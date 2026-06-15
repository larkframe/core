<?php

namespace LarkFrame;

use Exception;
use LarkFrame\Connection\TcpConnection;
use LarkFrame\Events\EventInterface;
use LarkFrame\Events\Select;
use RuntimeException;
use stdClass;
use Throwable;
use function chmod;
use function clearstatcache;
use function closedir;
use function count;
use function dirname;
use function fclose;
use function file_put_contents;
use function fopen;
use function function_exists;
use function getmypid;
use function in_array;
use function is_dir;
use function is_file;
use function is_resource;
use function mkdir;
use function pcntl_alarm;
use function pcntl_fork;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use function pcntl_wait;
use function posix_getpid;
use function posix_kill;
use function posix_setsid;
use function readdir;
use function rmdir;
use function set_error_handler;
use function stream_context_create;
use function stream_socket_accept;
use function stream_socket_server;
use function strlen;
use function strpos;
use function substr;
use function touch;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_UN;
use const PHP_SAPI;
use const SIGINT;
use const SIGTERM;
use const SIGUSR1;
use const WNOHANG;

/**
 * Class Worker
 *
 * Server process manager that handles forking, socket listening, and worker lifecycle.
 * Optimized for PHP 8.1 with readonly properties, match expressions, union types,
 * first-class callable syntax, and named arguments.
 */
class Worker
{
    /**
     * Version.
     */
    final public const VERSION = '1.0.0';

    /**
     * Status constants.
     */
    public const STATUS_INITIAL = 0;
    public const STATUS_STARTING = 1;
    public const STATUS_RUNNING = 2;
    public const STATUS_SHUTDOWN = 4;
    public const STATUS_RELOADING = 8;

    /**
     * Default backlog.
     */
    public const DEFAULT_BACKLOG = 102400;

    /**
     * Worker id.
     */
    public int $id = 0;

    /**
     * Name of the worker processes.
     */
    public string $name = 'none';

    /**
     * Number of worker processes.
     */
    public int $count = 1;

    /**
     * Unix user of processes.
     */
    public string $user = '';

    /**
     * Unix group of processes.
     */
    public string $group = '';

    /**
     * reloadable.
     */
    public bool $reloadable = true;

    /**
     * reuse port.
     */
    public bool $reusePort = false;

    /**
     * Emitted when worker processes is starting.
     */
    public \Closure|null $onWorkerStart = null;

    /**
     * Emitted when a socket connection is successfully established.
     */
    public \Closure|null $onConnect = null;

    /**
     * Emitted when data is received.
     */
    public \Closure|null $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     */
    public \Closure|null $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     */
    public \Closure|null $onError = null;

    /**
     * Emitted when worker processes has stopped.
     */
    public \Closure|null $onWorkerStop = null;

    /**
     * Transport layer protocol.
     */
    public string $transport = 'tcp';

    /**
     * Store all connections of clients.
     *
     * @var TcpConnection[]
     */
    public array $connections = [];

    /**
     * Application layer protocol.
     */
    public ?string $protocol = null;

    /**
     * Is worker stopping.
     */
    public bool $stopping = false;

    /**
     * EventLoop class.
     */
    public ?string $eventLoop = null;

    /**
     * Daemonize.
     */
    public static bool $daemonize = false;

    /**
     * Standard output stream.
     *
     * @var resource
     */
    public static $outputStream;

    /**
     * Stdout file.
     */
    public static string $stdoutFile = '/dev/null';

    /**
     * The file to store master process PID.
     */
    public static string $pidFile = '';

    /**
     * Log file.
     */
    public static string $logFile = '';

    /**
     * Log file maximum size in bytes.
     */
    public static int $logFileMaxSize = 10_485_760;

    /**
     * Global event loop.
     */
    public static ?EventInterface $globalEvent = null;

    /**
     * Emitted when the master process gets a reload signal.
     */
    public static \Closure|null $onMasterReload = null;

    /**
     * Emitted when the master process terminated.
     */
    public static \Closure|null $onMasterStop = null;

    /**
     * EventLoopClass
     *
     * @var class-string<EventInterface>|null
     */
    public static ?string $eventLoopClass = null;

