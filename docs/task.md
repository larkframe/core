# 任务 (Task)

Task 模式基于 `LarkFrame\Worker` 常驻内存进程，适合队列消费、定时任务、后台处理等场景。每个任务对应一个独立的 Worker 进程，不监听网络端口。

## 命令格式

```bash
php task.php <taskname> [action] [args]
```

| 参数 | 说明 |
|------|------|
| `taskname` | 任务名称，对应 `config/task.php` 中的键名 |
| `action` | 操作：`start`（默认）、`stop`、`restart`、`reload`、`status` |
| `args` | 传递给任务的参数，格式 `"key=value&key2=value2"` |

```bash
# 前台启动（默认 start）
php task.php cron

# 停止任务
php task.php cron stop

# 重启任务
php task.php cron restart

# 带参数启动
php task.php cleanup "days=30"
```

## 配置

任务配置在 `config/task.php` 中定义（文件存在时自动加载）：

```php
// config/task.php
return [
    'cron' => [
        'handler'   => \App\Task\CronTask::class,  // 任务处理类（必须）
        'options'   => ['interval' => 60],          // 传递给 run() 的选项
        'daemonize' => false,                       // 是否守护进程
        'worker'    => ['count' => 1],              // Worker 进程数
        'pidFile'   => 'task-cron.pid',             // PID 文件（runtime/ 下）
        'stdoutFile'=> 'task-cron.stdout.log',      // 标准输出日志
        'logFile'   => 'task-cron.log',             // 日志文件
    ],
];
```

### 配置项说明

| 键 | 类型 | 默认值 | 说明 |
|----|------|--------|------|
| `handler` | string | 必填 | 任务处理类，需实现 `static run(array $options, array $args): void` |
| `options` | array | `[]` | 传递给 `run()` 的第一参数 |
| `daemonize` | bool | `false` | 是否以守护进程运行 |
| `worker.count` | int | `1` | Worker 进程数 |
| `pidFile` | string | `task-{name}.pid` | PID 文件路径（相对于 `runtime/`） |
| `stdoutFile` | string | `task-{name}.stdout.log` | stdout 重定向文件（daemonize 时生效） |
| `logFile` | string | `task-{name}.log` | `Worker::log()` 输出的日志文件 |

## 任务类

任务类只需实现 `run` 静态方法：

```php
namespace App\Task;

use LarkFrame\Worker;

class CronTask
{
    public static function run(array $options, array $args): void
    {
        $interval = $options['interval'] ?? 60;

        Worker::log("[CronTask] Starting, interval: {$interval}s");

        // 注册定时器
        Worker::$globalEvent->repeat($interval, function () {
            // 定时执行的逻辑
        });
    }
}
```

- `$options`：来自配置的 `options` 键
- `$args`：来自命令行参数（`parse_str` 解析后的关联数组）

## 事件循环定时器

Task 模式下通过 `Worker::$globalEvent` 注册定时器，基于事件循环实现：

### repeat — 周期执行

```php
// 每 60 秒执行一次
Worker::$globalEvent->repeat(60, function () {
    Worker::log('tick');
});
```

### delay — 延迟执行

```php
// 5 秒后执行一次
Worker::$globalEvent->delay(5, function () {
    Worker::log('delayed');
});
```

### 取消定时器

```php
$timerId = Worker::$globalEvent->repeat(10, function () {
    // ...
});

// 取消
Worker::$globalEvent->offRepeat($timerId);
```

## 日志输出

使用 `Worker::log()` 同时输出到终端和日志文件：

```php
Worker::log("[MyTask] Processing...");
```

- 前台模式：输出到终端 + 写入 `logFile`
- 守护进程模式：写入 `stdoutFile` + `logFile`

不要使用 `echo`/`printf`，它们在守护进程模式下输出会丢失。

## 典型场景

### 定时任务

按固定间隔执行任务（如数据同步、报表生成）：

```php
class SyncTask
{
    public static function run(array $options, array $args): void
    {
        $interval = $options['interval'] ?? 300;

        // 立即执行一次
        static::sync();

        // 定时执行
        Worker::$globalEvent->repeat($interval, function () {
            static::sync();
        });
    }

    protected static function sync(): void
    {
        Worker::log('[SyncTask] Starting sync...');
        // 同步逻辑
        Worker::log('[SyncTask] Sync completed');
    }
}
```

### 队列消费

持续消费 Redis 队列中的消息：

```php
use LarkFrame\Queue;

class ConsumeTask
{
    public static function run(array $options, array $args): void
    {
        $queue = $options['queue'] ?? 'default';
        $interval = $options['interval'] ?? 1;

        Worker::log("[ConsumeTask] Watching queue: {$queue}");

        Worker::$globalEvent->repeat($interval, function () use ($queue) {
            $job = Queue::pop($queue);
            if ($job) {
                Worker::log("[ConsumeTask] Processing: " . $job->getName());
                $job->fire();
            }
        });
    }
}
```

### 定时 + 队列联动

定时推送任务到队列，消费者异步处理：

```php
// CronTask：定时推送
class CronTask
{
    public static function run(array $options, array $args): void
    {
        Worker::$globalEvent->repeat($options['interval'] ?? 60, function () {
            Queue::push('emails', SendEmailJob::class, [
                'to' => 'user@example.com',
                'subject' => 'Daily report',
            ]);
            Worker::log('[CronTask] Pushed email job');
        });
    }
}

// ConsumeTask：消费队列
class ConsumeTask
{
    public static function run(array $options, array $args): void
    {
        Worker::$globalEvent->repeat($options['interval'] ?? 1, function () use ($options) {
            $job = Queue::pop($options['queue'] ?? 'emails');
            if ($job) {
                $job->fire();
            }
        });
    }
}
```

## 运行时文件

Task 模式在 `runtime/` 目录下生成以下文件：

| 文件 | 说明 |
|------|------|
| `task-{name}.pid` | Worker 主进程 PID |
| `task-{name}.log` | `Worker::log()` 输出日志 |
| `task-{name}.stdout.log` | stdout/stderr 重定向（守护进程模式） |

## 与 Server 模式的区别

| 特性 | Server 模式 | Task 模式 |
|------|-----------|----------|
| 命令格式 | `php server.php <action>` | `php task.php <taskname> <action>` |
| 网络监听 | 监听 HTTP 端口 | 不监听端口 |
| 请求处理 | 路由匹配 → 控制器 → 响应 | 自定义 `run()` 逻辑 |
| 典型用途 | Web API 服务 | 定时任务、队列消费、后台处理 |
| 多任务 | 单服务多 Worker | 每个任务独立进程 |
| 配置位置 | `config/config.php` → `server` | `config/task.php` |
