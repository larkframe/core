# 依赖注入容器 (Container)

`LarkFrame\Container` 实现 PSR-11 容器接口，支持自动装配、单例绑定和别名。

## 基本用法

```php
use LarkFrame\Container;

// 绑定接口到实现
Container::bind(LoggerInterface::class, FileLogger::class);

// 绑定单例
Container::singleton(DatabaseInterface::class, MySQLDatabase::class);

// 绑定已有实例
Container::instance(Config::class, $configInstance);

// 解析
$db = Container::get(DatabaseInterface::class);

// 带参数创建
$instance = Container::make(UserService::class, ['name' => 'John']);
```

## 自动装配

容器通过反射自动解析构造函数依赖：

```php
class UserService
{
    public function __construct(
        private UserRepository $repo,
        private LoggerInterface $logger
    ) {}
}

// 自动解析所有依赖
$userService = Container::get(UserService::class);
```

## 别名

```php
Container::alias('db', DatabaseInterface::class);
$db = Container::get('db');  // 等同于 Container::get(DatabaseInterface::class)
```

## 批量定义

```php
Container::addDefinitions([
    LoggerInterface::class => FileLogger::class,
    CacheInterface::class => RedisCache::class,
]);
```

## 静态代理

`Container` 支持静态方法转发到全局容器实例：

```php
Container::get(ServiceInterface::class);    // 转发到全局容器
Container::make(ServiceInterface::class);   // 转发到全局容器
Container::has(ServiceInterface::class);    // 转发到全局容器
```

## 异常

| 异常类 | 说明 |
|--------|------|
| `ContainerException` | 容器解析失败 |
| `NotFoundException` | 服务未找到 |
