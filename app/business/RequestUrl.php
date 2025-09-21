<?php

namespace app\business;

class RequestUrl
{
    public static function balance():string
    {
        return '/wallet/balance';
    }
    public static function bet_result():string
    {
        return '/wallet/bet_result';
    }
    public static function bet():string
    {
        return '/wallet/bet';
    }
}