<?php

namespace app\service;
use app\controller\common\LogHelper;
use think\facade\Db;

/**
 * ========================================
 * 卡牌游戏基础服务类
 * ========================================
 * 
 * 功能说明：
 * - 提供卡牌游戏的基础功能服务
 * - 处理开牌信息的缓存读取
 * - 管理用户派彩金额的临时存储
 * - 记录开牌历史数据
 * 
 * 使用场景：
 * - Workerman推送开牌结果时调用
 * - 用户查询派彩结果时调用
 * - 开牌数据持久化存储
 * 
 * @package app\service
 * @author  系统开发团队
 */
class CardServiceBase
{
    /**
     * ========================================
     * 从Redis获取开牌展示信息
     * ========================================
     * 
     * 从Redis缓存中读取指定台桌的开牌数据，并根据游戏类型
     * 调用相应的解析服务生成前端显示所需的牌面信息
     * 
     * Redis存储说明：
     * - Key格式: table_id_{台桌ID}_{游戏类型}
     * - 存储时间: 开牌后5秒（用于实时推送）
     * - 数据格式: JSON字符串，包含所有牌面信息
     * 
     * @param int $table_id 台桌ID
     * @param int $game_type 游戏类型 (1=牛牛, 2=龙虎, 3=百家乐)
     * @return array|false 开牌信息数组，无数据时返回false
     */
    public function get_pai_info($table_id, $game_type)
    {
        // 构建Redis键名
        $redis_key = 'table_id_' . $table_id . '_' . $game_type;
        
        // 从Redis获取开牌数据
        $pai_data = redis()->get($redis_key);
        
        // 检查数据是否存在
        if (empty($pai_data)) {
            return false;
        }
        
        // 初始化开牌服务
        $service = new WorkerOpenPaiService();
        
        // 根据游戏类型调用相应的处理方法
        switch ($game_type) {
            case 3: // 百家乐游戏
                return $service->get_pai_info_bjl($pai_data);
                break;
                
            default:
                // 未知游戏类型
                return [];
        }
        
        return [];
    }

    /**
     * ========================================
     * 获取用户派彩金额
     * ========================================
     * 
     * 从Redis中获取指定用户在指定台桌的派彩金额
     * 获取后立即删除，确保派彩金额只能领取一次
     * 
     * Redis存储说明：
     * - Key格式: user_{用户ID}_table_id_{台桌ID}_{游戏类型}
     * - 存储时间: 开牌结算后5秒（用于派彩显示）
     * - 数据内容: 用户本局的输赢金额（正数=赢钱，负数=输钱）
     * 
     * @param int $user 用户ID
     * @param int $table_id 台桌ID
     * @param int $game_type 游戏类型
     * @return int|false 派彩金额，无数据时返回false
     */
    public function get_payout_money($user, $table_id, $game_type)
    {
        // 构建Redis键名
        $redis_key = 'user_' . $user . '_table_id_' . $table_id . '_' . $game_type;
        
        // 从Redis获取派彩金额
        $money = redis()->get($redis_key);
        
        // 检查数据是否存在（注意：金额可能为0，所以用===判断）
        if ($money === null) {
            return false;
        }
        
        // 获取后立即删除，防止重复领取
        redis()->del($redis_key);
        
        return $money;
    }

}

/**
 * ========================================
 * 类使用说明和最佳实践
 * ========================================
 * 
 * 1. 数据流向：
 *    荷官开牌 -> 结算服务 -> Redis缓存 -> 本服务读取 -> 推送给客户端
 * 
 * 2. Redis缓存策略：
 *    - 开牌数据：存储5秒，用于实时推送
 *    - 派彩数据：存储5秒，用户领取后删除
 *    - 避免数据长期占用内存
 * 
 * 3. 错误处理：
 *    - 缓存未命中返回false，不抛出异常
 *    - 上层调用需要检查返回值
 * 
 * 4. 扩展说明：
 *    - 新增游戏类型时，在get_pai_info()中添加case分支
 *    - 每种游戏类型需要对应的解析方法
 * 
 * 5. 性能优化：
 *    - Redis读取速度快，适合高并发场景
 *    - 数据库写入异步处理，不影响用户体验
 */