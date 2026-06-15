<?php

namespace LarkFrame;

use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteCollector;
use LarkFrame\RouteDefinition;
use function array_values;
use function class_exists;
use function explode;
use function FastRoute\simpleDispatcher;
use function is_array;
use function is_callable;
use function is_file;
use function is_scalar;
use function is_string;
use function json_encode;
use function method_exists;
use function strpos;

/**
 * Class Route
 * @package Lark
 */
class Route
{
    /**
     * @var self
     */
    protected static $instance = null;

    public function __construct() {}

    /**
     * @var GroupCountBased
     */
    protected static $dispatcher = null;

    /**
     * @var RouteCollector
     */
    protected static $collector = null;

    /**
     * @var array
     */
    protected static $nameList = [];

    /**
     * @var string
     */
    protected static $groupPrefix = '';

    /**
     * @var RouteDefinition[]
     */
    protected static $allRoutes = [];

    /**
     * @var RouteDefinition[]
     */
    protected $routes = [];

    /**
     * @var self[]
     */
    protected $children = [];

    /**
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    public static function get(string $path, $callback): RouteDefinition
    {
        return static::addRoute('GET', $path, $callback);
    }

    public static function shell(string $path, $callback): RouteDefinition
    {
        return static::addRoute('SHELL', $path, $callback);
    }

    /**
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    public static function post(string $path, $callback): RouteDefinition
    {
        return static::addRoute('POST', $path, $callback);
    }

    /**
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    public static function put(string $path, $callback): RouteDefinition
    {
        return static::addRoute('PUT', $path, $callback);
    }

    /**
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    public static function patch(string $path, $callback): RouteDefinition
    {
        return static::addRoute('PATCH', $path, $callback);
    }

    /**
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    public static function delete(string $path, $callback): RouteDefinition
    {
        return static::addRoute('DELETE', $path, $callback);
    }

    /**
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    public static function head(string $path, $callback): RouteDefinition
    {
        return static::addRoute('HEAD', $path, $callback);
    }

    /**
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    public static function options(string $path, $callback): RouteDefinition
    {
        return static::addRoute('OPTIONS', $path, $callback);
    }

    /**
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    public static function any(string $path, $callback): RouteDefinition
    {
        return static::addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'HEAD', 'OPTIONS','SHELL'], $path, $callback);
    }

    /**
     * @param $method
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    public static function add($method, string $path, $callback): RouteDefinition
    {
        return static::addRoute($method, $path, $callback);
    }

    /**
     * @param string|callable $path
     * @param callable|null $callback
     * @return self
     */
    public static function group($path, ?callable $callback = null): self
    {
        if ($callback === null) {
            $callback = $path;
            $path = '';
        }
        $previousGroupPrefix = static::$groupPrefix;
        static::$groupPrefix = $previousGroupPrefix . $path;
        $previousInstance = static::$instance;
        $instance = static::$instance = new self;
        if (static::$collector) {
            static::$collector->addGroup($path, $callback);
        }
        static::$groupPrefix = $previousGroupPrefix;
        static::$instance = $previousInstance;
        if ($previousInstance) {
            $previousInstance->addChild($instance);
        }
        return $instance;
    }

    /**
     * @return RouteDefinition[]
     */
    public static function getRoutes(): array
    {
        return static::$allRoutes;
    }

    /**
     * @param RouteDefinition $route
     */
    public function collect(RouteDefinition $route)
    {
        $this->routes[] = $route;
    }

    /**
     * @param string $name
     * @param RouteDefinition $instance
     */
    public static function setByName(string $name, RouteDefinition $instance)
    {
        static::$nameList[$name] = $instance;
    }

    /**
     * @param string $name
     * @return null|RouteDefinition
     */
    public static function getByName(string $name): ?RouteDefinition
    {
        return static::$nameList[$name] ?? null;
    }

    /**
     * @param Route $route
     * @return void
     */
    public function addChild(Route $route)
    {
        $this->children[] = $route;
    }

    /**
     * @return Route[]
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * @param string $method
     * @param string $path
     * @return array
     */
    public static function dispatch(string $method, string $path): array
    {
        return static::$dispatcher->dispatch($method, $path);
    }

    /**
     * @param string $path
     * @param callable|mixed $callback
     * @return callable|false|string[]
     * @throws \RuntimeException when callback is not callable
     */
    public static function convertToCallable(string $path, $callback)
    {
        if (is_string($callback) && strpos($callback, '@')) {
            $callback = explode('@', $callback, 2);
        }

        $actionSuffix = static::getActionSuffix();

        if (!is_array($callback)) {
            if (!is_callable($callback)) {
                $callStr = is_scalar($callback) ? $callback : 'Closure';
                throw new \RuntimeException("Route $path $callStr is not callable");
            }
        } else {
            $callback = array_values($callback);
            if ($actionSuffix && str_contains($callback[1], $actionSuffix)) {
                $callback[1] = str_replace($actionSuffix, '', $callback[1]);
            }
            $methodWithSuffix = $callback[1] . $actionSuffix;
            if (!isset($callback[1]) || !class_exists($callback[0]) || !method_exists($callback[0], $methodWithSuffix)) {
                throw new \RuntimeException("Route $path " . json_encode($callback) . " is not callable (method {$callback[0]}::{$methodWithSuffix} does not exist)");
            }
        }

        return $callback;
    }

    /**
     * Get the action method suffix (configurable via config).
     * Defaults to 'Action'. Set config('route.action_suffix', '') to disable.
     */
    public static function getActionSuffix(): string
    {
        static $suffix = null;
        if ($suffix === null) {
            $suffix = Config::get('route.action_suffix', 'Action');
        }
        return $suffix;
    }

    /**
     * @param array|string $methods
     * @param string $path
     * @param callable|mixed $callback
     * @return RouteDefinition
     */
    protected static function addRoute($methods, string $path, $callback): RouteDefinition
    {
        $route = new RouteDefinition($methods, static::$groupPrefix . $path, $callback);
        static::$allRoutes[] = $route;

        if (static::$collector) {
            try {
                $callable = static::convertToCallable($path, $callback);
                static::$collector->addRoute($methods, $path, ['callback' => $callable, 'route' => $route]);
            } catch (\RuntimeException $e) {
                // Log the error but don't crash — route is still tracked in $allRoutes
                trigger_error($e->getMessage(), E_USER_WARNING);
            }
        }
        if (static::$instance) {
            static::$instance->collect($route);
        }
        return $route;
    }

    /**
     * Load.
     * @param mixed $paths
     * @return void
     */
    public static function load()
    {
        static::$dispatcher = simpleDispatcher(function (RouteCollector $route) {
            Route::setCollector($route);
            $routeConfigFile = ROOT_PATH . '/config/route.php';
            if (is_file($routeConfigFile)) {
                require_once $routeConfigFile;
            }
        });
    }

    /**
     * SetCollector.
     * @param RouteCollector $route
     * @return void
     */
    public static function setCollector(RouteCollector $route)
    {
        static::$collector = $route;
    }
}
