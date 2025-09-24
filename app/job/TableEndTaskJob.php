<?php


namespace app\job;
use app\controller\common\LogHelper;
use app\model\Table;
use think\queue\Job;

/**
 * 开牌之后 主动设定结束
 * Class TableEndTaskJob
 * @package app\job
 */
class TableEndTaskJob
{
    public function fire(Job $job, $data = null)
    {
 
        $isJobDone = $this->doHelloJob($data);
     
        if ($isJobDone){
            $job->delete();
            return true;
        }

        #逻辑执行结束
        if ($job->attempts() > 3) {
            $job->delete();
            return true;
        }

    }

    private function doHelloJob($data) {
        // 根据消息中的数据进行实际的业务处理...
    
        if (empty($data)){
            return true;
        } 
        
        $find = Table::where('id', $data['table_id'])->find();
        $find = Table::table_opening_count_down($find);
        if(isset($find->end_time) && $find->end_time > 0){
            return true;
        }
        if (isset($find['end_time']) && $find['end_time'] > 0){
            
        }

       $a= Table::where('id', $data['table_id'])
            ->update([
                'status' => 1,
                'run_status' => 2,
                'update_time' => time(),
                //'remark'=>$data['table_id'].'触发'.time().'倒计时'.$find->end_time
            ]);
  
        return true;
    }
}