<?php

declare(strict_types=1);

namespace App\Command;

use App\Models\Config;
use App\Services\Cron as CronService;
use Exception;
use Telegram\Bot\Exceptions\TelegramSDKException;

final class CronFinance extends Command
{
    public string $description = <<<EOL
├─=: php xcat CronFinance - 处理财务报表相关定时任务
EOL;

    /**
     * @throws TelegramSDKException
     * @throws Exception
     */
    public function boot(): void
    {
        ini_set('memory_limit', '-1');

        $hour = (int) date('H');
        $minute = (int) date('i');

        $jobs = new CronService();

        // Daily finance report
        if (Config::obtain('enable_daily_finance_mail') && $hour === 0 && $minute === 0) {
            $jobs->sendDailyFinanceMail();
        }

        // Weekly finance report
        if (Config::obtain('enable_weekly_finance_mail') && $hour === 0 && $minute === 0 && date('w') === '1') {
            $jobs->sendWeeklyFinanceMail();
        }

        // Monthly finance report
        if (Config::obtain('enable_monthly_finance_mail') && $hour === 0 && $minute === 0 && date('d') === '01') {
            $jobs->sendMonthlyFinanceMail();
        }
    }
}