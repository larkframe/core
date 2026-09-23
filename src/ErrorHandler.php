<?php

namespace LarkFrame;

class ErrorHandler
{
    /**
     * Register error handler with options.
     *
     * 支持的 options:
     *   - logger: callable(string): void  自定义日志器，默认用 Worker::log
     *   - error_types: int               错误级别掩码，默认 error_reporting()
     *
     * @param bool $throwOnError Server/Task 模式下为 true：将错误转为 ErrorException 抛出，
     *                           由 onMessage 的 try-catch 或 set_exception_handler 统一记录日志；
     *                           Web/Shell 模式下为 false（默认）：记录日志并抑制错误。
     */
    public static function register(array $options = [], bool $throwOnError = false): void
    {
        $logger = $options['logger'] ?? null;
        $logger ??= static fn(string $msg) => Worker::log($msg);
        $errorTypes = $options['error_types'] ?? error_reporting();

        // 1) 错误处理器
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline) use ($logger, $errorTypes, $throwOnError): bool {
            // 必须用「当前」error_reporting() 判断而非注册时的快照：
            // PHP 8 中 @ 抑制符通过临时降低 error_reporting 生效，快照判断会让所有 @ 静默检查抛异常
            if (!(error_reporting() & $errno) || !($errorTypes & $errno)) {
                return false;
            }
            if ($throwOnError) {
                // Server/Task 模式：抛出异常，由 onMessage try-catch 记录（含完整请求上下文）
                // 或由 set_exception_handler 记录（非请求周期内的错误）
                throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
            }
            // Web/Shell 模式：记录日志并抑制
            $logger(self::formatError($errno, $errstr, $errfile, $errline));
            return true;
        });

        // 2) 异常处理器：捕获未捕获异常，防止裸露给客户端
        set_exception_handler(static function (\Throwable $e) use ($logger): void {
            // 异常处理器内不能再抛异常，仅记录
            $logger("Uncaught " . get_class($e) . ": {$e->getMessage()}\n{$e->getTraceAsString()}");
        });

        // 3) 致命错误捕获：真正 fatal 的类型在 shutdown 阶段才能拿到
        // （E_CORE_WARNING/E_COMPILE_WARNING 非致命，误列入会把正常结束时的残留警告记成 FATAL）
        register_shutdown_function(static function () use ($logger): void {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
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
