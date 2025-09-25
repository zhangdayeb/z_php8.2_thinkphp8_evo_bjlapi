<?php

namespace app\controller\order;

use app\controller\common\LogHelper;
use app\controller\Base;
use app\model\GameRecords as models;
use app\model\GameRecordsTemporary;
use app\model\MoneyLog;
use app\model\Odds;
use app\model\Table;
use app\model\UserModel;
use think\facade\Db;
use think\facade\Queue;
use app\job\ZongHeMoneyJob;

class Order extends Base
{
    protected $model;

    public function initialize()
    {
        $this->model = new models();
        parent::initialize();
    }

    //用户下注
    public function user_bet_order(): string
    {
        LogHelper::debug('=== 用户下注流程开始 ===', [
            'user_id' => self::$user['id'],
            'user_balance' => self::$user['money_balance'],
            'ip' => request()->ip()
        ]);

        // 参数检查
        $post = $this->request->param('bet');
        $table_id = $this->request->param('table_id/d', 0);
        $game_type = $this->request->param('game_type/d', 0);
        if (empty($post)) show([], config('ToConfig.http_code.error'), 'amount not selected');
        if (empty($table_id)) show([], config('ToConfig.http_code.error'), 'table not selected');
        if (empty($game_type) || $game_type != 3) show([], config('ToConfig.http_code.error'), 'game not selected');

        LogHelper::debug('下注参数接收', [
            'bet_data' => $post,
            'table_id' => $table_id,
            'game_type' => $game_type
        ]);

        // 获取台桌信息
        $find = Table::page_one($table_id);
        $table_opening_count_down = redis_get_table_opening_count_down($table_id);
        if (empty($find)) show([], config('ToConfig.http_code.error'), 'table does not exist');
        if ($find['status'] != 1) show([], config('ToConfig.http_code.error'), 'table stop');
        if ($find['run_status'] != 1 || $table_opening_count_down <= 0) show([], config('ToConfig.http_code.error'), 'opening card');
        if (cache('cache_post_order_bet_' . self::$user['id'])) show([],config('ToConfig.http_code.error'), '1秒不能重复操作');
        cache('cache_post_order_bet_' . self::$user['id'], time(), 1);

        // 根据台桌ID 获取当期的 靴号 跟 铺号
        $xue_number = xue_number($table_id);

        // 查询是否当前局下注过
        $is_exempt = $this->request->param('is_exempt', 0);
        $is_order = GameRecordsTemporary::user_status_bureau_number_is_exempt($table_id, $xue_number, self::$user, true);
        if ($is_order != 101 && $is_order != $is_exempt) {
            show([],config('ToConfig.http_code.error'), lang('the Council has bet') . ':' . $is_order);
        }

        // 增加当前台桌投注总数限制
        $money_table_max = 50000;
        $map_sum = [];
        $map_sum['xue_number'] = $xue_number['xue_number'];
        $map_sum['pu_number'] = $xue_number['pu_number'];
        $map_sum['table_id'] = $table_id;
        $money_table_now = Db::name('dianji_records')->where($map_sum)->sum('bet_amt');
        
        $money_this_bet = 0;
        foreach ($post as $key => $value){
            if (!isset($value['money'])) unset($post[$key]);
            if (!isset($value['rate_id'])) unset($post[$key]);
            if ($value['money'] <= 0 || $value['rate_id'] <= 0) {
                unset($post[$key]);
                continue;
            }
            $value['money'] = intval($value['money']);
            $money_this_bet += $value['money'];
        }

        if(($money_this_bet + $money_table_now) > $money_table_max){
            show([], config('ToConfig.http_code.error'),'超出台桌最大限红'.$money_table_max);
        }

        $total_money = 0;
        $post_info = [];
        foreach ($post as $key => $value) {
            if (!isset($value['money'])) unset($post[$key]);
            if (!isset($value['rate_id'])) unset($post[$key]);
            if ($value['money'] <= 0 || $value['rate_id'] <= 0) {
                unset($post[$key]);
                continue;
            }
            $value['money'] = intval($value['money']);
            $value['rate_id'] = intval($value['rate_id']);

            $total_money += $value['money'];
            $odds = Odds::data_one($value['rate_id']);
            $this->checkTableLimit($table_id, $value, $xue_number, $odds, $find); // 台桌限红检查

            // 数据组装
            $post_info[$key]['money'] = $value['money'];
            $post_info[$key]['rate_id'] = $value['rate_id'];
            $post_info[$key]['rate_info'] = $odds;
            $post_info[$key]['rate_info_id'] = $odds['id'];
            $post_info[$key]['rate_info_name'] = $odds['game_tip_name'];
            $post_info[$key]['rate_info_peilv'] = $odds['peilv'];
            
            // 免佣非免佣 庄 赔率特殊处理
            if ($odds['id'] == 8) {
                $array = explode('/', $odds['peilv']);
                if ($is_exempt == 1) {
                    $post_info[$key]['rate_info_peilv'] = $array[0];
                } else {
                    $post_info[$key]['rate_info_peilv'] = $array[1];
                }
            }
            // 幸运6 赔率特殊处理
            if ($odds['id'] == 3) {
                $post_info[$key]['rate_info_peilv'] = 12;
            }
            if (!$post_info[$key]['rate_info']) show([],config('ToConfig.http_code.error'), 'please fill in the correct odds id');
        }

        // 重复下注处理逻辑
        $mapForDel = array();
        $mapForDel['user_id'] = self::$user['id'];
        $mapForDel['table_id'] = $table_id;
        $mapForDel['xue_number'] = $xue_number['xue_number'];
        $mapForDel['pu_number'] = $xue_number['pu_number'];
        $mapForDel['game_type'] = $game_type;
        $mapForDel['close_status'] = 1;
        $delBetMoney = $this->model->where($mapForDel)->whereDay('created_at')->sum('bet_amt');
        $delBetMoney = is_numeric($delBetMoney) ? $delBetMoney : 0;

        // 花式插入判断
        $app_bet_max = !empty(get_config('app_bet_max')) ? get_config('app_bet_max')['value'] : 0;
        if ($total_money + $delBetMoney > $app_bet_max) show([], config('ToConfig.http_code.error'), lang('the bureaus maximum bet') . ':' . $app_bet_max);
        
        $user_money = 0;
        if ($delBetMoney > 0) {
            if ((self::$user['money_balance'] + $delBetMoney) < $total_money) show([], config('ToConfig.http_code.error'), lang('your balance insufficient') . ':' . self::$user['money_balance']);
            
            $mapForDelLog = $this->model->whereTime('created_at','-30 Minutes')->where($mapForDel)->column('id');
            MoneyLog::where('uid', self::$user['id'])
                ->where('status', 'in',[501,503])
                ->where('source_id', 'in', $mapForDelLog)
                ->whereTime('create_time','-30 Minutes')
                ->update(['status'=>$game_type*-1]);

            $this->model->where($mapForDel)->whereDay('created_at')->delete();
            GameRecordsTemporary::where($mapForDel)->whereDay('created_at')->delete();
            UserModel::where('id', self::$user['id'])->inc('money_balance', $delBetMoney)->update();
            $user_money = $delBetMoney;
        }

        // 组装订单数据
        $data = array();
        $user_money += self::$user['money_balance'];
        $dec_money = 0;
        
        foreach ($post_info as $key => $value) {
            $dec_money += $value['money'];
            $data[$key] = [
                'user_id' => self::$user['id'],
                'before_amt' => $user_money,
                'end_amt' => $user_money - $value['money'],
                'bet_amt' => $value['money'],
                'win_amt' => $value['rate_info_name'] * $value['money'],
                'shuffling_num' => $value['money'],
                'shuffling_amt' => 0,
                'shuffling_rate' => self::$user['xima_lv'] / 100,
                'delta_amt' => 0,
                'table_id' => $table_id,
                'xue_number' => $xue_number['xue_number'],
                'pu_number' => $xue_number['pu_number'],
                'game_type' => $game_type,
                'game_peilv_id' => $value['rate_info_id'],
                'game_peilv' => $value['rate_info_peilv'],
                'detail' => $value['rate_info_name'],
                'close_status' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
                'is_exempt' => $is_exempt,
                'agent_status' => $is_exempt == 1 ? 1 : 0,
            ];
            $user_money = $user_money - $value['money'];
        }
        
        if ($user_money < 0) {
            show([],config('ToConfig.http_code.error'), $dec_money . lang('the total amount exceeds your balance'));
        }

        // 写入订单
        $log = new MoneyLog();
        $save = false;
        Db::startTrans();
        try {
            foreach ($data as $key => $value) {
                $id = Db::name((new models())->name)->insertGetId($value);
                Db::name((new GameRecordsTemporary())->name)->insert($value);
                $value['source_id'] = $id;
                $log->order_insert_bet_money_log($value);
                UserModel::where('id', self::$user['id'])->dec('money_balance', $value['bet_amt'])->update();
            }
            $save = true;
            Db::commit();
            
            // 计算净扣款金额
            $net_debit_amount = $dec_money - $delBetMoney;
            // 异步通知钱包
            Queue::push(ZongHeMoneyJob::class, [
                'type' => 'bet',
                'user_id' => self::$user['id'],
                'user_name' => self::$user['user_name'],
                'total_amount' => $net_debit_amount,
                'table_id' => $table_id,
                'game_type' => $game_type,
                'xue_number' => $xue_number['xue_number'],
                'pu_number' => $xue_number['pu_number'],
                'is_modify' => $delBetMoney > 0
            ], 'bjl_zonghemoney_log_queue');
            
        } catch (\Exception $e) {
            Db::rollback();
            show([],config('ToConfig.http_code.error'), $e->getMessage());
        }

        $data['money_balance'] = self::$user['money_balance'] - $dec_money + $delBetMoney;
        $data['money_spend'] = $dec_money;
        if ($save){
            show($data);
        };
        show([], config('ToConfig.http_code.error'), 'bet failed');
    }

