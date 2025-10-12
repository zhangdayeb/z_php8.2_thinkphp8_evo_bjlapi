<?php
namespace app\job;

use app\controller\common\LogHelper;
use app\model\GameRecords;
use app\model\GameRecordsTemporary;
use app\model\UserModel;
use app\model\MoneyLog;
use think\facade\Db;
use think\facade\Queue;
use think\queue\Job;

/**
 * 百家乐资金结算任务
 * 
 * 处理开牌后的资金结算、流水记录、洗码费计算等
 */
class MoneyBetLogJob
{
    public function fire(Job $job, $data = null)
    {
        try {
            LogHelper::debug('=== 资金结算任务开始 ===', $data['search'] ?? []);
            
            // 执行业务逻辑
            $res = $this->doJob($data);
            
            if ($res) {
                // 成功，删除任务
                $job->delete();
                LogHelper::debug('=== 资金结算任务完成 ===');
                return;
            }
            
            // 失败，检查重试次数
            if ($job->attempts() >= 3) {
                LogHelper::error('资金结算任务失败，超过最大重试次数', $data);
                $job->delete();
            } else {
                // 未超过3次，10秒后重试
                $job->release(10);
            }
            
        } catch (\Exception $e) {
            LogHelper::error('资金结算任务异常', ['error' => $e->getMessage(), 'data' => $data]);
            
            if ($job->attempts() >= 3) {
                $job->delete();
            } else {
                $job->release(10);
            }
        }
    }

    /**
     * 执行结算业务逻辑
     */
    public function doJob($data)
    {
        // 开启事务
        Db::startTrans();
        try {
            // 1. 查询本局所有投注记录
            $betRecords = GameRecords::where($data['search'])
                ->where('close_status', 1)  // 未结算
                ->select();
            
            if ($betRecords->isEmpty()) {
                LogHelper::debug('没有需要结算的投注记录');
                Db::commit();
                return true;
            }
            
            LogHelper::debug('查询到投注记录数量：' . count($betRecords));

            // 获取开牌结果信息
            $pai_info = $data['pai_info'];
            $luzhu_id = $data['luzhu_id'] ?? 0;  // 🔥 修正：从根级别获取 luzhu_id，而不是从 search 中

            LogHelper::debug('获取露珠ID', [
                'luzhu_id' => $luzhu_id,
                'data_keys' => array_keys($data)
            ]);

            // 2. 遍历每条投注记录进行结算
            foreach ($betRecords as $record) {
                $this->processBetRecord($record, $pai_info, $luzhu_id);
            }
            
            // 3. 清理临时投注记录
            GameRecordsTemporary::where($data['search'])->delete();
            LogHelper::debug('临时投注记录清理完成');
            
            Db::commit();
            return true;
            
        } catch (\Exception $e) {
            Db::rollback();
            LogHelper::error('资金结算事务失败', $e);
            return false;
        }
    }

    /**
     * 处理单条投注记录
     */
    private function processBetRecord($record, $pai_info, $luzhu_id)
    {
        LogHelper::debug('处理投注记录', [
            'record_id' => $record->id,
            'user_id' => $record->user_id,
            'bet_amt' => $record->bet_amt,
            'win_or_loss' => $record->win_or_loss,
            'is_tie_money_return' => $record->is_tie_money_return
        ]);
        
        // 1. 构建 detail 字段
        $detail = $this->buildDetailString($record, $pai_info);
        
        // 2. 计算资金变动
        $settlement = $this->calculateSettlement($record);
        
        // 3. 处理用户余额变动（如有）
        if ($settlement['money_change'] > 0) {
            $this->processUserBalance($record, $settlement, $detail, $luzhu_id);
        }
        
        // 4. 处理洗码费（如符合条件）
        if ($this->shouldCalculateRebate($record)) {
            $this->processRebate($record);
        }
        
        // 5. 更新投注记录
        $record->detail = $detail;
        $record->delta_amt = $settlement['delta_amt'];
        $record->close_status = 2;  // 已结算
        $record->lu_zhu_id = $luzhu_id;
        $record->save();
        
        LogHelper::debug('投注记录处理完成', [
            'record_id' => $record->id,
            'delta_amt' => $settlement['delta_amt']
        ]);
    }

    /**
     * 构建详情描述
     */
    private function buildDetailString($record, $pai_info)
    {
        $bet_type_names = [
            2 => '闲对', 
            3 => '幸运6', 
            4 => '庄对',
            6 => '闲', 
            7 => '和', 
            8 => '庄',
            9 => '龙7', 
            10 => '熊8'
        ];
        
        // 结果描述
        $result_desc = $pai_info['result'] == 'he' ? '和局' : 
                      ($pai_info['result'] == 'zhuang' ? '庄赢' : '闲赢');
        
        // 结算结果
        if ($record->win_or_loss == 1) {
            $result_text = sprintf('，中奖%s元', $record->win_amt);
        } elseif ($record->is_tie_money_return == 1) {
            $result_text = sprintf('，退款%s元', $record->bet_amt);
        } else {
            $result_text = sprintf('，输%s元', $record->bet_amt);
        }
        
        return sprintf(
            "购买：%s %s元，开：%s|庄:%s %d点，闲:%s %d点%s",
            $bet_type_names[$record->game_peilv_id] ?? '未知',
            $record->bet_amt,
            $result_desc,
            trim($pai_info['zhuang_string'] ?? '', '-'),
            $pai_info['zhuang_point'] ?? 0,
            trim($pai_info['xian_string'] ?? '', '-'),
            $pai_info['xian_point'] ?? 0,
            $result_text
        );
    }

