<?php

namespace LarkFrame\Response;

use function explode;
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

    public function formatFileResponse(int $status, string $version, ?string $reason, array $headers): string
    {
        http_response_code($status);
        $this->sendHeaders($headers);
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