    // 台桌限红检查（简化版，只保留台桌限红）
    private function checkTableLimit($table_id, $value, $xue_number, $odds, $table_info)
    {
        $money = $value['money'];
        
        // 只检查台桌限红
        if (isset($table_info['is_table_xian_hong']) && $table_info['is_table_xian_hong'] == 1) {
            switch ($odds->id) {
                case 2: // 闲对
                    if ($money < $table_info['bjl_xian_hong_xian_dui_min']) show([], config('ToConfig.http_code.error'), lang('limit red leisure to bet at least') . ':' . $table_info['bjl_xian_hong_xian_dui_min']);
                    if ($money > $table_info['bjl_xian_hong_xian_dui_max']) show([], config('ToConfig.http_code.error'), lang('limit red leisure to bet the most') . ':' . $table_info['bjl_xian_hong_xian_dui_max']);
                    break;
                case 3: // 幸运6
                    if ($money < $table_info['bjl_xian_hong_lucky6_min']) show([], config('ToConfig.http_code.error'), lang('limit red lucky 6 minimum bet') . ':' . $table_info['bjl_xian_hong_lucky6_min']);
                    if ($money > $table_info['bjl_xian_hong_lucky6_max']) show([], config('ToConfig.http_code.error'), lang('limit red lucky 6 to bet most') . ':' . $table_info['bjl_xian_hong_lucky6_max']);
                    break;
                case 4: // 庄对
                    if ($money < $table_info['bjl_xian_hong_zhuang_dui_min']) show([], config('ToConfig.http_code.error'), lang('limit red zhuang bet the least') . ':' . $table_info['bjl_xian_hong_zhuang_dui_min']);
                    if ($money > $table_info['bjl_xian_hong_zhuang_dui_max']) show([], config('ToConfig.http_code.error'), lang('limit red zhuang to bet most') . ':' . $table_info['bjl_xian_hong_zhuang_dui_max']);
                    break;
                case 6: // 闲
                    if ($money < $table_info['bjl_xian_hong_xian_min']) show([], config('ToConfig.http_code.error'), lang('limit red leisure and bet at least') . ':' . $table_info['bjl_xian_hong_xian_min']);
                    if ($money > $table_info['bjl_xian_hong_xian_max']) show([], config('ToConfig.http_code.error'), lang('limit red leisure and bet at most') . ':' . $table_info['bjl_xian_hong_xian_max']);
                    break;
                case 7: // 和
                    if ($money < $table_info['bjl_xian_hong_he_min']) show([], config('ToConfig.http_code.error'), lang('limit red and minimum bet least') . ':' . $table_info['bjl_xian_hong_he_min']);
                    if ($money > $table_info['bjl_xian_hong_he_max']) show([], config('ToConfig.http_code.error'), lang('limit red and minimum bet most') . ':' . $table_info['bjl_xian_hong_he_max']);
                    break;
                case 8: // 庄
                    if ($money < $table_info['bjl_xian_hong_zhuang_min']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet least') . ':' . $table_info['bjl_xian_hong_zhuang_min']);
                    if ($money > $table_info['bjl_xian_hong_zhuang_max']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet most') . ':' . $table_info['bjl_xian_hong_zhuang_max']);
                    break;
                case 9: // 龙7
                    if ($money < $table_info['bjl_xian_hong_long7_min']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet least') . ':' . $table_info['bjl_xian_hong_long7_min']);
                    if ($money > $table_info['bjl_xian_hong_long7_max']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet most') . ':' . $table_info['bjl_xian_hong_long7_max']);
                    break;
                case 10: // 熊8
                    if ($money < $table_info['bjl_xian_hong_xiong8_min']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet least') . ':' . $table_info['bjl_xian_hong_xiong8_min']);
                    if ($money > $table_info['bjl_xian_hong_xiong8_max']) show([], config('ToConfig.http_code.error'), lang('limit red and zhuang bet most') . ':' . $table_info['bjl_xian_hong_xiong8_max']);
                    break;
            }
        }
        return true;
    }


}