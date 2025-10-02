<?php

use app\controller\common\LogHelper;

/**
 * 获取 Redis 实例
 * @return mixed Redis 存储实例
 */
function redis()
{
    return think\facade\Cache::store('redis');
}


/**
 * 生成台桌局号
 * @param int $table_id 台桌ID
 * @param bool $xue_number 是否返回详细信息
 * @return string|array 局号或包含局号和靴铺信息的数组
 */
function bureau_number($table_id, $xue_number = false)
{
    // 获取靴号和铺号
    $xue = xue_number($table_id);
    
    // 生成局号格式：年月日时 + 台桌ID + 靴号 + 铺号
    $table_bureau_number = date('YmdH') . $table_id . $xue['xue_number'] . $xue['pu_number'];
    
    if ($xue_number) {
        return [
            'bureau_number' => $table_bureau_number, 
            'xue' => $xue
        ];
    }
    
    return $table_bureau_number;
}

/**
 * 获取靴号和铺号
 * @param int $table_id 台桌ID
 * @return array 包含靴号和铺号的数组
 */
function xue_number($table_id)
{
    // 获取该台桌最新的有效路珠记录
    $find = \app\model\Luzhu::where('table_id', $table_id)
        ->where('status', 1)
        ->order('id desc')
        ->find();
    
    // 如果没有记录，返回初始值
    if (empty($find)) {
        return [
            'xue_number' => 1, 
            'pu_number' => 1
        ];
    }
    
    // 获取当前靴号
    $xue = $find->xue_number;
    
    // 根据上局结果决定铺号
    if ($find->result == 0) {
        // 结果为0（可能是和局），铺号不变
        $pu = $find->pu_number;
    } else {
        // 其他结果，铺号加1
        $pu = $find->pu_number + 1;
    }
    
    return [
        'xue_number' => $xue, 
        'pu_number' => $pu
    ];
}

/**
 * 统一响应输出函数
 * @param array $data 响应数据
 * @param int $code 业务状态码
 * @param string $message 响应消息
 * @param int $httpStatus HTTP状态码
 * @return mixed
 */
function show($data = [], int $code = 200, string $message = 'ok！', int $httpStatus = 0)
{
    $result = [
        'code' => $code,
        'message' => lang($message),  // 使用语言包翻译
        'data' => $data,
    ];
    
    // 设置跨域访问
    header('Access-Control-Allow-Origin:*');
    
    if ($httpStatus != 0) {
        // 返回指定的HTTP状态码
        return json($result, $httpStatus);
    }
    
    // 默认输出并终止脚本
    echo json_encode($result);
    exit();
}

/**
 * 获取系统配置
 * @param string|null $name 配置名称
 * @return mixed 配置值或配置列表
 */
function get_config($name = null)
{
    if ($name == null) {
        // 获取所有配置
        return \app\model\SysConfig::select();
    }
    
    // 获取指定配置
    return \app\model\SysConfig::where('name', $name)->find();
}

/**
 * 从 Redis 获取牌局信息
 * @param int $table_id 台桌ID
 * @return string 牌局信息
 */
function redis_get_pai_info_正常展示结果的版本($table_id)
{
    $key = 'pai_info_table_' . $table_id;
    $data = redis()->get($key);
    return $data ? $data : '{"1":"11|r","2":"5|f","3":"0|0","4":"7|f","5":"8|f","6":"9|f"}';
}


/**
 * 从 Redis 获取牌局信息  过程数据获取版本
 * @param int $table_id 台桌ID
 * @return string 牌局信息
 */
function redis_get_pai_info($table_id)
{

    $key = 'pai_info_table_temp_' . $table_id;
    $data = redis()->get($key);
    return $data ? $data : '{"1":"10|0","2":"0|0","3":"0|0","4":"0|0","5":"0|0","6":"0|0"}';
}


/**
 * 从数据库读取牌局临时数据并存入Redis
 * @return bool 是否成功
 */
