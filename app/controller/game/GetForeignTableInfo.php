<?php

namespace app\controller\game;

use app\controller\common\LogHelper;
use app\BaseController;
use app\model\Luzhu;
use app\model\LuzhuPreset;
use app\model\Table;
use app\job\TableEndTaskJob;
use app\service\CardSettlementService;
use app\validate\BetOrder as validates;
use think\exception\ValidateException;
use think\facade\Queue;
use app\model\UserModel;           // 用户模型
use app\model\GameRecords;         // 游戏记录模型  
use think\facade\Db;               // 数据库操作
use app\business\Curl;
use app\business\RequestUrl;
use app\model\HomeTokenModel;

class GetForeignTableInfo extends BaseController
{
    
    /**
     * ========================================
     * 获取用户详细信息
     * ========================================
     * 
     * 根据用户ID获取用户的完整信息，包括基础信息、余额、统计数据等
     * 主要用于：
     * - 荷官端查看用户信息
     * - 客服端用户资料查询
     * - 管理端用户管理
     * 
     * @return string JSON响应
     */
public function get_user_info(): string
    {
        LogHelper::debug('=== 获取用户信息请求开始 ===');
        
        // 获取参数
        $user_id = $this->request->param('user_id', 0);
        
        // 参数验证
        if (empty($user_id) || !is_numeric($user_id)) {
            LogHelper::warning('用户ID参数无效', ['user_id' => $user_id]);
            return show([], config('ToConfig.http_code.error'), '用户ID必填且必须为数字');
        }
        
        $user_id = intval($user_id);
        
        LogHelper::debug('查询用户信息', ['user_id' => $user_id]);
        
        try {
            // 查询用户基础信息
            $userInfo = UserModel::where('id', $user_id)->find();
            // 先检查用户是否存在
            if (empty($userInfo)) {
                LogHelper::warning('用户不存在', ['user_id' => $user_id]);
                return show([], config('ToConfig.http_code.error'), '用户不存在');
            }
            
            $token = $this->request->header('x-csrf-token');
            $HomeTokenModel = new HomeTokenModel();
            //查询是否存在这条token的用户
            $update = $HomeTokenModel->where('user_id', $userInfo['id'])
                ->update(['token' => $token, 'create_time' => date('Y-m-d H:i:s')]);

            //数据不存在时插入
            if ($update == 0) {
                $HomeTokenModel->insert([
                    'token' => $token, 'user_id' => $userInfo['id'], 'create_time' => date('Y-m-d H:i:s')
                ]);
            }
            
            // 转换为数组便于处理
            $userData = $userInfo->toArray();
            
            // 移除敏感信息
            unset($userData['pwd']);
            unset($userData['withdraw_pwd']);
            
            // 格式化金额字段（确保字段存在）
            $userData['money_balance'] = number_format($userData['money_balance'] ?? 0, 2);
            $userData['rebate_balance'] = number_format($userData['rebate_balance'], 2);
            $userData['rebate_total'] = number_format($userData['rebate_total'], 2);
            
            // 虚拟账号类型
            $fictitiousTypes = [
                0 => '正常账号',
                1 => '虚拟账号',
                2 => '试玩账号'
            ];
            $userData['is_fictitious_text'] = $fictitiousTypes[$userData['is_fictitious'] ?? 0] ?? '未知';
            
            LogHelper::debug('用户信息查询成功', [
                'user_id' => $user_id,
                'user_name' => $userData['user_name'] ?? 'unknown',
                'balance' => $userData['money_balance']
            ]);
            
            return show($userData, 1, '获取用户信息成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取用户信息失败', [
                'user_id' => $user_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取用户信息失败：' . $e->getMessage());
        }
    }

    //获取台桌视频    
    public function get_table_video()
    {
        $params = $this->request->param();
        $returnData = array();
        $info = Table::order('id desc')->find($params['tableId']);
        $returnData['video_near'] = $info['video_near'];
        $returnData['video_far'] = $info['video_far'];
        // 返回数据
        show($returnData, 1);
    }

    //获取荷官台桌露珠信息
    public function get_hg_lz_list(): string
    {
        
        $params = $this->request->param();
       
        $returnData = Luzhu::LuZhuList($params);
        show($returnData, 1);
    }

    public function get_hg_data_list(): string
    {
        
        $params = $this->request->param();
       //查询台桌是否在洗桌状态
       //获取台桌信息
        if(!isset($params['tableId']) || empty($params['tableId'])) return show([],1,'台桌ID不存在');
         $table  = Table::where('id',$params['tableId'])->find();
         if(empty($table)) return show([],1,'台桌不存在');
         $table = $table->toArray();
         if($table['wash_status']  ==1 ){
               show([], 1);
         }
        $returnData = Luzhu::LuZhuList($params);
        show($returnData, 1);
    }
    
    public function get_hg_video_list(): string
    {
        
        $params = $this->request->param();
        //获取台桌信息
        if(!isset($params['tableId']) || empty($params['tableId'])) return show([],1,'台桌ID不存在');
        $table  = Table::where('id',$params['tableId'])->find();
         if(empty($table)) return show([],1,'台桌不存在');
         $table = $table->toArray();
       $video_near = explode('=',$table['video_near']);
       $video_far = explode('=',$table['video_far']);

        show(['video_near'=>$video_near[1].$table['id'],'video_far'=>$video_far[1].$table['id']], 1);
    }

   //获取台桌露珠信息
    public function get_lz_list(): string
    {
        $params = $this->request->param();
        $tableId = $this->request->param('tableId',0);
        if ($tableId <=0 ) show([], config('ToConfig.http_code.error'),'台桌ID必填');
        $returnData = Luzhu::LuZhuList($params);        
        show($returnData, 1);
    }

    // 获取台桌列表
    public function get_table_list(): string
    {
        $gameTypeView = array(
            '1' => '_nn',
            '2' => '_lh',
            '3' => '_bjl'
        );
        $infos = Table::where(['status' => 1])->order('id asc')->select()->toArray();
        empty($infos) && show($infos, 1);
        foreach ($infos as $k => $v) {
            // 设置台桌类型 对应的 view 文件
            $infos[$k]['viewType'] = $gameTypeView[$v['game_type']];
            $number = rand(100, 3000);// 随机人数
            $infos[$k]['number'] = $number;
            // 获取靴号
            //正式需要加上时间查询
            $luZhu = Luzhu::where(['status' => 1, 'table_id' => $v['id']])->whereTime('create_time', 'today')->select()->toArray();

            if (isset($luZhu['xue_number'])) {
                $infos[$k]['xue_number'] = $luZhu['xue_number'];
                continue;
            }
            $infos[$k]['xue_number'] = 1;
        }
        show($infos, 1);
    }

    // 获取统计数据
    public function get_table_count(): string
    {
        $params = $this->request->param();
        $map = array();
        $map['status'] = 1;
        if (!isset($params['tableId']) || !isset($params['xue']) || !isset($params['gameType'])) {
            show([], 0,'');
        }

        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['xue'];
        $map['game_type'] = $params['gameType']; // 代表百家乐

        $nowTime = time();
		$startTime = strtotime(date("Y-m-d 09:00:00", time()));
		// 如果小于，则算前一天的
		if ($nowTime < $startTime) {
		    $startTime = $startTime - (24 * 60 * 60);
		} else {
		    // 保持不变 这样做到 自动更新 露珠
		}

        // 需要兼容 龙7 熊8 大小老虎 69 幸运6 
        $returnData = array();
        $returnData_zhuang_1 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '1|%')->where($map)->order('id asc')->count();
        $returnData_zhuang_4 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '4|%')->where($map)->order('id asc')->count();
        $returnData_zhuang_6 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '6|%')->where($map)->order('id asc')->count();
        $returnData_zhuang_7 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '7|%')->where($map)->order('id asc')->count();
        $returnData_zhuang_9 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '9|%')->where($map)->order('id asc')->count();
		$returnData['zhuang'] = $returnData_zhuang_1 + $returnData_zhuang_4 + $returnData_zhuang_6 + $returnData_zhuang_7 + $returnData_zhuang_9;

        $returnData_xian_2 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '2|%')->where($map)->order('id asc')->count();
        $returnData_xian_8 = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '8|%')->where($map)->order('id asc')->count();
        $returnData['xian'] = $returnData_xian_2 + $returnData_xian_8;

        $returnData['he'] = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '3|%')->where($map)->order('id asc')->count();
        $returnData['zhuangDui'] = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '%|1')->where($map)->order('id asc')->count();
        $returnData['xianDui'] = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '%|2')->where($map)->order('id asc')->count();
        $returnData['zhuangXianDui'] = Luzhu::whereTime('create_time','>=', date('Y-m-d H:i:s',$startTime))->where('result', 'like', '%|3')->where($map)->order('id asc')->count();
        $returnData['zhuangDui'] += $returnData['zhuangXianDui'];
        $returnData['xianDui'] += $returnData['zhuangXianDui'];
        // 返回数据
        show($returnData, 1);
    }

    //获取发送的数据 荷官开牌
    public function set_post_data(): string
    {

        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        LogHelper::debug('=== 开牌流程开始 ===');
        LogHelper::debug('=== 开牌流程开始 ===');
        LogHelper::debug('=== 开牌流程开始 ===');
        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        LogHelper::debug('接收到荷官开牌请求', [
            'ip' => request()->ip(),
            'timestamp' => date('Y-m-d H:i:s')
        ]);


        $postField = 'gameType,tableId,xueNumber,puNumber,result,ext,pai_result';
        $params = $this->request->only(explode(',', $postField), 'param', null);
        LogHelper::debug('荷官原始参数', $params);

        try {
            validate(validates::class)->scene('lz_post')->check($params);
        } catch (ValidateException $e) {
            return show([], config('ToConfig.http_code.error'), $e->getError());
        }
        $map = array();
        $map['status'] = 1;
        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['xueNumber'];
        $map['pu_number'] = $params['puNumber'];
        $map['game_type'] = $params['gameType'];

        //查询当日最新的一铺牌
        $info = Luzhu::whereTime('create_time', 'today')->where('result','<>',0)->where($map)->find();
        if (!empty($info)) show($info, 0, '数据重复上传');

        #####开始预设###########
        //查询是否有预设的开牌信息
        $presetInfo = LuzhuPreset::LuZhuPresetFind($map);
        $map['update_time'] = $map['create_time'] = time();
        $HeguanLuzhu = $map;

        $id = 0;
        if ($presetInfo){
            //插入当前信息
            $id = $presetInfo['id'];
            $map['result'] = $presetInfo['result'];
            $map['result_pai'] = $presetInfo['result_pai'];
        }else{
            //插入当前信息
            $map['result'] = intval($params['result']) . '|' . intval($params['ext']);
            $map['result_pai'] = json_encode($params['pai_result']);
        }
        //荷官正常露珠
        $HeguanLuzhu['result'] = intval($params['result']) . '|' . intval($params['ext']);
        $HeguanLuzhu['result_pai'] = json_encode($params['pai_result']);
        #####结束预设###########


        // 增加缓存删除
        \think\facade\Cache::delete('luzhuinfo_'.$params['tableId']);

        // 根据游戏类型调用相应服务
        switch ($map['game_type']) {
            case 3:
                LogHelper::debug('调用百家乐开牌服务', ['table_id' => $map['table_id']]);
                $card = new CardSettlementService();
                return $card->open_game($map, $HeguanLuzhu, $id);
            default:
                LogHelper::error('不支持的游戏类型', ['game_type' => $map['game_type']]);
                show([], 404, 'game_type错误！');
        }
    }
    

    
    //删除指定露珠
    public function lz_delete(): string
    {
        $params = $this->request->param();
        $map = array();
        $map['table_id'] = $params['tableId'];
        $map['xue_number'] = $params['num_xue'];
        $map['pu_number'] = $params['num_pu'];
        Luzhu::where($map)->delete();
        show([], config('ToConfig.http_code.error'));
    }

    //清除一张台桌露珠
    public function lz_table_delete(): string
    {
        $table_id = $this->request->param('tableId', 0);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'), '台桌id错误');
        $del = Luzhu::where(['table_id' => $table_id])->delete();
        if ($del) show([]);
        show([], config('ToConfig.http_code.error'));
    }

    //设置靴号 荷官主动换靴号
    public function set_xue_number(): string
    {
        //过滤数据
        $postField = 'tableId,num_xue,gameType';
        $post = $this->request->only(explode(',', $postField), 'param', null);

        try {
            validate(validates::class)->scene('lz_set_xue')->check($post);
        } catch (ValidateException $e) {
            return show([], config('ToConfig.http_code.error'), $e->getError());
        }

        //取才创建时间最后一条数据
        $find = Luzhu::where('table_id', $post['tableId'])->order('id desc')->find();

        if ($find) {
            $xue_number['xue_number'] = $find->xue_number + 1;
        } else {
            $xue_number['xue_number'] = 1;
        }
        $post['status'] = 1;
        $post['table_id'] = $post['tableId'];
        $post['xue_number'] = $xue_number['xue_number'];
        $post['pu_number'] = 1;
        $post['update_time'] = $post['create_time'] = time();
        $post['game_type'] = $post['gameType'];
        $post['result'] = 0;
        $post['result_pai'] = 0;

        $save = (new Luzhu())->save($post);
        if ($save) show($post);
        show($post, config('ToConfig.http_code.error'));
    }

    //开局信号
    public function set_start_signal(): string
    {
        $table_id = $this->request->param('tableId', 0);               
        $time = (int) $this->request->param('time', 45);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'), 'tableId参数错误');
        $data = [
            'start_time' => time(),
            'countdown_time' => $time,
            'run_status' => 1,
            'wash_status'=>0,
            'update_time' => time(),
        ];
        $save = Table::where('id', $table_id)
            ->update($data);
        if (!$save) {
            show($data, config('ToConfig.http_code.error'));
        }
        $data['table_id'] = $table_id;
        Queue::later($time, TableEndTaskJob::class, $data,'bjl_end_queue');
        redis()->del('table_info_'.$table_id);
        redis()->set('table_set_start_signal_'.$table_id,$table_id,$time+5);//储存redis
        show($data);
    }

    //结束信号
    public function set_end_signal(): string
    {
        $table_id = $this->request->param('tableId', 0);
        if ($table_id <= 0) show([], config('ToConfig.http_code.error'));
        $save = Table::where('id', $table_id)
            ->update([
                'run_status' => 2,
                'wash_status'=>0,
                'update_time' => time(),
            ]);
        if ($save) show([]);
        show([], config('ToConfig.http_code.error'));
    }

    //台桌信息 靴号 铺号
    public function get_table_info()
    {
        $params = $this->request->param();
        $returnData = array();
        $info = Table::order('id desc')->find($params['tableId']);
        // 发给前台的 数据
        $returnData['lu_zhu_name'] = $info['lu_zhu_name'];
        $returnData['right_money_banker_player'] = $info['xian_hong_zhuang_xian_usd'];
        $returnData['right_money_banker_player_cny'] = $info['xian_hong_zhuang_xian_cny'];
        $returnData['right_money_tie'] = $info['xian_hong_he_usd'];
        $returnData['right_money_tie_cny'] = $info['xian_hong_he_cny'];
        $returnData['right_money_pair'] = $info['xian_hong_duizi_usd'];
        $returnData['right_money_pair_cny'] = $info['xian_hong_duizi_cny'];
        $returnData['video_near'] = $info['video_near'];
        $returnData['video_far'] = $info['video_far'];
        $returnData['time_start'] = $info['countdown_time'];

        // 获取最新的 靴号，铺号
        $xun = bureau_number($params['tableId'],true);
        $returnData['id'] = $info['id'];
        $returnData['num_pu'] = $xun['xue']['pu_number'];
        $returnData['num_xue'] = $xun['xue']['xue_number'];
         $returnData['bureau_number'] = $xun['bureau_number'];
        // 返回数据
        show($returnData, 1);
    }

    //台桌信息 靴号 铺号
    public function get_table_info_for_bet()
    {
        $params = $this->request->param();
        $returnData = array();
        $info = Table::order('id desc')->find($params['tableId']);
        // 获取最新的 靴号，铺号
        $xun = bureau_number($params['tableId'],true);
        $info['num_pu'] = $xun['xue']['pu_number'];
        $info['num_xue'] = $xun['xue']['xue_number'];
        // 返回数据
        show($info, 1);
    }
    
    //台桌信息
    public function get_table_wash_brand()
    {
        $tableId = $this->request->param('tableId',0);
        if ($tableId <=0 ) show([], config('ToConfig.http_code.error'),'台桌ID必填');
        $table  = Table::where('id',$tableId)->find();
        $status = $table->wash_status == 0 ? 1 : 0;
        $table->save(['wash_status'=>$status]);
        $returnData['result_info']  = ['table_info'=>['game_type'=>123456]];
        $returnData['money_spend']  = '';
        // 返回数据
        show([], 1);
    }

}