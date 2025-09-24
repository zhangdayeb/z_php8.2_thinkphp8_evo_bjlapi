<?php
namespace app\job;

use app\controller\common\LogHelper;
use app\model\GameRecords;
use app\model\GameRecordsTemporary;
use app\model\Luzhu;
use app\model\UserModel;
use app\model\MoneyLog;
use app\job\UserSettlementJob;
use app\job\ZongHeMoneyJob;
use think\facade\Db;
use think\facade\Queue;
use think\queue\Job;


class MoneyBetLogJob
{
    public function fire(Job $job, $data = null)
    {
        try {
            // 执行业务逻辑
            $res = $this->doJob($data);
            
            if ($res) {
                // 成功，删除任务
                $job->delete();
                return;
            }
            
            // 失败，检查重试次数
            if ($job->attempts() >= 3) {
                // 超过3次，删除任务
                $job->delete();
            } else {
                // 未超过3次，10秒后重试
                $job->release(10);
            }
            
        } catch (\Exception $e) {
            // 异常处理
            if ($job->attempts() >= 3) {
                $job->delete();
            } else {
                $job->release(10);
            }
        }
    }

    public function doJob($data)
    {
        // 这里执行 洗码 业务 资金日志等等 标记
        
        // 执行成功返回 true，失败返回 false
        return true;
    }
/**
     * ========================================
     * 根据输赢结果计算洗码费（新规则）
     * ========================================
     * 
     * 洗码费规则：
     * ✅ 输钱 + 非免佣：正常给洗码费
     * ❌ 中奖：不给洗码费
     * ❌ 输钱 + 免佣：不给洗码费  
     * ❌ 和局：不给洗码费
     * 
     * @param array $record 投注记录
     * @param bool $is_win 是否中奖
     * @param array $pai_result 开牌结果
     * @return array 包含洗码费和洗码量的数组
     */
    private function calculateRebate($record, $is_win, $pai_result): array
    {
        $is_tie = in_array(7, $pai_result['win_array']); // 是否和局

        // 中奖：无洗码费
        if ($is_win) {
            return ['shuffling_amt' => 0, 'shuffling_num' => 0];
        }

        // 和局：庄闲投注退款，其他投注输钱但不给洗码费
        if ($is_tie) {
            return ['shuffling_amt' => 0, 'shuffling_num' => 0];
        }
        
        // 免佣模式：无洗码费
        if ($record['is_exempt'] == 1) {
            return ['shuffling_amt' => 0, 'shuffling_num' => 0];
        }
        
        // 非免佣模式且输钱：计算洗码费
        $shuffling_rate = $record['shuffling_rate'] ?? 0.008; // 默认0.8%洗码率
        return [
            'shuffling_amt' => $record['bet_amt'] * $shuffling_rate,
            'shuffling_num' => $record['bet_amt']
        ];
    }
    /**
     * ========================================
     * 将投注类型ID转换为中文描述
     * ========================================
     * 
     * @param int $res 投注类型ID
     * @return string 中文描述
     */
    private function user_pai_chinese(int $res): string
    {
        // 这个数组 可以根据 游戏类型 去 赔率表里面读取 这个位置 闲临时这样用了
        $pai_names = [
            1 => '大', 
            2 => '闲对', 
            3 => '幸运6', 
            4 => '庄对', 
            5 => '小', 
            6 => '闲', 
            7 => '和', 
            8 => '庄',
            9 => '龙7', 
            10 => '熊8', 
            11 => '大老虎', 
            12 => '小老虎',
        ];
        
        return $pai_names[$res] ?? '未知';
    }
    /**
     * 自动累计用户洗码费到用户表
     * @param array $dataSaveRecords 结算记录数组
     */
    private function accumulateUserRebate($dataSaveRecords)
    {
        // 按用户汇总洗码费
        $userRebates = [];
        foreach ($dataSaveRecords as $record) {
            if ($record['shuffling_amt'] > 0) {
                $userId = $record['user_id'];
                if (!isset($userRebates[$userId])) {
                    $userRebates[$userId] = 0;
                }
                $userRebates[$userId] += $record['shuffling_amt'];
            }
        }
        
        // 批量更新用户洗码费
        if (!empty($userRebates)) {
            LogHelper::debug('开始累计用户洗码费', [
                'user_count' => count($userRebates),
                'total_rebate' => array_sum($userRebates)
            ]);

            foreach ($userRebates as $userId => $totalRebate) {
                // 更新用户洗码费余额和累计洗码费
                UserModel::where('id', $userId)
                    ->inc('rebate_balance', $totalRebate)
                    ->inc('rebate_total', $totalRebate)
                    ->update();
                
                // 记录洗码费流水
                $this->recordRebateLog($userId, $totalRebate);
            }
        }
    }
    /**
     * 记录洗码费流水日志
     * @param int $userId 用户ID
     * @param float $amount 洗码费金额
     */
    private function recordRebateLog($userId, $amount)
    {
        MoneyLog::insert([
            'uid' => $userId,
            'type' => 1,
            'status' => 602, // 洗码费自动累计
            'money' => $amount,
            'money_before' => 0, // 洗码费余额变动
            'money_end' => $amount,
            'source_id' => 0,
            'mark' => '系统自动累计洗码费',
            'create_time' => date('Y-m-d H:i:s')
        ]);
    }
}