function get_temp_data_from_db()
{
    try {
        // 使用name函数，自动加上表前缀
        $data = \think\facade\Db::name('see_pai_temp')
            ->field('tableid, position, card')
            ->select();
        
        // 如果没有数据，返回false
        if (empty($data)) {
            return false;
        }
        
        // 按table_id分组整理数据
        $grouped_data = [];
        foreach ($data as $row) {
            $table_id = $row['tableid'];
            
            // 初始化该table_id的数据
            if (!isset($grouped_data[$table_id])) {
                $grouped_data[$table_id] = [];
            }
            
            // 处理position字段，提取数字
            // position格式：xian_1, zhuang_1, xian_2, zhuang_2, xian_3
            $position_parts = explode('_', $row['position']);
            if (count($position_parts) >= 2) {
                $pos_num = $position_parts[1]; // 获取位置编号（1,2,3等）
                
                // 处理card字段
                // card格式：12|r, 13|r, 10|m 等
                $card = $row['card'];
                
                // 存入对应table_id的数组
                $grouped_data[$table_id][$pos_num] = $card;
            }
        }
        
        // 将每个table_id的数据存入Redis
        $success_count = 0;
        foreach ($grouped_data as $table_id => $positions) {
            // 确保有完整的6个位置，没有的位置填充默认值
            $complete_data = [];
            for ($i = 1; $i <= 6; $i++) {
                if (isset($positions[$i])) {
                    $complete_data[(string)$i] = $positions[$i];
                } else {
                    // 没有数据的位置使用默认值
                    $complete_data[(string)$i] = "0|0";
                }
            }
            
            // 转换为JSON格式
            $json_data = json_encode($complete_data, JSON_UNESCAPED_UNICODE);
            
            // 设置Redis key
            $key = 'pai_info_table_temp_' . $table_id;
            
            // 存入Redis（设置过期时间为1小时）
            redis()->set($key, $json_data, 3600);
            
            $success_count++;
        }
        
        return $success_count > 0;
        
    } catch (\Exception $e) {
        // 错误处理，可以记录日志
        // LogHelper::error('获取牌局临时数据失败', ['error' => $e->getMessage()]);
        return false;
    }
}
/**
 * 从 Redis 获取用户赢钱金额
 * @param int $user_id 用户ID
 * @param int $table_id 台桌ID
 * @return string 赢钱金额
 */
function redis_get_user_win_money($user_id, $table_id)
{
    $key = 'user_win_money_user_' . $user_id . '_table_' . $table_id;
    $data = redis()->get($key);
    return $data ? $data : 0;
}

/**
 * 从 Redis 获取台桌开牌倒计时
 * @param int $table_id 台桌ID
 * @return string 倒计时秒数
 */
function redis_get_table_opening_count_down($table_id)
{
    $key_start = 'table_opening_count_down_start_table_' . $table_id;
    $key_down_time = 'table_opening_count_down_time_table_' . $table_id;
    $time_start = redis()->get($key_start);
    $time_down_time = redis()->get($key_down_time);
    $data = 0;
    if ($time_start != 0 && $time_down_time != 0) {
        $data = $time_down_time - (time() - $time_start);
        if ($data < 0) {
            $data = 0;
        }
    }
    // LogHelper::debug('倒计时==>调试信息1', [$key_start => $time_start]); 
    // LogHelper::debug('倒计时==>调试信息2', [$key_down_time => $time_down_time]); 
    // LogHelper::debug('倒计时==>调试信息3', ['back_data_time' => $data]); 
    return $data;
    
}

/**
 * 从 Redis 获取台桌开牌倒计时
 * @param int $table_id 台桌ID
 * @return string 倒计时秒数
 */
function redis_set_table_opening_count_down($table_id, $count_down  = 0, $time = 0)
{
    $key_start = 'table_opening_count_down_start_table_' . $table_id;
    $key_down_time = 'table_opening_count_down_time_table_' . $table_id;
    redis()->set($key_start, $count_down);
    redis()->set($key_down_time, $time);
}