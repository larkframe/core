<?php

namespace LarkFrame;

class Library
{
    public array $config = [];

    /**
     * 构造时按子类短名（小写）自动加载 config/{name}.php 与 config/{name}.{env}.php。
     * 环境覆盖为 array_merge 浅合并：嵌套数组键需在覆盖文件中整体重写。
     * 'config'/'route' 两个短名与框架自身配置文件冲突，跳过自动加载。
     */
    public function __construct()
    {
        $resource = explode("\\", get_class($this));
        $childClassName = strtolower(array_pop($resource));
        if ($childClassName && $childClassName != 'config' && $childClassName != 'route' && empty($this->config)) {
            $configPath = config_path() . '/';
            $config = [];
            if (file_exists($configPath . $childClassName . '.php')) {
                $loaded = require $configPath . $childClassName . '.php';
                if (is_array($loaded)) {
                    $config = $loaded;
                }
            }
            // 环境覆盖独立于主配置存在：仅有 queue.prod.php 而无 queue.php 时也应生效
            if (defined('RUN_MODE') && file_exists($configPath . $childClassName . '.' . RUN_MODE . '.php')) {
                $envConfig = require $configPath . $childClassName . '.' . RUN_MODE . '.php';
                if (is_array($envConfig)) {
                    $config = array_merge($config, $envConfig);
                }
            }
            if ($config) {
                $this->config = $config;
            }
        }
    }
}