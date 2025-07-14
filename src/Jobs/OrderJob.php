<?php
declare(strict_types=1);

namespace App\Jobs;

use App\Models\Order;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Utils\Tools;
use DateTime;
use Exception;

/**
 * 订单任务处理类
 * 处理 order_queue 队列中的订单状态更新任务
 */
final class OrderJob
{
    // 定义订单状态常量
    private const STATUS_PENDING_PAYMENT = 'pending_payment';
    private const STATUS_PENDING_ACTIVATION = 'pending_activation';
    private const STATUS_ACTIVATED = 'activated';

    // 定义产品类型常量
    private const TYPE_TABP = 'tabp';
    private const TYPE_BANDWIDTH = 'bandwidth';
    private const TYPE_TIME = 'time';
    private const TYPE_TOPUP = 'topup';

    /**
     * 处理订单任务
     * @param array $task 任务数据，包含 order_id
     * @throws Exception 如果订单不存在或更新失败
     */
    public static function handle(array $task): void
    {
        if (!isset($task['data']['order_id'])) {
            throw new Exception('任务数据缺少 order_id');
        }

        $order_id = $task['data']['order_id'];
        $order = (new Order)->where('id', $order_id)->first();
        if ($order === null) {
            throw new Exception("订单 #{$order_id} 不存在");
        }

        if (!in_array($order->status, [self::STATUS_PENDING_PAYMENT, self::STATUS_PENDING_ACTIVATION])) {
            return;
        }

        $user = (new User)->where('id', $order->user_id)->first();
        if ($user === null) {
            throw new Exception("用户 #{$order->user_id} 不存在");
        }

        $content = json_decode($order->product_content, true);
        if ($content === null) {
            throw new Exception("订单 #{$order_id} 的内容解析失败");
        }

        // 使用 switch 处理产品类型
        switch ($order->product_type) {
            case self::TYPE_TABP:
                self::handleTabp($user, $order, $content);
                break;
            case self::TYPE_BANDWIDTH:
                self::handleBandwidth($user, $order, $content);
                break;
            case self::TYPE_TIME:
                self::handleTime($user, $order, $content);
                break;
            case self::TYPE_TOPUP:
                self::handleTopup($user, $order, $content);
                break;
            default:
                self::updateOrderStatus($order);
                break;
        }
    }

    /**
     * 更新订单状态
     * @param Order $order 订单实例
     * @throws Exception 如果订单更新失败
     */
    private static function updateOrderStatus(Order $order): void
    {
        $order->status = self::STATUS_ACTIVATED;
        $order->update_time = time();
        if (!$order->save()) {
            throw new Exception("订单 #{$order->id} 更新失败");
        }
    }

    /**
     * 处理 TABP 订单
     */
    private static function handleTabp(User $user, Order $order, array $content): void
    {
        if (!isset($content['bandwidth'], $content['class'], $content['class_time'], $content['node_group'], $content['speed_limit'], $content['ip_limit'])) {
            throw new Exception("订单 #{$order->id} 的内容缺少必要字段");
        }

        $user->u = 0;
        $user->d = 0;
        $user->transfer_today = 0;
        $user->transfer_enable = Tools::gbToB($content['bandwidth']);
        $user->class = $content['class'];
        $user->class_expire = (new DateTime())->modify('+'.$content['class_time'].' days')->format('Y-m-d H:i:s');
        $user->node_group = $content['node_group'];
        $user->node_speedlimit = $content['speed_limit'];
        $user->node_iplimit = $content['ip_limit'];

        if (!$user->save()) {
            throw new Exception("用户 #{$user->id} 更新失败");
        }

        self::updateOrderStatus($order);
        echo "TABP订单 #{$order->id} 已激活。\n";
    }

    /**
     * 处理流量包订单
     * @throws Exception
     */
    private static function handleBandwidth(User $user, Order $order, array $content): void
    {
        if (!isset($content['bandwidth'])) {
            throw new Exception("订单 #{$order->id} 的内容缺少必要字段");
        }

        $user->transfer_enable += Tools::gbToB($content['bandwidth']);

        if (!$user->save()) {
            throw new Exception("用户 #{$user->id} 更新失败");
        }

        self::updateOrderStatus($order);
        echo "流量包订单 #{$order->id} 已激活。\n";
    }

    /**
     * 处理时间包订单
     * @throws Exception
     */
    private static function handleTime(User $user, Order $order, array $content): void
    {
        if (!isset($content['class'], $content['class_time'], $content['node_group'], $content['speed_limit'], $content['ip_limit'])) {
            throw new Exception("订单 #{$order->id} 的内容缺少必要字段");
        }

        if ($user->class !== (int) $content['class'] && $user->class > 0) {
            echo "时间包订单 #{$order->id} 跳过：用户等级 {$user->class} 不匹配订单等级 {$content['class']}。\n";
            return;
        }

        $user->class = $content['class'];
        $user->class_expire = (new DateTime($user->class_expire))->modify('+'.$content['class_time'].' days')->format('Y-m-d H:i:s');
        $user->node_group = $content['node_group'];
        $user->node_speedlimit = $content['speed_limit'];
        $user->node_iplimit = $content['ip_limit'];

        if (!$user->save()) {
            throw new Exception("用户 #{$user->id} 更新失败");
        }

        self::updateOrderStatus($order);
        echo "时间包订单 #{$order->id} 已激活。\n";
    }

    /**
     * 处理充值订单
     * @throws Exception
     */
    private static function handleTopup(User $user, Order $order, array $content): void
    {
        if (!isset($content['amount'])) {
            throw new Exception("订单 #{$order->id} 的内容缺少必要字段");
        }

        $user->money += $content['amount'];

        if (!$user->save()) {
            throw new Exception("用户 #{$user->id} 更新失败");
        }

        self::updateOrderStatus($order);
        (new UserMoneyLog())->add(
            $user->id,
            $user->money - $content['amount'],
            $user->money,
            $content['amount'],
            "充值订单 #{$order->id}"
        );
        echo "充值订单 #{$order->id} 已激活。\n";
    }
}