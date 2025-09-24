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