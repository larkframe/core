<?php

namespace LarkFrame\Queue;

use LarkFrame\Queue\Job;

/**
 * 队列接口
 */
interface QueueInterface
{
    /**
     * 推送任务到队列
     */
    public function push(string $queue, mixed $job, mixed $data = '', int $delay = 0): string;

    /**
     * 延迟推送任务
     */
    public function later(string $queue, int $delay, mixed $job, mixed $data = ''): string;

    /**
     * 弹出任务
     */
    public function pop(string $queue): ?Job;

    /**
     * 确认任务完成
     */
    public function ack(Job $job): void;

    /**
     * 标记任务失败
     */
    public function fail(Job $job, ?\Throwable $exception = null): void;

    /**
     * 重新释放任务到队列
     */
    public function release(Job $job, int $delay = 0): void;

    /**
     * 获取队列大小
     */
    public function size(string $queue): int;

    /**
     * 清空队列
     */
    public function clear(string $queue): void;
}
