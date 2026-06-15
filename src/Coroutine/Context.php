<?php

namespace LarkFrame\Coroutine;

use ArrayObject;
use Closure;
use Fiber;
use WeakMap;

/**
 * Class Context
 *
 * Coroutine-safe context storage based on PHP Fiber + WeakMap.
 * Each fiber has its own isolated context data.
 * When not in a fiber, a shared non-fiber context is used.
 */
class Context
{
    /**
     * Map from Fiber to ArrayObject (auto-cleaned when Fiber is GC'd).
     */
    private static WeakMap $contexts;

    /**
     * Context for non-fiber environment.
     */
    private static ArrayObject $nonFiberContext;

    /**
     * Get the value from the context with the specified name.
     * If name is null, return the entire context data.
     */
    public static function get(?string $name = null, mixed $default = null): mixed
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            return $name !== null ? (self::$nonFiberContext[$name] ?? $default) : self::$nonFiberContext;
        }

        if (!isset(self::$contexts[$fiber])) {
            return $default;
        }

        if ($name === null) {
            return self::$contexts[$fiber];
        }

        return self::$contexts[$fiber][$name] ?? $default;
    }

    /**
     * Set the value in the context with the specified name.
     */
    public static function set(string $name, mixed $value): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            self::$nonFiberContext[$name] = $value;
            return;
        }

        self::$contexts[$fiber] ??= new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        self::$contexts[$fiber][$name] = $value;
    }

    /**
     * Check if the specified name exists in the context.
     */
    public static function has(string $name): bool
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            return self::$nonFiberContext->offsetExists($name);
        }

        return isset(self::$contexts[$fiber]) && self::$contexts[$fiber]->offsetExists($name);
    }

    /**
     * Initialize/reset the context with data.
     */
    public static function reset(?ArrayObject $data = null): void
    {
        $data ??= new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
        $data->setFlags(ArrayObject::ARRAY_AS_PROPS);

        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            self::$nonFiberContext = $data;
            return;
        }

        self::$contexts[$fiber] = $data;
    }

    /**
     * Destroy the current context.
     * Triggers onDestroy callbacks before clearing data.
     */
    public static function destroy(): void
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            // In non-Fiber mode, trigger onDestroy callbacks before clearing
            $onDestroyObj = self::$nonFiberContext['context.onDestroy'] ?? null;
            if ($onDestroyObj !== null) {
                unset(self::$nonFiberContext['context.onDestroy']);
            }
            self::$nonFiberContext = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
            return;
        }

        // In Fiber mode, explicitly trigger onDestroy callbacks before removing context
        // (GC timing is unpredictable, so we can't rely on DestructionWatcher alone)
        if (isset(self::$contexts[$fiber])) {
            $onDestroyObj = self::$contexts[$fiber]['context.onDestroy'] ?? null;
            if ($onDestroyObj !== null) {
                // Remove the reference so DestructionWatcher's WeakMap can detect it
                unset(self::$contexts[$fiber]['context.onDestroy']);
                // Force GC to collect the orphaned object and trigger DestructionWatcher callbacks
                gc_collect_cycles();
            }
        }

        unset(self::$contexts[$fiber]);
    }

    /**
     * Register a callback to be executed when the current context is destroyed.
     */
    public static function onDestroy(Closure $closure): void
    {
        $obj = self::get('context.onDestroy');

        if (!$obj) {
            $obj = new \stdClass();
            self::set('context.onDestroy', $obj);
        }

        DestructionWatcher::watch($obj, $closure);
    }

    /**
     * Initialize the WeakMap and non-fiber context.
     */
    public static function init(): void
    {
        self::$contexts = new WeakMap();
        self::$nonFiberContext = new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
    }
}

Context::init();
