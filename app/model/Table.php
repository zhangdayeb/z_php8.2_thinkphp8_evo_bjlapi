<?php


namespace app\model;


use think\Model;

class Table extends Model
{
    public $name = 'dianji_table';
    protected $autoWriteTimestamp = 'start_time';
    //台桌
    protected $status = [
        1=>'正常',
        2=>'暂停'
    ];

    protected $run_status = [
        0=>'暂停',
        1=>'投注',
        2=>'开牌',
        3=>'洗牌中'
    ];

    //获取单条数据
    public static function page_one($id)
    {
        $info = Table::find($id);
        if (empty($info)) show([], config('ToConfig.http_code.error'), '台桌不存在');
        return $info;
    }

    //获取多条数据
    public static function page_repeat($where = [], $order = '')
    {
        $self = self::where($where);
        !empty($order) && $self->order($order);
        $sel = $self->select();
        return $sel;
    }
}