<?php

namespace app\service;

use app\controller\common\LogHelper;
use app\model\GameRecords;
use app\model\GameRecordsTemporary;
use app\model\Luzhu;
use app\model\UserModel;
use app\model\MoneyLog;
use app\job\MoneyBetLogJob;
use app\job\UserSettlementJob;
use app\job\ZongHeMoneyJob;
use think\facade\Db;
use think\facade\Queue;

/**
 * 百家乐用户结算服务类
 * 
 * 功能：处理百家乐游戏的用户投注结算，包括输赢计算、
 * 资金变动、洗码费处理等完整的结算流程
 * 
 * @package app\service
 */
class CardSettlementService 
{
    // 查询条件数组：存储 table_id, game_type, xue_number, pu_number
    public $search_array = [];
    
    // 游戏记录模型实例
    public $GameRecords = null;

    /**
     * 构造函数 - 初始化游戏记录模型
     */
    public function __construct()
    {
        $this->GameRecords = new GameRecords();
    }

    /**
     * ========================================
     * 开牌核心逻辑
     * ========================================
     * 
     * 处理开牌请求，保存开牌结果，缓存数据，
     * 并分发异步结算任务
     * 
     * @param array $post 开牌数据
     * @return string JSON响应结果
     */
    public function open_game($post): string
    {
        // 记录开牌开始日志
        LogHelper::debug('=== 开牌服务开始 ===', [
            'table_id' => $post['table_id'],
            'game_type' => $post['game_type'],
            'xue_number' => $post['xue_number'],
            'pu_number' => $post['pu_number']
        ]);
        
        // ========================================
        // 1. 数据库事务处理 - 保存露珠记录
        // ========================================
        $luzhuModel = new Luzhu();
        $save = false;        
       
        // 开启数据库事务
        Db::startTrans();
        try {
            // 保存露珠数据
            $luzhuModel->save($post);                      
            $save = true;
            Db::commit();
        } catch (\Exception $e) {
            $save = false;
            Db::rollback();
            LogHelper::error('开牌数据保存失败', $e);
        }

        // ========================================
        // 2. Redis缓存设置 - 供实时推送使用
        // ========================================
        // 将开牌信息缓存到Redis，存储5秒供WebSocket推送
        $redis_key = 'pai_info_table_' . $post['table_id'];
        redis()->set($redis_key, $post['result_pai'], 5);

        // ========================================
        // 3. 错误处理和状态检查
        // ========================================
        if (!$save) {
            show([], 0, '开牌失败');
        }

        // ========================================
        // 4. 异步用户结算任务分发
        // ========================================
        // 添加露珠ID到结算数据中
        $post['luzhu_id'] = $luzhuModel->id;
        LogHelper::debug('开牌数据保存成功，露珠ID：' . $luzhuModel->id);

        // 延迟1秒执行用户结算任务（避免数据冲突）
        $queue = Queue::push(UserSettlementJob::class, $post, 'bjl_jiesuan_queue');
        if ($queue == false) {
            LogHelper::error('结算任务分发失败');
            show([], 0, 'dismiss job queue went wrong');
        }

        LogHelper::debug('=== 开牌服务完成 ===');
        show([]);
    }

    /**
     * 用户结算核心逻辑
     * 
     * 处理用户投注结算，包括输赢计算、
     * 资金变动、洗码费处理等完整的结算流程
     * 
     * @param array $post 结算数据
     * @return bool 结算成功返回true，失败返回false
     */
    public function user_settlement($post): bool
    {
        // 运行时间记录
        $startTime = microtime(true);

        // 记录结算开始日志
        LogHelper::debug('=== 用户结算开始 ===', [
            'table_id' => $post['table_id'],
            'xue_number' => $post['xue_number'],
            'pu_number' => $post['pu_number']
        ]);

        // 设置查询条件
        $this->search_array = [
            'table_id' => $post['table_id'],
            'game_type' => $post['game_type'],
            'xue_number' => $post['xue_number'],
            'pu_number' => $post['pu_number']
        ];

        // 计算开牌结果 + 标记用户输赢
        $res = $this->update_mysql_records_by_pai_info($post['result_pai']);

        // 获取用户中奖金额，存入redis供前端查询
        $this->cache_user_win_amount();

        // 结算用户资金变动 - 推送到异步队列处理
        $jobData = [
            'search' => $this->search_array,
            'pai_info' => $res,
            'result_pai' => $post['result_pai'],
            'luzhu_id' => $post['luzhu_id'] ?? 0  // 传递露珠ID
        ];
        Queue::push(MoneyBetLogJob::class, $jobData, 'bjl_money_log_queue');
        
        // 结算完成时间记录
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        LogHelper::debug('=== 用户结算完成 ===', ['duration' => $duration . ' seconds']);   
        
        return true;
    }

