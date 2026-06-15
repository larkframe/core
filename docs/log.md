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

## 配置

```php
// config/config.php
'log' => [
    'default' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\StreamHandler::class,
                'constructor' => [runtime_path('logs/app.log'), Monolog\Logger::DEBUG],
            ],
        ],
    ],
    'access' => [
        'handlers' => [
            [
                'class' => Monolog\Handler\StreamHandler::class,
                'constructor' => [runtime_path('logs/access.log'), Monolog\Logger::INFO],
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
