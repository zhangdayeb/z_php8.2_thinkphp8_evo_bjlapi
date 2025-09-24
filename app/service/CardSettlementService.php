<?php

namespace app\service;

use app\controller\common\LogHelper;
use app\model\GameRecords;
use app\model\GameRecordsTemporary;
use app\model\Luzhu;
use app\model\UserModel;
use app\model\MoneyLog;
use app\job\MoneyLogInsert;
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
    public $oddsModel = new GameRecords();

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

        // 计算开牌结果
        $this->update_mysql_records_by_pai_info($post['result_pai']);


        // ========================================
        // 1. 查询本局投注记录
        // ========================================
              

        LogHelper::debug('开始查询投注记录');
        // 获取所有未结算的投注记录
        $betRecords = $this->oddsModel
            ->where([
                'table_id'     => $post['table_id'],
                'game_type'    => $post['game_type'],
                'xue_number'   => $post['xue_number'],
                'pu_number'    => $post['pu_number'],
                'close_status' => 1, // 1=未结算，2=已结算
            ])
            ->select()
            ->toArray();

        LogHelper::debug('投注记录详情', $betRecords);

        // ========================================
        // 2. 初始化结算数据容器
        // ========================================
        $dataSaveRecords = [];  // 保存更新后的投注记录数据
        $userSaveDataTemp = []; // 保存用户资金变动临时数据

        // ========================================
        // 3. 计算开牌结果
        // ========================================


        LogHelper::debug('开始逐笔投注结算');

        // ========================================
        // 4. 遍历投注记录进行结算计算
        // ========================================
        foreach ($betRecords as $key => $value) {
            // 判断用户是否中奖
            $user_is_win_or_not = $card->user_win_or_not(
                intval($value['result']), 
                $pai_result
            );

            LogHelper::debug('投注结算分析', [
                'record_id' => $value['id'],
                'user_id' => $value['user_id'],
                'bet_type' => $value['result'],
                'bet_type_name' => $this->user_pai_chinese($value['result']),
                'bet_amount' => $value['bet_amt'],
                'odds' => $value['game_peilv'],
                'is_win' => $user_is_win_or_not
            ]);

            // ========================================
            // 4.1 基础结算信息设置
            // ========================================
            $dataSaveRecords[$key] = [
                // 详细描述：原详情 + 购买内容 + 开牌结果
                'detail' => $value['detail']
                    . '-购买：' . $this->user_pai_chinese($value['result'])
                    . ',开：' . $this->pai_chinese($pai_result)
                    . '|本次结果记录' . json_encode($pai_result),
                'close_status' => 2,                    // 2=已结算
                'user_id'      => $value['user_id'],    // 用户ID
                'win_amt'      => 0,                    // 输赢金额默认0
                'id'           => $value['id'],         // 投注记录ID
                'lu_zhu_id'    => $luzhu_id,           // 关联露珠ID
                'table_id'     => $value['table_id'],   // 台桌ID
                'game_type'    => $value['game_type'],  // 游戏类型
            ];

            // ========================================
            // 4.2 特殊赔率预处理
            // ========================================
            $tempPelv = intval($value['game_peilv']); // 默认赔率

            // 用户投注幸运6：根据庄家牌数选择赔率
            if ($value['result'] == 3) {
                $pei_lv = explode('/', $value['game_peilv']); // 格式：12/20
                if ($pai_result['luckySize'] == 2) {
                    $tempPelv = intval($pei_lv[0]); // 2张牌赔率
                } elseif ($pai_result['luckySize'] == 3) {
                    $tempPelv = intval($pei_lv[1]); // 3张牌赔率
                }
            }

            // 用户投注庄：免佣庄特殊处理
            if ($value['result'] == 8) {
                // 免佣庄特殊处理：庄6点赢只赔50%
                if ($value['is_exempt'] == 1 ) {
                    if($pai_result['zhuang_point'] == 6){
                        $tempPelv = 0.5;
                    }else{
                        $tempPelv = 1;
                    }
                    
                }else{
                    $tempPelv = 0.95;
                }
            }

            $dataSaveRecords[$key]['game_peilv'] = $tempPelv;
            // 就是因为 押了庄才出现的问题
            $moneyWinTemp = $tempPelv * $value['bet_amt']; // 中奖金额 = 赔率 × 本金

            // ========================================
            // 4.3 洗码费计算（新规则：输了才给）
            // ========================================
            $rebateResult = $this->calculateRebate($value, $user_is_win_or_not, $pai_result);
            $dataSaveRecords[$key]['shuffling_amt'] = $rebateResult['shuffling_amt'];
            $dataSaveRecords[$key]['shuffling_num'] = $rebateResult['shuffling_num'];

            LogHelper::debug('洗码费计算结果', [
                'user_id' => $value['user_id'],
                'bet_amt' => $value['bet_amt'],
                'is_win' => $user_is_win_or_not,
                'is_exempt' => $value['is_exempt'],
                'shuffling_amt' => $rebateResult['shuffling_amt'],
                'shuffling_num' => $rebateResult['shuffling_num']
            ]);
            
            // ========================================
            // 4.4 输赢结算处理
            // ========================================
            if ($user_is_win_or_not) {
                // --- 中奖处理 ---
                $dataSaveRecords[$key]['win_amt'] = $moneyWinTemp;
                $dataSaveRecords[$key]['delta_amt'] = $moneyWinTemp + $value['bet_amt']; // 返还 = 奖金 + 本金

                // 用户资金变动记录
                $userSaveDataTemp[$key] = [
                    'money_balance_add_temp' => $dataSaveRecords[$key]['delta_amt'],
                    'id'                     => $value['user_id'],
                    'win'                    => $moneyWinTemp,
                    'bet_amt'                => $value['bet_amt'],
                ];

                LogHelper::debug('中奖处理', [
                    'user_id' => $value['user_id'],
                    'win_amt' => $moneyWinTemp,
                    'return_amt' => $dataSaveRecords[$key]['delta_amt']
                ]);
            } else {
                // --- 未中奖处理 ---
                $is_tie = in_array(7, $pai_result['win_array']); // 是否和局

                if ($is_tie) {
                    // 和局特殊处理：庄闲投注退回本金
                    if ($value['result'] == 8 || $value['result'] == 6) {
                        $userSaveDataTemp[$key] = [
                            'money_balance_add_temp' => $value['bet_amt'], // 退回本金
                            'id'                     => $value['user_id'],
                            'win'                    => 0,
                            'bet_amt'                => $value['bet_amt'],
                        ];

                        $dataSaveRecords[$key]['win_amt'] = 0;
                        $dataSaveRecords[$key]['delta_amt'] = 0;
                        $dataSaveRecords[$key]['agent_status'] = 1;
                        $dataSaveRecords[$key]['shuffling_amt'] = 0;  // 和局无洗码费
                        $dataSaveRecords[$key]['shuffling_num'] = 0;  // 和局无洗码量

                        LogHelper::debug('和局退款处理', [
                            'user_id' => $value['user_id'],
                            'refund_amt' => $value['bet_amt']
                        ]);
                    } else {
                        // 其他投注类型输掉本金
                        $dataSaveRecords[$key]['win_amt'] = $value['bet_amt'] * -1;

                        LogHelper::debug('和局其他投注输钱', [
                            'user_id' => $value['user_id'],
                            'loss_amt' => $value['bet_amt']
                        ]);
                    }
                } else {
                    // 正常输牌：输掉本金
                    $dataSaveRecords[$key]['win_amt'] = $value['bet_amt'] * -1;

                    LogHelper::debug('正常输牌处理', [
                        'user_id' => $value['user_id'],
                        'loss_amt' => $value['bet_amt'],
                        'rebate_amt' => $dataSaveRecords[$key]['shuffling_amt']
                    ]);
                }
            }
        }

        // ========================================
        // 5. 合并同用户的多笔投注
        // ========================================
        $user_save_data = [];
        if (!empty($userSaveDataTemp)) {
            foreach ($userSaveDataTemp as $v) {
                if (array_key_exists($v['id'], $user_save_data)) {
                    // 同用户多笔投注金额累加
                    $user_save_data[$v['id']]['money_balance_add_temp'] += $v['money_balance_add_temp'];
                } else {
                    $user_save_data[$v['id']] = $v;
                }
            }
        }

        // ========================================
        // 6. 生成派彩显示数据
        // ========================================
        if (!empty($dataSaveRecords)) {
            $userCount = [];
            
            // 按用户汇总输赢金额
            foreach ($dataSaveRecords as $v) {
                if (array_key_exists($v['user_id'], $userCount)) {
                    $userCount[$v['user_id']]['win_amt'] += $v['win_amt'];
                } else {
                    $userCount[$v['user_id']] = $v;
                }
            }

            // 将派彩结果存入Redis，供客户端显示（存储300秒）
            foreach ($userCount as $record) {
                $redis_key = 'user_' . $record['user_id'] . '_table_id_' . $record['table_id'] . '_' . $record['game_type'];
                redis()->set($redis_key, $record['win_amt'], 300);
                LogHelper::debug('Redis缓存--骰宝派奖--设置成功', [
                    'redis_key' => $redis_key,
                    'ttl' => 30
                ]);
            }
        }

        // ========================================
        // 7. 数据库事务处理 - 更新用户余额和投注记录
        // ========================================
        LogHelper::debug('开始用户余额更新事务');

        $UserModel = new UserModel();
        $UserModel->startTrans();
        
        try {
            // 更新用户余额
            if (!empty($userSaveDataTemp)) {
                foreach ($userSaveDataTemp as $userData) {
                    // 获取用户当前余额（加锁防止并发）
                    $find = $UserModel->where('id', $userData['id'])->lock(true)->find();

                    // 准备资金流水记录
                    $moneyLog = [
                        'money_before' => $find->money_balance,
                        'money_end'    => $find->money_balance + $userData['money_balance_add_temp'],
                        'uid'          => $userData['id'],
                        'type'         => 1,
                        'status'       => 503, // 百家乐结算
                        'source_id'    => $luzhu_id,
                        'money'        => $userData['money_balance_add_temp'],
                        'create_time'  => date('Y-m-d H:i:s'),
                        'mark'         => '下注结算--变化:' . $userData['money_balance_add_temp'] 
                                        . '下注：' . $userData['bet_amt'] 
                                        . '总赢：' . $userData['win']
                    ];

                    // 更新用户余额
                    $user_update = $UserModel->where('id', $userData['id'])
                        ->inc('money_balance', $userData['money_balance_add_temp'])
                        ->update();

                    // 如果余额更新成功，将资金记录推入Redis队列
                    if ($user_update) {  // ✅ 改为正确的变量名
                        redis()->LPUSH('bet_settlement_money_log', json_encode($moneyLog));
                        
                        // 分发钱包结算通知队列（立即执行，不延迟）
                        Queue::push(ZongHeMoneyJob::class, [
                            'type' => 'settle',
                            'userData' => $userData,
                            'luzhu_id' => $luzhu_id
                        ], 'bjl_zonghemoney_log_queue');
                    }
                }
            }

            // 批量更新投注记录状态
            if (!empty($dataSaveRecords)) {
                $oddsModel->saveAll($dataSaveRecords);
            }

            
            // ========================================
            // 7.1. 自动累计用户洗码费
            // ========================================
            try {
                $this->accumulateUserRebate($dataSaveRecords);
                LogHelper::debug('洗码费累计完成');
            } catch (\Exception $e) {
                LogHelper::error('洗码费累计失败', $e);
                // 不影响主流程，只记录错误
            }

            $UserModel->commit();
            LogHelper::debug('用户余额更新事务完成');

        } catch (\Exception $e) {
            $UserModel->rollback();
            LogHelper::error('用户余额更新失败', $e);
            return false;
        }

        // ========================================
        // 8. 后续处理任务
        // ========================================
        LogHelper::debug('开始后续处理任务');
        
        // 延迟2秒执行资金日志写入任务
        Queue::later(1, MoneyLogInsert::class, $post, 'bjl_money_log_queue');
        LogHelper::debug('资金日志写入任务已加入队列');

        // 清理临时投注记录
        GameRecordsTemporary::destroy(function($query) use ($post) {
            $query->where('table_id', $post['table_id']);
        });
        LogHelper::debug('临时投注记录清理完成');

        $endTime = microtime(true);
        $duration = round(($endTime - $startTime) * 1000, 2);
        
        LogHelper::debug('=== 用户结算完成 ===', [
            'luzhu_id' => $luzhu_id,
            'duration_ms' => $duration,
            'memory_usage_mb' => round(memory_get_usage() / 1024 / 1024, 2)
        ]);



        return true;
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

        }
        $search_array['game_peilv_id'] = 3; // 幸运6
        if($res['lucky'] == 6){
            if($res['luckySize'] == 2){
                $peilv = 12; // 幸运6 二张牌
            }
            if($res['luckySize'] == 3){
                $peilv = 20; // 幸运6 三张牌
            }
        }
        $search_array['game_peilv_id'] = 4; // 庄对
        if($res['zhuang_dui'] == 1){

        }
        $search_array['game_peilv_id'] = 6; // 闲
        if($res['result'] == 'xian'){

        }
        $search_array['game_peilv_id'] = 7; // 和
        if($res['result'] == 'he'){
            // 退回本金 给庄闲

        }
        $search_array['game_peilv_id'] = 8; // 庄
        if($res['result'] == 'xian'){

        }
        $search_array['game_peilv_id'] = 9; // 龙7
        if($res['zhuang_point'] == 7 && $res['zhuang_count'] == 3){

        }
        $search_array['game_peilv_id'] = 10; // 熊8
        if($res['xian_point'] == 8 && $res['xian_count'] == 3){

        }
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

