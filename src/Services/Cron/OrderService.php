<?php

declare(strict_types=1);

namespace App\Services\Cron;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Services\DB;
use App\Utils\Tools;
use DateTime;
use Exception;
use Throwable;
use function in_array;
use function json_decode;
use function time;
use const PHP_EOL;

final class OrderService
{
    public function processTabpOrderActivation(): void
    {
        $activated_orders = new Order()
            ->where("status", "activated")
            ->where("product_type", "tabp")
            ->orderBy("id")
            ->get();

        $blocked_user_ids = [];

        foreach ($activated_orders as $activated_order) {
            $content = json_decode($activated_order->product_content);

            if (
                $activated_order->update_time + $content->time * 86400 <
                time()
            ) {
                DB::beginTransaction();

                try {
                    $activated_order->status = "expired";
                    $activated_order->update_time = time();
                    $activated_order->save();

                    DB::commit();
                    echo "TABP订单 #{$activated_order->id} 已过期。\n";
                } catch (Throwable $e) {
                    DB::rollBack();
                    echo "TABP订单 #{$activated_order->id} 过期处理失败：{$e->getMessage()}\n";
                    $blocked_user_ids[$activated_order->user_id] = true;
                    continue;
                }

                continue;
            }

            $blocked_user_ids[$activated_order->user_id] = true;
        }

        $pending_activation_orders = new Order()
            ->where("status", "pending_activation")
            ->where("product_type", "tabp")
            ->orderBy("user_id")
            ->orderBy("id")
            ->get();

        $processed_user_ids = [];

        foreach ($pending_activation_orders as $order) {
            if (
                isset($blocked_user_ids[$order->user_id]) ||
                isset($processed_user_ids[$order->user_id])
            ) {
                continue;
            }

            $user = new User()->find($order->user_id);

            if ($user === null) {
                continue;
            }

            $content = json_decode($order->product_content);

            DB::beginTransaction();

            try {
                $user->u = 0;
                $user->d = 0;
                $user->transfer_today = 0;
                $user->transfer_enable = Tools::gbToB($content->bandwidth);
                $user->class = $content->class;
                $old_class_expire = new DateTime();
                $user->class_expire = $old_class_expire
                    ->modify("+" . $content->class_time . " days")
                    ->format("Y-m-d H:i:s");
                $user->node_group = $content->node_group;
                $user->node_speedlimit = $content->speed_limit;
                $user->node_iplimit = $content->ip_limit;
                $user->save();

                $order->status = "activated";
                $order->update_time = time();
                $order->save();

                DB::commit();
                $processed_user_ids[$order->user_id] = true;
                echo "TABP订单 #{$order->id} 已激活。\n";
            } catch (Throwable $e) {
                DB::rollBack();
                echo "TABP订单 #{$order->id} 激活失败：{$e->getMessage()}\n";
            }
        }

        echo Tools::toDateTime(time()) . " TABP订单激活处理完成" . PHP_EOL;
    }

    public function processBandwidthOrderActivation(): void
    {
        $orders = new Order()
            ->where("status", "pending_activation")
            ->where("product_type", "bandwidth")
            ->orderBy("user_id")
            ->orderBy("id")
            ->get();

        $processed_user_ids = [];

        foreach ($orders as $order) {
            if (isset($processed_user_ids[$order->user_id])) {
                continue;
            }

            $user = new User()->find($order->user_id);

            if ($user === null) {
                continue;
            }

            $content = json_decode($order->product_content);

            DB::beginTransaction();

            try {
                $user->transfer_enable += Tools::gbToB($content->bandwidth);
                $user->save();

                $order->status = "activated";
                $order->update_time = time();
                $order->save();

                DB::commit();
                $processed_user_ids[$order->user_id] = true;
                echo "流量包订单 #{$order->id} 已激活。\n";
            } catch (Throwable $e) {
                DB::rollBack();
                echo "流量包订单 #{$order->id} 激活失败：{$e->getMessage()}\n";
            }
        }

        echo Tools::toDateTime(time()) . " 流量包订单激活处理完成" . PHP_EOL;
    }

