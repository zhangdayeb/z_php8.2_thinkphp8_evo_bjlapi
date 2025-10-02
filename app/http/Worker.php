<?php

use Workerman\Worker;
use Workerman\Timer;
use app\controller\common\LogHelper;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../common.php';

/**
 * WebSocket服务器配置
 * 监听端口：2003
 * 协议：websocket
 */
$worker = new Worker('websocket://0.0.0.0:2003');

/**
 * 进程数配置
 * 重要：必须设置为1，避免多进程通信数据混乱
 */
$worker->count = 1;

/**
 * UID到连接的映射表
 * 用于保存用户ID与连接实例的对应关系
 */
$worker->uidConnections = array();

/**
 * 客户端消息处理回调
 * 
 * @param $connection 连接实例
 * @param $data 客户端发送的数据
 */
$worker->onMessage = function ($connection, $data) use ($worker) {
    
    // 请求计数器：达到10000次后重启进程，防止内存泄漏
    static $request_count;
    if (++$request_count > 10000) {
        Worker::stopAll();
    }

    // 心跳检测：响应客户端的ping请求
    if ($data == 'ping') {
        return $connection->send('pong');
    }

    // 解析客户端JSON数据
    $data = json_decode($data, true);
    $connection->lastMessageTime = time();
    
    // 参数验证：检查必要字段
    if (!isset($data['user_id']) || !isset($data['table_id']) || !isset($data['game_type'])) {
        return $connection->send('连接成功，参数错误');
    }

    // 初始化连接：将新连接加入连接池
    if (!isset($connection->uid)) {
        $connection->uid = $data['user_id'];
        $connection->data_info = $data;
        $worker->uidConnections[$connection->uid] = $connection;
        
        return $connection->send(json_encode([
            'code' => 200, 
            'msg' => '初始化链接成功'
        ]));
    }
};

/**
 * Worker进程启动回调
 * 初始化定时器任务
 */
$worker->onWorkerStart = function ($worker) {
    echo "Worker started, initializing timer...\n";
    
    /**
     * 定时任务：每秒执行一次
     * 向所有连接的客户端推送游戏数据
     */
    Timer::add(1, function () use ($worker) {
        try {
            // 无连接时直接返回
            if (empty($worker->connections)) {
                return;
            }
            
            // 遍历所有活跃连接
            foreach ($worker->connections as $key => &$connection) {
                // 获取连接的用户数据
                $data = isset($connection->data_info) ? $connection->data_info : '';
                if (empty($data)) {
                    continue;
                }
                
                $user_id = $data['user_id'];
                $table_id = $data['table_id'];
                
                try {
                    /**
                     * 从Redis获取游戏数据 每个人 链接的 桌子 数据 都可能不一样
                     */
                    // 获取当局开牌牌型
                    get_temp_data_from_db();
                    $pai_info = redis_get_pai_info($table_id);
                    
                    // 获取用户中奖金额
                    $win_or_loss_info = redis_get_user_win_money($user_id, $table_id);
                    
                    // 获取开牌倒计时
                    $table_opening_count_down = redis_get_table_opening_count_down($table_id);

                    // 向客户端推送数据
                    $connection->send(json_encode([
                        'code' => 200,
                        'msg' => 'WebSocket 返回信息',
                        'pai_info' => $pai_info,
                        'win_or_loss_info' => $win_or_loss_info,
                        'table_opening_count_down' => $table_opening_count_down
                    ]));
                    
                } catch (\Exception $e) {
                    // 单个连接处理异常：记录错误并继续处理其他连接
                    error_log("Error processing connection: " . $e->getMessage());
                    continue;
                }
            }
            
        } catch (\Exception $e) {
            // 全局定时器异常：停止所有Worker进程
            error_log("Timer fatal error: " . $e->getMessage());
            Worker::stopAll();
            exit(1);
        }
    });
};

/**
 * 客户端断开连接回调
 * 清理连接资源
 * 
 * @param $connection 断开的连接实例
 */
$worker->onClose = function ($connection) use ($worker) {
    if (isset($connection->uid)) {
        $connection->close();
        unset($worker->uidConnections[$connection->uid]);
        echo "user_id:" . $connection->uid . " 断开连接\n";
    }
};

// 启动Worker进程
Worker::runAll();