# 响应 (Response)

`LarkFrame\Response` 封装 HTTP 响应，支持文件下载、304 缓存、JSON 等。

## 创建响应

```php
use LarkFrame\Response;

// 纯文本
return new Response(200, [], 'Hello World');

// JSON（推荐使用辅助函数）
return json(['status' => 'ok']);

// 重定向
return redirect('/login');

// XML
return xml($xmlString);
```

## 设置响应头

```php
$response = new Response(200, ['Content-Type' => 'text/html'], $body);
$response->withHeaders([
    'X-Custom-Header' => 'value',
    'Cache-Control' => 'no-cache',
]);
```

## 文件响应

```php
// 发送文件（支持 304 Not Modified 与 Range 断点续传）
return (new Response())->file('/path/to/file.pdf');

// 下载文件
return (new Response())->download('/path/to/file.pdf', 'report.pdf');

// 显式指定区段（返回 206 Partial Content）
return (new Response())->withFile('/path/to/file.pdf', 1024, 4096);
```

`file()` 方法在 Server 模式下自动处理：

- **304 缓存**：请求头 `If-Modified-Since` 与文件修改时间一致时返回 304
- **Range 断点续传**：请求头 `Range: bytes=0-499` / `bytes=-500`（后缀）
  自动返回 206 Partial Content + `Content-Range`（Range 头从当前 Request 读取，
  FPM 无请求上下文时回退 `$_SERVER['HTTP_RANGE']`）
- 完整文件响应为 200（不带 `Content-Range`）

## 辅助函数

| 函数 | 说明 |
|------|------|
| `response($body, $status, $headers)` | 创建文本响应 |
| `json($data, $options)` | 创建 JSON 响应 |
| `jsonp($data, $callback)` | 创建 JSONP 响应 |
| `redirect($url, $status)` | 创建重定向响应 |
| `xml($xml)` | 创建 XML 响应 |
| `view($template, $vars)` | 创建视图响应 |
| `raw_view($template, $vars)` | 原生 PHP 视图响应 |
| `twig_view($template, $vars)` | Twig 模板响应 |

## 响应发送策略

- Server 模式：通过 `TcpConnection` 直接发送
- Web 模式：通过 `header()` + `echo` 发送
- 自动处理 Keep-Alive：HTTP/1.1 默认保持连接，否则关闭
