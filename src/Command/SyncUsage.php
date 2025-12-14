<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Usage;
use App\Utils\Tools;
use const PHP_EOL;

/**
 * 流量同步命令
 *
 * 将 Redis 中缓存的流量数据同步到数据库
 * 建议每分钟执行一次
 */
final class SyncUsage extends Command
{
  public string $description = <<<EOL
├─=: php xcat SyncUsage - 同步流量缓存到数据库（建议每分钟执行）
EOL;

  public function boot(): void
  {
    $usage = new Usage();

    // 显示当前缓存状态
    $cacheStats = $usage->getCacheStats();
    echo Tools::toDateTime(time()) . ' 当前缓存状态:' . PHP_EOL;
    echo "  待同步用户: {$cacheStats['pending_users']}" . PHP_EOL;
    echo "  待同步节点: {$cacheStats['pending_nodes']}" . PHP_EOL;
    echo "  待同步日志: {$cacheStats['pending_hourly']}" . PHP_EOL;

    // 执行同步
    $stats = $usage->syncToDatabase();

    echo Tools::toDateTime(time()) . ' 同步完成:' . PHP_EOL;
    echo "  用户流量: {$stats['users']}" . PHP_EOL;
    echo "  节点流量: {$stats['nodes']}" . PHP_EOL;
    echo "  小时日志: {$stats['hourly_logs']}" . PHP_EOL;
  }
}
