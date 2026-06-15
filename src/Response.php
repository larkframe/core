<?php

namespace LarkFrame;

use LarkFrame\Consts;
use LarkFrame\Response\ResponseSenderInterface;
use LarkFrame\Response\ServerSender;
use LarkFrame\Response\WebSender;
use Stringable;
use Throwable;
use function array_merge_recursive;
use function explode;
use function file;
use function filemtime;
use function gmdate;
use function is_array;
use function is_file;
use function pathinfo;
use function preg_match;
use function rawurlencode;
use function strlen;
use function substr;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;

class Response implements Stringable
{
    /**
     * HTTP reason phrase.
     */
    protected ?string $reason = null;

    /**
     * HTTP version.
     */
    protected string $version = '1.1';

    /**
     * Send file info.
     */
    public ?array $file = null;

    /**
     * Stored exception.
     */
    protected ?Throwable $exception = null;

    /**
     * Response sender strategy.
     */
    protected static ?ResponseSenderInterface $sender = null;

    /**
     * MIME type map.
     */
    protected static array $mimeTypeMap = [
        'html' => 'text/html', 'htm' => 'text/html', 'shtml' => 'text/html',
        'css' => 'text/css', 'xml' => 'text/xml', 'gif' => 'image/gif',
        'jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'js' => 'application/javascript',
        'atom' => 'application/atom+xml', 'rss' => 'application/rss+xml',
        'wasm' => 'application/wasm', 'mml' => 'text/mathml', 'txt' => 'text/plain',
        'jad' => 'text/vnd.sun.j2me.app-descriptor', 'wml' => 'text/vnd.wap.wml',
        'htc' => 'text/x-component', 'png' => 'image/png', 'tif' => 'image/tiff',
        'tiff' => 'image/tiff', 'wbmp' => 'image/vnd.wap.wbmp', 'ico' => 'image/x-icon',
        'jng' => 'image/x-jng', 'bmp' => 'image/x-ms-bmp', 'svg' => 'image/svg+xml',
        'svgz' => 'image/svg+xml', 'webp' => 'image/webp', 'woff' => 'application/font-woff',
        'jar' => 'application/java-archive', 'war' => 'application/java-archive',
        'ear' => 'application/java-archive', 'json' => 'application/json',
        'hqx' => 'application/mac-binhex40', 'doc' => 'application/msword',
        'pdf' => 'application/pdf', 'ps' => 'application/postscript',
        'eps' => 'application/postscript', 'ai' => 'application/postscript',
        'rtf' => 'application/rtf', 'm3u8' => 'application/vnd.apple.mpegurl',
        'xls' => 'application/vnd.ms-excel', 'eot' => 'application/vnd.ms-fontobject',
        'ppt' => 'application/vnd.ms-powerpoint', 'wmlc' => 'application/vnd.wap.wmlc',
        'kml' => 'application/vnd.google-earth.kml+xml',
        'kmz' => 'application/vnd.google-earth.kmz', '7z' => 'application/x-7z-compressed',
        'cco' => 'application/x-cocoa', 'jardiff' => 'application/x-java-archive-diff',
        'jnlp' => 'application/x-java-jnlp-file', 'run' => 'application/x-makeself',
        'pl' => 'application/x-perl', 'pm' => 'application/x-perl',
        'prc' => 'application/x-pilot', 'pdb' => 'application/x-pilot',
        'rar' => 'application/x-rar-compressed',
        'rpm' => 'application/x-redhat-package-manager', 'sea' => 'application/x-sea',
        'swf' => 'application/x-shockwave-flash', 'sit' => 'application/x-stuffit',
        'tcl' => 'application/x-tcl', 'tk' => 'application/x-tcl',
        'der' => 'application/x-x509-ca-cert', 'pem' => 'application/x-x509-ca-cert',
        'crt' => 'application/x-x509-ca-cert', 'xpi' => 'application/x-xpinstall',
        'xhtml' => 'application/xhtml+xml', 'xspf' => 'application/xspf+xml',
        'zip' => 'application/zip', 'bin' => 'application/octet-stream',
        'exe' => 'application/octet-stream', 'dll' => 'application/octet-stream',
        'deb' => 'application/octet-stream', 'dmg' => 'application/octet-stream',
        'iso' => 'application/octet-stream', 'img' => 'application/octet-stream',
        'msi' => 'application/octet-stream', 'msp' => 'application/octet-stream',
        'msm' => 'application/octet-stream',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'mid' => 'audio/midi', 'midi' => 'audio/midi', 'kar' => 'audio/midi',
        'mp3' => 'audio/mpeg', 'ogg' => 'audio/ogg', 'm4a' => 'audio/x-m4a',
        'ra' => 'audio/x-realaudio', '3gpp' => 'video/3gpp', '3gp' => 'video/3gpp',
        'ts' => 'video/mp2t', 'mp4' => 'video/mp4', 'mpeg' => 'video/mpeg',
        'mpg' => 'video/mpeg', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'flv' => 'video/x-flv', 'm4v' => 'video/x-m4v', 'mng' => 'video/x-mng',
        'asx' => 'video/x-ms-asf', 'asf' => 'video/x-ms-asf', 'wmv' => 'video/x-ms-wmv',
        'avi' => 'video/x-msvideo', 'ttf' => 'font/ttf',
    ];

