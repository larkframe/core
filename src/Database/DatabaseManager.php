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
    protected function createPool(string $name, string $database, ?string $type): Pool
    {
        $poolConfig = config('database.connections.' . $name . '.pool', []);
        $pool = new Pool($poolConfig['max_connections'] ?? 6, $poolConfig);
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
     * Should be called in onWorkerStart after the event loop is ready.
     */
    public static function startPoolMaintenance(\LarkFrame\Events\EventInterface $eventLoop): void
    {
        foreach (static::$pools as $pool) {
            $pool->startMaintenance($eventLoop);
        }
    }
}
