<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

class Worker extends Command
{
    protected function configure()
    {
        $this->setName('ws')
            ->addArgument('action', Argument::OPTIONAL, 'start|stop|restart|reload|status', 'start')
            ->addArgument('option', Argument::OPTIONAL, '-d')
            ->setDescription('Start WebSocket Worker Server');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = $input->getArgument('action') ?: 'start';
        $option = $input->getArgument('option');
        
        // 设置命令行参数
        global $argv;
        $argv = ['worker', $action];
        if ($option === '-d') {
            $argv[] = '-d';
        }
        
        $output->writeln("Starting WebSocket Worker Server...");
        
        // 初始化框架组件
        $this->initializeFramework();
        
        // 执行 Worker.php
        require __DIR__ . '/../http/Worker.php';
    }
    
    /**
     * 初始化框架组件
     */
    protected function initializeFramework()
    {
        // 1. 数据库配置已自动加载
        echo "Database config loaded (MySQL: " . env('database.hostname') . "/" . env('database.database') . ")\n";
        
        // 2. 加载公共函数文件（已包含 redis() 和 bureau_number() 函数）
        $commonFile = $this->app->getBasePath() . 'common.php';
        if (file_exists($commonFile)) {
            require_once $commonFile;
            echo "Common functions loaded (including redis() and bureau_number()).\n";
        } else {
            echo "Warning: common.php not found!\n";
        }
        
        // 3. 显示 Worker 配置信息
        if (env('zonghepan.enable')) {
            echo "综合盘已启用，游戏URL: " . env('zonghepan.game_url') . "\n";
        }
        
        echo "Framework initialization completed.\n";
        echo "----------------------------------------\n";
    }
}