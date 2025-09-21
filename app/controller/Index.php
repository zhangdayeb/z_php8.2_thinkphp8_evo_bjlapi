<?php
namespace app\controller;

use app\controller\common\LogHelper;
use app\BaseController;

class Index extends BaseController
{
    public function index()
    {
        LogHelper::debug('LogHelper调试信息', ['test' => 'data']);        
        return 'it work!' ;
    }
    
}
