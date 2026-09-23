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
 *
 * 静态存储采用懒初始化（访问时 ??=），不依赖文件级副作用执行——
 * opcache 预加载场景下文件只执行一次，类被预载后静态属性状态不可靠。
 */
class Context
{
    /**
     * Map from Fiber to ArrayObject (auto-cleaned when Fiber is GC'd).
     */
    private static ?WeakMap $contexts = null;

    /**
     * Context for non-fiber environment.
     */
    private static ?ArrayObject $nonFiberContext = null;

    /**
     * 懒初始化静态存储，所有公开方法入口调用。
     */
    private static function boot(): void
    {
        self::$contexts ??= new WeakMap();
        self::$nonFiberContext ??= new ArrayObject([], ArrayObject::ARRAY_AS_PROPS);
    }

    /**
     * Get the value from the context with the specified name.
     * If name is null, return the entire context data.
     */
    public static function get(?string $name = null, mixed $default = null): mixed
    {
        self::boot();
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
        self::boot();
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
        self::boot();
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
        self::boot();
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
     *
     * 回调触发依赖引用计数而非 gc_collect_cycles：unset 移除 context 对
     * stdClass 挂载点的引用后，WeakMap 键引用计数归零即被销毁，watcher
     * 的 __destruct 立即执行。强制 GC 是全量根扫描（大堆下百微秒级），
     * 会给每个注册过 onDestroy 的请求（DB/Redis 使用方）增加固定开销。
     */
    public static function destroy(): void
    {
        self::boot();
        $fiber = Fiber::getCurrent();

        if ($fiber === null) {
            // In non-Fiber mode, trigger onDestroy callbacks via GC before clearing
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
            }
        }

        unset(self::$contexts[$fiber]);
    }

    /**
     * Register a callback to be executed when the current context is destroyed.
     */
    public static function onDestroy(Closure $closure): void
    {
        self::boot();
        $obj = self::get('context.onDestroy');

        if (!$obj) {
            $obj = new \stdClass();
            self::set('context.onDestroy', $obj);
        }

        DestructionWatcher::watch($obj, $closure);
    }

    /**
     * Initialize the WeakMap and non-fiber context（兼容入口，现为幂等）.
     */
    public static function init(): void
    {
        self::boot();
    }

    /**
     * P2-27：扫描清理已终止/未启动的 Fiber 上下文，防止长生命周期 worker 中
     * 因外部持有 Fiber 引用导致 WeakMap 中的上下文数据无法回收。
     * 建议在事件循环空闲或定时器中周期性调用（如每 60s）。
     */
    public static function gc(): void
    {
        if (self::$contexts === null) {
            return;
        }
        // Copy keys first — modifying a WeakMap during iteration skips entries
        $fibers = [];
        foreach (self::$contexts as $fiber => $_) {
            $fibers[] = $fiber;
        }
        foreach ($fibers as $fiber) {
            if (!$fiber->isStarted() || $fiber->isTerminated()) {
                // Remove onDestroy reference so DestructionWatcher can detect it
                unset(self::$contexts[$fiber]['context.onDestroy']);
                unset(self::$contexts[$fiber]);
            }
        }
        // Force GC to collect orphaned onDestroy objects and trigger callbacks
        gc_collect_cycles();
    }
}
