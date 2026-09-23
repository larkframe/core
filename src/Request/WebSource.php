<?php

namespace LarkFrame\Request;

use function file_get_contents;
use function getallheaders;
use function json_decode;
use function microtime;
use function strtolower;

/**
 * Class WebSource
 *
 * Request source for traditional PHP-FPM/Web mode.
 * Data is populated from PHP superglobals.
 *
 * P2-38：php://input 通过 file_get_contents 一次性读入内存。FPM 模式下受
 * php.ini post_max_size 限制，超大 body 由 PHP 层截断。流式读取属于新功能，
 * 当前不实现，依赖 post_max_size 兜底。
 */
class WebSource implements RequestSourceInterface
{
    public function populateData(array &$data): void
    {
        // 单次 CSPRNG 调用替代 md5+uniqid+gethostname+Rand::str 组合（省系统调用与拒绝采样开销）
        $requestId = bin2hex(random_bytes(16));
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
