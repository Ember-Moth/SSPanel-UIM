<?php

declare(strict_types=1);

namespace App\Services;


use Redis;
use Swoole\Coroutine\Redis as CoRedis;

final class Cache
{

    /**
     * 初始化 Redis 客户端
     * @param bool $swoole 是否使用 Swoole 协程客户端
     * @return Redis|CoRedis
     */
    public function initRedis(bool $swoole = false)
    {
        $config = self::getRedisConfig();
        if ($swoole) {
            $redis = new CoRedis();
            $redis->connect($config['host'], $config['port']);
            if (isset($config['auth'])) {
                if (isset($config['auth']['user']) && isset($config['auth']['pass'])) {
                    $redis->auth([$config['auth']['user'], $config['auth']['pass']]);
                } elseif (isset($config['auth']['pass'])) {
                    $redis->auth($config['auth']['pass']);
                }
            }
            if (isset($config['database'])) {
                $redis->select((int)$config['database']);
            }
            return $redis;
        } else {
            $redis = new Redis();
            $redis->connect(
                $config['host'],
                $config['port'],
                $config['connectTimeout']
            );
            $redis->setOption(Redis::OPT_READ_TIMEOUT, $config['readTimeout']);
            if (isset($config['auth'])) {
                if (isset($config['auth']['user']) && isset($config['auth']['pass'])) {
                    $redis->auth([$config['auth']['user'], $config['auth']['pass']]);
                } elseif (isset($config['auth']['pass'])) {
                    $redis->auth($config['auth']['pass']);
                }
            }
            if (isset($config['database'])) {
                $redis->select((int)$config['database']);
            }
            return $redis;
        }
    }

    public static function getRedisConfig(): array
    {
        $config = [
            'host' => $_ENV['redis_host'] ?? 'localhost',
            'port' => (int)($_ENV['redis_port'] ?? 6379),
            'connectTimeout' => (float)($_ENV['redis_connect_timeout'] ?? 2.0),
            'readTimeout' => (float)($_ENV['redis_read_timeout'] ?? 2.0),
            'database' => isset($_ENV['redis_db']) ? (int)$_ENV['redis_db'] : 0,
        ];

        if ($_ENV['redis_username'] !== null && $_ENV['redis_username'] !== '') {
            $config['auth']['user'] = $_ENV['redis_username'];
        }

        if ($_ENV['redis_password'] !== null && $_ENV['redis_password'] !== '') {
            $config['auth']['pass'] = $_ENV['redis_password'];
        }

        if (filter_var($_ENV['redis_ssl'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $config['ssl'] = $_ENV['redis_ssl_context'] ?? [];
        }

        return $config;
    }
}
