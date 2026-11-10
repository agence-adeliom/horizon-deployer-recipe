<?php
/**
 * Plugin Name: Adeliom - Rôles
 * Description: Rôles et permissions déclarés dans le code (remplace User Role Editor). Fichier géré par Adeliom.
 * Author: Adeliom
 *
 * Chargeur du mu-plugin adeliom-roles, généré par le skill wordpress-remplacer-ure. Pour modifier un rôle,
 * modifier adeliom-roles/roles.php et augmenter « version » : la synchronisation s'applique au chargement suivant.
 * Ne rien modifier d'autre : le moteur (adeliom-roles/src, views, data) est identique sur tous les sites.
 */

defined('ABSPATH') || exit;

/*
 * Démarrage, une fois tous les mu-plugins chargés (muplugins_loaded) : l'ancien mu-plugin d'un seul fichier
 * (moteur 1.x) peut avoir été laissé par erreur sous un autre nom, chargé avant ou après ce fichier. S'il est
 * présent, le nouveau moteur ne démarre pas (aucune double synchronisation) et les administrateurs sont avertis.
 */
$adeliom_roles_start = static function () {
    if (function_exists('adeliom_roles_declaration') || function_exists('adeliom_roles_maybe_sync')) {
        add_action('admin_notices', static function () {
            if (!current_user_can('manage_options')) {
                return;
            }
            echo '<div class="notice notice-error"><p><strong>' . esc_html("Rôles (Adeliom)\u{00A0}: l’ancien mu-plugin adeliom-roles d’un seul fichier est encore chargé.") . '</strong> '
                . esc_html('Le nouveau moteur (dossier adeliom-roles) ne démarre pas tant qu’il est présent. Supprimer l’ancien fichier des mu-plugins.')
                . '</p></div>';
        });

        return;
    }

    require_once __DIR__ . '/adeliom-roles/src/Autoloader.php';
    \Adeliom\Roles\Autoloader::register(__DIR__ . '/adeliom-roles/src');
    \Adeliom\Roles\Plugin::boot();
};

if (did_action('muplugins_loaded')) {
    $adeliom_roles_start();
} else {
    add_action('muplugins_loaded', $adeliom_roles_start, 0);
}
unset($adeliom_roles_start);
