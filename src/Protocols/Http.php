<?php

namespace LarkFrame\Protocols;

use LarkFrame\Connection\TcpConnection;
use function clearstatcache;
use function count;
use function ctype_digit;
use function ctype_xdigit;
use function explode;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function hexdec;
use function ini_get;
use function is_array;
use function is_object;
use function ltrim;
use function preg_match;
use function preg_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function trim;

/**
 * Class Http
 *
 * HTTP protocol implementation for parsing HTTP requests and encoding HTTP responses.
 * Optimized for PHP 8.1 with readonly properties, match expressions, and named arguments.
 */
class Http
{
    /**
     * Request class name.
     */
    protected static string $requestClass = \LarkFrame\Request::class;

    /**
     * Upload tmp dir.
     */
    protected static string $uploadTmpDir = '';

    /**
     * Bad request response.
     */
    protected const HTTP_400 = "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n";

    /**
     * Payload too large response.
     */
    protected const HTTP_413 = "HTTP/1.1 413 Payload Too Large\r\nConnection: close\r\n\r\n";

    /**
     * Request Header Fields Too Large response.
     */
    protected const HTTP_431 = "HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\n\r\n";

    /**
     * Max header length.
     */
    protected const MAX_HEADER_LENGTH = 16384;

    /**
     * Get or set the request class name.
     */
    public static function requestClass(?string $className = null): string
    {
        if ($className !== null) {
            static::$requestClass = $className;
        }
        return static::$requestClass;
    }

    /**
     * Check the integrity of the package.
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        $crlfPos = strpos($buffer, "\r\n\r\n");
        if (false === $crlfPos) {
            if (strlen($buffer) >= static::MAX_HEADER_LENGTH) {
                $connection->end(static::HTTP_431, true);
            }
            return 0;
        }

        $length = $crlfPos + 4;
        if ($crlfPos >= static::MAX_HEADER_LENGTH) {
            $connection->end(static::HTTP_431, true);
            return 0;
        }

        // Use connection context for cache isolation (Fiber-safe)
        $connection->context->httpInputCache ??= [];
        $connection->context->httpInputCacheKeys ??= [];
        $cache = &$connection->context->httpInputCache;
        $cacheKeys = &$connection->context->httpInputCacheKeys;

        // Use a hash as cache key to avoid storing large header strings
        $header = substr($buffer, 0, $length);
        $cacheKey = $length <= TcpConnection::MAX_CACHE_STRING_LENGTH ? $header : null;

        if ($cacheKey !== null && isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Validate request line.
        $firstLineEnd = strpos($header, "\r\n");
        if (!preg_match(
            '~^(?-i:GET|POST|OPTIONS|HEAD|DELETE|PUT|PATCH) /[^\x00-\x20\x7f]* (?-i:HTTP)/1\.(?<minor>[0-9])$~',
            substr($header, 0, $firstLineEnd),
            $matches
        )) {
            $connection->end(static::HTTP_400, true);
            return 0;
        }

        // Parse headers.
        $headers = [];
        $headerBody = substr($header, $firstLineEnd + 2, $crlfPos - $firstLineEnd - 2);
        foreach (explode("\r\n", $headerBody) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (!isset($parts[1]) || !preg_match('/^[a-zA-Z0-9!#$%&\'*+\-.^_`|~]+$/', $parts[0])) {
                $connection->end(static::HTTP_400, true);
                return 0;
            }
            $headers[strtolower($parts[0])][] = trim($parts[1], " \t");
        }

        // Host header validation.
        $hostCount = count($headers['host'] ?? []);
        if ($hostCount > 1 || ((int)$matches['minor'] > 0 && $hostCount === 0)) {
            $connection->end(static::HTTP_400, true);
            return 0;
        }
        if ($hostCount === 1 && !preg_match('/^(?:\[[^\]\r\n]+\]|[^\s:\/\[\]\r\n]+)(?::[0-9]+)?$/', $headers['host'][0])) {
            $connection->end(static::HTTP_400, true);
            return 0;
        }

        // Transfer-Encoding: chunked.
        if (isset($headers['transfer-encoding'])) {
            if (isset($headers['content-length'])
                || count($headers['transfer-encoding']) !== 1
                || strtolower($headers['transfer-encoding'][0]) !== 'chunked') {
                $connection->end(static::HTTP_400, true);
                return 0;
            }
            return static::inputChunked($buffer, $connection, $length);
        }

        // Content-Length.
        if (isset($headers['content-length'])) {
            if (count($headers['content-length']) !== 1 || !ctype_digit($headers['content-length'][0])) {
                $connection->end(static::HTTP_400, true);
                return 0;
            }
            $length += (int)$headers['content-length'][0];
        }

        if ($length > $connection->maxPackageSize) {
            $connection->end(static::HTTP_413, true);
            return 0;
        }

        if ($cacheKey !== null) {
            $cache[$cacheKey] = $length;
            if (count($cache) > TcpConnection::MAX_CACHE_SIZE) {
                $evictKey = array_shift($cacheKeys);
                unset($cache[$evictKey]);
            }
            $cacheKeys[] = $cacheKey;
        }

        return $length;
    }

    /**
     * Check the integrity of a chunked transfer-encoded request body.
     */
    protected static function inputChunked(string $buffer, TcpConnection $connection, int $headerLength): int
    {
        $connection->context ??= new \stdClass();
        $connection->context->chunked = true;

        $pos = $headerLength;
        $bufLen = strlen($buffer);
        $maxSize = $connection->maxPackageSize;

        while (true) {
            $lineEnd = strpos($buffer, "\r\n", $pos);
            if ($lineEnd === false) {
                return 0;
            }

            $semiPos = strpos($buffer, ';', $pos);
            $hexEnd = ($semiPos !== false && $semiPos < $lineEnd) ? $semiPos : $lineEnd;
            $hexStr = substr($buffer, $pos, $hexEnd - $pos);

            if ($hexStr === '' || !ctype_xdigit($hexStr) || isset($hexStr[16])) {
                $connection->end(static::HTTP_400, true);
                return 0;
            }

            $chunkSize = hexdec($hexStr);
            if (is_float($chunkSize)) {
                $connection->end(static::HTTP_400, true);
                return 0;
            }

            $pos = $lineEnd + 2;

            if ($chunkSize === 0) {
                while (true) {
                    $lineEnd = strpos($buffer, "\r\n", $pos);
                    if ($lineEnd === false) {
                        return 0;
                    }
                    if ($lineEnd === $pos) {
                        $totalLength = $pos + 2;
                        if ($totalLength > $maxSize) {
                            $connection->end(static::HTTP_413, true);
                            return 0;
                        }
                        return $totalLength;
                    }
                    $pos = $lineEnd + 2;
                }
            }

            if ($pos + $chunkSize + 2 > $bufLen) {
                return 0;
            }
            if (substr($buffer, $pos + $chunkSize, 2) !== "\r\n") {
                $connection->end(static::HTTP_400, true);
                return 0;
            }
            $pos += $chunkSize + 2;

            if ($pos > $maxSize) {
                $connection->end(static::HTTP_413, true);
                return 0;
            }
        }
    }

