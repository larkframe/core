<?php

namespace LarkFrame\Cache;

use InvalidArgumentException;
use RedisException;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\Psr16Cache;
use Throwable;
use WeakMap;

/**
 * Class Cache
 * @package Core\Cache
 *
 * @method static mixed get($key, $default = null)
 * @method static bool set($key, $value, $ttl = null)
 * @method static bool delete($key)
 * @method static bool clear()
 * @method static iterable getMultiple($keys, $default = null)
 * @method static bool setMultiple($values, $ttl = null)
 * @method static bool deleteMultiple($keys)
 * @method static bool has($key)
 */
class Cache
{
    /**
     * @var Psr16Cache[]
     */
    public static array $instances = [];

    /**
     * WeakMap for tracking Redis-based cache instances.
     */
    public static WeakMap $weakMap;

    /**
     * Supported cache drivers.
     */
    private const DRIVER_MAP = [
        'redis' => 'createRedisCache',
        'file' => 'createFileCache',
        'array' => 'createArrayCache',
        'apcu' => 'createApcuCache',
    ];

    /**
     * Get a cache store instance.
     *
     * @throws CacheException|Throwable|RedisException
     */
    public static function store(?string $name = null): Psr16Cache
    {
        static::$weakMap ??= new WeakMap();

        $name = $name ?: config('cache.default', 'redis');
        $stores = !config('cache') ? [
            'redis' => ['driver' => 'redis', 'connection' => 'default'],
        ] : config('cache.stores', []);

        if (!isset($stores[$name])) {
            throw new InvalidArgumentException("cache.store.$name is not defined. Please check config/cache.php");
        }

        $driver = $stores[$name]['driver'];

        // Redis uses WeakMap tracking, not static instances
        if ($driver === 'redis') {
            return static::createRedisCache($stores[$name]);
        }

        if (!isset(static::$instances[$name])) {
            $creator = self::DRIVER_MAP[$driver] ?? null;
            if ($creator === null) {
                throw new InvalidArgumentException("cache.store.$name.driver=$driver is not supported.");
            }
            static::$instances[$name] = static::$creator($stores[$name]);
        }

        return static::$instances[$name];
    }

    /**
     * Create Redis cache with WeakMap tracking.
     */
    private static function createRedisCache(array $config): Psr16Cache
    {
        $redis = Redis::connection($config['connection']);
        if (isset(static::$weakMap[$redis])) {
            return static::$weakMap[$redis];
        }

        $cache = new Psr16Cache(new RedisAdapter($redis->client()));
        static::$weakMap[$redis] = $cache;

        return $cache;
    }

    /**
     * Create file cache.
     */
    private static function createFileCache(array $config): Psr16Cache
    {
        return new Psr16Cache(new FilesystemAdapter('', 0, $config['path']));
    }

    /**
     * Create array cache.
     */
    private static function createArrayCache(array $config): Psr16Cache
    {
        return new Psr16Cache(new ArrayAdapter(0, $config['serialize'] ?? false, 0, 0));
    }

    /**
     * Create APCu cache.
     */
    private static function createApcuCache(array $config): Psr16Cache
    {
        return new Psr16Cache(new ApcuAdapter('', 0));
    }

    /**
     * Proxy static calls to default store.
     */
    public static function __callStatic(string $name, array $arguments): mixed
    {
        return static::store()->{$name}(...$arguments);
    }
}
