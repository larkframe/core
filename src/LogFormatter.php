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
    /** @var ?callable */
    protected $stacktracesParser;

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
            $this->stacktracesParser = $parser;
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
     * {@inheritDoc}
     */
    public function format(array $record): string
    {
        $vars = parent::format($record);
        $output = $this->format;
        $requestObj = request();
        if (is_null($requestObj)) {
            $request = [
                'all' => "",
                'requestId' => '',
                'uri' => '',
                'remoteIp' => '',
                'localIp' => '',
                'usedTime' => '',
                'userAgent' => '',
            ];
        } else {
            $request = [
                'all' => $requestObj->all(),
                'requestId' => $requestObj->requestId(),
                'uri' => $requestObj->uri(),
                'remoteIp' => $requestObj->getRemoteIp(),
                'localIp' => $requestObj->getLocalIp(),
                'usedTime' => $requestObj->usedTime(),
                'userAgent' => $requestObj->header('user-agent'),
            ];
        }
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

        if ($this->ignoreEmptyContextAndExtra) {
            if (!empty($vars['context'])) unset($vars['context']);
            $output = str_replace('%context%', '', $output);

            if (!empty($vars['extra'])) unset($vars['extra']);
            $output = str_replace('%extra%', '', $output);
        }

        unset($vars['channel']);
        foreach ($vars as $var => $val) {
            if (str_contains($output, '%' . $var . '%')) {
                if ($var == 'message' && $val == '') {
                    $val = $request['all'];
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
            '%request_id%' => $request['requestId'] ?? '',
            '%uri%' => $request['uri'] ?? '',
            '%remote_ip%' => $request['remoteIp'] ?? '',
            '%server_ip%' => $request['localIp'] ?? '',
            '%used_time%' => isset($request['usedTime']) ? $this->stringify($request['usedTime']) : '0',
            '%user_agent%' => $request['userAgent'] ?? '',
            '%run_type%' => defined('RUN_TYPE') ? RUN_TYPE : '',
        ];
        $output = str_replace(array_keys($replacements), array_values($replacements), $output);

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
            $str .= $this->stacktracesParser($e);
        }

        return $str;
    }

    private function stacktracesParser(\Throwable $e): string
    {
        $trace = $e->getTraceAsString();

        if ($this->stacktracesParser) {
            $trace = $this->stacktracesParserCustom($trace);
        }

        return "\n[stacktrace]\n" . $trace . "\n";
    }

    private function stacktracesParserCustom(string $trace): string
    {
        return implode("\n", array_filter(array_map($this->stacktracesParser, explode("\n", $trace))));
    }
}
