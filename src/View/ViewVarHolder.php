<?php

namespace LarkFrame\View;

use Fiber;

/**
 * Class ViewVarHolder
 *
 * Decoupled storage for view variables.
 * In server mode (long-running process), uses Context for per-request isolation.
 * In FPM mode, uses static array (each request is a separate process).
 */
class ViewVarHolder
{
    /**
     * Internal static storage for FPM mode.
     */
    private static array $vars = [];

    /**
     * Assign variables to the view.
     */
    public static function assign(string|array $name, mixed $value = null): void
    {
        $vars = static::getVars();

        if (is_array($name)) {
            $vars = array_merge($vars, $name);
        } else {
            $vars[$name] = $value;
        }

        static::saveVars($vars);
    }

    /**
     * Get all assigned variables.
     */
    public static function getVars(): array
    {
        if (static::isServerMode()) {
            // 请求上下文未写入时回退 static：worker 启动阶段（Fiber 外）assign 的变量
            // 才能被请求 Fiber 内的 render 读到，消除读写存储错位
            return \LarkFrame\Context::get('_view_vars', self::$vars);
        }

        return self::$vars;
    }

    /**
     * Clear all assigned variables (typically called after rendering).
     */
    public static function clear(): void
    {
        static::saveVars([]);
        // P2-47：同时清理 static fallback，防止 server 模式下 Fiber 外写入的变量跨请求泄漏
        self::$vars = [];
    }

    /**
     * Save variables to the appropriate storage.
     */
    private static function saveVars(array $vars): void
    {
        if (static::isServerMode()) {
            \LarkFrame\Context::set('_view_vars', $vars);
            // 双写 static：Fiber 外 assign、Fiber 内 render 的场景下两侧存储保持一致
            self::$vars = $vars;
            return;
        }

        self::$vars = $vars;
    }

    /**
     * Check if running in server mode (long-running process).
     * Detects by whether Context has been initialized for request-scoped storage.
     * In server mode, App::onMessage calls Context::reset() which stores the
     * Request object, so we check for its presence as a reliable signal.
     */
    private static function isServerMode(): bool
    {
        // In Fiber context, always use Context for isolation
        if (class_exists(Fiber::class) && \Fiber::getCurrent() !== null) {
            return true;
        }
        // If Context has a Request object, we're in a server-mode request lifecycle
        return \LarkFrame\Context::has(\LarkFrame\Request::class);
    }
}
