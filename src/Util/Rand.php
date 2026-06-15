<?php

namespace LarkFrame\Util;

class Rand
{
    /**
     * 生成随机数字字符串
     */
    public static function numberStr(int $length = 10): string
    {
        return self::any($length, '0123456789');
    }

    /**
     * 生成随机整数
     */
    public static function numberInt(int $min = 0, int $max = 100): int
    {
        try {
            return random_int($min, $max);
        } catch (\Exception) {
            return mt_rand($min, $max);
        }
    }

    /**
     * 生成随机浮点数
     */
    public static function numberFloat(int $min = 0, int $max = 100, int $decimalPlaces = 2): float
    {
        if ($min >= $max) {
            return 0.0;
        }

        $factor = pow(10, $decimalPlaces);
        $minInt = (int)round($min * $factor);
        $maxInt = (int)round($max * $factor);
        $randomInt = self::numberInt($minInt, $maxInt);

        return (float)number_format($randomInt / $factor, $decimalPlaces, '.', '');
    }

    /**
     * 生成随机字符串（字母+数字）
     */
    public static function str(int $length = 10): string
    {
        return self::any($length, '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
    }

    /**
     * 生成易读随机字符串（排除易混淆字符 0O1lI）
     */
    public static function strEasy(int $length = 10): string
    {
        return self::any($length, '23456789abcdefghjklmnpqrstuvwxyzABCDEFGHIJKLMNPQRSTUVWXYZ');
    }

    /**
     * 生成随机字符串（自定义字符集）
     */
    public static function any(int $length = 10, string $characters = ''): string
    {
        if ($characters === '') {
            $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!@#$%^&*()_+-=[]{}|;:,.<>?';
        }

        $charactersLength = strlen($characters);
        $bytes = random_bytes($length);
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $characters[ord($bytes[$i]) % $charactersLength];
        }
        return $result;
    }

    /**
     * 生成 UUID v4
     */
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * 生成唯一ID（短格式，适合请求追踪等场景）
     */
    public static function uniqid(int $length = 16): string
    {
        $hex = bin2hex(random_bytes((int)ceil($length / 2)));
        return substr($hex, 0, $length);
    }

    /**
     * 从数组中随机选取一个或多个元素
     */
    public static function arrayPick(array $array, int $count = 1): mixed
    {
        if ($array === []) {
            return null;
        }

        $count = min($count, count($array));

        if ($count === 1) {
            return $array[array_rand($array)];
        }

        $keys = array_rand($array, $count);
        return array_map(fn($key) => $array[$key], (array)$keys);
    }

    /**
     * 生成随机布尔值
     */
    public static function bool(): bool
    {
        return self::numberInt(0, 1) === 1;
    }

    /**
     * 生成随机十六进制字符串
     */
    public static function hex(int $length = 16): string
    {
        return substr(bin2hex(random_bytes((int)ceil($length / 2))), 0, $length);
    }
}
