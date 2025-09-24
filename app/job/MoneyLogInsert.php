<?php


namespace app\job;
use app\controller\common\LogHelper;
use app\model\MoneyLog;

use think\queue\Job;

/**
 * 开牌之后主动写入资金记录
 * Class MoneyLogInsert
 * @package app\job
 */
class MoneyLogInsert
{
    public function fire(Job $job)
    {
        $res = $this->consumption();
        if ($res) {
            $job->delete();
            return;
        }
        #逻辑执行结束
        if ($job->attempts() > 3) {
            $job->delete();
            return;
            //通过这个方法可以检查这个任务已经重试了几次了
        }
    }

    public function consumption()
    {
        // 这里执行 洗码 业务 资金日志等等 标记
        return true;
    }

}