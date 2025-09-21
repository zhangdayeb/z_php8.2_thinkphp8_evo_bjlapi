<?php
declare (strict_types=1);

namespace app\validate;

use think\Validate;

class BetOrder extends Validate
{
    /**
     * 定义验证规则
     * 格式：'字段名' =>  ['规则1','规则2'...]
     *
     * @var array
     */
    protected $rule = [
        'money' => 'require|integer',
        'rate_id' => 'require|integer',
        //露珠写入
        'table_id' => 'require|integer',
        'tableId' => 'require|integer',
        'game_type' => 'require|integer',
        'gameType' => 'require|integer',
        'result' => 'require|integer',
        'ext' => 'require|integer',
        'result_pai' => 'require|max:100',
        'xue_number' => 'require|integer',
        'xueNumber' => 'require|integer',
        'pu_number' => 'require|integer',
        'puNumber' => 'require|integer',
        'num_xue' => 'require|integer',
    ];

    /**
     * 定义错误信息
     * 格式：'字段名.规则名' =>  '错误信息'
     *
     * @var array
     */
    protected $message = [
        'money.require' => '下注金额必填',
        'money.integer' => '下注金额必须是整数',
        'rate_id.require' => '赔率ID必填',
        'rate_id.integer' => '赔率ID必须是整数',

        'result.max' => '结果不能超过100字',
        'xue_number.require' => '靴号必填',
        'xue_number.integer' => '靴号必须是整数',
        'pu_number.require' => '铺号必填',
        'pu_number.integer' => '铺号必须是整数',
        'result_pai.max' => '开牌信息不能超过100字',
        'table_id.require' => '台桌必填',
        'table_id.integer' => '台桌必须是整数',
        'game_type.require' => '游戏类型必填',
        'game_type.integer' => '游戏类型必须是整数',
        'ext.require' => '游戏类型必填',
        'ext.integer' => '游戏类型必须是整数',
    ];
    /**
     * 验证场景
     * @var \string[][]
     */
    protected $scene = [
        'bet_order' => ['money', 'rate_id','xue_number','pu_number','game_type','table_id'],//下注
        'lz_order' => ['table_id', 'game_type','result','xue_number','pu_number','result_pai'],//露珠生成

        'lz_post' => ['gameType','tableId','xueNumber','puNumber','result','ext','pai_result'],
        'lz_del'=>['table_id','xue_number','pu_number'],
        'lz_set_xue'=>['tableId','num_xue','gameType'],


    ];

}
