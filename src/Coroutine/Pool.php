<?php

namespace LarkFrame\Coroutine;

use Closure;
use Fiber;
use LarkFrame\Consts;
use LarkFrame\Events\EventInterface;
use stdClass;
use Throwable;
use WeakMap;
use function class_exists;
use function count;
use function defined;
use function gettype;
use function max;
use function microtime;

/**
 * Class Pool
 *
 * Connection pool implementation with PHP 8.1 optimizations.
 * Uses readonly properties, ConnectionStatus enum, constructor property promotion,
 * and first-class callable syntax.
 */
class Pool implements PoolInterface
{
    /**
     * Connection status tracking via WeakMap.
     */
    private readonly WeakMap $connectionStatus;

    /**
     * Connection creation timestamps.
     */
    private readonly WeakMap $createdAt;

    /**
     * Channel for connection distribution.
     */
    private readonly ChannelInterface $channel;

    /**
     * Connection tracking WeakMap.
     */
    private readonly WeakMap $connections;

    /**
     * Last used times WeakMap.
     */
    private readonly WeakMap $lastUsedTimes;

    /**
     * Last heartbeat times WeakMap.
     */
    private readonly WeakMap $lastHeartbeatTimes;

    /**
     * Connection for non-coroutine environment.
     */
    private ?object $nonCoroutineConnection = null;

    /**
     * Maintenance timer IDs.
     */
    private ?int $idleCheckTimerId = null;
    private ?int $heartbeatTimerId = null;

    /**
     * Connection creator callback.
     */
    private ?Closure $connectionCreateHandler = null;

    /**
     * Connection closer callback.
     */
    private ?Closure $connectionDestroyHandler = null;

    /**
     * Connection heartbeat checker callback.
     */
    private ?Closure $connectionHeartbeatHandler = null;

    /**
     * Whether to force coroutine mode (Server 模式启动期也走协程分支，消除启动时机决定路径的隐式依赖).
     */
    private readonly bool $forceCoroutineMode;

    /**
     * Constructor with property promotion for config values.
     *
     * @param array $config 原始配置（fromConfig 解析后各字段已展开为独立参数，此参数仅为位置兼容保留）
     */
    public function __construct(
        private readonly int $maxConnections = 1,
        array $config = [],
        private readonly int $minConnections = 1,
        private readonly float $idleTimeout = 60.0,
        private readonly float $heartbeatInterval = 50.0,
        private readonly float $waitTimeout = 10.0,
        bool $forceCoroutineMode = false,
    ) {
        $this->channel = new MemoryChannel($maxConnections);
        $this->connections = new WeakMap();
        $this->lastUsedTimes = new WeakMap();
        $this->lastHeartbeatTimes = new WeakMap();
        $this->connectionStatus = new WeakMap();
        $this->createdAt = new WeakMap();
        $this->forceCoroutineMode = $forceCoroutineMode;
    }

