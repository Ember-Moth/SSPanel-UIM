<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\Ann;
use App\Models\Paylist;
use App\Models\User;
use App\Services\Analytics;
use App\Services\I18n;
use App\Services\Notification;
use App\Utils\Tools;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function count;
use function date;
use function json_decode;
use function str_replace;
use function strtotime;
use function time;
use const PHP_EOL;

final class ReportService
{
    public function sendDailyFinanceMail(): void
    {
        $today = strtotime('00:00:00');
        $paylists = (new Paylist())->where('status', 1)
            ->whereBetween('datetime', [strtotime('-1 day', $today), $today])
            ->get();

        if (count($paylists) > 0) {
            $text_html = '<table><tr><td>金额</td><td>用户ID</td><td>用户名</td><td>充值时间</td></tr>';

            foreach ($paylists as $paylist) {
                $text_html .= '<tr>';
                $text_html .= '<td>' . $paylist->total . '</td>';
                $text_html .= '<td>' . $paylist->userid . '</td>';
                $text_html .= '<td>' . (new User())->find($paylist->userid)->user_name . '</td>';
                $text_html .= '<td>' . Tools::toDateTime((int) $paylist->datetime) . '</td>';
                $text_html .= '</tr>';
            }

            $text_html .= '</table>';
            $text_html .= '<br>昨日总收入笔数：' . count($paylists) . '<br>昨日总收入金额：' . $paylists->sum('total');

            $text_html = str_replace([
                '<table>',
                '<tr>',
                '<td>',
            ], [
                '<table style="width: 100%;border: 1px solid black;border-collapse: collapse;">',
                '<tr style="border: 1px solid black;padding: 5px;">',
                '<td style="border: 1px solid black;padding: 5px;">',
            ], $text_html);

            echo 'Sending daily finance email to admin user' . PHP_EOL;

            try {
                Notification::notifyAdmin(
                    '财务日报',
                    $text_html,
                    'finance.tpl'
                );
            } catch (GuzzleException | ClientExceptionInterface | TelegramSDKException $e) {
                echo $e->getMessage() . PHP_EOL;
            }

            echo Tools::toDateTime(time()) . ' Successfully sent daily finance email' . PHP_EOL;
        } else {
            echo 'No paylist found' . PHP_EOL;
        }
    }

    public function sendWeeklyFinanceMail(): void
    {
        $today = strtotime('00:00:00');
        $paylists = (new Paylist())->where('status', 1)
            ->whereBetween('datetime', [strtotime('-1 week', $today), $today])
            ->get();

        $text_html = '<br>上周总收入笔数：' . count($paylists) . '<br>上周总收入金额：' . $paylists->sum('total');
        echo 'Sending weekly finance email to admin user' . PHP_EOL;

        try {
            Notification::notifyAdmin(
                '财务周报',
                $text_html,
                'finance.tpl'
            );
        } catch (GuzzleException | ClientExceptionInterface | TelegramSDKException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 成功发送财务周报' . PHP_EOL;
    }

    public function sendMonthlyFinanceMail(): void
    {
        $today = strtotime('00:00:00');
        $paylists = (new Paylist())->where('status', 1)
            ->whereBetween('datetime', [strtotime('-1 month', $today), $today])
            ->get();

        $text_html = '<br>上月总收入笔数：' . count($paylists) . '<br>上月总收入金额：' . $paylists->sum('total');
        echo 'Sending monthly finance email to admin user' . PHP_EOL;

        try {
            Notification::notifyAdmin(
                '财务月报',
                $text_html,
                'finance.tpl'
            );
        } catch (GuzzleException | ClientExceptionInterface | TelegramSDKException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' 成功发送财务月报' . PHP_EOL;
    }

    public function sendDailyTrafficReport(): void
    {
        $users = (new User())->whereIn('daily_mail_enable', [1, 2])->get();
        $ann_latest_raw = (new Ann())->where('status', '>', 0)
            ->orderBy('status', 'desc')
            ->orderBy('sort')
            ->orderBy('date', 'desc')
            ->first();

        if ($ann_latest_raw === null) {
            $ann_latest = '<br><br>';
        } else {
            $ann_latest = $ann_latest_raw->content . '<br><br>';
        }

        foreach ($users as $user) {
            $user->sendDailyNotification($ann_latest);
        }

        echo Tools::toDateTime(time()) . ' Successfully sent daily traffic report' . PHP_EOL;
    }

    public function sendDailyJobNotification(): void
    {
        try {
            Notification::notifyUserGroup(
                I18n::trans('bot.daily_job_run', $_ENV['locale'])
            );
        } catch (TelegramSDKException | GuzzleException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' Successfully sent daily job notification' . PHP_EOL;
    }

    public function sendDiaryNotification(): void
    {
        try {
            Notification::notifyUserGroup(
                str_replace(
                    [
                        '%checkin_user%',
                        '%lastday_total%',
                    ],
                    [
                        Analytics::getTodayCheckinUser(),
                        Analytics::getTodayTrafficUsage(),
                    ],
                    I18n::trans('bot.diary', $_ENV['locale'])
                )
            );
        } catch (TelegramSDKException | GuzzleException $e) {
            echo $e->getMessage() . PHP_EOL;
        }

        echo Tools::toDateTime(time()) . ' Successfully sent diary notification' . PHP_EOL;
    }
}