    /**
     * After sending the stop command to the child process stopTimeout seconds,
     * if the process is still living then forced to kill.
     */
    public static int $stopTimeout = 2;

    /**
     * Command.
     */
    public static string $command = '';

    /**
     * The PID of master process.
     */
    protected static int $masterPid = 0;

    /**
     * Listening socket.
     *
     * @var resource|null
     */
    protected $mainSocket = null;

    /**
     * Socket name.
     */
    protected string $socketName = '';

    /**
     * Context of socket.
     *
     * @var resource|null
     */
    protected $socketContext = null;

    /**
     * Context object.
     */
    protected stdClass $context;

    /**
     * All worker instances.
     *
     * @var Worker[]
     */
    protected static array $workers = [];

    /**
     * All worker processes pid.
     */
    protected static array $pidMap = [];

    /**
     * Current status.
     */
    protected static int $status = self::STATUS_INITIAL;

    /**
     * Start file.
     */
    protected static string $startFile = '';

    /**
     * Worker object's hash id.
     */
    protected readonly string $workerId;

    /**
     * Constructor.
     */
    public function __construct(?string $socketName = null, array $socketContext = [])
    {
        $this->workerId = spl_object_hash($this);
        $this->context = new stdClass();
        static::$workers[$this->workerId] = $this;
        static::$pidMap[$this->workerId] = [];

        if ($socketName) {
            $this->socketName = $socketName;
            $socketContext['socket']['backlog'] ??= static::DEFAULT_BACKLOG;
            $this->socketContext = stream_context_create($socketContext);
        }

        $this->onMessage = static function (): void {
            // Empty.
        };
    }

    /**
     * Run all worker instances.
     */
    public static function runAll(): void
    {
        try {
            static::checkSapiEnv();
            static::init();
            static::parseCommand();

            match (static::$command) {
                'start' => static::handleStart(),
                'stop' => static::handleStop(),
                'restart' => static::handleRestart(),
                'reload' => static::handleReload(),
                'status' => static::handleStatus(),
                default => static::handleStart(),
            };
        } catch (Throwable $e) {
            static::log($e);
        }
    }

    /**
     * Handle start command.
     */
    protected static function handleStart(): void
    {
        // Check if already running
        if (static::getMasterPid() > 0 && @posix_kill(static::getMasterPid(), 0)) {
            static::safeEcho("Server already running (PID: " . static::getMasterPid() . ")");
            exit(1);
        }

        static::safeEcho("LarkFrame server starting...");
        $firstWorker = reset(static::$workers);
        if ($firstWorker) {
            static::safeEcho("Listen: {$firstWorker->socketName}");
            static::safeEcho("Worker count: {$firstWorker->count}");
        }
        static::safeEcho("Press Ctrl+C to stop." . PHP_EOL);

        static::daemonize();
        static::initWorkers();
        static::installSignal();
        static::saveMasterPid();
        static::forkWorkers();
        static::resetStd();
        static::monitorWorkers();
    }

    /**
     * Handle stop command.
     */
    protected static function handleStop(): void
    {
        $masterPid = static::getMasterPid();
        if ($masterPid <= 0 || !@posix_kill($masterPid, 0)) {
            static::safeEcho("Server is not running");
            exit(0);
        }

        static::safeEcho("Stopping server (PID: {$masterPid})...");
        posix_kill($masterPid, SIGINT);

        // Wait for process to exit
        $timeout = 10;
        while ($timeout > 0 && @posix_kill($masterPid, 0)) {
            usleep(200000);
            $timeout -= 0.2;
        }

        if (@posix_kill($masterPid, 0)) {
            static::safeEcho("Force killing server...");
            posix_kill($masterPid, SIGKILL);
        }

        @unlink(static::$pidFile);
        static::safeEcho("Server stopped");
    }

