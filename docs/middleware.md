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
