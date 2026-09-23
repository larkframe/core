# 日志 (Log)

`LarkFrame\Log` 基于 Monolog，提供多通道日志记录。

## 基本用法

```php
use LarkFrame\Log;

Log::debug('Debug message', ['key' => 'value']);
Log::info('User logged in', ['user_id' => 1]);
Log::warning('Rate limit approaching', ['ip' => '10.0.0.1']);
Log::error('Database error', ['error' => $e->getMessage()]);
Log::critical('System failure', ['exception' => $e]);
```

## 日志通道

```php
// 使用指定通道
$logger = Log::channel('access');
$logger->info('Request processed');
```

未配置的通道回退默认 handler（`runtime/logs/app.log`，级别随 `app.debug` 切换），
而非抛出异常——日志是最后一道防线，配置缺失不应让调用链在记录错误时二次崩溃。
`handlers` 配置为空数组时同样回退默认，不会静默丢弃日志。

## 配置

handler/formatter/processor 的 `constructor` 参数支持 **Closure 延迟求值**：
config.php 在配置加载完成前被 require，其中直接调用 `runtime_path()` 只能取到
默认目录；写成闭包即可在 handler 实例化时（配置已就绪）求值，使
`app.runtime_path` 配置对日志路径真正生效。

```php
// config/config.php
'log' => [
    'default' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\RotatingFileHandler::class,
                // 闭包延迟求值：实例化时才解析 runtime_path。
                // 参数顺序：filename, maxFiles, level, bubble, filePermission, useLocking
                'constructor' => [fn() => runtime_path('logs/app.log'), 7, Monolog\Logger::DEBUG, true, null, true],
            ],
        ],
    ],
    'access' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\StreamHandler::class,
                // 参数顺序：stream, level, bubble, filePermission, useLocking
                'constructor' => [fn() => runtime_path('logs/access.log'), Monolog\Logger::INFO, true, null, true],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => ["%datetime% %message% %context%\n", 'Y-m-d H:i:s'],
                ],
            ],
        ],
    ],
]
```

### 多进程写入必须启用 useLocking

写文件的 handler（`StreamHandler` / `RotatingFileHandler` 等）在 `constructor` 末位都有
`useLocking` 参数，其默认值为 `false`。**多 worker 部署（`server.worker.count > 1` 或
开启 `reusePort`）时该参数必须传 `true`**：多个进程并发写同一日志文件时，无 flock
保护的单次写入可能被其他进程的写入截断，表现为日志行撕裂、内容交错（一条日志里混入
另一条的开头）。

框架内置的默认 handler 已启用该参数；自定义 handler 配置需自行传入，否则会退回无锁写入。

参数位置因 handler 而异，例如 `RotatingFileHandler` 的 `useLocking` 是第 6 个参数，
`StreamHandler` 是第 5 个参数：

| Handler | 参数顺序 |
| --- | --- |
| `StreamHandler` | `stream, level, bubble, filePermission, useLocking` |
| `RotatingFileHandler` | `filename, maxFiles, level, bubble, filePermission, useLocking` |

为避免记忆参数位置，`constructor` 同时支持 **命名参数**（字符串键原样传给构造函数，
Closure 延迟求值同样生效）：

```php
'constructor' => [
    'filename' => fn() => runtime_path('logs/app.log'),
    'maxFiles' => 7,
    'level' => Monolog\Logger::DEBUG,
    'useLocking' => true,   // 无需记住它排在第 6 位
],
```

位置参数（数字键）与命名参数可以混用，但**位置参数必须写在命名参数之前**（PHP 语法限制）；
混用时数字键需从 0 开始连续，否则会被当作命名参数解析失败。

### formatter 的 allowInlineLineBreaks 与日志注入

`LogFormatter` 的第 3 个构造参数 `allowInlineLineBreaks`（默认 `false`）：

| 取值 | 行为 | 适用场景 |
| --- | --- | --- |
| `false`（默认） | 值中的 `\r\n`/`\r`/`\n` 替换为**空格**，一条日志恒为一行 | 生产环境；便于按行解析与审计 |
| `true` | 换行原样保留，一条日志可占多行 | 需要多行异常堆栈时 |

**多进程/多来源日志场景应保持 `false`**：置 `true` 后，异常 message、用户输入等带换行的内容
会原样落盘，攻击者或异常数据即可**伪造出额外的日志行**，污染审计记录、干扰按行解析。

确实需要多行异常堆栈时，传第 5 个参数 `includeStacktraces = true`。注意它**会自动把
`allowInlineLineBreaks` 置为 `true`**（与 Monolog 语义一致），即换行保护随之全局失效——
两者无法分离。若既要堆栈多行、又要防注入，应在记录前自行折叠消息中的换行。

```php
'formatter' => [
    'class' => LarkFrame\LogFormatter::class,
    //                     format, dateFormat,      allowInlineLineBreaks, ignoreEmptyContextAndExtra, includeStacktraces
    'constructor' => [null, 'Y-m-d H:i:s', false, false, false],
],
```

`Worker::log()` 走的是独立于 Monolog 的路径，其换行折叠是**内置且不可配置**的
（同样折叠为空格），因此异常文本经此输出不会破坏日志行结构。

## 日志级别

从低到高：`debug` → `info` → `notice` → `warning` → `error` → `critical` → `alert` → `emergency`
