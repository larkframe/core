<?php

namespace LarkFrame;

use function pathinfo;

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

    public function getUploadExtension(): string
    {
        return pathinfo($this->uploadName, PATHINFO_EXTENSION);
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
     * @deprecated Use getUploadMimeType() instead
     */
    public function getUploadMineType(): ?string
    {
        return $this->uploadMimeType;
    }
}
