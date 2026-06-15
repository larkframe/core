# 工具集 (Util)

`LarkFrame\Util` 提供常用工具类，支持门面调用和直接调用。

## 门面调用

```php
use LarkFrame\Util;

Util::str()->mask('13800138000');           // 138****8000
Util::str()->camelToUnderscore('userName'); // user_name
Util::rand()->uuid();                       // 550e8400-e29b-41d4-a716-446655440000
Util::rand()->str(16);                      // 随机 16 位字符串
Util::base64()->urlEncode($data);           // URL 安全的 Base64 编码
Util::file()->ensureDir($dir);              // 确保目录存在
Util::img()->resize($src, $dst, 100, 100);  // 缩放图片
Util::mock()->list($template, 10);          // 生成 10 条模拟数据
```

## 直接调用

```php
use LarkFrame\Util\Str;
use LarkFrame\Util\Rand;

Str::mask('13800138000');
Rand::uuid();
```

## Str — 字符串工具

| 方法 | 说明 | 示例 |
|------|------|------|
| `camelToUnderscore($str)` | 驼峰转下划线 | `userName` → `user_name` |
| `underscoreToCamel($str)` | 下划线转驼峰 | `user_name` → `userName` |
| `truncate($str, $len)` | 截断字符串 | `truncate('hello world', 5)` → `hello...` |
| `mask($str, $start, $end)` | 脱敏 | `mask('13800138000')` → `138****8000` |
| `startsWith($str, $prefix)` | 前缀判断 | |
| `endsWith($str, $suffix)` | 后缀判断 | |
| `contains($str, $needle)` | 包含判断 | |
| `slug($str)` | URL 友好格式 | `Hello World` → `hello-world` |
| `formatBytes($bytes)` | 字节格式化 | `1048576` → `1 MB` |
| `random($length)` | 随机可读字符串 | |

## Rand — 随机工具

| 方法 | 说明 |
|------|------|
| `str($length)` | 随机字符串（字母+数字） |
| `strEasy($length)` | 易读随机字符串（排除 0O1lI） |
| `numberStr($length)` | 随机数字字符串 |
| `numberInt($min, $max)` | 随机整数 |
| `numberFloat($min, $max, $decimals)` | 随机浮点数 |
| `uuid()` | UUID v4 |
| `uniqid($length)` | 短唯一 ID |
| `hex($length)` | 随机十六进制 |
| `bool()` | 随机布尔值 |
| `arrayPick($array, $count)` | 从数组随机选取 |
| `any($length, $chars)` | 自定义字符集随机字符串 |

## Base64 — 编码工具

| 方法 | 说明 |
|------|------|
| `encode($data)` | 标准 Base64 编码 |
| `decode($data)` | 标准 Base64 解码 |
| `urlEncode($data)` | URL 安全 Base64 编码 |
| `urlDecode($data)` | URL 安全 Base64 解码 |
| `authcode($str, $op, $key, $expiry)` | 可逆加密/解密（带过期时间） |

## File — 文件工具

| 方法 | 说明 |
|------|------|
| `copyDir($src, $dest)` | 递归复制目录 |
| `removeDir($dir)` | 递归删除目录 |
| `scanDir($path)` | 扫描目录 |
| `scanDirRecursive($dir)` | 递归扫描目录 |
| `ensureDir($dir)` | 确保目录存在 |
| `safeWrite($path, $content)` | 安全写入文件 |
| `extension($path)` | 获取扩展名 |
| `formatSize($path)` | 人类可读文件大小 |
| `dirSize($dir)` | 目录大小 |
| `getRealpath($path)` | 真实路径（兼容 phar） |

## Img — 图片工具

| 方法 | 说明 |
|------|------|
| `convertToIco($src, $dst, $w, $h)` | 转换为 ICO |
| `resize($src, $dst, $w, $h)` | 缩放图片 |
| `toDataUri($path)` | 生成 Base64 Data URI |

## Mock — 模拟数据

```php
$template = [
    'id' => '@id',
    'name' => '@str(8)',
    'age' => '@int(18, 60)',
    'email' => '@pick(gmail.com, qq.com, 163.com)',
    'created' => '@datetime',
    'is_active' => '@bool',
];

$list = Util::mock()->list($template, 10);
// 生成 10 条模拟数据
```

支持指令：`@id`、`@datetime`、`@date`、`@time`、`@timestamp`、`@int(min,max)`、`@float(min,max,decimals)`、`@str(length)`、`@pick(a,b,c)`、`@bool`、`@uuid`
