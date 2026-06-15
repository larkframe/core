<?php

namespace LarkFrame;

use Exception;
class Config
{
    /**
     * @var array
     */
    protected static $config = [];

    /**
     * Whether config has been loaded.
     */
    protected static bool $loaded = false;

    public static function load(): void
    {
        if (static::$loaded) {
            return;
        }

        $config = require ROOT_PATH.DIRECTORY_SEPARATOR . "config".DIRECTORY_SEPARATOR . "config.php";

        // Auto-load config/task.php if exists
        $taskConfigFile = ROOT_PATH.DIRECTORY_SEPARATOR . "config".DIRECTORY_SEPARATOR . "task.php";
        if (file_exists($taskConfigFile) && !isset($config['task'])) {
            $config['task'] = require $taskConfigFile;
        }

        if (defined('RUN_MODE') && in_array(RUN_MODE, ['dev', 'test', 'stage', 'prod'])) {
            // 设置环境类型
            if (file_exists(ROOT_PATH.DIRECTORY_SEPARATOR . "config".DIRECTORY_SEPARATOR. "config." . RUN_MODE . ".php")) {
                $envConfig = require ROOT_PATH.DIRECTORY_SEPARATOR . "config".DIRECTORY_SEPARATOR. "config." . RUN_MODE . ".php";
                $config = array_merge($config, $envConfig);
            }
        } elseif (defined('RUN_MODE')) {
            trigger_error("Config: Invalid RUN_MODE '" . RUN_MODE . "'. Expected one of: dev, test, stage, prod", E_USER_WARNING);
        }
        static::$config = $config;
        static::$loaded = true;
    }
    public static function loadEnv($filePath): array
    {
        if (!file_exists($filePath)) {
            throw new Exception("env file error: $filePath");
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new Exception("env load error: $filePath");
        }

        $env = [];
        $inQuote = false;
        $currentKey = null;
        $currentValue = '';

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#')) {
                continue;
            }

            if ($inQuote) {
                if (preg_match('/(?<![\\\\])([\'"])\s*$/', $line, $matches)) {
                    $inQuote = false;
                    $currentValue .= "\n" . substr($line, 0, -strlen($matches[0]));
                } else {
                    $currentValue .= "\n" . $line;
                    continue;
                }
            } else {
                $parts = explode('=', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $currentKey = trim($parts[0]);
                $value = trim($parts[1]);

                if (preg_match('/^([\'"])(.*)(?<![\\\\])\1$/', $value, $matches)) {
                    $currentValue = $matches[2];
                } elseif (preg_match('/^([\'"])(.*)$/', $value, $matches)) {
                    $inQuote = true;
                    $quoteChar = $matches[1];
                    $currentValue = $matches[2];
                    continue;
                } else {
                    $currentValue = $value;
                }
            }

            $currentValue = preg_replace_callback('/\\\\([nrtvf\\\\$"\']|u([0-9a-fA-F]{4}))/',
                function ($matches) {
                    $escapes = [
                        'n' => "\n", 'r' => "\r", 't' => "\t",
                        'v' => "\v", 'f' => "\f", '\\\\' => "\\",
                        '$' => '$', '"' => '"', "'" => "'"
                    ];
                    return $escapes[$matches[1]] ?? (isset($matches[2])
                        ? json_decode('"\u' . $matches[2] . '"')
                        : $matches[0]);
                },
                $currentValue
            );

            if (!$inQuote) {
                $currentValue = preg_replace('/\s+#.*$/', '', $currentValue);
            }

            $env[$currentKey] = self::castEnvValue($currentValue);
            $currentValue = '';
        }

        if ($inQuote) {
            throw new Exception("env config error: $currentKey");
        }

        return $env;

    }
    public static function clear()
    {
        static::$config = [];
        static::$loaded = false;
    }

    public static function get(?string $key = null, mixed $default = null)
    {
        if ($key === null) {
            return static::$config;
        }
        $keyArray = explode('.', $key);
        $value = static::$config;
        $found = true;
        foreach ($keyArray as $index) {
            if ($index === '' || !is_array($value) || !array_key_exists($index, $value)) {
                $found = false;
                break;
            }
            $value = $value[$index];
        }
        if ($found) {
            return $value;
        }
        return $default;
    }

    /**
     * Cast .env string values to appropriate PHP types.
     *
     * - "true"/"false" → bool
     * - "null" → null
     * - Numeric strings → int or float
     * - Quoted strings remain as strings (already unquoted by parser)
     * - Everything else remains as string
     */
    protected static function castEnvValue(string $value): mixed
    {
        $lower = strtolower($value);

        if ($lower === 'true') {
            return true;
        }
        if ($lower === 'false') {
            return false;
        }
        if ($lower === 'null') {
            return null;
        }
        if ($value === '') {
            return '';
        }

        // Integer detection (no leading zeros except "0" itself)
        if (preg_match('/^-?(0|[1-9]\d*)$/', $value)) {
            return (int)$value;
        }

        // Float detection
        if (preg_match('/^-?(0|[1-9]\d*)\.\d+$/', $value)) {
            return (float)$value;
        }

        return $value;
    }

}