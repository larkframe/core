<?php

namespace LarkFrame\Database;

use Illuminate\Container\Container as IlluminateContainer;
use LarkFrame\Container;
use LarkFrame\Database\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Pagination\Paginator;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Cursor;

class Initializer
{
    private static bool $initialized = false;

    /**
     * Initialize database connections and Eloquent ORM.
     */
    public static function init(array $config): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $connections = $config['connections'] ?? [];
        if (!$connections) {
            return;
        }

        $capsule = new Capsule(IlluminateContainer::getInstance());

        $default = $config['default'] ?? false;
        if ($default && isset($connections[$default])) {
            $capsule->addConnection($connections[$default], $default);
            $capsule->getDatabaseManager()->setDefaultConnection($default);
            unset($connections[$default]);
        }

        foreach ($connections as $name => $connectionConfig) {
            $capsule->addConnection($connectionConfig, $name);
        }

        if (class_exists(Dispatcher::class) && !$capsule->getEventDispatcher()) {
            $capsule->setEventDispatcher(new Dispatcher(IlluminateContainer::getInstance()));
        }

        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        static::setupPaginator();
    }

    /**
     * Setup pagination resolvers.
     */
    private static function setupPaginator(): void
    {
        if (!class_exists(Paginator::class)) {
            return;
        }

        if (method_exists(Paginator::class, 'queryStringResolver')) {
            Paginator::queryStringResolver(fn(): string => request()?->queryString() ?? '');
        }

        Paginator::currentPathResolver(fn(): string => request()?->path() ?? '/');
        Paginator::currentPageResolver(function (string $pageName = 'page'): int {
            $request = request();
            if (!$request) {
                return 1;
            }
            $page = (int)($request->input($pageName, 1));
            return $page > 0 ? $page : 1;
        });

        if (class_exists(CursorPaginator::class)) {
            CursorPaginator::currentCursorResolver(
                fn(string $cursorName = 'cursor') => Cursor::fromEncoded(request()->input($cursorName))
            );
        }
    }
}
