<?php

namespace LarkFrame\View;

use Throwable;
use function array_merge;
use function config;
use function extract;
use function is_array;
use function ob_end_clean;
use function ob_get_clean;
use function ob_start;

class Raw implements View
{
    /**
     * Assign.
     * @param string|array $name
     * @param mixed $value
     */
    public static function assign(string|array $name, mixed $value = null): void
    {
        ViewVarHolder::assign($name, $value);
    }

    /**
     * Render.
     * @param string $template
     * @param array $vars
     * @param string|null $viewSuffix
     * @return string
     */
    public static function render(string $template, array $vars, ?string $viewSuffix = null): string
    {
        if ($viewSuffix == null) {
            $viewSuffix = config("view.options.view_suffix", 'php');
        }

        $__template_path__ = ROOT_PATH . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . $template . '.' . $viewSuffix;
        if (!file_exists($__template_path__)) {
            return "template " . $template .".". $viewSuffix . " not found";
        }

        // Merge view vars from holder with render-time vars
        $allVars = array_merge(ViewVarHolder::getVars(), $vars);
        extract($allVars);

        ob_start();
        // Try to include php file.
        try {
            include $__template_path__;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        $result = ob_get_clean();

        // Clear assigned vars after rendering
        ViewVarHolder::clear();

        return $result;
    }
}
