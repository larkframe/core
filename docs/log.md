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
                // 闭包延迟求值：实例化时才解析 runtime_path
                'constructor' => [fn() => runtime_path('logs/app.log'), 7, Monolog\Logger::DEBUG],
            ],
        ],
    ],
    'access' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\StreamHandler::class,
                'constructor' => [fn() => runtime_path('logs/access.log'), Monolog\Logger::INFO],
                'formatter' => [
                    'class' => Monolog\Formatter\LineFormatter::class,
                    'constructor' => ["%datetime% %message% %context%\n", 'Y-m-d H:i:s'],
                ],
            ],
        ],
    ],
]
```

## 日志级别

从低到高：`debug` → `info` → `notice` → `warning` → `error` → `critical` → `alert` → `emergency`
