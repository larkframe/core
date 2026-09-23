<?php

namespace LarkFrame;

use ArrayObject;
use Closure;
use Exception;
use FastRoute\Dispatcher;
use LarkFrame\Config;
use LarkFrame\Connection\TcpConnection;
use LarkFrame\Database\Initializer;
use LarkFrame\Events\Select;
use LarkFrame\Protocols\Http;
use LarkFrame\Response;
use LarkFrame\Worker;
use LarkFrame\Log;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use Throwable;
use function array_merge;
use function array_values;
use function clearstatcache;
use function count;
use function gettype;
use function is_a;
use function is_array;
use function is_file;
use function is_string;
use function key;
use function method_exists;
use function strpos;
use function strtolower;
use function substr;

class App
{
    /**
     * @var callable[]
     */
    protected static array $callbacks = [];

    /**
     * Doubly-linked list nodes for O(1) LRU eviction: key => ['prev' => ?string, 'next' => ?string].
     */
    protected static array $lruNodes = [];

    /**
     * LRU head (oldest entry).
     */
    protected static ?string $lruHead = null;

    /**
     * LRU tail (most recently used entry).
     */
    protected static ?string $lruTail = null;

    /**
     * Maximum callback cache size.
     */
    protected static int $maxCallbackCache = 1024;

    /**
     * @var Worker|null
     */
    protected static ?Worker $worker = null;

    /**
     * @var LoggerInterface|null
     */
    protected static ?LoggerInterface $logger = null;

    /**
     * @var string
     */
    protected static string $requestClass = '';

    /**
     * App constructor.
     */
    public function __construct(string $requestClass, LoggerInterface $logger)
    {
        static::$requestClass = $requestClass;
        static::$logger = $logger;
    }

    /**
     * OnMessage.
     */
    public function onMessage(TcpConnection $connection, Request $request): void
    {
        try {
            Context::reset(new ArrayObject([\LarkFrame\Request::class => $request]));
            $request->initRequestIdAndStartTime();
            $path = $request->path();
            // 单次正则压缩连续斜杠，避免 while+str_replace 对超长斜杠串的 O(n·迭代) 内存抖动
            if (str_contains($path, '//')) {
                $path = preg_replace('~[/]{2,}~', '/', $path);
            }
            $key = $request->method() . $path;

            if (isset(static::$callbacks[$key])) {
                // Move to end of LRU (most recently used) — O(1) via doubly-linked list
                static::lruTouch($key);
                [$call, $args, $controller, $action, $route] = static::$callbacks[$key];
                $request->setController($controller);
                $request->setAction($action);
                $request->setRoute($route);
                // controller 实例于请求时重建，避免缓存闭包复用上一请求实例导致跨请求状态污染
                static::send($connection, static::getCallback($call, $args, $route)($request), $request);
                return;
            }

            $status = 200;
            if (
                static::unsafeUri($connection, $path, $request) ||
                static::findFile($connection, $path, $key, $request) ||
                static::findRoute($connection, $path, $key, $request, $status)
            ) {
                return;
            }
            static::send($connection, static::notFound($request), $request);

        } catch (Throwable $e) {
            static::$logger?->error($e->getMessage(), ['exception' => $e]);
            static::send($connection, static::exceptionResponse($e, $request), $request);
        }
    }

    /**
     * OnWorkerStart.
     */
    public function onWorkerStart(Worker $worker): void
    {
        static::$worker = $worker;
        Http::requestClass(static::$requestClass);
    }

    /**
     * Collect route callbacks with O(1) LRU eviction via doubly-linked list.
     */
    protected static function collectCallbacks(string $key, array $data): void
    {
        if (!isset(static::$callbacks[$key])) {
            // Evict oldest entries if over limit
            while (count(static::$callbacks) >= static::$maxCallbackCache) {
                $evictedKey = static::lruEvict();
                if ($evictedKey === null) {
                    break;
                }
                unset(static::$callbacks[$evictedKey]);
            }
        }

        static::lruTouch($key);
        static::$callbacks[$key] = $data;
    }

    /**
     * Move key to LRU tail (most recently used). O(1).
     */
    protected static function lruTouch(string $key): void
    {
        if (isset(static::$lruNodes[$key])) {
            static::lruDetach($key);
        }
        static::lruAttachTail($key);
    }

