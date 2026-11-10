<?php

namespace Adeliom\Roles\Ure;

defined('ABSPATH') || exit;

/**
 * Nettoyage de User Role Editor à la première synchronisation : caps ure_* des rôles et des utilisateurs,
 * options, plugin actif. Tout ce qui est supprimé est d'abord sauvegardé par UreBackup.
 */
final class UreCleaner
{
    /**
     * Nettoie User Role Editor : caps ure_* des rôles et des utilisateurs, options, plugin actif.
     */
    public static function clean(): void
    {
        $wp_roles = wp_roles();
        foreach ($wp_roles->roles as $slug => $role) {
            foreach (array_keys($role['capabilities']) as $cap) {
                if (strpos($cap, 'ure_') === 0) {
                    get_role($slug)->remove_cap($cap);
                }
            }
        }

        foreach (self::userIds() as $user_id) {
            $user = new \WP_User($user_id);
            foreach (array_keys($user->caps) as $cap) {
                if (strpos($cap, 'ure_') === 0 && !$wp_roles->is_role($cap)) {
                    $user->remove_cap($cap);
                }
            }
        }

        foreach (self::optionNames() as $option) {
            delete_option($option);
        }

        foreach (self::activePlugins() as $plugin) {
            deactivate_plugins($plugin, true);
        }
    }

    /**
     * Options de User Role Editor supprimées par le nettoyage : ure_*, user_role_editor, <préfixe>backup_user_roles.
     *
     * @return string[]
     */
    public static function optionNames(): array
    {
        global $wpdb;

        $options = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like('ure_') . '%'
        ));
        $options[] = 'user_role_editor';
        $options[] = $wpdb->get_blog_prefix() . 'backup_user_roles';

        return array_values(array_unique($options));
    }

    /**
     * Identifiants des utilisateurs dont les caps individuelles contiennent peut-être une cap ure_*.
     *
     * @return int[]
     */
    public static function userIds(): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
            $wpdb->get_blog_prefix() . 'capabilities',
            '%' . $wpdb->esc_like('ure_') . '%'
        )));
    }

    /**
     * Plugins User Role Editor actifs (gratuit et Pro).
     *
     * @return string[]
     */
    public static function activePlugins(): array
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $active = array();
        foreach (array('user-role-editor/user-role-editor.php', 'user-role-editor-pro/user-role-editor-pro.php') as $plugin) {
            if (is_plugin_active($plugin)) {
                $active[] = $plugin;
            }
        }

        return $active;
    }
}
