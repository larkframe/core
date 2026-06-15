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
     * Ordered keys for LRU eviction (oldest first).
     */
    protected static array $callbackKeys = [];

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
            // Collapse consecutive slashes (faster than preg_replace for the common case)
            while (str_contains($path, '//')) {
                $path = str_replace('//', '/', $path);
            }
            $key = $request->method() . $path;

            if (isset(static::$callbacks[$key])) {
                // Move to end of LRU (most recently used)
                $idx = array_search($key, static::$callbackKeys, true);
                if ($idx !== false) {
                    unset(static::$callbackKeys[$idx]);
                    static::$callbackKeys = array_values(static::$callbackKeys);
                    static::$callbackKeys[] = $key;
                }
                [$callback, $controller, $action, $route] = static::$callbacks[$key];
                $request->setController($controller);
                $request->setAction($action);
                $request->setRoute($route);
                static::send($connection, $callback($request), $request);
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
     * Collect route callbacks with proper LRU eviction.
     * Uses a linked-list approach for O(1) eviction instead of array_values rebuild.
     */
    protected static function collectCallbacks(string $key, array $data): void
    {
        // If key already exists, move it to the end (most recently used)
        if (isset(static::$callbacks[$key])) {
            $idx = array_search($key, static::$callbackKeys, true);
            if ($idx !== false) {
                unset(static::$callbackKeys[$idx]);
                static::$callbackKeys = array_values(static::$callbackKeys);
            }
            static::$callbackKeys[] = $key;
        } else {
            static::$callbackKeys[] = $key;

            // Evict oldest entries if over limit
            while (count(static::$callbacks) >= static::$maxCallbackCache) {
                $oldestKey = array_shift(static::$callbackKeys);
                unset(static::$callbacks[$oldestKey]);
            }
        }

        static::$callbacks[$key] = $data;
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
            $callback = static::getFallback(400);
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

    protected static function notFound(mixed $request): Response
    {
        $errorPage = config('error_page.404', null);
        if ($errorPage) {
            return redirect($errorPage);
        }
        return new \LarkFrame\Response(404, ['Content-Type' => 'text/html; charset=utf-8'], static::buildErrorPage(404));
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
        $response = new \LarkFrame\Response(500, [], static::config('app.debug', true) ? (string)$e : $e->getMessage());
        $response->exception($e);
        return $response;
    }

    public static function getCallback(mixed $call, array $args = [], ?RouteDefinition $route = null): callable
    {
        $isController = is_array($call) && is_string($call[0]);
        $middlewares = Middleware::getMiddleware($call, $route);
        $container = self::container();

        foreach ($middlewares as $key => $item) {
            $middleware = $item[0];
            if (is_string($middleware)) {
                $middleware = $container->get($middleware);
            } elseif ($middleware instanceof Closure) {
                $middleware = $middleware($container);
            }
            $middlewares[$key][0] = $middleware;
        }

        $anonymousArgs = array_values($args);

        if ($isController) {
            $call[0] = $container->get($call[0]);
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
     */
    public static function request(): \LarkFrame\Request
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

            $callback = static::getCallback($callback, $args, $route);
            static::collectCallbacks($key, [$callback, $controller ?: '', $action, $route]);
            $request->setController($controller ?: '');
            $request->setAction($action);
            $request->setRoute($route);
            static::send($connection, $callback($request), $request);
            return true;
        }

        $status = $routeInfo[0] === Dispatcher::METHOD_NOT_ALLOWED ? 405 : 404;
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
        $callback = static::getCallback(function ($request) use ($file) {
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
        if ($keepAlive === null && $request->protocolVersion() === '1.1') {
            $isKeepAlive = true;
        } elseif ($keepAlive !== null) {
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
        define('RUN_MODE', strtolower($env['RUN_MODE']) ?? 'prod');
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
                    http_response_code($response->getStatusCode());
                    foreach ($response->getHeaders() as $name => $value) {
                        if (strtolower($name) === 'server' || strtolower($name) === 'connection' || strtolower($name) === 'content-length') {
                            continue;
                        }
                        header("$name: $value");
                    }
                    echo $response->rawBody();
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
     * Run as server (Worker-based).
     */
    protected static function runAsServer(): void
    {
        $config = config('server');
        Worker::$pidFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . ($config['pidFile'] ?? 'server.pid');
        Worker::$stdoutFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . ($config['stdoutFile'] ?? 'server.stdout.log');
        Worker::$logFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . ($config['logFile'] ?? 'server.log');
        Worker::$eventLoopClass = $config['eventLoopClass'] ?? '';
        Worker::$daemonize = $config['daemonize'] ?? false;
        TcpConnection::$defaultMaxPackageSize = 10 * 1024 * 1024;

        $listen = $config['socketName'] ?? '127.0.0.1:8080';
        $worker = new Worker($listen, []);
        $config = config('server');
        $worker->name = config('app.name', 'server');
        $worker->count = $config['worker']['count'] ?? 1;
        $worker->reusePort = $config['worker']['reusePort'] ?? true;

        $accessLogName = $config['accessLog'] ?? 'default';

        $worker->onWorkerStart = function ($worker) use ($accessLogName) {
            $worker = $worker ?? null;
            if (empty(Worker::$eventLoopClass)) {
                Worker::$eventLoopClass = Select::class;
            }

            set_error_handler(function ($level, $message, $file = '', $line = 0) {
                if (error_reporting() & $level) {
                    throw new \ErrorException($message, 0, $level, $file, $line);
                }
            });

            if ($worker) {
                register_shutdown_function(function ($startTime) {
                    if (time() - $startTime <= 0.1) {
                        sleep(1);
                    }
                }, time());
            }

            Config::clear();
            Config::load();
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
        if (in_array(RUN_TYPE, [$consts::RUN_TYPE_SHELL, $consts::RUN_TYPE_WEB])) {
            if (RUN_TYPE == $consts::RUN_TYPE_WEB) {
                $method = strtoupper($_SERVER['REQUEST_METHOD']);
                $queryString = $_SERVER['QUERY_STRING'] ?? '';
                $route = str_replace($queryString, "", $_SERVER['REQUEST_URI']);
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

            if (config('error.catch', false)) {
                $errorHandler = config("error.handler", null);
                if ($errorHandler !== null && !str_contains(ROUTE_VALUE, '.')) {
                    if (method_exists($errorHandler, "register")) {
                        $errorHandlerOption = config("error.options", []);
                        call_user_func([$errorHandler, 'register'], $errorHandlerOption);
                    }
                }
            }

            \LarkFrame\Route::load();
            $request = new Request();
            Context::set(Request::class, $request);

            $routeInfo = \LarkFrame\Route::dispatch($method, $route);

            if ($routeInfo[0] === Dispatcher::FOUND) {
                $routeInfo[0] = 'route';
                $callback = $routeInfo[1]['callback'];
                $args = !empty($routeInfo[2]) ? $routeInfo[2] : [];
                $anonymousArgs = [];
                if ($args) {
                    $anonymousArgs = array_values($args);
                }
                $controller = $callback[0];
                $action = $callback[1] ?? '';
                $action = $action ?? "index";
                if (!str_contains($action, 'Action')) {
                    $action .= 'Action';
                }
                $callback[1] = $action;

                $container = static::container();
                $call = [$controller, $action];
                $call[0] = $container->get($call[0]);
                if (!empty($anonymousArgs)) {
                    $result = $call($request, ...$anonymousArgs);
                } else {
                    $result = $call($request);
                }

                \LarkFrame\Log::info("");
                return $result;
            } else {
                if (!str_ends_with($route, '.php') && $route != $_SERVER['DOCUMENT_URI']) {
                    $filePath = realpath(ROOT_PATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . $route);
                    if ($filePath && file_exists($filePath) && is_file($filePath)) {
                        $result = (new Response())->file($filePath);
                        \LarkFrame\Log::info("");
                        return $result;
                    }
                }
            }
            $errorPage = config('error_page.404', null);
            if ($errorPage) {
                return redirect($errorPage);
            } else {
                return "404 Not Found";
            }
        } else {
            return "Error Run Type";
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

        Worker::$pidFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . ($taskConfig['pidFile'] ?? "task-$taskName.pid");
        Worker::$stdoutFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . ($taskConfig['stdoutFile'] ?? "task-$taskName.stdout.log");
        Worker::$logFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . ($taskConfig['logFile'] ?? "task-$taskName.log");
        Worker::$daemonize = $daemonize;

        $worker = new Worker();
        $worker->name = "task:$taskName";
        $worker->count = $workerCount;

        $worker->onWorkerStart = function ($worker) use ($handler, $options, $taskArgsParsed) {
            if (empty(Worker::$eventLoopClass)) {
                Worker::$eventLoopClass = Select::class;
            }

            set_error_handler(function ($level, $message, $file = '', $line = 0) {
                if (error_reporting() & $level) {
                    throw new \ErrorException($message, 0, $level, $file, $line);
                }
            });

            Config::clear();
            Config::load();

            call_user_func([$handler, 'run'], $options, $taskArgsParsed);
        };

        // Restructure internal argv for Worker::parseCommand():
        // User command:    php task.php <taskname> [action] [args]
        // Worker expects:  php task.php <action> [args]
        // So we remove taskname from argv[1] and put action there instead
        $action = $_SERVER['argv'][2] ?? 'start';
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
