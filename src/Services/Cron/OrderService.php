<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Utils\Tools;
use DateTime;
use Exception;
use function in_array;
use function json_decode;
use function time;
use const PHP_EOL;

final class OrderService
{
    public function processTabpOrderActivation(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            $user_id = $user->id;

            $activated_order = (new Order())->where('user_id', $user_id)
                ->where('status', 'activated')
                ->where('product_type', 'tabp')
                ->orderBy('id')
                ->first();

            $pending_activation_orders = (new Order())->where('user_id', $user_id)
                ->where('status', 'pending_activation')
                ->where('product_type', 'tabp')
                ->orderBy('id')
                ->get();

            if ($activated_order !== null) {
                $content = json_decode($activated_order->product_content);

                if ($activated_order->update_time + $content->time * 86400 < time()) {
                    $activated_order->status = 'expired';
                    $activated_order->update_time = time();
                    $activated_order->save();
                    echo "TABP订单 #{$activated_order->id} 已过期。\n";
                    $activated_order = null;
                }
            }

            if ($activated_order === null && count($pending_activation_orders) > 0) {
                $order = $pending_activation_orders[0];
                $content = json_decode($order->product_content);

                $user->u = 0;
                $user->d = 0;
                $user->transfer_today = 0;
                $user->transfer_enable = Tools::gbToB($content->bandwidth);
                $user->class = $content->class;
                $old_class_expire = new DateTime();
                $user->class_expire = $old_class_expire
                    ->modify('+' . $content->class_time . ' days')
                    ->format('Y-m-d H:i:s');
                $user->node_group = $content->node_group;
                $user->node_speedlimit = $content->speed_limit;
                $user->node_iplimit = $content->ip_limit;
                $user->save();

                $order->status = 'activated';
                $order->update_time = time();
                $order->save();
                echo "TABP订单 #{$order->id} 已激活。\n";
            }
        }

        echo Tools::toDateTime(time()) . ' TABP订单激活处理完成' . PHP_EOL;
    }

    public function processBandwidthOrderActivation(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            $user_id = $user->id;
            $order = (new Order())->where('user_id', $user_id)
                ->where('status', 'pending_activation')
                ->where('product_type', 'bandwidth')
                ->orderBy('id')
                ->first();

            if ($order !== null) {
                $content = json_decode($order->product_content);

                $user->transfer_enable += Tools::gbToB($content->bandwidth);
                $user->save();

                $order->status = 'activated';
                $order->update_time = time();
                $order->save();
                echo "流量包订单 #{$order->id} 已激活。\n";
            }
        }

        echo Tools::toDateTime(time()) . ' 流量包订单激活处理完成' . PHP_EOL;
    }

    /**
     * @throws Exception
     */
    public function processTimeOrderActivation(): void
    {
        $users = User::all();

        foreach ($users as $user) {
            $user_id = $user->id;
            $order = (new Order())->where('user_id', $user_id)
                ->where('status', 'pending_activation')
                ->where('product_type', 'time')
                ->orderBy('id')
                ->first();

            if ($order !== null) {
                $content = json_decode($order->product_content);

                if ($user->class !== (int) $content->class && $user->class > 0) {
                    continue;
                }

                $user->class = $content->class;
                $old_class_expire = new DateTime($user->class_expire);
                $user->class_expire = $old_class_expire
                    ->modify('+' . $content->class_time . ' days')
                    ->format('Y-m-d H:i:s');
                $user->node_group = $content->node_group;
                $user->node_speedlimit = $content->speed_limit;
                $user->node_iplimit = $content->ip_limit;
                $user->save();

                $order->status = 'activated';
                $order->update_time = time();
                $order->save();
                echo "时间包订单 #{$order->id} 已激活。\n";
            }
        }

        echo Tools::toDateTime(time()) . ' 时间包订单激活处理完成' . PHP_EOL;
    }

    /**
     * @throws Exception
     */
    public function processTopupOrderActivation(): void
    {
        $orders = (new Order())->where('status', 'pending_activation')
            ->where('product_type', 'topup')
            ->orderBy('id')
            ->get();

        foreach ($orders as $order) {
            $user_id = $order->user_id;
            $user = (new User())->find($user_id);
            $content = json_decode($order->product_content);

            $user->money += $content->amount;
            $user->save();

            $order->status = 'activated';
            $order->update_time = time();
            $order->save();

            (new UserMoneyLog())->add(
                $user_id,
                $user->money - $content->amount,
                $user->money,
                $content->amount,
                "充值订单 #{$order->id}"
            );

            echo "充值订单 #{$order->id} 已激活。\n";
        }

        echo Tools::toDateTime(time()) . ' 充值订单激活处理完成' . PHP_EOL;
    }

    public function processPendingOrder(): void
    {
        $pending_payment_orders = (new Order())->where('status', 'pending_payment')->get();

        foreach ($pending_payment_orders as $order) {
            $invoice = (new Invoice())->where('order_id', $order->id)->first();

            if ($invoice === null) {
                continue;
            }

            if (in_array($invoice->status, ['paid_gateway', 'paid_balance', 'paid_admin'])) {
                $order->status = 'pending_activation';
                $order->update_time = time();
                $order->save();
                echo "已标记订单 #{$order->id} 为等待激活。\n";
                continue;
            }

            if ($order->create_time + 86400 < time() && $invoice->status !== 'partially_paid') {
                $order->status = 'cancelled';
                $order->update_time = time();
                $order->save();
                echo "已取消超时订单 #{$order->id}。\n";

                $invoice->status = 'cancelled';
                $invoice->update_time = time();
                $invoice->save();
                echo "已取消超时账单 #{$invoice->id}。\n";
            }
        }

        echo Tools::toDateTime(time()) . ' 等待中订单处理完成' . PHP_EOL;
    }
}
