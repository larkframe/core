<?php

namespace LarkFrame\Util;

/**
 * 工具类静态代理，将实例方法调用转发到目标类的静态方法
 */
class UtilProxy
{
    public function __construct(
        private readonly string $targetClass
    ) {}

    public function __call(string $method, array $arguments): mixed
    {
        return forward_static_call_array([$this->targetClass, $method], $arguments);
    }
}

/**
 * Util 门面类，统一访问工具组件
 *
 * 用法：
 *   Util::base64()->encode($data)
 *   Util::base64()->urlEncode($data)
 *   Util::base64()->authcode($string, 'ENCODE')
 *   Util::str()->camelToUnderscore($input)
 *   Util::str()->mask('13800138000')
 *   Util::rand()->uuid()
 *   Util::rand()->str(16)
 *   Util::file()->ensureDir($dir)
 *   Util::file()->safeWrite($path, $content)
 *   Util::img()->resize($src, $dst, 100, 100)
 *   Util::mock()->list($template, 10)
 */
class UtilFacade
{
    private static array $map = [
        'base64' => Base64::class,
        'file'   => File::class,
        'img'    => Img::class,
        'mock'   => Mock::class,
        'rand'   => Rand::class,
        'str'    => Str::class,
    ];

    private static array $proxies = [];

    public static function __callStatic(string $name, array $arguments): UtilProxy
    {
        if (!isset(static::$map[$name])) {
            throw new \BadMethodCallException("Util component '{$name}' not found. Available: " . implode(', ', array_keys(static::$map)));
        }

        return static::$proxies[$name] ??= new UtilProxy(static::$map[$name]);
    }
}
