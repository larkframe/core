# 缓存 (Cache)

`LarkFrame\Cache\Cache` 基于 Symfony Cache 组件，实现 PSR-16 缓存接口。

## 基本用法

```php
use LarkFrame\Cache\Cache;

// 设置
Cache::set('key', 'value', 3600);  // 缓存 1 小时

// 获取
$value = Cache::get('key', $default);

// 删除
Cache::delete('key');

// 判断存在
Cache::has('key');

// 批量操作
Cache::setMultiple(['k1' => 'v1', 'k2' => 'v2'], 3600);
$values = Cache::getMultiple(['k1', 'k2']);
Cache::deleteMultiple(['k1', 'k2']);

// 清空
Cache::clear();
```

## 切换存储

```php
// 使用指定存储
$redisCache = Cache::store('redis');
$fileCache = Cache::store('file');
$apcuCache = Cache::store('apcu');
```

## 配置

```php
// config/config.php
'cache' => [
    'default' => 'redis',
    'stores' => [
        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],
        'file' => [
            'driver' => 'file',
            'path' => runtime_path('cache'),
        ],
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
        'apcu' => [
            'driver' => 'apcu',
        ],
    ],
]
```

## 支持的驱动

| 驱动 | 说明 | 适用场景 |
|------|------|---------|
| `redis` | Redis 缓存 | 生产环境，高性能 |
| `file` | 文件缓存 | 无 Redis 环境 |
| `array` | 内存数组缓存 | 测试环境 |
| `apcu` | APCu 缓存 | 单机高性能 |
