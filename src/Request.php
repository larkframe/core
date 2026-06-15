<?php

namespace LarkFrame;

use Exception;
use LarkFrame\Consts;
use LarkFrame\Request\RequestSourceInterface;
use LarkFrame\Request\ServerSource;
use LarkFrame\Request\WebSource;
use LarkFrame\Request\ShellSource;
use LarkFrame\Util\Rand;
use RuntimeException;
use Stringable;
use LarkFrame\Connection\TcpConnection;
use LarkFrame\Protocols\Http;
use LarkFrame\UploadFile;
use function array_walk_recursive;
use function bin2hex;
use function clearstatcache;
use function count;
use function current;
use function explode;
use function file_put_contents;
use function filter_var;
use function ip2long;
use function is_array;
use function is_file;
use function json_decode;
use function ltrim;
use function microtime;
use function pack;
use function parse_str;
use function parse_url;
use function preg_match;
use function preg_replace;
use function strlen;
use function strpos;
use function strstr;
use function strtolower;
use function substr;
use function tempnam;
use function trim;
use function unlink;
use function urlencode;
use const FILTER_FLAG_IPV4;
use const FILTER_FLAG_NO_PRIV_RANGE;
use const FILTER_FLAG_NO_RES_RANGE;
use const FILTER_VALIDATE_IP;

class Request implements Stringable
{
    /**
     * Connection.
     */
    public ?TcpConnection $connection = null;

    /**
     * Maximum file uploads.
     */
    public static int $maxFileUploads = 1024;

    /**
     * Maximum string length for cache.
     */
    public const MAX_CACHE_STRING_LENGTH = 4096;

    /**
     * Maximum cache size.
     */
    public const MAX_CACHE_SIZE = 256;

    /**
     * Dynamic properties.
     */
    public array $properties = [];

    /**
     * Request data.
     */
    protected array $data = [];

    /**
     * Is safe.
     */
    protected bool $isSafe = true;

    /**
     * Context.
     */
    public array $context = [];

    /**
     * Controller name (set by App).
     */
    protected string $controller = '';

    /**
     * Action name (set by App).
     */
    protected string $action = '';

    /**
     * Route object (set by App).
     */
    protected mixed $route = null;

    /**
     * App name (set by App).
     */
    protected string $app = '';

    /**
     * Request source strategy.
     */
    protected RequestSourceInterface $source;

    /**
     * Whether the source has been initialized.
     */
    protected bool $sourceInitialized = false;

    /**
     * Constructor.
     */
    public function __construct(protected string $buffer = '')
    {
        $this->source = $this->resolveSource();
        $this->source->populateData($this->data);
        $this->sourceInitialized = true;
    }

    /**
     * Resolve the appropriate request source based on runtime type.
     */
    protected function resolveSource(): RequestSourceInterface
    {
        if (!defined('RUN_TYPE')) {
            return new WebSource();
        }
        return match (RUN_TYPE) {
            Consts::RUN_TYPE_SERVER => new ServerSource(),
            Consts::RUN_TYPE_WEB => new WebSource(),
            Consts::RUN_TYPE_SHELL => new ShellSource(),
            Consts::RUN_TYPE_TASK => new ServerSource(),
            default => new WebSource(),
        };
    }

    /**
     * Check if running in server mode (has raw HTTP buffer).
     */
    protected function isServerMode(): bool
    {
        return $this->source->hasRawBuffer();
    }

    public function initRequestIdAndStartTime(): void
    {
        if ($this->isServerMode()) {
            $this->data['requestId'] = strtolower(substr(md5(microtime() . uniqid(gethostname() . '_', true)), 8, 16) . Rand::str(16));
            $this->data['startTime'] = microtime(true);
        }
    }

