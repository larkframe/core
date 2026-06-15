<?php

namespace LarkFrame\Events;

use Throwable;

/**
 * Class Event
 *
 * Event loop implementation using the ext-event extension (epoll/kqueue).
 * Provides high-performance I/O multiplexing without the 1024 fd limit of select().
 *
 * @see https://www.php.net/manual/en/book.event.php ext-event extension
 */
class Event implements EventInterface
{
    /**
     * Event base resource.
     * @var \EventBase
     */
    private readonly object $eventBase;

    /**
     * Read event resources indexed by fd key.
     *
     * @var array<int, \Event>
     */
    private array $readEvents = [];

    /**
     * Write event resources indexed by fd key.
     *
     * @var array<int, \Event>
     */
    private array $writeEvents = [];

    /**
     * Signal event resources.
     *
     * @var array<int, \Event>
     */
    private array $signalEvents = [];

    /**
     * Timer event resources indexed by timer ID.
     *
     * @var array<int, \Event>
     */
    private array $timerEvents = [];

    /**
     * Timer callbacks indexed by timer ID.
     */
    private array $timerCallbacks = [];

    /**
     * Timer ID counter.
     */
    private int $timerId = 1;

    /**
     * Error handler callback.
     */
    private ?\Closure $errorHandler = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->eventBase = new \EventBase();
    }

    /**
     * @inheritDoc
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $this->timerCallbacks[$timerId] = [$func, $args];

        $event = \Event::timer($this->eventBase, function () use ($timerId) {
            if (!isset($this->timerCallbacks[$timerId])) {
                return;
            }
            $callback = $this->timerCallbacks[$timerId];
            unset($this->timerCallbacks[$timerId], $this->timerEvents[$timerId]);
            $this->safeCall($callback[0], $callback[1]);
        });

        $event->add($delay);
        $this->timerEvents[$timerId] = $event;

        return $timerId;
    }

    /**
     * @inheritDoc
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->timerEvents[$timerId])) {
            $this->timerEvents[$timerId]->free();
            unset($this->timerEvents[$timerId], $this->timerCallbacks[$timerId]);
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
        $this->timerCallbacks[$timerId] = [$func, $args];

        $event = \Event::timer($this->eventBase, function () use ($timerId, $interval) {
            if (!isset($this->timerCallbacks[$timerId])) {
                return;
            }
            $callback = $this->timerCallbacks[$timerId];
            $this->safeCall($callback[0], $callback[1]);

            // Re-schedule if still active
            if (isset($this->timerEvents[$timerId])) {
                $this->timerEvents[$timerId]->add($interval);
            }
        });

        $event->add($interval);
        $this->timerEvents[$timerId] = $event;

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
        $fdKey = (int)$stream;

        if (isset($this->readEvents[$fdKey])) {
            $this->readEvents[$fdKey]->free();
        }

        $event = new \Event(
            $this->eventBase,
            $stream,
            \Event::READ | \Event::PERSIST,
            function ($fd) use ($func) {
                $this->safeCall($func, [$fd]);
            }
        );
        $event->add();
        $this->readEvents[$fdKey] = $event;
    }

    /**
     * @inheritDoc
     */
    public function offReadable(mixed $stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->readEvents[$fdKey]->free();
            unset($this->readEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function onWritable(mixed $stream, callable $func): void
    {
        $fdKey = (int)$stream;

        if (isset($this->writeEvents[$fdKey])) {
            $this->writeEvents[$fdKey]->free();
        }

        $event = new \Event(
            $this->eventBase,
            $stream,
            \Event::WRITE | \Event::PERSIST,
            function ($fd) use ($func) {
                $this->safeCall($func, [$fd]);
            }
        );
        $event->add();
        $this->writeEvents[$fdKey] = $event;
    }

    /**
     * @inheritDoc
     */
    public function offWritable(mixed $stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->writeEvents[$fdKey]->free();
            unset($this->writeEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function onSignal(int $signal, callable $func): void
    {
        if (isset($this->signalEvents[$signal])) {
            $this->signalEvents[$signal]->free();
        }

        $event = \Event::signal($this->eventBase, $signal, function () use ($func, $signal) {
            $this->safeCall($func, [$signal]);
        });
        $event->add();
        $this->signalEvents[$signal] = $event;
    }

    /**
     * @inheritDoc
     */
    public function offSignal(int $signal): bool
    {
        if (isset($this->signalEvents[$signal])) {
            $this->signalEvents[$signal]->free();
            unset($this->signalEvents[$signal]);
            return true;
        }
        return false;
    }

    /**
     * @inheritDoc
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->timerEvents as $event) {
            $event->free();
        }
        $this->timerEvents = [];
        $this->timerCallbacks = [];
    }

    /**
     * @inheritDoc
     */
    public function run(): void
    {
        $this->eventBase->loop();
    }

    /**
     * @inheritDoc
     */
    public function stop(): void
    {
        $this->deleteAllTimer();

        foreach ($this->signalEvents as $event) {
            $event->free();
        }
        $this->signalEvents = [];

        foreach ($this->readEvents as $event) {
            $event->free();
        }
        $this->readEvents = [];

        foreach ($this->writeEvents as $event) {
            $event->free();
        }
        $this->writeEvents = [];

        $this->eventBase->stop();
    }

    /**
     * @inheritDoc
     */
    public function getTimerCount(): int
    {
        return count($this->timerCallbacks);
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
