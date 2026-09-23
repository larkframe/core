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
     * 单次迁移批量上限，防止单次脚本执行过长阻塞 Redis
     */
    private const MIGRATE_BATCH_LIMIT = 100;

    /**
     * 原子「迁移到期任务 + 取出并预留」Lua 脚本
     *
     * 原实现每次 pop 需 3 次 RTT（延迟队列迁移、reserved 超时迁移、LPOP+ZADD），
     * 合并为单脚本后降至 1 次；迁移与取出在同一脚本内执行，天然互斥，
     * 多 Worker 并发迁移不会重复消费。
     *
     * KEYS[1]=主队列 KEYS[2]=延迟队列 KEYS[3]=reserved
     * ARGV[1]=当前时间戳 ARGV[2]=retry_after 秒 ARGV[3]=迁移批量上限
     */
    private const POP_MIGRATE_AND_RESERVE_LUA = <<<'LUA'
local now = tonumber(ARGV[1])
local retryAfter = tonumber(ARGV[2])
local limit = tonumber(ARGV[3])

-- 迁移到期的延迟任务
local delayed = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', ARGV[1], 'LIMIT', 0, limit)
local delayedCount = #delayed
if delayedCount > 0 then
    redis.call('ZREMRANGEBYRANK', KEYS[2], 0, delayedCount - 1)
    for i = 1, delayedCount do
        redis.call('RPUSH', KEYS[1], delayed[i])
    end
end

-- 迁移 reserved 中已超时未确认的任务（score = expires_at）
local expired = redis.call('ZRANGEBYSCORE', KEYS[3], '-inf', ARGV[1], 'LIMIT', 0, limit)
local expiredCount = #expired
if expiredCount > 0 then
    redis.call('ZREMRANGEBYRANK', KEYS[3], 0, expiredCount - 1)
    for i = 1, expiredCount do
        redis.call('RPUSH', KEYS[1], expired[i])
    end
end

-- 取出并预留
local payload = redis.call('LPOP', KEYS[1])
if not payload then return nil end
local ok, data = pcall(cjson.decode, payload)
if not ok then
    redis.call('RPUSH', KEYS[1] .. ':failed', payload)
    return nil
end
data['reserved_at'] = now
data['expires_at'] = now + retryAfter
local encoded = cjson.encode(data)
redis.call('ZADD', KEYS[3], now + retryAfter, encoded)
return encoded
LUA;

    /**
     * 原子「从 reserved 移除 + 推入目标 List」Lua 脚本（release 回主队列 / fail 进失败队列）
     *
     * 仅当 ZREM 命中（仍持有该任务的预留）时才推入目标队列：
     * 预留已超时被其他 Worker 迁回主队列时返回 0，此时不推入可避免任务同时存在于
     * 两个队列而被重复消费。拆开为两步时，中间崩溃同样会造成重复。
     *
     * KEYS[1]=reserved KEYS[2]=目标 List ARGV[1]=reserved 成员原文 ARGV[2]=新 payload
     */
    private const ACK_AND_PUSH_LIST_LUA = <<<'LUA'
local removed = redis.call('ZREM', KEYS[1], ARGV[1])
if removed == 1 then
    redis.call('RPUSH', KEYS[2], ARGV[2])
end
return removed
LUA;

    /**
     * 原子「从 reserved 移除 + 推入延迟队列 ZSET」Lua 脚本（release 带延迟）
     *
     * KEYS[1]=reserved KEYS[2]=延迟队列 ARGV[1]=reserved 成员原文 ARGV[2]=score ARGV[3]=新 payload
     */
    private const ACK_AND_PUSH_DELAYED_LUA = <<<'LUA'
local removed = redis.call('ZREM', KEYS[1], ARGV[1])
if removed == 1 then
    redis.call('ZADD', KEYS[2], ARGV[2], ARGV[3])
end
return removed
LUA;

    /**
     * 原子重试失败任务 Lua 脚本
     * 读取 + payload 清理 + 从 failed 删除 + 重新入队全部原子完成：
     * 拆开实现时，并发调用会在 lRem 返回 0 后仍然 rPush 造成任务重复；两步之间崩溃则任务永久丢失；
     * PHP 侧先读再由 Lua 删则存在列表移位窗口，可能删除 A 推送 B
     */
    private const RETRY_FAILED_LUA = <<<'LUA'
local items = redis.call('LRANGE', KEYS[1], ARGV[1], ARGV[1])
if #items == 0 then return 0 end
local ok, payload = pcall(cjson.decode, items[1])
if not ok then return 0 end
payload['attempts'] = 0
payload['reserved_at'] = nil
payload['expires_at'] = nil
payload['failed_at'] = nil
payload['error'] = nil
local removed = redis.call('LREM', KEYS[1], 1, items[1])
if removed == 0 then return 0 end
redis.call('RPUSH', KEYS[2], cjson.encode(payload))
return 1
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

        // 单次 Lua 完成「迁移到期任务 + 取出并预留」：原实现需 3 次 Redis RTT，现降至 1 次，
        // 且迁移与取出在同一脚本内原子完成，多 Worker 并发迁移不会重复消费
        $payload = Redis::eval(
            self::POP_MIGRATE_AND_RESERVE_LUA,
            3,
            $queue,
            $queue . ':delayed',
            $queue . ':reserved',
            time(),
            $this->retryAfter,
            self::MIGRATE_BATCH_LIMIT
        );
        if (!$payload) {
            return null;
        }

        // 不能用 falsy 判断：合法 payload 解码为 []（如 data 为空对象）时任务会被滞留在 reserved
        $data = json_decode($payload, true);
        if (!is_array($data)) {
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
            // 保留异常类名与位置，仅 message 无法定位失败根因
            $payload['error'] = get_class($exception) . ': ' . $exception->getMessage()
                . ' in ' . $exception->getFile() . ':' . $exception->getLine();
        }

        // 原子完成「移出 reserved + 推入 failed」，避免两步之间崩溃导致任务同时存在于两处
        Redis::eval(
            self::ACK_AND_PUSH_LIST_LUA,
            2,
            $job->getQueue() . ':reserved',
            $job->getQueue() . ':failed',
            $job->getRawPayload(),
            json_encode($payload, JSON_THROW_ON_ERROR)
        );
    }

    public function release(Job $job, int $delay = 0): void
    {
        $payload = $job->getPayload();
        $payload['attempts'] = ($payload['attempts'] ?? 0) + 1;
        $payload['reserved_at'] = null;
        $payload['expires_at'] = null;

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $reservedKey = $job->getQueue() . ':reserved';
        $rawPayload = $job->getRawPayload();

        // 原子完成「移出 reserved + 重新入队」，避免两步之间崩溃导致任务重复投递或丢失
        if ($delay > 0) {
            Redis::eval(
                self::ACK_AND_PUSH_DELAYED_LUA,
                2,
                $reservedKey,
                $job->getQueue() . ':delayed',
                $rawPayload,
                time() + $delay,
                $encoded
            );
        } else {
            Redis::eval(
                self::ACK_AND_PUSH_LIST_LUA,
                2,
                $reservedKey,
                $job->getQueue(),
                $rawPayload,
                $encoded
            );
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
        // 读取、payload 清理、删除、重新入队全部在 Lua 内原子完成
        $result = Redis::eval(self::RETRY_FAILED_LUA, 2, $queue . ':failed', $queue, $index);
        return (int)$result === 1;
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