    /**
     * Detach a node from the LRU list. O(1).
     */
    protected static function lruDetach(string $key): void
    {
        $node = static::$lruNodes[$key];
        if ($node['prev'] !== null) {
            static::$lruNodes[$node['prev']]['next'] = $node['next'];
        } else {
            static::$lruHead = $node['next'];
        }
        if ($node['next'] !== null) {
            static::$lruNodes[$node['next']]['prev'] = $node['prev'];
        } else {
            static::$lruTail = $node['prev'];
        }
    }

    /**
     * Attach key to LRU tail. O(1).
     */
    protected static function lruAttachTail(string $key): void
    {
        static::$lruNodes[$key] = ['prev' => static::$lruTail, 'next' => null];
        if (static::$lruTail !== null) {
            static::$lruNodes[static::$lruTail]['next'] = $key;
        }
        static::$lruTail = $key;
        if (static::$lruHead === null) {
            static::$lruHead = $key;
        }
    }

    /**
     * Evict the oldest key from LRU head. O(1).
     */
    protected static function lruEvict(): ?string
    {
        if (static::$lruHead === null) {
            return null;
        }
        $evictKey = static::$lruHead;
        static::lruDetach($evictKey);
        unset(static::$lruNodes[$evictKey]);
        return $evictKey;
    }

    /**
     * Check for unsafe URI patterns.
     */
    protected static function unsafeUri(TcpConnection $connection, string $path, mixed $request): bool
    {
        if (
            !$path ||
            $path[0] !== '/' ||
            str_contains($path, '/../') ||
            str_ends_with($path, '/..') ||
            str_contains($path, "\\") ||
            str_contains($path, "\0")
        ) {
            $callback = Middleware::wrapGlobal(static::getFallback(400));
            $request->setApp('');
            $request->setController('');
            $request->setAction('');
            static::send($connection, $callback($request, 400), $request);
            return true;
        }
        return false;
    }

    /**
     * Get fallback callback for error status.
     */
    protected static function getFallback(int $status = 404): Closure
    {
        return static function (mixed $request, int $statusCode = 0) use ($status): Response {
            $code = $statusCode ?: $status;
            $errorPage = config("error_page.$code", null);
            if ($errorPage) {
                return redirect($errorPage);
            }
            return new \LarkFrame\Response($code, ['Content-Type' => 'text/html; charset=utf-8'], static::buildErrorPage($code));
        };
    }

    /**
     * 404 兜底：经全局中间件管道，使 CORS 等响应头中间件能作用于未命中请求
     */
    protected static function notFound(mixed $request): Response
    {
        $errorPage = config('error_page.404', null);
        $fallback = static function () use ($errorPage): Response {
            if ($errorPage) {
                return redirect($errorPage);
            }
            return new \LarkFrame\Response(404, ['Content-Type' => 'text/html; charset=utf-8'], static::buildErrorPage(404));
        };
        $callback = Middleware::wrapGlobal($fallback);
        return $callback($request);
    }

