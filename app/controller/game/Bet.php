<?php
namespace app\controller\game;

use app\BaseController;
use app\controller\common\LogHelper;
use app\model\Luzhu;
use app\model\Table;
use app\service\CardSettlementService;
use app\validate\BetOrder as validates;
use app\job\TableStartJob;
use think\exception\ValidateException;
use think\facade\Queue;
use think\facade\Db;

/**
 * 投注和开牌控制器
 * 处理荷官开牌、设置靴号、开始结束信号等操作
 */
class Bet extends BaseController
{
    /**
     * 荷官开牌
     * 接收荷官发送的开牌数据并进行结算
     * @return string JSON响应
     */
    public function set_post_data(): string
    {
        LogHelper::debug('===================================================');
        LogHelper::debug('=== 开牌流程开始 接收到荷官开牌请求 ===');
        LogHelper::debug('===================================================');
        
        // 获取请求参数
        $params = $this->request->param();
        LogHelper::debug('荷官原始参数', $params);
        
        // 处理 pai_result 数组 - 转换为JSON字符串
        if (isset($params['pai_result']) && is_array($params['pai_result'])) {
            $params['result_pai'] = json_encode($params['pai_result']);
        } else {
            $params['result_pai'] = '';
        }
        
        // 只验证关键参数，跳过 pai_result 验证
        if (empty($params['tableId']) || empty($params['gameType']) || 
            empty($params['xueNumber']) || empty($params['puNumber'])) {
            LogHelper::error('开牌参数缺失', $params);
            show([], config('ToConfig.http_code.error'), '必要参数缺失');
        }
        
        // 检查是否重复开牌
        $isDuplicate = $this->checkDuplicateResult($params);
        if ($isDuplicate) {
            LogHelper::warning('重复开牌请求', $params);
            show([], config('ToConfig.http_code.error'), '该局已经开牌，请勿重复提交');
        }
        
        // 构建开牌数据
        $openData = [
            'table_id' => $params['tableId'],
            'xue_number' => $params['xueNumber'],
            'pu_number' => $params['puNumber'],
            'game_type' => $params['gameType'],
            'result' => $params['result'].'|'.$params['ext'],
            'result_pai' => $params['result_pai']
        ];
        
        try {
            // 根据游戏类型调用相应服务
            switch ($params['gameType']) {
                case 3: // 百家乐
                    LogHelper::debug('调用百家乐开牌服务', [
                        'table_id' => $openData['table_id'],
                        'xue_number' => $openData['xue_number'],
                        'pu_number' => $openData['pu_number'],
                        'result_pai' => $openData['result_pai']
                    ]);
                    
                    $cardService = new CardSettlementService();
                    $result = $cardService->open_game($openData);
                    
                    LogHelper::info('百家乐开牌成功', [
                        'table_id' => $openData['table_id'],
                        'result' => $params['result']
                    ]);
                    
                    return $result;
                    
                default:
                    LogHelper::error('不支持的游戏类型', [
                        'game_type' => $params['gameType']
                    ]);
                    show([], config('ToConfig.http_code.error'), '不支持的游戏类型');
            }
            
        } catch (\Exception $e) {
            LogHelper::error('开牌处理失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'params' => $openData
            ]);
            show([], config('ToConfig.http_code.error'), '开牌失败：' . $e->getMessage());
        }
    }
    
    /**
     * 设置靴号（荷官换靴）
     * @return string JSON响应
     */
    public function set_xue_number(): string
    {
        // 获取参数
        $postField = 'tableId,num_xue,gameType';
        $params = $this->request->only(explode(',', $postField), 'param', null);
        
        // 参数验证
        try {
            validate(validates::class)->scene('lz_set_xue')->check($params);
        } catch (ValidateException $e) {
            LogHelper::error('设置靴号参数验证失败', [
                'error' => $e->getError(),
                'params' => $params
            ]);
            show([], config('ToConfig.http_code.error'), $e->getError());
        }
        
        try {
            // 获取最后一条记录确定新靴号
            $lastRecord = Luzhu::where('table_id', $params['tableId'])
                ->order('id desc')
                ->find();
            
            // 计算新靴号
            $xueNumber = $lastRecord ? $lastRecord->xue_number + 1 : 1;
            
            // 创建新靴记录
            $newXue = [
                'status' => 1,
                'table_id' => $params['tableId'],
                'xue_number' => $xueNumber,
                'pu_number' => 1,
                'game_type' => $params['gameType'],
                'result' => 0,
                'result_pai' => 0,
                'create_time' => time(),
                'update_time' => time()
            ];
            
            // 保存新靴记录
            $save = (new Luzhu())->save($newXue);
            
            if ($save) {
                LogHelper::info('设置新靴号成功', [
                    'table_id' => $params['tableId'],
                    'xue_number' => $xueNumber
                ]);
                
                show($newXue, 1, '设置靴号成功');
            } else {
                throw new \Exception('保存靴号失败');
            }
            
        } catch (\Exception $e) {
            LogHelper::error('设置靴号失败', [
                'error' => $e->getMessage(),
                'params' => $params
            ]);
            show([], config('ToConfig.http_code.error'), '设置靴号失败');
        }
    }
    
    /**
     * 开局信号
     * 荷官发送开始下注信号
     * @return string JSON响应
     */
    public function set_start_signal(): string
    {
        $tableId = $this->request->param('tableId', 0);
        $time = (int) $this->request->param('time', 45);
        
        // 参数验证
        if ($tableId <= 0) {
            LogHelper::warning('开局信号参数错误', [
                'table_id' => $tableId
            ]);
            show([], config('ToConfig.http_code.error'), 'tableId参数错误');
        }
        
        // 限制倒计时时间范围
        if ($time < 10 || $time > 120) {
            $time = 45; // 默认45秒
        }
        
        try {
            // 更新台桌状态
            $updateData = [
                'start_time' => time(),
                'countdown_time' => $time,
                'run_status' => 1,      // 开始下注
                'wash_status' => 0,     // 非洗牌状态
                'update_time' => time()
            ];
            
            $updated = Table::where('id', $tableId)->update($updateData);
            
            if (!$updated) {
                throw new \Exception('更新台桌状态失败');
            }
                       
            // 推送到队列处理
            $queueData = array_merge($updateData, ['table_id' => $tableId]);
            Queue::push(TableStartJob::class, $queueData, 'bjl_start_queue');
            
            LogHelper::info('开局信号发送成功', [
                'table_id' => $tableId,
                'countdown' => $time
            ]);
            
            show($queueData, 1, '开局成功');
            
        } catch (\Exception $e) {
            LogHelper::error('发送开局信号失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            show([], config('ToConfig.http_code.error'), '开局失败');
        }
    }
    
    /**
     * 结束信号
     * 荷官发送停止下注信号
     * @return string JSON响应
     */
    public function set_end_signal(): string
    {
        $tableId = $this->request->param('tableId', 0);
        
        // 参数验证
        if ($tableId <= 0) {
            LogHelper::warning('结束信号参数错误', [
                'table_id' => $tableId
            ]);
            show([], config('ToConfig.http_code.error'), 'tableId参数错误');
        }
        
        try {
            // 更新台桌状态
            $updateData = [
                'run_status' => 2,      // 停止下注
                'wash_status' => 0,     // 非洗牌状态
                'update_time' => time()
            ];
            
            $updated = Table::where('id', $tableId)->update($updateData);
            
            if (!$updated) {
                throw new \Exception('更新台桌状态失败');
            }
            
            LogHelper::info('结束信号发送成功', [
                'table_id' => $tableId
            ]);
            
            show(['table_id' => $tableId], 1, '停止下注成功');
            
        } catch (\Exception $e) {
            LogHelper::error('发送结束信号失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            show([], config('ToConfig.http_code.error'), '停止下注失败');
        }
    }
    
    /**
     * 检查是否重复开牌
     * @param array $params 开牌参数
     * @return bool
     */
    private function checkDuplicateResult(array $params): bool
    {
        // 构建查询条件
        $map = [
            'status' => 1,
            'table_id' => $params['tableId'],
            'xue_number' => $params['xueNumber'],
            'pu_number' => $params['puNumber'],
            'game_type' => $params['gameType']
        ];
        
        // 查询当日是否已有开牌记录
        $existingRecord = Luzhu::whereTime('create_time', 'today')
            ->where('result', '<>', 0)
            ->where($map)
            ->find();
        
        return !empty($existingRecord);
    }
    
    /**
     * 取消开牌
     * 用于异常情况下取消某一局的开牌结果
     * @return string JSON响应
     */
    public function cancel_result(): string
    {
        $tableId = $this->request->param('tableId', 0);
        $xueNumber = $this->request->param('xueNumber', 0);
        $puNumber = $this->request->param('puNumber', 0);
        $reason = $this->request->param('reason', '');
        
        // 参数验证
        if ($tableId <= 0 || $xueNumber <= 0 || $puNumber <= 0) {
            show([], config('ToConfig.http_code.error'), '参数不完整');
        }
        
        try {
            // 开启事务
            Db::startTrans();
            
            // 更新露珠状态
            Luzhu::where([
                'table_id' => $tableId,
                'xue_number' => $xueNumber,
                'pu_number' => $puNumber
            ])->update([
                'status' => 3, // 已取消
                'update_time' => time()
            ]);
            
            // 更新相关投注记录
            Db::name('dianji_records')->where([
                'table_id' => $tableId,
                'xue_number' => $xueNumber,
                'pu_number' => $puNumber
            ])->update([
                'close_status' => 3, // 已取消
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            
            // 记录取消日志
            LogHelper::warning('取消开牌结果', [
                'table_id' => $tableId,
                'xue_number' => $xueNumber,
                'pu_number' => $puNumber,
                'reason' => $reason,
                'operator' => self::$user['user_name'] ?? 'system'
            ]);
            
            Db::commit();
            
            show([], 1, '取消成功');
            
        } catch (\Exception $e) {
            Db::rollback();
            LogHelper::error('取消开牌失败', [
                'error' => $e->getMessage()
            ]);
            show([], config('ToConfig.http_code.error'), '取消失败');
        }
    }
}