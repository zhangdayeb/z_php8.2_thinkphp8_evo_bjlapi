<?php
namespace app\job;

use app\controller\common\LogHelper;
use app\service\CardSettlementService;
use think\facade\Log;
use think\queue\Job;

/**
 * 开牌后用户结算
 * Class UserSettleTaskJob
 * @package app\job
 */
class UserSettleTaskJob
{
    public function fire(Job $job, $data = null)
    {
        LogHelper::debug('=== 结算队列任务开始 ===', [
            'attempt' => $job->attempts(),
            'max_attempts' => 3,
            'queue_name' => 'bjl_open_queue'
        ]);
        
        LogHelper::debug('任务数据', $data);

        $info = $data;

        #逻辑执行
        $isJobDone = $this->doHelloJob($data);

        if ($isJobDone){
            LogHelper::debug('结算队列任务执行成功');
            $job->delete();
            return true;
        }
        #逻辑执行结束
        if ($job->attempts() > 3) {
            LogHelper::error('结算队列任务失败 - 超过最大重试次数', [
                'data' => $info,
                'attempts' => $job->attempts()
            ]);

            $job->delete();
            return true;
            //通过这个方法可以检查这个任务已经重试了几次了
        }

        LogHelper::warning('结算队列任务失败 - 将重试', [
            'attempt' => $job->attempts(),
            'data' => $info
        ]);

        // ✅ 修复：明确返回 false 进行重试
        return false;

    }
    private function doHelloJob($data) {
        
        LogHelper::debug('开始执行具体结算逻辑');

        // 根据消息中的数据进行实际的业务处理...
        if (empty($data)){
            LogHelper::warning('结算数据为空');
            return true;
        }

        $luzhu_id = $data['luzhu_id'];
        unset($data['luzhu_id']);

        LogHelper::debug('调用结算服务', ['luzhu_id' => $luzhu_id]);

        $card_service = new CardSettlementService();
        $res = $card_service->user_settlement($luzhu_id,$data);

        if (!$res){
            LogHelper::error('结算服务执行失败', ['luzhu_id' => $luzhu_id]);
            return false;
        }

        LogHelper::debug('结算服务执行成功', ['luzhu_id' => $luzhu_id]);
        return true;
    }
}