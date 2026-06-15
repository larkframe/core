<?php

namespace LarkFrame;

use SplFileInfo;
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

class File extends SplFileInfo
{
    /**
     * Move the file to a new location.
     */
    public function move(string $destination): self
    {
        $error = '';
        set_error_handler(static function (int $type, string $msg) use (&$error): bool {
            $error = $msg;
            return true;
        });

        $path = pathinfo($destination, PATHINFO_DIRNAME);
        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            restore_error_handler();
            throw new FileException(sprintf('Unable to create the "%s" directory (%s)', $path, strip_tags($error)));
        }

        if (!rename($this->getPathname(), $destination)) {
            restore_error_handler();
            throw new FileException(sprintf('Could not move the file "%s" to "%s" (%s)', $this->getPathname(), $destination, strip_tags($error)));
        }

        restore_error_handler();
        @chmod($destination, 0666 & ~umask());

        return new self($destination);
    }
}
