<?php

namespace LarkFrame\Connection;

use LarkFrame\Events\EventInterface;
use RuntimeException;
use stdClass;
use Throwable;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_resource;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_socket_get_name;
use function stream_socket_shutdown;
use function strlen;
use function strrpos;
use function substr;
use const PHP_INT_MAX;
use const STREAM_SHUT_WR;

/**
 * Class TcpConnection
 *
 * TCP connection implementation for the server mode.
 * Optimized for PHP 8.1 with enum-based status, readonly properties,
 * first-class callable syntax, and match expressions.
 */
class TcpConnection
{
    /**
     * Connection status enum for type-safe status handling.
     */
    public const STATUS_INITIAL = 0;
    public const STATUS_CONNECTING = 1;
    public const STATUS_ESTABLISHED = 2;
    public const STATUS_ENDING = 4;
    public const STATUS_CLOSING = 8;
    public const STATUS_CLOSED = 16;

    /**
     * Read buffer size.
     */
    public const READ_BUFFER_SIZE = 87380;

    /**
     * Maximum string length for cache.
     */
    public const MAX_CACHE_STRING_LENGTH = 2048;

    /**
     * Maximum cache size.
     */
    public const MAX_CACHE_SIZE = 512;

    /**
     * Send failed constant.
     */
    public const SEND_FAIL = 2;

    /**
     * Connection statistics.
     */
    public static array $statistics = [
        'connection_count' => 0,
        'total_request' => 0,
        'throw_exception' => 0,
        'send_fail' => 0,
    ];

    /**
     * Application layer protocol class name.
     */
    public ?string $protocol = null;

    /**
     * Emitted when data is received.
     */
    public \Closure|null $onMessage = null;

    /**
     * Emitted when connection is closed.
     */
    public \Closure|null $onClose = null;

    /**
     * Emitted when an error occurs.
     */
    public \Closure|null $onError = null;

    /**
     * Emitted when send buffer becomes full.
     */
    public \Closure|null $onBufferFull = null;

    /**
     * Emitted when send buffer becomes empty.
     */
    public \Closure|null $onBufferDrain = null;

    /**
     * Event loop instance.
     */
    public ?EventInterface $eventLoop = null;

    /**
     * Bytes read.
     */
    public int $bytesRead = 0;

    /**
     * Bytes written.
     */
    public int $bytesWritten = 0;

    /**
     * Connection ID.
     */
    public readonly int $id;

    /**
     * Maximum send buffer size.
     */
    public int $maxSendBufferSize = 1048576;

    /**
     * Context data.
     */
    public ?stdClass $context = null;

    /**
     * Response headers (deprecated, kept for compatibility).
     */
    public array $headers = [];

    /**
     * Default max send buffer size.
     */
    public static int $defaultMaxSendBufferSize = 1048576;

    /**
     * Maximum acceptable packet size.
     */
    public int $maxPackageSize = 10485760;

    /**
     * Read timeout in seconds. 0 means no timeout.
     * Prevents Slowloris attacks by closing connections that take too long
     * to send a complete request.
     */
    public static int $readTimeout = 0;

    /**
     * Timer ID for the read timeout watcher.
     */
    protected ?int $readTimeoutTimerId = null;

    /**
     * Default maximum acceptable packet size.
     */
    public static int $defaultMaxPackageSize = 10485760;

    /**
     * Initialize defaultMaxPackageSize from config if available.
     */
    public static function initFromConfig(): void
    {
        $configValue = \LarkFrame\Config::get('server.max_package_size');
        if (is_int($configValue) && $configValue > 0) {
            self::$defaultMaxPackageSize = $configValue;
        }

        $readTimeout = \LarkFrame\Config::get('server.read_timeout');
        if (is_int($readTimeout) && $readTimeout > 0) {
            self::$readTimeout = $readTimeout;
        }
    }

    /**
     * ID recorder.
     */
    protected static int $idRecorder = 1;

    /**
     * Socket resource.
     *
     * @var resource|null
     */
    protected $socket = null;

    /**
     * Send buffer.
     */
    protected string $sendBuffer = '';

    /**
     * Receive buffer.
     */
    protected string $recvBuffer = '';

    /**
     * Current package length.
     */
    protected int $currentPackageLength = 0;

    /**
     * Connection status.
     */
    protected int $status = self::STATUS_ESTABLISHED;

    /**
     * Remote address.
     */
    protected readonly string $remoteAddress;

