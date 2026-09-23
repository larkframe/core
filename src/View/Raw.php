<?php

namespace LarkFrame\View;

use RuntimeException;
use Throwable;
use function array_merge;
use function config;
use function extract;
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

        $templateDir = ROOT_PATH . DIRECTORY_SEPARATOR . 'template';
        $__template_path__ = $templateDir . DIRECTORY_SEPARATOR . $template . '.' . $viewSuffix;

        // 防路径穿越：$template 含 ../ 时可 include 模板目录之外的任意 PHP 文件（LFI），
        // 真实路径必须仍位于模板目录内；is_file 同时排除目录误判（P2-22）
        $realTemplateDir = realpath($templateDir);
        $realPath = realpath($__template_path__);
        if ($realTemplateDir === false || $realPath === false
            || !str_starts_with($realPath, $realTemplateDir . DIRECTORY_SEPARATOR)
            || !is_file($realPath)) {
            throw new RuntimeException("Template not found: {$template}.{$viewSuffix} (path: {$__template_path__})");
        }
        $__template_path__ = $realPath;

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
