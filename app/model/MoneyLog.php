<?php


namespace app\model;

use think\Model;

class MoneyLog extends Model
{
    public $name = 'common_pay_money_log';

    //下注插入资金记录
    public function order_insert_bet_money_log(array $info)
    {
        $status = 501;
        switch ($info['game_type']) {
            case 1:
                $status = 501;
                break;
            case 2:
                $status = 502;
                break;
            case 3:
                $status = 503;
                break;
            case 4:
                $status = 504;
                break;
            case 5:
                $status = 505;
                break;
            case 6:
                $status = 506;
                break;
            case 7:
                $status = 507;
                break;
            case 8:
                $status = 508;
                break;
        }
        !isset($info['deposit_amt']) && $info['deposit_amt'] = 0;
        //备注
        $mark = '下注:' . $info['bet_amt'] . ',押金：' . $info['deposit_amt'] . ',开始金额:' . $info['before_amt'] . ',结束金额:' . $info['end_amt'];
        $mark .= ',台桌/类型:' . $info['table_id'] . '-' . $info['game_type'] . ',靴/铺:' . $info['xue_number'] . '-' . $info['pu_number'] . ',赔率:ID' . $info['game_peilv_id'];

        self::insert([
            'create_time' => date('Y-m-d H:i:s'),
            'type' => 2,
            'status' => $status,
            'money_before' => $info['before_amt'],
            'money_end' => $info['end_amt'],
            'money' => -$info['bet_amt'],
            'uid' => $info['user_id'],
            'source_id' => $info['source_id'],
            'mark' => $mark
        ]);
        return true;
    }
}