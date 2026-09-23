# 队列 (Queue)

`LarkFrame\Queue` 提供基于 Redis 的队列系统，支持延迟推送、消息确认和失败重试。

驱动实现 `LarkFrame\Queue\QueueInterface` 契约（含 `getFailedJobs`/`retryFailed`），
门面方法直接面向接口调用，自定义驱动需实现全部接口方法。

## 推送任务

```php
use LarkFrame\Queue;

// 即时推送（推荐：任务类名）
Queue::push('emails', SendEmailJob::class, ['to' => 'user@example.com']);
Queue::push('orders', ProcessOrderJob::class, ['order_id' => 123]);

// 延迟推送（60 秒后执行）
Queue::later('notifications', 60, SendNotification::class, ['user_id' => 1]);
```

> **注意**：闭包不可序列化（`serialize(Closure)` 直接抛异常），请使用任务类名；
> 推送对象实例时走 `serialize()`，消费端 `Job::fire()` 会以
> `allowed_classes => true` 反序列化——队列内容不是可信边界，任何能写入 Redis
> 的攻击面都可能注入 POP 链，生产环境请确保 Redis 访问受控并仅推送类名。

## 消费任务

```php
// 弹出单个任务（队列名省略时使用配置 queue.default）
$job = Queue::pop('emails');
if ($job) {
    try {
        $job->fire();   // 执行任务
        $job->ack();    // 确认完成（handler 内已 ack 时为幂等 no-op）
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
// 队列大小（队列名省略时使用配置 queue.default）
$size = Queue::size('emails');

// 清空队列
Queue::clear('emails');

// 失败任务
$failed = Queue::getFailedJobs('emails');
Queue::retryFailed('emails', 0);  // 重试第 0 个失败任务（Lua 原子完成取出+删除+重新入队）
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

### 原子 pop + 迁移 + reserve

`pop()` 通过**单个** Lua 脚本（`POP_MIGRATE_AND_RESERVE_LUA`）完成三件事：

1. 把到期的延迟任务从 `:delayed` 迁移到主队列
2. 把 reserved 中超时未确认的任务迁回主队列
3. `LPOP` 主队列并 `ZADD` 到 reserved（score = now + retry_after）

合并为单脚本后每次 pop 只需 **1 次 Redis RTT**（原实现为 3 次），且迁移与取出在同一脚本内天然互斥，多 Worker 并发迁移不会重复消费。Lua 脚本内 `cjson.decode` 包裹 `pcall`，若 payload 损坏则自动推入 `:failed` 队列并返回 nil，不丢失原始数据。单次迁移批量上限 100，避免脚本执行过长阻塞 Redis。

### ack 精确匹配

`Job` 构造时保存 Lua `cjson.encode` 的原始字符串作为 `rawPayload`，`ack()` 使用它与 reserved ZSET 成员精确匹配。避免 PHP `json_encode` 与 Lua `cjson.encode` 编码差异（如 Unicode 转义、键序）导致 `zRem` 失败。

### fail/release 原子性

`fail()` 与 `release()` 各自通过单个 Lua 脚本（`ACK_AND_PUSH_LIST_LUA` / `ACK_AND_PUSH_DELAYED_LUA`）原子完成「从 reserved 移除 + 推入目标队列」，消除两步之间崩溃导致任务同时存在于两处、被重复消费的窗口。

脚本仅在 `ZREM` 命中（即仍持有该任务的预留）时才推入目标队列。若预留已超时并被其他 Worker 迁回主队列，`ZREM` 返回 0，此时不推入——避免任务同时存在于主队列与 `:failed` 而被重复消费。

### 序列化安全

- `createPayload()` 使用 `JSON_THROW_ON_ERROR`，编码失败时抛出异常而非静默推空字符串
- `Job::fire()` 中 `unserialize` 默认 `allowed_classes => []`，仅允许数组/标量；需要反序列化对象任务时通过 `queue.allowed_job_classes` 显式声明白名单，避免任意类实例化（RCE）

### 重试与失败判定

`Queue\Worker::processJob()` 为任务处理的统一入口（阻塞式 daemon 循环与事件循环消费任务共用），`max_tries` 判定语义：

- `attempts + 1 >= max_tries` → 本次是最后一次允许的执行，失败即 `fail($e)`，异常根因写入 `:failed` 的 `error` 字段
- 否则 → `release($sleep)` 重试

`attempts` 在 release 时递增，故第 N 次执行时 `attempts == N - 1`。
