<?php

namespace LarkFrame;

enum ErrorType: int
{
    case Error = 1;
    case Warning = 2;
    case Parse = 4;
    case Notice = 8;
    case CoreError = 16;
    case CoreWarning = 32;
    case CompileError = 64;
    case CompileWarning = 128;
    case UserError = 256;
    case UserWarning = 512;
    case UserNotice = 1024;
    case Strict = 2048;
    case RecoverableError = 4096;
    case Deprecated = 8192;
    case UserDeprecated = 16384;

    public function label(): string
    {
        return match ($this) {
            self::Error => 'E_ERROR',
            self::Warning => 'E_WARNING',
            self::Parse => 'E_PARSE',
            self::Notice => 'E_NOTICE',
            self::CoreError => 'E_CORE_ERROR',
            self::CoreWarning => 'E_CORE_WARNING',
            self::CompileError => 'E_COMPILE_ERROR',
            self::CompileWarning => 'E_COMPILE_WARNING',
            self::UserError => 'E_USER_ERROR',
            self::UserWarning => 'E_USER_WARNING',
            self::UserNotice => 'E_USER_NOTICE',
            self::Strict => 'E_STRICT',
            self::RecoverableError => 'E_RECOVERABLE_ERROR',
            self::Deprecated => 'E_DEPRECATED',
            self::UserDeprecated => 'E_USER_DEPRECATED',
        };
    }

    public static function fromInt(int $value): ?self
    {
        return self::tryFrom($value);
    }
}

class ErrorHandler
{
    /**
     * Register error handler with options.
     *
     * 支持的 options:
     *   - logger: callable(string): void  自定义日志器，默认用 Worker::log
     *   - error_types: int               错误级别掩码，默认 error_reporting()
     */
    public static function register(array $options = []): void
    {
        $logger = $options['logger'] ?? null;
        $logger ??= static fn(string $msg) => Worker::log($msg);
        $errorTypes = $options['error_types'] ?? error_reporting();

        // 1) 错误处理器：尊重 error_reporting，避免记录被屏蔽级别
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use ($logger, $errorTypes): bool {
            if (!($errorTypes & $errno)) {
                return false;
            }
            $logger(self::formatError($errno, $errstr, $errfile, $errline));
            return true;
        });

        // 2) 异常处理器：捕获未捕获异常，防止裸露给客户端
        set_exception_handler(static function (\Throwable $e) use ($logger): void {
            // 异常处理器内不能再抛异常，仅记录
            $logger("Uncaught " . get_class($e) . ": {$e->getMessage()}\n{$e->getTraceAsString()}");
        });

        // 3) 致命错误捕获：E_ERROR/E_PARSE/E_CORE_ERROR/E_COMPILE_ERROR 在 shutdown 阶段才能拿到
        register_shutdown_function(static function () use ($logger): void {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING], true)) {
                $logger("FATAL: {$e['message']} in {$e['file']} on line {$e['line']}");
            }
        });
    }

    /**
     * 格式化错误信息为可读字符串
     */
    private static function formatError(int $errno, string $errstr, string $errfile, int $errline): string
    {
        $map = [
            E_WARNING => 'WARNING', E_NOTICE => 'NOTICE', E_DEPRECATED => 'DEPRECATED',
            E_USER_ERROR => 'USER_ERROR', E_USER_WARNING => 'USER_WARNING',
            E_USER_NOTICE => 'USER_NOTICE', E_USER_DEPRECATED => 'USER_DEPRECATED',
            E_STRICT => 'STRICT', E_RECOVERABLE_ERROR => 'RECOVERABLE_ERROR',
        ];
        $label = $map[$errno] ?? "UNKNOWN($errno)";
        return "$label: $errstr in $errfile on line $errline";
    }
}
