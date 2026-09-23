# 路由 (Route)

`LarkFrame\Route` 提供基于 FastRoute 的高性能路由注册与调度。

## 注册路由

```php
// config/route.php
use LarkFrame\Route;

// 基础路由
Route::get('/users', [UserController::class, 'index']);
Route::post('/users', [UserController::class, 'store']);
Route::put('/users/{id}', [UserController::class, 'update']);
Route::delete('/users/{id}', [UserController::class, 'destroy']);
Route::patch('/users/{id}', [UserController::class, 'patch']);

// 命令行路由
Route::shell('/migrate', [MigrateController::class, 'run']);

// 匹配所有方法（注意 any 隐含 SHELL：php shell.php / 会命中该路由并执行）
Route::any('/api/{path:.+}', [ApiController::class, 'handle']);

// 自定义方法
Route::add(['GET', 'POST'], '/form', [FormController::class, 'handle']);
```

## 路由参数

```php
// 必选参数
Route::get('/users/{id}', [UserController::class, 'show']);

// 正则约束
Route::get('/users/{id:\d+}', [UserController::class, 'show']);
```

> **注意**：FastRoute 不支持 `{id?}` 形式的可选参数——`id?` 会被当作名为
> `id?` 的必选占位符，请求 `/posts`（无尾段）不会匹配。可选语义请注册两条路由：
>
> ```php
> Route::get('/posts', [PostController::class, 'index']);
> Route::get('/posts/{id:\d+}', [PostController::class, 'show']);
> ```

在控制器中通过路由对象获取参数：

```php
public function showAction(Request $request)
{
    $id = $request->route()->param('id');
}
```

## 路由分组

```php
Route::group('/api', function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
});
```

## 路由命名

```php
Route::get('/users/{id}', [UserController::class, 'show'])->name('users.show');

// 获取命名路由
$route = Route::getByName('users.show');
$url = $route->url(['id' => 1]); // /users/1
```

## 路由中间件

```php
// 单条路由的中间件（middleware() 定义在 RouteDefinition 上）
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware(AuthMiddleware::class);
```

> **注意**：`Route::group()` 返回 `Route` 实例，**没有** `middleware()` 方法，
> 分组内路由的中间件需逐条注册：

```php
Route::group('/api', function () {
    Route::get('/users', [UserController::class, 'index'])
        ->middleware([ApiAuthMiddleware::class, RateLimitMiddleware::class]);
    Route::get('/orders', [OrderController::class, 'index'])
        ->middleware([ApiAuthMiddleware::class, RateLimitMiddleware::class]);
});
```

也可以使用控制器属性（对该控制器全部 action 生效）：

```php
class UserController
{
    protected array $middleware = [ApiAuthMiddleware::class];
}
```

## Action 后缀

默认控制器方法需要 `Action` 后缀（如 `indexAction`），可通过配置修改：

```php
// config/config.php
'route' => [
    'action_suffix' => 'Action',  // 默认值，设为空字符串可禁用
]
```

## RouteDefinition

`LarkFrame\RouteDefinition` 是路由定义对象，存储单条路由的元数据：

| 方法 | 说明 |
|------|------|
| `getName()` | 获取路由名称 |
| `getPath()` | 获取路由路径 |
| `getMethods()` | 获取 HTTP 方法 |
| `getCallback()` | 获取回调 |
| `getMiddleware()` | 获取中间件列表 |
| `param($name)` | 获取路由参数 |
| `url($params)` | 生成 URL |

## 路由调度

`Route::dispatch($method, $path)` 在内部调用 FastRoute dispatcher。调用前必须先执行 `Route::load()` 初始化 dispatcher，否则抛出 `RuntimeException`。

```php
Route::load();  // 初始化 dispatcher，加载 config/route.php
$result = Route::dispatch('GET', '/users/1');
// [FastRoute\Dispatcher::FOUND, $handler, $routeParams]
```

框架在 `App::run()` 中已自动调用 `Route::load()`，通常无需手动调用。
