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
     * Avoids repeated ReflectionClass creation for the same controller.
     *
     * @var array<string, array{hasMiddleware: bool, middleware: array, methods: array<string, ReflectionMethod>}>
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
                    'methods' => [],
                ];
                static::$reflectionCache[$controllerClass] = $cached;
            }

            $reflectionClass = $cached['reflectionClass'];

            // Controller middleware annotation
            self::prepareAttributeMiddlewares($middlewares, $reflectionClass);

            // Controller middleware property
            if ($cached['hasMiddleware']) {
                foreach ((array)$cached['middleware'] as $className) {
                    $middlewares[] = [$className, 'process'];
                }
            }

            // Route middleware
            $middlewares = array_merge($middlewares, $routeMiddlewares);

            // Method middleware annotation (cache ReflectionMethod)
            $methodName = $controller[1];
            $method = $cached['methods'][$methodName] ?? null;
            if ($method === null && $reflectionClass->hasMethod($methodName)) {
                $method = $reflectionClass->getMethod($methodName);
                $cached['methods'][$methodName] = $method;
                static::$reflectionCache[$controllerClass] = $cached;
            }
            if ($method !== null) {
                self::prepareAttributeMiddlewares($middlewares, $method);
            }
        } else {
            // Route middleware
            $middlewares = array_merge($middlewares, $routeMiddlewares);
        }

        return array_reverse($middlewares);
    }

    /**
     * Prepare middlewares from PHP 8 attributes.
     */
    private static function prepareAttributeMiddlewares(array &$middlewares, ReflectionClass|ReflectionMethod $reflection): void
    {
        $middlewareAttributes = $reflection->getAttributes(Annotation\Middleware::class, ReflectionAttribute::IS_INSTANCEOF);
        foreach ($middlewareAttributes as $middlewareAttribute) {
            $middlewareAttributeInstance = $middlewareAttribute->newInstance();
            $middlewares = array_merge($middlewares, $middlewareAttributeInstance->getMiddlewares());
        }
    }
}
