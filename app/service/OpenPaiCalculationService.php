<?php

namespace app\service;
use app\controller\common\LogHelper;
/**
 * ========================================
 * 百家乐开牌计算服务类
 * ========================================
 * 
 * 功能概述：
 * - 处理百家乐游戏的牌面计算逻辑
 * - 计算庄闲家点数和各种特殊牌型
 * - 判断游戏胜负结果和特殊投注
 * - 提供牌面信息的格式化输出
 * 
 * 使用方法说明：
 * 1. 主要调用 runs() 方法执行完整计算流程
 * 2. runs() 内部按顺序调用三个核心方法：
 *    - calculation() : 解析原始牌面数据
 *    - calculation_start() : 计算庄闲点数和特殊牌型
 *    - calculation_result() : 判断最终胜负和幸运6
 * 
 * 牌面数据格式：
 * - 输入：["1"=>"13|h", "2"=>"1|r", ...] 
 * - 格式：位置索引 => "牌值|花色"
 * - 位置：1,2,3=庄家牌，4,5,6=闲家牌
 * - 花色：h=黑桃, r=红桃, f=方块, m=梅花
 * 
 * @package app\service
 * @author  系统开发团队
 * @version 2.0
 */
class OpenPaiCalculationService
{
    /**
     * ========================================
     * 执行完整的牌面计算流程
     * ========================================
     * 
     * 主入口方法，按顺序执行三个计算步骤，
     * 将原始牌面数据转化为完整的游戏结果
     * 
     * @param array $pai 原始牌面数据数组
     * @return array 完整的游戏计算结果
     */
    public function runs(array $pai): array
    {
        LogHelper::debug('=== 开牌计算开始 ===');
        LogHelper::debug('原始牌面数据', $pai);
        
        $calculation_data = $this->calculation($pai);
        LogHelper::debug('数据整理结果', $calculation_data);
        
        $calculation_start = $this->calculation_start($calculation_data);
        LogHelper::debug('中间计算结果', $calculation_start);
        
        $result = $this->calculation_result($calculation_start);
        
        LogHelper::debug('开牌计算完成', [
            'zhuang_point' => $result['zhuang_point'],
            'xian_point' => $result['xian_point'],
            'win_array' => $result['win_array'],
            'win_types' => array_map([$this, 'user_pai_chinese'], $result['win_array'])
        ]);
        LogHelper::debug('===============================最终计算结果==========================================');  
        LogHelper::debug(json_encode($result));

        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        LogHelper::debug('=== 开牌计算完成 ===');
        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        LogHelper::debug('===================================================');
        
        return $result;
    }

    /**
     * ========================================
     * 第一步：解析和整理原始牌面数据
     * ========================================
     * 
     * 将数据库中的字符串格式牌面数据转换为
     * 结构化的数组格式，便于后续计算处理
     * 
     * 数据转换示例：
     * 输入: ["1"=>"13|h", "2"=>"1|r"]
     * 输出: [1=>[13,"h"], 2=>[1,"r"]]
     * 
     * @param array $pai 原始牌面数据
     * @return array 整理后的结构化数据
     */
    public function calculation($pai): array
    {
        $data = [];
        
        foreach ($pai as $key => $value) {
            // 确保键为整数类型
            $key = intval($key);
            
            // 分离牌值和花色：格式 "牌值|花色"
            $data[$key] = explode('|', $value);
            
            // 将牌值转换为整数，花色保持字符串
            $data[$key][0] = intval($data[$key][0]);
            // $data[$key][1] 花色保持不变
        }
        
        return $data;
    }

