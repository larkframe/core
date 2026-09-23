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
     * 已创建的池实例（弱引用注册表，不阻止 GC）。
     * 供 Worker 在进程退出前统一释放连接与维护定时器，见 closeAllInstances()。
     *
     * @var WeakMap<self, true>|null
     */
    private static ?WeakMap $instances = null;

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

        self::$instances ??= new WeakMap();
        self::$instances[$this] = true;
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
            if ($this->nonCoroutineConnection !== null) {
                // 失效连接由 closeConnection 内部置空 nonCoroutineConnection
                $this->probeOnAcquire($this->nonCoroutineConnection);
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

        // 仅真 Fiber 环境可挂起等待；非 Fiber 下 channel 的等待由 usleep 轮询实现，
        // 会阻塞整个事件循环直到 waitTimeout——而同步上下文里不可能"稍后有人归还"
        // （同一时刻只有一个执行流），等待没有收益。故改为非阻塞取用 + 立即失败：
        // 池耗尽快速暴露并给出诊断，而不是拖垮整个 worker 的事件循环。
        $canSuspend = $this->canSuspend();
        $connection = $this->channel->pop($canSuspend ? $this->waitTimeout : 0.0);
        if (!$connection) {
            throw new PoolException($canSuspend
                ? "Failed to get a connection from the pool within the wait timeout ({$this->waitTimeout} seconds). The connection pool is exhausted."
                : 'No idle connection available and the pool has reached max_connections. '
                    . 'Waiting is not possible outside a Fiber context because it would block the event loop. '
                    . 'This usually indicates leaked connections (borrowed but never returned) or an undersized pool.');
        }

        // 协程路径同样需要借出前校验：空闲连接的心跳只在定时器中做（间隔
        // heartbeat_interval），借出期间被服务端 wait_timeout/网络中断断开的连接
        // 会原样交给调用方，表现为 MySQL "server has gone away" / Redis 连接错误。
        // 与新建路径一致：失效连接关闭后重建，计数已减 1 故不会触及 maxConnections 上限。
        if (!$this->probeOnAcquire($connection)) {
            $connection = $this->createConnection();
        }

        $this->lastUsedTimes[$connection] = microtime(true);
        $this->connectionStatus[$connection] = ConnectionStatus::Active;
        return $connection;
    }

    /**
     * 借出前按 heartbeat_interval 节流做活性探测，剔除已被服务端关闭的死连接。
     *
     * 节流是必要的：连接刚建立/刚探测过（lastHeartbeatTimes 由 registerConnection 初始化）
     * 时无需再探测，否则等于给每次借出多加一次网络往返（Redis PING / MySQL ping）。
     * heartbeat_interval <= 0 视为未配置节流，每次借出都探测，不引入活性校验空窗。
     *
     * @return bool true=连接可用；false=连接已失效并被移出池（调用方需重建）
     */
    protected function probeOnAcquire(object $connection): bool
    {
        if ($this->connectionHeartbeatHandler === null) {
            return true;
        }

        $now = microtime(true);
        if ($this->heartbeatInterval > 0
            && ($now - ($this->lastHeartbeatTimes[$connection] ?? 0.0)) < $this->heartbeatInterval) {
            return true;
        }

        try {
            ($this->connectionHeartbeatHandler)($connection);
            $this->lastHeartbeatTimes[$connection] = $now;
            return true;
        } catch (Throwable $e) {
            $this->closeConnection($connection);
            $this->log("Stale connection discarded on acquire: " . $e->getMessage());
            return false;
        }
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
     * 当前是否处于可挂起的 Fiber 环境。
     *
     * 与 isCoroutine() 的区别：后者决定"走池还是走单连接"（Server 模式恒为池），
     * 本方法只回答"能否挂起等待"，二者不可互相替代。
     */
    protected function canSuspend(): bool
    {
        return class_exists(Fiber::class) && Fiber::getCurrent() !== null;
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
     * 释放所有池实例：停止维护定时器并关闭全部连接。
     *
     * 由 Worker 在进程退出前调用（见 Worker::exitWorker）。必要性有两点：
     *   1. 优雅关闭的等待窗口内维护定时器仍在事件循环中，会继续对空闲连接发心跳；
     *   2. 不发关闭命令直接退出，会让服务端把连接挂到各自的 wait_timeout 才回收
     *      （多 worker 滚动重启时表现为服务端连接数堆积）。
     */
    public static function closeAllInstances(): void
    {
        if (self::$instances === null) {
            return;
        }

        $eventLoop = \LarkFrame\Worker::$globalEvent;
        foreach (self::$instances as $pool => $_) {
            try {
                if ($eventLoop !== null) {
                    $pool->stopMaintenance($eventLoop);
                }
                $pool->closeConnections();
            } catch (Throwable $e) {
                // 单个池释放失败不应阻断其他池与后续退出流程
                $pool->log('Failed to close connection pool on shutdown: ' . $e->getMessage());
            }
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