/**
 * ========================================
 * 类使用说明和技术要点
 * ========================================
 * 
 * 1. 结算流程控制：
 *    - 开牌 -> 数据保存 -> Redis缓存 -> 异步结算 -> 推送结果
 *    - 使用队列异步处理，避免阻塞用户操作
 * 
 * 2. 数据一致性保证：
 *    - 使用数据库事务确保数据完整性
 *    - 用户余额更新时加锁防止并发问题
 *    - Redis缓存设置合理过期时间
 * 
 * 3. 特殊规则处理：
 *    - 幸运6：根据庄家牌数选择不同赔率
 *    - 免佣庄：庄6点赢只赔50%
 *    - 和局：庄闲投注退回本金
 *    - 洗码费：只有输钱且非免佣才给洗码费
 * 
 * 4. 性能优化：
 *    - 批量数据库操作减少IO次数
 *    - Redis队列异步处理日志写入
 *    - 合理的缓存策略提升响应速度
 * 
 * 5. 错误处理：
 *    - 完整的异常捕获和事务回滚
 *    - 队列任务失败重试机制
 *    - 数据校验和边界条件处理
 * 
 * 6. 洗码费新规则：
 *    - 只有用户输钱且选择非免佣模式时才给洗码费
 *    - 中奖、和局、免佣模式下都不给洗码费
 *    - 通过calculateRebate()方法统一处理洗码费逻辑
 */