    /**
     * Handle restart command.
     */
    protected static function handleRestart(): void
    {
        $masterPid = static::getMasterPid();
        if ($masterPid > 0 && @posix_kill($masterPid, 0)) {
            static::safeEcho("Stopping server (PID: {$masterPid})...");
            posix_kill($masterPid, SIGINT);

            $timeout = 10;
            while ($timeout > 0 && @posix_kill($masterPid, 0)) {
                usleep(200000);
                $timeout -= 0.2;
            }
            if (@posix_kill($masterPid, 0)) {
                posix_kill($masterPid, SIGKILL);
            }
            @unlink(static::$pidFile);
            static::safeEcho("Server stopped");
        } else {
            static::safeEcho("Server is not running");
        }

        static::safeEcho("Starting server...");
        $firstWorker = reset(static::$workers);
        if ($firstWorker) {
            static::safeEcho("Listen: {$firstWorker->socketName}");
            static::safeEcho("Worker count: {$firstWorker->count}");
        }
        static::safeEcho("Press Ctrl+C to stop." . PHP_EOL);
        static::daemonize();
        static::initWorkers();
        static::installSignal();
        static::saveMasterPid();
        static::forkWorkers();
        static::resetStd();
        static::monitorWorkers();
    }

    /**
     * Handle reload command.
     */
    protected static function handleReload(): void
    {
        $masterPid = static::getMasterPid();
        if ($masterPid <= 0 || !@posix_kill($masterPid, 0)) {
            static::safeEcho("Server is not running");
            exit(1);
        }

        posix_kill($masterPid, SIGUSR1);
        static::safeEcho("Reload signal sent to server (PID: {$masterPid})");
    }

    /**
     * Handle status command.
     */
    protected static function handleStatus(): void
    {
        $masterPid = static::getMasterPid();
        if ($masterPid <= 0 || !@posix_kill($masterPid, 0)) {
            static::safeEcho("Server is not running");
        } else {
            static::safeEcho("Server is running (PID: {$masterPid})");
        }
    }

    /**
     * Get master PID from pid file.
     */
    protected static function getMasterPid(): int
    {
        if (static::$pidFile && is_file(static::$pidFile)) {
            return (int)file_get_contents(static::$pidFile);
        }
        return 0;
    }

    /**
     * Check sapi environment.
     */
    protected static function checkSapiEnv(): void
    {
        if (!in_array(PHP_SAPI, ['cli', 'micro'], true)) {
            exit("Only run in command line mode" . PHP_EOL);
        }
        if (DIRECTORY_SEPARATOR === '/') {
            foreach (['pcntl', 'posix'] as $name) {
                if (!extension_loaded($name)) {
                    exit("Please install $name extension" . PHP_EOL);
                }
            }
        }
    }

    /**
     * Init.
     */
    protected static function init(): void
    {
        set_error_handler(static function (int $code, string $msg, string $file, int $line): bool {
            static::safeEcho(sprintf("%s \"%s\" in file %s on line %d\n", 'WARNING', $msg, $file, $line));
            return true;
        });

        $_SERVER['SERVER_SOFTWARE'] = 'Lark/' . static::VERSION;
        $_SERVER['SERVER_START_TIME'] = time();

        // Start file.
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        static::$startFile = static::$startFile ?: end($backtrace)['file'];
        $startFilePrefix = basename(static::$startFile);
        $startFileDir = dirname(static::$startFile);

        // Pid file.
        static::$pidFile = static::$pidFile ?: sprintf('%s/runtime/lark.%s.pid', $startFileDir, $startFilePrefix);

        // Log file.
        static::$logFile = static::$logFile ?: sprintf('%s/runtime/lark.log', $startFileDir);

        if (static::$logFile !== '/dev/null' && !is_file(static::$logFile) && !str_contains(static::$logFile, '://')) {
            if (!is_dir(dirname(static::$logFile))) {
                mkdir(dirname(static::$logFile), 0777, true);
            }
            touch(static::$logFile);
            chmod(static::$logFile, 0644);
        }

        static::$status = static::STATUS_STARTING;
        static::initGlobalEvent();

        // Process title.
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title('Lark: master process  start_file=' . static::$startFile);
        }

