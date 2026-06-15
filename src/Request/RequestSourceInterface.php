<?php

namespace LarkFrame\Request;

/**
 * Interface RequestSourceInterface
 *
 * Strategy for populating request data from different runtime sources.
 * Replaces scattered RUN_TYPE conditionals in Request.
 */
interface RequestSourceInterface
{
    /**
     * Populate request data (get, post, headers, cookie, uri, etc.).
     */
    public function populateData(array &$data): void;

    /**
     * Whether this source provides a raw HTTP buffer.
     */
    public function hasRawBuffer(): bool;

    /**
     * Get the raw HTTP buffer (empty for non-server sources).
     */
    public function getRawBuffer(): string;

    /**
     * Get the host.
     */
    public function getHost(bool $withoutPort = false): ?string;
}
