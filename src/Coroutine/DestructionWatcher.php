<?php

namespace LarkFrame\Coroutine;

use WeakMap;

/**
 * Class DestructionWatcher
 *
 * Watch object destruction and trigger callbacks when the object is garbage collected.
 * Based on WeakMap to avoid memory leaks.
 */
class DestructionWatcher
{
    /**
     * Watched objects and their watcher instances.
     */
    private static WeakMap $objects;

    /**
     * Registered callbacks for the associated object.
     *
     * @var callable[]
     */
    private array $callbacks = [];

    /**
     * Register a callback to be executed when the watched object is destroyed.
     *
     * Uses WeakMap so that when the watched object is garbage collected,
     * this DestructionWatcher instance is also collected, triggering __destruct.
     */
    public static function watch(object $object, callable $callback): void
    {
        self::$objects ??= new WeakMap();
        self::$objects[$object] ??= new self();
        self::$objects[$object]->callbacks[] = $callback;
    }

    /**
     * Destructor - execute all registered callbacks in reverse order (LIFO).
     * 每个回调独立 try/catch，避免单个回调异常中断后续清理。
     */
    public function __destruct()
    {
        foreach (array_reverse($this->callbacks) as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                error_log('[DestructionWatcher] callback threw: ' . $e->getMessage());
            }
        }
    }
}
