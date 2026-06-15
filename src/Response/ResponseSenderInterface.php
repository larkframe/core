<?php

namespace LarkFrame\Response;

/**
 * Interface ResponseSenderInterface
 *
 * Strategy for sending HTTP responses in different runtime modes.
 * Decouples response output logic from the Response object itself.
 */
interface ResponseSenderInterface
{
    /**
     * Send or format cookie.
     * In FPM mode: sends cookie via setcookie() and returns null.
     * In server mode: returns the formatted Set-Cookie header string.
     */
    public function sendCookie(
        string $name,
        string $value,
        ?int $maxAge,
        string $path,
        string $domain,
        bool $secure,
        bool $httpOnly,
        string $sameSite
    ): ?string;

    /**
     * Send headers and return the formatted response string.
     * For server mode: returns the full HTTP response string.
     * For FPM mode: sends headers via header() and returns only the body.
     */
    public function formatResponse(int $status, string $version, ?string $reason, array $headers, string $body): string;

    /**
     * Format file response headers.
     * For server mode: returns the full header string.
     * For FPM mode: sends headers via header() and returns empty string.
     */
    public function formatFileResponse(int $status, string $version, ?string $reason, array $headers): string;
}
