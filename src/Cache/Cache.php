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
    protected static array $instances = [];

    /**
     * 默认 store 实例缓存：__callStatic 热路径（Cache::get/set 每次调用）
     * 免去 config 查询与 store 解析。与 $instances 同生命周期，进程内不失效
     * （配置仅 worker 启动期重载，运行期变更缓存配置本就不被支持）。
     */
    protected static ?Psr16Cache $defaultStore = null;

    /**
     * WeakMap for tracking Redis-based cache instances.
     * 结构：Connection 对象 => [storeName => Psr16Cache]
     */
    protected static WeakMap $weakMap;

    /**
     * Supported cache drivers.（redis 在 store() 中提前分派，不经过此表）
     */
    private const DRIVER_MAP = [
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
        // 热路径快通道：默认 store 已解析则直接返回（Cache::get/set 等 __callStatic 每次必经）
        if ($name === null || $name === '') {
            return static::$defaultStore ??= static::resolveStore('');
        }

        return static::resolveStore($name);
    }

    /**
     * Resolve a store instance by name (full path with config lookups).
     */
    protected static function resolveStore(string $name): Psr16Cache
    {
        static::$weakMap ??= new WeakMap();

        $name = $name !== '' ? $name : (string)config('cache.default', 'redis');
        // 半配置（有 cache 键但无 stores）与无配置统一回退默认 redis，行为对称
        $stores = config('cache.stores');
        if (!is_array($stores) || $stores === []) {
            $stores = ['redis' => ['driver' => 'redis', 'connection' => 'default']];
        }

        if (!isset($stores[$name])) {
            throw new InvalidArgumentException("cache.store.{$name} is not defined. Please check the 'cache' section in config/config.php");
        }

        $driver = $stores[$name]['driver'] ?? null;
        if ($driver === null) {
            throw new InvalidArgumentException("cache.store.{$name}.driver is not configured.");
        }

        // Redis uses WeakMap tracking, not static instances
        if ($driver === 'redis') {
            return static::createRedisCache($stores[$name], $name);
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
    private static function createRedisCache(array $config, string $storeName): Psr16Cache
    {
        if (!isset($config['connection'])) {
            throw new InvalidArgumentException('Redis cache store requires a "connection" key.');
        }
        $redis = Redis::connection($config['connection']);
        $storeCaches = static::$weakMap[$redis] ?? [];
        if (isset($storeCaches[$storeName])) {
            return $storeCaches[$storeName];
        }

        // namespace 默认取 store 名：同一 Redis 连接被多个 store 复用时键空间相互隔离，
        // 否则两个 store 命中同一实例，clear() 一个会清掉另一个的数据
        $namespace = (string)($config['namespace'] ?? $storeName);
        $cache = new Psr16Cache(new RedisAdapter($redis->client(), $namespace));
        $storeCaches[$storeName] = $cache;
        static::$weakMap[$redis] = $storeCaches;

        return $cache;
    }

    /**
     * Create file cache.
     *
     * path 支持 Closure 延迟求值（同 Log::handler 的延迟求值约定）：
     * config.php require 时配置未加载，直接调 runtime_path() 只能取默认目录。
     */
    private static function createFileCache(array $config): Psr16Cache
    {
        $path = $config['path'] ?? null;
        if ($path instanceof \Closure) {
            $path = $path();
        }
        if (!is_string($path) || $path === '') {
            throw new InvalidArgumentException('File cache store requires a non-empty "path" key.');
        }
        return new Psr16Cache(new FilesystemAdapter('', 0, $path));
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
