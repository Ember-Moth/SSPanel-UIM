<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\Config;
use App\Models\User;
use App\Utils\Tools;
use App\Services\Notification;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function date;
use function strtotime;
use function time;
use const PHP_EOL;

final class UserService
{
    public function detectInactiveUser(): void
    {
        $checkin_days = Config::obtain("detect_inactive_user_checkin_days");
        $login_days = Config::obtain("detect_inactive_user_login_days");
        $use_days = Config::obtain("detect_inactive_user_use_days");

        new User()
            ->where("is_admin", 0)
            ->where("is_inactive", 0)
            ->where("last_check_in_time", "<", time() - 86400 * $checkin_days)
            ->where("last_login_time", "<", time() - 86400 * $login_days)
            ->where("last_use_time", "<", time() - 86400 * $use_days)
            ->update(["is_inactive" => 1]);

        new User()
            ->where("is_admin", 0)
            ->where("is_inactive", 1)
            ->where("last_check_in_time", ">", time() - 86400 * $checkin_days)
            ->where("last_login_time", ">", time() - 86400 * $login_days)
            ->where("last_use_time", ">", time() - 86400 * $use_days)
            ->update(["is_inactive" => 0]);

        echo Tools::toDateTime(time()) .
            " 检测到 " .
            new User()->where("is_inactive", 1)->count() .
            " 个账户处于闲置状态" .
            PHP_EOL;
    }

    public function expirePaidUserAccount(): void
    {
        $paidUsers = new User()->where("class", ">", 0)->get();

        foreach ($paidUsers as $user) {
            if (strtotime($user->class_expire) < time()) {
                $text = "你好，系统发现你的账号等级已经过期了。";
                $reset_traffic = $_ENV["class_expire_reset_traffic"];

                if ($reset_traffic >= 0) {
                    $user->transfer_enable = Tools::gbToB($reset_traffic);
                    $text .= "流量已经被重置为" . $reset_traffic . "GB。";
                }

                try {
                    Notification::notifyUser(
                        $user,
                        $_ENV["appName"] . "-你的账号等级已经过期了",
                        $text,
                    );
                } catch (GuzzleException | ClientExceptionInterface | TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }

                $user->u = 0;
                $user->d = 0;
                $user->transfer_today = 0;
                $user->class = 0;
                $user->save();
            }
        }

        echo Tools::toDateTime(time()) . " 付费用户过期检测完成" . PHP_EOL;
    }

    public function removeInactiveUserLinkAndInvite(): void
    {
        $inactive_users = new User()->where("is_inactive", 1)->get();

        foreach ($inactive_users as $user) {
            $user->removeLink();
            $user->removeInvite();
        }

        echo Tools::toDateTime(time()) .
            ' Successfully removed inactive user\'s Link and Invite' .
            PHP_EOL;
    }

    public function resetTodayBandwidth(): void
    {
        new User()->query()->update(["transfer_today" => 0]);

        echo Tools::toDateTime(time()) . " 重设用户每日流量完成" . PHP_EOL;
    }

    public function resetFreeUserBandwidth(): void
    {
        $freeUsers = new User()
            ->where("class", 0)
            ->where("auto_reset_day", date("d"))
            ->get();

        foreach ($freeUsers as $user) {
            try {
                Notification::notifyUser(
                    $user,
                    $_ENV["appName"] . "-免费流量重置通知",
                    "你好，你的免费流量已经被重置为" .
                        $user->auto_reset_bandwidth .
                        "GB。",
                );
            } catch (GuzzleException | ClientExceptionInterface | TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            $user->u = 0;
            $user->d = 0;
            $user->transfer_enable =
                $user->auto_reset_bandwidth * 1024 * 1024 * 1024;
            $user->save();
        }

        echo Tools::toDateTime(time()) . " 免费用户流量重置完成" . PHP_EOL;
    }

    public function sendPaidUserUsageLimitNotification(): void
    {
        $paidUsers = new User()->where("class", ">", 0)->get();

        foreach ($paidUsers as $user) {
            $user_traffic_left = $user->transfer_enable - $user->u - $user->d;
            $under_limit = false;
            $unit_text = "";

            if (
                $_ENV["notify_limit_mode"] === "per" &&
                ($user_traffic_left / $user->transfer_enable) * 100 <
                    $_ENV["notify_limit_value"]
            ) {
                $under_limit = true;
                $unit_text = "%";
            } elseif (
                $_ENV["notify_limit_mode"] === "mb" &&
                Tools::bToMB($user_traffic_left) < $_ENV["notify_limit_value"]
            ) {
                $under_limit = true;
                $unit_text = "MB";
            }

            if ($under_limit && !$user->traffic_notified) {
                try {
                    Notification::notifyUser(
                        $user,
                        $_ENV["appName"] . "-你的剩余流量过低",
                        "你好，系统发现你剩余流量已经低于 " .
                            $_ENV["notify_limit_value"] .
                            $unit_text .
                            " 。",
                    );

                    $user->traffic_notified = true;
                } catch (GuzzleException | ClientExceptionInterface | TelegramSDKException $e) {
                    $user->traffic_notified = false;
                    echo $e->getMessage() . PHP_EOL;
                }

                $user->save();
            } elseif (!$under_limit && $user->traffic_notified) {
                $user->traffic_notified = false;
                $user->save();
            }
        }

        echo Tools::toDateTime(time()) . " 付费用户用量限制提醒完成" . PHP_EOL;
    }
}
