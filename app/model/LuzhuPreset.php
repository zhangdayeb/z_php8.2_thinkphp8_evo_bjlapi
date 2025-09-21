<?php

namespace app\model;


class LuzhuPreset extends LuzhuRes
{
    public $name = 'dianji_lu_zhu_preset';

    //查询是否存在预设的露珠
    public static function LuZhuPresetFind($params){

        $find = self::where($params)->where('is_status',0)->whereTime('create_time','-2 hours')->find();
        if (empty($find)) return [];
        return $find->toArray();
    }

    public static function IsStatus($id){
        self::where('id',$id)->save(['is_status'=>1]);
        return true;
    }
}