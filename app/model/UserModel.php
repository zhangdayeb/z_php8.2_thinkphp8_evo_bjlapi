<?php


namespace app\model;


use think\Model;


class UserModel extends Model
{
    public $name = 'common_user';
    
    public static function page_one(int $id)
    {
        $find = self::lock(true)->find($id);
        if (empty($find)) return [];
        return $find->toArray();
    }
}