<?php

namespace LarkFrame\Util;

class Img
{
    /**
     * 将图片转换为 ICO 格式
     *
     * @param string $sourcePath 源图片路径
     * @param string $destinationPath 目标 ICO 文件路径
     * @param int $width ICO 宽度
     * @param int $height ICO 高度
     * @return bool 是否成功
     */
    public static function convertToIco(string $sourcePath, string $destinationPath, int $width = 32, int $height = 32): bool
    {
        if (!file_exists($sourcePath)) {
            return false;
        }

        $imageInfo = getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }

        $image = match ($imageInfo[2]) {
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_GIF => imagecreatefromgif($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : false,
            default => false,
        };

        if (!$image) {
            return false;
        }

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $resizedImage = imagecreatetruecolor($width, $height);
        imagealphablending($resizedImage, false);
        imagesavealpha($resizedImage, true);

        $transparent = imagecolorallocatealpha($resizedImage, 0, 0, 0, 127);
        imagefill($resizedImage, 0, 0, $transparent);

        // 保持宽高比缩放
        $srcWidth = imagesx($image);
        $srcHeight = imagesy($image);
        $ratio = min($width / $srcWidth, $height / $srcHeight);
        $newWidth = (int)($srcWidth * $ratio);
        $newHeight = (int)($srcHeight * $ratio);
        $xPos = (int)(($width - $newWidth) / 2);
        $yPos = (int)(($height - $newHeight) / 2);

        imagecopyresampled($resizedImage, $image, $xPos, $yPos, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);

        $icoData = static::generateIcoData($resizedImage, $width, $height);
        $result = file_put_contents($destinationPath, $icoData);

        imagedestroy($image);
        imagedestroy($resizedImage);

        return $result !== false;
    }

    /**
     * 缩放图片到指定尺寸
     */
    public static function resize(string $sourcePath, string $destinationPath, int $width, int $height, bool $keepAspectRatio = true): bool
    {
        $imageInfo = @getimagesize($sourcePath);
        if (!$imageInfo) {
            return false;
        }

        $srcImage = match ($imageInfo[2]) {
            IMAGETYPE_PNG => imagecreatefrompng($sourcePath),
            IMAGETYPE_JPEG => imagecreatefromjpeg($sourcePath),
            IMAGETYPE_GIF => imagecreatefromgif($sourcePath),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : false,
            default => false,
        };

        if (!$srcImage) {
            return false;
        }

        $srcWidth = imagesx($srcImage);
        $srcHeight = imagesy($srcImage);

        if ($keepAspectRatio) {
            $ratio = min($width / $srcWidth, $height / $srcHeight);
            $width = (int)($srcWidth * $ratio);
            $height = (int)($srcHeight * $ratio);
        }

        $dstImage = imagecreatetruecolor($width, $height);
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
        $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
        imagefill($dstImage, 0, 0, $transparent);

        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $width, $height, $srcWidth, $srcHeight);

        $ext = strtolower(pathinfo($destinationPath, PATHINFO_EXTENSION));
        $result = match ($ext) {
            'png' => imagepng($dstImage, $destinationPath),
            'gif' => imagegif($dstImage, $destinationPath),
            'webp' => function_exists('imagewebp') ? imagewebp($dstImage, $destinationPath) : false,
            default => imagejpeg($dstImage, $destinationPath, 85),
        };

        imagedestroy($srcImage);
        imagedestroy($dstImage);

        return $result !== false;
    }

    /**
     * 生成图片的 Base64 Data URI
     */
    public static function toDataUri(string $path): string
    {
        if (!file_exists($path)) {
            return '';
        }

        $imageInfo = @getimagesize($path);
        if (!$imageInfo) {
            return '';
        }

        $mime = $imageInfo['mime'];
        $data = base64_encode(file_get_contents($path));

        return "data:$mime;base64,$data";
    }

    /**
     * 生成 ICO 文件二进制数据
     */
    private static function generateIcoData(object $image, int $width, int $height): string
    {
        // ICO 文件头
        $ico = pack('vvv', 0, 1, 1);

        // AND 掩码
        $andStride = (int)(($width + 31) / 32) * 4;
        $andSize = $andStride * $height;
        $andMask = str_repeat("\x00", $andSize);

        // 图像目录条目
        $bpp = 32;
        $size = 40 + ($width * $height * 4) + $andSize;
        $ico .= pack('CCCCvvVV', $width, $height, 0, 0, 1, $bpp, $size, 0);

        // BMP 信息头
        $bmpHeader = pack('VVVvvVVVVVV', 40, $width, $height * 2, 1, $bpp, 0, $width * $height * 4, 0, 0, 0, 0);

        // 像素数据
        $pixels = '';
        for ($y = $height - 1; $y >= 0; $y--) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($image, $x, $y);
                $a = ($color >> 24) & 0xFF;
                $r = ($color >> 16) & 0xFF;
                $g = ($color >> 8) & 0xFF;
                $b = $color & 0xFF;
                $icoAlpha = ($a === 127) ? 0 : (255 - (int)($a * 255 / 127));
                $pixels .= pack('CCCC', $b, $g, $r, $icoAlpha);
            }
        }

        $icoData = $ico . $bmpHeader . $pixels . $andMask;

        // 更新偏移量
        $offset = strlen($ico);
        $icoData = substr_replace($icoData, pack('V', $offset), 18, 4);

        return $icoData;
    }
}
