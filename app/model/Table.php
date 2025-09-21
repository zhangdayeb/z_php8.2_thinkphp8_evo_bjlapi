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

    //台桌开局倒计时 $info台桌信息
    public static function table_opening_count_down($info)
    {
        if (empty($info)) show([],config('ToConfig.http_code.error'), '台桌不存在');
        $end = time() - ($info->getData('start_time') + $info['countdown_time']);
        if (is_object($info)){
            $info = $info->toArray();
            $info['end_time'] = 0;
            if ($end <= 0) {
                $info['end_time'] = abs($end);
            }
        }else{
            $info['end_time'] = 0;
            if ($end <= 0) {
                $info['end_time'] = abs($end);
            }
        }
        return self::table_open_video_url($info);
    }

    /**
     * 台桌视频地址
     * @param $info /台桌信息
     * @return mixed
     */
    public static function table_open_video_url($info)
    {
        $info['video_near'] = $info['video_near'] . $info['id'];
        $info['video_far'] = $info['video_far'] . $info['id'];
        return $info;
    }

    public static function table_opening_count_down_time($info)
    {
        if (empty($info)) show([],config('ToConfig.http_code.error'), '台桌不存在');
        $end = time() - ($info['start_time'] + $info['countdown_time']);
        if (is_object($info)){
            $info->end_time = 0;
            if ($end <= 0) {
                $info->end_time = abs($end);
            }
        }else{
            $info['end_time'] = 0;
            if ($end <= 0) {
                $info['end_time'] = abs($end);
            }
        }
        return $info;
    }
}