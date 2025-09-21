<?php


namespace app\model;


use think\Model;

class HomeTokenModel extends Model
{
    public $name = 'common_home_token';

    //token验证
    public static function auth_token(string $token)
    {
        $find =  self::where('token', $token)->cache(5)->find();
        if (empty($find)) return [];
        return $find->toArray();
    }


    public static function update_token(string $token): bool
    {
        $ip =  isset($_SERVER['HTTP_ALI_CDN_REAL_IP']) ? $_SERVER['HTTP_ALI_CDN_REAL_IP'] :$_SERVER['REMOTE_ADDR'];
        (new HomeTokenModel())
            ->where('token', $token)
            ->update(['create_time' => date('Y-m-d H:i:s'), 'ip' => $ip]);
        return true;
    }
}