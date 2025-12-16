<?php

declare(strict_types=1);

namespace App\Services\Queue\Jobs;

use App\Services\OrderActivation;
use App\Services\Queue\JobInterface;
use App\Services\Queue\RedisQueue;
use Throwable;
use function json_encode;

/**
 * 订单激活任务
 */
final class ActivateOrderJob implements JobInterface
{
  /**
   * 执行订单激活
   * @param array $payload ['order_id' => int]
   */
  public function handle(array $payload): void
  {
    $orderId = $payload['order_id'] ?? null;
    if (!$orderId) {
      throw new \InvalidArgumentException('order_id is required');
    }
    // 幂等激活
    OrderActivation::activateOrder((int)$orderId);
  }

  public static function getQueue(): string
  {
    return RedisQueue::QUEUE_HIGH;
  }

  public function failed(array $payload, Throwable $exception): void
  {
    error_log(sprintf(
      '[ActivateOrderJob] Failed to activate order: %s | Payload: %s',
      $exception->getMessage(),
      json_encode($payload, JSON_UNESCAPED_UNICODE)
    ));
  }

  /**
   * 快捷派发
   */
  public static function dispatch(int $orderId, int $delay = 0): string
  {
    $queue = new RedisQueue();
    return $queue->push(self::class, ['order_id' => $orderId], self::getQueue(), $delay);
  }
}