    /**
     * Create a Pool from config array (named constructor pattern).
     */
    public static function fromConfig(int $maxConnections, array $config): self
    {
        $camelCased = [];
        foreach ($config as $key => $value) {
            $camelKey = lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $key))));
            $camelCased[$camelKey] = $value;
        }

        // Server 模式下强制协程分支，避免启动期误创建 nonCoroutineConnection 串行化瓶颈
        $forceCoroutine = defined('RUN_TYPE') && RUN_TYPE === Consts::RUN_TYPE_SERVER;

        return new self(
            maxConnections: $maxConnections,
            config: $config,
            minConnections: (int)($camelCased['minConnections'] ?? 1),
            idleTimeout: (float)($camelCased['idleTimeout'] ?? 60.0),
            heartbeatInterval: (float)($camelCased['heartbeatInterval'] ?? 50.0),
            waitTimeout: (float)($camelCased['waitTimeout'] ?? 10.0),
            forceCoroutineMode: $forceCoroutine,
        );
    }

    /**
     * Set the connection creator using first-class callable syntax.
     */
    public function setConnectionCreator(callable $connectionCreateHandler): self
    {
        $this->connectionCreateHandler = $connectionCreateHandler(...);
        return $this;
    }

    /**
     * Set the connection closer using first-class callable syntax.
     */
    public function setConnectionCloser(callable $connectionDestroyHandler): self
    {
        $this->connectionDestroyHandler = $connectionDestroyHandler(...);
        return $this;
    }

    /**
     * Set the connection heartbeat checker using first-class callable syntax.
     */
    public function setHeartbeatChecker(callable $connectionHeartbeatHandler): self
    {
        $this->connectionHeartbeatHandler = $connectionHeartbeatHandler(...);
        return $this;
    }

    /**
     * Get connection from pool.
     */
    public function get(): object
    {
        // Non-coroutine: reuse a single connection with heartbeat validation
        if (!$this->isCoroutine()) {
            // 已有连接则用心跳回调校验活性，避免返回已被服务端关闭的死连接。
            // 按 heartbeat_interval 节流：原实现每次 get() 都探测，等于给每个操作多加一次网络往返
            // （Redis PING / MySQL ping）；heartbeat_interval <= 0 视为未配置节流，保持每次都探测，
            // 不引入活性校验空窗。
            $conn = $this->nonCoroutineConnection;
            if ($conn !== null && $this->connectionHeartbeatHandler !== null) {
                $shouldProbe = $this->heartbeatInterval <= 0
                    || (microtime(true) - ($this->lastHeartbeatTimes[$conn] ?? 0.0)) >= $this->heartbeatInterval;
                if ($shouldProbe) {
                    try {
                        ($this->connectionHeartbeatHandler)($conn);
                        $this->lastHeartbeatTimes[$conn] = microtime(true);
                    } catch (Throwable) {
                        $this->closeConnection($conn);
                        $this->nonCoroutineConnection = null;
                    }
                }
            }
            if ($this->nonCoroutineConnection === null) {
                $this->nonCoroutineConnection = $this->createConnection();
            }
            $this->connectionStatus[$this->nonCoroutineConnection] = ConnectionStatus::Active;
            return $this->nonCoroutineConnection;
        }

        $num = $this->channel->length();
        if ($num === 0 && $this->getConnectionCount() < $this->maxConnections) {
            // 预占槽位，消除"判定与插入之间的并发窗口"
            $placeholder = new stdClass();
            $this->connections[$placeholder] = true;
            try {
                $connection = $this->instantiateConnection();
                unset($this->connections[$placeholder]);
                $this->registerConnection($connection);
                return $connection;
            } catch (Throwable $e) {
                unset($this->connections[$placeholder]);
                throw $e;
            }
        }

        $connection = $this->channel->pop($this->waitTimeout);
        if (!$connection) {
            throw new PoolException(
                "Failed to get a connection from the pool within the wait timeout ({$this->waitTimeout} seconds). The connection pool is exhausted."
            );
        }

        $this->lastUsedTimes[$connection] = microtime(true);
        $this->connectionStatus[$connection] = ConnectionStatus::Active;
        return $connection;
    }

    /**
     * Put connection back to pool.
     */
    public function put(object $connection): void
    {
        if (!isset($this->connections[$connection])) {
            throw new PoolException('The connection does not belong to the connection pool.');
        }

        // 幂等防护：已处于 Idle 状态说明已被归还过，重复 push 会导致同一连接被两个协程同时借出
        if ($this->getConnectionStatus($connection) === ConnectionStatus::Idle) {
            return;
        }

        if ($connection === $this->nonCoroutineConnection) {
            $this->connectionStatus[$connection] = ConnectionStatus::Idle;
            return;
        }

        try {
            $this->connectionStatus[$connection] = ConnectionStatus::Idle;
            $this->channel->push($connection);
        } catch (Throwable $throwable) {
            $this->closeConnection($connection);
            throw $throwable;
        }
    }

    /**
     * Create a new connection.
     */
    public function createConnection(): object
    {
        if ($this->getConnectionCount() >= $this->maxConnections) {
            throw new PoolException('CreateConnection failed, maximum connection limit reached.');
        }

        // Reserve a slot with a placeholder
        $placeholder = new stdClass();
        $this->connections[$placeholder] = true;

        try {
            $connection = $this->instantiateConnection();
            unset($this->connections[$placeholder]);
            $this->registerConnection($connection);
        } catch (Throwable $throwable) {
            unset($this->connections[$placeholder]);
            throw $throwable;
        }

        return $connection;
    }

    /**
     * Instantiate connection via creator handler (shared by createConnection and get).
     */
    private function instantiateConnection(): object
    {
        if ($this->connectionCreateHandler === null) {
            throw new PoolException('CreateConnection failed, no connection creator set.');
        }

        $connection = ($this->connectionCreateHandler)();

        if (!is_object($connection)) {
            throw new PoolException(
                'CreateConnection failed, expected a connection object, but got ' . gettype($connection) . '.'
            );
        }

        return $connection;
    }

    /**
     * Register a new connection into pool tracking structures.
     */
    private function registerConnection(object $connection): void
    {
        $now = microtime(true);
        $this->connections[$connection] = true;
        $this->lastUsedTimes[$connection] = $now;
        $this->lastHeartbeatTimes[$connection] = $now;
        $this->createdAt[$connection] = $now;
        $this->connectionStatus[$connection] = ConnectionStatus::Active;
    }

    /**
     * Close the connection and remove it from the pool.
     */
    public function closeConnection(object $connection): void
    {
        if (!isset($this->connections[$connection])) {
            return;
        }

        unset(
            $this->connections[$connection],
            $this->lastUsedTimes[$connection],
            $this->lastHeartbeatTimes[$connection],
            $this->createdAt[$connection],
            $this->connectionStatus[$connection],
        );

        if ($this->nonCoroutineConnection === $connection) {
            $this->nonCoroutineConnection = null;
        }

        $this->connectionDestroyHandler?->__invoke($connection);
    }

    /**
     * Get the number of connections in the pool.
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * Close all connections in the pool.
     * Closes both idle connections in the channel and active (borrowed) connections.
     */
    public function closeConnections(): void
    {
        // 先清空 nonCoroutineConnection 引用，避免后续 foreach 重复关闭
        $this->nonCoroutineConnection = null;

        // Close idle connections from the channel
        $num = $this->channel->length();
        for ($i = $num; $i > 0; $i--) {
            $connection = $this->channel->pop(0.001);
            if (!$connection) {
                break;
            }
            $this->closeConnection($connection);
        }

        // Close active (borrowed) connections tracked in the WeakMap
        // Copy keys first — modifying a WeakMap during iteration skips entries
        $connections = [];
        foreach ($this->connections as $connection => $_) {
            $connections[] = $connection;
        }
        foreach ($connections as $connection) {
            $this->closeConnection($connection);
        }
    }

    /**
     * Get the status of a connection.
     */
    public function getConnectionStatus(object $connection): ConnectionStatus
    {
        return $this->connectionStatus[$connection] ?? ConnectionStatus::Closed;
    }

    /**
     * Check if currently in a coroutine context.
     */
    protected function isCoroutine(): bool
    {
        return $this->forceCoroutineMode || (class_exists(Fiber::class) && Fiber::getCurrent() !== null);
    }

    /**
     * Log a message.
     */
    protected function log(mixed $message): void
    {
        try {
            \LarkFrame\Log::info((string)$message);
        } catch (Throwable) {
            // Fallback to error_log if logger is unavailable
            error_log((string)$message);
        }
    }

    /**
     * Start maintenance timers for idle connection recycling and heartbeat checks.
     * Should be called after the event loop is available (e.g., in onWorkerStart).
     */
    public function startMaintenance(EventInterface $eventLoop): void
    {
        // Idle connection recycling timer
        if ($this->idleTimeout > 0) {
            $checkInterval = max($this->idleTimeout / 2, 10.0);
            $this->idleCheckTimerId = $eventLoop->repeat($checkInterval, function (): void {
                $this->recycleIdleConnections();
            });
        }

        // Heartbeat check timer
        if ($this->heartbeatInterval > 0 && $this->connectionHeartbeatHandler !== null) {
            $this->heartbeatTimerId = $eventLoop->repeat($this->heartbeatInterval, function (): void {
                $this->checkHeartbeats();
            });
        }
    }

    /**
     * Stop maintenance timers.
     */
    public function stopMaintenance(EventInterface $eventLoop): void
    {
        if ($this->idleCheckTimerId !== null) {
            $eventLoop->offRepeat($this->idleCheckTimerId);
            $this->idleCheckTimerId = null;
        }
        if ($this->heartbeatTimerId !== null) {
            $eventLoop->offRepeat($this->heartbeatTimerId);
            $this->heartbeatTimerId = null;
        }
    }

    /**
     * Recycle idle connections that have exceeded the idle timeout.
     * Only closes idle connections that are in the channel (not active/borrowed).
     */
    protected function recycleIdleConnections(): void
    {
        $now = microtime(true);

        // Drain the channel, check each connection, and put back non-expired ones
        $num = $this->channel->length();
        for ($i = 0; $i < $num; $i++) {
            $connection = $this->channel->pop(0.001);
            if (!$connection) {
                break;
            }

            // closeConnection 会从 WeakMap 移除，getConnectionCount() 反映真实剩余数
            if ($this->getConnectionCount() <= $this->minConnections) {
                $this->channel->push($connection);
                continue;
            }

            $lastUsed = $this->lastUsedTimes[$connection] ?? $now;
            $idleSeconds = $now - $lastUsed;

            if ($idleSeconds < $this->idleTimeout) {
                $this->channel->push($connection);
            } else {
                $this->closeConnection($connection);
                $this->log("Recycled idle connection (idle {$idleSeconds}s)");
            }
        }
    }

    /**
     * Check heartbeats of idle connections.
     * Connections that fail heartbeat are closed and removed.
     */
    protected function checkHeartbeats(): void
    {
        if ($this->connectionHeartbeatHandler === null) {
            return;
        }

        $now = microtime(true);

        // Drain the channel, check heartbeats, and put back healthy ones
        $num = $this->channel->length();
        for ($i = 0; $i < $num; $i++) {
            $connection = $this->channel->pop(0.001);
            if (!$connection) {
                break;
            }

            $lastHeartbeat = $this->lastHeartbeatTimes[$connection] ?? $now;
            $elapsed = $now - $lastHeartbeat;

            if ($elapsed < $this->heartbeatInterval) {
                $this->channel->push($connection);
                continue;
            }

            // Perform heartbeat check
            try {
                ($this->connectionHeartbeatHandler)($connection);
                $this->lastHeartbeatTimes[$connection] = $now;
                $this->channel->push($connection);
            } catch (Throwable $e) {
                $this->closeConnection($connection);
                $this->log("Heartbeat failed, closed connection: " . $e->getMessage());
            }
        }
    }
}
