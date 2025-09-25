<?php
namespace app\controller\game;

use app\controller\Base;
use app\controller\common\LogHelper;
use app\model\Luzhu;
use app\model\Table;
use think\facade\Db;

/**
 * 台桌信息控制器
 * 处理百家乐游戏台桌相关的所有查询和操作
 */
class TableInfo extends Base
{
    /**
     * 获取台桌列表
     * @return string JSON响应
     */
    public function get_table_list(): string
    {
        try {
            // 游戏类型视图映射
            $gameTypeView = [
                '1' => '_nn',   // 牛牛
                '2' => '_lh',   // 龙虎
                '3' => '_bjl'   // 百家乐
            ];
            
            // 查询所有启用的台桌
            $tables = Table::where(['status' => 1])
                ->order('id asc')
                ->select()
                ->toArray();
            
            if (empty($tables)) {
                return show([], 1, '暂无可用台桌');
            }
            
            // 处理每个台桌的附加信息
            foreach ($tables as $k => $v) {
                // 设置视图类型
                $tables[$k]['viewType'] = $gameTypeView[$v['game_type']] ?? '_bjl';
                
                // 生成随机在线人数（演示用）
                $tables[$k]['number'] = rand(100, 3000);
                
                // 获取当前靴号
                $luZhu = Luzhu::where([
                    'status' => 1, 
                    'table_id' => $v['id']
                ])
                ->whereTime('create_time', 'today')
                ->order('id desc')
                ->find();
                
                $tables[$k]['xue_number'] = $luZhu ? $luZhu['xue_number'] : 1;
            }
            
            return show($tables, 1, '获取台桌列表成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取台桌列表失败', [
                'error' => $e->getMessage()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取台桌列表失败');
        }
    }
    
    /**
     * 获取台桌详细信息（合并后的方法）
     * 包含：基础信息、靴号铺号、视频地址、洗牌状态等
     * @return string JSON响应
     */
    public function get_table_info(): string
    {
        $tableId = $this->request->param('tableId', 0);
        $infoType = $this->request->param('infoType', 'all'); // all, basic, video, bet
        
        // 参数验证
        if (empty($tableId) || !is_numeric($tableId)) {
            return show([], config('ToConfig.http_code.error'), '台桌ID必填且必须为数字');
        }
        
        try {
            // 查询台桌信息
            $tableInfo = Table::find($tableId);
            if (empty($tableInfo)) {
                return show([], config('ToConfig.http_code.error'), '台桌不存在');
            }
            
            $tableData = $tableInfo->toArray();
            
            // 获取靴号和铺号
            $bureauInfo = xue_number($tableId);
            
            // 构建返回数据
            $returnData = [
                'id' => $tableData['id'],
                'table_name' => $tableData['lu_zhu_name'],
                'game_type' => $tableData['game_type'],
                'status' => $tableData['status'],
                'run_status' => $tableData['run_status'],
                'wash_status' => $tableData['wash_status'],
                
                // 靴号铺号信息
                'xue_number' => $bureauInfo['xue_number'],
                'pu_number' => $bureauInfo['pu_number'],
                'bureau_number' => $bureauInfo['bureau_number'] ?? '',
                
                // 倒计时信息
                'countdown_time' => $tableData['countdown_time'],
                'opening_countdown' => redis_get_table_opening_count_down($tableId),
                
                // 视频流地址
                'video_near' => $tableData['video_near'],
                'video_far' => $tableData['video_far'],
                
                // 限红信息（人民币）
                'limit_cny' => [
                    'banker_player' => [
                        'min' => $tableData['bjl_xian_hong_zhuang_xian_min'] ?? 0,
                        'max' => $tableData['bjl_xian_hong_zhuang_xian_max'] ?? 0
                    ],
                    'tie' => [
                        'min' => $tableData['bjl_xian_hong_he_min'] ?? 0,
                        'max' => $tableData['bjl_xian_hong_he_max'] ?? 0
                    ],
                    'pair' => [
                        'min' => $tableData['bjl_xian_hong_duizi_min'] ?? 0,
                        'max' => $tableData['bjl_xian_hong_duizi_max'] ?? 0
                    ],
                    'lucky6' => [
                        'min' => $tableData['bjl_xian_hong_lucky6_min'] ?? 0,
                        'max' => $tableData['bjl_xian_hong_lucky6_max'] ?? 0
                    ]
                ]
            ];
            
            // 根据请求类型返回不同的数据
            switch ($infoType) {
                case 'basic':
                    // 只返回基础信息
                    unset($returnData['video_near'], $returnData['video_far']);
                    break;
                case 'video':
                    // 只返回视频信息
                    $returnData = [
                        'id' => $tableData['id'],
                        'video_near' => $tableData['video_near'],
                        'video_far' => $tableData['video_far']
                    ];
                    break;
                case 'bet':
                    // 下注专用信息
                    unset($returnData['video_near'], $returnData['video_far']);
                    $returnData['is_table_xian_hong'] = $tableData['is_table_xian_hong'] ?? 0;
                    break;
            }
            
            LogHelper::debug('获取台桌信息成功', [
                'table_id' => $tableId,
                'info_type' => $infoType
            ]);
            
            return show($returnData, 1, '获取台桌信息成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取台桌信息失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取台桌信息失败');
        }
    }
    
    /**
     * 获取台桌统计数据
     * 统计当天的庄闲和等开奖结果
     * @return string JSON响应
     */
    public function get_table_count(): string
    {
        // 获取参数
        $tableId = $this->request->param('tableId', 0);
        $xueNumber = $this->request->param('xue', 0);
        $gameType = $this->request->param('gameType', 3);
        
        // 参数验证
        if (empty($tableId) || empty($xueNumber)) {
            return show([], config('ToConfig.http_code.error'), '参数不完整');
        }
        
        try {
            // 构建查询条件
            $map = [
                'status' => 1,
                'table_id' => $tableId,
                'xue_number' => $xueNumber,
                'game_type' => $gameType
            ];
            
            // 计算统计时间范围（从早上9点开始）
            $nowTime = time();
            $startTime = strtotime(date("Y-m-d 09:00:00"));
            if ($nowTime < $startTime) {
                $startTime = $startTime - 86400; // 前一天的9点
            }
            
            // 查询各种结果的数量
            $baseQuery = Luzhu::whereTime('create_time', '>=', date('Y-m-d H:i:s', $startTime))
                ->where($map);
            
            // 统计庄赢（包含所有庄赢的结果码）
            $zhuangCount = 0;
            $zhuangResults = ['1|%', '4|%', '6|%', '7|%', '9|%'];
            foreach ($zhuangResults as $pattern) {
                $count = (clone $baseQuery)->where('result', 'like', $pattern)->count();
                $zhuangCount += $count;
            }
            
            // 统计闲赢
            $xianCount = 0;
            $xianResults = ['2|%', '8|%'];
            foreach ($xianResults as $pattern) {
                $count = (clone $baseQuery)->where('result', 'like', $pattern)->count();
                $xianCount += $count;
            }
            
            // 统计和
            $heCount = (clone $baseQuery)->where('result', 'like', '3|%')->count();
            
            // 统计对子
            $zhuangDuiCount = (clone $baseQuery)->where('result', 'like', '%|1')->count();
            $xianDuiCount = (clone $baseQuery)->where('result', 'like', '%|2')->count();
            $shuangDuiCount = (clone $baseQuery)->where('result', 'like', '%|3')->count();
            
            // 处理双对的情况
            $zhuangDuiCount += $shuangDuiCount;
            $xianDuiCount += $shuangDuiCount;
            
            // 构建返回数据
            $returnData = [
                'zhuang' => $zhuangCount,
                'xian' => $xianCount,
                'he' => $heCount,
                'zhuangDui' => $zhuangDuiCount,
                'xianDui' => $xianDuiCount,
                'zhuangXianDui' => $shuangDuiCount,
                'total' => $zhuangCount + $xianCount + $heCount
            ];
            
            LogHelper::debug('获取台桌统计成功', [
                'table_id' => $tableId,
                'xue_number' => $xueNumber,
                'stats' => $returnData
            ]);
            
            return show($returnData, 1, '获取统计数据成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取台桌统计失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取统计数据失败');
        }
    }
    
    /**
     * 获取露珠列表
     * @return string JSON响应
     */
    public function get_lz_list(): string
    {
        $tableId = $this->request->param('tableId', 0);
        
        // 参数验证
        if (empty($tableId)) {
            return show([], config('ToConfig.http_code.error'), '台桌ID不存在');
        }
        
        try {
            // 验证台桌是否存在
            $table = Table::find($tableId);
            if (empty($table)) {
                return show([], config('ToConfig.http_code.error'), '台桌不存在');
            }
            
            // 检查洗牌状态
            if ($table['wash_status'] == 1) {
                return show([], 1, '正在洗牌中');
            }
            
            // 获取露珠数据
            $params = $this->request->param();
            $returnData = Luzhu::LuZhuList($params);
            
            LogHelper::debug('获取露珠列表成功', [
                'table_id' => $tableId,
                'count' => count($returnData)
            ]);
            
            return show($returnData, 1, '获取露珠列表成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取露珠列表失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取露珠列表失败');
        }
    }
    
    /**
     * 切换台桌洗牌状态
     * @return string JSON响应
     */
    public function get_table_wash_brand(): string
    {
        $tableId = $this->request->param('tableId', 0);
        
        // 参数验证
        if (empty($tableId)) {
            return show([], config('ToConfig.http_code.error'), '台桌ID必填');
        }
        
        try {
            // 查询台桌
            $table = Table::find($tableId);
            if (empty($table)) {
                return show([], config('ToConfig.http_code.error'), '台桌不存在');
            }
            
            // 切换洗牌状态
            $newStatus = $table->wash_status == 0 ? 1 : 0;
            $table->save(['wash_status' => $newStatus]);
            
            LogHelper::info('切换洗牌状态', [
                'table_id' => $tableId,
                'old_status' => $table->wash_status == $newStatus ? 0 : 1,
                'new_status' => $newStatus
            ]);
            
            $returnData = [
                'table_id' => $tableId,
                'wash_status' => $newStatus,
                'message' => $newStatus == 1 ? '开始洗牌' : '洗牌结束'
            ];
            
            return show($returnData, 1, '操作成功');
            
        } catch (\Exception $e) {
            LogHelper::error('切换洗牌状态失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            return show([], config('ToConfig.http_code.error'), '操作失败');
        }
    }
}