<?php

namespace LarkFrame\Coroutine;

use Closure;
use Fiber;
use LarkFrame\Events\EventInterface;
use stdClass;
use Throwable;
use WeakMap;

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
     * Constructor with property promotion for config values.
     */
    public function __construct(
        private readonly int $maxConnections = 1,
        private readonly array $config = [],
        private readonly int $minConnections = 1,
        private readonly float $idleTimeout = 60.0,
        private readonly float $heartbeatInterval = 50.0,
        private readonly float $waitTimeout = 10.0,
    ) {
        $this->channel = new MemoryChannel($maxConnections);
        $this->connections = new WeakMap();
        $this->lastUsedTimes = new WeakMap();
        $this->lastHeartbeatTimes = new WeakMap();
        $this->connectionStatus = new WeakMap();
        $this->createdAt = new WeakMap();
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

        return new self(
            maxConnections: $maxConnections,
            config: $config,
            minConnections: (int)($camelCased['minConnections'] ?? 1),
            idleTimeout: (float)($camelCased['idleTimeout'] ?? 60.0),
            heartbeatInterval: (float)($camelCased['heartbeatInterval'] ?? 50.0),
            waitTimeout: (float)($camelCased['waitTimeout'] ?? 10.0),
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
        // Non-coroutine: reuse a single connection
        if (!$this->isCoroutine()) {
            if (!$this->nonCoroutineConnection) {
                $this->nonCoroutineConnection = $this->createConnection();
                $this->connections[$this->nonCoroutineConnection] = 1;
            }
            $this->connectionStatus[$this->nonCoroutineConnection] = ConnectionStatus::Active;
            return $this->nonCoroutineConnection;
        }

        $num = $this->channel->length();
        if ($num === 0 && $this->getConnectionCount() < $this->maxConnections) {
            return $this->createConnection();
        }

        $connection = $this->channel->pop($this->waitTimeout);
        if (!$connection) {
            throw new PoolException(
                "Failed to get a connection from the pool within the wait timeout ({$this->waitTimeout} seconds). The connection pool is exhausted."
            );
        }

        $this->lastUsedTimes[$connection] = time();
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
            if ($this->connectionCreateHandler === null) {
                throw new PoolException('CreateConnection failed, no connection creator set.');
            }

            $connection = ($this->connectionCreateHandler)();

            if (!is_object($connection)) {
                throw new PoolException(
                    'CreateConnection failed, expected a connection object, but got ' . gettype($connection) . '.'
                );
            }

            unset($this->connections[$placeholder]);

            $now = time();
            $this->connections[$connection] = true;
            $this->lastUsedTimes[$connection] = $now;
            $this->lastHeartbeatTimes[$connection] = $now;
            $this->createdAt[$connection] = $now;
            $this->connectionStatus[$connection] = ConnectionStatus::Active;
        } catch (Throwable $throwable) {
            unset($this->connections[$placeholder]);
            throw $throwable;
        }

        return $connection;
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
        foreach ($this->connections as $connection => $_) {
            $this->closeConnection($connection);
        }

        if ($this->nonCoroutineConnection !== null) {
            $this->closeConnection($this->nonCoroutineConnection);
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
            // Fallback to echo if logger is unavailable
            echo $message . PHP_EOL;
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
        $now = time();
        $checked = [];

        // Drain the channel, check each connection, and put back non-expired ones
        $num = $this->channel->length();
        for ($i = 0; $i < $num; $i++) {
            $connection = $this->channel->pop(0.001);
            if (!$connection) {
                break;
            }

            $lastUsed = $this->lastUsedTimes[$connection] ?? $now;
            $idleSeconds = $now - $lastUsed;

            // Keep minimum connections alive
            $activeCount = $this->getConnectionCount() - count($checked);
            if ($idleSeconds < $this->idleTimeout || $activeCount <= $this->minConnections) {
                $this->channel->push($connection);
                $checked[] = $connection;
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

        $now = time();
        $checked = [];

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
                $checked[] = $connection;
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
