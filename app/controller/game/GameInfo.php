<?php
namespace app\controller\game;

use app\controller\Base;
use app\controller\common\LogHelper;
use app\model\GameRecords;
use app\model\Odds;
use think\facade\Db;

/**
 * 游戏信息控制器
 * 处理游戏相关的数据查询，如投注历史等
 */
class GameInfo extends Base
{
    /**
     * 获取用户投注历史记录
     * @return string JSON响应
     */
    public function get_user_bet_history(): string
    {
        // 获取请求参数
        $params = $this->getRequestParams();
        
        // 参数验证
        $validation = $this->validateParams($params);
        if ($validation !== true) {
            show([], config('ToConfig.http_code.error'), $validation);
        }
        
        try {
            // 构建查询条件
            $where = $this->buildQueryConditions($params);
            
            // 查询总记录数
            $total = Db::name('dianji_records')
                ->where($where)
                ->count();
            
            // 分页查询记录
            $records = Db::name('dianji_records')
                ->where($where)
                ->order('created_at desc')
                ->page($params['page'], $params['page_size'])
                ->select()
                ->toArray();
            
            // 格式化记录数据
            $formattedRecords = $this->formatRecords($records);
            
            // 构建返回结果
            $result = $this->buildResponse($formattedRecords, $total, $params);
            
            LogHelper::debug('获取投注历史成功', [
                'user_id' => $params['user_id'],
                'total_records' => $total,
                'page' => $params['page']
            ]);
            
            show($result, 1, '获取投注历史成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取投注历史失败', [
                'user_id' => $params['user_id'],
                'error' => $e->getMessage()
            ]);
            show([], config('ToConfig.http_code.error'), '获取投注历史失败');
        }
    }
    
    /**
     * 获取请求参数
     * @return array
     */
    private function getRequestParams(): array
    {
        return [
            'user_id' => $this->request->param('user_id/d', 0),
            'table_id' => $this->request->param('table_id', ''),
            'game_type' => $this->request->param('game_type/d', 3),
            'page' => $this->request->param('page/d', 1),
            'page_size' => $this->request->param('page_size/d', 20),
            'status' => $this->request->param('status', ''),
            'start_date' => $this->request->param('start_date', ''),
            'end_date' => $this->request->param('end_date', ''),
            'bet_type' => $this->request->param('bet_type', ''),
            'xue_number' => $this->request->param('xue_number', ''),
            'pu_number' => $this->request->param('pu_number', '')
        ];
    }
    
    /**
     * 验证参数
     * @param array $params 参数数组
     * @return bool|string
     */
    private function validateParams(array $params)
    {
        if ($params['user_id'] <= 0) {
            return '用户ID必填';
        }
        
        if (empty($params['table_id'])) {
            return '台桌ID必填';
        }
        
        if ($params['page'] < 1) {
            $params['page'] = 1;
        }
        
        if ($params['page_size'] < 1 || $params['page_size'] > 100) {
            $params['page_size'] = 20;
        }
        
        return true;
    }
    
    /**
     * 构建查询条件
     * @param array $params 参数数组
     * @return array
     */
    private function buildQueryConditions(array $params): array
    {
        // 基础查询条件
        $where = [
            ['user_id', '=', $params['user_id']],
            ['table_id', '=', $params['table_id']],
            ['game_type', '=', $params['game_type']]
        ];
        
        // 状态筛选
        if (!empty($params['status']) && $params['status'] != 'all') {
            $this->addStatusCondition($where, $params['status']);
        }
        
        // 时间范围筛选
        if (!empty($params['start_date'])) {
            $where[] = ['created_at', '>=', $params['start_date'] . ' 00:00:00'];
        }
        
        if (!empty($params['end_date'])) {
            $where[] = ['created_at', '<=', $params['end_date'] . ' 23:59:59'];
        }
        
        // 投注类型筛选
        if (!empty($params['bet_type']) && $params['bet_type'] != 'all') {
            $where[] = ['game_peilv_id', '=', $this->getBetTypeId($params['bet_type'])];
        }
        
        // 靴号筛选
        if (!empty($params['xue_number'])) {
            $where[] = ['xue_number', '=', $params['xue_number']];
        }
        
        // 铺号筛选
        if (!empty($params['pu_number'])) {
            $where[] = ['pu_number', '=', $params['pu_number']];
        }
        
        return $where;
    }
    
    /**
     * 添加状态查询条件
     * @param array &$where 查询条件引用
     * @param string $status 状态
     * @return void
     */
    private function addStatusCondition(array &$where, string $status): void
    {
        $statusMap = [
            'pending' => 1,    // 待开牌
            'settled' => 2,    // 已结算
            'cancelled' => 3,  // 已取消
            'processing' => 4  // 处理中
        ];
        
        if (isset($statusMap[$status])) {
            $where[] = ['close_status', '=', $statusMap[$status]];
            
            // 如果是已结算，可以进一步区分输赢
            if ($status == 'settled') {
                $subStatus = $this->request->param('sub_status', '');
                if ($subStatus == 'win') {
                    $where[] = ['win_amt', '>', 0];
                } elseif ($subStatus == 'lose') {
                    $where[] = ['win_amt', '=', 0];
                }
            }
        }
    }
    
    /**
     * 获取投注类型ID
     * @param string $betType 投注类型
     * @return int
     */
    private function getBetTypeId(string $betType): int
    {
        $betTypeMap = [
            'banker' => 8,      // 庄
            'player' => 6,      // 闲
            'tie' => 7,         // 和
            'banker_pair' => 4, // 庄对
            'player_pair' => 2, // 闲对
            'lucky6' => 3       // 幸运6
        ];
        
        return $betTypeMap[$betType] ?? 0;
    }
    