    /**
     * ========================================
     * 第二步：计算庄闲点数和特殊牌型
     * ========================================
     * 
     * 基于整理后的牌面数据，计算以下内容：
     * - 庄家和闲家的点数总和
     * - 庄对、闲对的判断
     * - 幸运6相关的牌数计算
     * - 大小判断的基础数据
     * - 牌面的中文描述字符串
     * 
     * @param array $data 经过calculation()处理的数据
     * @return array 包含所有中间计算结果的数组
     */
    public function calculation_start(array $data): array
    {
        // ========================================
        // 初始化计算变量
        // ========================================
        $luckySize = 2;         // 幸运6的牌数（默认2张）
        $size = 0;              // 大小计数器（用于判断大小）
        $zhuang_dui = 0;        // 是否庄对
        $xian_dui = 0;          // 是否闲对
        $lucky = 0;             // 幸运6点数累计
        $zhuang_string = '';    // 庄家牌面描述
        $zhuang_count = 0;      // 庄家牌数量
        $xian_string = '';      // 闲家牌面描述
        $xian_count = 0;        // 闲家牌数量
        $zhuang_point = 0;      // 庄家总点数
        $xian_point = 0;        // 闲家总点数

        // ========================================
        // 预处理：特殊判断逻辑
        // ========================================
        
        // 判断幸运6的牌数（如果庄家第三张牌存在）
        if ($data[1][0] != 0 && $data[2][0] != 0 && $data[3][0] != 0) {
            $luckySize = 3; // 庄家有3张牌
        }

        // 判断庄对：比较庄家前两张牌的牌值
        // 位置 1,2 是主牌 3 是补牌  
        if (isset($data[1], $data[2])) {
            if( ($data[1][0] === $data[2][0])){
                $zhuang_dui = 1;
            }            
        }

        // 判断闲对：比较闲家前两张牌的牌值
        if (isset($data[4], $data[5])) {
            if( ($data[4][0] === $data[5][0]) ){
                $xian_dui = 1;
            }   
        }
        LogHelper::debug('庄对 闲对判断', $data);
        // ========================================
        // 花色映射表
        // ========================================
        $flower = [
            'r' => '红桃', 
            'h' => '黑桃', 
            'f' => '方块', 
            'm' => '梅花'
        ];

        // ========================================
        // 主循环：遍历所有牌进行计算 6张 牌的数据
        // ========================================
        foreach ($data as $key => $value) {
            
            // --- 大小判断预处理 ---
            // 统计0点牌的数量，用于后续大小判断
            if ($value[0] == 0) {
                $size++;
            }

            // ========================================
            // 牌面描述字符串生成  方便前后端展示
            // ========================================
            if ($value[0] > 0) {
                $pai = $value[0];
                $pai_flower = $flower[$value[1]] ?? '未知';

                // 特殊牌值转换
                switch ($value[0]) {
                    case 1:
                        $pai = 'A';
                        break;
                    case 11:
                        $pai = 'J';
                        break;
                    case 12:
                        $pai = 'Q';
                        break;
                    case 13:
                        $pai = 'K';
                        break;
                }

                // 根据位置分配到庄家或闲家的描述字符串
                if ($key == 1 || $key == 2 || $key == 3) {
                    $zhuang_string .= $pai_flower . $pai . '-';
                } elseif ($key == 4 || $key == 5 || $key == 6) {
                    $xian_string .= $pai_flower . $pai . '-';
                }
            }

            // ========================================
            // 点数累计计算  这个位置不要进行点数 折合计算 10 11 12 13 先不转化成0
            // ========================================
            
            // 庄家点数累计（位置1,2,3）
            if ($key == 1 || $key == 2 || $key == 3) {
                if ($value[0] != 0) {
                    $zhuang_count++; // 统计庄家有效牌数
                }
            }

            // 闲家点数累计（位置4,5,6）
            if ($key == 4 || $key == 5 || $key == 6) {
                if ($value[0] != 0) {
                    $xian_count++; // 统计闲家有效牌数
                }
            }

            // ========================================
            // 百家乐规则：大于9点的牌按0点计算  10 11 12 13 需要转化成0 算作点数
            // ========================================
            if ($value[0] > 9) {
                $value[0] = 0;
            }

            // 庄家点数累计（位置1,2,3）
            if ($key == 1 || $key == 2 || $key == 3) {
                $zhuang_point += $value[0];
                $lucky += $value[0]; // 幸运6点数累计
            }

            // 闲家点数累计（位置4,5,6）
            if ($key == 4 || $key == 5 || $key == 6) {
                if ($value[0] != 0) {
                    $xian_point += $value[0];
                }
            }

        }

        // ========================================
        // 大小结果判断
        // ========================================
        // 逻辑：0点牌少于2张为"大"，否则为"小"
        if ($size < 2) {
            $size = 1; // 大
        } else {
            $size = 0; // 小
        }

        // ========================================
        // 返回所有中间计算结果
        // ========================================
        return [
            'luckySize'    => $luckySize,    // 幸运6牌数（2或3）
            'size'         => $size,         // 大小结果（0=小，1=大）
            'zhuang_point' => $zhuang_point, // 庄家总点数
            'xian_point'   => $xian_point,   // 闲家总点数
            'zhuang_dui'   => $zhuang_dui,   // 是否庄对
            'xian_dui'     => $xian_dui,     // 是否闲对
            'lucky'        => $lucky,        // 幸运6点数
            'zhuang_count' => $zhuang_count, // 庄家牌数
            'xian_count'   => $xian_count,   // 闲家牌数
            'zhuang_string'=> $zhuang_string, // 庄家牌面描述
            'xian_string'  => $xian_string,  // 闲家牌面描述
        ];
    }

