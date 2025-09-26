<?php
namespace app\controller\game;

use app\BaseController;
use app\controller\common\LogHelper;
use app\model\Luzhu;
use app\model\Table;
use think\facade\Db;

/**
 * 台桌信息控制器
 * 处理百家乐游戏台桌相关的所有查询和操作
 */
class TableInfo extends BaseController
{
    /**
     * 获取台桌列表
     * @return string JSON响应
     */
    public function get_table_list(): string
    {
        try {
            // 游戏类型视图映射
            $gameTypeView = [
                '1' => '_nn',   // 牛牛
                '2' => '_lh',   // 龙虎
                '3' => '_bjl'   // 百家乐
            ];
            
            // 查询所有启用的台桌
            $tables = Table::where(['status' => 1])
                ->order('id asc')
                ->select()
                ->toArray();
            
            if (empty($tables)) {
                show([], 1, '暂无可用台桌');
            }
            
            // 处理每个台桌的附加信息
            foreach ($tables as $k => $v) {
                // 设置视图类型
                $tables[$k]['viewType'] = $gameTypeView[$v['game_type']] ?? '_bjl';
                
                // 生成随机在线人数（演示用）
                $tables[$k]['number'] = rand(100, 3000);
                
                // 获取当前靴号
                $luZhu = Luzhu::where([
                    'status' => 1, 
                    'table_id' => $v['id']
                ])
                ->whereTime('create_time', 'today')
                ->order('id desc')
                ->find();
                
                $tables[$k]['xue_number'] = $luZhu ? $luZhu['xue_number'] : 1;
            }
            
            show($tables, 1, '获取台桌列表成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取台桌列表失败', [
                'error' => $e->getMessage()
            ]);
            show([], config('ToConfig.http_code.error'), '获取台桌列表失败');
        }
    }
    
    /**
     * 获取台桌详细信息（合并后的方法）
     * 包含：基础信息、靴号铺号、视频地址、洗牌状态等
     * @return string JSON响应
     */
public function get_table_info(): string
{
    $tableId = $this->request->param('tableId', 0);
    
    // 参数验证
    if (empty($tableId) || !is_numeric($tableId)) {
        show([], config('ToConfig.http_code.error'), '台桌ID必填且必须为数字');
    }
    
    try {
        // 查询台桌信息
        $tableInfo = Table::find($tableId);
        if (empty($tableInfo)) {
            show([], config('ToConfig.http_code.error'), '台桌不存在');
        }
        
        // 获取原始数据
        $tableData = $tableInfo->toArray();
        
        // 获取靴号和铺号
        $bureauInfo = xue_number($tableId);
        
        // 添加靴号铺号到原始数据
        $tableData['xue_number'] = $bureauInfo['xue_number'];
        $tableData['pu_number'] = $bureauInfo['pu_number'];
        
        show($tableData, 1, '获取台桌信息成功');
        
    } catch (\Exception $e) {
        LogHelper::error('获取台桌信息失败', [
            'table_id' => $tableId,
            'error' => $e->getMessage()
        ]);
        show([], config('ToConfig.http_code.error'), '获取台桌信息失败');
    }
}
    
    /**
     * 获取台桌今日各结果出现次数统计
     * @return string JSON响应
     */
    
    public function get_table_count(): string
    {
        // 获取参数
        $tableId = $this->request->param('tableId', 0);
        $bureauInfo = xue_number($tableId);
        $xueNumber = $bureauInfo['xue_number'];


        $map = array();
        $map['status'] = 1;
        $map['table_id'] = $tableId;
        $map['xue_number'] = $xueNumber;


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
    /**
     * 获取露珠列表
     * @return string JSON响应
     */
    public function get_lz_list(): string
    {
        $params = $this->request->param();
        $tableId = $this->request->param('tableId',0);
        if ($tableId <=0 ) show([], config('ToConfig.http_code.error'),'台桌ID必填');
        $returnData = Luzhu::LuZhuList($params);     
        show($returnData);
    }
    
    /**
     * 切换台桌洗牌状态
     * @return string JSON响应
     */
    public function get_table_wash_brand(): string
    {
        $tableId = $this->request->param('tableId', 0);
        
        // 参数验证
        if (empty($tableId)) {
            show([], config('ToConfig.http_code.error'), '台桌ID必填');
        }
        
        try {
            // 查询台桌
            $table = Table::find($tableId);
            if (empty($table)) {
                show([], config('ToConfig.http_code.error'), '台桌不存在');
            }
            
            // 切换洗牌状态
            $newStatus = $table->wash_status == 0 ? 1 : 0;
            $table->save(['wash_status' => $newStatus]);
            
            LogHelper::info('切换洗牌状态', [
                'table_id' => $tableId,
                'old_status' => $table->wash_status == $newStatus ? 0 : 1,
                'new_status' => $newStatus
            ]);
            
            $returnData = [
                'table_id' => $tableId,
                'wash_status' => $newStatus,
                'message' => $newStatus == 1 ? '开始洗牌' : '洗牌结束'
            ];
            
            show($returnData, 1, '操作成功');
            
        } catch (\Exception $e) {
            LogHelper::error('切换洗牌状态失败', [
                'table_id' => $tableId,
                'error' => $e->getMessage()
            ]);
            show([], config('ToConfig.http_code.error'), '操作失败');
        }
    }
}