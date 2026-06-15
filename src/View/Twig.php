<?php


namespace LarkFrame\View;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use function array_merge;
use function config;
use function is_array;

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
        static $views = [];
        if ($viewSuffix == null) {
            $viewSuffix = config("view.options.view_suffix", 'html');
        }
        $viewPath = ROOT_PATH . DIRECTORY_SEPARATOR . "template" . DIRECTORY_SEPARATOR;
        if (!file_exists($viewPath . $template . "." . $viewSuffix)) {
            return "template " . $template . "." . $viewSuffix . " not found";
        }
        if (!isset($views[$viewPath])) {
            $views[$viewPath] = new Environment(new FilesystemLoader($viewPath), config("view.options", []));
            $extension = config("view.extension");
            if ($extension) {
                $extension($views[$viewPath]);
            }
        }

        // Merge view vars from holder with render-time vars
        $allVars = array_merge(ViewVarHolder::getVars(), $vars);

        $result = $views[$viewPath]->render("$template.$viewSuffix", $allVars);

        // Clear assigned vars after rendering
        ViewVarHolder::clear();

        return $result;
    }
}
