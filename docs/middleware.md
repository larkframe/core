# 中间件 (Middleware)

`LarkFrame\Middleware` 提供请求处理的中间件链机制，支持全局、控制器和路由级中间件。

## 定义中间件

```php
use LarkFrame\MiddlewareInterface;
use LarkFrame\Request;
use LarkFrame\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function process(Request $request, callable $next): Response
    {
        if (!$this->isAuthenticated($request)) {
            return redirect('/login');
        }
        return $next($request);
    }
}
```

## 注册中间件

### 全局中间件

```php
// config/config.php
'server' => [
    'middleware' => [
        CorsMiddleware::class,
        AuthMiddleware::class,
    ],
]
```

### 控制器中间件

```php
class UserController
{
    protected array $middleware = [
        AuthMiddleware::class,
    ];

    public function indexAction(Request $request) { ... }
}
```

### 路由中间件

```php
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware(AuthMiddleware::class);
```

### 注解中间件

```php
use LarkFrame\Annotation\Middleware as MiddlewareAttr;

#[MiddlewareAttr(RateLimitMiddleware::class)]
class ApiController
{
    #[MiddlewareAttr(AuthMiddleware::class)]
    public function userAction(Request $request) { ... }
}
```

## 执行顺序

中间件按以下顺序执行（洋葱模型）：

1. 全局中间件（正序）
2. 控制器注解中间件
3. 控制器属性中间件
4. 路由中间件
5. 控制器方法注解中间件
6. 控制器方法

响应按相反顺序返回。

## 行为约定

### 校验策略（fail-fast）

全局、路由、控制器属性、注解四种来源的中间件在注册/解析时统一校验
（类存在 + `process` 方法存在），无效中间件抛 `RuntimeException`——
静默跳过会让拼错的中间件无声失效（鉴权中间件失效是安全事故而非可用性问题）。

### 作用范围

- **三种模式一致**：Server / Web（FPM）/ Shell 的路由请求均经过完整中间件管道
- **兜底响应也穿管道**：404 / 405 / 400 响应经全局中间件包裹——
  CORS 预检（OPTIONS，通常未注册路由）依赖此机制才能收到跨域响应头；
  鉴权类全局中间件对 404/405 页同样生效
- **属性中间件为类定义默认值**：反射缓存读取 `getDefaultProperties()`，
  运行时修改 `$this->middleware` 不生效；解析结果按类名缓存，
  同进程内该类所有 action 共享一次解析结果
