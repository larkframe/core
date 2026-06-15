<?php

namespace LarkFrame\View;

interface View
{
    /**
     * Render
     * @param string $template
     * @param array $vars
     * @param string|null $app
     * @return string
     */
    public static function render(string $template, array $vars, ?string $viewSuffix = null): string;
}
