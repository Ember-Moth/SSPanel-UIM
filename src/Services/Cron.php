<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ann;
use App\Models\Config;
use App\Models\DetectLog;
use App\Models\EmailQueue;
use App\Models\HourlyUsage;
use App\Models\Node;
use App\Models\OnlineLog;
use App\Models\Paylist;
use App\Models\SubscribeLog;
use App\Models\User;
use App\Services\IM\Telegram;
use App\Utils\Tools;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function array_map;
use function date;
use function str_replace;
use function strtotime;
use function time;
use const PHP_EOL;

final class Cron
{
    public static function cleanDb(): void
    {
        $time = time();
        $thresholds = [
            (new SubscribeLog())->where('request_time', '<', $time - 86400 * Config::obtain('subscribe_log_retention_days')),
            (new HourlyUsage())->where('date', '<', date('Y-m-d', $time - 86400 * Config::obtain('traffic_log_retention_days'))),
            (new DetectLog())->where('datetime', '<', $time - 86400 * 3),
            (new EmailQueue())->where('time', '<', $time - 86400),
            (new OnlineLog())->where('last_time', '<', $time - 86400),
        ];

        foreach ($thresholds as $query) {
            $query->delete();
        }

        echo Tools::toDateTime($time) . ' 数据库清理完成' . PHP_EOL;
    }

    public static function detectInactiveUser(): void
    {
        $time = time();
        $config = [
            'checkin' => Config::obtain('detect_inactive_user_checkin_days') * 86400,
            'login' => Config::obtain('detect_inactive_user_login_days') * 86400,
            'use' => Config::obtain('detect_inactive_user_use_days') * 86400,
        ];

        $baseQuery = (new User())->where('is_admin', 0);
        
        $baseQuery->clone()
            ->where('is_inactive', 0)
            ->where('last_check_in_time', '<', $time - $config['checkin'])
            ->where('last_login_time', '<', $time - $config['login'])
            ->where('last_use_time', '<', $time - $config['use'])
            ->update(['is_inactive' => 1]);

        $baseQuery->clone()
            ->where('is_inactive', 1)
            ->where('last_check_in_time', '>', $time - $config['checkin'])
            ->where('last_login_time', '>', $time - $config['login'])
            ->where('last_use_time', '>', $time - $config['use'])
            ->update(['is_inactive' => 0]);

        echo Tools::toDateTime($time) . ' 检测到 ' . $baseQuery->where('is_inactive', 1)->count() . ' 个账户处于闲置状态' . PHP_EOL;
    }

    public static function detectNodeOffline(): void
    {
        $nodes = (new Node())->where('type', 1)->get();
        $time = time();
        $telegramConfig = [
            'offline' => Config::obtain('telegram_node_offline'),
            'online' => Config::obtain('telegram_node_online'),
            'offline_text' => Config::obtain('telegram_node_offline_text'),
            'online_text' => Config::obtain('telegram_node_online_text'),
        ];

        foreach ($nodes as $node) {
            $status = $node->getNodeOnlineStatus();
            if ($status >= 0 && $node->online === 1) {
                continue;
            }

            $telegram = new Telegram();
            $appName = $_ENV['appName'];

            if ($status === -1 && $node->online === 1) {
                echo 'Send Node Offline Email to admin users' . PHP_EOL;
                try {
                    Notification::notifyAdmin("{$appName}-系统警告", "管理员你好，系统发现节点 {$node->name} 掉线了，请你及时处理。");
                    if ($telegramConfig['offline']) {
                        $telegram->send(0, str_replace('%node_name%', $node->name, $telegramConfig['offline_text']));
                    }
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }
                $node->online = 0;
                $node->save();
                continue;
            }

            if ($status === 1 && $node->online === 0) {
                echo 'Send Node Online Email to admin user' . PHP_EOL;
                try {
                    Notification::notifyAdmin("{$appName}-系统提示", "管理员你好，系统发现节点 {$node->name} 恢复上线了。");
                    if ($telegramConfig['online']) {
                        $telegram->send(0, str_replace('%node_name%', $node->name, $telegramConfig['online_text']));
                    }
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }
                $node->online = 1;
                $node->save();
            }
        }

        echo Tools::toDateTime($time) . ' 节点离线检测完成' . PHP_EOL;
    }

