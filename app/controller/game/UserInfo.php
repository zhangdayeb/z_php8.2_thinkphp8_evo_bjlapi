<?php
namespace app\controller\game;

use app\controller\Base;
use app\controller\common\LogHelper;
use app\model\UserModel;
use app\model\HomeTokenModel;
use app\model\GameRecords;
use think\facade\Db;

/**
 * 用户信息控制器
 * 处理用户相关的查询和操作
 */
class UserInfo extends Base
{
    /**
     * 获取用户详细信息
     * 用于荷官端、客服端、管理端查询用户资料
     * @return string JSON响应
     */
    public function get_user_info(): string
    {
        LogHelper::debug('=== 获取用户信息请求开始 ===');
        
        // 获取参数
        $userId = $this->request->param('user_id', 0);
        $infoType = $this->request->param('info_type', 'basic'); // basic, full, balance
        
        // 参数验证
        if (empty($userId) || !is_numeric($userId)) {
            LogHelper::warning('用户ID参数无效', ['user_id' => $userId]);
            return show([], config('ToConfig.http_code.error'), '用户ID必填且必须为数字');
        }
        
        $userId = intval($userId);
        
        try {
            // 查询用户基础信息
            $userInfo = UserModel::find($userId);
            
            // 验证用户是否存在
            if (empty($userInfo)) {
                LogHelper::warning('用户不存在', ['user_id' => $userId]);
                return show([], config('ToConfig.http_code.error'), '用户不存在');
            }
            
            // 更新或创建Token记录
            $this->updateUserToken($userId);
            
            // 转换为数组
            $userData = $userInfo->toArray();
            
            // 移除敏感信息
            unset($userData['pwd']);
            unset($userData['withdraw_pwd']);
            unset($userData['pay_pwd']);
            
            // 构建基础返回数据
            $returnData = [
                'id' => $userData['id'],
                'user_name' => $userData['user_name'],
                'nick_name' => $userData['nick_name'] ?? '',
                'avatar' => $userData['avatar'] ?? '',
                'status' => $userData['status'],
                'status_text' => $userData['status'] == 1 ? '正常' : '禁用',
                'created_at' => $userData['created_at'],
                'last_login_time' => $userData['last_login_time'] ?? '',
                'last_login_ip' => $userData['last_login_ip'] ?? ''
            ];
            
            // 根据请求类型添加额外信息
            switch ($infoType) {
                case 'balance':
                    // 只返回余额信息
                    $returnData['balance_info'] = $this->getUserBalance($userData);
                    break;
                    
                case 'full':
                    // 返回完整信息
                    $returnData['balance_info'] = $this->getUserBalance($userData);
                    $returnData['account_info'] = $this->getUserAccountInfo($userData);
                    $returnData['bet_stats'] = $this->getUserBetStats($userId);
                    break;
                    
                default:
                    // 基础信息 + 余额
                    $returnData['balance_info'] = $this->getUserBalance($userData);
                    break;
            }
            
            LogHelper::debug('用户信息查询成功', [
                'user_id' => $userId,
                'user_name' => $userData['user_name'],
                'info_type' => $infoType
            ]);
            
            return show($returnData, 1, '获取用户信息成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取用户信息失败', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取用户信息失败');
        }
    }
    
    /**
     * 获取用户余额信息
     * @param array $userData 用户数据
     * @return array
     */
    private function getUserBalance(array $userData): array
    {
        return [
            'money_balance' => floatval($userData['money_balance'] ?? 0),
            'money_balance_formatted' => number_format($userData['money_balance'] ?? 0, 2),
            'rebate_balance' => floatval($userData['rebate_balance'] ?? 0),
            'rebate_balance_formatted' => number_format($userData['rebate_balance'] ?? 0, 2),
            'rebate_total' => floatval($userData['rebate_total'] ?? 0),
            'rebate_total_formatted' => number_format($userData['rebate_total'] ?? 0, 2),
            'currency' => 'CNY'
        ];
    }
    
    /**
     * 获取用户账号信息
     * @param array $userData 用户数据
     * @return array
     */
    private function getUserAccountInfo(array $userData): array
    {
        // 账号类型映射
        $fictitiousTypes = [
            0 => '正常账号',
            1 => '虚拟账号',
            2 => '试玩账号'
        ];
        
        return [
            'is_fictitious' => $userData['is_fictitious'] ?? 0,
            'is_fictitious_text' => $fictitiousTypes[$userData['is_fictitious'] ?? 0] ?? '未知',
            'xima_lv' => floatval($userData['xima_lv'] ?? 0),
            'vip_level' => $userData['vip_level'] ?? 0,
            'agent_id' => $userData['agent_id'] ?? 0,
            'parent_id' => $userData['parent_id'] ?? 0,
            'phone' => !empty($userData['phone']) ? substr_replace($userData['phone'], '****', 3, 4) : '',
            'email' => $userData['email'] ?? ''
        ];
    }
    
    /**
     * 获取用户投注统计
     * @param int $userId 用户ID
     * @return array
     */
    private function getUserBetStats(int $userId): array
    {
        try {
            // 今日统计
            $todayStats = GameRecords::where('user_id', $userId)
                ->whereTime('created_at', 'today')
                ->field([
                    'COUNT(*) as bet_count',
                    'SUM(bet_amt) as bet_amount',
                    'SUM(win_amt) as win_amount',
                    'SUM(CASE WHEN win_amt > 0 THEN 1 ELSE 0 END) as win_count'
                ])
                ->find();
            
            // 总统计
            $totalStats = GameRecords::where('user_id', $userId)
                ->field([
                    'COUNT(*) as bet_count',
                    'SUM(bet_amt) as bet_amount',
                    'SUM(win_amt) as win_amount',
                    'SUM(CASE WHEN win_amt > 0 THEN 1 ELSE 0 END) as win_count'
                ])
                ->find();
            
            return [
                'today' => [
                    'bet_count' => intval($todayStats['bet_count'] ?? 0),
                    'bet_amount' => floatval($todayStats['bet_amount'] ?? 0),
                    'win_amount' => floatval($todayStats['win_amount'] ?? 0),
                    'win_count' => intval($todayStats['win_count'] ?? 0),
                    'win_rate' => $todayStats['bet_count'] > 0 
                        ? round(($todayStats['win_count'] / $todayStats['bet_count']) * 100, 2) 
                        : 0
                ],
                'total' => [
                    'bet_count' => intval($totalStats['bet_count'] ?? 0),
                    'bet_amount' => floatval($totalStats['bet_amount'] ?? 0),
                    'win_amount' => floatval($totalStats['win_amount'] ?? 0),
                    'win_count' => intval($totalStats['win_count'] ?? 0),
                    'win_rate' => $totalStats['bet_count'] > 0 
                        ? round(($totalStats['win_count'] / $totalStats['bet_count']) * 100, 2) 
                        : 0
                ]
            ];
            
        } catch (\Exception $e) {
            LogHelper::error('获取用户投注统计失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
            return [
                'today' => [
                    'bet_count' => 0,
                    'bet_amount' => 0,
                    'win_amount' => 0,
                    'win_count' => 0,
                    'win_rate' => 0
                ],
                'total' => [
                    'bet_count' => 0,
                    'bet_amount' => 0,
                    'win_amount' => 0,
                    'win_count' => 0,
                    'win_rate' => 0
                ]
            ];
        }
    }
    
    /**
     * 更新用户Token
     * @param int $userId 用户ID
     * @return void
     */
    private function updateUserToken(int $userId): void
    {
        try {
            $token = $this->request->header('x-csrf-token');
            if (empty($token)) {
                return;
            }
            
            $homeTokenModel = new HomeTokenModel();
            
            // 尝试更新已存在的Token
            $updated = $homeTokenModel->where('user_id', $userId)
                ->update([
                    'token' => $token,
                    'create_time' => date('Y-m-d H:i:s')
                ]);
            
            // 如果不存在则插入新记录
            if ($updated == 0) {
                $homeTokenModel->insert([
                    'token' => $token,
                    'user_id' => $userId,
                    'create_time' => date('Y-m-d H:i:s')
                ]);
            }
            
            LogHelper::debug('更新用户Token成功', [
                'user_id' => $userId,
                'token' => substr($token, 0, 10) . '...'
            ]);
            
        } catch (\Exception $e) {
            LogHelper::error('更新用户Token失败', [
                'user_id' => $userId,
                'error' => $e->getMessage()
            ]);
        }
    }
}