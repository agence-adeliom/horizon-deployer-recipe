<?php

namespace Adeliom\Roles\Admin;

use Adeliom\Roles\CapOrigins;
use Adeliom\Roles\Declaration;
use Adeliom\Roles\Sync\Fingerprint;
use Adeliom\Roles\Sync\State;
use Adeliom\Roles\Sync\Status;

defined('ABSPATH') || exit;

/**
 * Page de debug Outils › Rôles (Adeliom), en lecture seule, pour manage_options.
 *
 * Active si « debug_page » est vrai dans la déclaration, ou si la déclaration est invalide (diagnostic).
 * Styles natifs du back-office uniquement ; cette classe prépare les données, views/debug-page.php les échappe.
 */
final class DebugPage
{
    public const SLUG = 'adeliom-roles';

    public const SCREEN = 'tools_page_adeliom-roles';

    /** @var Declaration */
    private $declaration;

    public function __construct(Declaration $declaration)
    {
        $this->declaration = $declaration;
    }

    /**
     * La page est-elle active pour cette déclaration ?
     *
     * @param Declaration $declaration
     * @return bool
     */
    public static function enabledFor(Declaration $declaration): bool
    {
        return $declaration->debugPage() || !$declaration->isValid();
    }

    /**
     * Action admin_menu : enregistre la page Outils › Rôles (Adeliom).
     *
     * @return string|false Identifiant de l'écran.
     */
    public function register()
    {
        return add_management_page('Rôles (Adeliom)', 'Rôles (Adeliom)', 'manage_options', self::SLUG, array($this, 'output'));
    }

    /**
     * Affiche la page.
     */
    public function output(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        echo $this->render(); // Vue échappée.
    }

    /**
     * HTML de la page : résumé, écarts, rôles et permissions, utilisateurs particuliers.
     *
     * @return string
     */
    public function render(): string
    {
        $declaration = $this->declaration;
        $valid = $declaration->isValid();
        $state = State::get();
        $status = Status::current($declaration);
        $special = $this->specialUsers();
        $multi = 0;
        foreach ($special as $user) {
            if (count($user['roles']) > 1) {
                $multi++;
            }
        }

        return View::render('debug-page', array(
            'errors' => $declaration->errors(),
            'pending' => $status === 'pending',
            'status_label' => Status::label($status),
            'date' => !empty($state['applied_at']) ? Status::formatDate($state['applied_at']) : 'jamais',
            'applied' => isset($state['applied_version']) ? (string) (int) $state['applied_version'] : 'aucune',
            'declared' => $valid ? (string) $declaration->version() : 'invalide',
            'multi' => number_format_i18n($multi),
            'engine' => ADELIOM_ROLES_ENGINE_VERSION,
            'production_diff' => isset($state['status']) && $state['status'] === 'pending' && !empty($state['diff']) ? self::diffRows($state['diff']) : array(),
            'declaration_diff' => $valid ? self::diffRows(Fingerprint::declarationDiff($declaration)) : null,
            'roles_table' => $this->rolesTable(),
            'special' => $this->specialRows($special),
        ));
    }

    /**
     * Utilisateurs particuliers : plusieurs rôles ou caps individuelles. Une seule requête sur les métas.
     *
     * @return array user_id => array(roles, caps)
     */
    public function specialUsers(): array
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s ORDER BY user_id",
            $wpdb->get_blog_prefix() . 'capabilities'
        ));
        $wp_roles = wp_roles();
        $users = array();
        foreach ($rows as $row) {
            $stored = maybe_unserialize($row->meta_value);
            if (!is_array($stored)) {
                continue;
            }
            $roles = array();
            $caps = array();
            foreach ($stored as $key => $value) {
                if ($wp_roles->is_role($key)) {
                    $roles[] = (string) $key;
                } else {
                    $caps[(string) $key] = (bool) $value;
                }
            }
            if (count($roles) > 1 || $caps) {
                $users[(int) $row->user_id] = array('roles' => $roles, 'caps' => $caps);
            }
        }

        return $users;
    }

    /**
     * Lignes d'écart en texte : role, cap (null pour une ligne de rôle), expected, actual.
     *
     * @param array $diff
     * @return array
     */
    private static function diffRows(array $diff): array
    {
        $rows = array();
        foreach ($diff as $row) {
            $is_role = $row['cap'] === null;
            $rows[] = array(
                'role' => (string) $row['role'],
                'cap' => $is_role ? null : (string) $row['cap'],
                'expected' => Fingerprint::diffValue($row['expected'], $is_role),
                'actual' => Fingerprint::diffValue($row['actual'], $is_role),
            );
        }

        return $rows;
    }

    /**
     * Tableau rôles et permissions : colonnes (rôles) et lignes (caps triées par origine, valeur par rôle).
     *
     * @return array columns : liste de array(slug, name) ; rows : liste de array(cap, origin, values).
     */
    private function rolesTable(): array
    {
        $roles = wp_roles()->roles;
        $origins = new CapOrigins($this->declaration);
        $caps = array();
        foreach ($roles as $role) {
            foreach (array_keys((array) $role['capabilities']) as $cap) {
                $caps[(string) $cap] = $origins->originOf((string) $cap);
            }
        }
        uksort($caps, function ($a, $b) use ($caps) {
            $order = array('core' => 0, 'adeliom' => 1, 'inconnue' => 3);
            $oa = isset($order[$caps[$a]]) ? $order[$caps[$a]] : 2;
            $ob = isset($order[$caps[$b]]) ? $order[$caps[$b]] : 2;

            return $oa !== $ob ? $oa - $ob : strcmp($caps[$a] . $a, $caps[$b] . $b);
        });

        $columns = array();
        foreach ($roles as $slug => $role) {
            $columns[] = array('slug' => (string) $slug, 'name' => translate_user_role($role['name']));
        }
        $rows = array();
        foreach ($caps as $cap => $origin) {
            $values = array();
            foreach ($roles as $role) {
                $value = '';
                if (array_key_exists($cap, (array) $role['capabilities'])) {
                    $value = $role['capabilities'][$cap] ? 'Oui' : 'Refusée';
                }
                $values[] = $value;
            }
            $rows[] = array('cap' => (string) $cap, 'origin' => CapOrigins::label($origin), 'values' => $values);
        }

        return array('columns' => $columns, 'rows' => $rows);
    }

    /**
     * Lignes du tableau des utilisateurs particuliers.
     *
     * @param array $special Résultat de specialUsers().
     * @return array liste de array(login, link, main, extra, individual).
     */
    private function specialRows(array $special): array
    {
        $rows = array();
        foreach ($special as $user_id => $user) {
            $data = get_userdata($user_id);
            $individual = array();
            foreach ($user['caps'] as $cap => $grant) {
                $individual[] = $cap . ($grant ? '' : ' (refusée)');
            }
            $rows[] = array(
                'login' => $data ? $data->user_login : '#' . $user_id,
                'link' => (string) get_edit_user_link($user_id),
                'main' => $user['roles'] ? $user['roles'][0] : '',
                'extra' => implode(', ', array_slice($user['roles'], 1)),
                'individual' => implode(', ', $individual),
            );
        }

        return $rows;
    }
}
