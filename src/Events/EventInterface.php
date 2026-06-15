<?php

namespace LarkFrame\Events;

/**
 * Interface EventInterface
 *
 * Event loop interface for async I/O operations.
 */
interface EventInterface
{
    /**
     * Delay the execution of a callback.
     */
    public function delay(float $delay, callable $func, array $args = []): int;

    /**
     * Delete a delay timer.
     */
    public function offDelay(int $timerId): bool;

    /**
     * Repeatedly execute a callback.
     */
    public function repeat(float $interval, callable $func, array $args = []): int;

    /**
     * Delete a repeat timer.
     */
    public function offRepeat(int $timerId): bool;

    /**
     * Execute a callback when a stream resource becomes readable.
     */
    public function onReadable(mixed $stream, callable $func): void;

    /**
     * Cancel a callback of stream readable.
     */
    public function offReadable(mixed $stream): bool;

    /**
     * Execute a callback when a stream resource becomes writable.
     */
    public function onWritable(mixed $stream, callable $func): void;

    /**
     * Cancel a callback of stream writable.
     */
    public function offWritable(mixed $stream): bool;

    /**
     * Execute a callback when a signal is received.
     */
    public function onSignal(int $signal, callable $func): void;

    /**
     * Cancel a callback of signal.
     */
    public function offSignal(int $signal): bool;

    /**
     * Delete all timers.
     */
    public function deleteAllTimer(): void;

    /**
     * Run the event loop.
     */
    public function run(): void;

    /**
     * Stop event loop.
     */
    public function stop(): void;

    /**
     * Get timer count.
     */
    public function getTimerCount(): int;

    /**
     * Set error handler.
     */
    public function setErrorHandler(callable $errorHandler): void;
}
