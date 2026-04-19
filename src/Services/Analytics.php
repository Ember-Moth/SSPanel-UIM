<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\HourlyUsage;
use App\Models\Node;
use App\Models\Paylist;
use App\Models\User;
use App\Utils\Tools;
use function array_fill;
use function date;
use function floatval;
use function is_null;
use function json_decode;
use function round;
use function strtotime;
use function time;

final class Analytics
{
    public static function getDashboardIncomeStats(): array
    {
        $today = strtotime("00:00:00");
        $income_stats = new Paylist()
            ->query()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status = 1 AND datetime BETWEEN ? AND ? THEN total ELSE 0 END), 0) AS today_income,
                COALESCE(SUM(CASE WHEN status = 1 AND datetime BETWEEN ? AND ? THEN total ELSE 0 END), 0) AS yesterday_income,
                COALESCE(SUM(CASE WHEN status = 1 AND datetime BETWEEN ? AND ? THEN total ELSE 0 END), 0) AS this_month_income,
                COALESCE(SUM(CASE WHEN status = 1 THEN total ELSE 0 END), 0) AS total_income",
                [
                    $today,
                    time(),
                    strtotime("-1 day", $today),
                    $today,
                    strtotime("first day of this month 00:00:00"),
                    time(),
                ],
            )
            ->first();

        return [
            "today_income" => round(floatval($income_stats->today_income), 2),
            "yesterday_income" => round(
                floatval($income_stats->yesterday_income),
                2,
            ),
            "this_month_income" => round(
                floatval($income_stats->this_month_income),
                2,
            ),
            "total_income" => round(floatval($income_stats->total_income), 2),
        ];
    }

    public static function getDashboardUserStats(): array
    {
        $today = strtotime("today");
        $user_stats = new User()
            ->query()
            ->selectRaw(
                "COUNT(*) AS total_user,
                COALESCE(SUM(CASE WHEN last_check_in_time > 0 THEN 1 ELSE 0 END), 0) AS checkin_user,
                COALESCE(SUM(CASE WHEN last_check_in_time > ? THEN 1 ELSE 0 END), 0) AS today_checkin_user,
                COALESCE(SUM(CASE WHEN is_inactive = 1 THEN 1 ELSE 0 END), 0) AS inactive_user,
                COALESCE(SUM(CASE WHEN is_inactive = 0 THEN 1 ELSE 0 END), 0) AS active_user",
                [$today],
            )
            ->first();

        $total_user = (int) $user_stats->total_user;
        $checkin_user = (int) $user_stats->checkin_user;
        $today_checkin_user = (int) $user_stats->today_checkin_user;

        return [
            "total_user" => $total_user,
            "checkin_user" => $checkin_user,
            "today_checkin_user" => $today_checkin_user,
            "never_checkin_user" => $total_user - $checkin_user,
            "history_checkin_user" => $checkin_user - $today_checkin_user,
            "inactive_user" => (int) $user_stats->inactive_user,
            "active_user" => (int) $user_stats->active_user,
        ];
    }

    public static function getDashboardNodeStats(): array
    {
        $alive_threshold = time() - 90;
        $node_stats = new Node()
            ->query()
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN node_heartbeat > 0 THEN 1 ELSE 0 END), 0) AS total_node,
                COALESCE(SUM(CASE WHEN node_heartbeat > ? THEN 1 ELSE 0 END), 0) AS alive_node",
                [$alive_threshold],
            )
            ->first();

        $total_node = (int) $node_stats->total_node;
        $alive_node = (int) $node_stats->alive_node;

        return [
            "total_node" => $total_node,
            "alive_node" => $alive_node,
            "offline_node" => $total_node - $alive_node,
        ];
    }

    public static function getDashboardTrafficStats(): array
    {
        $traffic_sums = self::getTrafficSums();
        $today_traffic = Tools::autoBytes($traffic_sums["transfer_today"]);
        $last_traffic = Tools::autoBytes(
            $traffic_sums["u"] +
                $traffic_sums["d"] -
                $traffic_sums["transfer_today"],
        );
        $unused_traffic = Tools::autoBytes(
            $traffic_sums["transfer_enable"] -
                $traffic_sums["u"] -
                $traffic_sums["d"],
        );

        return [
            "today_traffic_gb" => Tools::bToGB($traffic_sums["transfer_today"]),
            "last_traffic_gb" => Tools::bToGB(
                $traffic_sums["u"] +
                    $traffic_sums["d"] -
                    $traffic_sums["transfer_today"],
            ),
            "unused_traffic_gb" => Tools::bToGB(
                $traffic_sums["transfer_enable"] -
                    $traffic_sums["u"] -
                    $traffic_sums["d"],
            ),
            "today_traffic" => $today_traffic,
            "last_traffic" => $last_traffic,
            "unused_traffic" => $unused_traffic,
        ];
    }

    private static function getTrafficSums(): array
    {
        $traffic_sums = new User()
            ->query()
            ->selectRaw(
                "SUM(u) AS sum_u, SUM(d) AS sum_d, SUM(transfer_today) AS sum_transfer_today, SUM(transfer_enable) AS sum_transfer_enable",
            )
            ->first();

        return [
            "u" => (int) $traffic_sums->sum_u,
            "d" => (int) $traffic_sums->sum_d,
            "transfer_today" => (int) $traffic_sums->sum_transfer_today,
            "transfer_enable" => (int) $traffic_sums->sum_transfer_enable,
        ];
    }

    /**
     * 获取累计收入
     */
    public static function getIncome(string $req): float
    {
        $today = strtotime("00:00:00");
        $paylist = new Paylist();
        $number = match ($req) {
            "today" => $paylist
                ->where("status", 1)
                ->whereBetween("datetime", [$today, time()])
                ->sum("total"),
            "yesterday" => $paylist
                ->where("status", 1)
                ->whereBetween("datetime", [
                    strtotime("-1 day", $today),
                    $today,
                ])
                ->sum("total"),
            "this month" => $paylist
                ->where("status", 1)
                ->whereBetween("datetime", [
                    strtotime("first day of this month 00:00:00"),
                    time(),
                ])
                ->sum("total"),
            default => $paylist->where("status", 1)->sum("total"),
        };

        return is_null($number) ? 0.0 : round(floatval($number), 2);
    }

    public static function getTotalUser(): int
    {
        return new User()->count();
    }

    public static function getCheckinUser(): int
    {
        return new User()->where("last_check_in_time", ">", 0)->count();
    }

    public static function getTodayCheckinUser(): int
    {
        return new User()
            ->where("last_check_in_time", ">", strtotime("today"))
            ->count();
    }

    public static function getTrafficUsage(): string
    {
        $traffic_sums = self::getTrafficSums();

        return Tools::autoBytes($traffic_sums["u"] + $traffic_sums["d"]);
    }

    public static function getTodayTrafficUsage(): string
    {
        $traffic_sums = self::getTrafficSums();

        return Tools::autoBytes($traffic_sums["transfer_today"]);
    }

    public static function getRawTodayTrafficUsage(): int
    {
        $traffic_sums = self::getTrafficSums();

        return $traffic_sums["transfer_today"];
    }

    public static function getRawGbTodayTrafficUsage(): float
    {
        $traffic_sums = self::getTrafficSums();

        return Tools::bToGB($traffic_sums["transfer_today"]);
    }

    public static function getLastTrafficUsage(): string
    {
        $traffic_sums = self::getTrafficSums();

        return Tools::autoBytes(
            $traffic_sums["u"] +
                $traffic_sums["d"] -
                $traffic_sums["transfer_today"],
        );
    }

    public static function getRawLastTrafficUsage(): int
    {
        $traffic_sums = self::getTrafficSums();

        return $traffic_sums["u"] +
            $traffic_sums["d"] -
            $traffic_sums["transfer_today"];
    }

    public static function getRawGbLastTrafficUsage(): float
    {
        $traffic_sums = self::getTrafficSums();

        return Tools::bToGB(
            $traffic_sums["u"] +
                $traffic_sums["d"] -
                $traffic_sums["transfer_today"],
        );
    }

    public static function getUnusedTrafficUsage(): string
    {
        $traffic_sums = self::getTrafficSums();

        return Tools::autoBytes(
            $traffic_sums["transfer_enable"] -
                $traffic_sums["u"] -
                $traffic_sums["d"],
        );
    }

    public static function getRawUnusedTrafficUsage(): int
    {
        $traffic_sums = self::getTrafficSums();

        return $traffic_sums["transfer_enable"] -
            $traffic_sums["u"] -
            $traffic_sums["d"];
    }

    public static function getRawGbUnusedTrafficUsage(): float
    {
        $traffic_sums = self::getTrafficSums();

        return Tools::bToGB(
            $traffic_sums["transfer_enable"] -
                $traffic_sums["u"] -
                $traffic_sums["d"],
        );
    }

    public static function getTotalTraffic(): string
    {
        $traffic_sums = self::getTrafficSums();

        return Tools::autoBytes($traffic_sums["transfer_enable"]);
    }

    public static function getRawTotalTraffic(): int
    {
        $traffic_sums = self::getTrafficSums();

        return $traffic_sums["transfer_enable"];
    }

    public static function getRawGbTotalTraffic(): float
    {
        $traffic_sums = self::getTrafficSums();

        return Tools::bToGB($traffic_sums["transfer_enable"]);
    }

    public static function getTotalNode(): int
    {
        return new Node()->where("node_heartbeat", ">", 0)->count();
    }

    public static function getAliveNode(): int
    {
        return new Node()->where("node_heartbeat", ">", time() - 90)->count();
    }

    public static function getInactiveUser(): int
    {
        return new User()->where("is_inactive", 1)->count();
    }

    public static function getActiveUser(): int
    {
        return new User()->where("is_inactive", 0)->count();
    }

    public static function getUserHourlyUsage(int $user_id, string $date): array
    {
        $hourly_usage = new HourlyUsage()
            ->where("user_id", $user_id)
            ->where("date", $date)
            ->first();

        return $hourly_usage
            ? json_decode($hourly_usage->usage, true)
            : array_fill(0, 24, 0);
    }

    public static function getUserTodayHourlyUsage(int $user_id): array
    {
        $date = date("Y-m-d");

        return self::getUserHourlyUsage($user_id, $date);
    }
}
