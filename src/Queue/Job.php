<?php

namespace LarkFrame\Queue;

/**
 * 队列任务对象
 */
class Job
{
    public function __construct(
        protected QueueInterface $queue,
        protected string $queueName,
        protected array $payload,
        protected ?string $rawPayload = null
    ) {}

    /**
     * 获取任务 ID
     */
    public function getId(): string
    {
        return $this->payload['id'] ?? '';
    }

    /**
     * 获取任务名称（类名或标识）
     */
    public function getName(): string
    {
        return $this->payload['job'] ?? '';
    }

    /**
     * 获取任务数据
     */
    public function getData(): mixed
    {
        return $this->payload['data'] ?? '';
    }

    /**
     * 获取已尝试次数
     */
    public function getAttempts(): int
    {
        return $this->payload['attempts'] ?? 0;
    }

    /**
     * 获取队列名称
     */
    public function getQueue(): string
    {
        return $this->queueName;
    }

    /**
     * 获取完整 payload
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * 获取原始 JSON payload
     * 优先返回构造时传入的 rawPayload（Lua cjson.encode 产物），保证与 reserved ZSET 成员精确匹配
     */
    public function getRawPayload(): string
    {
        return $this->rawPayload ?? json_encode($this->payload, JSON_THROW_ON_ERROR);
    }

    /**
     * 确认任务完成
     */
    public function ack(): void
    {
        $this->queue->ack($this);
    }

    /**
     * 标记任务失败
     */
    public function fail(?\Throwable $exception = null): void
    {
        $this->queue->fail($this, $exception);
    }

    /**
     * 重新释放任务到队列
     */
    public function release(int $delay = 0): void
    {
        $this->queue->release($this, $delay);
    }

    /**
     * 是否超过最大重试次数
     */
    public function hasExceededMaxTries(int $maxTries): bool
    {
        return $this->getAttempts() >= $maxTries;
    }

    /**
     * 执行任务
     *
     * @throws \Throwable
     */
    public function fire(): mixed
    {
        $job = $this->payload['job'] ?? null;

        if ($job === null) {
            throw new \RuntimeException('Job payload missing "job" key');
        }

        // If job is a serialized closure or object
        if (!class_exists($job)) {
            // 对象任务反序列化默认拒绝任意类实例化（防 RCE），仅允许数组/标量；
            // 确有需要反序列化对象任务时，通过 queue.allowed_job_classes 显式声明类白名单
            $allowedClasses = (array) \config('queue.allowed_job_classes', []);
            $instance = unserialize($job, ['allowed_classes' => $allowedClasses]);
            if ($instance === false && $job !== 'b:0;') {
                throw new \RuntimeException("Unable to unserialize job");
            }
            if (is_callable($instance)) {
                return $instance($this);
            }
            throw new \RuntimeException("Unserialized job is not callable");
        }

        // Job is a class name — instantiate and call handle()
        $instance = new $job();
        if (method_exists($instance, 'handle')) {
            return $instance->handle($this, $this->getData());
        }

        throw new \RuntimeException("Job class {$job} must implement a handle() method");
    }
}
