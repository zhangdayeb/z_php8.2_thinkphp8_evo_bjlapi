<?php

namespace app\service;

use app\controller\common\LogHelper;
use app\model\GameRecords;
use app\model\GameRecordsTemporary;
use app\model\Luzhu;
use app\model\UserModel;
use app\model\MoneyLog;
use app\job\MoneyLogInsertJob;
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
class CardSettlementService extends CardServiceBase
{
    // search_array 用于存储查询条件 tabe_id game_type xue_number pu_number
    public $search_array = [];
    public $GameRecords = new GameRecords();

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
       
        // 组合露珠数据
        // 开启数据库事务
        Db::startTrans();
        try {
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
        // 5. 异步用户结算任务分发
        // ========================================
        // 添加露珠ID到结算数据中
        $post['luzhu_id'] = $luzhuModel->id;
        LogHelper::debug('开牌数据保存成功，露珠ID：' . $luzhuModel->id);

        // 延迟1秒执行用户结算任务（避免数据冲突）
        $queue = Queue::later(1, UserSettlementJob::class, $post, 'bjl_jiesuan_queue');
        if ($queue == false) {
            LogHelper::error('结算任务分发失败');
            show([], 0, 'dismiss job queue went wrong');
        }

        LogHelper::debug('=== 开牌服务完成 ===');

        return show([]);
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

        LogHelper::debug('=== 用户结算开始 ===', [
            'table_id' => $post['table_id'],
            'xue_number' => $post['xue_number'],
            'pu_number' => $post['pu_number']
        ]);

        // 设置查询条件
        $this->search_array =   [
            'table_id' => $post['table_id'],
            'game_type' => $post['game_type'],
            'xue_number' => $post['xue_number'],
            'pu_number' => $post['pu_number']
        ]
        $luzhu_id = $post['luzhu_id'];

        // 计算开牌结果 + 标记用户输赢
        $this->update_mysql_records_by_pai_info($post['result_pai']);

        // 获取用户中奖金额 存入 redis 供前端查询
        $this->cache_user_win_amount();

        // 结算用户资金变动
        Queue::push(MoneyLogInsertJob::class, $this->search_array, 'bjl_money_log_queue');
        // 结算完成时间记录
        $endTime = microtime(true);
        $duration = $endTime - $startTime;
        LogHelper::debug('=== 用户结算完成 ===', ['duration' => $duration . ' seconds']);   
            


    }

    private function cache_user_win_amount()
    {
        $userWins = $this->GameRecords
            ->where($this->search_array)
            ->where('win_or_loss', 1) // 赢了
            ->field('user_id, SUM(win_amt) as total_win')
            ->group('user_id')
            ->select()
            ->toArray();

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

    // 根据开牌信息更新投注记录
    private function update_mysql_records_by_pai_info($result_pai)
    {
        LogHelper::debug('开始计算开牌结果');
        $card = new OpenPaiCalculationService();
        $pai_result = $card->runs(json_decode($result_pai, true));
        LogHelper::debug('开牌计算详细结果', $pai_result);
        
        // 根据点数计算 输赢结果
        if ($res['zhuang_point'] == $res['xian_point']) {
            $res['result'] = 'he'; // 和局
        } elseif ($res['zhuang_point'] > $res['xian_point']) {
            $res['result'] = 'zhuang'; // 庄赢
        } else {
            $res['result'] = 'xian'; // 闲赢
        }

        // 计算其他投注结果
        $search_array = $this->search_array;
        $search_array['game_peilv_id'] = 2; // 闲对
        if($res['xian_dui'] == 1){
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        }else{
            // 标记为输了 默认就是输了 
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }


        $search_array['game_peilv_id'] = 3; // 幸运6
        if($res['lucky'] == 6){
            // 根据接近修改真实赔率
            $peilv = ($res['luckySize'] == 3) ? 20 : 12; // 根据牌数选择赔率
            $OrdersTemp = $this->GameRecords->where($search_array)->select();
            foreach($OrdersTemp as $k => $v){
                $v->win_amt = $v->money * $peilv;
                $v->game_peilv = $peilv;
                $v->save();
            }
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        }else{
            // 标记为输了 默认就是输了 
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }


        $search_array['game_peilv_id'] = 4; // 庄对
        if($res['zhuang_dui'] == 1){
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        }else{
            // 标记为输了 默认就是输了 
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }


        $search_array['game_peilv_id'] = 6; // 闲
        if($res['result'] == 'xian'){
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        }else{
            // 标记为输了 默认就是输了 
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }


        $search_array['game_peilv_id'] = 7; // 和
        if($res['result'] == 'he'){
            // 退回本金 给庄闲
            $tempSearch = $search_array;
            unset($tempSearch['game_peilv_id']); 
            $OrdersTemp = $this->GameRecords->where('game_peilv_id','between','6,8')->where($tempSearch)->select();
            foreach($OrdersTemp as $k => $v){
                $v->is_tie_money_return = 1;
                $v->save();
            }
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        }else{
            // 标记为输了 默认就是输了 
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }


        $search_array['game_peilv_id'] = 8; // 庄
        if($res['result'] == 'zhuang'){
            // 根据免佣修改真实赔率
            $peilv = 1;
            $OrdersTemp = $this->GameRecords->where($search_array)->select();
            foreach($OrdersTemp as $k => $v){
                if($v->is_exempt == 1){
                    if($res['zhuang_point'] == 6){
                        $peilv = 0.5; // 免佣庄6点
                    }else{
                        $peilv = 1; // 免佣庄其他点数
                    }       
                }else{  
                    $peilv = 0.95; // 非免佣庄
                }
                $v->win_amt = $v->money * $peilv;
                $v->game_peilv = $peilv;
                $v->save();
            }
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        }else{
            // 标记为输了 默认就是输了 
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }


        $search_array['game_peilv_id'] = 9; // 龙7
        if($res['zhuang_point'] == 7 && $res['zhuang_count'] == 3){
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        }else{
            // 标记为输了 默认就是输了 
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }


        $search_array['game_peilv_id'] = 10; // 熊8
        if($res['xian_point'] == 8 && $res['xian_count'] == 3){
            // 标记为赢了
            $this->GameRecords->where($search_array)->update(['win_or_loss' => 1]);
        }else{
            // 标记为输了 默认就是输了 
            $this->GameRecords->where($search_array)->update(['win_amt' => 0, 'win_or_loss' => 0]);
        }
    }   
}    
