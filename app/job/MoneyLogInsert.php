<?php
namespace app\job;

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
use think\queue\Job;

/**
 * 开牌之后主动写入资金记录
 * Class MoneyLogInsert
 * @package app\job
 */
class MoneyLogInsert
{
    public function fire(Job $job, $data = null)
    {
        $res = $this->doJob($data);
        if ($res) {
            $job->delete();
            return;
        }
        #逻辑执行结束
        if ($job->attempts() > 3) {
            $job->delete();
            return;
        }
    }

    public function doJob($data)
    {
        // 这里执行 洗码 业务 资金日志等等 标记
        return true;
    }

}