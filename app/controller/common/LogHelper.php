<?php
namespace app\controller\common;

use think\facade\Log;

class LogHelper 
{
    // 调试日志 - 只在开发环境记录
    public static function debug($message, $data = [])
    {
        if (env('APP_DEBUG') || env('DEBUG_LOG')) {
            Log::channel('debug')->debug($message . (!empty($data) ? ' 数据：' . json_encode($data, JSON_UNESCAPED_UNICODE) : ''));
            // 写入后立即设置权限为755
            self::setLogFilePermissions('debug');
        }
    }
    
    // 业务日志 - 重要业务流程
    public static function business($message, $data = [])
    {
        Log::channel('business')->info($message . (!empty($data) ? ' 数据：' . json_encode($data, JSON_UNESCAPED_UNICODE) : ''));
        // 写入后立即设置权限为755
        self::setLogFilePermissions('business');
    }
    
    // 错误日志 - 始终记录
    public static function error($message, $exception = null)
    {
        $errorMsg = $message;
        if ($exception instanceof \Exception) {
            $errorMsg .= ' 错误：' . $exception->getMessage();
            if (env('APP_DEBUG')) {
                $errorMsg .= ' 堆栈：' . $exception->getTraceAsString();
            }
        }
        Log::error($errorMsg);
        // 写入后立即设置权限为755
        self::setLogFilePermissions('file');
    }
    
    // 警告日志 - 始终记录
    public static function warning($message, $data = [])
    {
        Log::warning($message . (!empty($data) ? ' 数据：' . json_encode($data, JSON_UNESCAPED_UNICODE) : ''));
        // 写入后立即设置权限为755
        self::setLogFilePermissions('file');
    }
    
    // 条件信息日志 - 可控制
    public static function info($message, $data = [], $force = false)
    {
        if ($force || env('APP_DEBUG') || in_array('info', explode(',', env('LOG_LEVEL', 'error,warning')))) {
            Log::info($message . (!empty($data) ? ' 数据：' . json_encode($data, JSON_UNESCAPED_UNICODE) : ''));
            // 写入后立即设置权限为755
            self::setLogFilePermissions('file');
        }
    }
    
    /**
     * 设置日志文件权限为755
     * @param string $channel 日志通道名
     */
    private static function setLogFilePermissions($channel)
    {
        try {
            // 根据通道确定日志路径
            switch ($channel) {
                case 'debug':
                    $logPath = runtime_path() . 'log' . DIRECTORY_SEPARATOR . 'debug' . DIRECTORY_SEPARATOR;
                    break;
                case 'business':
                    $logPath = runtime_path() . 'log' . DIRECTORY_SEPARATOR . 'business' . DIRECTORY_SEPARATOR;
                    break;
                default:
                    $logPath = runtime_path() . 'log' . DIRECTORY_SEPARATOR;
                    break;
            }
            
            if (is_dir($logPath)) {
                // 获取今天的日志文件
                $today = date('Ymd');
                $logFiles = [
                    $logPath . $today . '.log',
                    $logPath . 'debug_' . $today . '.log',
                    $logPath . 'info_' . $today . '.log',
                    $logPath . 'error_' . $today . '.log',
                    $logPath . 'warning_' . $today . '.log'
                ];
                
                foreach ($logFiles as $file) {
                    if (file_exists($file)) {
                        chmod($file, 0755); // 设置为755权限
                    }
                }
                
                // 也可以批量处理所有.log文件
                $allLogFiles = glob($logPath . '*.log');
                foreach ($allLogFiles as $file) {
                    if (file_exists($file)) {
                        chmod($file, 0755); // 设置为755权限
                    }
                }
            }
        } catch (\Exception $e) {
            // 权限设置失败时不影响日志写入，静默处理
            // 可以选择记录到系统日志或忽略
        }
    }
}