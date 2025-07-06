<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Cron as CronService;
use Exception;
use Telegram\Bot\Exceptions\TelegramSDKException;

final class CronNode extends Command
{
    public string $description = <<<EOL
├─=: php xcat CronNode - 处理节点相关定时任务
EOL;

    /**
     * @throws TelegramSDKException
     * @throws Exception
     */
    public function boot(): void
    {
        ini_set('memory_limit', '-1');

        $jobs = new CronService();

        // Run node related jobs
        $jobs->updateNodeIp();

        if ($_ENV['enable_detect_offline']) {
            $jobs->detectNodeOffline();
        }
    }
}