<?php

namespace LarkFrame;

class Library
{
    public array $config = [];
    public function __construct()
    {
        $resource = explode("\\", get_class($this));
        $childClassName = strtolower(array_pop($resource));
        if ($childClassName && $childClassName != 'config' && $childClassName != 'route' && empty($this->config)) {
            $configPath = config_path() . '/';
            if (file_exists($configPath . $childClassName . '.php')) {
                $config = require $configPath . $childClassName . '.php';
                if (is_array($config) && file_exists($configPath . $childClassName . '.' . RUN_MODE . '.php')) {
                    $envConfig = require $configPath . $childClassName . '.' . RUN_MODE . '.php';
                    if (is_array($envConfig)) {
                        $config = array_merge($config, $envConfig);
                    }
                }
                if (is_array($config)) {
                    $this->config = $config;
                }
            }
        }
    }
}