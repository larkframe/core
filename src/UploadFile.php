<?php

namespace LarkFrame;

use Symfony\Component\HttpFoundation\File\Exception\FileException;
use function chmod;
use function is_dir;
use function mkdir;
use function pathinfo;
use function restore_error_handler;
use function set_error_handler;
use function sprintf;
use function strip_tags;
use function umask;
use const FILEINFO_EXTENSION;
use const PHP_SAPI;

class UploadFile extends File
{
    public function __construct(
        string $fileName,
        protected ?string $uploadName = null,
        protected ?string $uploadMimeType = null,
        protected ?int $uploadErrorCode = null,
    ) {
        parent::__construct($fileName);
    }

    public function getUploadName(): ?string
    {
        return $this->uploadName;
    }

    public function getUploadMimeType(): ?string
    {
        return $this->uploadMimeType;
    }

    /**
     * P2-15 方案 A：用 finfo 从临时文件内容推断扩展名，避免信任客户端 uploadName 被伪造。
     */
    public function getUploadExtension(): string
    {
        $finfo = new \finfo(FILEINFO_EXTENSION);
        $ext = $finfo->file($this->getPathname());
        if ($ext === false || $ext === '') {
            // finfo 失败时回退到客户端名（已无更好方案，业务层应做白名单校验）
            return strtolower(pathinfo($this->uploadName ?? '', PATHINFO_EXTENSION));
        }
        // finfo 对某些文件返回 "xxx jpeg" 等多扩展，取第一段
        return strtolower(explode(' ', $ext)[0]);
    }

    public function getUploadErrorCode(): ?int
    {
        return $this->uploadErrorCode;
    }

    public function isValid(): bool
    {
        return $this->uploadErrorCode === UPLOAD_ERR_OK;
    }

    /**
     * P2-14 方案 A：FPM 模式强制 move_uploaded_file 校验上传来源，
     * 防止攻击者借助非 HTTP 上传通道读取任意文件作为上传。
     * Server/Shell 模式无 $_FILES 概念，回退到 rename。
     */
    public function move(string $destination): self
    {
        $error = '';
        set_error_handler(static function (int $type, string $msg) use (&$error): bool {
            $error = $msg;
            return true;
        });

        try {
            $path = pathinfo($destination, PATHINFO_DIRNAME);
            if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
                throw new FileException(sprintf('Unable to create the "%s" directory (%s)', $path, strip_tags($error)));
            }

            if (PHP_SAPI !== 'cli') {
                // FPM 模式：move_uploaded_file 内部校验 is_uploaded_file
                if (!move_uploaded_file($this->getPathname(), $destination)) {
                    throw new FileException(sprintf('Could not move the uploaded file "%s" to "%s" (%s)', $this->getPathname(), $destination, strip_tags($error)));
                }
            } else {
                // Server/Shell 模式：无 $_FILES 机制，直接 rename；
                // 跨设备 rename 失败（如 /tmp 独立分区 → 数据盘）回退 copy + unlink，与父类 File::move 行为一致
                if (!rename($this->getPathname(), $destination)) {
                    if (!@copy($this->getPathname(), $destination)) {
                        throw new FileException(sprintf('Could not move the file "%s" to "%s" (%s)', $this->getPathname(), $destination, strip_tags($error)));
                    }
                    @unlink($this->getPathname());
                }
            }

            @chmod($destination, 0666 & ~umask());
            // 保留上传元数据（原名/MIME/错误码），否则链式调用 getUploadName()/isValid() 全部失效
            return new self($destination, $this->uploadName, $this->uploadMimeType, $this->uploadErrorCode);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @deprecated Use getUploadMimeType() instead
     */
    public function getUploadMineType(): ?string
    {
        return $this->uploadMimeType;
    }
}
