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

    // 请求 ID（自动生成，32 位十六进制随机串）
    $requestId = $request->requestId();

    // 客户端 IP
    $ip = $request->getRemoteIp();
}
```

## 获取参数

```php
// GET 参数
$request->get('key', $default);
$request->get();  // 所有 GET 参数

// POST 参数（Server 模式下 body 惰性解析：不访问 post/file 时不产生解析开销）
$request->post('key', $default);
$request->post(); // 所有 POST 参数

// 合并 GET + POST
$request->all();
// input() 优先 GET：GET 命中时不会触发 POST body 解析
$request->input('key', $default);
```

POST body 支持表单（`application/x-www-form-urlencoded`）与 JSON
（`Content-Type` 含 `json` 时自动 `json_decode`）两种格式，multipart 文件上传
走 `uploadFile()`。

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
| `usedTime()` | 请求耗时（**毫秒**，int；亚毫秒返回 3 位小数） |
| `cookie()` | 获取 Cookie |
| `file()` | 获取上传文件信息（原始数组） |
| `getRemoteIp()` | 对端 IP |
| `getRealIp()` | 真实客户端 IP（需配置 `app.trusted_proxies` 才信任 XFF 头） |
| `route()` | 当前路由对象（`param()` 取路由参数） |
| `controller()` / `action()` | 当前控制器/方法名 |

## 请求源

根据运行模式自动选择请求源：

| 模式 | 请求源类 | 数据来源 |
|------|---------|---------|
| Server | `ServerSource` | 从 TCP 连接原始 buffer 解析 |
| Web | `WebSource` | PHP 超全局变量 `$_GET/$_POST/$_SERVER` |
| Shell | `ShellSource` | 命令行参数 `$_SERVER['argv']` |
| Task | `ServerSource` | 常驻内存 Worker，不处理 HTTP 请求 |
