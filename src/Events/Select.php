<?php

namespace LarkFrame\Events;

use SplPriorityQueue;
use Throwable;
use function count;
use function max;
use function microtime;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use const DIRECTORY_SEPARATOR;

/**
 * Class Select
 *
 * Select-based event loop implementation using stream_select().
 * Optimized for PHP 8.1 with readonly properties, first-class callable syntax,
 * match expressions, and improved type safety.
 */
class Select implements EventInterface
{
    /**
     * Max select timeout in microseconds.
     */
    private const MAX_SELECT_TIMEOUT_US = 800000;

    /**
     * Running flag.
     */
    private bool $running = true;

    /**
     * Read event listeners indexed by fd key.
     *
     * @var array<int, callable>
     */
    private array $readEvents = [];

    /**
     * Write event listeners indexed by fd key.
     *
     * @var array<int, callable>
     */
    private array $writeEvents = [];

    /**
     * Except event listeners indexed by fd key.
     *
     * @var array<int, callable>
     */
    private array $exceptEvents = [];

    /**
     * Signal event listeners.
     *
     * @var array<int, callable>
     */
    private array $signalEvents = [];

    /**
     * Read file descriptors.
     *
     * @var array<int, resource>
     */
    private array $readFds = [];

    /**
     * Write file descriptors.
     *
     * @var array<int, resource>
     */
    private array $writeFds = [];

    /**
     * Except file descriptors.
     *
     * @var array<int, resource>
     */
    private array $exceptFds = [];

    /**
     * Timer scheduler using a max-heap (negated timestamps for min-heap behavior).
     */
    private SplPriorityQueue $scheduler;

    /**
     * Timer event listeners indexed by timer ID.
     */
    private array $eventTimer = [];

    /**
     * Timer ID counter.
     */
    private int $timerId = 1;

    /**
     * Select timeout in microseconds.
     */
    private int $selectTimeout = self::MAX_SELECT_TIMEOUT_US;

    /**
     * Next run time of the timer.
     */
    private float $nextTickTime = 0;

    /**
     * Error handler callback.
     */
    private ?\Closure $errorHandler = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * @inheritDoc
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $runTime = microtime(true) + $delay;
        $this->scheduler->insert($timerId, -$runTime);
        $this->eventTimer[$timerId] = [$func, $args];

        if ($this->nextTickTime == 0 || $this->nextTickTime > $runTime) {
            $this->setNextTickTime($runTime);
        }

