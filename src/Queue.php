<?php

namespace LarkFrame;

use LarkFrame\Cache\Redis;
use LarkFrame\Queue\Job;
use LarkFrame\Queue\QueueInterface;
use LarkFrame\Queue\RedisQueue;

// RedisQueue 仅供 driver() 实例化使用，门面方法统一面向 QueueInterface 契约

/**
 * Queue 门面类
 *
 * 基于 Redis 的队列系统，支持延迟推送、消息确认、失败重试。
 *
 * 用法：
 *   // 推送任务
 *   Queue::push('emails', SendEmailJob::class, ['to' => 'user@example.com']);
 *   Queue::push('orders', ProcessOrderJob::class, ['order_id' => 123]);
 *
 *   // 延迟推送
 *   Queue::later('notifications', 60, SendNotification::class, ['user_id' => 1]);
 *
 *   // 消费任务
 *   Queue::pop('emails');  // 返回 Job 或 null
 *
 *   // 队列大小
 *   Queue::size('emails');
 *
 *   // 清空队列
 *   Queue::clear('emails');
 *
 *   // 失败任务
 *   Queue::getFailedJobs('emails');
 *   Queue::retryFailed('emails', 0);
 *
 * 配置（config/config.php）：
 *   'queue' => [
 *       'default' => 'default',
 *       'driver' => 'redis',
 *       'retry_after' => 60,
 *       'max_tries' => 3,
 *   ]
 */
class Queue
{
    protected static ?QueueInterface $instance = null;

    /**
     * 获取队列驱动实例
     */
    public static function driver(): QueueInterface
    {
        if (static::$instance === null) {
            $config = config('queue', []);
            $driver = $config['driver'] ?? 'redis';
            if ($driver !== 'redis') {
                throw new \RuntimeException("Queue driver '{$driver}' is not supported. Only 'redis' is implemented.");
            }
            static::$instance = new RedisQueue($config);
        }
        return static::$instance;
    }

    /**
     * 推送任务到队列
     *
     * @param string $queue 队列名称，空串时使用配置 queue.default
     * @param mixed $job 任务类名或可序列化对象（闭包不可序列化，请使用类名）
     * @param mixed $data 任务数据
     * @param int $delay 延迟秒数
     * @return string 任务 ID
     */
    public static function push(string $queue, mixed $job, mixed $data = '', int $delay = 0): string
    {
        return static::driver()->push($queue, $job, $data, $delay);
    }

    /**
     * 延迟推送任务
     */
    public static function later(string $queue, int $delay, mixed $job, mixed $data = ''): string
    {
        return static::driver()->later($queue, $delay, $job, $data);
    }

    /**
     * 弹出任务
     */
    public static function pop(string $queue = ''): ?Job
    {
        return static::driver()->pop($queue);
    }

    /**
     * 获取队列大小
     */
    public static function size(string $queue = ''): int
    {
        return static::driver()->size($queue);
    }

    /**
     * 清空队列
     */
    public static function clear(string $queue = ''): void
    {
        static::driver()->clear($queue);
    }

    /**
     * 获取失败任务列表
     */
    public static function getFailedJobs(string $queue = ''): array
    {
        return static::driver()->getFailedJobs($queue);
    }

    /**
     * 重试失败任务
     */
    public static function retryFailed(string $queue = '', int $index = 0): bool
    {
        return static::driver()->retryFailed($queue, $index);
    }
}