    /**
     * 计算结算金额
     */
    private function calculateSettlement($record)
    {
        if ($record->win_or_loss == 1) {
            // 中奖
            return [
                'delta_amt' => $record->win_amt,  // 纯奖金
                'money_change' => $record->bet_amt + $record->win_amt,  // 返还总额
                'type' => 'win'
            ];
            
        } elseif ($record->win_or_loss == 0 && $record->is_tie_money_return == 1) {
            // 和局退款
            return [
                'delta_amt' => 0,  // 不输不赢
                'money_change' => $record->bet_amt,  // 退回本金
                'type' => 'tie_refund'
            ];
            
        } else {
            // 正常输钱
            return [
                'delta_amt' => -$record->bet_amt,  // 负数，表示输钱
                'money_change' => 0,  // 无资金变动
                'type' => 'lose'
            ];
        }
    }

    /**
     * 处理用户余额变动
     */
    private function processUserBalance($record, $settlement, $detail, $luzhu_id)
    {
        // 加锁查询用户
        $user = UserModel::where('id', $record->user_id)->lock(true)->find();
        if (!$user) {
            throw new \Exception('用户不存在：' . $record->user_id);
        }
        
        $money_before = $user->money_balance;
        $money_end = $money_before + $settlement['money_change'];
        
        // 更新用户余额
        $user->money_balance = $money_end;
        $user->save();
        
        // 生成资金流水记录
        MoneyLog::insert([
            'uid' => $record->user_id,
            'type' => 1,  // 收入
            'status' => 503,  // 百家乐结算
            'money_before' => $money_before,
            'money_end' => $money_end,
            'money' => $settlement['money_change'],
            'source_id' => $record->id,
            'mark' => $detail,
            'create_time' => date('Y-m-d H:i:s')
        ]);
        
        // 调用综合处理任务
        $jobData = [
            'type' => 'settle',
            'userData' => [
                'id' => $record->user_id,
                'money_balance_add_temp' => $settlement['money_change'],
                'win' => $settlement['type'] == 'win' ? $record->win_amt :
                        ($settlement['type'] == 'tie_refund' ? $record->bet_amt : 0),
                'bet_amt' => $record->bet_amt
            ],
            'luzhu_id' => $luzhu_id
        ];
        
        Queue::push(ZongHeMoneyJob::class, $jobData, 'bjl_zonghemoney_log_queue');
        
        LogHelper::debug('用户余额更新完成', [
            'user_id' => $record->user_id,
            'money_change' => $settlement['money_change'],
            'new_balance' => $money_end
        ]);
    }

    /**
     * 判断是否需要计算洗码费
     */
    private function shouldCalculateRebate($record)
    {
        // 输钱 + 非退款 + 非免佣
        return $record->win_or_loss == 0 && 
               $record->is_tie_money_return == 0 && 
               $record->is_exempt == 0;
    }

    /**
     * 处理洗码费
     */
    private function processRebate($record)
    {
        // 计算洗码费（注意除以100）
        $shuffling_rate = $record->shuffling_rate ?? 1.8;  // 默认1.8%
        $shuffling_amt = $record->bet_amt * ($shuffling_rate / 100);
        
        // 更新用户洗码费余额
        $user = UserModel::where('id', $record->user_id)->find();
        $rebate_before = $user->rebate_balance;
        
        UserModel::where('id', $record->user_id)
            ->inc('rebate_balance', $shuffling_amt)
            ->inc('rebate_total', $shuffling_amt)
            ->update();
        
        // 记录洗码费流水
        MoneyLog::insert([
            'uid' => $record->user_id,
            'type' => 1,  // 收入
            'status' => 602,  // 洗码费
            'money_before' => $rebate_before,
            'money_end' => $rebate_before + $shuffling_amt,
            'money' => $shuffling_amt,
            'source_id' => $record->id,
            'mark' => sprintf('百家乐洗码费返还，投注%s元，洗码率%s%%', 
                            $record->bet_amt, $shuffling_rate),
            'create_time' => date('Y-m-d H:i:s')
        ]);
        
        // 更新投注记录的洗码费
        $record->shuffling_amt = $shuffling_amt;
        $record->shuffling_num = $record->bet_amt;
        
        LogHelper::debug('洗码费处理完成', [
            'user_id' => $record->user_id,
            'shuffling_amt' => $shuffling_amt,
            'shuffling_rate' => $shuffling_rate
        ]);
    }
}