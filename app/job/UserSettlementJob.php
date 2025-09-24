<?php
namespace app\job;

use app\controller\common\LogHelper;
use app\service\CardSettlementService;
use think\queue\Job;

/**
 * 开牌后用户结算
 * Class UserSettlementJob
 * @package app\job
 */
class UserSettlementJob
{
    public function fire(Job $job, $data = null)
    {
        try {
            // 执行用户结算
            $card_service = new CardSettlementService();
            $res = $card_service->user_settlement($data);
            
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
}