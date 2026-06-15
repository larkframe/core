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
 * Class WebSource
 *
 * Request source for traditional PHP-FPM/Web mode.
 * Data is populated from PHP superglobals.
 */
class WebSource implements RequestSourceInterface
{
    public function populateData(array &$data): void
    {
        $requestId = strtolower(substr(md5(microtime() . uniqid(gethostname() . '_', true)), 8, 16) . Rand::str(16));
        $startTime = microtime(true);

        $_RAW_PARAMS = [];
        $_RAW_DATA = file_get_contents('php://input');
        if ($_RAW_DATA) {
            $tmpValue = json_decode($_RAW_DATA, true);
            if (is_array($tmpValue)) {
                $_RAW_PARAMS = $tmpValue;
            }
        }

        $data['get'] = $_GET;
        $data['post'] = array_merge($_POST, $_RAW_PARAMS);
        $data['method'] = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $tmpHeader = getallheaders();
        foreach ($tmpHeader as $k => $v) {
            $data['headers'][strtolower($k)] = $v;
        }
        $data['headers']['user-agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $data['cookie'] = $_COOKIE;
        $data['uri'] = $_SERVER['REQUEST_URI'];
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