    /**
     * Build an HTML error page for the given status code.
     * Supports custom template via config key 'error_page.template'.
     * Template receives: {code}, {phrase}
     */
    protected static function buildErrorPage(int $code): string
    {
        $phrase = \LarkFrame\Response::PHRASES[$code] ?? 'Error';

        // Check for custom template file
        $templatePath = config('error_page.template');
        if ($templatePath && is_file($templatePath)) {
            $html = file_get_contents($templatePath);
            if ($html !== false) {
                return str_replace(['{code}', '{phrase}'], [(string)$code, $phrase], $html);
            }
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>$code $phrase</title>
<style>body{font-family:sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;margin:0;background:#f5f5f5;color:#333}
.container{text-align:center;padding:2rem}h1{font-size:4rem;margin:0;color:#e74c3c}p{font-size:1.2rem;color:#666}</style>
</head><body><div class="container"><h1>$code</h1><p>$phrase</p></div></body></html>
HTML;
    }

    /**
     * Build exception response.
     */
    protected static function exceptionResponse(Throwable $e, mixed $request): Response
    {
        $response = new \LarkFrame\Response(500, [], static::config('app.debug', false) ? (string)$e : $e->getMessage());
        $response->exception($e);
        return $response;
    }

    public static function getCallback(mixed $call, array $args = [], ?RouteDefinition $route = null): callable
    {
        $isController = is_array($call) && is_string($call[0]);
        $middlewares = Middleware::getMiddleware($call, $route);
        $middlewares = Middleware::resolveInstances($middlewares);

        $anonymousArgs = array_values($args);

        if ($isController) {
            $call[0] = static::container()->get($call[0]);
        }

        if ($middlewares !== []) {
            $callback = array_reduce($middlewares, function ($carry, $pipe) {
                return function ($request) use ($carry, $pipe) {
                    try {
                        return $pipe($request, $carry);
                    } catch (Throwable $e) {
                        return static::exceptionResponse($e, $request);
                    }
                };
            }, function ($request) use ($call, $anonymousArgs) {
                try {
                    $response = $call($request, ...$anonymousArgs);
                } catch (Throwable $e) {
                    return static::exceptionResponse($e, $request);
                }
                if (!$response instanceof \LarkFrame\Response) {
                    if (!is_string($response)) {
                        $response = static::stringify($response);
                    }
                    $response = new \LarkFrame\Response(200, [], $response);
                }
                return $response;
            });
        } else {
            $callback = $anonymousArgs === []
                ? $call
                : fn($request) => $call($request, ...$anonymousArgs);
        }
        return $callback;
    }

    /**
     * Get the DI container.
     */
    public static function container(): ContainerInterface
    {
        return static::config('container');
    }

    /**
     * Get current request.
     *
     * 非 HTTP 上下文（队列消费、定时任务、CLI）无当前请求，返回 null。
     * 返回类型必须可空：LogFormatter（$requestObj !== null）、Response::file（$request !== null）、
     * Database\Initializer（request()?->）等调用点均按可空处理，声明为非空会在
     * 队列/任务写日志时抛 TypeError 直接崩溃。
     */
    public static function request(): ?\LarkFrame\Request
    {
        return Context::get(\LarkFrame\Request::class);
    }

    /**
     * Get current worker.
     */
    public static function worker(): ?Worker
    {
        return static::$worker;
    }

    /**
     * Find a matching route.
     */
    protected static function findRoute(TcpConnection $connection, string $path, string $key, mixed $request, int &$status): bool
    {
        $routeInfo = \LarkFrame\Route::dispatch($request->method(), $path);
        if ($routeInfo[0] === Dispatcher::FOUND) {
            $status = 200;
            $routeInfo[0] = 'route';
            $callback = $routeInfo[1]['callback'];
            $route = clone $routeInfo[1]['route'];
            $controller = $action = '';
            $args = $routeInfo[2] ?? [];
            if ($args) {
                $route->setParams($args);
            }
            $args = array_merge($route->param(), $args);

            if (is_array($callback)) {
                $controller = $callback[0];
                $action = $callback[1] ?? 'index';
                $actionSuffix = \LarkFrame\Route::getActionSuffix();
                if ($actionSuffix && !str_contains($action, $actionSuffix)) {
                    $action .= $actionSuffix;
                }
                $callback[1] = $action;
            }

            // 缓存可重建元信息（原始回调 + 参数），不缓存 controller 实例及其实例化闭包；
            // 命中时经 getCallback 重建实例，杜绝同名 URL 复用上一请求 controller 的状态污染
            static::collectCallbacks($key, [$callback, $args, $controller ?: '', $action, $route]);

            $callback = static::getCallback($callback, $args, $route);
            $request->setController($controller ?: '');
            $request->setAction($action);
            $request->setRoute($route);
            static::send($connection, $callback($request), $request);
            return true;
        }

        // P2-25：METHOD_NOT_ALLOWED 时发送 405 + Allow 头（FastRoute 已自动映射 GET→HEAD，
        // OPTIONS 命中此处并返回所有注册方法）。经全局中间件管道包裹——
        // CORS 预检（OPTIONS）通常未注册路由，不穿管道则预检中间件分支永远不执行
        if ($routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED) {
            $allowedMethods = is_array($routeInfo[1] ?? null) ? $routeInfo[1] : [];
            $allowHeader = ['Allow' => implode(', ', $allowedMethods)];
            $callback = Middleware::wrapGlobal(
                static fn(): \LarkFrame\Response => new \LarkFrame\Response(405, $allowHeader, '405 Method Not Allowed')
            );
            static::send($connection, $callback($request), $request);
            return true;
        }

        return false;
    }

    /**
     * Find a static file.
     */
    protected static function findFile(TcpConnection $connection, string $path, string $key, mixed $request): bool
    {
        if (preg_match('/%[0-9a-f]{2}/i', $path)) {
            $path = urldecode($path);
            if (static::unsafeUri($connection, $path, $request)) {
                return true;
            }
        }

        $publicDir = ROOT_PATH . "/public";
        $file = "$publicDir$path";

        if (!is_file($file)) {
            return false;
        }

        // Do NOT cache static file callbacks — files may be modified/deleted at runtime.
        // Each request re-checks file existence for correctness.
        // wrapGlobal 复用进程级缓存的全局中间件实例链（getCallback 每次重建会重复反射解析）
        $callback = Middleware::wrapGlobal(function ($request) use ($file) {
            clearstatcache(true, $file);
            if (!is_file($file)) {
                return new \LarkFrame\Response(404, ['Content-Type' => 'text/html; charset=utf-8'], static::buildErrorPage(404));
            }
            $response = (new \LarkFrame\Response())->file($file);

            // Add cache headers for static files
            $lastModified = filemtime($file);
            if ($lastModified) {
                $response->withHeaders([
                    'Last-Modified' => gmdate('D, d M Y H:i:s', $lastModified) . ' GMT',
                    'Cache-Control' => 'public, max-age=86400',
                ]);

                // Handle If-Modified-Since for 304 response
                $ifModifiedSince = $request->header('if-modified-since');
                if ($ifModifiedSince && strtotime($ifModifiedSince) >= $lastModified) {
                    return new \LarkFrame\Response(304, [], '');
                }
            }

            return $response;
        });

        $request->setController('');
        $request->setAction('');
        $request->setRoute('');
        static::send($connection, $callback($request), $request);
        return true;
    }

    /**
     * Send response to connection.
     */
    protected static function send(mixed $connection, mixed $response, mixed $request): void
    {
        Log::info("");

        Context::destroy();

        $keepAlive = $request->header('connection');
        $isKeepAlive = false;
        if ($keepAlive === null) {
            $isKeepAlive = $request->protocolVersion() === '1.1';
        } elseif (strcasecmp($keepAlive, 'keep-alive') === 0) {
            // 快路径：绝大多数连接头就是单一 "keep-alive"/"close" 值，
            // 免去每请求的 strtolower+explode+array_map+in_array 开销
            $isKeepAlive = true;
        } else {
            // Handle comma-separated values like "Keep-Alive, TE"
            $tokens = array_map('trim', explode(',', strtolower($keepAlive)));
            $isKeepAlive = in_array('keep-alive', $tokens, true);
        }

        if ($isKeepAlive || (is_a($response, \LarkFrame\Response::class) && $response->getHeader('Transfer-Encoding') === 'chunked')) {
            $connection->send($response);
            return;
        }

        $connection->close($response);
    }

    /**
     * Execute a PHP file and return its output.
     */
    public static function execPhpFile(string $file): string|false
    {
        ob_start();
        try {
            include $file;
        } catch (Exception $e) {
            echo $e;
        }
        return ob_get_clean();
    }

    /**
     * Get config value.
     */
    protected static function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }

    /**
     * Convert data to string representation.
     */
    protected static function stringify(mixed $data): string
    {
        return match (gettype($data)) {
            'boolean' => $data ? 'true' : 'false',
            'NULL' => 'NULL',
            'array' => 'Array',
            'object' => method_exists($data, '__toString') ? (string)$data : 'Object',
            default => (string)$data,
        };
    }

    // ─── Application entry point (merged from LarkFrame\App) ──────────────────

    /**
     * Run the application.
     */
    public static function run(string $runType): void
    {
        if (!defined('ROOT_PATH')) {
            exit('Please define ROOT_PATH constant');
        }
        ini_set('display_errors', 'on');
        error_reporting(E_ALL);
        define('RUN_START_TIME', microtime(true));
        $env = [];
        try {
            $envFile = ROOT_PATH . DIRECTORY_SEPARATOR . '.env';
            if (!file_exists($envFile)) {
                file_put_contents($envFile, "APP_NAME=app\r\nTIME_ZONE=Asia/Shanghai\r\nRUN_MODE=prod\r\n");
            }
            $env = Config::loadEnv($envFile);
        } catch (Exception $e) {
            // nothing
        }

        date_default_timezone_set($env['TIME_ZONE'] ?? 'Asia/Shanghai');
        // ?? 必须作用于取值之前：strtolower() 永不返回 null，写在后面时键缺失会先触发警告
        define('RUN_MODE', strtolower($env['RUN_MODE'] ?? 'prod'));
        if (RUN_MODE === 'prod') {
            define('isProd', true);
        } else {
            define('isProd', false);
        }
        define('APP_NAME', $env['APP_NAME'] ?? 'app');

        Config::load();
        Initializer::init(config('database', []));

        define('RUN_TYPE', $runType);

        switch (RUN_TYPE) {
            case \LarkFrame\Consts::RUN_TYPE_SERVER:
                static::runAsServer();
                break;
            case \LarkFrame\Consts::RUN_TYPE_SHELL:
            case \LarkFrame\Consts::RUN_TYPE_WEB:
                $response = static::runAsNormal();
                if ($response instanceof Response) {
                    if ($response->file !== null) {
                        // 文件响应：通过 __toString 触发 WebSender 输出 headers + 文件正文
                        // WebSender::formatFileResponse 内部调用 http_response_code() + header() + readfile()
                        echo $response;
                    } else {
                        http_response_code($response->getStatusCode());
                        foreach ($response->getHeaders() as $name => $value) {
                            if (strtolower($name) === 'server' || strtolower($name) === 'connection' || strtolower($name) === 'content-length') {
                                continue;
                            }
                            header("$name: $value");
                        }
                        echo $response->rawBody();
                    }
                } else {
                    echo $response;
                }
                break;
            case \LarkFrame\Consts::RUN_TYPE_TASK:
                static::runAsTask();
                break;
            default:
                echo "Unknown run type: " . RUN_TYPE . "\n";
        }
    }

    /**
     * 统一注册错误处理器。
     *
     * 所有运行模式（Server/Task/Web/Shell）使用同一套 error 配置：
     *   - error.catch = true: 使用 config('error.handler') 注册，logger 走 config('error.options.logger')
     *   - error.catch = false: Server/Task 退化为最基本的 error→exception 转换；
     *                          Web/Shell 不注册（保持 PHP 默认行为）
     *
     * @param bool $throwOnError Server/Task 为 true：错误转 ErrorException 抛出，由 onMessage
     *                           try-catch 或 set_exception_handler 统一记录日志；
     *                           Web/Shell 为 false：记录日志并抑制错误。
     * @param bool $skipForStaticFiles Web/Shell 模式下跳过静态资源请求（ROUTE_VALUE 含 '.'）。
     */
    protected static function registerErrorHandler(bool $throwOnError = false, bool $skipForStaticFiles = false): void
    {
        if (!config('error.catch', false)) {
            if ($throwOnError) {
                set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0): bool {
                    if (error_reporting() & $level) {
                        throw new \ErrorException($message, 0, $level, $file, $line);
                    }
                    return false;
                });
            }
            return;
        }
        if ($skipForStaticFiles && defined('ROUTE_VALUE') && str_contains(ROUTE_VALUE, '.')) {
            return;
        }
        $errorHandler = config('error.handler', \LarkFrame\ErrorHandler::class);
        if ($errorHandler && method_exists($errorHandler, 'register')) {
            $options = config('error.options', []);
            call_user_func([$errorHandler, 'register'], $options, $throwOnError);
        }
    }

