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
     * Scheduler rebuild threshold: after this many cancellations, rebuild to free memory.
     */
    private const REBUILD_THRESHOLD = 100;

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
     * Cancelled timer count for lazy deletion rebuild trigger.
     */
    private int $cancelledCount = 0;

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

        if ($this->nextTickTime <= 0 || $this->nextTickTime > $runTime) {
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
            $this->cancelledCount++;
            if ($this->cancelledCount >= self::REBUILD_THRESHOLD) {
                $this->rebuildScheduler();
            }
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

        if ($this->nextTickTime <= 0 || $this->nextTickTime > $runTime) {
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
        // 捕获 $func 值而非动态读取 $this->signalEvents[$signal]，避免 offSignal 后信号到达读到 null
        pcntl_signal($signal, function () use ($signal, $func) {
            $this->safeCall($func, [$signal]);
        });
    }

    /**
     * @inheritDoc
     */
    public function offSignal(int $signal): bool
    {
        if (!function_exists('pcntl_signal')) {
            return false;
        }

        // 恢复 SIG_DFL 默认处理（与 offAll 一致），确保信号可被进程正常接收/终止
        pcntl_signal($signal, SIG_DFL);

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

        // P2-3 方案 B：用 <= 0 替代 == 0 浮点比较，防御负数/极小浮点异常
        if ($nextTickTime <= 0) {
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
        // 保持 timerId 单调递增（P2-1 方案 B），避免 deleteAllTimer 后旧 id 引用与新注册 id 冲突
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->eventTimer = [];
        $this->cancelledCount = 0;
        $this->nextTickTime = 0;
        $this->selectTimeout = self::MAX_SELECT_TIMEOUT_US;
    }

    /**
     * Rebuild scheduler by removing entries for cancelled timers.
     */
    private function rebuildScheduler(): void
    {
        $validEntries = [];
        while (!$this->scheduler->isEmpty()) {
            $item = $this->scheduler->extract();
            if (isset($this->eventTimer[$item['data']])) {
                $validEntries[] = $item;
            }
        }
        foreach ($validEntries as $item) {
            $this->scheduler->insert($item['data'], $item['priority']);
        }
        $this->cancelledCount = 0;
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
                    // stream_select 返回 false 表示信号中断或错误，不应终止事件循环
                    @stream_select($read, $write, $except, 0, $this->selectTimeout);
                } catch (Throwable) {
                    // stream_select can throw on signal interruption
                    $read = $write = $except = [];
                }
            } else {
                $this->selectTimeout >= 1 && usleep($this->selectTimeout);
            }

            $this->dispatchEvents($read, $this->readEvents);
            $this->dispatchEvents($write, $this->writeEvents);
            $this->dispatchEvents($except, $this->exceptEvents);

            // 持续处理到期定时器，避免单轮延迟累积
            while ($this->nextTickTime > 0 && microtime(true) >= $this->nextTickTime) {
                $this->tick();
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
                $this->safeCall($events[$fdKey], [$fd]);
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function offAll(): void
    {
        $this->deleteAllTimer();

        // P2-2 方案 A：stop 恢复 SIG_DFL 默认处理（而非 SIG_IGN），确保 stop 后进程仍可被 SIGTERM 正常终止
        foreach (array_keys($this->signalEvents) as $signal) {
            if (function_exists('pcntl_signal')) {
                pcntl_signal($signal, SIG_DFL);
            }
            unset($this->signalEvents[$signal]);
        }

        $this->readFds = [];
        $this->writeFds = [];
        $this->exceptFds = [];
        $this->readEvents = [];
        $this->writeEvents = [];
        $this->exceptEvents = [];
    }

    /**
     * @inheritDoc
     */
    public function stop(): void
    {
        $this->running = false;
        $this->offAll();
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
            if ($this->errorHandler !== null) {
                try {
                    ($this->errorHandler)($e);
                } catch (Throwable $inner) {
                    // errorHandler 自身异常只能记录，不能再抛
                    error_log("[EventLoop] errorHandler threw: " . $inner->getMessage() . " | original: " . $e->getMessage());
                }
            } else {
                error_log("[EventLoop] uncaught: " . $e->getMessage() . "\n" . $e->getTraceAsString());
            }
        }
    }
}
