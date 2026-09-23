<?php

namespace LarkFrame\Util;

/**
 * 工具类静态代理，将实例方法调用转发到目标类的静态方法
 */
class UtilProxy
{
    public function __construct(
        private readonly string $targetClass
    ) {
        if (!class_exists($targetClass)) {
            throw new \InvalidArgumentException("Util target class '{$targetClass}' does not exist.");
        }
    }

    public function __call(string $method, array $arguments): mixed
    {
        if (!method_exists($this->targetClass, $method)) {
            throw new \BadMethodCallException("Method '{$method}' does not exist on {$this->targetClass}.");
        }
        return forward_static_call_array([$this->targetClass, $method], $arguments);
    }
}
