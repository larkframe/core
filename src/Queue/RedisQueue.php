<?php

namespace LarkFrame\Queue;

use LarkFrame\Cache\Redis;
use LarkFrame\Queue\Job;

/**
 * Redis 队列驱动
 *
 * 基于 Redis List 实现的队列，支持延迟推送和消息确认。
 */
class RedisQueue implements QueueInterface
{
    protected string $defaultQueue = 'default';

    protected int $retryAfter = 60;

    protected int $maxTries = 3;

    public function __construct(array $config = [])
    {
        $this->defaultQueue = $config['default'] ?? 'default';
        $this->retryAfter = $config['retry_after'] ?? 60;
        $this->maxTries = $config['max_tries'] ?? 3;
    }

    public function push(string $queue, mixed $job, mixed $data = '', int $delay = 0): string
    {
        $payload = $this->createPayload($job, $data);
        $queue = $this->getQueue($queue);

        if ($delay > 0) {
            return $this->laterRaw($queue, $delay, $payload);
        }

        Redis::rPush($queue, $payload);
        return json_decode($payload, true)['id'] ?? '';
    }

    public function later(string $queue, int $delay, mixed $job, mixed $data = ''): string
    {
        $payload = $this->createPayload($job, $data);
        return $this->laterRaw($this->getQueue($queue), $delay, $payload);
    }

    public function pop(string $queue): ?Job
    {
        $queue = $this->getQueue($queue);

        // Move expired delayed jobs to main queue
        $this->migrateExpiredJobs($queue . ':delayed', $queue);

        // Move reserved jobs that have timed out back to main queue
        $this->migrateExpiredJobs($queue . ':reserved', $queue);

        $payload = Redis::lPop($queue);
        if (!$payload) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!$data) {
            return null;
        }

        // Mark as reserved
        $data['reserved_at'] = time();
        $data['expires_at'] = time() + $this->retryAfter;
        Redis::zAdd($queue . ':reserved', $data['expires_at'], json_encode($data));

        return new Job($this, $queue, $data);
    }

    public function ack(Job $job): void
    {
        $reservedQueue = $job->getQueue() . ':reserved';
        // Remove from reserved set
        Redis::zRem($reservedQueue, $job->getRawPayload());
    }

    public function fail(Job $job, ?\Throwable $exception = null): void
    {
        $this->ack($job);

        $payload = $job->getPayload();
        $payload['failed_at'] = time();
        if ($exception) {
            $payload['error'] = $exception->getMessage();
        }

        Redis::rPush($job->getQueue() . ':failed', json_encode($payload));
    }

    public function release(Job $job, int $delay = 0): void
    {
        $this->ack($job);

        $payload = $job->getPayload();
        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
        $payload['reserved_at'] = null;
        $payload['expires_at'] = null;

        $encoded = json_encode($payload);

        if ($delay > 0) {
            Redis::zAdd($job->getQueue() . ':delayed', time() + $delay, $encoded);
        } else {
            Redis::rPush($job->getQueue(), $encoded);
        }
    }

    public function size(string $queue): int
    {
        $queue = $this->getQueue($queue);
        return Redis::lLen($queue) + Redis::zCard($queue . ':delayed') + Redis::zCard($queue . ':reserved');
    }

    public function getFailedJobs(string $queue): array
    {
        $queue = $this->getQueue($queue);
        $items = Redis::lRange($queue . ':failed', 0, -1);
        return array_map(fn($item) => json_decode($item, true), $items);
    }

    public function retryFailed(string $queue, int $index): bool
    {
        $queue = $this->getQueue($queue);
        $failedQueue = $queue . ':failed';

        $items = Redis::lRange($failedQueue, $index, $index);
        if (empty($items)) {
            return false;
        }

        $payload = json_decode($items[0], true);
        $payload['attempts'] = 0;
        $payload['reserved_at'] = null;
        $payload['expires_at'] = null;
        unset($payload['failed_at'], $payload['error']);

        Redis::lRem($failedQueue, $items[0], 1);
        Redis::rPush($queue, json_encode($payload));
        return true;
    }

    public function clear(string $queue): void
    {
        $queue = $this->getQueue($queue);
        Redis::del($queue, $queue . ':delayed', $queue . ':reserved', $queue . ':failed');
    }

    protected function laterRaw(string $queue, int $delay, string $payload): string
    {
        Redis::zAdd($queue . ':delayed', time() + $delay, $payload);
        return json_decode($payload, true)['id'] ?? '';
    }

    protected function migrateExpiredJobs(string $from, string $to): void
    {
        $now = time();
        $options = ['LIMIT' => [0, 100]];

        $jobs = Redis::zRangeByScore($from, '-inf', $now, $options);
        if (empty($jobs)) {
            return;
        }

        foreach ($jobs as $job) {
            Redis::zRem($from, $job);
            Redis::rPush($to, $job);
        }
    }

    protected function createPayload(mixed $job, mixed $data = ''): string
    {
        return json_encode([
            'id' => $this->generateId(),
            'job' => is_string($job) ? $job : serialize($job),
            'data' => $data,
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
    }

    protected function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    protected function getQueue(string $queue): string
    {
        return 'queue:' . ($queue ?: $this->defaultQueue);
    }
}
