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
     */
    public static function register(array $options = []): void
    {
        set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
            $errorType = ErrorType::tryFrom($errno);
            $label = $errorType?->label() ?? "UNKNOWN($errno)";

            Worker::log("$label: $errstr in $errfile on line $errline");

            return true;
        });
    }
}
