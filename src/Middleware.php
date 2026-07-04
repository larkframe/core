<?php

namespace LarkFrame;

use Closure;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use function array_merge;
use function array_reverse;
use function is_array;
use function method_exists;

class Middleware
{
    /**
     * Global middleware instances.
     */
    protected static array $instances = [];

    /**
     * Reflection cache for controller classes.
     * Avoids repeated ReflectionClass creation and attribute parsing for the same controller.
     *
     * @var array<string, array{
     *     reflectionClass: ReflectionClass,
     *     hasMiddleware: bool,
     *     middleware: array,
     *     classAttrs: array,
     *     methods: array<string, array{reflection: ReflectionMethod, attrs: array}>
     * }>
     */
    protected static array $reflectionCache = [];

    /**
     * Load global middlewares.
     */
    public static function load(array $middlewares): void
    {
        foreach ($middlewares as $className) {
            if (class_exists($className) && method_exists($className, 'process')) {
                static::$instances[] = [$className, 'process'];
            }
        }
    }

    /**
     * Get middleware stack for a controller/action.
     *
     * P2-13：明确执行顺序约定（洋葱模型，array_reverse 后最外层先执行）：
     *   注册顺序：全局 → 控制器注解 → 控制器属性 → 路由 → 方法注解
     *   执行顺序（reverse 后）：方法注解 → 路由 → 控制器属性 → 控制器注解 → 全局
     * 即全局中间件最先收到请求、最后处理响应（最外层）。
     */
    public static function getMiddleware(string|array|Closure $controller, RouteDefinition|null $route): array
    {
        $isController = is_array($controller) && is_string($controller[0]);
        $middlewares = static::$instances;
        $routeMiddlewares = [];

        // Route middleware
        if ($route) {
            foreach (array_reverse($route->getMiddleware()) as $className) {
                $routeMiddlewares[] = [$className, 'process'];
            }
        }

        if ($isController && $controller[0] && class_exists($controller[0])) {
            $controllerClass = $controller[0];
            $cached = static::$reflectionCache[$controllerClass] ?? null;

            if ($cached === null) {
                $reflectionClass = new ReflectionClass($controllerClass);
                $cached = [
                    'reflectionClass' => $reflectionClass,
                    'hasMiddleware' => $reflectionClass->hasProperty('middleware'),
                    'middleware' => $reflectionClass->hasProperty('middleware')
                        ? $reflectionClass->getDefaultProperties()['middleware']
                        : [],
                    'classAttrs' => self::parseAttributeMiddlewares($reflectionClass),
                    'methods' => [],
                ];
                static::$reflectionCache[$controllerClass] = $cached;
            }

            // Controller middleware annotation (cached)
            $middlewares = array_merge($middlewares, $cached['classAttrs']);

            // Controller middleware property
            if ($cached['hasMiddleware']) {
                foreach ((array)$cached['middleware'] as $className) {
                    $middlewares[] = [$className, 'process'];
                }
            }

            // Route middleware
            $middlewares = array_merge($middlewares, $routeMiddlewares);

            // Method middleware annotation (cached per method)
            $methodName = $controller[1];
            $methodCache = $cached['methods'][$methodName] ?? null;
            if ($methodCache === null && $cached['reflectionClass']->hasMethod($methodName)) {
                $method = $cached['reflectionClass']->getMethod($methodName);
                $methodCache = [
                    'reflection' => $method,
                    'attrs' => self::parseAttributeMiddlewares($method),
                ];
                $cached['methods'][$methodName] = $methodCache;
                static::$reflectionCache[$controllerClass] = $cached;
            }
            if ($methodCache !== null) {
                $middlewares = array_merge($middlewares, $methodCache['attrs']);
            }
        } else {
            // Route middleware
            $middlewares = array_merge($middlewares, $routeMiddlewares);
        }

        return array_reverse($middlewares);
    }

    /**
     * Parse middlewares from PHP 8 attributes into a flat array.
     * P2-48：结果可缓存，避免每次请求重复 getAttributes + newInstance。
     */
    private static function parseAttributeMiddlewares(ReflectionClass|ReflectionMethod $reflection): array
    {
        $middlewares = [];
        $middlewareAttributes = $reflection->getAttributes(Annotation\Middleware::class, ReflectionAttribute::IS_INSTANCEOF);
        foreach ($middlewareAttributes as $middlewareAttribute) {
            $middlewareAttributeInstance = $middlewareAttribute->newInstance();
            $middlewares = array_merge($middlewares, $middlewareAttributeInstance->getMiddlewares());
        }
        return $middlewares;
    }
}
