<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\Config;
use App\Models\DetectLog;
use App\Models\EmailQueue;
use App\Models\HourlyUsage;
use App\Models\OnlineLog;
use App\Models\SubscribeLog;
use App\Utils\Tools;
use function date;
use function time;
use const PHP_EOL;

final class MaintenanceService
{
    public function cleanDb(): void
    {
        new SubscribeLog()
            ->where(
                "request_time",
                "<",
                time() - 86400 * Config::obtain("subscribe_log_retention_days"),
            )
            ->delete();

        new HourlyUsage()
            ->where(
                "date",
                "<",
                date(
                    "Y-m-d",
                    time() -
                        86400 * Config::obtain("traffic_log_retention_days"),
                ),
            )
            ->delete();

        new DetectLog()->where("datetime", "<", time() - 86400 * 3)->delete();
        new EmailQueue()->where("time", "<", time() - 86400)->delete();
        new OnlineLog()->where("last_time", "<", time() - 86400)->delete();

        echo Tools::toDateTime(time()) . " 数据库清理完成" . PHP_EOL;
    }
}