    /**
     * HTTP status phrases.
     */
    public const PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Payload Too Large',
        414 => 'URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /**
     * Get the response sender strategy.
     */
    public static function getSender(): ResponseSenderInterface
    {
        if (static::$sender === null) {
            static::$sender = match (RUN_TYPE) {
                Consts::RUN_TYPE_SERVER => new ServerSender(),
                default => new WebSender(),
            };
        }
        return static::$sender;
    }

    /**
     * Set a custom response sender (useful for testing).
     */
    public static function setSender(ResponseSenderInterface $sender): void
    {
        static::$sender = $sender;
    }

    /**
     * Constructor using PHP 8.1 constructor property promotion.
     */
    public function __construct(
        protected int $status = 200,
        protected array $headers = [],
        protected string $body = ''
    ) {
    }

    /**
     * Set a header.
     */
    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    /**
     * Set a header (alias).
     */
    public function withHeader(string $name, string $value): static
    {
        return $this->header($name, $value);
    }

    /**
     * Set multiple headers.
     */
    public function withHeaders(array $headers): static
    {
        $this->headers = array_merge_recursive($this->headers, $headers);
        return $this;
    }

    /**
     * Remove a header.
     */
    public function withoutHeader(string $name): static
    {
        unset($this->headers[$name]);
        return $this;
    }

    /**
     * Get a header value.
     */
    public function getHeader(string $name): array|string|null
    {
        return $this->headers[$name] ?? null;
    }

    /**
     * Get all headers.
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Set status code.
     */
    public function withStatus(int $code, ?string $reasonPhrase = null): static
    {
        $this->status = $code;
        $this->reason = $reasonPhrase;
        return $this;
    }

    /**
     * Get status code.
     */
    public function getStatusCode(): int
    {
        return $this->status;
    }

    /**
     * Get reason phrase.
     */
    public function getReasonPhrase(): ?string
    {
        return $this->reason;
    }

    /**
     * Set protocol version.
     */
    public function withProtocolVersion(string $version): static
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Set body.
     */
    public function withBody(string $body): static
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Get raw body.
     */
    public function rawBody(): string
    {
        return $this->body;
    }

    /**
     * Set file response.
     */
    public function withFile(string $file, int $offset = 0, int $length = 0): static
    {
        if (!is_file($file)) {
            return $this->withStatus(404)->withBody('<h3>404 Not Found</h3>');
        }
        $this->file = ['file' => $file, 'offset' => $offset, 'length' => $length];
        return $this;
    }

