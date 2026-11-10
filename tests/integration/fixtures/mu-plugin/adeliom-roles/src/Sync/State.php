<?php

namespace Adeliom\Roles\Sync;

defined('ABSPATH') || exit;

/**
 * État de la synchronisation (option adeliom_roles_state) : applied_version, applied_at, status, diff,
 * ure_cleaned, et declaration_errors tant que roles.php est invalide.
 */
final class State
{
    public const OPTION = 'adeliom_roles_state';

    /**
     * État de la synchronisation.
     *
     * @return array
     */
    public static function get(): array
    {
        $state = get_option(self::OPTION, array());

        return is_array($state) ? $state : array();
    }

    /**
     * Enregistre l'état (option non chargée automatiquement).
     *
     * @param array $state
     */
    public static function save(array $state): void
    {
        update_option(self::OPTION, $state, false);
    }
}
