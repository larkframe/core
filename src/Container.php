<?php

namespace LarkFrame;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Named exception classes for Container errors.
 * Replaces anonymous classes to avoid repeated class creation overhead.
 */
class ContainerException extends \Exception implements ContainerExceptionInterface {}
class NotFoundException extends \Exception implements NotFoundExceptionInterface {}

use Throwable;
use function array_key_exists;
use function array_slice;
use function class_exists;
use function interface_exists;
use function is_array;
use function is_callable;
use function is_string;

class Container implements ContainerInterface
{
    /**
     * Resolved singleton instances.
     */
    protected array $instances = [];

    /**
     * Service definitions (factories/callables/class names).
     */
    protected array $definitions = [];

    /**
     * Singleton flags: name => true.
     */
    protected array $singletons = [];

    /**
     * Alias mapping: alias => canonical name.
     */
    protected array $aliases = [];

    /**
     * ReflectionClass cache to avoid repeated reflection overhead.
     * 实例属性（P2-6 方案 A）：避免 static 跨实例共享导致测试间状态污染。
     *
     * @var ReflectionClass[]
     */
    protected array $reflectionCache = [];

    /**
     * Fallback global instance when Config has no container configured.
     * Prevents null dereference in __callStatic and infinite recursion.
     */
    private static ?self $globalInstance = null;

    /**
     * Get a service instance (singleton by default).
     */
    public function get(string $name): mixed
    {
        $canonical = $this->resolveAlias($name);

        if (isset($this->instances[$canonical])) {
            return $this->instances[$canonical];
        }

        $instance = $this->resolve($canonical);

        if (isset($this->singletons[$canonical]) || $this->isDefinitionSingleton($canonical)) {
            $this->instances[$canonical] = $instance;
        }

        return $instance;
    }

    /**
     * Check if a service exists (PSR-11: "can the container return this entry").
     * Only returns true for explicitly bound or already-resolved entries;
     * auto-wirable classes are not considered "existing entries".
     */
    public function has(string $name): bool
    {
        $canonical = $this->resolveAlias($name);
        return array_key_exists($canonical, $this->instances)
            || array_key_exists($canonical, $this->definitions);
    }

    /**
     * Bind an interface/abstract to a concrete implementation.
     *
     * @param string $abstract The interface or abstract class name
     * @param string|callable|null $concrete The concrete class name, factory callable, or null for auto-resolution
     * @param bool $singleton Whether to treat as singleton
     */
    public function bind(string $abstract, string|callable|null $concrete = null, bool $singleton = false): self
    {
        if ($concrete === null) {
            $concrete = $abstract;
        }

        $this->dropStaleInstances($abstract);

        $this->definitions[$abstract] = $concrete;

        // P2-8 方案 A：重新绑定时显式管理 singletons 标记，避免原 singleton 重新 bind 为非 singleton 后仍按单例缓存
        if ($singleton) {
            $this->singletons[$abstract] = true;
        } else {
            unset($this->singletons[$abstract]);
        }

        return $this;
    }

    /**
     * Bind as singleton.
     */
    public function singleton(string $abstract, string|callable|null $concrete = null): self
    {
        return $this->bind($abstract, $concrete, true);
    }

    /**
     * Set an existing instance as singleton.
     */
    public function instance(string $abstract, mixed $instance): self
    {
        $canonical = $this->resolveAlias($abstract);
        $this->instances[$canonical] = $instance;
        $this->singletons[$canonical] = true;
        return $this;
    }

    /**
     * Create a new instance without caching (always fresh).
     * Parameter overrides are passed through the call chain to avoid shared mutable state.
     */
    public function make(string $name, array $parameters = []): mixed
    {
        $canonical = $this->resolveAlias($name);
        return $this->resolve($canonical, $parameters);
    }

    /**
     * Add service definitions (batch).
     */
    public function addDefinitions(array $definitions): self
    {
        foreach ($definitions as $name => $definition) {
            $this->definitions[$name] = $definition;
        }
        return $this;
    }

    /**
     * Set an alias.
     */
    public function alias(string $alias, string $abstract): self
    {
        $this->aliases[$alias] = $abstract;
        return $this;
    }

