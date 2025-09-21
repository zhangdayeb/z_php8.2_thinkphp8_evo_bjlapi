<?php

namespace app\controller\order;

use app\controller\common\LogHelper;
use app\controller\Base;
use app\model\GameRecords;

class OrderBase extends Base
{

    //用户限红
    public function user_xian_hong($table_id, $value, $xue_number, $odds, $table_info)
    {
        //查询用户本次赔率下注的金额//当前赔率本次下注 加上前面下注
        $money = $value['money'];
        $limit_long_7_min = 10;
        $limit_long_7_max = 250;
        $limit_xiong_8_min = 10;
        $limit_xiong_8_max = 400;
        $tip = '超过限红设置';
        //用户限红
        if (isset(self::$user['is_xian_hong']) && self::$user['is_xian_hong'] == 1){
            switch ($odds->id) {
                //百家乐限红
                case 2://百家乐 闲对
                    if ($money < self::$user['bjl_xian_hong_xian_dui_min']) show([], config('ToConfig.http_code.error'), lang('limit red leisure to bet at least') . ':' . self::$user['bjl_xian_hong_xian_dui_min']);
                    if ($money > self::$user['bjl_xian_hong_xian_dui_max']) show([], config('ToConfig.http_code.error'), lang('limit red leisure to bet the most') . ':' . self::$user['bjl_xian_hong_xian_dui_max']);
                    //台桌限红
                    break;
                case 3: //百家乐 幸运6
                    if ($money < self::$user['bjl_xian_hong_lucky6_min']) show([], config('ToConfig.http_code.error'), lang('limit red lucky 6 minimum bet') . ':' . self::$user['bjl_xian_hong_lucky6_min']);
                    if ($money > self::$user['bjl_xian_hong_lucky6_max']) show([], config('ToConfig.http_code.error'), lang('limit red lucky 6 to bet most') . ':' . self::$user['bjl_xian_hong_lucky6_max']);
                    break;
                case 4://百家乐庄 对
                    if ($money < self::$user['bjl_xian_hong_zhuang_dui_min']) show([], config('ToConfig.http_code.error'), lang('limit red zhuang bet the least') . ':' . self::$user['bjl_xian_hong_zhuang_dui_min']);
                    if ($money > self::$user['bjl_xian_hong_zhuang_dui_max']) show([], config('ToConfig.http_code.error'), lang('limit red zhuang to bet most') . ':' . self::$user['bjl_xian_hong_zhuang_dui_max']);
                    break;
                case 6: //百家乐 闲
                    if ($money < self::$user['bjl_xian_hong_xian_min']) show([], config('ToConfig.http_code.error'), lang('limit red leisure and bet at least') . ':' . self::$user['bjl_xian_hong_xian_min']);
                    if ($money > self::$user['bjl_xian_hong_xian_max']) show([], config('ToConfig.http_code.error'), lang('limit red leisure and bet at most') . ':' . self::$user['bjl_xian_hong_xian_max']);
                    break;
                case 7://百家乐 和
                    if ($money < self::$user['bjl_xian_hong_he_min']) show([], config('ToConfig.http_code.error'), lang('limit red and minimum bet least') . ':' . self::$user['bjl_xian_hong_he_min']);
                    if ($money > self::$user['bjl_xian_hong_he_max']) show([], config('ToConfig.http_code.error'), lang('limit red and minimum bet most') . ':' . self::$user['bjl_xian_hong_he_max']);
                    break;
                case 8://百家乐 庄
                    if ($money < self::$user['bjl_xian_hong_zhuang_min']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet least') . ':' . self::$user['bjl_xian_hong_zhuang_min']);
                    if ($money > self::$user['bjl_xian_hong_zhuang_max']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet most') . ':' . self::$user['bjl_xian_hong_zhuang_max']);
                    break;

                // 客户新增 临时 限红    9 龙7 10 熊8
                case 9://百家乐 庄
                    if ($money < $limit_long_7_min) show([], config('ToConfig.http_code.error'), $tip);
                    if ($money > $limit_long_7_max) show([], config('ToConfig.http_code.error'), $tip);
                    break;
                case 10://百家乐 庄
                    if ($money < $limit_xiong_8_min) show([], config('ToConfig.http_code.error'), $tip);
                    if ($money > $limit_xiong_8_max) show([], config('ToConfig.http_code.error'), $tip);
                    break;
                    
            }
            return true;
        }

        //台桌限红
        if (isset($table_info['is_table_xian_hong']) && $table_info['is_table_xian_hong'] == 1) {
            switch ($odds->id) { //台桌限红
                //百家乐限红
                case 2://百家乐 闲对
                    if ($money < $table_info['bjl_xian_hong_xian_dui_min']) show([], config('ToConfig.http_code.error'), lang('limit red leisure to bet at least') . ':' . $table_info['bjl_xian_hong_xian_dui_min']);
                    if ($money > $table_info['bjl_xian_hong_xian_dui_max']) show([], config('ToConfig.http_code.error'), lang('limit red leisure to bet the most') . ':' . $table_info['bjl_xian_hong_xian_dui_max']);
                    break;
                case 3: //百家乐 幸运6
                    if ($money < $table_info['bjl_xian_hong_lucky6_min']) show([], config('ToConfig.http_code.error'), lang('limit red lucky 6 minimum bet') . ':' . $table_info['bjl_xian_hong_lucky6_min']);
                    if ($money > $table_info['bjl_xian_hong_lucky6_max']) show([], config('ToConfig.http_code.error'), lang('limit red lucky 6 to bet most') . ':' . $table_info['bjl_xian_hong_lucky6_max']);
                    break;
                case 4://百家乐庄 对
                    if ($money < $table_info['bjl_xian_hong_zhuang_dui_min']) show([], config('ToConfig.http_code.error'), lang('limit red zhuang bet the least') . ':' . $table_info['bjl_xian_hong_zhuang_dui_min']);
                    if ($money > $table_info['bjl_xian_hong_zhuang_dui_max']) show([], config('ToConfig.http_code.error'), lang('limit red zhuang to bet most') . ':' . $table_info['bjl_xian_hong_zhuang_dui_max']);

                    break;
                case 6: //百家乐 闲
                    if ($money < $table_info['bjl_xian_hong_xian_min']) show([], config('ToConfig.http_code.error'), lang('limit red leisure and bet at least') . ':' . $table_info['bjl_xian_hong_xian_min']);
                    if ($money > $table_info['bjl_xian_hong_xian_max']) show([], config('ToConfig.http_code.error'), lang('limit red leisure and bet at most') . ':' . $table_info['bjl_xian_hong_xian_max']);
                    break;
                case 7://百家乐 和
                    if ($money < $table_info['bjl_xian_hong_he_min']) show([], config('ToConfig.http_code.error'), lang('limit red and minimum bet least') . ':' . $table_info['bjl_xian_hong_he_min']);
                    if ($money > $table_info['bjl_xian_hong_he_max']) show([], config('ToConfig.http_code.error'), lang('limit red and minimum bet most') . ':' . $table_info['bjl_xian_hong_he_max']);
                    break;
                case 8://百家乐 庄
                    if ($money < $table_info['bjl_xian_hong_zhuang_min']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet least') . ':' . $table_info['bjl_xian_hong_zhuang_min']);
                    if ($money > $table_info['bjl_xian_hong_zhuang_max']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet most') . ':' . $table_info['bjl_xian_hong_zhuang_max']);
                    break;

                // 客户新增 临时 限红    9 龙7 10 熊8
                case 9://百家乐 庄
                    if ($money < $table_info['bjl_xian_hong_long7_min']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet least') . ':' . $table_info['bjl_xian_hong_long7_min']);
                    if ($money > $table_info['bjl_xian_hong_long7_max']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet most') . ':' . $table_info['bjl_xian_hong_long7_max']);
                    break;

                case 10://百家乐 庄
                    if ($money < $table_info['bjl_xian_hong_xiong8_min']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet least') . ':' . $table_info['bjl_xian_hong_xiong8_min']);
                    if ($money > $table_info['bjl_xian_hong_xiong8_max']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet most') . ':' . $table_info['bjl_xian_hong_xiong8_max']);
                    break;
            }
            return true;
        }

        //赔率限红
        if (empty($odds)) return show([], config('ToConfig.http_code.error'), 'please fill in the correct odds id');
        if ($value['money'] < $odds->xian_hong_min) show([], config('ToConfig.http_code.error'), lang('limit red minimum least') . ':' . $odds->xian_hong_min);
        if ($money > $odds->xian_hong_max) show([], config('ToConfig.http_code.error'), lang('limit red maximum most') . ':' . $odds->xian_hong_max);
        return true;
    }

    //当前下单记录
    public function order_current_record()
    {
        $table_id = $this->request->post('id/d', 0);
        if ($table_id <= 0) show([]);

        $records = GameRecords::where([
                'user_id'      => self::$user['id'],
                'table_id'     => $table_id,
                'close_status' => 1,
            ])
            ->field('bet_amt,game_peilv_id,is_exempt')
            ->whereTime('created_at', '-10 minutes')
            ->select();

        $is_exempt = 0;

        foreach ($records as $record) {
            if ($record['is_exempt'] != 0) {
                $is_exempt = $record['is_exempt'];
                break; // 一旦发现免佣，提前结束
            }
        }

        show([
            'is_exempt'   => $is_exempt,
            'record_list' => $records,
        ]);
    }

}