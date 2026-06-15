<?php

namespace LarkFrame\Response;

use function explode;
use function rawurlencode;
use function setrawcookie;
use function time;

/**
 * Class ServerSender
 *
 * Response sender for the built-in server mode.
 * Returns formatted HTTP response strings for sending over socket connections.
 */
class ServerSender implements ResponseSenderInterface
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
        return $name . '=' . rawurlencode($value)
            . (empty($domain) ? '' : '; Domain=' . $domain)
            . '; Max-Age=' . (empty($maxAge) ? 86400 : $maxAge)
            . '; Expires=' . (time() + (empty($maxAge) ? 86400 : $maxAge))
            . (empty($path) ? '; Path=/' : '; Path=' . $path)
            . (!$secure ? '' : '; Secure')
            . (!$httpOnly ? '' : '; HttpOnly')
            . (empty($sameSite) ? '' : '; SameSite=' . $sameSite);
    }

    public function formatResponse(int $status, string $version, ?string $reason, array $headers, string $body): string
    {
        $reason = $reason ?: (\LarkFrame\Response::PHRASES[$status] ?? '');
        $head = "HTTP/$version $status $reason\r\n";

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $head .= "$name: $item\r\n";
                }
                continue;
            }
            $head .= "$name: $value\r\n";
        }

        $bodyLen = strlen($body);
        if (isset($headers['Transfer-Encoding']) && $bodyLen) {
            return "$head\r\n" . dechex($bodyLen) . "\r\n{$body}\r\n0\r\n\r\n";
        }

        return $head . "Content-Length: $bodyLen\r\n\r\n" . $body;
    }

    public function formatFileResponse(int $status, string $version, ?string $reason, array $headers): string
    {
        $reason = $reason ?: (\LarkFrame\Response::PHRASES[$status] ?? '');
        $head = "HTTP/$version $status $reason\r\n";

        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $head .= "$name: $item\r\n";
                }
                continue;
            }
            $head .= "$name: $value\r\n";
        }

        return "$head\r\n";
    }
}
