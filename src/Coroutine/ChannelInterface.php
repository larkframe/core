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
}