    /**
     * 缓存用户中奖金额到Redis
     * 
     * 将每个用户的中奖总额缓存到Redis，供前端快速查询
     */
    private function cache_user_win_amount()
    {
        // 查询所有中奖用户及其中奖总额
        $userWins = $this->GameRecords
            ->where($this->search_array)
            ->where('win_or_loss', 1) // 筛选赢了的记录
            ->field('user_id, SUM(win_amt) as total_win')
            ->group('user_id')
            ->select()
            ->toArray();

        // 将每个用户的中奖金额存入Redis
        foreach ($userWins as $record) {
            $redis_key = 'user_win_money_user_' . $record['user_id'] . '_table_' . $this->search_array['table_id'];
            redis()->set($redis_key, $record['total_win'], 10); // 存储10秒
            
            LogHelper::debug('Redis缓存--用户中奖金额--设置成功', [
                'redis_key' => $redis_key,
                'total_win' => $record['total_win'],
                'ttl' => 10
            ]);
        }
    }   

    /**
     * 根据开牌信息更新投注记录
     * 
     * 计算开牌结果，更新各种投注类型的输赢状态
     * 
     * @param string $result_pai 开牌结果JSON字符串
     * @return array 开牌计算结果
     */
    private function update_mysql_records_by_pai_info($result_pai)
    {
        LogHelper::debug('开始计算开牌结果');
        
        // 调用开牌计算服务
        $card = new OpenPaiCalculationService();
        $pai_result = $card->runs(json_decode($result_pai, true));
        LogHelper::debug('开牌计算详细结果', $pai_result);
        
        // 假设这里的 $res 应该是 $pai_result
        $res = $pai_result;
        
        // ========================================
        // 根据点数计算基本输赢结果
        // ========================================
        if ($res['zhuang_point'] == $res['xian_point']) {
            $res['result'] = 'he';     // 和局
        } elseif ($res['zhuang_point'] > $res['xian_point']) {
            $res['result'] = 'zhuang';  // 庄赢
        } else {
            $res['result'] = 'xian';    // 闲赢
        }

        // 复制查询条件，用于不同投注类型的更新
        $search_array = $this->search_array;
        
        // ========================================
        // 处理各种投注类型的输赢
        // ========================================
        
        // 1. 闲对（赔率ID=2）
        $search_array['game_peilv_id'] = 2;
        if ($res['xian_dui'] == 1) {
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        } else {
            // 标记为输了
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }

        // 2. 幸运6（赔率ID=3）
        $search_array['game_peilv_id'] = 3;
        if ($res['lucky'] == 6) {
            // 根据牌数确定赔率：3张牌赔20倍，2张牌赔12倍
            $peilv = ($res['luckySize'] == 3) ? 20 : 12;
            $OrdersTemp = $this->GameRecords->where($search_array)->select();
            
            // 更新每条记录的中奖金额和实际赔率
            foreach ($OrdersTemp as $k => $v) {
                $v->win_amt = $v->money * $peilv;
                $v->game_peilv = $peilv;
                $v->save();
            }
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        } else {
            // 标记为输了
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }

        // 3. 庄对（赔率ID=4）
        $search_array['game_peilv_id'] = 4;
        if ($res['zhuang_dui'] == 1) {
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        } else {
            // 标记为输了
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }

        // 4. 闲（赔率ID=6）
        $search_array['game_peilv_id'] = 6;
        if ($res['result'] == 'xian') {
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        } else {
            // 标记为输了
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }

        // 5. 和（赔率ID=7）
        $search_array['game_peilv_id'] = 7;
        if ($res['result'] == 'he') {
            // 和局时，退回庄闲的本金
            $tempSearch = $search_array;
            unset($tempSearch['game_peilv_id']); 
            $OrdersTemp = $this->GameRecords
                ->where('game_peilv_id', 'between', '6,8')
                ->where($tempSearch)
                ->select();
            
            // 标记为退回本金
            foreach ($OrdersTemp as $k => $v) {
                $v->is_tie_money_return = 1;
                $v->save();
            }
            // 标记和投注为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        } else {
            // 标记为输了
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }

        // 6. 庄（赔率ID=8）
        $search_array['game_peilv_id'] = 8;
        if ($res['result'] == 'zhuang') {
            // 根据免佣状态修改实际赔率
            $peilv = 1;
            $OrdersTemp = $this->GameRecords->where($search_array)->select();
            
            foreach ($OrdersTemp as $k => $v) {
                if ($v->is_exempt == 1) {
                    // 免佣庄
                    if ($res['zhuang_point'] == 6) {
                        $peilv = 0.5;   // 免佣庄6点赔0.5倍
                    } else {
                        $peilv = 1;     // 免佣庄其他点数赔1倍
                    }       
                } else {  
                    $peilv = 0.95;      // 非免佣庄赔0.95倍（扣5%佣金）
                }
                $v->win_amt = $v->money * $peilv;
                $v->game_peilv = $peilv;
                $v->save();
            }
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        } else {
            // 标记为输了
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }

        // 7. 龙7（赔率ID=9）
        $search_array['game_peilv_id'] = 9;
        if ($res['zhuang_point'] == 7 && $res['zhuang_count'] == 3) {
            // 庄7点且3张牌，标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        } else {
            // 标记为输了
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }

        // 8. 熊8（赔率ID=10）  
        $search_array['game_peilv_id'] = 10;
        if ($res['xian_point'] == 8 && $res['xian_count'] == 3) {
            // 闲8点且3张牌，标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        } else {
            // 标记为输了
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }

        return $res;
    }   
}