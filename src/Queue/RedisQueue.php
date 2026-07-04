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
    /**
     * 原子 pop + reserve Lua 脚本
     * 单次 RTT 完成取出+写入 reserved 集合，避免进程崩溃在两步之间导致任务丢失
     */
    private const POP_AND_RESERVE_LUA = <<<'LUA'
local payload = redis.call('LPOP', KEYS[1])
if not payload then return nil end
local now = tonumber(ARGV[1])
local ttl = now + tonumber(ARGV[2])
local ok, data = pcall(cjson.decode, payload)
if not ok then
    redis.call('RPUSH', KEYS[1] .. ':failed', payload)
    return nil
end
data['reserved_at'] = now
data['expires_at'] = ttl
local encoded = cjson.encode(data)
redis.call('ZADD', KEYS[2], ttl, encoded)
return encoded
LUA;

    /**
     * 原子批量迁移过期任务 Lua 脚本
     * 单次 RTT 完成读取+删除+推送，避免多 Worker 并发迁移导致重复消费
     */
    private const MIGRATE_EXPIRED_LUA = <<<'LUA'
local jobs = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', ARGV[1], 'LIMIT', 0, tonumber(ARGV[2]))
local count = #jobs
if count == 0 then return 0 end
redis.call('ZREMRANGEBYRANK', KEYS[1], 0, count - 1)
for i = 1, count do
    redis.call('RPUSH', KEYS[2], jobs[i])
end
return count
LUA;

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

        // 用 Lua 脚本原子完成 lPop + zAdd(reserved)，避免两步之间进程崩溃导致任务永久丢失
        $payload = Redis::eval(self::POP_AND_RESERVE_LUA, 2, $queue, $queue . ':reserved', time(), $this->retryAfter);
        if (!$payload) {
            return null;
        }

        $data = json_decode($payload, true);
        if (!$data) {
            return null;
        }

        return new Job($this, $queue, $data, $payload);
    }

    public function ack(Job $job): void
    {
        $reservedQueue = $job->getQueue() . ':reserved';
        // Remove from reserved set
        Redis::zRem($reservedQueue, $job->getRawPayload());
    }

    public function fail(Job $job, ?\Throwable $exception = null): void
    {
        $payload = $job->getPayload();
        $payload['failed_at'] = time();
        if ($exception) {
            $payload['error'] = $exception->getMessage();
        }

        Redis::rPush($job->getQueue() . ':failed', json_encode($payload, JSON_THROW_ON_ERROR));
        $this->ack($job);
    }

    public function release(Job $job, int $delay = 0): void
    {
        $payload = $job->getPayload();
        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
        $payload['reserved_at'] = null;
        $payload['expires_at'] = null;

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        if ($delay > 0) {
            Redis::zAdd($job->getQueue() . ':delayed', time() + $delay, $encoded);
        } else {
            Redis::rPush($job->getQueue(), $encoded);
        }
        $this->ack($job);
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
        Redis::rPush($queue, json_encode($payload, JSON_THROW_ON_ERROR));
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
        // 用 Lua 脚本原子完成 zRangeByScore + zRem + rPush，避免并发迁移导致重复消费
        // 批量上限 100 防止单次操作过长阻塞 Redis
        Redis::eval(self::MIGRATE_EXPIRED_LUA, 2, $from, $to, time(), 100);
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
        ], JSON_THROW_ON_ERROR);
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