    /**
     * Is paused.
     */
    protected bool $isPaused = false;

    /**
     * All connection instances.
     *
     * @var TcpConnection[]
     */
    public static array $connections = [];

    /**
     * Constructor.
     */
    public function __construct(
        EventInterface $eventLoop,
        $socket,
        string $remoteAddress = ''
    ) {
        ++self::$statistics['connection_count'];
        $this->id = self::$idRecorder++;
        if (self::$idRecorder === PHP_INT_MAX) {
            self::$idRecorder = 1;
        }
        // Skip IDs that are still in use by existing connections
        while (isset(self::$connections[$this->id])) {
            $this->id = self::$idRecorder++;
            if (self::$idRecorder === PHP_INT_MAX) {
                self::$idRecorder = 1;
            }
        }

        $this->socket = $socket;
        $this->remoteAddress = $remoteAddress;
        $this->eventLoop = $eventLoop;
        $this->context = new stdClass();

        stream_set_blocking($this->socket, false);
        stream_set_read_buffer($this->socket, 0);

        $this->eventLoop->onReadable($this->socket, $this->baseRead(...));
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;

        static::$connections[$this->id] = $this;

        // Start read timeout timer if configured
        $this->startReadTimeout();
    }

    /**
     * Get connection status.
     */
    public function getStatus(bool $rawOutput = true): int|string
    {
        if ($rawOutput) {
            return $this->status;
        }

        return match ($this->status) {
            self::STATUS_INITIAL => 'INITIAL',
            self::STATUS_CONNECTING => 'CONNECTING',
            self::STATUS_ESTABLISHED => 'ESTABLISHED',
            self::STATUS_CLOSING => 'CLOSING',
            self::STATUS_ENDING => 'ENDING',
            self::STATUS_CLOSED => 'CLOSED',
            default => 'UNKNOWN',
        };
    }

    /**
     * Send data on the connection.
     */
    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        if ($this->status === self::STATUS_ENDING || $this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            return false;
        }

        // Try to call protocol::encode() before sending.
        if (false === $raw && $this->protocol !== null) {
            try {
                $sendBuffer = $this->protocol::encode($sendBuffer, $this);
            } catch (Throwable $e) {
                $this->error($e);
            }
            if ($sendBuffer === '') {
                return null;
            }
        }

        if ($this->status !== self::STATUS_ESTABLISHED) {
            if ($this->sendBuffer !== '' && $this->bufferIsFull()) {
                ++self::$statistics['send_fail'];
                return false;
            }
            $this->sendBuffer .= $sendBuffer;
            $this->checkBufferWillFull();
            return null;
        }

        // Attempt to send data directly.
        if ($this->sendBuffer === '') {
            $len = 0;
            try {
                $len = @fwrite($this->socket, $sendBuffer);
            } catch (Throwable $e) {
                $this->error($e);
            }

            // Send successful.
            if ($len === strlen($sendBuffer)) {
                $this->bytesWritten += $len;
                return true;
            }

            // Send only part of the data.
            if ($len > 0) {
                $this->sendBuffer = substr($sendBuffer, $len);
                $this->bytesWritten += $len;
            } else {
                if (!is_resource($this->socket) || feof($this->socket)) {
                    ++self::$statistics['send_fail'];
                    $this->destroy();
                    return false;
                }
                $this->sendBuffer = $sendBuffer;
            }

            $this->eventLoop->onWritable($this->socket, $this->baseWrite(...));
            $this->checkBufferWillFull();
            return null;
        }

        if ($this->bufferIsFull()) {
            ++self::$statistics['send_fail'];
            return false;
        }

