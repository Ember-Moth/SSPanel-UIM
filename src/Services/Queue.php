<?php

declare(strict_types=1);

namespace App\Services;

use function json_encode;
use function time;

/**
 * 通用队列处理类
 * 用于管理 Redis 队列的任务添加、弹出和删除
 *
 * @property string $queueName 队列名称
 * @property int $ttl       任务存活时间（秒）
 */
final class Queue
{
    // Redis 连接类型
    private $connection = 'redis';
    // 默认队列名称
    private $queueName = 'default_queue';
    // Redis 客户端实例
    private $redis;
    // 任务默认存活时间（24小时）
    private $ttl = 86400;

    /**
     * 构造函数，初始化队列
     *
     * @param string $queueName 队列名称
     */
    public function __construct(string $queueName = 'default_queue')
    {
        $this->queueName = $queueName;
        $this->redis = (new Cache())->initRedis();
    }

    /**
     * 添加任务到队列
     *
     * @param array $data 任务数据
     * @param string $type 任务类型
     */
    public function add(array $data, string $type): void
    {
        try {
            $taskData = [
                'id' => $this->generateUniqueId(),
                'type' => $type,
                'data' => $data,
                'time' => time(),
            ];

            $key = $this->queueName . ':' . $taskData['id'];
            $this->redis->rPush($this->queueName, $key);
            $this->redis->setEx($key, $this->ttl, json_encode($taskData));
        } catch (Throwable $e) {
            throw new RuntimeException("添加任务失败: " . $e->getMessage());
        }
    }

    /**
     * 生成唯一任务 ID
     *
     * @return string 唯一 ID
     */
    private function generateUniqueId(): string
    {
        return md5(uniqid('task_', true) . microtime(true));
    }

    /**
     * 获取队列长度
     *
     * @return int 队列中的任务数量
     */
    public function count(): int
    {
        return (int)$this->redis->lLen($this->queueName);
    }

    /**
     * 非阻塞弹出任务
     *
     * @return array|null 任务数据或 null
     */
    public function pop(): ?array
    {
        try {
            $key = $this->redis->lPop($this->queueName);
            if ($key === false) {
                return null;
            }
            $data = $this->redis->get($key);
            $this->redis->del($key);
            if ($data === false) {
                return null;
            }
            $decoded = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("JSON 解析错误: " . json_last_error_msg());
            }
            return $decoded;
        } catch (Throwable $e) {
            throw new RuntimeException("弹出任务失败: " . $e->getMessage());
        }
    }

    /**
     * 阻塞弹出任务（单队列，兼容旧方法）
     *
     * @param int $timeout 超时时间（秒）
     * @return array|null 任务数据或 null
     */
    public function blockingPop(int $timeout = 30): ?array
    {
        $result = $this->blockingPopMultiple([$this->queueName], $timeout);
        return $result ? $result['task'] : null;
    }

    /**
     * 阻塞弹出任务（支持多队列）
     *
     * @param array $queues 队列名称数组
     * @param int $timeout 超时时间（秒）
     * @return array|null 包含队列名称和任务数据的数组，或 null
     */
    public function blockingPopMultiple(array $queues, int $timeout = 30): ?array
    {
        try {
            $result = $this->redis->brPop($queues, $timeout);
            if ($result === null || count($result) !== 2) {
                return null;
            }

            [$queue, $key] = $result;
            $data = $this->redis->get($key);
            $this->redis->del($key);
            if ($data === false) {
                return null;
            }
            $decoded = json_decode($data, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("JSON 解析错误: " . json_last_error_msg());
            }
            return ['queue' => $queue, 'task' => $decoded];
        } catch (Throwable $e) {
            throw new RuntimeException("阻塞弹出任务失败: " . $e->getMessage());
        }
    }

    /**
     * 查询条件过滤（占位，依赖 TTL 自动清理）
     *
     * @param mixed $column 列名
     * @param mixed $operator 操作符
     * @param mixed $value 值
     * @return self
     */
    public function where($column, $operator, $value): self
    {
        return $this;
    }

    /**
     * 删除整个队列及其关联数据
     */
    public function delete(): void
    {
        try {
            $keys = $this->redis->lRange($this->queueName, 0, -1);
            if ($keys !== []) {
                $this->redis->del($keys);
            }
            $this->redis->del($this->queueName);
        } catch (Throwable $e) {
            throw new RuntimeException("删除队列失败: " . $e->getMessage());
        }
    }
}