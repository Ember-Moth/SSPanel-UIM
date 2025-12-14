<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Utils\Tools;
use DateTime;
use Exception;
use function json_decode;
use function time;

/**
 * 订单激活服务
 * 支持支付成功后立即激活订单
 */
final class OrderActivation
{
  /**
   * 根据账单ID激活关联的订单
   * 在支付成功回调时调用
   */
  public static function activateByInvoiceId(int $invoice_id): bool
  {
    $invoice = (new Invoice())->find($invoice_id);

    if ($invoice === null) {
      return false;
    }

    $order = (new Order())->find($invoice->order_id);

    if ($order === null || $order->status !== 'pending_activation') {
      return false;
    }

    return self::activateOrder($order);
  }

  /**
   * 激活单个订单
   */
  public static function activateOrder(Order $order): bool
  {
    return match ($order->product_type) {
      'tabp' => self::activateTabpOrder($order),
      'bandwidth' => self::activateBandwidthOrder($order),
      'time' => self::activateTimeOrder($order),
      'topup' => self::activateTopupOrder($order),
      default => false,
    };
  }

  /**
   * 激活TABP（时间流量包）订单
   */
  private static function activateTabpOrder(Order $order): bool
  {
    $user = (new User())->find($order->user_id);

    if ($user === null) {
      return false;
    }

    // 检查用户是否有已激活的TABP订单
    $has_activated = (new Order())->where('user_id', $user->id)
      ->where('status', 'activated')
      ->where('product_type', 'tabp')
      ->exists();

    if ($has_activated) {
      // 用户已有激活的TABP订单，不立即激活，等待定时任务处理
      return false;
    }

    $content = json_decode($order->product_content);

    if ($content === null) {
      return false;
    }

    try {
      DB::beginTransaction();

      // 激活TABP
      $user->u = 0;
      $user->d = 0;
      $user->transfer_today = 0;
      $user->transfer_enable = Tools::gbToB($content->bandwidth);
      $user->class = $content->class;
      $old_class_expire = new DateTime();
      $user->class_expire = $old_class_expire
        ->modify('+' . $content->class_time . ' days')->format('Y-m-d H:i:s');
      $user->node_group = $content->node_group;
      $user->node_speedlimit = $content->speed_limit;
      $user->node_iplimit = $content->ip_limit;
      $user->save();

      $order->status = 'activated';
      $order->update_time = time();
      $order->save();

      DB::commit();
      return true;
    } catch (Exception $e) {
      DB::rollBack();
      return false;
    }
  }

  /**
   * 激活流量包订单
   */
  private static function activateBandwidthOrder(Order $order): bool
  {
    $user = (new User())->find($order->user_id);

    if ($user === null) {
      return false;
    }

    $content = json_decode($order->product_content);

    if ($content === null || ! isset($content->bandwidth)) {
      return false;
    }

    try {
      DB::beginTransaction();

      // 激活流量包 - 叠加流量
      $user->transfer_enable += Tools::gbToB($content->bandwidth);
      $user->save();

      $order->status = 'activated';
      $order->update_time = time();
      $order->save();

      DB::commit();
      return true;
    } catch (Exception $e) {
      DB::rollBack();
      return false;
    }
  }

  /**
   * 激活时间包订单
   */
  private static function activateTimeOrder(Order $order): bool
  {
    $user = (new User())->find($order->user_id);

    if ($user === null) {
      return false;
    }

    $content = json_decode($order->product_content);

    if ($content === null) {
      return false;
    }

    // 跳过当前账户等级不等于时间包等级的非免费用户订单
    if ($user->class !== (int) $content->class && $user->class > 0) {
      // 等级不匹配，不立即激活，等待定时任务处理
      return false;
    }

    try {
      DB::beginTransaction();

      // 激活时间包
      $user->class = $content->class;
      // 如果用户会员已过期，从当前时间开始计算
      $old_class_expire = new DateTime($user->class_expire);
      $now = new DateTime();
      if ($old_class_expire < $now) {
        $old_class_expire = $now;
      }
      $user->class_expire = $old_class_expire
        ->modify('+' . $content->class_time . ' days')->format('Y-m-d H:i:s');
      $user->node_group = $content->node_group;
      $user->node_speedlimit = $content->speed_limit;
      $user->node_iplimit = $content->ip_limit;
      $user->save();

      $order->status = 'activated';
      $order->update_time = time();
      $order->save();

      DB::commit();
      return true;
    } catch (Exception $e) {
      DB::rollBack();
      return false;
    }
  }

  /**
   * 激活充值订单
   */
  private static function activateTopupOrder(Order $order): bool
  {
    $user = (new User())->find($order->user_id);

    if ($user === null) {
      return false;
    }

    $content = json_decode($order->product_content);

    if ($content === null || ! isset($content->amount)) {
      return false;
    }

    try {
      DB::beginTransaction();

      $money_before = $user->money;
      // 充值
      $user->money += $content->amount;
      $user->save();

      $order->status = 'activated';
      $order->update_time = time();
      $order->save();

      (new UserMoneyLog())->add(
        $order->user_id,
        $money_before,
        $user->money,
        $content->amount,
        "充值订单 #{$order->id}"
      );

      DB::commit();
      return true;
    } catch (Exception $e) {
      DB::rollBack();
      return false;
    }
  }
}
