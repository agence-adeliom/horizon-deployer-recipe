<?php

namespace Adeliom\Roles\Ure;

defined('ABSPATH') || exit;

/**
 * Sauvegarde des données de User Role Editor avant leur nettoyage (option adeliom_roles_ure_backup).
 */
final class UreBackup
{
    /**
     * Sauvegarde, avant le nettoyage, tout ce que UreCleaner::clean() supprime (jamais écrasée ensuite) :
     * options, caps ure_* des rôles et des utilisateurs, plugins actifs. Sert au retour arrière vers une
     * version du site sans le mu-plugin (scripts/restore-ure.php du skill wordpress-remplacer-ure).
     */
    public static function backup(): void
    {
        $options = array();
        foreach (UreCleaner::optionNames() as $option) {
            $value = get_option($option, null);
            if ($value !== null) {
                $options[$option] = $value;
            }
        }
        ksort($options);

        $role_caps = array();
        foreach (wp_roles()->roles as $slug => $role) {
            foreach ($role['capabilities'] as $cap => $grant) {
                if (strpos($cap, 'ure_') === 0) {
                    $role_caps[$slug][$cap] = (bool) $grant;
                }
            }
        }

        $wp_roles = wp_roles();
        $user_caps = array();
        foreach (UreCleaner::userIds() as $user_id) {
            $user = new \WP_User($user_id);
            foreach ($user->caps as $cap => $grant) {
                if (strpos($cap, 'ure_') === 0 && !$wp_roles->is_role($cap)) {
                    $user_caps[$user_id][$cap] = (bool) $grant;
                }
            }
        }

        add_option('adeliom_roles_ure_backup', array(
            'saved_at' => time(),
            'options' => $options,
            'role_caps' => $role_caps,
            'user_caps' => $user_caps,
            'plugins' => UreCleaner::activePlugins(),
        ), '', 'no');
    }
}