        restore_error_handler();
    }

    /**
     * Init global event.
     * Auto-detects the best available event loop extension.
     */
    protected static function initGlobalEvent(): void
    {
        if (static::$globalEvent !== null) {
            static::$eventLoopClass = get_class(static::$globalEvent);
            static::$globalEvent = null;
            return;
        }

        if (!empty(static::$eventLoopClass)) {
            return;
        }

        // Auto-detect: prefer ext-event (epoll/kqueue) over select
        if (extension_loaded('event')) {
            static::$eventLoopClass = Events\Event::class;
        } else {
            static::$eventLoopClass = Select::class;
        }
    }

    /**
     * Parse command.
     */
    protected static function parseCommand(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        global $argv;
        $command = $argv[1] ?? '';
        $validCommands = ['start', 'stop', 'restart', 'reload', 'status'];

        if ($command === '' || !in_array($command, $validCommands, true)) {
            static::showUsage();
            exit(0);
        }

        static::$command = $command;
    }

    /**
     * Show usage information.
     */
    protected static function showUsage(): void
    {
        static::safeEcho("Usage: php " . basename(static::$startFile) . " {start|stop|restart|reload|status}");
        static::safeEcho("");
        static::safeEcho("Commands:");
        static::safeEcho("  start    Start the server (foreground)");
        static::safeEcho("  stop     Stop the server");
        static::safeEcho("  restart  Restart the server");
        static::safeEcho("  reload   Reload worker processes");
        static::safeEcho("  status   Show server status");
    }

    /**
     * Daemonize.
     */
    protected static function daemonize(): void
    {
        if (!static::$daemonize || DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork() failed');
        }
        if ($pid > 0) {
            exit(0);
        }
        if (posix_setsid() === -1) {
            throw new RuntimeException('posix_setsid() failed');
        }
        // Fork again to avoid acquiring a controlling terminal.
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork() failed');
        }
        if ($pid > 0) {
            exit(0);
        }
        umask(0);
    }

    /**
     * Init all worker instances.
     */
    protected static function initWorkers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        foreach (static::$workers as $worker) {
            if (empty($worker->name)) {
                $worker->name = 'none';
            }
            $worker->setProtocol();
        }
    }

    /**
     * Set protocol for this worker based on socket name.
     */
    protected function setProtocol(): void
    {
        if ($this->socketName && !str_contains($this->socketName, '://')) {
            $this->socketName = 'tcp://' . $this->socketName;
        }
        // Default to HTTP protocol.
        $this->protocol = Protocols\Http::class;
    }

    /**
     * Install signal handlers.
     */
    protected static function installSignal(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        pcntl_signal(SIGINT, static::signalHandler(...));
        pcntl_signal(SIGTERM, static::signalHandler(...));
        pcntl_signal(SIGUSR1, static::signalHandler(...));
    }

    /**
     * Signal handler.
     */
    public static function signalHandler(int $signal): void
    {
        match ($signal) {
            SIGINT, SIGTERM => static::stopAll(),
            SIGUSR1 => static::reloadWorkers(),
            default => null,
        };
    }

    /**
     * Save master PID.
     */
    protected static function saveMasterPid(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        static::$masterPid = posix_getpid();
        if (static::$pidFile) {
            $dir = dirname(static::$pidFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents(static::$pidFile, static::$masterPid);
        }
    }

    /**
     * Fork worker processes.
     */
    protected static function forkWorkers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            // Windows: run in main process.
            foreach (static::$workers as $worker) {
                $worker->run();
            }
            return;
        }

        foreach (static::$workers as $worker) {
            while (count(static::$pidMap[$worker->workerId]) < $worker->count) {
                static::forkOneWorker($worker);
            }
        }
    }

    /**
     * Fork one worker.
     */
    protected static function forkOneWorker(self $worker): void
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException("pcntl_fork() failed");
        }
        if ($pid > 0) {
            // Master process.
            static::$pidMap[$worker->workerId][$pid] = $pid;
            return;
        }
        // Worker process.
        $worker->run();
        exit(0);
    }

    /**
     * Reset std.
     */
    protected static function resetStd(): void
    {
        if (!static::$daemonize || DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        global $STDIN, $STDOUT, $STDERR;
        $STDOUT = fopen(static::$stdoutFile, 'a');
        $STDERR = fopen(static::$stdoutFile, 'a');
    }

    /**
     * Monitor worker processes.
     */
    protected static function monitorWorkers(): void
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        static::$status = static::STATUS_RUNNING;

        // Set a periodic alarm to interrupt pcntl_wait so the master process
        // can check for shutdown signals even if no child exits.
        pcntl_signal(SIGALRM, function (): void {
            // No-op: just interrupts pcntl_wait for signal dispatch
        });
        pcntl_alarm(5);

        while (true) {
            pcntl_signal_dispatch();
            $status = 0;
            // Block until a child exits or interrupted by signal (e.g., SIGALRM, SIGTERM)
            $pid = pcntl_wait($status);
            pcntl_signal_dispatch();

            // Re-arm the alarm
            pcntl_alarm(5);

            if ($pid > 0) {
                // A child process exited.
                foreach (static::$workers as $worker) {
                    if (isset(static::$pidMap[$worker->workerId][$pid])) {
                        unset(static::$pidMap[$worker->workerId][$pid]);
                        if (static::$status !== static::STATUS_SHUTDOWN) {
                            // Restart the worker.
                            static::forkOneWorker($worker);
                        }
                        break;
                    }
                }
            }

            if (static::$status === static::STATUS_SHUTDOWN && empty(array_filter(static::$pidMap))) {
                // All workers exited.
                static::exitAndCleanUp();
                return;
            }
        }
    }

    /**
     * Run worker.
     */
    public function run(): void
    {
        // Create event loop.
        $eventLoopClass = static::$eventLoopClass ?? Select::class;
        static::$globalEvent = new $eventLoopClass();

        // Set error handler.
        static::$globalEvent->setErrorHandler(static::log(...));

        // Initialize configurable defaults from config.
        TcpConnection::initFromConfig();

        // Listen.
        $this->listen();

        // Set process title.
        if (function_exists('cli_set_process_title')) {
            @cli_set_process_title("Lark: worker process  {$this->name}");
        }

        // Install signal handler for worker process.
        if (DIRECTORY_SEPARATOR === '/' && function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, static::signalHandler(...));
            pcntl_signal(SIGTERM, static::signalHandler(...));
        }

        // onWorkerStart callback.
        $this->onWorkerStart?->__invoke($this);

        // Run event loop.
        static::$globalEvent->run();
    }

    /**
     * Listen for connections.
     */
    protected function listen(): void
    {
        if (!$this->socketName) {
            return;
        }

        $socketName = $this->socketName;
        // Parse the socket name.
        if (str_starts_with($socketName, 'http://')) {
            $socketName = 'tcp://' . substr($socketName, 7);
        }

        // Create socket.
        $flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
        $this->mainSocket = stream_socket_server($socketName, $errno, $errmsg, $flags, $this->socketContext);
        if (!$this->mainSocket) {
            throw new RuntimeException("stream_socket_server() failed: $errmsg (errno=$errno)");
        }

        stream_set_blocking($this->mainSocket, false);

        // Register onReadable callback for accepting connections.
        static::$globalEvent->onReadable($this->mainSocket, $this->acceptConnection(...));
    }

    /**
     * Accept a new connection.
     */
    protected function acceptConnection(mixed $socket): void
    {
        $newSocket = @stream_socket_accept($socket, 0, $remoteAddress);
        if (!$newSocket) {
            return;
        }

        $connection = new TcpConnection(static::$globalEvent, $newSocket, $remoteAddress);
        $connection->protocol = $this->protocol;
        $connection->onMessage = $this->onMessage;
        $connection->onClose = $this->onClose;
        $connection->onError = $this->onError;
        $this->connections[$connection->id] = $connection;

        $this->onConnect?->__invoke($connection);
    }

    /**
     * Stop all workers.
     * In worker process: sets graceful stop flag, waits for connections to close
     * before stopping the event loop.
     */
    public static function stopAll(int $status = 0, ?Throwable $e = null): void
    {
        if ($e) {
            static::log($e);
        }
        static::$status = static::STATUS_SHUTDOWN;

        if (DIRECTORY_SEPARATOR === '/' && static::$masterPid === posix_getpid()) {
            // Master process: send SIGINT to all workers.
            foreach (static::$pidMap as $pids) {
                foreach ($pids as $pid) {
                    posix_kill($pid, SIGINT);
                }
            }
            // If no workers, exit immediately.
            if (empty(array_filter(static::$pidMap))) {
                static::exitAndCleanUp();
            }
        } else {
            // Worker process: graceful shutdown
            foreach (static::$workers as $worker) {
                $worker->stopping = true;
                // Stop accepting new connections
                if ($worker->mainSocket) {
                    static::$globalEvent?->offReadable($worker->mainSocket);
                }
            }

            // Check if there are active connections
            $hasActiveConnections = false;
            foreach (static::$workers as $worker) {
                foreach ($worker->connections as $connection) {
                    if ($connection->getStatus() === TcpConnection::STATUS_ESTABLISHED) {
                        $hasActiveConnections = true;
                        $connection->close();
                    }
                }
            }

            // If no active connections, stop immediately
            if (!$hasActiveConnections) {
                static::$globalEvent?->stop();
                if ($thisWorker = reset(static::$workers)) {
                    $thisWorker->onWorkerStop?->__invoke($thisWorker);
                }
                exit($status);
            }

            // Schedule forced exit after stopTimeout seconds
            $forceExitTime = time() + static::$stopTimeout;
            $gracefulTimerId = static::$globalEvent?->repeat(1.0, function () use ($forceExitTime, $status, &$gracefulTimerId): void {
                $hasActive = false;
                foreach (static::$workers as $worker) {
                    foreach ($worker->connections as $connection) {
                        if ($connection->getStatus() !== TcpConnection::STATUS_CLOSED) {
                            $hasActive = true;
                            break 2;
                        }
                    }
                }

                if (!$hasActive || time() >= $forceExitTime) {
                    // Cancel this timer before stopping
                    if ($gracefulTimerId !== null) {
                        static::$globalEvent?->offRepeat($gracefulTimerId);
                    }
                    static::$globalEvent?->stop();
                    if ($thisWorker = reset(static::$workers)) {
                        $thisWorker->onWorkerStop?->__invoke($thisWorker);
                    }
                    exit($status);
                }
            });
        }
    }

    /**
     * Reload workers.
     */
    protected static function reloadWorkers(): void
    {
        foreach (static::$pidMap as $pids) {
            foreach ($pids as $pid) {
                posix_kill($pid, SIGINT);
            }
        }
    }

    /**
     * Exit and clean up.
     */
    protected static function exitAndCleanUp(): void
    {
        if (static::$pidFile && is_file(static::$pidFile)) {
            @unlink(static::$pidFile);
        }
        exit(0);
    }

    /**
     * Check if worker is running.
     */
    public static function isRunning(): bool
    {
        return static::$status === static::STATUS_RUNNING;
    }

    /**
     * Log a message.
     */
    public static function log(string|Throwable $message): void
    {
        $message = (string)$message;
        if (static::$logFile && static::$logFile !== '/dev/null') {
            $dir = dirname(static::$logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            // Rotate log file if it exceeds max size
            if (is_file(static::$logFile) && filesize(static::$logFile) > static::$logFileMaxSize) {
                $rotatedFile = static::$logFile . '.' . date('Y-m-d-His');
                @rename(static::$logFile, $rotatedFile);
            }
            file_put_contents(static::$logFile, date('Y-m-d H:i:s') . ' ' . $message . "\n", FILE_APPEND | LOCK_EX);
        }
        static::safeEcho($message);
    }

    /**
     * Safe echo.
     */
    public static function safeEcho(string $message): void
    {
        if (!static::$outputStream) {
            static::$outputStream = defined('STDOUT') ? STDOUT : @fopen('php://stdout', 'w');
        }
        if (!is_resource(static::$outputStream)) {
            return;
        }
        @fwrite(static::$outputStream, $message . "\n");
    }

    /**
     * Get all worker instances.
     *
     * @return Worker[]
     */
    public static function getWorkers(): array
    {
        return static::$workers;
    }

    /**
     * Get worker by id.
     */
    public static function getWorkerByPid(int $pid): ?self
    {
        foreach (static::$pidMap as $workerId => $pids) {
            if (isset($pids[$pid])) {
                return static::$workers[$workerId] ?? null;
            }
        }
        return null;
    }

    /**
     * Destructor.
     */
    public function __destruct()
    {
        if (isset(static::$workers[$this->workerId])) {
            unset(static::$workers[$this->workerId]);
        }
    }
}
