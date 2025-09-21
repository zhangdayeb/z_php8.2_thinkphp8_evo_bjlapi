<?php
use app\controller\common\LogHelper;
use Workerman\Worker;
use Workerman\Timer;
use app\service\CardSettlementService;
use app\model\Table;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../common.php';

// 初始化一个worker容器，监听2003端口
$worker = new Worker('websocket://0.0.0.0:2003');
// ====这里进程数必须必须必须设置为1====
$worker->count = 1;
// 新增加一个属性，用来保存uid到connection的映射
$worker->uidConnections = array();

// 当有客户端发来消息时执行的回调函数
$worker->onMessage = function ($connection, $data) use ($worker) {
    if($data == 'ping'){
        return $connection->send('pong');
    }
    
    static $request_count;
    // 业务处理略
    if (++$request_count > 10000) {
        Worker::stopAll();
    }

    $data = json_decode($data, true);
    
    // 判断当前客户端是否已经验证,即是否设置了uid
    if (!isset($connection->uid)) {
        // 原先的逻辑
        $connection->lastMessageTime = time();

        if (!isset($data['user_id']) || empty($data['user_id'])) {
            return $connection->send('连接成功，userId错误');
        }
        if (!isset($data['table_id']) || !isset($data['game_type'])) {
            return $connection->send('连接成功，参数错误');
        }

        //绑定uid
        $data['user_id'] = $connection->uid = $data['user_id'] == 'null__' ? rand(10000,99999): $data['user_id'];
        $connection->data_info = $data;
        $worker->uidConnections[$connection->uid] = $connection;

        //前端逻辑变化，这里就不发连接成功，改为发送台桌信息过去
        try {
            $WorkerOpenPaiService = new \app\service\WorkerOpenPaiService();
            $user_id = intval(str_replace('_', '', $data['user_id']));

            if ($user_id) {
                $table_info['table_run_info'] = $WorkerOpenPaiService->get_table_info($data['table_id'], $user_id);
            } else {
                $table_info['table_run_info'] = [];
            }
        } catch (\Exception $e) {
            // 如果出错，返回空数据
            $table_info['table_run_info'] = [];
            error_log("Error getting table info: " . $e->getMessage());
        }

        return $connection->send(json_encode(['code' => 200, 'msg' => '成功', 'data' => $table_info]));
    }

    if (isset($data['code'])) {
        $user_id = str_replace('_', '', $data['user_id']);
        $msg = '';
        if (isset($data['msg'])) {
            $msg = $data['msg'];
        }
        $array = ['code' => $data['code'], 'msg' => $msg, 'data' => $data];
        //约定推送语音消息,user消息推送到台桌
        if ($data['code'] == 205){
            $user_id .='_';
            $ret = sendMessageByUid($worker, $user_id, json_encode($array));
            return  $connection->send($ret ? 'ok' : 'fail');
        }
        //推送消息到 视频页面
        sendMessageByUid($worker, $user_id, json_encode($array));
        return $connection->send(json_encode($array));
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
            
            // 获取台桌开牌信息
            $newOpen = new CardSettlementService();
            
            // 每秒遍历所有的链接用户
            foreach ($worker->connections as $key => &$connection) {
                // 获取链接用户数据
                $data = isset($connection->data_info) ? $connection->data_info : '';
                if (empty($data)) { 
                    continue;
                }
                
                try {
                    // 获取用户ID
                    $user_id = intval(str_replace('_', '', $data['user_id']));
                    $WorkerOpenPaiService = new \app\service\WorkerOpenPaiService();

                    // 情况1： 发送给前端用户倒计时信号
                    $redis = redis();
                    if ($redis) {
                        $signal = $redis->get('table_set_start_signal_' . $data['table_id']);
                        if ($signal) {
                            // ✅ 每次重新获取最新台桌信息，不使用缓存
                            $table_info['table_run_info'] = $WorkerOpenPaiService->get_table_info($data['table_id'], $user_id);
                            
                            // ✅ 直接发送，简洁明了
                            $connection->send(json_encode(['code' => 200, 'msg' => '倒计时信息', 'data' => $table_info]));
                            continue;
                        }
                    }
                    
                    // 情况2： 发送给前端用户开牌信号
                    $pai_result = [];
                    $pai_result = $newOpen->get_pai_info($data['table_id'], $data['game_type']);
                    if (!empty($pai_result)){
                        $pai_result['table_info'] = $data;
                        $connection->send(json_encode([
                            'code' => 200, 'msg' => '开牌信息',
                            'data' => ['result_info' => $pai_result, 'bureau_number' => bureau_number($data['table_id'])],
                        ]));
                        continue;
                    } 
                    
                    // 情况3： 发送给前端用户中奖信息 
                    $pai_result = [];
                    $money = $newOpen->get_payout_money($user_id, $data['table_id'], $data['game_type']);
                    if ($money){
                        $pai_result['money'] = $money;
                        $connection->send(json_encode([
                            'code' => 200, 'msg' => '中奖信息',
                            'data' => ['result_info' => $pai_result, 'bureau_number' => bureau_number($data['table_id'])],
                        ]));
                        continue;
                    }
                } catch (\Exception $e) {
                    // 单个连接处理出错，继续处理其他连接
                    error_log("Error processing connection: " . $e->getMessage());
                    continue;
                }
            }
        } catch (\Exception $e) {
            error_log("Timer error: " . $e->getMessage());
        }
    });
};

// 当有客户端连接断开时
$worker->onClose = function ($connection) use ($worker) {
    if (isset($connection->uid)) {
        $connection->close();
        // 连接断开时删除映射
        unset($worker->uidConnections[$connection->uid]);
        echo "断开连接\n";
    }
};

// 向所有验证的用户推送数据
function broadcast($worker, $message)
{
    foreach ($worker->uidConnections as $connection) {
        $connection->send($message);
    }
}

// 针对uid推送数据
function sendMessageByUid($worker, $uid, $message)
{
    if (isset($worker->uidConnections[$uid])) {
        $connection = $worker->uidConnections[$uid];
        $connection->send($message);
        return true;
    }
    return false;
}

// 运行所有的worker
Worker::runAll();