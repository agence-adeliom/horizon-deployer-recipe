<?php

namespace Adeliom\Roles;

defined('ABSPATH') || exit;

/**
 * Environnement courant.
 */
final class Environment
{
    /**
     * Environnement courant (filtrable par adeliom_roles_environment, notamment pour les tests).
     *
     * @return string
     */
    public static function type(): string
    {
        return (string) apply_filters('adeliom_roles_environment', wp_get_environment_type());
    }
}
