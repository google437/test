<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $template, array $vars = [], ?string $layout = 'layout'): void
    {
        $vars['user'] = $vars['user'] ?? Auth::user();
        $vars['org'] = $vars['org'] ?? Auth::org();
        $content = self::partial($template, $vars);
        if ($layout === null) {
            echo $content;
            return;
        }
        $vars['content'] = $content;
        echo self::partial($layout, $vars);
    }

    public static function partial(string $template, array $vars = []): string
    {
        $file = APP_ROOT . '/src/Views/' . $template . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: $template");
        }
        extract($vars, EXTR_SKIP);
        ob_start();
        try {
            require $file;
        } finally {
            $out = ob_get_clean();
        }
        return (string) $out;
    }
}
