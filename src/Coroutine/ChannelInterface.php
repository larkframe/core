<?php

namespace LarkFrame\Coroutine;

/**
 * Interface ChannelInterface
 *
 * Defines the contract for channel implementations used for
 * communication between coroutines.
 */
interface ChannelInterface
{
    /**
     * Push data into the channel.
     */
    public function push(mixed $data, float $timeout = -1): bool;

    /**
     * Pop data from the channel.
     */
    public function pop(float $timeout = -1): mixed;

    /**
     * Get the current length of the channel.
     */
    public function length(): int;

    /**
     * Get the capacity of the channel.
     */
    public function getCapacity(): int;

    /**
     * Close the channel.
     */
    public function close(): void;

    /**
     * P2-28：检查通道是否已关闭。
     * 调用方配合 push/pop 的 false 返回值区分"超时"与"通道关闭"。
     */
    public function isClosed(): bool;
}
