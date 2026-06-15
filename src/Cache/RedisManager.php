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
            try {
                $connection = static::$pools[$name]->get();
                Context::set($key, $connection);
            } catch (Throwable $e) {
                // Connection was never obtained, nothing to return to pool
                throw $e;
            }
            Context::onDestroy(function () use ($connection, $name): void {
                try {
                    static::$pools[$name]->put($connection);
                } catch (Throwable) {
                    // ignore
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
        $pool = new Pool($poolConfig['max_connections'] ?? 10, $poolConfig);
        $pool->setConnectionCreator(function () use ($name): Connection {
            $connection = $this->configure($this->resolve($name), $name);
            if (class_exists(Dispatcher::class)) {
                $connection->setEventDispatcher(new Dispatcher());
            }
            $this->allConnections ??= new WeakMap();
            $this->allConnections[$connection] = true;
            return $connection;
        });
        $pool->setConnectionCloser(fn(Connection $connection): bool => $connection->client()->close());
        $pool->setHeartbeatChecker(fn(Connection $connection): mixed => $connection->get('PING'));
        return $pool;
    }

    /**
     * Return all the created connections.
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