    /**
     * ========================================
     * 第三步：计算最终游戏结果
     * ========================================
     * 
     * 基于中间计算结果，确定最终的游戏胜负：
     * - 庄闲点数取余数（百家乐规则）
     * - 比较点数大小确定胜负
     * - 判断是否触发幸运6特殊奖励
     * 
     * 返回结果说明：
     * - win: 1=庄赢, 2=闲赢, 3=和牌, 0=错误
     * - lucky: 0=非幸运6, 1=触发幸运6
     * 
     * @param array $res 中间计算结果数组
     * @return array 最终游戏结果数组
     */
    public function calculation_result(array $res): array
    {
        // ========================================
        // 百家乐规则：所有点数取余数
        // ========================================
        $res['zhuang_point'] = $res['zhuang_point'] % 10;
        $res['xian_point'] = $res['xian_point'] % 10;
        $res['lucky'] = $res['lucky'] % 10;

        // ========================================
        // 胜负判断逻辑  新的逻辑 支持 庄 闲 和 幸运6(单双) 龙7 熊8 大小老虎 也就是说
        // 这里 win_array 添加的 数字 是 ntp_dianji_game_peilv 赔率表里面的ID 根据用户迎娶的选项 去 计算赔率 金钱这些
        // ========================================
        $win_array = [];   // 主结果：1=庄赢, 2=闲赢, 3=和牌, 0=错误       
        
        if (intval($res['xian_dui']) === 1) {
            // 闲对
            $win_array[] = 2;
        }
        if (intval($res['zhuang_dui']) === 1) {
            // 庄对
            $win_array[] = 4;
        }
        if (intval($res['zhuang_point']) < intval($res['xian_point'])) {
            // 闲家点数大 == 闲赢
            $win_array[] = 6;
        }
        if (intval($res['zhuang_point']) === intval($res['xian_point'])) {
            // 点数相等 == 和牌
            $win_array[] = 7;
        } 
        if (intval($res['zhuang_point']) > intval($res['xian_point'])) {
            // 庄家点数大 == 庄赢
            $win_array[] = 8;
            if (intval($res['lucky']) === 6) {
                // 幸运6判断：庄赢且庄家点数为6点
                $win_array[] = 3;
            }
        }
        // ========================================
        // 修正：龙7判断 - 庄7点赢且庄家有3张牌
        // ========================================
        if ((intval($res['zhuang_point']) > intval($res['xian_point'])) 
            && (intval($res['zhuang_point']) === 7) 
            && (intval($res['zhuang_count']) === 3)) {
            // 庄家点数大 == 庄赢 且 庄7点 且 庄家3张牌 = 龙7
            $win_array[] = 9;
        }
        
        // ========================================
        // 修正：熊8判断 - 闲8点赢且闲家有3张牌
        // ========================================        
        if ((intval($res['zhuang_point']) < intval($res['xian_point'])) 
            && (intval($res['xian_point']) === 8) 
            && (intval($res['xian_count']) === 3)) {
            // 闲家点数大 == 闲赢 且 闲8点 且 闲家3张牌 = 熊8
            $win_array[] = 10;
        }
        if (intval($res['lucky']) === 6 && intval($res['luckySize']) ===2 ) {
            // 幸运6判断：庄赢且庄家点数为6点  小老虎
            $win_array[] = 11; 
        }
        if (intval($res['lucky']) === 6 && intval($res['luckySize']) ===3 ) {
            // 幸运6判断：庄赢且庄家点数为6点 大老虎
            $win_array[] = 12;
        }        

        // 更新结果到数组中
        $res['win_array'] = $win_array;

        return $res;
    }

