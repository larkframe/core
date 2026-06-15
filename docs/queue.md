# 队列 (Queue)

`LarkFrame\Queue` 提供基于 Redis 的队列系统，支持延迟推送、消息确认和失败重试。

## 推送任务

```php
use LarkFrame\Queue;

// 即时推送
Queue::push('emails', SendEmailJob::class, ['to' => 'user@example.com']);
Queue::push('orders', ProcessOrderJob::class, ['order_id' => 123]);

// 延迟推送（60 秒后执行）
Queue::later('notifications', 60, SendNotification::class, ['user_id' => 1]);

// 推送闭包
Queue::push('tasks', function (Job $job) {
    // 处理逻辑
    $job->ack();
});
```

## 消费任务

```php
// 弹出单个任务
$job = Queue::pop('emails');
if ($job) {
    try {
        $job->fire();   // 执行任务
        $job->ack();    // 确认完成
    } catch (\Throwable $e) {
        $job->fail($e); // 标记失败
    }
}
```

## 队列 Worker

```php
use LarkFrame\Queue\Worker;
use LarkFrame\Queue\RedisQueue;

$worker = new Worker(new RedisQueue(), [
    'max_tries' => 3,
    'sleep' => 1,
    'memory_limit' => 128,
]);

// 持续消费
$worker->daemon('emails');
```

## 队列管理

```php
// 队列大小
$size = Queue::size('emails');

// 清空队列
Queue::clear('emails');

// 失败任务
$failed = Queue::getFailedJobs('emails');
Queue::retryFailed('emails', 0);  // 重试第 0 个失败任务
```

## 任务类

创建任务类只需实现 `handle()` 方法：

```php
class SendEmailJob
{
    public function handle(Job $job, mixed $data): void
    {
        $to = $data['to'];
        // 发送邮件逻辑...

        $job->ack();
    }
}
```

## 配置

```php
// config/config.php
'queue' => [
    'default' => 'default',
    'driver' => 'redis',
    'retry_after' => 60,   // 任务超时时间（秒）
    'max_tries' => 3,      // 最大重试次数
]
```

## Redis 数据结构

| Key | 类型 | 说明 |
|-----|------|------|
| `queue:{name}` | List | 主队列 |
| `queue:{name}:delayed` | Sorted Set | 延迟队列（score = 执行时间） |
| `queue:{name}:reserved` | Sorted Set | 已取出未确认的任务（score = 超时时间） |
| `queue:{name}:failed` | List | 失败任务 |

## 任务生命周期

```
push → [主队列] → pop → [reserved] → ack → 完成
                                  → fail → [failed]
                                  → release → [主队列/delayed]
```
