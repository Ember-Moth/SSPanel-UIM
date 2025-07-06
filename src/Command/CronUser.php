<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Cron as CronService;
use Exception;
use Telegram\Bot\Exceptions\TelegramSDKException;

final class CronUser extends Command
{
    public string $description = <<<EOL
├─=: php xcat CronUser - 处理用户相关定时任务
EOL;

    /**
     * @throws TelegramSDKException
     * @throws Exception
     */
    public function boot(): void
    {
        ini_set('memory_limit', '-1');

        $jobs = new CronService();

        // Run user related jobs
        $jobs->expirePaidUserAccount();
        $jobs->sendPaidUserUsageLimitNotification();
    }
}