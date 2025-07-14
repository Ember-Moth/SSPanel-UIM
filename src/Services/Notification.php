<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Config;
use App\Models\User;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;

use function json_encode;

/**
 * 通知服务类
 * 提供向管理员、用户或用户组发送通知的功能，支持邮件和即时消息（IM）
 */
final class Notification
{
    /**
     * 向管理员发送通知
     * @param string $title 通知标题
     * @param string $msg 通知内容
     * @param string $template 邮件模板名称，默认为 'warn.tpl'
     * @throws GuzzleException
     * @throws TelegramSDKException
     * @throws ClientExceptionInterface
     */
    public static function notifyAdmin(string $title = '', string $msg = '', string $template = 'warn.tpl'): void
    {
        $admins = (new User())->where('is_admin', 1)->get();

        foreach ($admins as $admin) {
            self::sendNotification($admin, $title, $msg, $template);
        }
    }

    /**
     * 发送通知的私有方法
     * @param User $user 用户对象
     * @param string $title 通知标题
     * @param string $msg 通知内容
     * @param string $template 邮件模板名称
     * @throws GuzzleException
     * @throws TelegramSDKException
     * @throws ClientExceptionInterface
     */
    private static function sendNotification(User $user, string $title, string $msg, string $template): void
    {
        if ($user->contact_method === 1 || $user->im_type === 0) {
            // 使用通用 Queue 类添加邮件任务
            (new Queue('email_queue'))->add(
                [
                    'to_email' => $user->email,
                    'subject' => $title,
                    'template' => $template,
                    'array' => json_encode([
                        'user' => $user,
                        'title' => $title,
                        'text' => $msg,
                    ])
                ],
                'email'
            );
        } else {
            // 通过 IM 发送通知
            IM::send((int)$user->im_value, $msg, $user->im_type);
        }
    }

    /**
     * 向单个用户发送通知
     * @param User $user 用户对象
     * @param string $title 通知标题
     * @param string $msg 通知内容
     * @param string $template 邮件模板名称，默认为 'warn.tpl'
     * @throws GuzzleException
     * @throws TelegramSDKException
     * @throws ClientExceptionInterface
     */
    public static function notifyUser(
        User $user,
        string $title = '',
        string $msg = '',
        string $template = 'warn.tpl'
    ): void {
        self::sendNotification($user, $title, $msg, $template);
    }

    /**
     * 向所有用户发送通知
     * @param string $title 通知标题
     * @param string $msg 通知内容
     * @param string $template 邮件模板名称，默认为 'warn.tpl'
     * @throws GuzzleException
     * @throws TelegramSDKException|ClientExceptionInterface
     */
    public static function notifyAllUser(string $title = '', string $msg = '', string $template = 'warn.tpl'): void
    {
        $users = User::all();

        foreach ($users as $user) {
            self::sendNotification($user, $title, $msg, $template);
        }
    }

    /**
     * 向用户组发送通知（通过 Telegram、Discord 或 Slack）
     * @param string $msg 通知内容
     * @throws GuzzleException
     * @throws TelegramSDKException
     */
    public static function notifyUserGroup(string $msg = ''): void
    {
        if (Config::obtain('enable_telegram_group_notify')) {
            IM::send((int)Config::obtain('telegram_chatid'), $msg, 0);
        }

        if (Config::obtain('enable_discord_channel_notify')) {
            IM::send((int)Config::obtain('discord_channel_id'), $msg, 1);
        }

        if (Config::obtain('enable_slack_channel_notify')) {
            IM::send((int)Config::obtain('slack_channel_id'), $msg, 2);
        }
    }
}