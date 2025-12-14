<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Config;
use App\Models\HourlyUsage;
use App\Models\Node;
use App\Models\User;
use App\Utils\Tools;
use Redis;
use function json_decode;
use function json_encode;
use function time;
use const PHP_EOL;

/**
 * 流量使用服务
 *
 * 使用 Redis 缓存流量数据，定时批量写入数据库
 */
final class Usage
{
  private Redis $redis;

  // Redis 键前缀
  private const PREFIX = 'sspanel:usage:';
  private const USER_TRAFFIC_KEY = self::PREFIX . 'user_traffic';
  private const NODE_TRAFFIC_KEY = self::PREFIX . 'node_traffic';
  private const HOURLY_USAGE_KEY = self::PREFIX . 'hourly_usage';
  private const NODE_ONLINE_KEY = self::PREFIX . 'node_online';

  public function __construct()
  {
    $this->redis = (new Cache())->initRedis();
  }

  /**
   * 记录用户流量到 Redis
   *
   * @param int $userId 用户ID
   * @param int $u 上传流量（原始值）
   * @param int $d 下载流量（原始值）
   * @param float $rate 流量倍率
   */
  public function addUserTraffic(int $userId, int $u, int $d, float $rate): void
  {
    $billedU = (int) ($u * $rate);
    $billedD = (int) ($d * $rate);

    // 使用 Hash 存储用户流量增量
    // 格式: user_traffic -> {user_id} -> {u, d, transfer_total, transfer_today}
    $key = self::USER_TRAFFIC_KEY;

    // 获取当前缓存的流量
    $current = $this->redis->hGet($key, (string) $userId);

    if ($current !== false) {
      $data = json_decode($current, true);
      $data['u'] += $billedU;
      $data['d'] += $billedD;
      $data['transfer_total'] += $u + $d;
      $data['transfer_today'] += $billedU + $billedD;
    } else {
      $data = [
        'u' => $billedU,
        'd' => $billedD,
        'transfer_total' => $u + $d,
        'transfer_today' => $billedU + $billedD,
      ];
    }

    $this->redis->hSet($key, (string) $userId, json_encode($data));
  }

  /**
   * 记录节点流量到 Redis
   *
   * @param int $nodeId 节点ID
   * @param int $traffic 流量（原始值）
   * @param int $onlineUser 在线用户数
   */
  public function addNodeTraffic(int $nodeId, int $traffic, int $onlineUser): void
  {
    // 节点流量累加
    $this->redis->hIncrBy(self::NODE_TRAFFIC_KEY, (string) $nodeId, $traffic);
    // 节点在线用户数（直接覆盖）
    $this->redis->hSet(self::NODE_ONLINE_KEY, (string) $nodeId, $onlineUser);
  }

  /**
   * 记录小时流量日志到 Redis
   *
   * @param int $userId 用户ID
   * @param int $traffic 流量
   */
  public function addHourlyUsage(int $userId, int $traffic): void
  {
    $this->redis->hIncrBy(self::HOURLY_USAGE_KEY, (string) $userId, $traffic);
  }

  /**
   * 批量同步流量数据到数据库
   *
   * 由定时任务调用
   */
  public function syncToDatabase(): array
  {
    // 1. 同步用户流量
    $userCount = $this->syncUserTraffic();

    // 2. 同步节点流量
    $nodeCount = $this->syncNodeTraffic();

    // 3. 同步小时流量日志
    $hourlyCount = Config::obtain('traffic_log') ? $this->syncHourlyUsage() : 0;

    return [
      'users' => $userCount,
      'nodes' => $nodeCount,
      'hourly_logs' => $hourlyCount,
    ];
  }

