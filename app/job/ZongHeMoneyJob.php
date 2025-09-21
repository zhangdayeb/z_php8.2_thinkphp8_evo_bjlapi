<?php
namespace app\job;

use app\business\RequestUrl;
use app\business\Curl;
use app\controller\common\LogHelper;
use think\facade\Db;
use think\queue\Job;

/**
 * 统一的钱包通知队列（百家乐版）
 * 处理下注扣款和结算通知
 * 
 * Class ZongHeMoneyJob
 * @package app\job
 */
class ZongHeMoneyJob
{
    /**
     * 队列任务执行入口
     * 
     * @param Job $job 队列任务对象
     * @param array|null $data 任务数据
     * @return bool
     */
    public function fire(Job $job, $data = null)
    {
        // 根据type判断操作类型
        $type = $data['type'] ?? 'settle';
        
        LogHelper::info('=== 钱包通知队列开始 ===', [
            'type' => $type,
            'attempt' => $job->attempts(),
            'max_attempts' => 3,
            'queue_name' => 'bjl_zonghemoney_log_queue',
            'job_id' => $job->getJobId()
        ]);
        
        LogHelper::info('钱包通知任务数据', $data);

        $taskInfo = $data;

        // 根据类型分发处理
        if ($type === 'bet') {
            // 处理下注扣款
            $isJobDone = $this->processBet($data);
        } else {
            // 处理结算通知
            $isJobDone = $this->processWalletNotification($data);
        }

        if ($isJobDone) {
            if ($type === 'bet') {
                LogHelper::info('钱包下注扣款成功', [
                    'user_id' => $data['user_id'] ?? 'unknown',
                    'amount' => $data['total_amount'] ?? 0
                ]);
            } else {
                LogHelper::info('钱包结算通知成功', [
                    'user_id' => $data['userData']['id'] ?? $data['userData']['user_id'] ?? 'unknown',
                    'luzhu_id' => $data['luzhu_id'] ?? 'unknown'
                ]);
            }
            $job->delete();
            return true;
        }

        // 检查重试次数
        if ($job->attempts() > 3) {
            LogHelper::error('钱包通知失败 - 超过最大重试次数', [
                'type' => $type,
                'data' => $taskInfo,
                'attempts' => $job->attempts(),
                'final_failure' => true
            ]);

            // 记录最终失败的通知
            $this->recordFailedNotification($taskInfo);

            $job->delete();
            return true;
        }

        LogHelper::warning('钱包通知失败 - 将重试', [
            'type' => $type,
            'attempt' => $job->attempts()
        ]);

        // 返回 false 进行重试
        return false;
    }

