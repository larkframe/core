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
                Log::warning("Worker memory limit ({$this->memoryLimit}MB) exceeded, stopping");
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

        $this->processJob($job);
    }

    /**
     * 处理单个已取出的任务（含 max_tries 判定与失败/重试分支）。
     *
     * 不做任何阻塞等待，可安全用于事件循环回调；阻塞式 daemon 循环与事件循环消费任务
     * （如 template 的 ConsumeTask）共用此入口，避免重试逻辑各写一份导致配置失效。
     */
    public function processJob(Job $job): void
    {
        try {
            $this->process($job);
        } catch (Throwable $e) {
            $this->handleException($job, $e);
        }
    }

    /**
     * 分片 sleep：100ms 粒度轮询退出标志，允许信号及时中断休眠。
     * 注意 usleep 本身是阻塞调用，仅在 Swoole runtime hook（SWOOLE_HOOK_SLEEP）开启时才会协程化；
     * 框架事件循环（Task 模式）下请勿在本 Worker 内使用，直接用事件循环定时器。
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
        try {
            // 幂等确认：handler 内部已 ack 时此调用为 no-op
            $job->ack();
        } catch (Throwable $e) {
            // ack 失败（Redis 瞬时故障）≠ 任务失败：任务已成功执行，
            // 若走 handleException 会 release 重投导致重复执行，只记录告警等待人工介入
            Log::error("Job {$job->getName()} executed but ack failed: " . $e->getMessage());
        }
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
            // 空闲轮询间隔同时充当重试延迟（与 Laravel queue:work --sleep 同款约定）：
            // sleep=30 时重试也要等 30s，需要更细粒度重试节奏的调用方请自行 pop/release
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