    /**
     * @throws Exception
     */
    public function processTimeOrderActivation(): void
    {
        $orders = new Order()
            ->where("status", "pending_activation")
            ->where("product_type", "time")
            ->orderBy("user_id")
            ->orderBy("id")
            ->get();

        $processed_user_ids = [];

        foreach ($orders as $order) {
            if (isset($processed_user_ids[$order->user_id])) {
                continue;
            }

            $user = new User()->find($order->user_id);

            if ($user === null) {
                continue;
            }

            $content = json_decode($order->product_content);

            if ($user->class !== (int) $content->class && $user->class > 0) {
                continue;
            }

            DB::beginTransaction();

            try {
                $user->class = $content->class;
                $old_class_expire = new DateTime($user->class_expire);
                $user->class_expire = $old_class_expire
                    ->modify("+" . $content->class_time . " days")
                    ->format("Y-m-d H:i:s");
                $user->node_group = $content->node_group;
                $user->node_speedlimit = $content->speed_limit;
                $user->node_iplimit = $content->ip_limit;
                $user->save();

                $order->status = "activated";
                $order->update_time = time();
                $order->save();

                DB::commit();
                $processed_user_ids[$order->user_id] = true;
                echo "时间包订单 #{$order->id} 已激活。\n";
            } catch (Throwable $e) {
                DB::rollBack();
                echo "时间包订单 #{$order->id} 激活失败：{$e->getMessage()}\n";
            }
        }

        echo Tools::toDateTime(time()) . " 时间包订单激活处理完成" . PHP_EOL;
    }

    /**
     * @throws Exception
     */
    public function processTopupOrderActivation(): void
    {
        $orders = new Order()
            ->where("status", "pending_activation")
            ->where("product_type", "topup")
            ->orderBy("id")
            ->get();

        foreach ($orders as $order) {
            $user_id = $order->user_id;
            $user = new User()->find($user_id);
            $content = json_decode($order->product_content);

            DB::beginTransaction();

            try {
                $user->money += $content->amount;
                $user->save();

                $order->status = "activated";
                $order->update_time = time();
                $order->save();

                new UserMoneyLog()->add(
                    $user_id,
                    $user->money - $content->amount,
                    $user->money,
                    $content->amount,
                    "充值订单 #{$order->id}",
                );

                DB::commit();
                echo "充值订单 #{$order->id} 已激活。\n";
            } catch (Throwable $e) {
                DB::rollBack();
                echo "充值订单 #{$order->id} 激活失败：{$e->getMessage()}\n";
            }
        }

        echo Tools::toDateTime(time()) . " 充值订单激活处理完成" . PHP_EOL;
    }

    public function processPendingOrder(): void
    {
        $pending_payment_orders = new Order()
            ->where("status", "pending_payment")
            ->get();

        $invoice_order_ids = $pending_payment_orders->pluck("id")->toArray();
        $invoices = new Invoice()
            ->whereIn("order_id", $invoice_order_ids)
            ->get()
            ->keyBy("order_id");

        foreach ($pending_payment_orders as $order) {
            $invoice = $invoices->get($order->id);

            if ($invoice === null) {
                continue;
            }

            if (
                in_array($invoice->status, [
                    "paid_gateway",
                    "paid_balance",
                    "paid_admin",
                ])
            ) {
                DB::beginTransaction();

                try {
                    $order->status = "pending_activation";
                    $order->update_time = time();
                    $order->save();

                    DB::commit();
                    echo "已标记订单 #{$order->id} 为等待激活。\n";
                } catch (Throwable $e) {
                    DB::rollBack();
                    echo "订单 #{$order->id} 标记等待激活失败：{$e->getMessage()}\n";
                }

                continue;
            }

            if (
                $order->create_time + 86400 < time() &&
                $invoice->status !== "partially_paid"
            ) {
                DB::beginTransaction();

                try {
                    $order->status = "cancelled";
                    $order->update_time = time();
                    $order->save();
                    echo "已取消超时订单 #{$order->id}。\n";

                    $invoice->status = "cancelled";
                    $invoice->update_time = time();
                    $invoice->save();
                    echo "已取消超时账单 #{$invoice->id}。\n";

                    DB::commit();
                } catch (Throwable $e) {
                    DB::rollBack();
                    echo "订单 #{$order->id} 取消失败：{$e->getMessage()}\n";
                }
            }
        }

        echo Tools::toDateTime(time()) . " 等待中订单处理完成" . PHP_EOL;
    }
}