    /**
     * 格式化记录数据
     * @param array $records 原始记录
     * @return array
     */
    private function formatRecords(array $records): array
    {
        $formattedRecords = [];
        
        foreach ($records as $record) {
            $formattedRecords[] = $this->formatSingleRecord($record);
        }
        
        return $formattedRecords;
    }
    
    /**
     * 格式化单条记录
     * @param array $record 单条记录
     * @return array
     */
    private function formatSingleRecord(array $record): array
    {
        // 获取投注类型名称
        $betTypeName = $this->getBetTypeName($record['game_peilv_id']);
        
        // 判断输赢状态
        $isWin = false;
        $status = 'pending';
        
        switch ($record['close_status']) {
            case 1:
                $status = 'pending';
                break;
            case 2:
                $status = 'settled';
                $isWin = $record['win_amt'] > 0;
                break;
            case 3:
                $status = 'cancelled';
                break;
            case 4:
                $status = 'processing';
                break;
        }
        
        return [
            'id' => (string)$record['id'],
            'table_id' => $record['table_id'],
            'xue_number' => $record['xue_number'],
            'pu_number' => $record['pu_number'],
            'user_id' => (string)$record['user_id'],
            'bet_time' => $record['created_at'],
            'settle_time' => $record['updated_at'],
            'bet_type' => $betTypeName,
            'bet_type_id' => $record['game_peilv_id'],
            'bet_amount' => floatval($record['bet_amt']),
            'win_amount' => floatval($record['win_amt']),
            'net_amount' => floatval($record['delta_amt']),
            'odds' => floatval($record['game_peilv']),
            'status' => $status,
            'is_win' => $isWin,
            'is_settled' => $record['close_status'] == 2,
            'is_exempt' => $record['is_exempt'] ?? 0,
            'shuffling_amt' => floatval($record['shuffling_amt'] ?? 0),
            'shuffling_rate' => floatval($record['shuffling_rate'] ?? 0),
            'currency' => 'CNY'
        ];
    }
    
    /**
     * 获取投注类型名称
     * @param int $betTypeId 投注类型ID
     * @return string
     */
    private function getBetTypeName(int $betTypeId): string
    {
        $betTypeNames = [
            2 => '闲对',
            3 => '幸运6',
            4 => '庄对',
            6 => '闲',
            7 => '和',
            8 => '庄',
            9 => '龙7',
            10 => '熊8'
        ];
        
        return $betTypeNames[$betTypeId] ?? '未知';
    }
    
    /**
     * 构建响应数据
     * @param array $records 格式化后的记录
     * @param int $total 总记录数
     * @param array $params 请求参数
     * @return array
     */
    private function buildResponse(array $records, int $total, array $params): array
    {
        $totalPages = ceil($total / $params['page_size']);
        
        // 计算统计信息
        $stats = $this->calculateStats($params);
        
        return [
            'records' => $records,
            'pagination' => [
                'current_page' => $params['page'],
                'total_pages' => $totalPages,
                'total_records' => $total,
                'page_size' => $params['page_size'],
                'has_more' => $params['page'] < $totalPages
            ],
            'stats' => $stats
        ];
    }
    
    /**
     * 计算统计信息
     * @param array $params 请求参数
     * @return array
     */
    private function calculateStats(array $params): array
    {
        try {
            // 基础查询条件
            $where = [
                ['user_id', '=', $params['user_id']],
                ['table_id', '=', $params['table_id']],
                ['game_type', '=', $params['game_type']],
                ['close_status', '=', 2] // 只统计已结算的
            ];
            
            // 添加时间范围
            if (!empty($params['start_date'])) {
                $where[] = ['created_at', '>=', $params['start_date'] . ' 00:00:00'];
            }
            
            if (!empty($params['end_date'])) {
                $where[] = ['created_at', '<=', $params['end_date'] . ' 23:59:59'];
            }
            
            // 查询统计数据
            $stats = Db::name('dianji_records')
                ->where($where)
                ->field([
                    'COUNT(*) as total_bets',
                    'SUM(bet_amt) as total_bet_amount',
                    'SUM(win_amt) as total_win_amount',
                    'SUM(delta_amt) as total_net_amount',
                    'SUM(CASE WHEN win_amt > 0 THEN 1 ELSE 0 END) as win_count'
                ])
                ->find();
            
            return [
                'total_bets' => intval($stats['total_bets'] ?? 0),
                'total_bet_amount' => floatval($stats['total_bet_amount'] ?? 0),
                'total_win_amount' => floatval($stats['total_win_amount'] ?? 0),
                'total_net_amount' => floatval($stats['total_net_amount'] ?? 0),
                'win_count' => intval($stats['win_count'] ?? 0),
                'lose_count' => intval($stats['total_bets'] ?? 0) - intval($stats['win_count'] ?? 0),
                'win_rate' => $stats['total_bets'] > 0 
                    ? round(($stats['win_count'] / $stats['total_bets']) * 100, 2) 
                    : 0
            ];
            
        } catch (\Exception $e) {
            LogHelper::error('计算统计信息失败', [
                'error' => $e->getMessage()
            ]);
            
            return [
                'total_bets' => 0,
                'total_bet_amount' => 0,
                'total_win_amount' => 0,
                'total_net_amount' => 0,
                'win_count' => 0,
                'lose_count' => 0,
                'win_rate' => 0
            ];
        }
    }
}