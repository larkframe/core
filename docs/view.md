# 视图 (View)

`LarkFrame\View` 提供视图变量管理和模板渲染，支持原生 PHP 和 Twig 模板。

## 视图变量

```php
use LarkFrame\View\ViewVarHolder;

// 赋值
ViewVarHolder::assign('title', 'My Page');
ViewVarHolder::assign(['name' => 'John', 'age' => 30]);

// 获取所有变量
$vars = ViewVarHolder::getVars();

// 清空
ViewVarHolder::clear();
```

在 Server 模式下，视图变量通过 `Context` 实现请求隔离，不会跨请求污染。

> **注意（assign 生命周期）**：`render()` 完成后自动调用 `ViewVarHolder::clear()`。
> 一次请求渲染多个视图（layout + partial）时，第一次 render 前赋值的变量
> 在第二次 render 中不再可用——跨模板共享变量请在每次 render 的 `$vars` 参数中显式传递。

## 渲染模板

模板目录固定为 `ROOT_PATH/template/`，模板名相对该目录解析（含 `../` 的模板名会被拒绝，防路径穿越）。

```php
// 原生 PHP 模板（模板文件 template/default/index.php）
return raw_view('default/index', ['username' => 'world'], 'php');

// Twig 模板（模板文件 template/user/test.html）
return twig_view('user/test', ['name' => 'larkframe'], 'html');

// 使用默认模板引擎（由配置 view.handler 决定）
return view('default/index', ['username' => 'world']);
```

模板缺失时两个引擎均抛出 `RuntimeException`（fail-fast），不会以 200 状态返回错误文案。

## 配置

```php
// config/config.php
'view' => [
    'handler' => \LarkFrame\View\Raw::class,  // 或 \LarkFrame\View\Twig::class
    'options' => [
        'view_suffix' => 'php',   // 不显式传后缀时的默认模板后缀
        // Twig 引擎额外选项（如 cache）透传给 Twig\Environment
    ],
    // Twig 专用：扩展注册回调，签名 fn(Twig\Environment $env): void
    // 'extension' => fn($env) => $env->addExtension(new MyExtension()),
],
```

> **注意（Twig extension 配置）**：`view.extension` 必须是可调用对象
> （闭包或 `[$obj, 'method']`），接收 `Twig\Environment` 实例完成扩展注册；
> 其他类型会在首次渲染时抛 Error。

## 辅助函数

| 函数 | 说明 |
|------|------|
| `view($template, $vars, $suffix)` | 使用默认引擎渲染 |
| `raw_view($template, $vars, $suffix)` | 原生 PHP 渲染 |
| `twig_view($template, $vars, $suffix)` | Twig 渲染 |
