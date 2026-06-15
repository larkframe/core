# Redis

`LarkFrame\Cache\Redis` 封装 Illuminate Redis，提供静态代理调用和连接管理。

## 基本用法

```php
use LarkFrame\Cache\Redis;

// 字符串操作
Redis::set('key', 'value');
Redis::setEx('key', 3600, 'value');  // 带过期时间
Redis::get('key');
Redis::del('key');

// Hash 操作
Redis::hSet('user:1', 'name', 'John');
Redis::hGet('user:1', 'name');
Redis::hGetAll('user:1');

// List 操作
Redis::lPush('queue', 'job1');
Redis::rPush('queue', 'job2');
Redis::lPop('queue');

// Set 操作
Redis::sAdd('tags', 'php');
Redis::sMembers('tags');

// Sorted Set 操作
Redis::zAdd('scores', 100, 'player1');
Redis::zRange('scores', 0, -1, true);

// 键操作
Redis::exists('key');
Redis::expire('key', 3600);
Redis::ttl('key');
Redis::keys('user:*');
```

## 多连接切换

```php
// 切换连接
Redis::use('cache')->set('key', 'value');
Redis::use('queue')->lPush('jobs', $job);

// 切换连接 + 数据库
Redis::use('cache', 1)->set('key', 'value');   // cache 连接，database 1
Redis::use('default', 2)->get('key');           // default 连接，database 2
```

## 配置

```php
// config/config.php
'redis' => [
    'client' => 'phpredis',  // 或 'predis'
    'default' => [
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 0,
    ],
    'cache' => [
        'host' => '127.0.0.1',
        'password' => null,
        'port' => 6379,
        'database' => 1,
    ],
]
```

## 连接池

在 Server 模式下，Redis 自动使用连接池管理：

- 每个连接名维护独立的连接池
- 支持空闲连接回收和心跳检测
- `Redis::use('cache', 1)` 会动态创建独立连接池
