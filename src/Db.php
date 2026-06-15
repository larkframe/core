<?php

namespace LarkFrame;

use Closure;
use Illuminate\Database\Connection;
use LarkFrame\Database\Manager;

/**
 * Class Db
 * @package support
 * @method static array select(string $query, $bindings = [], $useReadPdo = true)
 * @method static int insert(string $query, $bindings = [])
 * @method static int update(string $query, $bindings = [])
 * @method static int delete(string $query, $bindings = [])
 * @method static bool statement(string $query, $bindings = [])
 * @method static mixed transaction(Closure $callback, $attempts = 1)
 * @method static void beginTransaction()
 * @method static void rollBack($toLevel = null)
 * @method static void commit()
 */
class Db extends Manager
{
    /**
     * Get a database connection instance by name.
     * Shortcut for switching between multiple databases.
     *
     * Usage:
     *   Db::use('mysql')       — use the 'mysql' connection
     *   Db::use('mysql_slave') — use the 'mysql_slave' connection
     */
    public static function use(string $name): Connection
    {
        return static::connection($name);
    }
}