<?php

namespace LarkFrame;

use Closure;
use LarkFrame\Route as Router;
use function array_merge;
use function count;
use function preg_replace_callback;
use function str_replace;

/**
 * Route definition object.
 * Stores a single route's metadata (methods, path, callback, middleware, params).
 * Renamed from Route to avoid confusion with LarkFrame\Route (the route registrar).
 */
class RouteDefinition
{
    /**
     * Route name.
     */
    protected ?string $name = null;

    /**
     * HTTP methods.
     */
    protected array $methods = [];

    /**
     * Route path.
     */
    protected string $path = '';

    /**
     * Route callback.
     */
    protected \Closure|array|string|null $callback = null;

    /**
     * Route middlewares.
     */
    protected array $middlewares = [];

    /**
     * Route parameters.
     */
    protected array $params = [];

    /**
     * Constructor.
     */
    public function __construct(array|string $methods, string $path, mixed $callback)
    {
        $this->methods = (array)$methods;
        $this->path = $path;
        $this->callback = is_callable($callback) ? Closure::fromCallable($callback) : null;
        if ($this->callback === null) {
            $this->callback = $callback;
        }
    }

    /**
     * Get route name.
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * Set route name.
     */
    public function name(string $name): self
    {
        $this->name = $name;
        Router::setByName($name, $this);
        return $this;
    }

    /**
     * Get or set middleware(s).
     */
    public function middleware(mixed $middleware = null): self|array
    {
        if ($middleware === null) {
            return $this->middlewares;
        }
        $this->middlewares = array_merge(
            $this->middlewares,
            is_array($middleware) ? array_reverse($middleware) : [$middleware]
        );
        return $this;
    }

    /**
     * Get path.
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get methods.
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * Get callback.
     */
    public function getCallback(): mixed
    {
        return $this->callback;
    }

    /**
     * Get middlewares.
     */
    public function getMiddleware(): array
    {
        return $this->middlewares;
    }

    /**
     * Get or get all parameters.
     */
    public function param(?string $name = null, mixed $default = null): mixed
    {
        if ($name === null) {
            return $this->params;
        }
        return $this->params[$name] ?? $default;
    }

    /**
     * Set parameters.
     */
    public function setParams(array $params): self
    {
        $this->params = [...$this->params, ...$params];
        return $this;
    }

    /**
     * Generate URL with parameters.
     */
    public function url(array $parameters = []): string
    {
        if ($parameters === []) {
            return $this->path;
        }

        $path = str_replace(['[', ']'], '', $this->path);
        $path = preg_replace_callback('/\{(.*?)(?:\:[^\}]*?)*?\}/', function ($matches) use (&$parameters) {
            if ($parameters === []) {
                return $matches[0];
            }
            if (isset($parameters[$matches[1]])) {
                $value = $parameters[$matches[1]];
                unset($parameters[$matches[1]]);
                return $value;
            }
            $key = key($parameters);
            if (is_int($key)) {
                $value = $parameters[$key];
                unset($parameters[$key]);
                return $value;
            }
            return $matches[0];
        }, $path);

        return count($parameters) > 0 ? $path . '?' . http_build_query($parameters) : $path;
    }
}

// Backward-compatible alias (only if Route class doesn't already exist)
if (!class_exists(\LarkFrame\Route::class)) {
    class_alias(RouteDefinition::class, \LarkFrame\Route::class);
}
