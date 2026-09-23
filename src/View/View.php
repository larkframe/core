<?php

namespace LarkFrame\View;

interface View
{
    /**
     * Assign view variables (merged into render-time vars on the next render).
     *
     * @param string|array $name 变量名或 [name => value] 数组
     * @param mixed $value 变量值（$name 为数组时忽略）
     */
    public static function assign(string|array $name, mixed $value = null): void;

    /**
     * Render
     * @param string $template 模板名（相对 ROOT_PATH/template/，含 ../ 的穿越请求会被拒绝）
     * @param array $vars 渲染变量
     * @param string|null $viewSuffix 模板后缀，null 时取 view.options.view_suffix
     * @return string
     */
    public static function render(string $template, array $vars, ?string $viewSuffix = null): string;
}
