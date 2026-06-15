<?php

namespace LarkFrame\Util;

class File
{
    /**
     * 递归复制目录
     */
    public static function copyDir(string $source, string $dest, bool $overwrite = false): void
    {
        if (is_dir($source)) {
            if (!is_dir($dest)) {
                mkdir($dest, 0755, true);
            }
            $files = scandir($source);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    static::copyDir("$source/$file", "$dest/$file", $overwrite);
                }
            }
        } elseif (file_exists($source) && ($overwrite || !file_exists($dest))) {
            copy($source, $dest);
        }
    }

    /**
     * 递归删除目录
     */
    public static function removeDir(string $dir): bool
    {
        if (is_link($dir) || is_file($dir)) {
            return unlink($dir);
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file") && !is_link($dir)) ? static::removeDir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    /**
     * 扫描目录，返回文件列表
     */
    public static function scanDir(string $basePath, bool $withBasePath = true): array
    {
        if (!is_dir($basePath)) {
            return [];
        }
        $paths = array_diff(scandir($basePath), ['.', '..']) ?: [];
        return $withBasePath ? array_map(static fn($path) => $basePath . DIRECTORY_SEPARATOR . $path, $paths) : $paths;
    }

    /**
     * 获取真实路径（兼容 phar）
     */
    public static function getRealpath(string $filePath): string
    {
        if (str_starts_with($filePath, 'phar://')) {
            return $filePath;
        }
        return realpath($filePath) ?: $filePath;
    }

    /**
     * 确保目录存在，不存在则递归创建
     */
    public static function ensureDir(string $dir, int $mode = 0755): bool
    {
        if (is_dir($dir)) {
            return true;
        }
        return mkdir($dir, $mode, true);
    }

    /**
     * 安全写入文件（先写临时文件再重命名，避免写入中断导致文件损坏）
     */
    public static function safeWrite(string $path, string $content, int $mode = 0644): bool
    {
        $dir = dirname($path);
        static::ensureDir($dir);

        $tmpFile = $path . '.' . uniqid() . '.tmp';
        $result = file_put_contents($tmpFile, $content);
        if ($result === false) {
            @unlink($tmpFile);
            return false;
        }

        chmod($tmpFile, $mode);

        if (!rename($tmpFile, $path)) {
            @unlink($tmpFile);
            return false;
        }

        return true;
    }

    /**
     * 获取文件扩展名（小写）
     */
    public static function extension(string $path): string
    {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION));
    }

    /**
     * 获取人类可读的文件大小
     */
    public static function formatSize(string $path): string
    {
        if (!is_file($path)) {
            return '0 B';
        }
        return Str::formatBytes((int)filesize($path));
    }

    /**
     * 获取目录大小（递归）
     */
    public static function dirSize(string $dir): int
    {
        $size = 0;
        if (!is_dir($dir)) {
            return $size;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $size += $file->getSize();
        }
        return $size;
    }

    /**
     * 递归扫描目录获取所有文件
     */
    public static function scanDirRecursive(string $dir, bool $withBasePath = true): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $result = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $result[] = $withBasePath ? $file->getPathname() : $file->getFilename();
        }
        return $result;
    }
}
