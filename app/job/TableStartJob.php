<?php

namespace app\job;

use app\model\Table;
use think\queue\Job;
use Workerman\Timer;
use Workerman\Worker;

class TableStartJob
{
    public function fire(Job $job, $data = null)
    {
        if (empty($data) || !isset($data['table_id'])) {
            $job->delete();
            return;
        }
        
        $table_id = $data['table_id'];
        $redis_key = 'table_opening_count_down_table_' . $table_id;
        $remaining = $data['countdown_time'];
        
        // 初始化 Worker 事件循环（重要）
        if (!Worker::getAllWorkers()) {
            Worker::$globalEvent = '\Workerman\Events\Select';
        }
        
        // 使用 Workerman 定时器，每秒执行一次
        $timer_id = Timer::add(1, function() use (&$remaining, &$timer_id, $redis_key, $table_id) {
            // 存储当前倒计时到 Redis
            redis()->setex($redis_key, 5, $remaining);
            
            // 倒计时结束
            if ($remaining <= 0) {
                Table::where('id', $table_id)->update([
                    'status' => 1,
                    'run_status' => 2,
                    'update_time' => time()
                ]);
                
                // 删除定时器
                Timer::del($timer_id);
                return;
            }
            
            // 继续倒计时
            $remaining--;
        });
        
        // 任务完成后删除 job
        $job->delete();
        
        // 运行事件循环（阻塞式）
        Worker::runAll();
    }
}