    /**
     * Run as server (Worker-based).
     */
    protected static function runAsServer(): void
    {
        $config = config('server');
        // 统一经 runtime_path() 解析，使 app.runtime_path 配置对运行时文件生效
        Worker::$pidFile = runtime_path($config['pidFile'] ?? 'server.pid');
        Worker::$stdoutFile = runtime_path($config['stdoutFile'] ?? 'server.stdout.log');
        Worker::$logFile = runtime_path($config['logFile'] ?? 'server.log');
        Worker::$eventLoopClass = $config['eventLoopClass'] ?? '';
        Worker::$daemonize = $config['daemonize'] ?? false;
        TcpConnection::$defaultMaxPackageSize = 10 * 1024 * 1024;

        $listen = $config['socketName'] ?? '127.0.0.1:8080';
        $worker = new Worker($listen, []);
        $worker->name = config('app.name', 'server');
        $worker->count = $config['worker']['count'] ?? 1;
        $worker->reusePort = $config['worker']['reusePort'] ?? true;

        $accessLogName = $config['accessLog'] ?? 'default';

        $worker->onWorkerStart = function ($worker) use ($accessLogName) {
            $worker = $worker ?? null;
            if (empty(Worker::$eventLoopClass)) {
                Worker::$eventLoopClass = Select::class;
            }

            if ($worker) {
                // 用 microtime(true) 获取浮点秒，否则 time() 整数秒差值恒为整数，<= 0.1 等价于 <= 0
                register_shutdown_function(function (float $startTime) {
                    if (microtime(true) - $startTime <= 0.1) {
                        sleep(1);
                    }
                }, microtime(true));
            }

            Config::clear();
            Config::load();
            // 统一错误处理器：error 配置生效后注册，throwOnError=true 将错误转为 ErrorException
            // 由 onMessage 的 try-catch 记录到日志（含完整请求上下文）
            static::registerErrorHandler(true);
            \LarkFrame\Route::load();
            Middleware::load(config('server.middleware', []));

            $app = new static(Request::class, \LarkFrame\Log::channel($accessLogName));
            $worker->onMessage = $app->onMessage(...);
            $app->onWorkerStart($worker);
        };

        Worker::runAll();
    }

