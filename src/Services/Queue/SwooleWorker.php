<?php

declare(strict_types=1);

namespace App\Services\Queue;

use App\Utils\Tools;
use Swoole\Coroutine;

final class SwooleWorker
{
  private array $queues;
  private int $concurrency;
  private int $maxJobs;
  private int $maxTime;
  private int $memoryLimit;
  private int $sleep;

  public function __construct(
    array $queues,
    int $concurrency = 8,
    int $maxJobs = 0,
    int $maxTime = 0,
    int $memoryLimit = 128,
    int $sleep = 1000000
  ) {
    $this->queues = $queues;
    $this->concurrency = $concurrency;
    $this->maxJobs = $maxJobs;
    $this->maxTime = $maxTime;
    $this->memoryLimit = $memoryLimit;
    $this->sleep = $sleep;
  }

  public function run(): void
  {
    echo Tools::toDateTime(time()) . " Swoole 协程 Worker 启动，协程数: {$this->concurrency}, 队列: " . implode(', ', $this->queues) . "\n";
    Coroutine\run(function () {
      for ($i = 0; $i < $this->concurrency; $i++) {
        Coroutine::create(function () use ($i) {
          $worker = new Worker($this->queues, $this->maxJobs, $this->maxTime, $this->memoryLimit, $this->sleep);
          // Worker 构造时传 swoole=true，内部 RedisQueue 用协程 Redis
          $worker->setSwooleMode();
          $worker->run();
        });
      }
    });
  }
}
