<?php declare(strict_types=1);

namespace LarkFrame;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\Utils;

class LogFormatter extends NormalizerFormatter
{
    public const SIMPLE_FORMAT = "%datetime%|%request_id%|%run_type%|%level_name%|%remote_ip%|%server_ip%|%uri%|%used_time%|%message%|%user_agent%|%context%|%extra%\n";

    /** @var string */
    protected $format;
    /** @var bool */
    protected $allowInlineLineBreaks;
    /** @var bool */
    protected $ignoreEmptyContextAndExtra;
    /** @var bool */
    protected $includeStacktraces;
    /** @var ?callable 自定义堆栈行过滤器（接收单行 trace 字符串，返回过滤结果） */
    protected $stacktraceParser;
    /** @var bool */
    protected bool $stripAnsi = true;

    /**
     * @param string|null $format The format of the message
     * @param string|null $dateFormat The format of the timestamp: one supported by DateTime::format
     * @param bool $allowInlineLineBreaks Whether to allow inline line breaks in log entries
     * @param bool $ignoreEmptyContextAndExtra
     */
    public function __construct(?string $format = null, ?string $dateFormat = null, bool $allowInlineLineBreaks = false, bool $ignoreEmptyContextAndExtra = false, bool $includeStacktraces = false)
    {
        $this->format = $format === null ? static::SIMPLE_FORMAT : $format;
        $this->allowInlineLineBreaks = $allowInlineLineBreaks;
        $this->ignoreEmptyContextAndExtra = $ignoreEmptyContextAndExtra;
        $this->includeStacktraces($includeStacktraces);
        parent::__construct($dateFormat);
    }

    public function includeStacktraces(bool $include = true, ?callable $parser = null): self
    {
        $this->includeStacktraces = $include;
        if ($this->includeStacktraces) {
            $this->allowInlineLineBreaks = true;
            $this->stacktraceParser = $parser;
        }

        return $this;
    }

    public function allowInlineLineBreaks(bool $allow = true): self
    {
        $this->allowInlineLineBreaks = $allow;

        return $this;
    }

    public function ignoreEmptyContextAndExtra(bool $ignore = true): self
    {
        $this->ignoreEmptyContextAndExtra = $ignore;

        return $this;
    }