    /**
     * Get query parameter(s).
     * Returns raw values without XSS filtering — escape at output time instead.
     */
    public function get(?string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['get']) && $this->isServerMode()) {
            $this->parseGet();
        }
        if ($name === null) {
            return $this->data['get'];
        }
        if (!isset($this->data['get'][$name])) {
            return $default;
        }
        return $this->data['get'][$name];
    }

    /**
     * Get post parameter(s).
     * Returns raw values without XSS filtering — escape at output time instead.
     */
    public function post(?string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['post']) && $this->isServerMode()) {
            $this->parsePost();
        }
        if ($name === null) {
            return $this->data['post'];
        }
        if (!isset($this->data['post'][$name])) {
            return $default;
        }
        return $this->data['post'][$name];
    }

    /**
     * Get header(s).
     */
    public function header(?string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['headers']) && $this->isServerMode()) {
            $this->parseHeaders();
        }
        if ($name === null) {
            return $this->data['headers'];
        }
        $name = strtolower($name);
        return $this->data['headers'][$name] ?? $default;
    }

    /**
     * Get cookie(s).
     */
    public function cookie(?string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['cookie']) && $this->isServerMode()) {
            $cookies = explode(';', $this->header('cookie', ''));
            $mapped = [];
            foreach ($cookies as $cookie) {
                $cookie = explode('=', $cookie, 2);
                if (count($cookie) !== 2) {
                    continue;
                }
                $mapped[trim($cookie[0])] = $cookie[1];
            }
            $this->data['cookie'] = $mapped;
        }
        if ($name === null) {
            return $this->data['cookie'];
        }
        return $this->data['cookie'][$name] ?? $default;
    }

    /**
     * Get upload file(s).
     */
    public function file(?string $name = null): mixed
    {
        if ($this->isServerMode()) {
            if (!empty($this->data['files'])) {
                // Only clear stat cache for the specific temp files being validated
                $needsReparse = false;
                array_walk_recursive($this->data['files'], function (mixed $value, int|string $key) use (&$needsReparse): void {
                    if ($key === 'tmp_name' && is_string($value) && $value !== '') {
                        clearstatcache(true, $value);
                        if (!is_file($value)) {
                            $needsReparse = true;
                        }
                    }
                });
                if ($needsReparse) {
                    $this->data['files'] = [];
                }
            }
        }
        if (empty($this->data['files'])) {
            $this->parsePost();
        }
        if ($name === null) {
            return $this->data['files'];
        }
        return $this->data['files'][$name] ?? null;
    }

    /**
     * Get HTTP method.
     */
    public function method(): string
    {
        if (!isset($this->data['method']) && $this->isServerMode()) {
            $this->parseHeadFirstLine();
        }
        return $this->data['method'];
    }

    /**
     * Get HTTP protocol version.
     */
    public function protocolVersion(): string
    {
        if (!isset($this->data['protocolVersion']) && $this->isServerMode()) {
            $this->parseProtocolVersion();
        }
        return $this->data['protocolVersion'] ?? '';
    }

    /**
     * Get host.
     */
    public function host(bool $withoutPort = false): ?string
    {
        if ($this->isServerMode()) {
            $host = $this->header('host');
            if ($host && $withoutPort) {
                return preg_replace('/:\d{1,5}$/', '', $host);
            }
            return $host;
        }
        return $this->source->getHost($withoutPort);
    }

    /**
     * Get URI.
     */
    public function uri(): string
    {
        if (!isset($this->data['uri']) && $this->isServerMode()) {
            $this->parseHeadFirstLine();
        }
        return $this->data['uri'];
    }

    public function requestId(): string
    {
        return $this->data['requestId'] ?? '';
    }

    public function usedTime(): float|int
    {
        $usedTimeMs = (microtime(true) - ($this->data['startTime'] ?? 0)) * 1000;
        if ($usedTimeMs < 1) {
            // Sub-millisecond: return microseconds as fraction of ms (e.g. 0.123 ms)
            return round($usedTimeMs, 3);
        }
        return (int)$usedTimeMs;
    }

    /**
     * Get path.
     */
    public function path(): string
    {
        return $this->data['path'] ??= (string)parse_url($this->uri(), PHP_URL_PATH);
    }

    /**
     * Get query string.
     */
    public function queryString(): string
    {
        return $this->data['query_string'] ??= (string)parse_url($this->uri(), PHP_URL_QUERY);
    }

    /**
     * Get raw HTTP head.
     */
    public function rawHead(): string
    {
        if (!$this->isServerMode()) {
            return '';
        }
        return $this->data['head'] ??= strstr($this->buffer, "\r\n\r\n", true);
    }

    /**
     * Get raw HTTP body.
     */
    public function rawBody(): string
    {
        if (!$this->isServerMode()) {
            return '';
        }
        return $this->data['rawBody'] ??= substr($this->buffer, strpos($this->buffer, "\r\n\r\n") + 4);
    }

    /**
     * Get raw buffer.
     */
    public function rawBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Parse first line of HTTP header.
     */
    protected function parseHeadFirstLine(): void
    {
        if (!$this->isServerMode()) {
            return;
        }
        $firstLine = strstr($this->buffer, "\r\n", true);
        $tmp = explode(' ', $firstLine, 3);
        $this->data['method'] = $tmp[0];
        $this->data['uri'] = $tmp[1] ?? '/';
    }

    /**
     * Parse protocol version.
     */
    protected function parseProtocolVersion(): void
    {
        $firstLine = strstr($this->buffer, "\r\n", true);
        $httpStr = strstr($firstLine, 'HTTP/');
        $this->data['protocolVersion'] = $httpStr ? substr($httpStr, 5) : '1.0';
    }

    /**
     * Parse headers.
     */
    protected function parseHeaders(): void
    {
        $this->data['headers'] = [];
        $rawHead = $this->rawHead();
        $endLinePosition = strpos($rawHead, "\r\n");
        if ($endLinePosition === false) {
            return;
        }
        $headBuffer = substr($rawHead, $endLinePosition + 2);
        $cacheable = !isset($headBuffer[static::MAX_CACHE_STRING_LENGTH]);

        // Use connection context for cache isolation (Fiber-safe)
        if ($this->connection) {
            $this->connection->context->headerCache ??= [];
            $this->connection->context->headerCacheKeys ??= [];
            $cache = &$this->connection->context->headerCache;
            $cacheKeys = &$this->connection->context->headerCacheKeys;
        } else {
            static $fallbackCache = [];
            static $fallbackCacheKeys = [];
            $cache = &$fallbackCache;
            $cacheKeys = &$fallbackCacheKeys;
        }

        if ($cacheable && isset($cache[$headBuffer])) {
            $this->data['headers'] = $cache[$headBuffer];
            return;
        }
        foreach (explode("\r\n", $headBuffer) as $content) {
            if (str_contains($content, ':')) {
                [$key, $value] = explode(':', $content, 2);
                $key = strtolower($key);
                $value = ltrim($value);
            } else {
                $key = strtolower($content);
                $value = '';
            }
            $this->data['headers'][$key] = isset($this->data['headers'][$key])
                ? "{$this->data['headers'][$key]},$value"
                : $value;
        }
        if ($cacheable) {
            if (count($cache) >= static::MAX_CACHE_SIZE) {
                $evictKey = array_shift($cacheKeys);
                unset($cache[$evictKey]);
            }
            $cache[$headBuffer] = $this->data['headers'];
            $cacheKeys[] = $headBuffer;
        }
    }

    /**
     * Parse GET parameters.
     */
    protected function parseGet(): void
    {
        $queryString = $this->queryString();
        $this->data['get'] = [];
        if ($queryString === '') {
            return;
        }
        $cacheable = !isset($queryString[static::MAX_CACHE_STRING_LENGTH]);

        // Use connection context for cache isolation (Fiber-safe)
        if ($this->connection) {
            $this->connection->context->getCache ??= [];
            $this->connection->context->getCacheKeys ??= [];
            $cache = &$this->connection->context->getCache;
            $cacheKeys = &$this->connection->context->getCacheKeys;
        } else {
            static $fallbackCache = [];
            static $fallbackCacheKeys = [];
            $cache = &$fallbackCache;
            $cacheKeys = &$fallbackCacheKeys;
        }

        if ($cacheable && isset($cache[$queryString])) {
            $this->data['get'] = $cache[$queryString];
            return;
        }
        parse_str($queryString, $this->data['get']);
        if ($cacheable) {
            if (count($cache) >= static::MAX_CACHE_SIZE) {
                $evictKey = array_shift($cacheKeys);
                unset($cache[$evictKey]);
            }
            $cache[$queryString] = $this->data['get'];
            $cacheKeys[] = $queryString;
        }
    }

    /**
     * Parse POST data.
     */
    protected function parsePost(): void
    {
        $this->data['post'] = $this->data['files'] = [];
        $contentType = $this->header('content-type', '');
        if (preg_match('/boundary="?(\S+)"?/', $contentType, $match)) {
            $httpPostBoundary = '--' . $match[1];
            $this->parseUploadFiles($httpPostBoundary);
            return;
        }
        $bodyBuffer = $this->rawBody();
        if ($bodyBuffer === '') {
            return;
        }
        $cacheable = !isset($bodyBuffer[static::MAX_CACHE_STRING_LENGTH]);

        // Use connection context for cache isolation (Fiber-safe)
        if ($this->connection) {
            $this->connection->context->postCache ??= [];
            $this->connection->context->postCacheKeys ??= [];
            $cache = &$this->connection->context->postCache;
            $cacheKeys = &$this->connection->context->postCacheKeys;
        } else {
            static $fallbackCache = [];
            static $fallbackCacheKeys = [];
            $cache = &$fallbackCache;
            $cacheKeys = &$fallbackCacheKeys;
        }

        if ($cacheable && isset($cache[$bodyBuffer])) {
            $this->data['post'] = $cache[$bodyBuffer];
            return;
        }
        $this->data['post'] = preg_match('/\bjson\b/i', $contentType)
            ? (array)json_decode($bodyBuffer, true)
            : (parse_str($bodyBuffer, $parsed) ?: $parsed);
        if ($cacheable) {
            if (count($cache) >= static::MAX_CACHE_SIZE) {
                $evictKey = array_shift($cacheKeys);
                unset($cache[$evictKey]);
            }
            $cache[$bodyBuffer] = $this->data['post'];
            $cacheKeys[] = $bodyBuffer;
        }
    }

    /**
     * Parse upload files.
     */
    protected function parseUploadFiles(string $httpPostBoundary): void
    {
        $httpPostBoundary = trim($httpPostBoundary, '"');
        $buffer = $this->buffer;
        $postEncodeString = '';
        $filesEncodeString = '';
        $files = [];
        $bodyPosition = strpos($buffer, "\r\n\r\n") + 4;
        $offset = $bodyPosition + strlen($httpPostBoundary) + 2;
        $maxCount = static::$maxFileUploads;
        while ($maxCount-- > 0 && $offset) {
            $offset = $this->parseUploadFile($httpPostBoundary, $offset, $postEncodeString, $filesEncodeString, $files);
        }
        if ($postEncodeString) {
            parse_str($postEncodeString, $this->data['post']);
        }
        if ($filesEncodeString) {
            parse_str($filesEncodeString, $this->data['files']);
            array_walk_recursive($this->data['files'], function (mixed &$value) use ($files): void {
                $value = $files[$value];
            });
        }
    }

    /**
     * Parse a single upload file section.
     */
    protected function parseUploadFile(string $boundary, int $sectionStartOffset, string &$postEncodeString, string &$filesEncodeStr, array &$files): int
    {
        $file = [];
        $boundary = "\r\n$boundary";
        if (strlen($this->buffer) < $sectionStartOffset) {
            return 0;
        }
        $sectionEndOffset = strpos($this->buffer, $boundary, $sectionStartOffset);
        if (!$sectionEndOffset) {
            return 0;
        }
        $contentLinesEndOffset = strpos($this->buffer, "\r\n\r\n", $sectionStartOffset);
        if (!$contentLinesEndOffset || $contentLinesEndOffset + 4 > $sectionEndOffset) {
            return 0;
        }
        $contentLinesStr = substr($this->buffer, $sectionStartOffset, $contentLinesEndOffset - $sectionStartOffset);
        $contentLines = explode("\r\n", trim($contentLinesStr . "\r\n"));
        $boundaryValue = substr($this->buffer, $contentLinesEndOffset + 4, $sectionEndOffset - $contentLinesEndOffset - 4);
        $uploadKey = false;

        foreach ($contentLines as $contentLine) {
            if (!str_contains($contentLine, ': ')) {
                return 0;
            }
            [$key, $value] = explode(': ', $contentLine);

            match (strtolower($key)) {
                'content-disposition' => $this->parseContentDisposition(
                    $value, $boundaryValue, $uploadKey, $file, $postEncodeString
                ) ?? null,
                'content-type' => $file['type'] = trim($value),
                'webkitrelativepath' => $file['full_path'] = trim($value),
                default => null,
            };

            if (strtolower($key) === 'content-disposition') {
                if ($uploadKey === false) {
                    return $sectionEndOffset + strlen($boundary) + 2;
                }
            }
        }

        if ($uploadKey === false) {
            return 0;
        }
        $filesEncodeStr .= urlencode($uploadKey) . '=' . count($files) . '&';
        $files[] = $file;

        return $sectionEndOffset + strlen($boundary) + 2;
    }

    /**
     * Parse Content-Disposition header for upload files.
     */
    private function parseContentDisposition(
        string $value,
        string $boundaryValue,
        bool|int|null &$uploadKey,
        array &$file,
        string &$postEncodeString
    ): void {
        // Is file data.
        if (preg_match('/name="(.*?)"; filename="(.*?)"/i', $value, $match)) {
            $error = 0;
            $tmpFile = '';
            $fileName = $match[1];
            $size = strlen($boundaryValue);
            $tmpUploadDir = Http::uploadTmpDir();
            if (!$tmpUploadDir) {
                $error = UPLOAD_ERR_NO_TMP_DIR;
            } elseif ($boundaryValue === '' && $match[2] === '') {
                $error = UPLOAD_ERR_NO_FILE;
            } else {
                $tmpFile = tempnam($tmpUploadDir, 'lark.upload.');
                if ($tmpFile === false || false === file_put_contents($tmpFile, $boundaryValue)) {
                    $error = UPLOAD_ERR_CANT_WRITE;
                }
            }
            $uploadKey = $fileName;
            $file = [...$file, 'name' => $match[2], 'tmp_name' => $tmpFile, 'size' => $size, 'error' => $error, 'full_path' => $match[2]];
            $file['type'] ??= '';
            return;
        }

        // Is post field.
        if (preg_match('/name="(.*?)"$/', $value, $match)) {
            $k = $match[1];
            $postEncodeString .= urlencode($k) . "=" . urlencode($boundaryValue) . '&';
        }
    }

    /**
     * Set chunk trailers (called by Http::decode).
     */
    public function setChunkTrailers(array $trailers): void
    {
        $this->data['trailers'] = $trailers;
    }

    public function __toString(): string
    {
        return $this->buffer;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->properties[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        return $this->properties[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    public function __unset(string $name): void
    {
        unset($this->properties[$name]);
    }

    public function __wakeup(): void
    {
        $this->data = [];
    }

    /**
     * Get controller name.
     */
    public function controller(): string
    {
        return $this->controller;
    }

    /**
     * Set controller name (used by App).
     */
    public function setController(string $controller): void
    {
        $this->controller = $controller;
    }

    /**
     * Get action name.
     */
    public function action(): string
    {
        return $this->action;
    }

    /**
     * Set action name (used by App).
     */
    public function setAction(string $action): void
    {
        $this->action = $action;
    }

    /**
     * Get route object.
     */
    public function route(): mixed
    {
        return $this->route;
    }

    /**
     * Set route object (used by App).
     */
    public function setRoute(mixed $route): void
    {
        $this->route = $route;
    }

    /**
     * Get app name.
     */
    public function app(): string
    {
        return $this->app;
    }

    /**
     * Set app name (used by App).
     */
    public function setApp(string $app): void
    {
        $this->app = $app;
    }

    // ─── Convenience methods (merged from LarkFrame\Request) ───────────────────

    /**
     * @var bool
     */
    protected bool $isDirty = false;

    /**
     * Get all input (GET + POST).
     */
    public function all(): array
    {
        return $this->get() + $this->post();
    }

    /**
     * Get input value from GET or POST.
     */
    public function input(string $name, mixed $default = null): mixed
    {
        return $this->get($name, $this->post($name, $default));
    }

    /**
     * Get upload file(s) as UploadFile objects.
     */
    public function uploadFile(?string $name = null): array|null|UploadFile
    {
        $files = $this->file($name);
        if (null === $files) {
            return $name === null ? [] : null;
        }
        if ($name !== null) {
            if (is_array(current($files))) {
                return $this->parseUploadFileObjects($files);
            }
            return $this->parseUploadFileObject($files);
        }
        $uploadFiles = [];
        foreach ($files as $key => $file) {
            if (is_array(current($file))) {
                $uploadFiles[$key] = $this->parseUploadFileObjects($file);
            } else {
                $uploadFiles[$key] = $this->parseUploadFileObject($file);
            }
        }
        return $uploadFiles;
    }

    protected function parseUploadFileObject(array $file): UploadFile
    {
        return new UploadFile($file['tmp_name'], $file['name'], $file['type'], $file['error']);
    }

    protected function parseUploadFileObjects(array $files): array
    {
        $uploadFiles = [];
        foreach ($files as $key => $file) {
            if (is_array(current($file))) {
                $uploadFiles[$key] = $this->parseUploadFileObjects($file);
            } else {
                $uploadFiles[$key] = $this->parseUploadFileObject($file);
            }
        }
        return $uploadFiles;
    }

    /**
     * Get remote IP address.
     */
    public function getRemoteIp(): string
    {
        if (defined('RUN_TYPE') && RUN_TYPE != Consts::RUN_TYPE_SERVER) {
            return getClientIp();
        }
        return $this->connection ? $this->connection->getRemoteIp() : '127.0.0.1';
    }

    /**
     * Get remote port.
     */
    public function getRemotePort(): int
    {
        if (defined('RUN_TYPE') && RUN_TYPE != Consts::RUN_TYPE_SERVER) {
            return $_SERVER['REMOTE_PORT'] ?? 0;
        }
        return $this->connection ? $this->connection->getRemotePort() : 0;
    }

    /**
     * Get local IP address.
     */
    public function getLocalIp(): string
    {
        if (defined('RUN_TYPE') && RUN_TYPE != Consts::RUN_TYPE_SERVER) {
            return $_SERVER['SERVER_ADDR'] ?? '127.0.0.1';
        }
        return $this->connection ? $this->connection->getLocalIp() : '127.0.0.1';
    }

    /**
     * Get local port.
     */
    public function getLocalPort(): int
    {
        if (defined('RUN_TYPE') && RUN_TYPE != Consts::RUN_TYPE_SERVER) {
            return $_SERVER['SERVER_PORT'] ?? 0;
        }
        return $this->connection ? $this->connection->getLocalPort() : 0;
    }

    /**
     * Get real IP (considering proxies).
     */
    public function getRealIp(bool $safeMode = true): string
    {
        $remoteIp = $this->getRemoteIp();
        if ($safeMode && !static::isIntranetIp($remoteIp)) {
            return $remoteIp;
        }
        $ip = $this->header('x-forwarded-for')
            ?? $this->header('x-real-ip')
            ?? $this->header('client-ip')
            ?? $this->header('x-client-ip')
            ?? $this->header('via')
            ?? $remoteIp;
        if (is_string($ip)) {
            $ip = current(explode(',', $ip));
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : $remoteIp;
    }

    /**
     * Get URL (without query string).
     */
    public function url(): string
    {
        return '//' . $this->host() . $this->path();
    }

    /**
     * Get full URL (with query string).
     */
    public function fullUrl(): string
    {
        return '//' . $this->host() . $this->uri();
    }

    /**
     * Check if AJAX request.
     */
    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * Check if GET request.
     */
    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    /**
     * Check if POST request.
     */
    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Check if PJAX request.
     */
    public function isPjax(): bool
    {
        return (bool)$this->header('X-PJAX');
    }

    /**
     * Check if expects JSON response.
     */
    public function expectsJson(): bool
    {
        return ($this->isAjax() && !$this->isPjax()) || $this->acceptJson();
    }

    /**
     * Check if accepts JSON.
     */
    public function acceptJson(): bool
    {
        return false !== strpos($this->header('accept', ''), 'json');
    }

    /**
     * Check if IP is intranet.
     */
    public static function isIntranetIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $reservedIps = [
            1681915904 => 1686110207,
            3221225472 => 3221225727,
            3221225984 => 3221226239,
            3227017984 => 3227018239,
            3323068416 => 3323199487,
            3325256704 => 3325256959,
            3405803776 => 3405804031,
            3758096384 => 4026531839,
        ];
        $ipLong = ip2long($ip);
        foreach ($reservedIps as $ipStart => $ipEnd) {
            if (($ipLong >= $ipStart) && ($ipLong <= $ipEnd)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Set GET parameters.
     */
    public function setGet(array|string $input, mixed $value = null): static
    {
        $this->isDirty = true;
        $input = is_array($input) ? $input : array_merge($this->get(), [$input => $value]);
        if (isset($this->data)) {
            $this->data['get'] = $input;
        }
        return $this;
    }

    /**
     * Set POST parameters.
     */
    public function setPost(array|string $input, mixed $value = null): static
    {
        $this->isDirty = true;
        $input = is_array($input) ? $input : array_merge($this->post(), [$input => $value]);
        if (isset($this->data)) {
            $this->data['post'] = $input;
        }
        return $this;
    }

    /**
     * Set header.
     */
    public function setHeader(array|string $input, mixed $value = null): static
    {
        $this->isDirty = true;
        $input = is_array($input) ? $input : array_merge($this->header(), [$input => $value]);
        if (isset($this->data)) {
            $this->data['headers'] = $input;
        }
        return $this;
    }

    /**
     * Destroy request data.
     */
    public function destroy(): void
    {
        if ($this->isDirty) {
            unset($this->data['get'], $this->data['post'], $this->data['headers']);
        }
    }
}
