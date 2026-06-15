# 应用核心 (App)

`LarkFrame\App` 是框架的入口类，负责应用启动、路由调度和请求处理。

## 运行模式

框架通过入口文件显式指定运行模式（`App::run(LarkFrame\Consts::RUN_TYPE_*)`）：

| 模式 | 启动方式 | 入口文件 |
|------|---------|---------|
| Server | `php server.php` | `server.php` |
| Shell | `php shell.php route "key=value"` | `shell.php` |
| Task | `php task.php taskname [args]` | `task.php` |
| Web | 浏览器访问 | `public/index.php` |

- **Server 模式**：`php server.php` 启动常驻内存 Worker 进程，监听 HTTP 请求
- **Shell 模式**：`php shell.php migrate` 执行单次命令行任务
- **Task 模式**：`php task.php taskname` 执行自定义任务，taskname 对应 `config/task.php` 中的配置
- **Web 模式**：传统 PHP-FPM，通过 Nginx + php-fpm 配置根目录为 `public`，访问 `index.php`

## 启动流程

```php
// server.php
require __DIR__ . '/vendor/autoload.php';
LarkFrame\App::run(LarkFrame\Consts::RUN_TYPE_SERVER);

// shell.php
require __DIR__ . '/vendor/autoload.php';
LarkFrame\App::run(LarkFrame\Consts::RUN_TYPE_SHELL);

// task.php
require __DIR__ . '/vendor/autoload.php';
LarkFrame\App::run(LarkFrame\Consts::RUN_TYPE_TASK);

// public/index.php
require dirname(__DIR__) . '/vendor/autoload.php';
LarkFrame\App::run(LarkFrame\Consts::RUN_TYPE_WEB);
```

`App::run()` 执行以下步骤：

1. 加载 `.env` 环境变量（文件不存在则自动创建默认值）
2. 根据 `.env` 设置时区、`RUN_MODE` 和 `APP_NAME` 常量
3. 加载配置文件 `config/config.php`
4. 初始化数据库连接
5. 根据传入的运行类型进入对应模式

## Server 模式

Server 模式基于 `LarkFrame\Worker`，支持多进程、事件驱动：

```bash
# 前台运行（开发调试）
php server.php

# 后台守护运行（需 config 中 daemonize => true）
php server.php

# 停止服务
kill $(cat runtime/server.pid)
```

配置项：

```php
// config/config.php
'server' => [
    'socketName' => 'http://0.0.0.0:8080',  // 监听地址
    'daemonize' => false,                    // 是否守护进程
    'worker' => ['count' => 4],              // Worker 进程数
    'middleware' => [],                       // 全局中间件
]
```

### 请求生命周期

1. `onWorkerStart` — 加载路由、中间件、初始化 App
2. `onMessage` — 接收请求，匹配路由，执行回调，发送响应
3. 路由回调使用 LRU 缓存（默认 1024 条），避免重复解析

## Shell 模式

命令行执行单次任务，路由前缀 `/` 可省略：

```bash
php shell.php /migrate
php shell.php migrate
php shell.php /queue/work
php shell.php users/import "file=data.csv"
```

Shell 模式下：
- HTTP 方法自动设为 `SHELL`
- 路由取 `$_SERVER['argv'][1]`（自动补 `/` 前缀）
- 参数取 `$_SERVER['argv'][2]`，格式为 `"key=value&key2=value2"`（通过 `parse_str` 解析为 GET/POST 参数）
- 请求源为 `LarkFrame\Request\ShellSource`
- 执行完毕后进程退出

## Task 模式

常驻内存 Worker 进程执行自定义任务，适合队列消费、定时任务、后台处理等场景：

```bash
# 前台运行（默认 start）
php task.php cron

# 停止任务
php task.php cron stop

# 重启任务
php task.php cron restart

# 查看状态
php task.php cron status

# 带参数启动
php task.php cleanup "days=30"
```

配置项：

```php
// config/task.php
return [
    'cron' => [
        'handler'    => \App\Task\CronTask::class,
        'options'    => ['interval' => 60],
        'daemonize'  => false,
        'worker'     => ['count' => 1],
        'pidFile'    => 'task-cron.pid',
        'stdoutFile' => 'task-cron.stdout.log',
        'logFile'    => 'task-cron.log',
    ],
];
```

任务类需实现 `run` 方法（在 Worker 进程的 `onWorkerStart` 中调用）：

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
            Worker::log("[CronTask] tick");
        });
    }
}
```

Task 模式下：
- 使用 Worker 多进程常驻运行
- `run()` 方法在 `onWorkerStart` 回调中执行
- 支持守护进程、多 Worker 进程
- 不监听网络端口，不处理 HTTP 请求
- 使用 `Worker::log()` 输出日志（同时写入终端和日志文件）
- 通过 `Worker::$globalEvent` 注册定时器（`repeat`/`delay`）

详细文档参见 [docs/task.md](task.md)。

## Web 模式

传统 PHP-FPM 部署，每次请求独立进程。**站点根目录必须指向 `public/`**，不要指向项目根目录：

```nginx
server {
    root /var/www/myapp/public;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Web 模式下：
- 请求源为 `LarkFrame\Request\WebSource`（从 `$_GET/$_POST/$_SERVER` 读取）
- 无需连接池（每次请求独立进程）
- 适合低流量或无法运行常驻进程的环境

## 关键方法

| 方法 | 说明 |
|------|------|
| `App::run(string $runType)` | 应用入口，需传入运行类型 |
| `App::request()` | 获取当前请求对象 |
| `App::container()` | 获取 DI 容器 |
| `App::worker()` | 获取当前 Worker 实例 |

## 错误处理

- 生产环境：返回简洁错误页面
- 调试模式（`app.debug = true`）：返回完整异常信息
- 支持自定义错误页面模板：`config('error_page.template')`
- 支持 404/500 等状态码跳转：`config('error_page.404')`