        return $timerId;
    }

    /**
     * @inheritDoc
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            unset($this->eventTimer[$timerId]);
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $runTime = microtime(true) + $interval;
        $this->scheduler->insert($timerId, -$runTime);
        $this->eventTimer[$timerId] = [$func, $args, $interval];

        if ($this->nextTickTime == 0 || $this->nextTickTime > $runTime) {
            $this->setNextTickTime($runTime);
        }

        return $timerId;
    }

    /**
     * @inheritDoc
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * @inheritDoc
     */
    public function onReadable(mixed $stream, callable $func): void
    {
        if (count($this->readFds) >= 1024) {
            throw new \RuntimeException("Select event loop exceeded the maximum number of 1024 read file descriptors. Use Event (ext-event) for higher concurrency.");
        }

        $fdKey = (int)$stream;
        $this->readEvents[$fdKey] = $func;
        $this->readFds[$fdKey] = $stream;
    }

    /**
     * @inheritDoc
     */
    public function offReadable(mixed $stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            unset($this->readEvents[$fdKey], $this->readFds[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function onWritable(mixed $stream, callable $func): void
    {
        if (count($this->writeFds) >= 1024) {
            throw new \RuntimeException("Select event loop exceeded the maximum number of 1024 write file descriptors. Use Event (ext-event) for higher concurrency.");
        }

        $fdKey = (int)$stream;
        $this->writeEvents[$fdKey] = $func;
        $this->writeFds[$fdKey] = $stream;
    }

    /**
     * @inheritDoc
     */
    public function offWritable(mixed $stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            unset($this->writeEvents[$fdKey], $this->writeFds[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * On except event.
     */
    public function onExcept(mixed $stream, callable $func): void
    {
        $fdKey = (int)$stream;
        $this->exceptEvents[$fdKey] = $func;
        $this->exceptFds[$fdKey] = $stream;
    }

    /**
     * Off except event.
     */
    public function offExcept(mixed $stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->exceptEvents[$fdKey])) {
            unset($this->exceptEvents[$fdKey], $this->exceptFds[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function onSignal(int $signal, callable $func): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        $this->signalEvents[$signal] = $func;
        pcntl_signal($signal, fn() => $this->safeCall($this->signalEvents[$signal], [$signal]));
    }

    /**
     * @inheritDoc
     */
    public function offSignal(int $signal): bool
    {
        if (!function_exists('pcntl_signal')) {
            return false;
        }

        pcntl_signal($signal, SIG_IGN);

        if (isset($this->signalEvents[$signal])) {
            unset($this->signalEvents[$signal]);
            return true;
        }
        return false;
    }

    /**
     * Process timer tick.
     */
    protected function tick(): void
    {
        $tasksToInsert = [];

        while (!$this->scheduler->isEmpty()) {
            $schedulerData = $this->scheduler->top();
            $timerId = $schedulerData['data'];
            $nextRunTime = -$schedulerData['priority'];
            $timeNow = microtime(true);
            $this->selectTimeout = (int)(($nextRunTime - $timeNow) * 1_000_000);

            if ($this->selectTimeout <= 0) {
                $this->scheduler->extract();

                if (!isset($this->eventTimer[$timerId])) {
                    continue;
                }

                $taskData = $this->eventTimer[$timerId];
                if (isset($taskData[2])) {
                    // Repeating timer: reschedule
                    $nextRunTime = $timeNow + $taskData[2];
                    $tasksToInsert[] = [$timerId, -$nextRunTime];
                } else {
                    // One-shot timer: remove
                    unset($this->eventTimer[$timerId]);
                }

                $this->safeCall($taskData[0], $taskData[1]);
            } else {
                break;
            }
        }

        foreach ($tasksToInsert as [$id, $priority]) {
            $this->scheduler->insert($id, $priority);
        }

        if (!$this->scheduler->isEmpty()) {
            $schedulerData = $this->scheduler->top();
            $this->setNextTickTime(-$schedulerData['priority']);
            return;
        }

        $this->setNextTickTime(0);
    }

    /**
     * Set next tick time.
     */
    protected function setNextTickTime(float $nextTickTime): void
    {
        $this->nextTickTime = $nextTickTime;

        if ($nextTickTime == 0) {
            $this->selectTimeout = self::MAX_SELECT_TIMEOUT_US;
            return;
        }

        $this->selectTimeout = min(
            max((int)(($nextTickTime - microtime(true)) * 1_000_000), 0),
            self::MAX_SELECT_TIMEOUT_US
        );
    }

    /**
     * @inheritDoc
     */
    public function deleteAllTimer(): void
    {
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->eventTimer = [];
    }

    /**
     * @inheritDoc
     */
    public function run(): void
    {
        while ($this->running) {
            $read = $this->readFds;
            $write = $this->writeFds;
            $except = $this->exceptFds;

            if ($read || $write || $except) {
                try {
                    @stream_select($read, $write, $except, 0, $this->selectTimeout);
                } catch (Throwable) {
                    // stream_select can throw on signal interruption
                }
            } else {
                $this->selectTimeout >= 1 && usleep($this->selectTimeout);
            }

            $this->dispatchEvents($read, $this->readEvents);
            $this->dispatchEvents($write, $this->writeEvents);
            $this->dispatchEvents($except, $this->exceptEvents);

            if ($this->nextTickTime > 0) {
                if (microtime(true) >= $this->nextTickTime) {
                    $this->tick();
                } else {
                    $this->selectTimeout = (int)(($this->nextTickTime - microtime(true)) * 1_000_000);
                }
            }

            if (DIRECTORY_SEPARATOR === '/') {
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * Dispatch I/O events for the given file descriptors.
     *
     * @param array<int, resource> $fds
     * @param array<int, callable> $events
     */
    private function dispatchEvents(array $fds, array $events): void
    {
        foreach ($fds as $fd) {
            $fdKey = (int)$fd;
            if (isset($events[$fdKey])) {
                $events[$fdKey]($fd);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function stop(): void
    {
        $this->running = false;
        $this->deleteAllTimer();

        foreach ($this->signalEvents as $signal => $item) {
            $this->offSignal($signal);
        }

        $this->readFds = [];
        $this->writeFds = [];
        $this->exceptFds = [];
        $this->readEvents = [];
        $this->writeEvents = [];
        $this->exceptEvents = [];
        $this->signalEvents = [];
    }

    /**
     * @inheritDoc
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * @inheritDoc
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler(...);
    }

    /**
     * Safe call with error handling.
     */
    private function safeCall(callable $func, array $args = []): void
    {
        try {
            $func(...$args);
        } catch (Throwable $e) {
            $this->errorHandler?->__invoke($e) ?? print($e);
        }
    }
}
