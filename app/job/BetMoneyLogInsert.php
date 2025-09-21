<?php


namespace app\job;
use app\controller\common\LogHelper;
use app\model\MoneyLog;

use think\queue\Job;

/**##############################不要了，走定时任务
 * 开牌之后主动写入资金记录
 * Class BetMoneyLogInsert
 * @package app\job
 */
class BetMoneyLogInsert
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
        //如果任务执行成功后 记得删除任务，不然这个任务会重复执行，直到达到最大重试次数后失败后，执行failed方法
        // 也可以重新发布这个任务
        //$job->release(0); //$delay为延迟时间
    }

    public function consumption()
    {
        //获取资金记录redis
        $list = redis()->LRANGE('bet_settlement_money_log',0, -1);
        if (empty($list)) return true;
        foreach ($list as $item => $value) {
            $valueData = array();
            $valueData = json_decode($value, true);
           $insert = MoneyLog::insert($valueData);
            if ($insert){
                redis()->LREM('bet_settlement_money_log', $value);//删除当前已经计算过的值
            }else{
                return false;
            }
        }
        return true;
    }

}