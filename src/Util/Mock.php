<?php

namespace LarkFrame\Util;

class Mock
{
    /**
     * 生成模拟数据列表
     *
     * 支持指令：
     *   @id        - 自增 ID
     *   @datetime  - 当前日期时间 Y-m-d H:i:s
     *   @date      - 当前日期 Y-m-d
     *   @time      - 当前时间 H:i:s
     *   @timestamp - 当前时间戳
     *   @int(min,max) - 随机整数
     *   @float(min,max,decimals) - 随机浮点数
     *   @str(length) - 随机字符串
     *   @pick(a,b,c) - 随机选取
     *   @bool      - 随机布尔值
     *   @uuid      - UUID v4
     *
     * key|n 语法：从数组中随机选取 n 个元素
     *
     * @param array $itemTemplate 模板定义
     * @param int $count 生成数量
     * @return array
     */
    public static function list(array $itemTemplate, int $count): array
    {
        $mockList = [];
        for ($i = 1; $i <= $count; $i++) {
            $mockList[] = static::item($itemTemplate, $i);
        }
        return $mockList;
    }

    /**
     * 生成单条模拟数据
     */
    public static function item(array $template, int $index = 1): array
    {
        $mockItem = [];
        foreach ($template as $key => $item) {
            if (str_contains($key, '|')) {
                [$k, $c] = explode('|', $key, 2);
                $c = max(1, (int)$c);
                $c = min($c, is_array($item) ? count($item) : 1);
                if (is_array($item)) {
                    // shuffle+slice 替代 array_rand(array_flip())：
                    // flip 要求值全为 int/string（含 null 直接 ValueError）且重复元素被去重导致概率失真
                    $pool = $item;
                    shuffle($pool);
                    $picked = array_slice($pool, 0, $c);
                    $mockItem[$k] = $c === 1 ? $picked[0] : $picked;
                } else {
                    $mockItem[$k] = $item;
                }
            } else {
                $mockItem[$key] = static::resolveValue($item, $index);
            }
        }
        return $mockItem;
    }

    /**
     * 解析模拟指令值
     */
    private static function resolveValue(mixed $value, int $index): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // @id - 自增 ID（仅完整指令精确匹配；子串匹配会把 'user@idc.com' 之类普通文本误改写）
        if ($value === '@id') {
            return $index;
        }

        // @datetime
        if ($value === '@datetime') {
            return date('Y-m-d H:i:s');
        }

        // @date
        if ($value === '@date') {
            return date('Y-m-d');
        }

        // @time
        if ($value === '@time') {
            return date('H:i:s');
        }

        // @timestamp
        if ($value === '@timestamp') {
            return time();
        }

        // @bool
        if ($value === '@bool') {
            return Rand::bool();
        }

        // @uuid
        if ($value === '@uuid') {
            return Rand::uuid();
        }

        // @int(min,max)（支持负数边界）
        if (preg_match('/^@int\((-?\d+),\s*(-?\d+)\)$/', $value, $matches)) {
            return Rand::numberInt((int)$matches[1], (int)$matches[2]);
        }

        // @float(min,max,decimals)（支持负数边界）
        if (preg_match('/^@float\((-?\d+),\s*(-?\d+)(?:,\s*(\d+))?\)$/', $value, $matches)) {
            return Rand::numberFloat((int)$matches[1], (int)$matches[2], (int)($matches[3] ?? 2));
        }

        // @str(length)
        if (preg_match('/^@str\((\d+)\)$/', $value, $matches)) {
            return Rand::str((int)$matches[1]);
        }

        // @pick(a,b,c)
        if (preg_match('/^@pick\((.+)\)$/', $value, $matches)) {
            $options = array_map('trim', explode(',', $matches[1]));
            return $options[array_rand($options)];
        }

        return $value;
    }
}
