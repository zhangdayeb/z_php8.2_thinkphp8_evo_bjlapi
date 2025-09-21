<?php


namespace app\model;


use think\Model;

class GameRecords extends Model
{
    public $name = 'dianji_records';

    /**
     * /用户当前局免佣状态
     * @param $table_id /台桌ID
     * @param $number /靴号铺号
     * @param $user /用户信息
     * @param $is_order /是否需要知道 是否下单过 下单判断免佣会用到
     * return 默认 is_exempt = 1; $is_exempt->is_exempt查出是0 还是1
     */
    public static function user_status_bureau_number_is_exempt($table_id, $number, $user, $is_order = false)
    {
        $is_exempt = self::where([
            'xue_number' => $number['xue_number'],
            'pu_number' => $number['pu_number'],
            'table_id' => $table_id,
            'user_id' => $user['id']
        ])
            ->whereTime('created_at', 'today')
            ->order('created_at desc')
            ->find();

        #------获取是否下单过开始 下单会用到 101表示没下单过
        if ($is_order == true && empty($is_exempt)) {
            return 101;
        }
        #------获取是否下单过结束

        #####获取当前用户当前局免佣状态
        if (!empty($is_exempt)) {
            return $is_exempt->is_exempt;
        }
        return 0;
        #####获取当前用户当前局免佣状态结束
    }

}