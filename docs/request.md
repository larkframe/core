# 请求 (Request)

`LarkFrame\Request` 封装 HTTP 请求数据，兼容 Server/Web/Shell/Task 四种模式。

## 基本用法

```php
use LarkFrame\Request;

public function indexAction(Request $request)
{
    // 请求方法
    $method = $request->method();        // GET, POST, PUT, DELETE...

    // 请求路径
    $path = $request->path();            // /users/1

    // 请求 ID（自动生成）
    $requestId = $request->requestId();

    // 客户端 IP
    $ip = $request->getRemoteIp();

    // 请求时间
    $startTime = $request->startTime();
}
```

## 获取参数

```php
// GET 参数
$request->get('key', $default);
$request->get();  // 所有 GET 参数

// POST 参数
$request->post('key', $default);
$request->post(); // 所有 POST 参数

// 合并 GET + POST
$request->all();
$request->input('key', $default);  // 优先 GET，其次 POST
```

## 文件上传

```php
// 单文件
$file = $request->uploadFile('avatar');
// $file 是 LarkFrame\UploadFile 实例

// 多文件
$files = $request->uploadFile('images');

// 所有上传文件
$files = $request->uploadFile();
```

## 请求头

```php
$request->header('content-type');
$request->header();  // 所有请求头
```

## 其他方法

| 方法 | 说明 |
|------|------|
| `isAjax()` | 是否 AJAX 请求 |
| `isGet()` | 是否 GET 请求 |
| `isPost()` | 是否 POST 请求 |
| `isPjax()` | 是否 PJAX 请求 |
| `expectsJson()` | 期望 JSON 响应 |
| `acceptJson()` | 接受 JSON 响应 |
| `protocolVersion()` | HTTP 协议版本 |
| `url()` | 请求 URL（不含 query string） |
| `fullUrl()` | 完整请求 URL（含 query string） |
| `host()` | 请求主机名 |
| `uri()` | 请求 URI |
| `queryString()` | 查询字符串 |
| `rawBody()` | 原始请求体 |
| `rawBuffer()` | 原始 buffer |
| `usedTime()` | 请求耗时（秒） |
| `cookie()` | 获取 Cookie |
| `file()` | 获取上传文件信息 |

## 请求源

根据运行模式自动选择请求源：

| 模式 | 请求源类 | 数据来源 |
|------|---------|---------|
| Server | `ServerSource` | 从 TCP 连接原始 buffer 解析 |
| Web | `WebSource` | PHP 超全局变量 `$_GET/$_POST/$_SERVER` |
| Shell | `ShellSource` | 命令行参数 `$_SERVER['argv']` |
| Task | `ServerSource` | 常驻内存 Worker，不处理 HTTP 请求 |