    /**
     * P2-49：是否剥离 ANSI 颜色码。文件日志应设为 true（默认），
     * 终端输出可设为 false 以保留彩色。
     */
    public function stripAnsi(bool $strip = true): self
    {
        $this->stripAnsi = $strip;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function format(array $record): string
    {
        $vars = parent::format($record);
        $output = $this->format;
        $requestObj = request();

        foreach ($vars['extra'] as $var => $val) {
            if (false !== strpos($output, '%extra.' . $var . '%')) {
                $output = str_replace('%extra.' . $var . '%', $this->stringify($val), $output);
                unset($vars['extra'][$var]);
            }
        }

        foreach ($vars['context'] as $var => $val) {
            if (false !== strpos($output, '%context.' . $var . '%')) {
                $output = str_replace('%context.' . $var . '%', $this->stringify($val), $output);
                unset($vars['context'][$var]);
            }
        }

        // 语义与 Monolog LineFormatter 对齐：仅在为空时移除占位符，非空数据照常渲染
        if ($this->ignoreEmptyContextAndExtra) {
            if (empty($vars['context'])) {
                $output = str_replace('%context%', '', $output);
            }
            if (empty($vars['extra'])) {
                $output = str_replace('%extra%', '', $output);
            }
        }

        unset($vars['channel']);
        foreach ($vars as $var => $val) {
            if (str_contains($output, '%' . $var . '%')) {
                if ($var == 'message' && $val == '' && $requestObj !== null) {
                    // 访问日志语义：空 message 以请求参数填充（App::send 的 Log::info("") 走此分支）。
                    // 仅在需要时才调用 all()，避免每条日志都采集全量请求输入
                    $val = $requestObj->all();
                }
                $output = str_replace('%' . $var . '%', $this->stringify($val), $output);
            }
        }
        // remove leftover %extra.xxx% and %context.xxx% if any
        if (str_contains($output, '%')) {
            $output = preg_replace('/%(?:extra|context)\..+?%/', '', $output);
            if (null === $output) {
                $pcreErrorCode = preg_last_error();
                throw new \RuntimeException('Failed to run preg_replace: ' . $pcreErrorCode . ' / ' . Utils::pcreLastErrorMessage($pcreErrorCode));
            }
        }

        // Replace request-related placeholders using str_replace (faster than preg_replace)
        $replacements = [
            '%request_id%' => $requestObj?->requestId() ?? '',
            '%uri%' => $requestObj?->uri() ?? '',
            '%remote_ip%' => $requestObj?->getRemoteIp() ?? '',
            '%server_ip%' => $requestObj?->getLocalIp() ?? '',
            '%used_time%' => $requestObj !== null ? $this->stringify($requestObj->usedTime()) : '0',
            '%user_agent%' => $requestObj?->header('user-agent') ?? '',
            '%run_type%' => defined('RUN_TYPE') ? RUN_TYPE : '',
        ];
        $output = str_replace(array_keys($replacements), array_values($replacements), $output);

        // P2-49：剥离 ANSI 颜色码，防止终端色码泄漏到文件日志
        if ($this->stripAnsi) {
            $stripped = preg_replace('/\x1b\[[0-9;]*[a-zA-Z]/', '', $output);
            if ($stripped !== null) {
                $output = $stripped;
            }
        }

        return $output;
    }

    public function formatBatch(array $records): string
    {
        $message = '';
        foreach ($records as $record) {
            $message .= $this->format($record);
        }

        return $message;
    }

    /**
     * @param mixed $value
     */
    public function stringify($value): string
    {
        return $this->replaceNewlines($this->convertToString($value));
    }

    protected function normalizeException(\Throwable $e, int $depth = 0): string
    {
        $str = $this->formatException($e);

        if ($previous = $e->getPrevious()) {
            do {
                $depth++;
                if ($depth > $this->maxNormalizeDepth) {
                    $str .= "\n[previous exception] Over " . $this->maxNormalizeDepth . ' levels deep, aborting normalization';
                    break;
                }

                $str .= "\n[previous exception] " . $this->formatException($previous);
            } while ($previous = $previous->getPrevious());
        }

        return $str;
    }

    /**
     * @param mixed $data
     */
    protected function convertToString($data): string
    {
        if (null === $data || is_bool($data)) {
            return var_export($data, true);
        }

        if (is_scalar($data)) {
            return (string)$data;
        }

        return $this->toJson($data, true);
    }

    protected function replaceNewlines(string $str): string
    {
        if ($this->allowInlineLineBreaks) {
            if (0 === strpos($str, '{')) {
                $str = preg_replace('/(?<!\\\\)\\\\[rn]/', "\n", $str);
                if (null === $str) {
                    $pcreErrorCode = preg_last_error();
                    throw new \RuntimeException('Failed to run preg_replace: ' . $pcreErrorCode . ' / ' . Utils::pcreLastErrorMessage($pcreErrorCode));
                }
            }

            return $str;
        }

        return str_replace(["\r\n", "\r", "\n"], ' ', $str);
    }

    private function formatException(\Throwable $e): string
    {
        $str = '[object] (' . Utils::getClass($e) . '(code: ' . $e->getCode();
        if ($e instanceof \SoapFault) {
            if (isset($e->faultcode)) {
                $str .= ' faultcode: ' . $e->faultcode;
            }

            if (isset($e->faultactor)) {
                $str .= ' faultactor: ' . $e->faultactor;
            }

            if (isset($e->detail)) {
                if (is_string($e->detail)) {
                    $str .= ' detail: ' . $e->detail;
                } elseif (is_object($e->detail) || is_array($e->detail)) {
                    $str .= ' detail: ' . $this->toJson($e->detail, true);
                }
            }
        }
        $str .= '): ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine() . ')';

        if ($this->includeStacktraces) {
            $str .= $this->renderStackTrace($e);
        }

        return $str;
    }

    private function renderStackTrace(\Throwable $e): string
    {
        $trace = $e->getTraceAsString();

        // 自定义 parser 按行过滤（此前属性与方法同名导致 parser 被错误地以 Throwable 为参调用）
        if ($this->stacktraceParser) {
            $trace = implode("\n", array_filter(array_map($this->stacktraceParser, explode("\n", $trace))));
        }

        return "\n[stacktrace]\n" . $trace . "\n";
    }
}