    /**
     * 处理下注扣款
     * 
     * @param array $data 队列数据
     * @return bool
     */
    private function processBet($data): bool
    {
        try {
            LogHelper::info('处理百家乐下注扣款通知', [
                'user_id' => $data['user_id'],
                'user_name' => $data['user_name'],
                'amount' => $data['total_amount'],
                'table_id' => $data['table_id'],
                'xue_number' => $data['xue_number'],
                'pu_number' => $data['pu_number'],
                'is_modify' => $data['is_modify'],
                'action' => $data['total_amount'] > 0 ? '扣款' : ($data['total_amount'] < 0 ? '退款' : '无变动')
            ]);

            // 验证必要参数 - 兼容旧数据格式
            $userName = $data['user_name'];
            
            if (!$userName || !isset($data['total_amount'])) {
                LogHelper::error('下注参数缺失', $data);
                return true; // 参数错误不重试
            }

            // 如果金额为0，直接成功
            if ($data['total_amount'] == 0) {
                LogHelper::info('下注金额为0，无需扣款');
                return true;
            }

            // 构建URL
            $url = env('zonghepan.game_url', '0.0.0.0') . RequestUrl::bet();
            
            // 🔥 修改：生成唯一的下注交易ID（参考骰宝格式）
            $transactionId = sprintf(
                'BJL_BET_%s_U%d_T%d_X%d_P%d',
                date('YmdHis'),
                $data['user_id'],
                $data['table_id'],
                $data['xue_number'],
                $data['pu_number']
            );
            
            LogHelper::info('生成下注betId', [
                'bet_id' => $transactionId,
                'user_id' => $data['user_id'],
                'table_id' => $data['table_id'],
                'xue_number' => $data['xue_number'],
                'pu_number' => $data['pu_number']
            ]);
            
            // 构建下注扣款参数
            $params = [
                'user_name' => $userName,
                'betId' => $transactionId,  // 🔥 使用新的ID格式
                'externalTransactionId' => 'BJL_BET_TXN_' . $transactionId,  // 🔥 完整的交易ID
                'amount' => floatval($data['total_amount']),
                'gameCode' => 'XG_bjl',
                'roundId' => sprintf('%d_%d', $data['xue_number'], $data['pu_number']),
                'betTime' => intval(time() * 1000),
                'tableId' => $data['table_id'],
                'gameType' => $data['game_type'] ?? 3,  // 默认百家乐
                'isModify' => $data['is_modify'] ?? false,
                'actualBet' => $data['actual_bet'] ?? 0,
                'previousBet' => $data['previous_bet'] ?? 0
            ];

            LogHelper::info('钱包下注请求', [
                'url' => $url,
                'params' => $params
            ]);
            
            // 调用钱包API
            $response = Curl::post($url, $params, []);
            
            LogHelper::info('钱包下注响应', ['response' => $response]);
            
            // 处理响应
            if (is_array($response) && isset($response['code']) && $response['code'] == 200) {
                LogHelper::info('钱包下注扣款API调用成功', [
                    'user_name' => $userName,
                    'amount' => $data['total_amount'],
                    'is_modify' => $data['is_modify'] ?? false,
                    'transaction_id' => $transactionId
                ]);
                return true;
            }

            // API返回错误
            $errorMsg = $response['msg'] ?? $response['message'] ?? '未知错误';
            $errorCode = $response['code'] ?? 'unknown';

            LogHelper::warning('钱包下注API返回错误', [
                'user_name' => $userName,
                'error_code' => $errorCode,
                'error_msg' => $errorMsg,
                'response' => $response
            ]);
            
            // 判断是否需要重试
            return $this->shouldRetryBasedOnError($errorCode, $errorMsg);

        } catch (\Exception $e) {
            LogHelper::error('下注扣款异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false; // 异常需要重试
        }
    }

    /**
     * 处理钱包结算通知
     * 
     * @param array $data 队列数据
     * @return bool 是否成功
     */
    private function processWalletNotification($data): bool
    {
        try {
            LogHelper::info('开始处理钱包结算通知', [
                'luzhu_id' => $data['luzhu_id'] ?? 'unknown',
                'user_id' => $data['userData']['id'] ?? 'unknown'
            ]);

            // 验证必要数据
            if (!$this->validateQueueData($data)) {
                LogHelper::error('队列数据验证失败', $data);
                return true; // 数据错误不重试
            }

            // 从队列数据中提取参数
            $userData = $data['userData'] ?? [];
            $luzhu_id = $data['luzhu_id'] ?? 0;

            if (empty($userData) || $luzhu_id <= 0) {
                LogHelper::error('关键参数缺失', [
                    'userData_empty' => empty($userData),
                    'luzhu_id' => $luzhu_id
                ]);
                return true; // 参数错误不重试
            }

            // 调用钱包结算函数逻辑
            return $this->executeWalletSettlement($userData, $luzhu_id);

        } catch (\Exception $e) {
            LogHelper::error('钱包结算通知异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
            return false; // 异常情况需要重试
        }
    }

    /**
     * 验证队列数据完整性
     * 
     * @param array $data 队列数据
     * @return bool
     */
    private function validateQueueData($data): bool
    {
        $requiredFields = ['userData', 'luzhu_id'];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                LogHelper::warning("缺少必要字段: {$field}", $data);
                return false;
            }
        }

        return true;
    }

