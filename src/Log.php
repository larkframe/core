<?php

namespace LarkFrame;

use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger;
use function array_values;
use function config;
use function is_array;

/**
 * Class Log
 * @package LarkFrame
 *
 * @method static void log($level, $message, array $context = [])
 * @method static void debug($message, array $context = [])
 * @method static void info($message, array $context = [])
 * @method static void notice($message, array $context = [])
 * @method static void warning($message, array $context = [])
 * @method static void error($message, array $context = [])
 * @method static void critical($message, array $context = [])
 * @method static void alert($message, array $context = [])
 * @method static void emergency($message, array $context = [])
 */
class Log
{
    /**
     * @var array
     */
    protected static $instance = [];

    /**
     * Channel.
     * @param string $name
     * @return Logger
     */
    public static function channel(string $name = 'default'): Logger
    {
        if (!isset(static::$instance[$name])) {
            $logConfig = config('log', []);
            if (!is_array($logConfig)) {
                $logConfig = [];
            }
            // 未配置的通道回退默认 handler 而非抛异常：日志是最后一道防线，
            // 配置缺失不应让调用链（含错误处理器自身）在记录错误时二次崩溃
            $config = $logConfig[$name] ?? [];

            $handlers = self::handlers($config);
            $processors = self::processors($config);
            $logger = new Logger($name, $handlers, $processors);
            if (method_exists($logger, 'useLoggingLoopDetection')) {
                $logger->useLoggingLoopDetection(false);
            }
            static::$instance[$name] = $logger;
        }
        return static::$instance[$name];
    }

    /**
     * Handlers.
     * @param array $config
     * @return array
     */
    protected static function handlers(array $config): array
    {
        // 默认 StreamHandler，level 根据 app.debug 动态决定：开发 DEBUG / 生产 INFO
        $defaultLevel = config('app.debug', false) ? Logger::DEBUG : Logger::INFO;
        $handlerConfigs = $config['handlers'] ?? [];
        // 显式配置为空数组同样回退默认（?? 不会命中空数组，会把所有日志静默丢弃）
        if ($handlerConfigs === []) {
            $handlerConfigs = [
                ['class' => \Monolog\Handler\StreamHandler::class, 'constructor' => [runtime_path('logs/app.log'), $defaultLevel]]
            ];
        }
        $handlers = [];
        foreach ($handlerConfigs as $value) {
            $class = $value['class'] ?? '';
            $constructor = $value['constructor'] ?? [];

            $formatterConfig = $value['formatter'] ?? [];

            $class && $handlers[] = self::handler($class, $constructor, $formatterConfig);
        }

        return $handlers;
    }

    /**
     * Handler.
     *
     * constructor 参数支持 Closure 延迟求值：config.php 在配置加载完成前被 require，
     * 其中直接调用 runtime_path() 只能取到默认目录；写成
     * `fn() => runtime_path('logs/app.log')` 即可在本方法实例化时（配置已就绪）求值。
     *
     * @param string $class
     * @param array $constructor
     * @param array $formatterConfig
     * @return HandlerInterface
     */
    protected static function handler(string $class, array $constructor, array $formatterConfig): HandlerInterface
    {
        /** @var HandlerInterface $handler */
        $handler = new $class(... self::resolveConstructor($constructor));

        if ($handler instanceof FormattableHandlerInterface && $formatterConfig) {
            $formatterClass = $formatterConfig['class'] ?? null;
            $formatterConstructor = $formatterConfig['constructor'] ?? [];
            if ($formatterClass !== null) {
                /** @var FormatterInterface $formatter */
                $formatter = new $formatterClass(... self::resolveConstructor($formatterConstructor));
                $handler->setFormatter($formatter);
            }
        }

        return $handler;
    }

    /**
     * 解析构造参数：Closure 项在实例化时调用（延迟求值），其余原样返回。
     */
    protected static function resolveConstructor(array $constructor): array
    {
        return array_map(
            static fn(mixed $arg): mixed => $arg instanceof \Closure ? $arg() : $arg,
            array_values($constructor)
        );
    }

    /**
     * Processors.
     * @param array $config
     * @return array
     */
    protected static function processors(array $config): array
    {
        $result = [];
        if (!isset($config['processors']) && isset($config['processor'])) {
            $config['processors'] = [$config['processor']];
        }

        foreach ($config['processors'] ?? [] as $value) {
            if (is_array($value) && isset($value['class'])) {
                $value = new $value['class'](... self::resolveConstructor($value['constructor'] ?? []));
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @param string $name
     * @param array $arguments
     * @return mixed
     */
    public static function __callStatic(string $name, array $arguments)
    {
        return static::channel()->{$name}(... $arguments);
    }
}