    /**
     * Decode HTTP request from buffer.
     */
    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        $trailers = [];
        if (isset($connection->context->chunked)) {
            unset($connection->context->chunked);
            [$buffer, $trailers] = static::decodeChunked($buffer, strpos($buffer, "\r\n\r\n"));
        }

        $request = new static::$requestClass($buffer);
        if ($trailers !== []) {
            $request->setChunkTrailers($trailers);
        }
        $request->connection = $connection;
        return $request;
    }

    /**
     * Decode chunked transfer-encoded request.
     */
    protected static function decodeChunked(string $buffer, int $headerEnd): array
    {
        $header = preg_replace('~\r\nTransfer-Encoding[ \t]*:[^\r]*~i', '', substr($buffer, 0, $headerEnd), 1);
        $body = '';
        $trailers = [];
        $pos = $headerEnd + 4;
        $bufLen = strlen($buffer);

        while (true) {
            $lineEnd = strpos($buffer, "\r\n", $pos);
            if ($lineEnd === false) {
                break;
            }

            $semiPos = strpos($buffer, ';', $pos);
            $hexEnd = ($semiPos !== false && $semiPos < $lineEnd) ? $semiPos : $lineEnd;
            $hexStr = substr($buffer, $pos, $hexEnd - $pos);
            if ($hexStr === '' || !ctype_xdigit($hexStr) || isset($hexStr[16])) {
                break;
            }

            $chunkSize = hexdec($hexStr);
            if (is_float($chunkSize)) {
                break;
            }
            $pos = $lineEnd + 2;

            if ($chunkSize === 0) {
                while (true) {
                    $lineEnd = strpos($buffer, "\r\n", $pos);
                    if ($lineEnd === false) {
                        break 2;
                    }
                    if ($lineEnd === $pos) {
                        $pos += 2;
                        break;
                    }
                    $colonPos = strpos($buffer, ':', $pos);
                    if ($colonPos !== false && $colonPos < $lineEnd) {
                        $trailers[strtolower(substr($buffer, $pos, $colonPos - $pos))] = ltrim(substr($buffer, $colonPos + 1, $lineEnd - $colonPos - 1));
                    }
                    $pos = $lineEnd + 2;
                }
                break;
            }

            if ($pos + $chunkSize + 2 > $bufLen) {
                break;
            }
            if (substr($buffer, $pos + $chunkSize, 2) !== "\r\n") {
                break;
            }
            $body .= substr($buffer, $pos, $chunkSize);
            $pos += $chunkSize + 2;
        }

        return [$header . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body, $trailers];
    }

    /**
     * Encode HTTP response.
     */
    public static function encode(mixed $response, TcpConnection $connection): string
    {
        if (!is_object($response)) {
            return static::encodeRawResponse($response, $connection);
        }

        if ($connection->headers) {
            $response->withHeaders($connection->headers);
            $connection->headers = [];
        }

        if (isset($response->file)) {
            return static::encodeFileResponse($response, $connection);
        }

        return (string)$response;
    }

    /**
     * Encode a raw (non-object) response.
     */
    private static function encodeRawResponse(mixed $response, TcpConnection $connection): string
    {
        $extHeader = '';
        $contentType = 'text/html;charset=utf-8';

        foreach ($connection->headers as $name => $value) {
            if ($name === 'Content-Type') {
                $contentType = $value;
                continue;
            }
            $extHeader .= is_array($value)
                ? implode('', array_map(fn($item) => "$name: $item\r\n", $value))
                : "$name: $value\r\n";
        }

        $connection->headers = [];
        $response = (string)$response;
        $bodyLen = strlen($response);

        return "HTTP/1.1 200 OK\r\n{$extHeader}Connection: keep-alive\r\nContent-Type: $contentType\r\nContent-Length: $bodyLen\r\n\r\n$response";
    }

    /**
     * Encode a file response.
     */
    private static function encodeFileResponse(mixed $response, TcpConnection $connection): string
    {
        $file = $response->file['file'];
        $offset = $response->file['offset'] ?: 0;
        $length = $response->file['length'] ?: 0;

        clearstatcache(true, $file);
        $fileSize = (int)filesize($file);
        $bodyLen = $length > 0 ? $length : $fileSize - $offset;

        $response->withHeaders([
            'Content-Length' => $bodyLen,
            'Accept-Ranges' => 'bytes',
        ]);

        if ($offset || $length) {
            $offsetEnd = $offset + $bodyLen - 1;
            $response->header('Content-Range', "bytes $offset-$offsetEnd/$fileSize");
            $response->withStatus(206);
        }

        // Small files: send in one go.
        if ($bodyLen < 2 * 1024 * 1024) {
            $connection->send($response . file_get_contents($file, false, null, $offset, $bodyLen), true);
            return '';
        }

        // Large files: stream.
        $handler = fopen($file, 'r');
        if (false === $handler) {
            $connection->close((string)(new \LarkFrame\Response(403, [], '403 Forbidden')));
            return '';
        }

        $connection->send((string)$response, true);
        static::sendStream($connection, $handler, $offset, $length);
        return '';
    }

    /**
     * Send remainder of a stream to client.
     */
    protected static function sendStream(TcpConnection $connection, $handler, int $offset = 0, int $length = 0): void
    {
        $connection->context->bufferFull = false;
        $connection->context->streamSending = true;

        if ($offset !== 0) {
            fseek($handler, $offset);
        }

        $offsetEnd = $offset + $length;

        $doWrite = function () use ($connection, $handler, $length, $offsetEnd): void {
            while ($connection->context->bufferFull === false) {
                $size = 1024 * 1024;
                if ($length !== 0) {
                    $tell = ftell($handler);
                    $remainSize = $offsetEnd - $tell;
                    if ($remainSize <= 0) {
                        fclose($handler);
                        $connection->onBufferDrain = null;
                        return;
                    }
                    $size = min($remainSize, $size);
                }

                $buffer = fread($handler, $size);
                if ($buffer === '' || $buffer === false) {
                    fclose($handler);
                    $connection->onBufferDrain = null;
                    $connection->context->streamSending = false;
                    return;
                }
                $connection->send($buffer, true);
            }
        };

        $connection->onBufferFull = function (TcpConnection $connection): void {
            $connection->context->bufferFull = true;
        };

        $connection->onBufferDrain = function (TcpConnection $connection) use ($doWrite): void {
            $connection->context->bufferFull = false;
            $doWrite();
        };

        $doWrite();
    }

    /**
     * Get or set upload tmp dir.
     */
    public static function uploadTmpDir(string|null $dir = null): string
    {
        if (null !== $dir) {
            static::$uploadTmpDir = $dir;
        }

        if (static::$uploadTmpDir === '') {
            static::$uploadTmpDir = ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
        }

        return static::$uploadTmpDir;
    }
}
