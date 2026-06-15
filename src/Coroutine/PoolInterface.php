<?php

namespace LarkFrame\Coroutine;

/**
 * Interface PoolInterface
 *
 * Defines the contract for connection pool implementations.
 */
interface PoolInterface
{
    /**
     * Get a connection from the pool.
     */
    public function get(): mixed;

    /**
     * Put a connection back to the pool.
     */
    public function put(object $connection): void;

    /**
     * Create a new connection.
     */
    public function createConnection(): object;

    /**
     * Close the connection and remove it from the connection pool.
     */
    public function closeConnection(object $connection): void;

    /**
     * Get the number of connections in the connection pool.
     */
    public function getConnectionCount(): int;

    /**
     * Close all connections in the connection pool.
     */
    public function closeConnections(): void;
}
