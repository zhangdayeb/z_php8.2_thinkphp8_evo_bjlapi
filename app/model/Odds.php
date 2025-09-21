<?php


namespace app\model;


use think\Model;

class Odds extends Model
{
    public $name = 'dianji_game_peilv';

    public static function data_one($id)
    {
        return self::find($id);
    }
}
