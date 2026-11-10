<?php

namespace Adeliom\Roles\Sync;

use Adeliom\Roles\Declaration;

defined('ABSPATH') || exit;

/**
 * Statut affiché de la synchronisation (WP-CLI, avertissement, page de debug).
 */
final class Status
{
    /**
     * Statut affiché : invalid (roles.php invalide, rien n'est appliqué), locked (synchro en cours),
     * pending (en attente), up_to_date (version déclarée appliquée) ou not_applied (version déclarée pas
     * encore appliquée).
     *
     * @param Declaration $declaration
     * @return string
     */
    public static function current(Declaration $declaration): string
    {
        if (!$declaration->isValid()) {
            return 'invalid';
        }
        if (Lock::isLocked()) {
            return 'locked';
        }
        $state = State::get();
        if (isset($state['status']) && $state['status'] === 'pending') {
            return 'pending';
        }
        if (isset($state['applied_version']) && (int) $state['applied_version'] === $declaration->version()) {
            return 'up_to_date';
        }

        return 'not_applied';
    }

    /**
     * Libellé français d'un statut.
     *
     * @param string $status
     * @return string
     */
    public static function label(string $status): string
    {
        $labels = array(
            'locked' => "Verrouillée\u{00A0}: synchronisation en cours",
            'pending' => 'En attente',
            'up_to_date' => 'Synchro à jour',
            'not_applied' => 'Non appliquée',
            'invalid' => 'Déclaration invalide',
        );

        return isset($labels[$status]) ? $labels[$status] : $status;
    }

    /**
     * Date d'application au format français (13h30).
     *
     * @param int $timestamp
     * @return string
     */
    public static function formatDate($timestamp): string
    {
        return wp_date('d/m/Y à H\hi', (int) $timestamp);
    }
}
