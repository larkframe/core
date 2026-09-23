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
| `ContainerException` | 容器解析失败（含循环别名、别名链超深、构造参数不可解析） |
| `NotFoundException` | 服务未找到（类不存在且无绑定） |

## 全局容器

默认全局容器实例配置在 `config/config.php` 的 `container` 键：

```php
'container' => new LarkFrame\Container(),
```

`Container::getInstance()` 优先返回该配置实例；未配置时回退内置单例。
静态代理（`Container::get()` 等 `__callStatic`）与 `App::container()` 均经由它。

## 解析行为要点

- **自动装配**：未绑定的具体类按构造函数类型提示递归解析；可选依赖解析失败时使用默认值，必需依赖失败抛异常
- **单例判定**：`singleton()` / `instance()` 注册的条目解析后缓存；普通 `bind()` 每次解析新实例
- **参数覆盖**：`make($class, ['paramName' => $value])` 按构造参数名覆盖，透传无共享状态污染
- **控制器实例化**：路由中的控制器类名经容器解析，支持构造注入