    /**
     * Resolve a service from the container.
     *
     * @param array $overrides Constructor parameter overrides (透传避免共享状态污染)
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    protected function resolve(string $name, array $overrides = []): mixed
    {
        if (isset($this->definitions[$name])) {
            $definition = $this->definitions[$name];

            if (is_callable($definition)) {
                return $definition($this);
            }

            if (is_string($definition) && $definition !== $name) {
                return $this->resolve($definition, $overrides);
            }

            if (is_array($definition)) {
                return $this->resolveArrayDefinition($name, $definition);
            }
        }

        if (!class_exists($name)) {
            throw new NotFoundException("Unable to resolve '$name': class not found and no binding exists");
        }

        return $this->autowire($name, $overrides);
    }

    /**
     * Auto-wire a class by resolving its constructor dependencies.
     *
     * @param array $overrides Constructor parameter overrides (透传)
     * @throws ContainerExceptionInterface
     */
    protected function autowire(string $class, array $overrides = []): object
    {
        $reflector = $this->reflectionCache[$class] ?? null;
        if ($reflector === null) {
            try {
                $reflector = new ReflectionClass($class);
            } catch (\ReflectionException $e) {
                throw new ContainerException("Unable to reflect class '$class': " . $e->getMessage(), 0, $e);
            }
            $this->reflectionCache[$class] = $reflector;
        }

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("Class '$class' is not instantiable (it may be an interface or abstract class). Use bind() to provide a concrete implementation.");
        }

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $parameters = $this->resolveParameters($constructor->getParameters(), $class, $overrides);

        try {
            return $reflector->newInstanceArgs($parameters);
        } catch (\ReflectionException $e) {
            throw new ContainerException("Unable to instantiate '$class': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Resolve constructor parameters.
     *
     * @param array $overrides Constructor parameter overrides (透传，替代实例属性避免并发污染)
     */
    protected function resolveParameters(array $reflectionParameters, string $class, array $overrides = []): array
    {
        $resolved = [];

        foreach ($reflectionParameters as $param) {
            $paramName = $param->getName();

            if (array_key_exists($paramName, $overrides)) {
                $resolved[] = $overrides[$paramName];
                continue;
            }

            $type = $param->getType();

            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();
                if ($this->has($typeName)) {
                    $resolved[] = $this->get($typeName);
                    continue;
                }
                if (class_exists($typeName) || interface_exists($typeName)) {
                    try {
                        $resolved[] = $this->get($typeName);
                        continue;
                    } catch (Throwable $e) {
                        // 可选依赖失败用默认值；必需依赖失败必须抛出，避免真实异常被静默吞掉
                        if ($param->isDefaultValueAvailable() || $param->isOptional()) {
                            continue;
                        }
                        throw $e;
                    }
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $resolved[] = $param->getDefaultValue();
                continue;
            }

            if ($param->isVariadic()) {
                continue;
            }

            $declaringClass = $param->getDeclaringClass()?->getName() ?? $class;
            throw new ContainerException(
                "Unable to resolve parameter '\${$paramName}' in class '{$declaringClass}'. " .
                "Use bind() or provide a default value."
            );
        }

        return $resolved;
    }

    /**
     * Resolve an array definition.
     */
    protected function resolveArrayDefinition(string $name, array $definition): mixed
    {
        if (isset($definition[0]) && is_callable($definition[0])) {
            return $definition[0]($this, ...array_slice($definition, 1));
        }

        if (isset($definition['class'])) {
            $class = $definition['class'];
            $params = $definition['params'] ?? [];
            return $this->make($class, $params);
        }

        throw new ContainerException("Invalid array definition for '$name'");
    }

    /**
     * Resolve alias to canonical name.
     */
    protected function resolveAlias(string $name): string
    {
        $seen = [];
        $maxDepth = 64; // P2-7 方案 A：限制别名链深度，防御恶意超长链 DoS
        while (isset($this->aliases[$name])) {
            if (count($seen) >= $maxDepth) {
                throw new ContainerException("Alias chain too deep (>{$maxDepth}) for '$name'");
            }
            if (isset($seen[$name])) {
                throw new ContainerException("Circular alias detected for '$name'");
            }
            $seen[$name] = true;
            $name = $this->aliases[$name];
        }
        return $name;
    }

    /**
     * Check if a definition is marked as singleton.
     */
    protected function isDefinitionSingleton(string $name): bool
    {
        return isset($this->singletons[$name]);
    }

    /**
     * Drop stale instances for a binding being re-bound.
     */
    protected function dropStaleInstances(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }

    /**
     * Get the global container instance from config.
     * Falls back to a default singleton to prevent null dereference and infinite recursion.
     */
    public static function getInstance(): static
    {
        $fromConfig = \LarkFrame\Config::get('container');
        if ($fromConfig instanceof static) {
            return $fromConfig;
        }
        return self::$globalInstance ??= new self();
    }

    /**
     * Static call forwarding to the global container instance.
     * Supports: Container::get(), Container::make(), Container::has(), etc.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        return static::getInstance()->{$name}(...$arguments);
    }
}
