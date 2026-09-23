<?php

namespace LarkFrame\Database;

use Illuminate\Database\DatabaseManager as BaseDatabaseManager;
use Throwable;
use LarkFrame\Context;
use LarkFrame\Coroutine\Pool;

class DatabaseManager extends BaseDatabaseManager
{
    /**
     * @var Pool[]
     */
    protected static array $pools = [];

    /**
     * @inheritDoc
     */
    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->reconnector = function ($connection) {
            $name = $connection->getNameWithReadWriteType();
            [$database, $type] = $this->parseConnectionName($name);
            $fresh = $this->configure(
                $this->makeConnection($database), $type
            );
            $connection->setPdo($fresh->getRawPdo());
        };
    }

    /**
     * Get connection from pool with context management.
     *
     * @param string|null $name
     * @return mixed
     * @throws Throwable
     */
    public function connection($name = null): mixed
    {
        $name = $name ?: config('database.default', 'mysql');
        [$database, $type] = $this->parseConnectionName($name);

        $key = "database.connections.$name";
        $connection = Context::get($key);
        if (!$connection) {
            static::$pools[$name] ??= $this->createPool($name, $database, $type);
            $connection = static::$pools[$name]->get();
            Context::set($key, $connection);
            Context::onDestroy(function () use ($connection, $name): void {
                $pool = static::$pools[$name] ?? null;
                if ($pool === null) {
                    return;
                }
                try {
                    // Roll back uncommitted transactions to prevent dirty state leaking to next request.
                    // 嵌套事务必须逐层回滚：rollBack() 只回退一级，残留层级会污染下一个借用方
                    if (method_exists($connection, 'transactionLevel')) {
                        while ($connection->transactionLevel() > 0) {
                            $connection->rollBack();
                        }
                    }
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
    protected function createPool(string $name, string $database, ?string $type): Pool
    {
        $poolConfig = config('database.connections.' . $name . '.pool', []);
        // 必须经 fromConfig 创建：直接 new Pool(max, config) 会丢弃 min_connections/idle_timeout/
        // wait_timeout/heartbeat_interval 等子配置，且 Server 模式下 forceCoroutineMode 不会生效
        $pool = Pool::fromConfig($poolConfig['max_connections'] ?? 6, $poolConfig);
        $pool->setConnectionCreator(function () use ($database, $type) {
            return $this->configure($this->makeConnection($database), $type);
        });
        $pool->setConnectionCloser(function ($connection): void {
            $this->closeAndFreeConnection($connection);
        });
        $pool->setHeartbeatChecker(function ($connection): void {
            match (true) {
                in_array($connection->getDriverName(), ['mysql', 'pgsql', 'sqlite', 'sqlsrv']) => $connection->select('select 1'),
                default => null,
            };
        });
        // 池为懒创建（首个请求时），维护定时器在创建时自注册，而非依赖 worker 启动期统一挂载
        if (\LarkFrame\Worker::$globalEvent !== null) {
            $pool->startMaintenance(\LarkFrame\Worker::$globalEvent);
        }
        return $pool;
    }

    /**
     * Close connection and free resources.
     */
    protected function closeAndFreeConnection(mixed $connection): void
    {
        $connection->disconnect();
    }

    /**
     * Start pool maintenance timers for all pools.
     * 仅适用于事件循环就绪前已存在的池；懒创建的池在 createPool 内自注册。
     */
    public static function startPoolMaintenance(\LarkFrame\Events\EventInterface $eventLoop): void
    {
        foreach (static::$pools as $pool) {
            $pool->startMaintenance($eventLoop);
        }
    }
}
