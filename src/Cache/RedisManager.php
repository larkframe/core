<?php

namespace LarkFrame\Cache;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;
use Throwable;
use WeakMap;
use LarkFrame\Context;
use LarkFrame\Coroutine\Pool;

/**
 * Class RedisManager
 * @package Core\Cache
 */
class RedisManager extends \Illuminate\Redis\RedisManager
{
    /**
     * @var Pool[]
     */
    protected static array $pools = [];

    /**
     * All created connections tracked via WeakMap.
     */
    protected WeakMap $allConnections;

    /**
     * Get connection from pool with context management.
     *
     * @throws Throwable
     */
    public function connection($name = null): Connection
    {
        $name = $name ?: 'default';
        $key = "redis.connections.$name";
        $connection = Context::get($key);

        if (!$connection) {
            static::$pools[$name] ??= $this->createPool($name);
            $connection = static::$pools[$name]->get();
            Context::set($key, $connection);
            Context::onDestroy(function () use ($connection, $name): void {
                $pool = static::$pools[$name] ?? null;
                if ($pool === null) {
                    return;
                }
                // 归还前清理未提交的 MULTI 事务：业务在 multi() 后抛异常会把连接以事务态归还，
                // 下一个借用者的命令将被缓存进上一个事务，直到意外 exec/discard 才执行，导致数据错乱。
                // getMode() 是 phpredis 的本地调用（无网络往返）；Predis 的 __call 会把未知方法
                // 当命令转发，故先做 method_exists 判断。仅 MULTI 态需处理，避免每请求额外 RTT。
                try {
                    $client = $connection->client();
                    if (method_exists($client, 'getMode') && $client->getMode() === \Redis::MULTI) {
                        $client->discard();
                    }
                } catch (Throwable) {
                    // 清理失败不阻断归还，连接仍可复用
                }
                try {
                    $pool->put($connection);
                } catch (Throwable) {
                    // ignore — connection may already be closed
                }
            });
        }

        return $connection;
    }

    /**
     * Create a connection pool for the given name.
     */
    protected function createPool(string $name): Pool
    {
        $poolConfig = $this->config[$name]['pool'] ?? [];
        // 必须经 fromConfig 创建，池子配置（min_connections/idle_timeout 等）才能生效
        $pool = Pool::fromConfig($poolConfig['max_connections'] ?? 10, $poolConfig);
        $pool->setConnectionCreator(function () use ($name): Connection {
            $connection = $this->configure($this->resolve($name), $name);
            if (class_exists(Dispatcher::class)) {
                $connection->setEventDispatcher(new Dispatcher());
            }
            $this->allConnections ??= new WeakMap();
            $this->allConnections[$connection] = true;
            return $connection;
        });
        // phpredis 用 close()；predis 无 close（__call 会把 close 当命令转发导致报错），用 disconnect()
        $pool->setConnectionCloser(function (Connection $connection): void {
            $client = $connection->client();
            if (method_exists($client, 'close')) {
                $client->close();
            } elseif (method_exists($client, 'disconnect')) {
                $client->disconnect();
            }
        });
        $pool->setHeartbeatChecker(fn(Connection $connection): mixed => $connection->client()->ping());
        // 池为懒创建（首个请求时），维护定时器在创建时自注册
        if (\LarkFrame\Worker::$globalEvent !== null) {
            $pool->startMaintenance(\LarkFrame\Worker::$globalEvent);
        }
        return $pool;
    }

    /**
     * Return all the created connections.
     *
     * 警告：返回值包含正被其他协程借出的连接，直接对其执行命令
     * 会产生并发冲突。仅用于诊断/统计，禁止用于业务读写。
     */
    public function connections(): array
    {
        if (!isset($this->allConnections)) {
            return [];
        }
        $connections = [];
        foreach ($this->allConnections as $connection => $_) {
            $connections[] = $connection;
        }
        return $connections;
    }

    /**
     * Add or update a connection configuration dynamically.
     */
    public function addConnectionConfig(string $name, array $config): void
    {
        $this->config[$name] = $config;
    }

    /**
     * Start pool maintenance timers for all pools.
     * Should be called in onWorkerStart after the event loop is ready.
     */
    public static function startPoolMaintenance(\LarkFrame\Events\EventInterface $eventLoop): void
    {
        foreach (static::$pools as $pool) {
            $pool->startMaintenance($eventLoop);
        }
    }
}
