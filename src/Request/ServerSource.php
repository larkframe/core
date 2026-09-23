<?php

namespace LarkFrame\Request;

use function microtime;

/**
 * Class ServerSource
 *
 * Request source for the built-in server mode.
 * Data is parsed from the raw HTTP buffer on demand.
 *
 * P2-37：Server 模式下请求 body 由 Http::input/decode 从连接缓冲区读取，整体驻留内存。
 * 大文件上传时受 TcpConnection::maxPackageSize 限制（超出返回 413）。
 * 流式/临时文件暂存属于架构级新功能，当前不实现，依赖 maxPackageSize 兜底。
 */
class ServerSource implements RequestSourceInterface
{
    public function populateData(array &$data): void
    {
        // Server mode: data is populated lazily by parse methods in Request
        // Only set requestId and startTime here.
        // 单次 CSPRNG 调用替代 md5+uniqid+gethostname+Rand::str 组合（省系统调用与拒绝采样开销）
        $data['requestId'] = bin2hex(random_bytes(16));
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