        $this->sendBuffer .= $sendBuffer;
        $this->checkBufferWillFull();
        return null;
    }

    /**
     * Get remote IP.
     */
    public function getRemoteIp(): string
    {
        $address = $this->remoteAddress;
        // IPv6 in brackets: [::1]:8080
        if (str_starts_with($address, '[')) {
            $closeBracket = strpos($address, ']');
            if ($closeBracket !== false) {
                return substr($address, 1, $closeBracket - 1);
            }
        }
        // IPv4: 192.168.1.1:8080
        $pos = strrpos($address, ':');
        return $pos !== false ? substr($address, 0, $pos) : $address;
    }

    /**
     * Get remote port.
     */
    public function getRemotePort(): int
    {
        $address = $this->remoteAddress;
        if ($address === '') {
            return 0;
        }
        // IPv6 in brackets: [::1]:8080
        if (str_starts_with($address, '[')) {
            $closeBracket = strpos($address, ']');
            if ($closeBracket !== false) {
                $portStr = substr($address, $closeBracket + 2); // skip ']:'
                return (int)$portStr;
            }
        }
        // IPv4: 192.168.1.1:8080
        return (int)substr(strrchr($address, ':'), 1);
    }

    /**
     * Get remote address.
     */
    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    /**
     * Get local IP.
     */
    public function getLocalIp(): string
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return substr($address, 0, $pos);
    }

    /**
     * Get local port.
     */
    public function getLocalPort(): int
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)substr(strrchr($address, ':'), 1);
    }

    /**
     * Get local address.
     */
    public function getLocalAddress(): string
    {
        if (!is_resource($this->socket)) {
            return '';
        }
        return (string)@stream_socket_get_name($this->socket, false);
    }

    /**
     * Pause receiving data.
     */
    public function pauseRecv(): void
    {
        $this->eventLoop?->offReadable($this->socket);
        $this->isPaused = true;
    }

    /**
     * Resume receiving data.
     */
    public function resumeRecv(): void
    {
        if ($this->isPaused === true) {
            $this->eventLoop?->onReadable($this->socket, $this->baseRead(...));
            $this->isPaused = false;
            $this->baseRead($this->socket, false);
        }
    }

    /**
     * Base read handler.
     */
    public function baseRead($socket, bool $checkEof = true): void
    {
        $buffer = '';
        try {
            $buffer = @fread($socket, self::READ_BUFFER_SIZE);
        } catch (Throwable $e) {
            $this->error($e);
        }

        // Check connection closed.
        // fread on non-blocking socket may return '' without EOF (just no data available yet).
        // Only treat as disconnection when feof() returns true or fread returns false.
        if ($buffer === '' || $buffer === false) {
            if ($checkEof && ($buffer === false || !is_resource($socket) || feof($socket))) {
                $this->destroy();
                return;
            }
            // Empty string on non-blocking socket with no EOF: just no data, not a disconnect
            return;
        } else {
            $this->bytesRead += strlen($buffer);
            if ($this->status === self::STATUS_ENDING) {
                return;
            }
            $this->recvBuffer .= $buffer;
        }

        // If the application layer protocol has been set up.
        if ($this->protocol !== null) {
            $this->processProtocolData();
        } else {
            // No protocol, call onMessage directly.
            if ($this->recvBuffer !== '' && $this->onMessage !== null) {
                ++self::$statistics['total_request'];
                try {
                    ($this->onMessage)($this, $this->recvBuffer);
                } catch (Throwable $e) {
                    $this->error($e);
                }
                $this->recvBuffer = '';
            }
        }
    }

    /**
     * Process protocol data from the receive buffer.
     */
    private function processProtocolData(): void
    {
        while ($this->recvBuffer !== '' && !$this->isPaused) {
            // The current packet length is known.
            if ($this->currentPackageLength > 0) {
                if ($this->currentPackageLength > strlen($this->recvBuffer)) {
                    break;
                }
            } else {
                // Get current package length.
                try {
                    $this->currentPackageLength = $this->protocol::input($this->recvBuffer, $this);
                } catch (Throwable) {
                    $this->currentPackageLength = -1;
                }

                if ($this->currentPackageLength === 0) {
                    break;
                }

                if ($this->currentPackageLength > 0 && $this->currentPackageLength <= $this->maxPackageSize) {
                    if ($this->currentPackageLength > strlen($this->recvBuffer)) {
                        break;
                    }
                } else {
                    $this->destroy();
                    return;
                }
            }

            // The data is enough for a packet.
            ++self::$statistics['total_request'];
            $recvBufferLength = strlen($this->recvBuffer);

            $oneRequestBuffer = $recvBufferLength === $this->currentPackageLength
                ? $this->recvBuffer
                : substr($this->recvBuffer, 0, $this->currentPackageLength);

            if ($recvBufferLength > $this->currentPackageLength) {
                $this->recvBuffer = substr($this->recvBuffer, $this->currentPackageLength);
            } else {
                $this->recvBuffer = '';
            }

            // Reset current package length.
            $this->currentPackageLength = 0;

            // Decode and call onMessage.
            try {
                // Reset read timeout after receiving a complete request
                $this->resetReadTimeout();
                $request = $this->protocol::decode($oneRequestBuffer, $this);
                $this->onMessage?->__invoke($this, $request);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * Base write handler.
     */
    public function baseWrite($socket): void
    {
        $len = 0;
        try {
            $len = @fwrite($socket, $this->sendBuffer);
        } catch (Throwable $e) {
            $this->error($e);
        }

        if ($len === strlen($this->sendBuffer)) {
            $this->bytesWritten += $len;
            $this->sendBuffer = '';
            $this->eventLoop?->offWritable($socket);

            // Buffer drain callback.
            $this->onBufferDrain?->__invoke($this);

            if ($this->status === self::STATUS_ENDING) {
                $this->destroy();
            }
            return;
        }

        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->sendBuffer = substr($this->sendBuffer, $len);
        }

        $this->checkBufferWillFull();
    }

    /**
     * Close connection gracefully (send remaining data first).
     */
    public function close(mixed $data = null, bool $raw = false): void
    {
        if ($this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            return;
        }

        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->status = self::STATUS_CLOSING;

        if ($this->sendBuffer === '') {
            $this->destroy();
        }
    }

    /**
     * End connection (graceful close).
     */
    public function end(mixed $data = null, bool $raw = false): void
    {
        if ($this->status === self::STATUS_ENDING || $this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            return;
        }

        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->status = self::STATUS_ENDING;

        if ($this->sendBuffer === '') {
            $this->destroy();
        }
    }

    /**
     * Destroy the connection.
     */
    public function destroy(): void
    {
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }

        // Cancel read timeout timer
        $this->cancelReadTimeout();

        // Remove from event loop before closing socket.
        $socket = $this->socket;
        if ($socket) {
            $this->eventLoop?->offReadable($socket);
            $this->eventLoop?->offWritable($socket);
        }

        // Close socket.
        if (is_resource($socket)) {
            try {
                @stream_socket_shutdown($socket, STREAM_SHUT_WR);
            } catch (Throwable $e) {
                $this->error($e);
            }
            fclose($socket);
        }
        $this->socket = null;

        $this->eventLoop = null;

        $this->status = self::STATUS_CLOSED;
        $this->recvBuffer = '';
        $this->sendBuffer = '';

        unset(self::$connections[$this->id]);

        // onClose callback.
        $this->onClose?->__invoke($this);

        $this->onMessage = null;
        $this->onClose = null;
        $this->onError = null;
        $this->onBufferFull = null;
        $this->onBufferDrain = null;
    }

    /**
     * Check if send buffer is full.
     */
    protected function bufferIsFull(): bool
    {
        return strlen($this->sendBuffer) >= $this->maxSendBufferSize;
    }

    /**
     * Check if buffer will be full and trigger onBufferFull callback.
     */
    protected function checkBufferWillFull(): void
    {
        if ($this->bufferIsFull() && $this->onBufferFull !== null) {
            try {
                ($this->onBufferFull)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * Handle error.
     */
    public function error(Throwable $exception): void
    {
        ++self::$statistics['throw_exception'];
        if ($this->onError !== null) {
            try {
                ($this->onError)($this, self::SEND_FAIL, $exception->getMessage());
            } catch (Throwable $e) {
                // Prevent infinite recursion in error handler
            }
        }
    }

    /**
     * Is IPv4.
     */
    public function isIpV4(): bool
    {
        return !str_contains($this->getRemoteIp(), ':');
    }

    /**
     * Is IPv6.
     */
    public function isIpV6(): bool
    {
        return str_contains($this->getRemoteIp(), ':');
    }

    /**
     * Start the read timeout timer.
     * If readTimeout is 0 (disabled), does nothing.
     */
    protected function startReadTimeout(): void
    {
        if (self::$readTimeout <= 0 || $this->eventLoop === null) {
            return;
        }

        $this->cancelReadTimeout();
        $this->readTimeoutTimerId = $this->eventLoop->delay(
            (float)self::$readTimeout,
            function (): void {
                if ($this->status !== self::STATUS_CLOSED) {
                    $this->close();
                }
            }
        );
    }

    /**
     * Reset the read timeout timer (called after receiving a complete request).
     */
    protected function resetReadTimeout(): void
    {
        if (self::$readTimeout > 0) {
            $this->startReadTimeout();
        }
    }

    /**
     * Cancel the read timeout timer.
     */
    protected function cancelReadTimeout(): void
    {
        if ($this->readTimeoutTimerId !== null) {
            $this->eventLoop?->offDelay($this->readTimeoutTimerId);
            $this->readTimeoutTimerId = null;
        }
    }
}
