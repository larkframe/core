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
| `queue:{name}:failed` | List | 失败任务（含 pop 时损坏的 payload） |

## 任务生命周期

```
push → [主队列] → pop → [reserved] → ack → 完成
                                  → fail → [failed]
                                  → release → [主队列/delayed]
```

## 可靠性保证

### 原子 pop + reserve

`pop()` 通过 Lua 脚本原子完成 `LPOP` + `ZADD(reserved)`，避免进程崩溃在两步之间导致任务丢失。Lua 脚本内 `cjson.decode` 包裹 `pcall`，若 payload 损坏则自动推入 `:failed` 队列并返回 nil，不丢失原始数据。

### ack 精确匹配

`Job` 构造时保存 Lua `cjson.encode` 的原始字符串作为 `rawPayload`，`ack()` 使用它与 reserved ZSET 成员精确匹配。避免 PHP `json_encode` 与 Lua `cjson.encode` 编码差异（如 Unicode 转义、键序）导致 `zRem` 失败。

### fail/release 顺序

`fail()` 和 `release()` 均采用 **先 push 后 ack** 策略（at-least-once 语义）：
- 先将任务写入目标队列（`:failed` 或主队列/`:delayed`）
- 再从 reserved ZSET 移除

若 `ack` 失败，任务可能在 reserved 超时后被重新迁移到主队列，导致重复消费——这优于任务丢失。

### 序列化安全

- `createPayload()` 使用 `JSON_THROW_ON_ERROR`，编码失败时抛出异常而非静默推空字符串
- `Job::fire()` 中 `unserialize` 显式指定 `allowed_classes => true`，不再使用 `@` 抑制错误

### 批量迁移

`migrateExpiredJobs()` 通过 Lua 脚本原子完成 `ZRANGEBYSCORE` + `ZREMRANGEBYRANK` + `RPUSH`，单次批量上限 100，避免并发 Worker 重复迁移。
