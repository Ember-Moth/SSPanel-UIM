<?php

declare(strict_types=1);

namespace App\Command;

use App\Models\Config;
use App\Services\Cron as CronService;
use Exception;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function mktime;
use function time;

final class CronDaily extends Command
{
    public string $description = <<<EOL
├─=: php xcat CronDaily - 处理每日定时任务
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

        // Run daily job
        if ($hour === Config::obtain('daily_job_hour') &&
            $minute === Config::obtain('daily_job_minute') &&
            time() - Config::obtain('last_daily_job_time') > 86399
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
                $jobs->sendDiaryNotification();
            }

            $jobs->resetTodayBandwidth();

            if (Config::obtain('im_bot_group_notify_daily_job')) {
                $jobs->sendDailyJobNotification();
            }

            (new Config())->where('item', 'last_daily_job_time')->update([
                'value' => mktime(
                    Config::obtain('daily_job_hour'),
                    Config::obtain('daily_job_minute'),
                    0,
                    (int) date('m'),
                    (int) date('d'),
                    (int) date('Y')
                ),
            ]);
        }
    }
}