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

## 渲染模板

```php
// 原生 PHP 模板
return raw_view('default/index', ['username' => 'world'], 'php');

// Twig 模板
return twig_view('default/index', ['username' => 'world'], 'twig');

// 使用默认模板引擎（由配置决定）
return view('default/index', ['username' => 'world']);
```

## 配置

```php
// config/config.php
'view' => [
    'handler' => \LarkFrame\View\Raw::class,  // 或 \LarkFrame\View\Twig::class
    'path' => ROOT_PATH . '/app/View',
    'suffix' => 'php',  // 或 'twig'
],
```

## 辅助函数

| 函数 | 说明 |
|------|------|
| `view($template, $vars, $suffix)` | 使用默认引擎渲染 |
| `raw_view($template, $vars, $suffix)` | 原生 PHP 渲染 |
| `twig_view($template, $vars, $suffix)` | Twig 渲染 |