    /**
     * Run as normal (web/shell) request.
     */
    protected static function runAsNormal(): mixed
    {
        $consts = \LarkFrame\Consts::class;
        if (!in_array(RUN_TYPE, [$consts::RUN_TYPE_SHELL, $consts::RUN_TYPE_WEB])) {
            return "Error Run Type";
        }

        if (RUN_TYPE == $consts::RUN_TYPE_WEB) {
            $method = strtoupper($_SERVER['REQUEST_METHOD']);
            // 用 parse_url 提取 path，避免 str_replace 替换 URI 中所有匹配位置导致路径破坏
            $route = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
            if (str_ends_with($route, "/") || str_ends_with($route, "?")) {
                $route = substr($route, 0, strlen($route) - 1);
            }
            if (!$route) {
                $route = '/';
            }
            if (str_starts_with($route, "/.")) {
                return '403 Forbidden';
            }
        } else {
            $method = "SHELL";
            $route = $_SERVER['argv'][1] ?? '';
            if (!$route) {
                $route = '/';
            }
            // Auto-prepend '/' for route matching if not present
            if (!str_starts_with($route, '/')) {
                $route = '/' . $route;
            }
        }

        $route = preg_replace("~[\/]{2,}~", "/", $route);
        define('ROUTE_VALUE', $route);

        static::registerErrorHandler(false, true);

        \LarkFrame\Route::load();
        // 全局中间件在 dispatch 前加载：404/405 兜底管道同样需要
        //（CORS 预检等未命中路由的请求不走 FOUND 分支）
        Middleware::load(config('server.middleware', []));
        $request = new Request();
        Context::set(Request::class, $request);

        try {
            $routeInfo = \LarkFrame\Route::dispatch($method, $route);

            if ($routeInfo[0] === Dispatcher::FOUND) {
                $routeInfo[0] = 'route';
                $callback = $routeInfo[1]['callback'];
                $routeObj = $routeInfo[1]['route'] ?? null;
                $args = $routeInfo[2] ?? [];

                $controller = $callback[0];
                $action = $callback[1] ?? 'index';
                $actionSuffix = \LarkFrame\Route::getActionSuffix();
                if ($actionSuffix && !str_contains($action, $actionSuffix)) {
                    $action .= $actionSuffix;
                }
                $callback[1] = $action;

                // 与 Server 模式 findRoute 对齐：路由参数注入 RouteDefinition，
                // 默认参数 + URL 参数合并后按命名参数传给 action
                if ($routeObj && $args) {
                    $routeObj->setParams($args);
                }
                $callArgs = $routeObj ? array_merge($routeObj->param(), $args) : $args;

                // 与 Server 模式对齐：洋葱管道 + 响应归一 + 异常转 500
                // （全局中间件已在 dispatch 前统一 load）
                $callback = static::getCallback($callback, $callArgs, $routeObj);

                $request->setController($controller ?: '');
                $request->setAction($action);
                $request->setRoute($routeObj);

                $result = $callback($request);

                \LarkFrame\Log::info("");
                return $result;
            }

            // CLI（Shell 模式）无 DOCUMENT_URI，用 ?? 兜底避免 undefined key warning
            if (!str_ends_with($route, '.php') && $route != ($_SERVER['DOCUMENT_URI'] ?? null)) {
                $filePath = realpath(ROOT_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $route);
                if ($filePath && file_exists($filePath) && is_file($filePath)) {
                    $result = (new Response())->file($filePath);
                    \LarkFrame\Log::info("");
                    return $result;
                }
            }
            $errorPage = config('error_page.404', null);
            // Shell 模式保持纯文本输出；Web 模式必须返回 Response(404)，
            // 字符串会被 run() 直接 echo 成 200 状态码
            if (RUN_TYPE === $consts::RUN_TYPE_SHELL) {
                return $errorPage ? redirect($errorPage) : "404 Not Found";
            }
            $fallback = static function () use ($errorPage): Response {
                if ($errorPage) {
                    return redirect($errorPage);
                }
                return new Response(404, ['Content-Type' => 'text/html; charset=utf-8'], static::buildErrorPage(404));
            };
            return Middleware::wrapGlobal($fallback)($request);
        } finally {
            // 请求级 Context（Request、连接归还回调等）在响应产出后清理，
            // 对齐 Server 模式 send() 的 Context::destroy()
            Context::destroy();
        }
    }

