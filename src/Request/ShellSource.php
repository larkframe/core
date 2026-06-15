<?php

namespace LarkFrame\Request;

use LarkFrame\Util\Rand;
use function gethostname;
use function microtime;
use function parse_str;
use function strtolower;
use function substr;
use function md5;
use function uniqid;

/**
 * Class ShellSource
 *
 * Request source for CLI/Shell mode.
 * Data is populated from command-line arguments.
 */
class ShellSource implements RequestSourceInterface
{
    public function populateData(array &$data): void
    {
        $requestId = strtolower(substr(md5(microtime() . uniqid(gethostname() . '_', true)), 8, 16) . Rand::str(16));
        $startTime = microtime(true);

        $shellParams = [];
        if (isset($_SERVER['argv'][2])) {
            parse_str($_SERVER['argv'][2], $shellParams);
            $uri = ROUTE_VALUE . '?' . $_SERVER['argv'][2];
        } else {
            $uri = ROUTE_VALUE;
        }

        $data['get'] = $shellParams;
        $data['post'] = $shellParams;
        $data['uri'] = $uri;
        $data['requestId'] = $requestId;
        $data['startTime'] = $startTime;
    }

    public function hasRawBuffer(): bool
    {
        return false;
    }

    public function getRawBuffer(): string
    {
        return '';
    }

    public function getHost(bool $withoutPort = false): ?string
    {
        return getRealHost($withoutPort);
    }
}
