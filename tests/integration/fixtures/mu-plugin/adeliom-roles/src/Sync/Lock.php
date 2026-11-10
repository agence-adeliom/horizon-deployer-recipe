<?php

namespace Adeliom\Roles\Sync;

defined('ABSPATH') || exit;

/**
 * Verrou de synchronisation (option adeliom_roles_lock), en SQL direct (sans l'API des options ni le cache
 * objet) pour qu'il soit atomique. Un verrou de plus de 60 secondes est considéré périmé et repris.
 */
final class Lock
{
    /**
     * Prend le verrou de synchronisation.
     *
     * @return bool
     */
    public static function acquire(): bool
    {
        global $wpdb;

        $now = time();
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('adeliom_roles_lock', %s, 'no')",
            $now
        ));
        if ((int) $wpdb->rows_affected === 1) {
            return true;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'adeliom_roles_lock' AND CAST(option_value AS UNSIGNED) < %d",
            $now,
            $now - 60
        ));

        return (int) $wpdb->rows_affected === 1;
    }

    /**
     * Libère le verrou de synchronisation.
     */
    public static function release(): void
    {
        global $wpdb;

        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name = 'adeliom_roles_lock'");
        wp_cache_delete('adeliom_roles_lock', 'options');
    }

    /**
     * Une synchronisation est-elle en cours (verrou de moins de 60 secondes) ? Lecture SQL directe.
     *
     * @return bool
     */
    public static function isLocked(): bool
    {
        global $wpdb;

        $since = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'adeliom_roles_lock'");

        return $since !== null && (int) $since >= time() - 60;
    }
}
