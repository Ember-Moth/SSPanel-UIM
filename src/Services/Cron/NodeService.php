<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\Config;
use App\Models\Node;
use App\Services\I18n;
use App\Services\Notification;
use App\Utils\Tools;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Client\ClientExceptionInterface;
use Telegram\Bot\Exceptions\TelegramSDKException;
use function date;
use function str_replace;
use function time;
use const PHP_EOL;

final class NodeService
{
    public function detectNodeOffline(): void
    {
        $nodes = new Node()->where("type", 1)->get();

        foreach ($nodes as $node) {
            if ($node->getNodeOnlineStatus() >= 0 && $node->online === 1) {
                continue;
            }

            if ($node->getNodeOnlineStatus() === -1 && $node->online === 1) {
                echo "Send Node Offline Email to admin users" . PHP_EOL;

                try {
                    Notification::notifyAdmin(
                        $_ENV["appName"] . "-系统警告",
                        "管理员你好，系统发现节点 " .
                            $node->name .
                            " 掉线了，请你及时处理。",
                    );
                } catch (GuzzleException | ClientExceptionInterface | TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }

                if (Config::obtain("im_bot_group_notify_node_offline")) {
                    try {
                        Notification::notifyUserGroup(
                            str_replace(
                                "%node_name%",
                                $node->name,
                                I18n::trans(
                                    "bot.node_offline",
                                    $_ENV["locale"],
                                ),
                            ),
                        );
                    } catch (TelegramSDKException | GuzzleException $e) {
                        echo $e->getMessage() . PHP_EOL;
                    }
                }

                $node->online = 0;
                $node->save();

                continue;
            }

            if ($node->getNodeOnlineStatus() === 1 && $node->online === 0) {
                echo "Send Node Online Email to admin user" . PHP_EOL;

                try {
                    Notification::notifyAdmin(
                        $_ENV["appName"] . "-系统提示",
                        "管理员你好，系统发现节点 " .
                            $node->name .
                            " 恢复上线了。",
                    );
                } catch (GuzzleException | ClientExceptionInterface | TelegramSDKException $e) {
                    echo $e->getMessage() . PHP_EOL;
                }

                if (Config::obtain("im_bot_group_notify_node_online")) {
                    try {
                        Notification::notifyUserGroup(
                            str_replace(
                                "%node_name%",
                                $node->name,
                                I18n::trans("bot.node_online", $_ENV["locale"]),
                            ),
                        );
                    } catch (TelegramSDKException | GuzzleException $e) {
                        echo $e->getMessage() . PHP_EOL;
                    }
                }

                $node->online = 1;
                $node->save();
            }
        }

        echo Tools::toDateTime(time()) . " 节点离线检测完成" . PHP_EOL;
    }

    public function resetNodeBandwidth(): void
    {
        new Node()
            ->where("bandwidthlimit_resetday", date("d"))
            ->update(["node_bandwidth" => 0]);

        echo Tools::toDateTime(time()) . " 重设节点流量完成" . PHP_EOL;
    }

    public function updateNodeIp(): void
    {
        $nodes = new Node()->where("type", 1)->get();

        foreach ($nodes as $node) {
            $node->updateNodeIp();
            $node->save();
        }

        echo Tools::toDateTime(time()) . " 更新节点 IP 完成" . PHP_EOL;
    }
}
