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

        // 不用 FILE_SKIP_EMPTY_LINES：多行引号值内部的空行属于值内容，
        // 提前过滤会静默改变值；空行改在循环内按解析状态判断
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new Exception("env load error: $filePath");
        }

        $env = [];
        $inQuote = false;
        $quoteChar = '';
        $currentKey = null;
        $currentValue = '';
        // 值是否被引号包裹：决定是否跳过行内注释剥离与类型转换（dotenv 语义下引号值恒为字符串）
        $quoted = false;

        foreach ($lines as $line) {
            if (!$inQuote) {
                $trimmed = trim($line);
                // 引号态内的空行是值内容，只在非引号态跳过注释行与空行
                if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                    continue;
                }

                $parts = explode('=', $line, 2);
                if (count($parts) !== 2) {
                    continue;
                }

                $currentKey = self::parseEnvKey($parts[0]);
                if ($currentKey === null) {
                    continue;
                }

                $value = trim($parts[1]);
                $quoted = false;

                if ($value !== '' && ($value[0] === '"' || $value[0] === "'")) {
                    $quoteChar = $value[0];
                    $quoted = true;
                    [$closed, $content] = self::splitAtClosingQuote(substr($value, 1), $quoteChar);
                    $currentValue = $content;
                    if (!$closed) {
                        // 引号未闭合：进入多行模式，后续行继续累积
                        $inQuote = true;
                        continue;
                    }
                } else {
                    $currentValue = $value;
                }
            } else {
                // 多行引号值：每行查找闭合引号，未闭合则继续累积
                [$closed, $content] = self::splitAtClosingQuote($line, $quoteChar);
                $currentValue .= "\n" . $content;
                if (!$closed) {
                    continue;
                }
                $inQuote = false;
            }

            // 值已完整取出
            $currentValue = self::unescapeEnvValue($currentValue, $quoted, $quoteChar);

            // 行内注释仅对未加引号的值生效：引号内的 # 是值的一部分
            if (!$quoted) {
                $currentValue = preg_replace('/\s+#.*$/', '', $currentValue);
            }

            // 引号值恒为字符串；裸值按字面量推断类型
            $env[$currentKey] = $quoted ? $currentValue : self::castEnvValue($currentValue);

            $currentValue = '';
            $quoted = false;
            $quoteChar = '';
        }

        if ($inQuote) {
            throw new Exception("env config error: $currentKey");
        }

        return $env;
    }

    /**
     * 解析键名：去除空白与 dotenv 可选的 export 前缀。
     *
     * @return string|null null 表示该行不是有效的键值对
     */
    private static function parseEnvKey(string $rawKey): ?string
    {
        $key = trim($rawKey);
        if (str_starts_with($key, 'export ')) {
            $key = trim(substr($key, 7));
        }
        return $key === '' ? null : $key;
    }

    /**
     * 查找引号闭合位置，返回引号之前的内容。
     *
     * 反斜杠转义按**连续反斜杠的奇偶**判定：`"a\\"` 引号前是 2 个（偶数）→ 闭合，
     * `"a\"` 是 1 个（奇数）→ 被转义、不闭合。原实现用 (?<![\\]) 只排除单个反斜杠，
     * 会把 `KEY="v\\"` 误判为未闭合，进而吞掉后续所有行直至抛错。
     * 单引号内反斜杠无转义语义（dotenv 语义），任何位置的引号都视为闭合。
     *
     * @return array{0: bool, 1: string} [是否闭合, 引号之前的内容]
     */
    private static function splitAtClosingQuote(string $str, string $quoteChar): array
    {
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            if ($str[$i] !== $quoteChar) {
                continue;
            }
            if ($quoteChar === "'") {
                return [true, substr($str, 0, $i)];
            }
            $backslashes = 0;
            for ($j = $i - 1; $j >= 0 && $str[$j] === '\\'; $j--) {
                $backslashes++;
            }
            if ($backslashes % 2 === 0) {
                return [true, substr($str, 0, $i)];
            }
        }
        return [false, $str];
    }

    /**
     * 还原转义序列。
     *
     * 单引号值按 dotenv 语义为字面量，不做转义还原；双引号值与裸值保持既有行为。
     */
    private static function unescapeEnvValue(string $value, bool $quoted, string $quoteChar): string
    {
        if ($quoted && $quoteChar === "'") {
            return $value;
        }

        return preg_replace_callback('/\\\\([nrtvf\\\\$"\']|u([0-9a-fA-F]{4}))/',
            function ($matches) {
                $escapes = [
                    'n' => "\n", 'r' => "\r", 't' => "\t",
                    'v' => "\v", 'f' => "\f", '\\' => "\\",
                    '$' => '$', '"' => '"', "'" => "'"
                ];
                return $escapes[$matches[1]] ?? (isset($matches[2])
                    ? json_decode('"\u' . $matches[2] . '"')
                    : $matches[0]);
            },
            $value
        );
    }
    /**
     * 清理中央配置缓存。
     *
     * P2-24 注意：本方法仅清理 Config 类的静态缓存，不清理 Library 子类实例持有的
     * $config 副本（Library 在构造时按需加载并缓存到实例属性）。如需完整热重载，
     * 调用方应重新实例化 Library 子类或重启 worker。跨类全局注册表会增加耦合，
     * 不符合"少即是多"原则，故不在此实现。
     */
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
        // 单段键快路径（实测 4.3x）：config('server') 这类高频调用免去 explode 与循环。
        // 含点键在顶层不可能存在字面同名键（配置系统即点号分隔语法），探测失败自然落入慢路径
        if (array_key_exists($key, static::$config)) {
            return static::$config[$key];
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