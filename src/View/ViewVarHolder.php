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
            return \LarkFrame\Context::get('_view_vars', []);
        }

        return self::$vars;
    }

    /**
     * Clear all assigned variables (typically called after rendering).
     */
    public static function clear(): void
    {
        static::saveVars([]);
    }

    /**
     * Save variables to the appropriate storage.
     */
    private static function saveVars(array $vars): void
    {
        if (static::isServerMode()) {
            \LarkFrame\Context::set('_view_vars', $vars);
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
