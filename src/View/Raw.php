<?php

namespace LarkFrame\View;

use RuntimeException;
use Throwable;
use function array_merge;
use function config;
use function extract;
use function is_array;
use function is_file;
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

        // P2-22：用 is_file 替代 file_exists（后者对目录也返回 true），缺失时抛异常 fail-fast
        $__template_path__ = ROOT_PATH . DIRECTORY_SEPARATOR . 'template' . DIRECTORY_SEPARATOR . $template . '.' . $viewSuffix;
        if (!is_file($__template_path__)) {
            throw new RuntimeException("Template not found: {$template}.{$viewSuffix} (path: {$__template_path__})");
        }

        $allVars = array_merge(ViewVarHolder::getVars(), $vars);

        ob_start();
        // P2-23 方案 B：用闭包隔离模板变量作用域，避免 extract 出的变量污染 render 方法作用域，
        // 也避免 render 方法内的临时变量（$__template_path__ 等）被模板变量覆盖。
        // EXTR_SKIP: 不覆盖闭包内已定义的 $__template_path__ 与 $__vars__，防止任意文件包含。
        $render = static function (string $__template_path__, array $__vars__): void {
            extract($__vars__, EXTR_SKIP);
            include $__template_path__;
        };
        try {
            $render($__template_path__, $allVars);
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
