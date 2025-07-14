<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/.config.php';
require_once __DIR__ . '/config/appprofile.php';
require_once __DIR__ . '/app/predefine.php';

use App\Jobs\EmailJob;
use App\Jobs\OrderJob;
use App\Services\Boot;
use App\Services\Cache;
use App\Services\Queue;
use App\Utils\Tools;

use function Sentry\captureException;
use function Sentry\captureMessage;

const REDIS_RETRY_INTERVAL = 5;
const QUEUE_TIMEOUT = 30;
$queues = ['email_queue', 'order_queue'];

// 初始化环境
Boot::setTime();
Boot::bootSentry();
Boot::bootDb();

/**
 * 日志输出
 */
function logMessage(string $message): void
{
    echo Tools::toDateTime(time()) . ' ' . $message . "\n";
}

/**
 * 初始化 Redis 连接（复用 Cache 服务）
 * @return Redis
 * @throws Throwable
 */
function initRedisConnection(): Redis
{
    try {
        $cache = new Cache();
        $redis = $cache->initRedis();
        $redis->setOption(Redis::OPT_READ_TIMEOUT, -1); // 防止 brPop 报错
        $redis->ping();
        $config = Cache::getRedisConfig();
        $host = $config['host'];
        $port = $config['port'];
        logMessage("Redis（ext-redis）连接成功（{$host}:{$port}）");
        return $redis;
    } catch (Throwable $e) {
        throw new RuntimeException("Redis 连接失败: " . $e->getMessage());
    }
}

/**
 * 任务处理器接口
 */
interface JobHandler
{
    public function handle(array $task): void;
}

/**
 * 邮件任务处理器
 */
class EmailJobHandler implements JobHandler
{
    public function handle(array $task): void
    {
        EmailJob::handle($task);
    }
}

/**
 * 订单任务处理器
 */
class OrderJobHandler implements JobHandler
{
    /**
     * @throws Exception
     */
    public function handle(array $task): void
    {
        OrderJob::handle($task);
    }
}

/**
 * 处理任务
 * @param array $task 任务数据
 * @param string $queueName 队列名称
 */
function processTask(array $task, string $queueName): void
{
    try {
        $key = $task['id'] ?? 'unknown';
        if (!isset($task['type'], $task['data'])) {
            logMessage("无效任务结构：{$key}（队列：{$queueName}）");
            captureMessage("无效任务结构：Key={$key}, Queue={$queueName}");
            return;
        }

        $handlers = [
            'email' => new EmailJobHandler(),
            'order' => new OrderJobHandler(),
        ];

        logMessage("处理任务：{$key}（队列：{$queueName}）, 类型：{$task['type']}");

        if (!isset($handlers[$task['type']])) {
            logMessage("未知任务类型：{$task['type']}（队列：{$queueName}）");
            captureMessage("未知任务类型：Key={$key}, Type={$task['type']}, Queue={$queueName}");
            return;
        }

        $handlers[$task['type']]->handle($task);
        logMessage("任务处理成功：{$key}（队列：{$queueName}）");
    } catch (Throwable $ex) {
        logMessage("任务处理失败：{$key}（队列：{$queueName}）, 错误：" . $ex->getMessage());
        captureException($ex);

        try {
            $queue = new Queue($queueName);
            $queue->add($task['data'], $task['type']);
            logMessage("任务已重新入队：{$key}（队列：{$queueName}）");
        } catch (Throwable $re) {
            logMessage("任务重新入队失败：{$key}（队列：{$queueName}）, 错误：" . $re->getMessage());
            captureException($re);
        }

        sleep(1);
    }
}

// 启动消费者主循环
try {
    $redis = initRedisConnection();
    $queue = new Queue('default_queue'); // 用于 blockingPopMultiple
} catch (Throwable $e) {
    logMessage("初始化 Redis 失败，退出：{$e->getMessage()}");
    if (isset($redis) && $redis instanceof Redis) {
        $redis->close();
    }
    exit(1);
}

logMessage("队列消费者启动（队列：" . implode(', ', $queues) . "）");

while (true) {
    try {
        $result = $queue->blockingPopMultiple($queues, QUEUE_TIMEOUT);
        if ($result === null) {
            logMessage("无新任务，继续等待...");
            continue;
        }

        $queueName = $result['queue'];
        $task = $result['task'];
        logMessage("从队列获取任务：{$task['id']}（队列：{$queueName}）");
        processTask($task, $queueName);
    } catch (Throwable $e) {
        logMessage("消费进程异常：" . $e->getMessage());
        captureException($e);

        sleep(REDIS_RETRY_INTERVAL);

        try {
            $redis = initRedisConnection();
            $queue = new Queue('default_queue'); // 重新初始化 Queue 实例
        } catch (Throwable $re) {
            logMessage("Redis 重连失败：" . $re->getMessage());
            captureException($re);
        }
    }
}