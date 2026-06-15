<?php

namespace LarkFrame;

use LarkFrame\Cache\Redis;
use LarkFrame\Queue\Job;
use LarkFrame\Queue\QueueInterface;
use LarkFrame\Queue\RedisQueue;

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
            static::$instance = new RedisQueue($config);
        }
        return static::$instance;
    }

    /**
     * 推送任务到队列
     *
     * @param string $queue 队列名称
     * @param mixed $job 任务类名或闭包
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
    public static function pop(string $queue = 'default'): ?Job
    {
        return static::driver()->pop($queue);
    }

    /**
     * 获取队列大小
     */
    public static function size(string $queue = 'default'): int
    {
        return static::driver()->size($queue);
    }

    /**
     * 清空队列
     */
    public static function clear(string $queue = 'default'): void
    {
        static::driver()->clear($queue);
    }

    /**
     * 获取失败任务列表
     */
    public static function getFailedJobs(string $queue = 'default'): array
    {
        $driver = static::driver();
        if ($driver instanceof RedisQueue) {
            return $driver->getFailedJobs($queue);
        }
        return [];
    }

    /**
     * 重试失败任务
     */
    public static function retryFailed(string $queue = 'default', int $index = 0): bool
    {
        $driver = static::driver();
        if ($driver instanceof RedisQueue) {
            return $driver->retryFailed($queue, $index);
        }
        return false;
    }
}
