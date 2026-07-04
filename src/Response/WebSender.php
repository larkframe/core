<?php

namespace LarkFrame\Response;

use function explode;
use function flush;
use function header;
use function http_response_code;
use function rawurlencode;
use function setrawcookie;
use function time;

/**
 * Class WebSender
 *
 * Response sender for PHP-FPM/Web mode.
 * Uses PHP's header() and setrawcookie() functions for output.
 */
class WebSender implements ResponseSenderInterface
{
    public function sendCookie(
        string $name,
        string $value,
        ?int $maxAge,
        string $path,
        string $domain,
        bool $secure,
        bool $httpOnly,
        string $sameSite
    ): ?string {
        if (str_contains($domain, ':')) {
            $domain = explode(':', $domain)[0];
        }
        setrawcookie($name, rawurlencode($value), [
            'domain' => $domain,
            'expires' => time() + ($maxAge ?? 86400),
            'path' => empty($path) ? '/' : $path,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ]);
        return null;
    }

    public function formatResponse(int $status, string $version, ?string $reason, array $headers, string $body): string
    {
        http_response_code($status);
        $this->sendHeaders($headers);
        return $body;
    }

    public function formatFileResponse(int $status, string $version, ?string $reason, array $headers, ?array $file = null): string
    {
        http_response_code($status);
        $this->sendHeaders($headers);
        // FPM 模式必须显式输出文件正文，否则下载响应体为空
        if ($file !== null && isset($file['file']) && is_file($file['file'])) {
            $offset = $file['offset'] ?? 0;
            $length = $file['length'] ?? 0;
            if ($length > 0) {
                $fp = fopen($file['file'], 'rb');
                if ($fp !== false) {
                    fseek($fp, $offset);
                    echo stream_get_contents($fp, $length);
                    fclose($fp);
                }
            } elseif ($offset > 0) {
                $fp = fopen($file['file'], 'rb');
                if ($fp !== false) {
                    fseek($fp, $offset);
                    fpassthru($fp);
                    fclose($fp);
                }
            } else {
                readfile($file['file']);
            }
        }
        // P2-40：主动刷新输出缓冲，确保大文件下载内容及时推送到客户端
        flush();
        return '';
    }

    private function sendHeaders(array $headers): void
    {
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    header("$name: $item", false);
                }
                continue;
            }
            header("$name: $value", false);
        }
    }
}
