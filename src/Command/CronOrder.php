<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Cron as CronService;
use Exception;
use Telegram\Bot\Exceptions\TelegramSDKException;

final class CronOrder extends Command
{
    public string $description = <<<EOL
├─=: php xcat CronOrder - 处理订单相关定时任务
EOL;

    /**
     * @throws TelegramSDKException
     * @throws Exception
     */
    public function boot(): void
    {
        ini_set('memory_limit', '-1');

        $jobs = new CronService();

        // Run new shop related jobs
        $jobs->processPendingOrder();
        $jobs->processTabpOrderActivation();
        $jobs->processBandwidthOrderActivation();
        $jobs->processTimeOrderActivation();
        $jobs->processTopupOrderActivation();
    }
}