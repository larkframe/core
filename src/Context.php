<?php

namespace LarkFrame;

use LarkFrame\Coroutine\Context as CoroutineContext;
use Closure;

class Context extends CoroutineContext
{
    /**
     * Register a callback to be executed when the current context is destroyed.
     *
     * @param Closure $closure
     * @return void
     */
    public static function onDestroy(Closure $closure): void
    {
        $obj = static::get('context.onDestroy');
        if (!$obj) {
            $obj = new \stdClass();
            static::set('context.onDestroy', $obj);
        }
        Coroutine\DestructionWatcher::watch($obj, $closure);
    }
}
