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
        $user_id = $this->request->param('user_id', 0);
        
        // 参数验证
        if (empty($user_id) || !is_numeric($user_id)) {
            LogHelper::warning('用户ID参数无效', ['user_id' => $user_id]);
            show([], config('ToConfig.http_code.error'), '用户ID必填且必须为数字');
        }
        
        $user_id = intval($user_id);
        
        LogHelper::debug('查询用户信息', ['user_id' => $user_id]);
        
        try {
            // 查询用户基础信息
            $userInfo = UserModel::where('id', $user_id)->find();
            // 先检查用户是否存在
            if (empty($userInfo)) {
                LogHelper::warning('用户不存在', ['user_id' => $user_id]);
                show([], config('ToConfig.http_code.error'), '用户不存在');
            }
            
            $token = $this->request->header('x-csrf-token');
            $HomeTokenModel = new HomeTokenModel();
            //查询是否存在这条token的用户
            $update = $HomeTokenModel->where('user_id', $userInfo['id'])
                ->update(['token' => $token, 'create_time' => date('Y-m-d H:i:s')]);

            //数据不存在时插入
            if ($update == 0) {
                $HomeTokenModel->insert([
                    'token' => $token, 'user_id' => $userInfo['id'], 'create_time' => date('Y-m-d H:i:s')
                ]);
            }
            
            // 转换为数组便于处理
            $userData = $userInfo->toArray();
            
            // 移除敏感信息
            unset($userData['pwd']);
            unset($userData['withdraw_pwd']);
            
            // 格式化金额字段（确保字段存在）
            $userData['money_balance'] = number_format($userData['money_balance'] ?? 0, 2);
            $userData['rebate_balance'] = number_format($userData['rebate_balance'], 2);
            $userData['rebate_total'] = number_format($userData['rebate_total'], 2);
            
            // 虚拟账号类型
            $fictitiousTypes = [
                0 => '正常账号',
                1 => '虚拟账号',
                2 => '试玩账号'
            ];
            $userData['is_fictitious_text'] = $fictitiousTypes[$userData['is_fictitious'] ?? 0] ?? '未知';
            
            LogHelper::debug('用户信息查询成功', [
                'user_id' => $user_id,
                'user_name' => $userData['user_name'] ?? 'unknown',
                'balance' => $userData['money_balance']
            ]);
            
            show($userData, 1, '获取用户信息成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取用户信息失败', [
                'user_id' => $user_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            show([], config('ToConfig.http_code.error'), '获取用户信息失败：' . $e->getMessage());
        }
    }
}