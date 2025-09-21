<?php

namespace app\service;

use app\model\Table;
use app\model\GameRecords;
use app\controller\common\LogHelper;
/**
 * ========================================
 * Workerman开牌服务类
 * ========================================
 * 
 * 功能说明：
 * - 处理百家乐游戏的开牌逻辑
 * - 获取台桌实时信息
 * - 为WebSocket推送提供数据支持
 * 
 * @package app\service
 * @author  系统开发团队
 */
class WorkerOpenPaiService
{
    /**
     * ========================================
     * 百家乐开牌信息处理
     * ========================================
     * 
     * 解析开牌数据并生成前端显示所需的牌面信息
     * 
     * @param string $pai_data JSON格式的牌面数据
     * @return array 包含开牌结果、牌面信息和闪烁效果的数组
     * 
     * 数据格式说明：
     * - 输入: JSON字符串，如 {"1":"13|h","2":"1|r","3":"0|0",...}
     * - 花色: h=红桃, r=黑桃, m=梅花, f=方块
     * - 牌值: 1-13 (1=A, 11=J, 12=Q, 13=K)
     * - 位置: 1,2,3=庄家牌, 4,5,6=闲家牌
     */
    public function get_pai_info_bjl($pai_data)
    {
        // 解析JSON数据
        $pai_data = $pai_info = json_decode($pai_data, true);
        
        // 初始化牌面信息数组
        $info = [];
        
        // 遍历处理每张牌的数据
        foreach ($pai_info as $key => $value) {
            // 跳过空牌（0|0表示没有牌）
            if ($value == '0|0') {
                unset($pai_info[$key]);
                continue;
            }
            
            // 分离牌值和花色 格式：牌值|花色
            $pai = explode('|', $value);
            
            // 根据位置分配到庄家或闲家
            if ($key == 1 || $key == 2 || $key == 3) {
                // 位置1,2,3为庄家的牌
                $info['zhuang'][$key] = $pai[1] . $pai[0] . '.png';
            } else {
                // 位置4,5,6为闲家的牌  
                $info['xian'][$key] = $pai[1] . $pai[0] . '.png';
            }
        }
        
        // 计算游戏结果
        $card = new OpenPaiCalculationService();
        
        // 运行完整的牌面计算逻辑
        $pai_result = $card->runs($pai_data);
        
        // 获取需要闪烁显示的投注区域
        $pai_flash = $card->pai_flash($pai_result);
        
        // 返回完整的开牌信息
        return [
            'result'    => $pai_result,  // 游戏计算结果
            'info'      => $info,        // 牌面显示信息
            'pai_flash' => $pai_flash    // 中奖区域闪烁效果
        ];
    }

    /**
     * ========================================
     * 获取台桌完整信息
     * ========================================
     * 
     * 为WebSocket客户端提供台桌的实时状态信息
     * 
     * @param int $id 台桌ID
     * @param int $user_id 用户ID
     * @return array 台桌完整信息数组，失败返回空数组
     * 
     * 返回信息包含：
     * - 台桌基础信息（名称、状态、限红等）
     * - 倒计时信息
     * - 视频流地址
     * - 当前局号信息
     * - 用户免佣状态
     */
    public function get_table_info($id, $user_id)
    {
        // 参数验证和类型转换
        $id = intval($id);
        $user_id = intval($user_id);
        
        // 验证台桌ID有效性
        if ($id <= 0) {
            return [];
        }
        
        // 验证用户ID有效性  
        if ($user_id <= 0) {
            return [];
        }

        // ========================================
        // 获取台桌基础信息
        // ========================================
        $info = Table::page_one($id);
        
        // ========================================
        // 计算台桌倒计时和视频地址
        // ========================================
        $info = Table::table_opening_count_down($info);
        
        // ========================================
        // 获取当前游戏局号信息
        // ========================================
        // 生成局号：年月日时+台桌ID+靴号+铺号
        $bureau_number = bureau_number($id, true);
        $info['bureau_number'] = $bureau_number['bureau_number'];
        
        // ========================================
        // 获取用户在当前局的免佣状态
        // ========================================
        // 免佣状态说明：
        // - 0: 收取佣金（庄赢收5%佣金）
        // - 1: 免佣模式（庄6点赢只赔50%，其他正常赔付）
        $user['id'] = $user_id;
        $info['is_exempt'] = GameRecords::user_status_bureau_number_is_exempt(
            $id, 
            $bureau_number['xue'], 
            $user
        );
        
        return $info;
    }
    

}

/**
 * ========================================
 * 类使用说明
 * ========================================
 * 
 * 1. get_pai_info_bjl() 方法：
 *    - 用于Workerman推送开牌结果给客户端
 *    - 处理牌面数据格式转换
 *    - 计算游戏胜负结果
 * 
 * 2. get_table_info() 方法：
 *    - 用于客户端连接时获取台桌状态
 *    - 提供实时的倒计时信息
 *    - 返回用户个性化的游戏状态
 * 
 * 数据流向：
 * 客户端连接 -> get_table_info() -> 返回台桌信息
 * 荷官开牌 -> get_pai_info_bjl() -> 推送开牌结果
 */