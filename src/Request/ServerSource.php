<?php

namespace LarkFrame\Request;

use LarkFrame\Util\Rand;
use function file_get_contents;
use function getallheaders;
use function gethostname;
use function json_decode;
use function microtime;
use function parse_str;
use function strtolower;
use function substr;
use function md5;
use function uniqid;

/**
 * Class ServerSource
 *
 * Request source for the built-in server mode.
 * Data is parsed from the raw HTTP buffer on demand.
 */
class ServerSource implements RequestSourceInterface
{
    public function populateData(array &$data): void
    {
        // Server mode: data is populated lazily by parse methods in Request
        // Only set requestId and startTime here
        $data['requestId'] = strtolower(substr(md5(microtime() . uniqid(gethostname() . '_', true)), 8, 16) . Rand::str(16));
        $data['startTime'] = microtime(true);
    }

    public function hasRawBuffer(): bool
    {
        return true;
    }

    public function getRawBuffer(): string
    {
        return '';
    }

    public function getHost(bool $withoutPort = false): ?string
    {
        // Host is resolved from request headers, not available at source level
        return null;
    }
}
