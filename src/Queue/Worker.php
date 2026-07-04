<?php

namespace LarkFrame\Queue;

use LarkFrame\Log;
use LarkFrame\Queue\Job;
use Throwable;
use function function_exists;
use function microtime;
use function min;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function usleep;
use const SIGINT;
use const SIGQUIT;
use const SIGTERM;

/**
 * 队列消费者（Worker）
 *
 * 从队列中持续弹出任务并执行。
 */
class Worker
{
    protected QueueInterface $queue;

    protected int $maxTries = 3;

    protected int $sleep = 1;

    protected int $memoryLimit = 128;

    protected bool $shouldQuit = false;

    /**
     * pop 失败退避秒数（指数退避上限 30s）。
     */
    protected int $backoffSeconds = 1;

    public function __construct(QueueInterface $queue, array $config = [])
    {
        $this->queue = $queue;
        $this->maxTries = $config['max_tries'] ?? 3;
        $this->sleep = $config['sleep'] ?? 1;
        $this->memoryLimit = $config['memory_limit'] ?? 128;
    }

    /**
     * 启动消费
     */
    public function daemon(string $queue = 'default'): void
    {
        $this->installSignalHandlers();

        while (!$this->shouldQuit) {
            try {
                $this->runNextJob($queue);
            } catch (Throwable $e) {
                Log::error("Worker loop error: " . $e->getMessage());
            }

            if ($this->memoryExceeded()) {
                $this->stop();
            }
        }
    }

    /**
     * 注册信号处理，实现优雅退出
     */
    protected function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn() => $this->shouldQuit = true);
        pcntl_signal(SIGINT, fn() => $this->shouldQuit = true);
        pcntl_signal(SIGQUIT, fn() => $this->shouldQuit = true);
    }

    /**
     * 处理下一个任务
     */
    public function runNextJob(string $queue = 'default'): void
    {
        try {
            $job = $this->queue->pop($queue);
        } catch (Throwable $e) {
            Log::error("Queue pop failed: " . $e->getMessage());
            // 指数退避：1s → 2s → 4s → 8s → 上限 30s，避免 Redis 故障时雪崩
            $this->backoffSeconds = min($this->backoffSeconds * 2, 30);
            $this->coroutineSleep($this->backoffSeconds);
            return;
        }

        // pop 成功重置退避
        $this->backoffSeconds = 1;

        if ($job === null) {
            $this->coroutineSleep($this->sleep);
            return;
        }

        try {
            $this->process($job);
        } catch (Throwable $e) {
            $this->handleException($job, $e);
        }
    }

    /**
     * 协程友好的 sleep：100ms 粒度让出调度器，允许信号处理与协程切换
     */
    protected function coroutineSleep(int $seconds): void
    {
        $end = microtime(true) + $seconds;
        while (microtime(true) < $end && !$this->shouldQuit) {
            usleep(100_000);
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * 处理单个任务
     */
    protected function process(Job $job): void
    {
        if ($job->hasExceededMaxTries($this->maxTries)) {
            $job->fail();
            Log::warning("Job {$job->getName()} exceeded max tries ({$this->maxTries}), marked as failed");
            return;
        }

        $job->fire();
        $job->ack();
    }

    /**
     * 处理任务异常
     */
    protected function handleException(Job $job, Throwable $e): void
    {
        Log::error("Job {$job->getName()} failed: " . $e->getMessage(), ['exception' => $e]);

        if ($job->hasExceededMaxTries($this->maxTries)) {
            $job->fail($e);
        } else {
            $job->release($this->sleep);
        }
    }

    /**
     * 检查内存是否超限
     */
    protected function memoryExceeded(): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) > $this->memoryLimit;
    }

    /**
     * 停止消费
     */
    public function stop(): void
    {
        $this->shouldQuit = true;
    }
}
