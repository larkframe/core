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
     *
     * 无效中间件（类不存在/无 process 方法）显式抛异常：静默跳过会让
     * 拼错的中间件无声失效（如鉴权中间件失效是安全事故而非可用性问题）。
     */
    public static function load(array $middlewares): void
    {
        foreach ($middlewares as $className) {
            static::$instances[] = static::assertMiddleware($className, 'global');
        }
    }

    /**
     * 校验中间件可调用性，返回 [class, 'process'] 结构。
     *
     * @param mixed $className 中间件类名
     * @param string $source 来源描述（global / route / controller / annotation），用于错误定位
     */
    protected static function assertMiddleware(mixed $className, string $source): array
    {
        if (!is_string($className) || !class_exists($className) || !method_exists($className, 'process')) {
            throw new \RuntimeException(
                sprintf('Invalid %s middleware: %s (class must exist and implement process())', $source, var_export($className, true))
            );
        }
        return [$className, 'process'];
    }

    /**
     * 将 [class, 'process'] 形式的中间件列表实例化为可调用结构。
     *
     * 字符串类名经容器解析（支持构造注入），Closure 工厂以容器为参调用。
     */
    public static function resolveInstances(array $middlewares): array
    {
        $container = \LarkFrame\App::container();
        foreach ($middlewares as $key => $item) {
            $middleware = $item[0];
            if (is_string($middleware)) {
                $middleware = $container->get($middleware);
            } elseif ($middleware instanceof Closure) {
                $middleware = $middleware($container);
            }
            $middlewares[$key][0] = $middleware;
        }
        return $middlewares;
    }

    /**
     * 用全局中间件链包裹兜底回调（404/405/400 响应也走洋葱后置处理）。
     *
     * 必要性：CORS 预检（OPTIONS）通常未注册路由，会命中 405 分支被直接
     * send——不穿中间件管道则 CorsMiddleware 的预检分支永远不执行。
     * 中间件实例化结果缓存：全局中间件在进程生命周期内不变。
     *
     * 注意：全局中间件自此会收到兜底请求（鉴权类全局中间件对 404 页同样生效）。
     */
    public static function wrapGlobal(callable $fallback): callable
    {
        if (static::$instances === []) {
            return $fallback;
        }

        static $resolved = null;
        $resolved ??= static::resolveInstances(static::$instances);

        return array_reduce(
            $resolved,
            static fn(callable $carry, array $pipe): callable => static fn($request) => $pipe($request, $carry),
            $fallback
        );
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
                $routeMiddlewares[] = static::assertMiddleware($className, 'route');
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
                    $middlewares[] = static::assertMiddleware($className, 'controller');
                }
            }

            // Route middleware
            $middlewares = array_merge($middlewares, $routeMiddlewares);

            // Method middleware annotation (cached per method)
            // 单元素 [Controller::class] 形式无方法名，跳过方法级注解而非触发 undefined key
            $methodName = $controller[1] ?? '';
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
            foreach ($middlewareAttributeInstance->getMiddlewares() as $entry) {
                // 注解项为 [class, 'process'] 结构，校验其类名部分
                $middlewares[] = static::assertMiddleware($entry[0] ?? null, 'annotation');
            }
        }
        return $middlewares;
    }
}
