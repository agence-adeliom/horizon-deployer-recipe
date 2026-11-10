<?php

namespace Adeliom\Roles\Admin;

defined('ABSPATH') || exit;

/**
 * Rendu des gabarits HTML de views/ : les classes préparent les données, les vues les échappent.
 */
final class View
{
    /**
     * HTML d'une vue (views/<nom>.php), qui reçoit ses données dans $view.
     *
     * @param string $name Nom de la vue, sans extension.
     * @param array  $view Données de la vue.
     * @return string
     */
    public static function render(string $name, array $view): string
    {
        $file = dirname(__DIR__, 2) . '/views/' . $name . '.php';
        ob_start();
        try {
            (static function (string $__file, array $view) {
                require $__file;
            })($file, $view);
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
