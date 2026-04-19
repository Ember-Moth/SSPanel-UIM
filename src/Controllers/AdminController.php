<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Analytics;
use Exception;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

final class AdminController extends BaseController
{
    /**
     * 后台首页
     *
     * @throws Exception
     */
    public function index(
        ServerRequest $request,
        Response $response,
        array $args,
    ): ResponseInterface {
        $income_stats = Analytics::getDashboardIncomeStats();
        $user_stats = Analytics::getDashboardUserStats();
        $node_stats = Analytics::getDashboardNodeStats();
        $traffic_stats = Analytics::getDashboardTrafficStats();

        return $response->write(
            $this->view()
                ->assign("today_income", $income_stats["today_income"])
                ->assign("yesterday_income", $income_stats["yesterday_income"])
                ->assign(
                    "this_month_income",
                    $income_stats["this_month_income"],
                )
                ->assign("total_income", $income_stats["total_income"])
                ->assign("total_user", $user_stats["total_user"])
                ->assign("checkin_user", $user_stats["checkin_user"])
                ->assign(
                    "today_checkin_user",
                    $user_stats["today_checkin_user"],
                )
                ->assign(
                    "never_checkin_user",
                    $user_stats["never_checkin_user"],
                )
                ->assign(
                    "history_checkin_user",
                    $user_stats["history_checkin_user"],
                )
                ->assign("inactive_user", $user_stats["inactive_user"])
                ->assign("active_user", $user_stats["active_user"])
                ->assign("total_node", $node_stats["total_node"])
                ->assign("alive_node", $node_stats["alive_node"])
                ->assign("offline_node", $node_stats["offline_node"])
                ->assign("today_traffic_gb", $traffic_stats["today_traffic_gb"])
                ->assign("last_traffic_gb", $traffic_stats["last_traffic_gb"])
                ->assign(
                    "unused_traffic_gb",
                    $traffic_stats["unused_traffic_gb"],
                )
                ->assign("today_traffic", $traffic_stats["today_traffic"])
                ->assign("last_traffic", $traffic_stats["last_traffic"])
                ->assign("unused_traffic", $traffic_stats["unused_traffic"])
                ->fetch("admin/index.tpl"),
        );
    }
}
