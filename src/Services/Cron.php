<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Cron\EmailQueueService;
use App\Services\Cron\MaintenanceService;
use App\Services\Cron\NodeService;
use App\Services\Cron\OrderService;
use App\Services\Cron\ReportService;
use App\Services\Cron\UserService;
use Exception;

final class Cron
{
    private MaintenanceService $maintenanceService;
    private UserService $userService;
    private NodeService $nodeService;
    private OrderService $orderService;
    private ReportService $reportService;
    private EmailQueueService $emailQueueService;

    public function __construct()
    {
        $this->maintenanceService = new MaintenanceService();
        $this->userService = new UserService();
        $this->nodeService = new NodeService();
        $this->orderService = new OrderService();
        $this->reportService = new ReportService();
        $this->emailQueueService = new EmailQueueService();
    }

    public function cleanDb(): void
    {
        $this->maintenanceService->cleanDb();
    }

    public function detectInactiveUser(): void
    {
        $this->userService->detectInactiveUser();
    }

    public function detectNodeOffline(): void
    {
        $this->nodeService->detectNodeOffline();
    }

    public function expirePaidUserAccount(): void
    {
        $this->userService->expirePaidUserAccount();
    }

    public function processEmailQueue(): void
    {
        $this->emailQueueService->processEmailQueue();
    }

    public function processTabpOrderActivation(): void
    {
        $this->orderService->processTabpOrderActivation();
    }

    public function processBandwidthOrderActivation(): void
    {
        $this->orderService->processBandwidthOrderActivation();
    }

    /**
     * @throws Exception
     */
    public function processTimeOrderActivation(): void
    {
        $this->orderService->processTimeOrderActivation();
    }

    /**
     * @throws Exception
     */
    public function processTopupOrderActivation(): void
    {
        $this->orderService->processTopupOrderActivation();
    }

    public function processPendingOrder(): void
    {
        $this->orderService->processPendingOrder();
    }

    public function removeInactiveUserLinkAndInvite(): void
    {
        $this->userService->removeInactiveUserLinkAndInvite();
    }

    public function resetNodeBandwidth(): void
    {
        $this->nodeService->resetNodeBandwidth();
    }

    public function resetTodayBandwidth(): void
    {
        $this->userService->resetTodayBandwidth();
    }

    public function resetFreeUserBandwidth(): void
    {
        $this->userService->resetFreeUserBandwidth();
    }

    public function sendDailyFinanceMail(): void
    {
        $this->reportService->sendDailyFinanceMail();
    }

    public function sendWeeklyFinanceMail(): void
    {
        $this->reportService->sendWeeklyFinanceMail();
    }

    public function sendMonthlyFinanceMail(): void
    {
        $this->reportService->sendMonthlyFinanceMail();
    }

    public function sendPaidUserUsageLimitNotification(): void
    {
        $this->userService->sendPaidUserUsageLimitNotification();
    }

    public function sendDailyTrafficReport(): void
    {
        $this->reportService->sendDailyTrafficReport();
    }

    public function sendDailyJobNotification(): void
    {
        $this->reportService->sendDailyJobNotification();
    }

    public function sendDiaryNotification(): void
    {
        $this->reportService->sendDiaryNotification();
    }

    public function updateNodeIp(): void
    {
        $this->nodeService->updateNodeIp();
    }
}
