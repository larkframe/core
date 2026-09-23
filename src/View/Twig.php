<?php


namespace LarkFrame\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use function array_merge;
use function config;

class Twig implements View
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
        try {
            return static::doRender($template, $vars, $viewSuffix);
        } finally {
            // 必须覆盖所有退出路径（含模板缺失、缓存目录创建失败等前置抛出）：
            // ViewVarHolder::$vars 是静态属性，异常时残留的上一请求变量
            // 会被合并进下一个请求的渲染变量（跨请求数据泄漏）
            ViewVarHolder::clear();
        }
    }

    /**
     * 实际渲染逻辑（异常由 render() 统一兜底并清理变量表）。
     */
    private static function doRender(string $template, array $vars, ?string $viewSuffix): string
    {
        static $views = [];
        if ($viewSuffix == null) {
            $viewSuffix = config("view.options.view_suffix", 'html');
        }
        $viewPath = ROOT_PATH . DIRECTORY_SEPARATOR . "template" . DIRECTORY_SEPARATOR;
        // 与 Raw 引擎行为对齐：缺失模板抛异常 fail-fast，而不是以 HTTP 200 返回错误文案
        // is_file 排除目录误判（file_exists 对目录也返回 true）
        if (!is_file($viewPath . $template . "." . $viewSuffix)) {
            throw new \RuntimeException("Template not found: {$template}.{$viewSuffix} (path: {$viewPath}{$template}.{$viewSuffix})");
        }
        if (!isset($views[$viewPath])) {
            $options = config("view.options", []);
            // P2-46：未显式配置 cache 时默认开启模板编译缓存，避免每次请求重新编译
            if (!array_key_exists('cache', $options)) {
                $cacheDir = runtime_path('twig_cache');
                if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
                    throw new \RuntimeException("Cannot create Twig cache directory: {$cacheDir}");
                }
                $options['cache'] = $cacheDir;
            }
            $views[$viewPath] = new Environment(new FilesystemLoader($viewPath), $options);
            $extension = config("view.extension");
            if ($extension) {
                $extension($views[$viewPath]);
            }
        }

        // Merge view vars from holder with render-time vars
        $allVars = array_merge(ViewVarHolder::getVars(), $vars);

        return $views[$viewPath]->render("$template.$viewSuffix", $allVars);
    }
}
