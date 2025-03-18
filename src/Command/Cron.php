<?php

declare(strict_types=1);

namespace App\Command;

use App\Models\Config;
use App\Services\Cron as CronService;
use App\Services\Detect;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function date;
use function mktime;
use function time;

final class Cron extends Command
{
    public string $description = <<<EOL
├─=: php xcat Cron - 站点定时任务，每五分钟
EOL;

    /**
     * @throws TelegramSDKException
     * @throws \Exception
     */
    public function boot(): void
    {
        ini_set('memory_limit', '-1');

        $time = time();
        $hour = (int) date('H', $time);
        $minute = (int) date('i', $time);
        $jobs = new CronService();

        // Run user related jobs
        $jobs->expirePaidUserAccount();
        $jobs->sendPaidUserUsageLimitNotification();

        // Run node related jobs
        $jobs->updateNodeIp();

        if ($_ENV['enable_detect_offline']) {
            $jobs->detectNodeOffline();
        }

        // Run daily job
        $dailyHour = Config::obtain('daily_job_hour');
        $dailyMinute = Config::obtain('daily_job_minute');
        if ($hour === $dailyHour &&
            $minute === $dailyMinute &&
            $time - Config::obtain('last_daily_job_time') > 86399
        ) {
            $jobs->cleanDb();
            $jobs->resetNodeBandwidth();
            $jobs->resetFreeUserBandwidth();
            $jobs->sendDailyTrafficReport();

            if (Config::obtain('enable_detect_inactive_user')) {
                $jobs->detectInactiveUser();
            }

            if (Config::obtain('remove_inactive_user_link_and_invite')) {
                $jobs->removeInactiveUserLinkAndInvite();
            }

            if (Config::obtain('im_bot_group_notify_diary')) {
                $jobs->sendTelegramDiary(); // Assuming this is the intended method
            }

            $jobs->resetTodayBandwidth();

            if (Config::obtain('im_bot_group_notify_daily_job')) {
                $jobs->sendTelegramDailyJob(); // Assuming this is the intended method
            }

            (new Config())->where('item', 'last_daily_job_time')->update([
                'value' => mktime($dailyHour, $dailyMinute, 0, (int) date('m', $time), (int) date('d', $time), (int) date('Y', $time)),
            ]);
        }

        // Daily finance report
        if (Config::obtain('enable_daily_finance_mail') && $hour === 0 && $minute === 0) {
            $jobs->sendDailyFinanceMail();
        }

        // Weekly finance report
        if (Config::obtain('enable_weekly_finance_mail') && $hour === 0 && $minute === 0 && date('w', $time) === '1') {
            $jobs->sendWeeklyFinanceMail();
        }

        // Monthly finance report
        if (Config::obtain('enable_monthly_finance_mail') && $hour === 0 && $minute === 0 && date('d', $time) === '01') {
            $jobs->sendMonthlyFinanceMail();
        }

        // Detect GFW
        if (Config::obtain('enable_detect_gfw') && $minute === 0) {
            (new Detect())->gfw();
        }

        // Detect ban
        if (Config::obtain('enable_detect_ban') && $minute === 0) {
            (new Detect())->ban();
        }

        // Run email queue
        $jobs->processEmailQueue();
    }
}