    /**
     * Alias for withFile().
     * Supports 304 Not Modified when running in server mode.
     */
    public function file(string $file, int $offset = 0, int $length = 0): static
    {
        if (defined('RUN_TYPE') && RUN_TYPE == Consts::RUN_TYPE_SERVER && $this->notModifiedSince($file)) {
            return $this->withStatus(304);
        }
        return $this->withFile($file, $offset, $length);
    }

    /**
     * Download file as attachment.
     */
    public function download(string $file, string $downloadName = ''): static
    {
        $this->withFile($file);
        if ($downloadName) {
            $this->header('Content-Disposition', "attachment; filename=\"$downloadName\"");
        }
        return $this;
    }

    /**
     * Check if file was not modified since client's cached version.
     */
    protected function notModifiedSince(string $file): bool
    {
        $ifModifiedSince = App::request()->header('if-modified-since');
        if ($ifModifiedSince === null || !is_file($file) || !($mtime = filemtime($file))) {
            return false;
        }
        return $ifModifiedSince === gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
    }

    /**
     * Store exception reference.
     */
    public function exception(Throwable $e): static
    {
        $this->exception = $e;
        return $this;
    }

    /**
     * Get stored exception.
     */
    public function getException(): ?Throwable
    {
        return $this->exception;
    }

    /**
     * Set cookie.
     */
    public function cookie(
        string $name,
        string $value = '',
        ?int $maxAge = null,
        string $path = '',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = false,
        string $sameSite = ''
    ): static {
        $sender = static::getSender();
        $cookieHeader = $sender->sendCookie($name, $value, $maxAge, $path, $domain, $secure, $httpOnly, $sameSite);

        if ($cookieHeader !== null) {
            $this->headers['Set-Cookie'][] = $cookieHeader;
        }

        return $this;
    }

    /**
     * Create header string for file response.
     */
    protected function createHeadForFile(array $fileInfo): string
    {
        $headers = $this->headers;
        $headers['Server'] ??= 'lark-server';
        $headers['Connection'] ??= 'keep-alive';

        $file = $fileInfo['file'];
        $fileInfo = pathinfo($file);
        $extension = $fileInfo['extension'] ?? '';
        $baseName = $fileInfo['basename'] ?: 'unknown';

        if (!isset($headers['Content-Type'])) {
            $headers['Content-Type'] = self::$mimeTypeMap[$extension] ?? 'application/octet-stream';
        }

        if (!isset($headers['Content-Disposition']) && !isset(self::$mimeTypeMap[$extension])) {
            $headers['Content-Disposition'] = "attachment; filename=\"$baseName\"";
        }

        if (!isset($headers['Last-Modified']) && $mtime = filemtime($file)) {
            $headers['Last-Modified'] = gmdate('D, d M Y H:i:s', $mtime) . ' GMT';
        }

        return static::getSender()->formatFileResponse($this->status, $this->version, $this->reason, $headers);
    }

    /**
     * Convert to string.
     * This method must NOT throw exceptions (PHP limitation).
     * Avoids calling getSender() to prevent RUN_TYPE dependency issues.
     */
    public function __toString(): string
    {
        if ($this->file) {
            return $this->createHeadForFile($this->file);
        }

        $bodyLen = strlen($this->body);
        if (empty($this->headers)) {
            $headers = [
                'Server' => 'lark-server',
                'Content-Type' => ' text/html;charset=utf-8',
                'Content-Length' => $bodyLen,
                'Connection' => 'keep-alive',
            ];
        } else {
            $headers = $this->headers;
        }

        $headers['Server'] ??= 'lark-server';
        $headers['Connection'] ??= 'keep-alive';
        $headers['Content-Type'] ??= 'text/html;charset=utf-8';
        $headers['Content-Length'] ??= (string)$bodyLen;

        // Build response string directly without relying on sender
        $phrase = $this->reason ?? (self::PHRASES[$this->status] ?? '');
        $head = "HTTP/{$this->version} {$this->status} {$phrase}\r\n";
        foreach ($headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $head .= "$name: $v\r\n";
                }
            } else {
                $head .= "$name: $value\r\n";
            }
        }
        $head .= "\r\n";

        return $head . $this->body;
    }

}
