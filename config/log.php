<?php

// +----------------------------------------------------------------------
// | 日志设置
// +----------------------------------------------------------------------
return [
    // 默认日志记录通道
    'default'      => env('log.channel', 'file'),
    
    // 日志记录级别
    'level'        => ['error', 'warning', 'info', 'debug'],
    
    // 日志类型记录的通道
    'type_channel' => [],
    
    // 关闭全局日志写入
    'close'        => env('log.close', false),
    
    // 全局日志处理
    'processor'    => null,

    // 日志通道列表
    'channels'     => [
        'file' => [
            'type'           => 'File',
            'path'           => '',
            'single'         => false,
            'apart_level'    => [],
            'max_files'      => env('log.max_files', 30),
            'json'           => false,
            'processor'      => null,
            'close'          => false,
            'format'         => '[%s][%s] %s',
            'realtime_write' => false,
        ],
        
        // 调试通道
        'debug' => [
            'type'           => 'File',
            'path'           => runtime_path() . 'log' . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR,
            'single'         => false,
            'apart_level'    => ['debug'],
            'max_files'      => 7,
            'json'           => false,
            'close'          => false, // 确保这里是 false
            'format'         => '[%s][%s] %s',
            'realtime_write' => true, // 改为 true 立即写入
        ],
        
        // 业务日志通道
        'business' => [
            'type'           => 'File',
            'path'           => runtime_path() . 'log' . DIRECTORY_SEPARATOR . 'business' . DIRECTORY_SEPARATOR,
            'single'         => false,
            'apart_level'    => ['info'],
            'max_files'      => 90,
            'json'           => false,
            'close'          => false,
            'format'         => '[%s][%s] %s',
            'realtime_write' => true, // 改为 true 立即写入
        ],
    ],
];