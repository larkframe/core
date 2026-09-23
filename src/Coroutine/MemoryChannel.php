<?php

namespace LarkFrame\Coroutine;

use Fiber;
use SplQueue;

/**
 * Class MemoryChannel
 *
 * In-memory channel implementation with coroutine-aware blocking support.
 *
 * 超时唤醒契约：Fiber 内 pop/push 挂起时，会向 Worker::$globalEvent 注册
 * 一次性 delay 定时器，deadline 到达后 resume 该 Fiber（若仍处于挂起态）。
 * 无事件循环的裸 Fiber 调度场景（用户自管调度器）无法注册定时器，
 * 挂起协程只能被后续 push/pop/close 唤醒——timeout 在该场景不生效。
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
                    // close 语义为丢弃通道内一切数据，残留可接受
                    return false;
                }
                if (microtime(true) >= $deadline) {
                    return $this->revokeRendezvousData($data);
                }
                $this->suspendUntil($this->pushWaiters, $deadline);
            }
            return true;
        }

        while ($this->queue->count() >= $this->capacity) {
            if (microtime(true) >= $deadline) {
                return false;
            }

            $this->suspendUntil($this->pushWaiters, $deadline);

            if ($this->closed) {
                return false;
            }
        }

        $this->queue->enqueue($data);
        $this->wakePopWaiter();

        return true;
    }

    /**
     * Rendezvous 超时回滚：数据已入队但无人消费时必须撤回，
     * 否则消费方会取到一条 push 方已判定失败的"幽灵"数据。
     */
    private function revokeRendezvousData(mixed $data): bool
    {
        // 队列已空：数据刚好被消费，push 语义上成功
        if ($this->queue->isEmpty()) {
            return true;
        }
        // FIFO 下队头是自己入队的数据则撤回；队头是他人数据说明自己已被消费。
        // 边界：他人推送了与 $data === 全等的值时会被误撤（概率可忽略）
        if ($this->queue->bottom() === $data) {
            $this->queue->dequeue();
            return false;
        }
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

            $this->suspendUntil($this->popWaiters, $deadline);
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

    /**
     * 挂起当前 Fiber 等待唤醒（数据到达 / 空间释放 / 通道关闭 / 超时）。
     *
     * 唤醒来源：
     *   1. wakePopWaiter/wakePushWaiter/close 的 resume
     *   2. deadline 前注册到事件循环的超时定时器 resume（无事件循环时不注册）
     *
     * 醒来后先把自己从等待队列移除——否则超时返回的 Fiber 引用
     * 残留在数组中形成强引用泄漏，且后续 wake 会反复空唤醒。
     * 误唤醒（新一轮等待被上一轮定时器触发）是安全的：
     * 调用方循环条件会重新判定队列状态与 deadline。
     */
    private function suspendUntil(array &$waiters, float $deadline): void
    {
        $fiber = Fiber::getCurrent();
        if ($fiber === null) {
            usleep(self::POLL_INTERVAL_US);
            return;
        }

        $waiters[] = $fiber;

        $loop = null;
        $timerId = null;
        if ($deadline < PHP_FLOAT_MAX && \LarkFrame\Worker::$globalEvent !== null) {
            $loop = \LarkFrame\Worker::$globalEvent;
            $remaining = $deadline - microtime(true);
            if ($remaining > 0) {
                $timerId = $loop->delay($remaining, static function () use ($fiber): void {
                    // 数据先到时 fiber 已被唤醒运行/终止，此时非挂起态，跳过避免 Fatal
                    if ($fiber->isSuspended()) {
                        $fiber->resume();
                    }
                });
            }
        }

        try {
            Fiber::suspend();
        } finally {
            if ($timerId !== null) {
                $loop->offDelay($timerId);
            }
            $idx = array_search($fiber, $waiters, true);
            if ($idx !== false) {
                unset($waiters[$idx]);
            }
        }
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
