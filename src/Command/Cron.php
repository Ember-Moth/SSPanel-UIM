<?php

declare(strict_types=1);

namespace App\Command;

use App\Models\Config;
use App\Services\Cron\EmailQueueService;
use App\Services\Cron\MaintenanceService;
use App\Services\Cron\NodeService;
use App\Services\Cron\OrderService;
use App\Services\Cron\ReportService;
use App\Services\Cron\UserService;
use App\Services\Detect;
use Exception;
use Throwable;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function mktime;
use function time;
use const PHP_EOL;

final class Cron extends Command
{
    public string $description = <<<EOL
    ├─=: php xcat Cron - 站点定时任务，每五分钟
    EOL;

    /**
     * @throws TelegramSDKException
     * @throws Exception
     */
    public function boot(): void
    {
        ini_set("memory_limit", "-1");

        $hour = (int) date("H");
        $minute = (int) date("i");
        $now = time();

        $dailyJobHour = (int) Config::obtain("daily_job_hour");
        $dailyJobMinute = (int) Config::obtain("daily_job_minute");
        $lastDailyJobTime = (int) Config::obtain("last_daily_job_time");

        $enableDetectInactiveUser = (bool) Config::obtain(
            "enable_detect_inactive_user",
        );
        $removeInactiveUserLinkAndInvite = (bool) Config::obtain(
            "remove_inactive_user_link_and_invite",
        );
        $notifyDiary = (bool) Config::obtain("im_bot_group_notify_diary");
        $notifyDailyJob = (bool) Config::obtain(
            "im_bot_group_notify_daily_job",
        );

        $enableDailyFinanceMail = (bool) Config::obtain(
            "enable_daily_finance_mail",
        );
        $enableWeeklyFinanceMail = (bool) Config::obtain(
            "enable_weekly_finance_mail",
        );
        $enableMonthlyFinanceMail = (bool) Config::obtain(
            "enable_monthly_finance_mail",
        );

        $enableDetectGfw = (bool) Config::obtain("enable_detect_gfw");
        $enableDetectBan = (bool) Config::obtain("enable_detect_ban");

        $orderService = new OrderService();
        $userService = new UserService();
        $nodeService = new NodeService();
        $maintenanceService = new MaintenanceService();
        $reportService = new ReportService();
        $emailQueueService = new EmailQueueService();

        $this->runTask("processPendingOrder", static function () use (
            $orderService,
        ): void {
            $orderService->processPendingOrder();
        });
        $this->runTask("processTabpOrderActivation", static function () use (
            $orderService,
        ): void {
            $orderService->processTabpOrderActivation();
        });
        $this->runTask(
            "processBandwidthOrderActivation",
            static function () use ($orderService): void {
                $orderService->processBandwidthOrderActivation();
            },
        );
        $this->runTask("processTimeOrderActivation", static function () use (
            $orderService,
        ): void {
            $orderService->processTimeOrderActivation();
        });
        $this->runTask("processTopupOrderActivation", static function () use (
            $orderService,
        ): void {
            $orderService->processTopupOrderActivation();
        });

        $this->runTask("expirePaidUserAccount", static function () use (
            $userService,
        ): void {
            $userService->expirePaidUserAccount();
        });
        $this->runTask(
            "sendPaidUserUsageLimitNotification",
            static function () use ($userService): void {
                $userService->sendPaidUserUsageLimitNotification();
            },
        );

        $this->runTask("updateNodeIp", static function () use (
            $nodeService,
        ): void {
            $nodeService->updateNodeIp();
        });

        if ($_ENV["enable_detect_offline"]) {
            $this->runTask("detectNodeOffline", static function () use (
                $nodeService,
            ): void {
                $nodeService->detectNodeOffline();
            });
        }

        if (
            $hour === $dailyJobHour &&
            $minute === $dailyJobMinute &&
            $now - $lastDailyJobTime > 86399
        ) {
            $this->runTask("dailyJobs", static function () use (
                $maintenanceService,
                $nodeService,
                $userService,
                $reportService,
                $enableDetectInactiveUser,
                $removeInactiveUserLinkAndInvite,
                $notifyDiary,
                $notifyDailyJob,
            ): void {
                $this->runDailyJobs(
                    $maintenanceService,
                    $nodeService,
                    $userService,
                    $reportService,
                    $enableDetectInactiveUser,
                    $removeInactiveUserLinkAndInvite,
                    $notifyDiary,
                    $notifyDailyJob,
                );
            });

            $this->runTask("updateLastDailyJobTime", static function () use (
                $dailyJobHour,
                $dailyJobMinute,
            ): void {
                new Config()->where("item", "last_daily_job_time")->update([
                    "value" => mktime(
                        $dailyJobHour,
                        $dailyJobMinute,
                        0,
                        (int) date("m"),
                        (int) date("d"),
                        (int) date("Y"),
                    ),
                ]);
            });
        }

        $this->runTask("financeReports", static function () use (
            $reportService,
            $enableDailyFinanceMail,
            $enableWeeklyFinanceMail,
            $enableMonthlyFinanceMail,
            $hour,
            $minute,
        ): void {
            $this->runFinanceReports(
                $reportService,
                $enableDailyFinanceMail,
                $enableWeeklyFinanceMail,
                $enableMonthlyFinanceMail,
                $hour,
                $minute,
            );
        });

        $this->runTask("detectionJobs", static function () use (
            $enableDetectGfw,
            $enableDetectBan,
            $minute,
        ): void {
            $this->runDetectionJobs(
                $enableDetectGfw,
                $enableDetectBan,
                $minute,
            );
        });

        $this->runTask("processEmailQueue", static function () use (
            $emailQueueService,
        ): void {
            $emailQueueService->processEmailQueue();
        });
    }