    public static function expirePaidUserAccount(): void
    {
        $time = time();
        $paidUsers = (new User())->where('class', '>', 0)->get();
        $appName = $_ENV['appName'];

        foreach ($paidUsers as $user) {
            if (strtotime($user->class_expire) >= $time) {
                continue;
            }

            $resetTraffic = $_ENV['class_expire_reset_traffic'];
            $text = '你好，系统发现你的账号等级已经过期了。';
            if ($resetTraffic >= 0) {
                $user->transfer_enable = Tools::toGB($resetTraffic);
                $text .= "流量已经被重置为{$resetTraffic}GB。";
            }

            try {
                Notification::notifyUser($user, "{$appName}-你的账号等级已经过期了", $text);
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            $user->u = $user->d = $user->transfer_today = $user->class = 0;
            $user->save();
        }

        echo Tools::toDateTime($time) . ' 付费用户过期检测完成' . PHP_EOL;
    }

    public static function processEmailQueue(): void
    {
        $time = time();
        $emailQueue = new EmailQueue();

        if ($emailQueue->count() === 0) {
            echo Tools::toDateTime($time) . ' 邮件队列为空' . PHP_EOL;
            return;
        }

        $startTime = $time;
        while (time() - $startTime <= 299) {
            DB::beginTransaction();
            $email = $emailQueue->lockForUpdate()->first();
            if (!$email) {
                DB::commit();
                break;
            }

            echo '发送邮件至 ' . $email->to_email . PHP_EOL;
            $emailQueue->where('id', $email->id)->delete();

            if (Tools::isEmail($email->to_email)) {
                try {
                    Mail::send($email->to_email, $email->subject, $email->template, json_decode($email->array));
                } catch (Exception|ClientExceptionInterface $e) {
                    echo $e->getMessage() . PHP_EOL;
                }
            } else {
                echo "{$email->to_email} 邮箱格式错误，已跳过" . PHP_EOL;
            }
            DB::commit();
        }

        echo Tools::toDateTime(time()) . ($time === time() ? ' 邮件队列处理超时，已跳过' : ' 邮件队列处理完成') . PHP_EOL;
    }

    public static function removeInactiveUserLinkAndInvite(): void
    {
        $time = time();
        $inactiveUsers = (new User())->where('is_inactive', 1)->get();

        foreach ($inactiveUsers as $user) {
            $user->removeLink();
            $user->removeInvite();
        }

        echo Tools::toDateTime($time) . ' Successfully removed inactive user\'s Link and Invite' . PHP_EOL;
    }

    public static function resetNodeBandwidth(): void
    {
        $time = time();
        (new Node())->where('bandwidthlimit_resetday', date('d', $time))->update(['node_bandwidth' => 0]);

        echo Tools::toDateTime($time) . ' 重设节点流量完成' . PHP_EOL;
    }

    public static function resetTodayBandwidth(): void
    {
        $time = time();
        (new User())->query()->update(['transfer_today' => 0]);

        echo Tools::toDateTime($time) . ' 重设用户每日流量完成' . PHP_EOL;
    }

    public static function resetFreeUserBandwidth(): void
    {
        $time = time();
        $freeUsers = (new User())->where('class', 0)->where('auto_reset_day', date('d', $time))->get();
        $appName = $_ENV['appName'];

        foreach ($freeUsers as $user) {
            try {
                Notification::notifyUser($user, "{$appName}-免费流量重置通知", "你好，你的免费流量已经被重置为{$user->auto_reset_bandwidth}GB。");
            } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            $user->u = $user->d = 0;
            $user->transfer_enable = $user->auto_reset_bandwidth * 1024 * 1024 * 1024;
            $user->save();
        }

        echo Tools::toDateTime($time) . ' 免费用户流量重置完成' . PHP_EOL;
    }

    public static function sendDailyFinanceMail(): void
    {
        $time = time();
        $today = strtotime('00:00:00', $time);
        $paylists = (new Paylist())->where('status', 1)
            ->whereBetween('datetime', [strtotime('-1 day', $today), $today])
            ->get();

        $textHtml = '<table border=1><tr><td>金额</td><td>用户ID</td><td>用户名</td><td>充值时间</td>';
        foreach ($paylists as $paylist) {
            $textHtml .= "<tr><td>{$paylist->total}</td><td>{$paylist->userid}</td><td>" .
                (new User())->find($paylist->userid)->user_name . "</td><td>" .
                Tools::toDateTime((int)$paylist->datetime) . "</td></tr>";
        }
        $textHtml .= "</table><br>昨日总收入笔数：" . count($paylists) . "<br>昨日总收入金额：" . $paylists->sum('total');

        echo 'Sending daily finance email to admin user' . PHP_EOL;
        try {
            Notification::notifyAdmin('财务日报', $textHtml, 'finance.tpl');
        } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime($time) . ' 成功发送财务日报' . PHP_EOL;
    }

    public static function sendWeeklyFinanceMail(): void
    {
        $time = time();
        $today = strtotime('00:00:00', $time);
        $paylists = (new Paylist())->where('status', 1)
            ->whereBetween('datetime', [strtotime('-1 week', $today), $today])
            ->get();

        $textHtml = '<br>上周总收入笔数：' . count($paylists) . '<br>上周总收入金额：' . $paylists->sum('total');
        echo 'Sending weekly finance email to admin user' . PHP_EOL;
        try {
            Notification::notifyAdmin('财务周报', $textHtml, 'finance.tpl');
        } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime($time) . ' 成功发送财务周报' . PHP_EOL;
    }

    public static function sendMonthlyFinanceMail(): void
    {
        $time = time();
        $today = strtotime('00:00:00', $time);
        $paylists = (new Paylist())->where('status', 1)
            ->whereBetween('datetime', [strtotime('-1 month', $today), $today])
            ->get();

        $textHtml = '<br>上月总收入笔数：' . count($paylists) . '<br>上月总收入金额：' . $paylists->sum('total');
        echo 'Sending monthly finance email to admin user' . PHP_EOL;
        try {
            Notification::notifyAdmin('财务月报', $textHtml, 'finance.tpl');
        } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime($time) . ' 成功发送财务月报' . PHP_EOL;
    }

    public static function sendPaidUserUsageLimitNotification(): void
    {
        $time = time();
        $paidUsers = (new User())->where('class', '>', 0)->get();
        $limitMode = $_ENV['notify_limit_mode'];
        $limitValue = $_ENV['notify_limit_value'];
        $appName = $_ENV['appName'];

        foreach ($paidUsers as $user) {
            $trafficLeft = $user->transfer_enable - $user->u - $user->d;
            $underLimit = $limitMode === 'per'
                ? ($trafficLeft / $user->transfer_enable * 100 < $limitValue)
                : ($limitMode === 'mb' && Tools::flowToMB($trafficLeft) < $limitValue);

            if ($underLimit && !$user->traffic_notified) {
                try {
                    Notification::notifyUser(
                        $user,
                        "{$appName}-你的剩余流量过低",
                        "你好，系统发现你剩余流量已经低于 {$limitValue}" . ($limitMode === 'per' ? '%' : 'MB') . " 。"
                    );
                    $user->traffic_notified = true;
                } catch (GuzzleException|ClientExceptionInterface|TelegramSDKException $e) {
                    $user->traffic_notified = false;
                    echo $e->getMessage() . PHP_EOL;
                }
                $user->save();
            } elseif (!$underLimit && $user->traffic_notified) {
                $user->traffic_notified = false;
                $user->save();
            }
        }

        echo Tools::toDateTime($time) . ' 付费用户用量限制提醒完成' . PHP_EOL;
    }

    public static function sendDailyTrafficReport(): void
    {
        $time = time();
        $users = (new User())->whereIn('daily_mail_enable', [1, 2])->get();
        $annLatest = (new Ann())->orderBy('date', 'desc')->first()?->content . '<br><br>' ?? '<br><br>';

        foreach ($users as $user) {
            $user->sendDailyNotification($annLatest);
        }

        echo Tools::toDateTime($time) . ' 成功发送每日流量报告' . PHP_EOL;
    }

    /**
     * @throws TelegramSDKException
     */
    public static function sendTelegramDailyJob(): void
    {
        $time = time();
        (new Telegram())->send(0, Config::obtain('telegram_daily_job_text'));

        echo Tools::toDateTime($time) . ' 成功发送 Telegram 每日任务提示' . PHP_EOL;
    }

    /**
     * @throws TelegramSDKException
     */
    public static function sendTelegramDiary(): void
    {
        $time = time();
        (new Telegram())->send(0, str_replace(
            ['%getTodayCheckinUser%', '%lastday_total%'],
            [Analytics::getTodayCheckinUser(), Analytics::getTodayTrafficUsage()],
            Config::obtain('telegram_diary_text')
        ));

        echo Tools::toDateTime($time) . ' 成功发送 Telegram 系统运行日志' . PHP_EOL;
    }

    public static function updateNodeIp(): void
    {
        $time = time();
        $nodes = (new Node())->where('type', 1)->get();

        foreach ($nodes as $node) {
            $node->updateNodeIp();
            $node->save();
        }

        echo Tools::toDateTime($time) . ' 更新节点 IP 完成' . PHP_EOL;
    }
}
