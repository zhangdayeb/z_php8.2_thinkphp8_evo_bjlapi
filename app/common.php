<?php


function redis()
{
    return think\facade\Cache::store('redis');
}

/**
 * @param string $type 类型
 * @param string $name 集合名称
 * @param int $key 集合key
 * @param int $val 集合sort
 */
function redis_sort_set(string $type = 'set', string $name = '', int $key = 0, int $val = 0)
{
    if ($type == 'set') {//存入 有序集合
        return redis()->ZADD($name, $key, $val);
    }
    if ($type == 'get') {//获取有序集合指定的值
        return redis()->ZSCORE($name, $key);
    }

    if ($type == 'del') {//删除有序结合指定的key
        return redis()->ZREM($name, $key);
    }

}

//生成台桌局号
function bureau_number($table_id, $xue_number = false)
{
    $xue = xue_number($table_id);
    $table_bureau_number = date('YmdH') . $table_id . $xue['xue_number'] . $xue['pu_number'];
    if ($xue_number) return ['bureau_number' => $table_bureau_number, 'xue' => $xue];
    return $table_bureau_number;
}

//$table_id 台桌ID
function xue_number($table_id)
{
    //取才创建时间最后一条数据
    $find = \app\model\Luzhu::where('table_id', $table_id)->where('status', 1)->order('id desc')->find();
    if (empty($find)) return ['xue_number' => 1, 'pu_number' => 1];
    $xue = $find->xue_number;
    if ($find->result == 0) {
        $pu = $find->pu_number;
    } else {
        $pu = $find->pu_number + 1;
    }
    return ['xue_number' => $xue, 'pu_number' => $pu];
}

function show($data = [], int $code = 200, string $message = 'ok！', int $httpStatus = 0)
{
    $result = [
        'code' => $code,
        'message' => lang($message),
        'data' => $data,
    ];
    header('Access-Control-Allow-Origin:*');
    if ($httpStatus != 0) {
        return json($result, $httpStatus);
    }
    echo json_encode($result);
    exit();
}

function get_config($name = null)
{
    if ($name == null) {
        return \app\model\SysConfig::select();
    }
    return \app\model\SysConfig::where('name', $name)->find();
}

function redis_get_pai_info($table_id)
{
    $key = 'pai_info_table_' . $table_id;
    $data = redis()->get($key);
    return $data ? $data : '';
}   

function redis_get_pai_info_temp($table_id)
{
    $key = 'pai_info_temp_table_' . $table_id;
    $data = redis()->get($key);
    return $data ? $data : '';
}   

function redis_get_user_win_money($user_id, $table_id)
{
    $key = 'user_win_money_user_'.$user_id.'_table_' . $table_id;
    $data = redis()->get($key);
    return $data ? $data : '';
}   

function redis_get_table_opening_count_down($table_id)
{
    $key = 'table_opening_count_down_table_' . $table_id;
    $data = redis()->get($key);
    return $data ? $data : '';
}   