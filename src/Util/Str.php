<?php

namespace LarkFrame\Util;

class Str
{
    /**
     * 驼峰转下划线。
     *
     * P2-44 方案 B：处理连续大写（如 XMLParser → xml_parser，Http2Client → http2_client）。
     */
    public static function camelToUnderscore(string $input): string
    {
        // 先在"连续大写 + 大写开头小写"边界插入下划线（XMLParser → XML_Parser）
        $result = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $input);
        // 再在"小写/数字 + 大写"边界插入下划线（Http2Client → Http2_Client）
        $result = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $result);
        return strtolower($result);
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
        if ($len === 0) {
            return $str;
        }
        // 短串保留两端会泄漏原文：仅保留首字符，其余全部脱敏
        if ($len <= $keepStart + $keepEnd) {
            return mb_substr($str, 0, 1) . str_repeat($maskChar, $len - 1);
        }
        $start = mb_substr($str, 0, $keepStart);
        // keepEnd=0 时 -$keepEnd 即 -0，mb_substr 从 0 取整串导致原文完整泄漏
        $end = $keepEnd > 0 ? mb_substr($str, -$keepEnd) : '';
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
     * 字节格式化（将字节数转为人类可读格式）。
     *
     * P2-45 方案 A：明确 SI（1000 基，KB/MB）与 IEC（1024 基，KiB/MiB）两种标准，
     * 默认 IEC（1024 基）符合多数运维场景；调用方可传 $iec=false 切换 SI。
     * 用循环除法代替 log()：log(1024, 1024) 的浮点误差可能把边界值 floor 到低一档单位。
     */
    public static function formatBytes(int $bytes, bool $iec = true, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }
        $base = $iec ? 1024 : 1000;
        $units = $iec
            ? ['B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB']
            : ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float)$bytes;
        $exp = 0;
        $lastIndex = count($units) - 1;
        while ($value >= $base && $exp < $lastIndex) {
            $value /= $base;
            $exp++;
        }
        return round($value, $precision) . ' ' . $units[$exp];
    }
}