    /**
     * Run as task (Worker-based, resident memory).
     *
     * Usage: php task.php <taskname> {start|stop|restart|reload|status} [args]
     * Default action is 'start' if omitted.
     */
    protected static function runAsTask(): void
    {
        if (PHP_SAPI != 'cli') {
            return;
        }
        $taskName = $_SERVER['argv'][1] ?? '';
        if (!$taskName) {
            echo "Usage: php task.php <taskname> {start|stop|restart|reload|status} [args]\n";
            return;
        }

        $taskConfig = config('task.' . $taskName, []);

        if (empty($taskConfig) || !isset($taskConfig['handler'])
            || !class_exists($taskConfig['handler']) || !method_exists($taskConfig['handler'], 'run')) {
            echo ($taskConfig['handler'] ?? $taskName) . " not useable\n";
            return;
        }

        // Task action: argv[2], default 'start'; task args: argv[3]
        $action = $_SERVER['argv'][2] ?? 'start';
        $taskArgs = $_SERVER['argv'][3] ?? '';

        $handler = $taskConfig['handler'];
        $options = $taskConfig['options'] ?? [];
        parse_str($taskArgs, $taskArgsParsed);

        $workerCount = $taskConfig['worker']['count'] ?? 1;
        $daemonize = $taskConfig['daemonize'] ?? false;

        Worker::$pidFile = runtime_path($taskConfig['pidFile'] ?? "task-$taskName.pid");
        Worker::$stdoutFile = runtime_path($taskConfig['stdoutFile'] ?? "task-$taskName.stdout.log");
        Worker::$logFile = runtime_path($taskConfig['logFile'] ?? "task-$taskName.log");
        Worker::$daemonize = $daemonize;

        $worker = new Worker();
        $worker->name = "task:$taskName";
        $worker->count = $workerCount;

        $worker->onWorkerStart = function ($worker) use ($handler, $options, $taskArgsParsed) {
            if (empty(Worker::$eventLoopClass)) {
                Worker::$eventLoopClass = Select::class;
            }

            Config::clear();
            Config::load();
            static::registerErrorHandler(true);

            call_user_func([$handler, 'run'], $options, $taskArgsParsed);
        };

        // Restructure internal argv for Worker::parseCommand():
        // User command:    php task.php <taskname> [action] [args]
        // Worker expects:  php task.php <action> [args]
        // So we remove taskname from argv[1] and put action there instead
        $newArgv = [$_SERVER['argv'][0], $action];
        for ($i = 3; $i < count($_SERVER['argv']); $i++) {
            $newArgv[] = $_SERVER['argv'][$i];
        }
        $_SERVER['argv'] = $newArgv;
        global $argv;
        $argv = $_SERVER['argv'];

        Worker::runAll();
    }

}
