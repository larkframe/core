# LarkFrame Core

高性能 PHP 框架核心，支持 Server（常驻内存）、Web（PHP-FPM）、Shell（命令行）和 Task（自定义任务）四种运行模式。

## 特性

- **多运行模式** — Server 常驻内存 / Web FPM / Shell 命令行，一套代码多种部署；Task 常驻任务，独立运行自定义逻辑
- **高性能路由** — 基于 FastRoute，LRU 缓存路由回调
- **依赖注入** — PSR-11 容器，自动装配 + 单例绑定
- **数据库 ORM** — 基于 Illuminate Database，支持多库切换、Eloquent Model
- **Redis 缓存** — 连接池管理，支持多连接和数据库切换
- **队列系统** — 基于 Redis 的消息队列，支持延迟推送、失败重试
- **协程支持** — PHP Fiber 上下文隔离、连接池、内存通道
- **中间件** — 洋葱模型，支持全局/控制器/路由/注解四种注册方式
- **视图引擎** — 原生 PHP 和 Twig 双引擎
- **工具集** — 字符串、随机数、Base64、文件、图片、模拟数据等常用工具

## 环境要求

- PHP >= 8.1
- ext-json, ext-pdo
- 推荐：ext-redis, ext-event

## 安装

```bash
composer require larkframe/core
```

## 快速开始

### 入口文件

```php
// server.php — Server 模式入口
require __DIR__ . '/vendor/autoload.php';
LarkFrame\App::run(LarkFrame\Consts::RUN_TYPE_SERVER);

// shell.php — Shell 模式入口
require __DIR__ . '/vendor/autoload.php';
LarkFrame\App::run(LarkFrame\Consts::RUN_TYPE_SHELL);

// task.php — Task 模式入口
require __DIR__ . '/vendor/autoload.php';
LarkFrame\App::run(LarkFrame\Consts::RUN_TYPE_TASK);

// public/index.php — Web 模式入口
require dirname(__DIR__) . '/vendor/autoload.php';
LarkFrame\App::run(LarkFrame\Consts::RUN_TYPE_WEB);
```

### 路由

```php
// config/route.php
use LarkFrame\Route;

Route::get('/hello/{name}', function ($request) {
    return json(['message' => 'Hello ' . $request->route()->param('name')]);
});
```

### 控制器

```php
namespace App\Controller;

use LarkFrame\Request;

class UserController
{
    public function indexAction(Request $request)
    {
        return json(['users' => []]);
    }

    public function showAction(Request $request)
    {
        $id = $request->route()->param('id');
        return json(['user' => ['id' => $id]]);
    }
}
```

### 中间件

```php
class AuthMiddleware implements \LarkFrame\MiddlewareInterface
{
    public function process(\LarkFrame\Request $request, callable $next): \LarkFrame\Response
    {
        if (!$request->header('authorization')) {
            return json(['error' => 'Unauthorized'], 401);
        }
        return $next($request);
    }
}
```

## 核心模块

| 模块 | 类 | 文档 |
|------|-----|------|
| 应用入口 | `LarkFrame\App` | [docs/app.md](docs/app.md) |
| 路由 | `LarkFrame\Route` | [docs/route.md](docs/route.md) |
| 请求 | `LarkFrame\Request` | [docs/request.md](docs/request.md) |
| 响应 | `LarkFrame\Response` | [docs/response.md](docs/response.md) |
| 容器 | `LarkFrame\Container` | [docs/container.md](docs/container.md) |
| 数据库 | `LarkFrame\Db` | [docs/database.md](docs/database.md) |
| 缓存 | `LarkFrame\Cache\Cache` | [docs/cache.md](docs/cache.md) |
| Redis | `LarkFrame\Cache\Redis` | [docs/redis.md](docs/redis.md) |
| 队列 | `LarkFrame\Queue` | [docs/queue.md](docs/queue.md) |
| 中间件 | `LarkFrame\Middleware` | [docs/middleware.md](docs/middleware.md) |
| 配置 | `LarkFrame\Config` | [docs/config.md](docs/config.md) |
| 日志 | `LarkFrame\Log` | [docs/log.md](docs/log.md) |
| 视图 | `LarkFrame\View\ViewVarHolder` | [docs/view.md](docs/view.md) |
| 工具集 | `LarkFrame\Util` | [docs/util.md](docs/util.md) |
| 协程 | `LarkFrame\Context` / `LarkFrame\Coroutine\Pool` | [docs/coroutine.md](docs/coroutine.md) |
| 任务 | `LarkFrame\Worker` / `LarkFrame\Events\Select` | [docs/task.md](docs/task.md) |

## 辅助函数

```php
// 配置与请求
config('app.name');              // 读取配置
request();                       // 获取当前请求
input('key', $default);          // 获取请求参数

// 响应
json($data);                     // JSON 响应
jsonp($data, 'callback');        // JSONP 响应
xml($xml);                       // XML 响应
redirect('/login');              // 重定向
response($body, $status);        // 通用响应
view('index', $vars);            // 视图响应（使用配置的引擎）
raw_view('index', $vars);        // 原生 PHP 模板
twig_view('index', $vars);       // Twig 模板

// 路径
run_path('sub/path');            // 程序执行目录
config_path();                   // 配置目录
runtime_path('logs');            // 运行时目录
path_combine($front, $back);     // 拼接路径

// 安全
escape($value);                  // HTML 转义（防 XSS）
clean($value);                   // 去除 HTML 标签并 trim

// 其他
is_phar();                       // 是否在 Phar 中运行
cpu_count();                     // CPU 核心数
getRealHost(true);               // 获取主机名
getClientIp();                   // 获取客户端 IP（支持代理头）
```

## 项目结构

```
core/src/
├── Annotation/          # 注解（中间件注解）
├── Cache/               # 缓存（Redis、Symfony Cache 封装）
├── Connection/          # TCP 连接
├── Coroutine/           # 协程（Context、Pool、Channel）
├── Database/            # 数据库（Eloquent、Manager）
├── Events/              # 事件循环（Select、ext-event）
├── Protocols/           # 协议（HTTP）
├── Queue/               # 队列（Redis 驱动、Job、Worker）
├── Request/             # 请求源（Server/Web/Shell）
├── Response/            # 响应发送器
├── Util/                # 工具集（Str、Rand、Base64、File、Img、Mock）
├── View/                # 视图（Raw PHP、Twig）
├── App.php              # 应用入口
├── Config.php           # 配置管理
├── Consts.php           # 常量定义
├── Container.php        # DI 容器
├── Context.php          # 协程上下文
├── Db.php               # 数据库门面
├── ErrorHandler.php     # 错误处理器
├── File.php             # 文件操作
├── Library.php          # 类库基类
├── Log.php              # 日志门面
├── LogFormatter.php     # 日志格式化
├── Middleware.php       # 中间件管理
├── MiddlewareInterface.php  # 中间件接口
├── Queue.php            # 队列门面
├── Request.php          # HTTP 请求
├── Response.php         # HTTP 响应
├── Route.php            # 路由注册器
├── RouteDefinition.php  # 路由定义对象
├── UploadFile.php       # 上传文件
├── Util.php             # 工具门面
├── Worker.php           # Worker 进程
└── helper.php           # 全局辅助函数
```

## License

MIT
