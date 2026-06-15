<?php

namespace LarkFrame\Util;

class Str
{
    /**
     * 驼峰转下划线
     */
    public static function camelToUnderscore(string $input): string
    {
        $input = lcfirst($input);
        $output = preg_replace_callback(
            '/(?<=\w)(?=[A-Z])|(?<=[a-z])(?=[A-Z])|(?<=\d)(?=[A-Za-z])/',
            fn() => '_',
            $input
        );
        return strtolower($output);
    }

    /**
     * 下划线转驼峰
     */
    public static function underscoreToCamel(string $input, bool $ucfirst = false): string
    {
        $parts = explode('_', $input);
        $result = implode('', array_map(fn($part) => ucfirst(strtolower($part)), $parts));
        return $ucfirst ? $result : lcfirst($result);
    }

    /**
     * 截断字符串，支持多字节安全截取
     */
    public static function truncate(string $str, int $length, string $suffix = '...'): string
    {
        if (mb_strlen($str) <= $length) {
            return $str;
        }
        return mb_substr($str, 0, $length) . $suffix;
    }

    /**
     * 限制字符串单词数（按空格分割）
     */
    public static function limitWords(string $str, int $words, string $suffix = '...'): string
    {
        $parts = preg_split('/\s+/', $str);
        if (count($parts) <= $words) {
            return $str;
        }
        return implode(' ', array_slice($parts, 0, $words)) . $suffix;
    }

    /**
     * 隐藏字符串中间部分（如手机号、身份证号脱敏）
     */
    public static function mask(string $str, int $keepStart = 3, int $keepEnd = 4, string $maskChar = '*'): string
    {
        $len = mb_strlen($str);
        if ($len <= $keepStart + $keepEnd) {
            return $str;
        }
        $start = mb_substr($str, 0, $keepStart);
        $end = mb_substr($str, -$keepEnd);
        $maskLen = $len - $keepStart - $keepEnd;
        return $start . str_repeat($maskChar, $maskLen) . $end;
    }

    /**
     * 判断字符串是否以指定前缀开头
     */
    public static function startsWith(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }

    /**
     * 判断字符串是否以指定后缀结尾
     */
    public static function endsWith(string $haystack, string $needle): bool
    {
        return str_ends_with($haystack, $needle);
    }

    /**
     * 判断字符串是否包含子串
     */
    public static function contains(string $haystack, string $needle): bool
    {
        return str_contains($haystack, $needle);
    }

    /**
     * 生成随机可读字符串（排除易混淆字符 0O1lI）
     */
    public static function random(int $length = 16): string
    {
        return Rand::strEasy($length);
    }

    /**
     * 将字符串转换为 slug 格式（URL 友好）
     */
    public static function slug(string $str, string $separator = '-'): string
    {
        $str = preg_replace('/[^\p{L}\p{Nd}]+/u', $separator, $str);
        $str = preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, $str);
        return trim($str, $separator);
    }

    /**
     * 字节格式化（将字节数转为人类可读格式）
     */
    public static function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }
}
