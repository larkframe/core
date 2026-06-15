<?php

namespace LarkFrame\Queue;

use LarkFrame\Log;
use LarkFrame\Queue\Job;

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
        while (!$this->shouldQuit) {
            $this->runNextJob($queue);

            if ($this->memoryExceeded()) {
                $this->stop();
            }
        }
    }

    /**
     * 处理下一个任务
     */
    public function runNextJob(string $queue = 'default'): void
    {
        $job = $this->queue->pop($queue);

        if ($job === null) {
            sleep($this->sleep);
            return;
        }

        try {
            $this->process($job);
        } catch (\Throwable $e) {
            $this->handleException($job, $e);
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
    protected function handleException(Job $job, \Throwable $e): void
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