  /**
   * 同步用户流量到数据库
   */
  private function syncUserTraffic(): int
  {
    $key = self::USER_TRAFFIC_KEY;
    $tempKey = $key . ':processing';
    $count = 0;

    // 原子操作：重命名 key，避免同步期间的并发写入丢失
    if (! $this->redis->rename($key, $tempKey)) {
      // key 不存在或重命名失败
      return 0;
    }

    // 获取所有用户流量数据
    $allData = $this->redis->hGetAll($tempKey);

    if (empty($allData)) {
      $this->redis->del($tempKey);
      return 0;
    }

    $now = time();

    foreach ($allData as $userId => $jsonData) {
      $data = json_decode($jsonData, true);

      if (! $data) {
        continue;
      }

      $user = (new User())->find((int) $userId);

      if ($user === null) {
        continue;
      }

      // 更新用户数据
      $user->update([
        'last_use_time' => $now,
        'u' => $user->u + $data['u'],
        'd' => $user->d + $data['d'],
        'transfer_total' => $user->transfer_total + $data['transfer_total'],
        'transfer_today' => $user->transfer_today + $data['transfer_today'],
      ]);

      $count++;
    }

    // 删除临时 key
    $this->redis->del($tempKey);

    return $count;
  }

  /**
   * 同步节点流量到数据库
   */
  private function syncNodeTraffic(): int
  {
    $trafficKey = self::NODE_TRAFFIC_KEY;
    $onlineKey = self::NODE_ONLINE_KEY;
    $tempTrafficKey = $trafficKey . ':processing';
    $tempOnlineKey = $onlineKey . ':processing';
    $count = 0;

    // 原子操作：重命名 key
    $hasTraffic = $this->redis->rename($trafficKey, $tempTrafficKey);
    $hasOnline = $this->redis->rename($onlineKey, $tempOnlineKey);

    if (! $hasTraffic && ! $hasOnline) {
      return 0;
    }

    // 获取所有节点数据
    $trafficData = $hasTraffic ? $this->redis->hGetAll($tempTrafficKey) : [];
    $onlineData = $hasOnline ? $this->redis->hGetAll($tempOnlineKey) : [];

    if (empty($trafficData) && empty($onlineData)) {
      if ($hasTraffic) {
        $this->redis->del($tempTrafficKey);
      }
      if ($hasOnline) {
        $this->redis->del($tempOnlineKey);
      }
      return 0;
    }

    // 合并所有节点ID
    $nodeIds = array_unique(array_merge(array_keys($trafficData), array_keys($onlineData)));

    foreach ($nodeIds as $nodeId) {
      $node = (new Node())->find((int) $nodeId);

      if ($node === null) {
        continue;
      }

      $updateData = [];

      // 流量增量
      if (isset($trafficData[$nodeId])) {
        $updateData['node_bandwidth'] = $node->node_bandwidth + (int) $trafficData[$nodeId];
      }

      // 在线用户数
      if (isset($onlineData[$nodeId])) {
        $updateData['online_user'] = (int) $onlineData[$nodeId];
      }

      if (! empty($updateData)) {
        $node->update($updateData);
        $count++;
      }
    }

    // 删除临时 key
    if ($hasTraffic) {
      $this->redis->del($tempTrafficKey);
    }
    if ($hasOnline) {
      $this->redis->del($tempOnlineKey);
    }

    return $count;
  }

  /**
   * 同步小时流量日志到数据库
   */
  private function syncHourlyUsage(): int
  {
    $key = self::HOURLY_USAGE_KEY;
    $tempKey = $key . ':processing';
    $count = 0;

    // 原子操作：重命名 key
    if (! $this->redis->rename($key, $tempKey)) {
      return 0;
    }

    $allData = $this->redis->hGetAll($tempKey);

    if (empty($allData)) {
      $this->redis->del($tempKey);
      return 0;
    }

    foreach ($allData as $userId => $traffic) {
      (new HourlyUsage())->add((int) $userId, (int) $traffic);
      $count++;
    }

    // 删除临时 key
    $this->redis->del($tempKey);

    return $count;
  }

  /**
   * 获取当前缓存统计
   */
  public function getCacheStats(): array
  {
    return [
      'pending_users' => $this->redis->hLen(self::USER_TRAFFIC_KEY),
      'pending_nodes' => $this->redis->hLen(self::NODE_TRAFFIC_KEY),
      'pending_hourly' => $this->redis->hLen(self::HOURLY_USAGE_KEY),
    ];
  }

  /**
   * 清空所有流量缓存（慎用）
   */
  public function flushCache(): void
  {
    $this->redis->del(self::USER_TRAFFIC_KEY);
    $this->redis->del(self::NODE_TRAFFIC_KEY);
    $this->redis->del(self::NODE_ONLINE_KEY);
    $this->redis->del(self::HOURLY_USAGE_KEY);
  }
}
