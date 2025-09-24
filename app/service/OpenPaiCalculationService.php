<?php

namespace app\service;

use app\controller\common\LogHelper;

/**
 * 百家乐开牌计算服务类
 * 
 * 功能：处理百家乐游戏的牌面计算、点数统计、胜负判断
 * 
 * 牌面数据格式：
 * - 输入：["1"=>"13|h", "2"=>"1|r", ...] 
 * - 格式：位置索引 => "牌值|花色"
 * - 位置：1,2,3=庄家牌，4,5,6=闲家牌
 * - 花色：h=黑桃, r=红桃, f=方块, m=梅花
 * 
 * @package app\service
 */
class OpenPaiCalculationService
{
    /**
     * 花色映射表
     */
    private const FLOWER_MAP = [
        'r' => '红桃', 
        'h' => '黑桃', 
        'f' => '方块', 
        'm' => '梅花'
    ];
    
    /**
     * 执行完整的牌面计算流程
     * 
     * @param array $pai 原始牌面数据
     * @return array 游戏计算结果
     */
    public function runs(array $pai): array
    {
        LogHelper::debug('=== 开牌计算开始 ===');
        LogHelper::debug('原始牌面数据', $pai);
        
        // 第一步：解析牌面数据
        $calculation_data = $this->calculation($pai);
        LogHelper::debug('数据整理结果', $calculation_data);
        
        // 第二步：计算点数和特殊牌型
        $calculation_start = $this->calculation_start($calculation_data);
        LogHelper::debug('中间计算结果', $calculation_start);
        
        // 第三步：判断最终结果
        $result = $this->calculation_result($calculation_start);        
        LogHelper::debug('最终计算结果', $result);
        LogHelper::debug('=== 开牌计算完成 ===');
        return $result;
    }

    /**
     * 第一步：解析和整理原始牌面数据
     * 
     * @param array $pai 原始牌面数据
     * @return array 结构化数据
     */
    public function calculation($pai): array
    {
        $data = [];
        
        foreach ($pai as $key => $value) {
            $key = intval($key);
            // 分离牌值和花色：格式 "牌值|花色"
            $data[$key] = explode('|', $value);
            $data[$key][0] = intval($data[$key][0]);
        }
        
        return $data;
    }

    /**
     * 第二步：计算庄闲点数和特殊牌型
     * 
     * @param array $data 牌面数据
     * @return array 计算结果
     */
    public function calculation_start(array $data): array
    {
        // 初始化变量
        $result = $this->initializeVariables();
        
        // 判断特殊牌型
        $result['luckySize'] = $this->checkLuckySize($data);
        // 正确的判断方式（只看前两张）
        // 庄对：位置1 和 位置2 的牌值相同
        // 闲对：位置4 和 位置5 的牌值相同
        $result['zhuang_dui'] = $this->checkPair($data[1] ?? null, $data[2] ?? null);
        $result['xian_dui'] = $this->checkPair($data[4] ?? null, $data[5] ?? null);
        
        LogHelper::debug('对子判断', [
            'zhuang_dui' => $result['zhuang_dui'],
            'xian_dui' => $result['xian_dui']
        ]);
        
        // 遍历所有牌进行计算
        foreach ($data as $key => $value) {
            if ($value[0] == 0) {
                continue;
            }
            
            // 生成牌面描述
            $this->buildCardString($key, $value, $result);
            
            // 统计牌数
            $this->countCards($key, $value, $result);
            
            // 计算点数（10以上按0算）
            $point = $value[0] > 9 ? 0 : $value[0];
            $this->calculatePoints($key, $point, $result);
        }
        
        
        return $result;
    }

    /**
     * 第三步：计算最终游戏结果
     * 
     * @param array $res 中间计算结果
     * @return array 最终结果
     */
    public function calculation_result(array $res): array
    {
        // 百家乐规则：点数取余
        $res['zhuang_point'] = $res['zhuang_point'] % 10;
        $res['xian_point'] = $res['xian_point'] % 10;
        $res['lucky'] = $res['lucky'] % 10;
        // 返回最终结算的结果        
        return $res;
    }
    
    // ========== 辅助方法 ==========
    
    /**
     * 初始化计算变量
     */
    private function initializeVariables(): array
    {
        return [
            'luckySize' => 2,           // 幸运6牌数
            'zhuang_dui' => 0,          // 庄对
            'xian_dui' => 0,            // 闲对
            'lucky' => 0,               // 幸运6点数
            'zhuang_string' => '',      // 庄家牌面描述
            'zhuang_count' => 0,        // 庄家牌数
            'xian_string' => '',        // 闲家牌面描述
            'xian_count' => 0,          // 闲家牌数
            'zhuang_point' => 0,        // 庄家点数
            'xian_point' => 0,          // 闲家点数
        ];
    }
    
    /**
     * 检查幸运6牌数
     */
    private function checkLuckySize(array $data): int
    {
        if (isset($data[1], $data[2], $data[3]) && 
            $data[1][0] != 0 && $data[2][0] != 0 && $data[3][0] != 0) {
            return 3;
        }
        return 2;
    }
    
    /**
     * 检查是否成对
     */
    private function checkPair($card1, $card2): int
    {
        if ($card1 && $card2 && $card1[0] === $card2[0]) {
            return 1;
        }
        return 0;
    }
    
    /**
     * 构建牌面描述字符串
     */
    private function buildCardString(int $key, array $value, array &$result): void
    {
        $pai = $this->formatCardValue($value[0]);
        $flower = self::FLOWER_MAP[$value[1]] ?? '未知';
        
        if (in_array($key, [1, 2, 3])) {
            $result['zhuang_string'] .= $flower . $pai . '-';
        } elseif (in_array($key, [4, 5, 6])) {
            $result['xian_string'] .= $flower . $pai . '-';
        }
    }
    
    /**
     * 格式化牌值显示
     */
    private function formatCardValue(int $value): string
    {
        switch ($value) {
            case 1:  return 'A';
            case 11: return 'J';
            case 12: return 'Q';
            case 13: return 'K';
            default: return (string)$value;
        }
    }
    
    /**
     * 统计牌数
     */
    private function countCards(int $key, array $value, array &$result): void
    {
        if ($value[0] == 0) {
            return;
        }
        
        if (in_array($key, [1, 2, 3])) {
            $result['zhuang_count']++;
        } elseif (in_array($key, [4, 5, 6])) {
            $result['xian_count']++;
        }
    }
    
    /**
     * 计算点数
     */
    private function calculatePoints(int $key, int $point, array &$result): void
    {
        if (in_array($key, [1, 2, 3])) {
            $result['zhuang_point'] += $point;
            $result['lucky'] += $point;
        } elseif (in_array($key, [4, 5, 6])) {
            $result['xian_point'] += $point;
        }
    }
}