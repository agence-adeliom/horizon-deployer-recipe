<?php

namespace Adeliom\Roles\Admin;

defined('ABSPATH') || exit;

/**
 * Protection des administrateurs (toujours active, indépendante de la déclaration, même invalide).
 *
 * Reprend la protection de User Role Editor pour les utilisateurs non administrateurs : sans elle,
 * un compte qui a edit_users ou promote_users pourrait se donner le rôle administrateur.
 * Elle s'applique par map_meta_cap, donc aussi hors des pages d'administration (AJAX, REST).
 */
final class AdminProtection
{
    /**
     * Décision pure : la protection s'applique-t-elle à cet utilisateur ?
     * Vraie pour tout compte non administrateur et non super admin, y compris un visiteur anonyme
     * (un formulaire public ne doit pas pouvoir attribuer « administrator »), comme User Role Editor.
     * Seule exception : WP-CLI sans utilisateur (scripts et commandes de maintenance).
     *
     * @param \WP_User|null $user
     * @param bool          $isCli
     * @return bool
     */
    public static function appliesFor(?\WP_User $user, bool $isCli): bool
    {
        $logged_in = $user && !empty($user->ID);
        if (!$logged_in) {
            return !$isCli;
        }
        if (in_array('administrator', (array) $user->roles, true)) {
            return false;
        }

        return !(is_multisite() && is_super_admin($user->ID));
    }

    /**
     * La protection s'applique-t-elle à l'utilisateur courant ?
     */
    public static function applies(): bool
    {
        return self::appliesFor(wp_get_current_user(), defined('WP_CLI') && WP_CLI);
    }

    /**
     * Le compte cible est-il protégé (utilisateur 1 ou rôle administrateur) ?
     *
     * @param int|string $userId
     * @return bool
     */
    public static function isProtected($userId): bool
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return false;
        }
        if ($userId === 1) {
            return true;
        }
        $user = get_userdata($userId);

        return $user && in_array('administrator', (array) $user->roles, true);
    }

    /**
     * Filtre editable_roles : retire « administrator » des rôles que l'on peut attribuer.
     *
     * @param array $roles
     * @return array
     */
    public static function editableRoles($roles)
    {
        if (self::applies()) {
            unset($roles['administrator']);
        }

        return $roles;
    }

    /**
     * Filtre map_meta_cap : interdit de modifier, supprimer, retirer ou promouvoir un administrateur.
     *
     * @param array  $caps
     * @param string $cap
     * @param int    $user_id
     * @param array  $args
     * @return array
     */
    public static function mapMetaCap($caps, $cap, $user_id, $args)
    {
        if (!in_array($cap, array('edit_user', 'delete_user', 'remove_user', 'promote_user'), true) || !isset($args[0])) {
            return $caps;
        }
        // Seul l'utilisateur courant est visé (la vérification pour un autre compte reste inchangée)
        // et personne n'est jamais bloqué sur son propre compte.
        if ((int) $user_id !== get_current_user_id() || (int) $args[0] === (int) $user_id || !self::applies()) {
            return $caps;
        }

        return self::isProtected($args[0]) ? array('do_not_allow') : $caps;
    }

    /**
     * Identifiants des administrateurs, lus directement en base : passer par WP_User_Query
     * rappellerait pre_user_query et bouclerait.
     *
     * @return int[]
     */
    public static function adminIds(): array
    {
        global $wpdb;

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s",
            $wpdb->get_blog_prefix() . 'capabilities',
            '%"administrator"%'
        ));
        // L'utilisateur 1 n'est masqué que s'il est administrateur (il figure alors dans la liste), comme URE ;
        // il reste protégé contre la modification dans mapMetaCap().
        return array_map('intval', (array) $ids);
    }

    /**
     * Action pre_user_query : exclut les administrateurs des listes d'utilisateurs, sur les pages
     * utilisateurs seulement (comme URE). Les sélecteurs d'auteur et autres requêtes continuent de les voir.
     *
     * @param \WP_User_Query $query
     */
    public static function userQuery($query): void
    {
        global $wpdb, $pagenow;

        if (!in_array($pagenow, array('profile.php', 'users.php', 'user-new.php', 'user-edit.php'), true)
            || !self::applies()) {
            return;
        }
        $ids = array_diff(self::adminIds(), array(get_current_user_id()));
        if ($ids) {
            $query->query_where .= " AND ( {$wpdb->users}.ID NOT IN ( " . implode(',', array_map('absint', $ids)) . ' ) )';
        }
    }

    /**
     * Filtre views_users : retire la vue « Administrateur » de la liste des utilisateurs et corrige le
     * compteur « Tous ». Travaille sur les nombres entre parenthèses, jamais sur les libellés (traduits).
     *
     * @param array $views
     * @return array
     */
    public static function views($views)
    {
        if (!self::applies() || !isset($views['administrator'])) {
            return $views;
        }

        $count = function ($html) {
            return preg_match('#\(([^)]*)\)#', $html, $m) ? array($m[1], (int) preg_replace('/\D/', '', $m[1])) : null;
        };
        $admins = $count($views['administrator']);
        $all = isset($views['all']) ? $count($views['all']) : null;
        if ($admins && $all) {
            $views['all'] = preg_replace(
                '#\(' . preg_quote($all[0], '#') . '\)#',
                '(' . number_format_i18n(max(0, $all[1] - $admins[1])) . ')',
                $views['all'],
                1
            );
        }
        unset($views['administrator']);

        return $views;
    }
}
