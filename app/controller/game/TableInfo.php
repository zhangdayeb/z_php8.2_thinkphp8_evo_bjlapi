    
    namespace app\controller\game;

use app\controller\common\LogHelper;
use app\BaseController;
use app\model\Luzhu;
use app\model\Table;
use app\job\TableStartJob;
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
extends Base
    
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

   //获取台桌露珠信息
    public function get_lz_list(): string
    {
        $params = $this->request->param();
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
