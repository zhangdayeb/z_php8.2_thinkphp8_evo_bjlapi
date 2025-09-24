<?php

namespace app\job;

use app\model\Table;
use think\queue\Job;

/**
 * 最简单的队列递归倒计时
 * 使用 release(1) 每秒执行一次
 */
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
        
        // 初始化
        if (!isset($data['remaining'])) {
            $data['remaining'] = $data['countdown_time'];
        }
        
        // 存储当前倒计时到 Redis
        redis()->setex($redis_key, $data['remaining'] + 10, $data['remaining']);
        
        // 倒计时结束
        if ($data['remaining'] <= 0) {
            Table::where('id', $table_id)->update([
                'status' => 1,
                'run_status' => 2,
                'update_time' => time()
            ]);
            $job->delete();
            return;
        }
        
        // 继续倒计时
        $data['remaining']--;
        $job->release(1);  // 1秒后重新执行
    }
}

/**
 * 使用方法：
 * 
 * use think\Queue;
 * 
 * // 开始倒计时
 * Queue::push('app\job\TableStartJob', [
 *     'table_id' => 1,
 *     'countdown_time' => 60  // 60秒
 * ]);
 * 
 * // 获取倒计时（在任何地方）
 * $remaining = redis()->get('table_opening_count_down_table_1');
 * echo "剩余：{$remaining}秒";
 */