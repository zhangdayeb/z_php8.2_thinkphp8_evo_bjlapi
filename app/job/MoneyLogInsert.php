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
        try {
            // 执行业务逻辑
            $res = $this->doJob($data);
            
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

    public function doJob($data)
    {
        // 这里执行 洗码 业务 资金日志等等 标记
        
        // 执行成功返回 true，失败返回 false
        return true;
    }
}