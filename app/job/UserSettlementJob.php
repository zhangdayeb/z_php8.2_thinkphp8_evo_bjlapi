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
        $card_service = new CardSettlementService();
        $card_service->user_settlement($data);
        return true;
    }
}