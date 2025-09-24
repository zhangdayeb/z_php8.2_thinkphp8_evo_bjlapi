<?php

use Workerman\Worker;
use Workerman\Timer;
use app\controller\common\LogHelper;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../common.php';

// 初始化一个worker容器，监听端口
$worker = new Worker('websocket://0.0.0.0:2003');
// ====这里进程数必须必须必须设置为1 否则多进程通信 数据混乱====
$worker->count = 1;
// 新增加一个属性，用来保存uid到connection的映射
$worker->uidConnections = array();

// 当有客户端发来消息时执行的回调函数
$worker->onMessage = function ($connection, $data) use ($worker) {

    // 记录请求数，达到一定数量后重启进程，防止内存泄漏  请求数达到10000后退出当前进程，主进程会自动重启一个新的进程
    static $request_count;
    if (++$request_count > 10000) {
        Worker::stopAll();
    }

    //  心跳检测
    if($data == 'ping'){
        return $connection->send('pong');
    }

    // 解析客户端传过来的数据
    $data = json_decode($data, true);
    $connection->lastMessageTime = time();
    if (!isset($data['user_id']) || !isset($data['table_id']) || !isset($data['game_type'])) {
        return $connection->send('连接成功，参数错误');
    }

    // 加入连接池
    if (!isset($connection->uid)) {
        $connection->uid = $data['user_id'];
        $connection->data_info = $data;
        $worker->uidConnections[$connection->uid] = $connection;
        return $connection->send(json_encode(['code' => 200, 'msg' => '初始化链接成功'));
    }
};

// 添加定时任务 每秒发送
$worker->onWorkerStart = function ($worker) {
    echo "Worker started, initializing timer...\n";
    
    // 每秒执行的倒计时 
    Timer::add(1, function () use ($worker) {
        try {
            // 如果没有连接，直接返回
            if (empty($worker->connections)) {
                return;
            }            
           
            // 每秒遍历所有的链接用户
            foreach ($worker->connections as $key => &$connection) {
                // 获取链接用户数据
                $data = isset($connection->data_info) ? $connection->data_info : '';
                if (empty($data)) { 
                    continue;
                }
                $user_id = $data['user_id'];
                $table_id = $data['table_id'];                
                try {

                    // 牌型数据 获取当局开牌牌型 从 redis 获取 
                    $pai_info = redis_get_pai_info($table_id);
                    // 牌型数据 获取当局开牌牌型 从 redis 获取 
                    $pai_info_temp = redis_get_pai_info_temp($table_id);
                    // 中奖金额 获取用户中奖金额 从 redis 获取
                    $win_or_loss_info = redis_get_payout_money($user_id, $table_id);
                    // 牌型数据 获取当局开牌牌型 从 redis 获取 
                    $table_opening_count_down = redis_get_table_opening_count_down($table_id);

                    // 执行最后的发送数据
                    $connection->send(json_encode([
                            'code' => 200, 
                            'msg' => 'WebSocket 返回信息',
                            'pai_info' => $pai_info,                            
                            'pai_info_temp' => $pai_info_temp,
                            'win_or_loss_info' => $win_or_loss_info,
                            'table_opening_count_down' => $table_opening_count_down
                        ]));
                } catch (\Exception $e) {                   
                    // 记录错误日志 单个连接处理出错，继续处理其他连
                    error_log("Error processing connection: " . $e->getMessage());
                    continue;
                }
            }
        } catch (\Exception $e) {
            //  全局定时器错误，停止所有worker进程
            error_log("Timer fatal error: " . $e->getMessage());
            Worker::stopAll();
            exit(1);
        }
    });
};

// 当有客户端连接断开时
$worker->onClose = function ($connection) use ($worker) {
    if (isset($connection->uid)) {
        $connection->close();
        unset($worker->uidConnections[$connection->uid]);
        echo "user_id:".$connection->uid." 断开连接\n";
    }
};

// 运行所有的worker
Worker::runAll();