    /**
     * ========================================
     * 判断用户投注是否中奖
     * ========================================
     * 
     * 根据用户的投注类型ID和游戏结果，
     * 判断该笔投注是否中奖
     * 
     * 投注类型对照表：
     * 1=大, 2=闲对, 3=幸运6, 4=庄对, 5=小, 6=闲, 7=和, 8=庄
     * 
     * @param int $resId 用户购买的投注类型ID
     * @param array $paiInfo 游戏计算结果
     * @return bool true=中奖, false=未中奖
     */
    public function user_win_or_not(int $resId, array $paiInfo): bool
    {
        // ========================================
        // 胜负判断逻辑  新的逻辑 支持 庄 闲 和 幸运6(单双) 龙7 熊8 大小老虎 也就是说
        // 这里 win_array 添加的 数字 是 ntp_dianji_game_peilv 赔率表里面的ID 根据用户迎娶的选项 去 计算赔率 金钱这些
        // ========================================       
        // 判断用户投注信息 是否在 中将池子里面
        return in_array($resId, $paiInfo['win_array']); 


    }

    /**
     * ========================================
     * 将投注类型ID转换为中文描述
     * ========================================
     * 
     * @param int $res 投注类型ID
     * @return string 中文描述
     */
    public function user_pai_chinese(int $res): string
    {
        // 这个数组 可以根据 游戏类型 去 赔率表里面读取 这个位置 闲临时这样用了
        $pai_names = [
            1 => '大', 
            2 => '闲对', 
            3 => '幸运6', 
            4 => '庄对', 
            5 => '小', 
            6 => '闲', 
            7 => '和', 
            8 => '庄',
            9 => '龙7', 
            10 => '熊8', 
            11 => '大老虎', 
            12 => '小老虎',
        ];
        
        return $pai_names[$res] ?? '未知';
    }

    /**
     * ========================================
     * 将游戏结果转换为中文描述字符串
     * ========================================
     * 
     * @param array $paiInfo 游戏计算结果
     * @return string 中文结果描述，用"|"分隔
     */
    public function pai_chinese(array $paiInfo): string
    {
        $string = '';
        foreach ($paiInfo['win_array'] as $win) {
            $string .= $this->user_pai_chinese($win).'|';
        }
        return $string;

    }

    /**
     * ========================================
     * 获取牌面信息的格式化输出
     * ========================================
     * 
     * @param array $paiInfo 游戏计算结果
     * @return array 格式化的牌面信息
     */
    public function pai_info(array $paiInfo): array
    {
        if (empty($paiInfo)) {
            return ['z' => '庄:', 'x' => '闲:'];
        }
        
        return [
            'z' => '庄:' . $paiInfo['zhuang_string'] . '  ', 
            'x' => '闲:' . $paiInfo['xian_string']
        ];
    }

    /**
     * ========================================
     * 生成需要闪烁显示的投注区域ID数组
     * ========================================
     * 
     * 根据游戏结果，返回前端需要高亮闪烁的
     * 投注区域ID数组，用于视觉效果展示
     * 
     * @param array $paiInfo 游戏计算结果
     * @return array 需要闪烁的投注区域ID数组
     */
    public function pai_flash(array $paiInfo): array
    {
        $map = [];
        
        foreach ($paiInfo['win_array'] as $win) {
            $map[] = $win;
        }

        return $map;
    }
}

/**
 * ========================================
 * 类使用说明和业务规则
 * ========================================
 * 
 * 1. 核心业务流程：
 *    原始数据 -> calculation() -> calculation_start() -> calculation_result()
 * 
 * 2. 百家乐规则要点：
 *    - 所有牌值大于9按0计算（J,Q,K,10都是0点）
 *    - 总点数超过9取余数（如15点=5点）
 *    - 庄闲点数相等为和牌
 *    - 幸运6：庄赢且庄家点数为6点
 * 
 * 3. 特殊投注规则：
 *    - 大小：根据0点牌的数量判断
 *    - 对子：同位置前两张牌牌值相同
 *    - 幸运6：特殊奖励投注，赔率较高
 * 
 * 4. 数据结构说明：
 *    - 位置1,2,3：庄家的牌
 *    - 位置4,5,6：闲家的牌
 *    - 花色：h=黑桃, r=红桃, f=方块, m=梅花
 * 
 * 5. 性能优化：
 *    - 单次计算完成所有结果
 *    - 避免重复数据处理
 *    - 结构化的数据返回格式
 */