    private function runTask(string $taskName, callable $task): void
    {
        echo "[Cron] Start {$taskName}" . PHP_EOL;

        try {
            $task();
            echo "[Cron] Success {$taskName}" . PHP_EOL;
        } catch (Throwable $e) {
            echo "[Cron] Failed {$taskName}: {$e->getMessage()}" . PHP_EOL;
        }
    }

    private function runDailyJobs(
        MaintenanceService $maintenanceService,
        NodeService $nodeService,
        UserService $userService,
        ReportService $reportService,
        bool $enableDetectInactiveUser,
        bool $removeInactiveUserLinkAndInvite,
        bool $notifyDiary,
        bool $notifyDailyJob,
    ): void {
        $maintenanceService->cleanDb();
        $nodeService->resetNodeBandwidth();
        $userService->resetFreeUserBandwidth();
        $reportService->sendDailyTrafficReport();

        if ($enableDetectInactiveUser) {
            $userService->detectInactiveUser();
        }

        if ($removeInactiveUserLinkAndInvite) {
            $userService->removeInactiveUserLinkAndInvite();
        }

        if ($notifyDiary) {
            $reportService->sendDiaryNotification();
        }

        $userService->resetTodayBandwidth();

        if ($notifyDailyJob) {
            $reportService->sendDailyJobNotification();
        }
    }

    private function runFinanceReports(
        ReportService $reportService,
        bool $enableDailyFinanceMail,
        bool $enableWeeklyFinanceMail,
        bool $enableMonthlyFinanceMail,
        int $hour,
        int $minute,
    ): void {
        if ($enableDailyFinanceMail && $hour === 0 && $minute === 0) {
            $reportService->sendDailyFinanceMail();
        }

        if (
            $enableWeeklyFinanceMail &&
            $hour === 0 &&
            $minute === 0 &&
            date("w") === "1"
        ) {
            $reportService->sendWeeklyFinanceMail();
        }

        if (
            $enableMonthlyFinanceMail &&
            $hour === 0 &&
            $minute === 0 &&
            date("d") === "01"
        ) {
            $reportService->sendMonthlyFinanceMail();
        }
    }

    private function runDetectionJobs(
        bool $enableDetectGfw,
        bool $enableDetectBan,
        int $minute,
    ): void {
        if ($minute !== 0) {
            return;
        }

        $detect = null;

        if ($enableDetectGfw) {
            $detect ??= new Detect();
            $detect->gfw();
        }

        if ($enableDetectBan) {
            $detect ??= new Detect();
            $detect->ban();
        }
    }
}