    /**
     * 执行钱包结算逻辑
     * 
     * @param array $userData 用户结算数据
     * @param int $luzhuId 露珠ID
     * @return bool
     */
    private function executeWalletSettlement($userData, $luzhuId): bool
    {
        try {
            // 从 userData 中获取用户ID
            $userId = $userData['id'] ?? $userData['user_id'] ?? null;
            
            if (!$userId) {
                LogHelper::warning('用户ID不存在', ['userData' => $userData]);
                return true; // 数据错误不重试
            }
            
            // 查询用户信息
            $userInfo = Db::table('ntp_common_user')
                ->where('id', $userId)
                ->field('user_name')
                ->find();
            
            if (empty($userInfo)) {
                LogHelper::warning('用户信息不存在', ['user_id' => $userId]);
                return true; // 用户不存在不重试
            }
            
            // 构建URL
            $url = env('zonghepan.game_url', '0.0.0.0') . RequestUrl::bet_result();
            
            LogHelper::info('准备调用钱包结算', [
                'url' => $url,
                'user' => $userInfo['user_name'],
                'luzhu_id' => $luzhuId,
                'user_id' => $userId
            ]);
            
            // 计算金额
            $betAmount = floatval($userData['bet_amt'] ?? 0);
            $winAmount = floatval($userData['money_balance_add_temp'] ?? 0);
            $winLoss = floatval($userData['win'] ?? 0);
            
            // 和局退款处理
            $isTieRefund = ($winLoss == 0 && $winAmount > 0 && $winAmount == $betAmount);
            
            LogHelper::info('结算金额计算', [
                'user_id' => $userId,
                'bet_amount' => $betAmount,
                'win_amount' => $winAmount, 
                'win_loss' => $winLoss,
                'is_tie_refund' => $isTieRefund
            ]);
            
            // 根据和局情况调整参数
            if ($isTieRefund) {
                // 和局退款：对钱包来说相当于用户赢得了本金
                $finalWinAmount = $betAmount;  // 总返还金额 = 本金
                $finalWinLoss = $betAmount;    // winLoss设为本金，表示赢了本金
                $resultType = 'WIN';           // 对钱包来说是WIN（游戏内部是和局）
                
                LogHelper::info('和局退款处理 - 对钱包当作赢钱', [
                    'user_id' => $userId,
                    'bet_amount' => $betAmount,
                    'win_amount' => $finalWinAmount,
                    'win_loss' => $finalWinLoss,
                    'result_type' => $resultType,
                    'note' => '游戏内部是和局，但对钱包来说是赢钱'
                ]);
            } else {
                // 正常输赢情况
                $finalWinAmount = $winAmount > 0 ? ($betAmount + $winLoss) : 0;
                $finalWinLoss = $winLoss;
                $resultType = $winLoss > 0 ? 'WIN' : 'LOSE';
                
                LogHelper::info('正常输赢处理', [
                    'user_id' => $userId,
                    'bet_amount' => $betAmount,
                    'win_amount' => $finalWinAmount,
                    'win_loss' => $finalWinLoss,
                    'result_type' => $resultType
                ]);
            }
            
            // 🔥 修改：生成完全不同的结算ID（参考骰宝格式）
            $settlementId = sprintf(
                'BJL_SETTLE_%s_L%d_U%d',
                date('YmdHis'),
                $luzhuId,
                $userId
            );
            
            LogHelper::info('生成结算betId', [
                'settlement_id' => $settlementId,
                'luzhu_id' => $luzhuId,
                'user_id' => $userId
            ]);
            
            // 准备参数
            $params = [
                'user_name' => $userInfo['user_name'],
                'betId' => $settlementId,  // 🔥 修改：使用结算专用ID，不会与下注ID冲突
                'roundId' => (string)$luzhuId,
                'externalTransactionId' => 'BJL_SETTLE_TXN_' . $settlementId,  // 🔥 修改：结算交易ID
                'betAmount' => $betAmount,
                'winAmount' => $finalWinAmount,
                'effectiveTurnover' => $betAmount,
                'winLoss' => $finalWinLoss,
                'jackpotAmount' => 0,
                'resultType' => $resultType,
                'isFreespin' => 0,
                'isEndRound' => 1,
                'betTime' => intval((time() - 60) * 1000),
                'settledTime' => intval(time() * 1000),
                'gameCode' => 'XG_bjl',
            ];
            
            LogHelper::info('钱包结算请求参数', [
                'user' => $userInfo['user_name'],
                'settlement_id' => $settlementId,
                'params' => $params,
                'is_tie_refund' => $isTieRefund
            ]);
            
            // 调用钱包API
            $response = Curl::post($url, $params, []);
            
            LogHelper::info('钱包结算响应', [
                'user' => $userInfo['user_name'],
                'luzhu_id' => $luzhuId,
                'settlement_id' => $settlementId,
                'response' => $response
            ]);
            
            // 处理API响应
            return $this->handleAPIResponse($response, $userInfo, $luzhuId);
            
        } catch (\Exception $e) {
            LogHelper::error('executeWalletSettlement异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $userId ?? 'unknown',
                'luzhu_id' => $luzhuId
            ]);
            return false; // 异常需要重试
        }
    }

    /**
     * 处理API响应
     * 
     * @param mixed $response API响应
     * @param array $userInfo 用户信息
     * @param int $luzhuId 露珠ID
     * @return bool
     */
    private function handleAPIResponse($response, $userInfo, $luzhuId): bool
    {
        if (!is_array($response)) {
            LogHelper::warning('钱包API返回格式错误', [
                'user' => $userInfo['user_name'],
                'luzhu_id' => $luzhuId,
                'response_type' => gettype($response),
                'response' => $response
            ]);
            return false;
        }

        if (isset($response['code']) && $response['code'] == 200) {
            LogHelper::info('钱包API调用成功', [
                'user' => $userInfo['user_name'],
                'luzhu_id' => $luzhuId,
                'response' => $response
            ]);
            return true;
        }

        // API返回错误
        $errorMsg = $response['msg'] ?? $response['message'] ?? '未知错误';
        $errorCode = $response['code'] ?? 'unknown';

        LogHelper::warning('钱包API返回错误', [
            'user' => $userInfo['user_name'],
            'luzhu_id' => $luzhuId,
            'error_code' => $errorCode,
            'error_msg' => $errorMsg,
            'response' => $response
        ]);

        // 根据错误类型决定是否重试
        return $this->shouldRetryBasedOnError($errorCode, $errorMsg);
    }

    /**
     * 根据错误类型决定是否重试
     * 
     * @param mixed $errorCode 错误代码
     * @param string $errorMsg 错误消息
     * @return bool false=重试, true=不重试
     */
    private function shouldRetryBasedOnError($errorCode, $errorMsg): bool
    {
        // 不重试的错误类型
        $noRetryErrors = [
            // '400', // 参数错误
            // '401', // 认证失败
            // '403', // 权限不足
            // '404', // 接口不存在
            // 'USER_NOT_FOUND', // 用户不存在
            // 'DUPLICATE_TRANSACTION', // 重复交易
            // 'INSUFFICIENT_BALANCE', // 余额不足
        ];

        if (in_array($errorCode, $noRetryErrors)) {
            LogHelper::info('错误类型不需要重试', [
                'error_code' => $errorCode,
                'error_msg' => $errorMsg
            ]);
            return true; // 不重试
        }

        // 需要重试的错误类型（网络错误、服务器错误等）
        LogHelper::info('错误类型需要重试', [
            'error_code' => $errorCode,
            'error_msg' => $errorMsg
        ]);
        return false; // 重试
    }

    /**
     * 记录最终失败的通知
     * 可以用于后续手动补发或监控
     * 
     * @param array $data 失败的任务数据
     */
    private function recordFailedNotification($data): void
    {
        try {
            $type = $data['type'] ?? 'settle';
            
            // 记录到特殊日志
            LogHelper::error('钱包通知最终失败 - 需要人工处理', [
                'type' => $type,
                'game' => 'bjl',  // 🔥 添加游戏类型标识
                'user_id' => $type === 'bet' 
                    ? ($data['user_id'] ?? 'unknown')
                    : ($data['userData']['id'] ?? $data['userData']['user_id'] ?? 'unknown'),
                'luzhu_id' => $data['luzhu_id'] ?? 'unknown',
                'data' => $data,
                'timestamp' => date('Y-m-d H:i:s'),
                'action_required' => 'MANUAL_RETRY'
            ]);

            // 可选：写入数据库失败记录表
            // Db::table('wallet_failed_notifications')->insert([
            //     'type' => $type,
            //     'game' => 'bjl',
            //     'data' => json_encode($data),
            //     'created_at' => date('Y-m-d H:i:s')
            // ]);

        } catch (\Exception $e) {
            LogHelper::error('记录失败通知异常', [
                'error' => $e->getMessage()
            ]);
        }
    }
}