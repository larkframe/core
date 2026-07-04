<?php

namespace LarkFrame\Coroutine;

use Fiber;
use SplQueue;
use Throwable;

/**
 * Class MemoryChannel
 *
 * In-memory channel implementation with coroutine-aware blocking support.
 * When a pop is called on an empty channel within a Fiber, it will yield
 * and retry instead of busy-waiting. In non-Fiber contexts, it falls back
 * to a timed sleep-based wait.
 */
class MemoryChannel implements ChannelInterface
{
    private readonly SplQueue $queue;
    private readonly int $capacity;
    private bool $closed = false;

    /**
     * Waiters for pop operations (Fibers waiting for data).
     *
     * @var Fiber[]
     */
    private array $popWaiters = [];

    /**
     * Waiters for push operations (Fibers waiting for space).
     *
     * @var Fiber[]
     */
    private array $pushWaiters = [];

    /**
     * Default poll interval in microseconds for non-Fiber contexts.
     */
    private const POLL_INTERVAL_US = 1000;

    public function __construct(int $capacity = 0)
    {
        $this->capacity = $capacity;
        $this->queue = new SplQueue();
    }

    public function push(mixed $data, float $timeout = -1): bool
    {
        if ($this->closed) {
            return false;
        }

        $deadline = $timeout >= 0 ? microtime(true) + $timeout : PHP_FLOAT_MAX;

        if ($this->capacity === 0) {
            // Rendezvous mode: push completes only when a pop consumes the data
            $this->queue->enqueue($data);
            $this->wakePopWaiter();
            while (!$this->queue->isEmpty()) {
                if ($this->closed) {
                    return false;
                }
                if (microtime(true) >= $deadline) {
                    return false;
                }
                if (Fiber::getCurrent() !== null) {
                    $this->pushWaiters[] = Fiber::getCurrent();
                    Fiber::suspend();
                } else {
                    usleep(self::POLL_INTERVAL_US);
                }
            }
            return true;
        }

        while ($this->queue->count() >= $this->capacity) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            if (Fiber::getCurrent() !== null) {
                $this->pushWaiters[] = Fiber::getCurrent();
                Fiber::suspend();
            } else {
                usleep(self::POLL_INTERVAL_US);
            }

            if ($this->closed) {
                return false;
            }
        }

        $this->queue->enqueue($data);
        $this->wakePopWaiter();

        return true;
    }

    /**
     * Wake up a pop waiter if any.
     */
    private function wakePopWaiter(): void
    {
        while ($this->popWaiters !== []) {
            $waiter = array_shift($this->popWaiters);
            if ($waiter->isSuspended()) {
                $waiter->resume();
                break;
            }
        }
    }

    public function pop(float $timeout = -1): mixed
    {
        $deadline = $timeout >= 0 ? microtime(true) + $timeout : PHP_FLOAT_MAX;

        while ($this->queue->isEmpty()) {
            if ($this->closed) {
                return false;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            if (Fiber::getCurrent() !== null) {
                // In Fiber context: suspend and wait to be resumed
                $this->popWaiters[] = Fiber::getCurrent();
                Fiber::suspend();
            } else {
                // In non-Fiber context: sleep briefly and retry
                usleep(self::POLL_INTERVAL_US);
            }
        }

        if ($this->queue->isEmpty()) {
            return false;
        }

        $data = $this->queue->dequeue();

        // Wake up a push waiter if any
        while ($this->pushWaiters !== []) {
            $waiter = array_shift($this->pushWaiters);
            if ($waiter->isSuspended()) {
                $waiter->resume();
                break;
            }
        }

        return $data;
    }

    public function length(): int
    {
        return $this->queue->count();
    }

    public function getCapacity(): int
    {
        return $this->capacity;
    }

    public function close(): void
    {
        $this->closed = true;

        // Wake up all waiting fibers (skip already-resumed ones)
        foreach ($this->popWaiters as $waiter) {
            if ($waiter->isSuspended()) {
                $waiter->resume();
            }
        }
        foreach ($this->pushWaiters as $waiter) {
            if ($waiter->isSuspended()) {
                $waiter->resume();
            }
        }
        $this->popWaiters = [];
        $this->pushWaiters = [];
    }

    /**
     * P2-26/28：检查通道是否已关闭。
     *
     * push/pop 返回 false 时，调用方可用 isClosed() 区分：
     *   - isClosed() === true  → 通道已关闭
     *   - isClosed() === false → 超时（仍有数据/空间但未在 timeout 内完成）
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }
}
