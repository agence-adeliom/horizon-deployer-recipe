<?php

namespace Adeliom\Roles\Sync;

use Adeliom\Roles\Declaration;

defined('ABSPATH') || exit;

/**
 * Empreinte des rôles et écarts.
 *
 * diff(), hash() et sortKeys() sont des fonctions pures, sans WordPress : les scripts du skill les appellent
 * hors WordPress (ils définissent ABSPATH avant de charger ce fichier).
 */
final class Fingerprint
{
    /**
     * État actuel de la base, au format de expected : seules les caps surveillées sont lues.
     *
     * @param Declaration $declaration
     * @return array
     */
    public static function snapshot(Declaration $declaration): array
    {
        $roles = wp_roles()->roles;
        $slugs = array_keys($roles);
        sort($slugs);

        $expected = $declaration->expected();
        $watched = isset($expected['caps']) ? $expected['caps'] : array();
        $caps = array();
        foreach ($watched as $slug => $role_caps) {
            $current = isset($roles[$slug]['capabilities']) ? $roles[$slug]['capabilities'] : array();
            $caps[$slug] = array();
            foreach (array_keys($role_caps) as $cap) {
                $caps[$slug][$cap] = array_key_exists($cap, $current) ? (bool) $current[$cap] : null;
            }
        }

        return array('roles' => $slugs, 'caps' => $caps);
    }

    /**
     * Écarts entre l'empreinte attendue et l'état actuel.
     * Ligne de rôle : cap null, expected et actual indiquent si le rôle existe.
     * Ligne de cap : expected et actual valent true, false ou null (cap absente).
     *
     * @param array $expected
     * @param array $actual
     * @return array
     */
    public static function diff(array $expected, array $actual): array
    {
        $diff = array();

        $expected_roles = isset($expected['roles']) ? $expected['roles'] : array();
        $actual_roles = isset($actual['roles']) ? $actual['roles'] : array();
        $all_roles = array_unique(array_merge($expected_roles, $actual_roles));
        sort($all_roles);
        foreach ($all_roles as $slug) {
            $in_expected = in_array($slug, $expected_roles, true);
            $in_actual = in_array($slug, $actual_roles, true);
            if ($in_expected !== $in_actual) {
                $diff[] = array('role' => $slug, 'cap' => null, 'expected' => $in_expected, 'actual' => $in_actual);
            }
        }

        $expected_caps = isset($expected['caps']) ? $expected['caps'] : array();
        foreach ($expected_caps as $slug => $caps) {
            foreach ($caps as $cap => $value) {
                $current = isset($actual['caps'][$slug]) && array_key_exists($cap, $actual['caps'][$slug])
                    ? $actual['caps'][$slug][$cap]
                    : null;
                if ($current !== $value) {
                    $diff[] = array('role' => $slug, 'cap' => $cap, 'expected' => $value, 'actual' => $current);
                }
            }
        }

        return $diff;
    }

    /**
     * Trie récursivement les clés d'un tableau associatif (les listes gardent leur ordre).
     *
     * @param mixed $value
     * @return mixed
     */
    public static function sortKeys($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if ($value && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value, SORT_STRING);
        }

        return array_map(array(self::class, 'sortKeys'), $value);
    }

    /**
     * Empreinte (sha256) d'un état au format de expected : JSON aux clés triées.
     *
     * @param array $snapshot
     * @return string
     */
    public static function hash(array $snapshot): string
    {
        return hash('sha256', json_encode(self::sortKeys($snapshot), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Écarts entre la déclaration (le code) et la base : rôle absent, cap déclarée différente,
     * cap à retirer encore présente. Même format de ligne que diff().
     *
     * @param Declaration $declaration
     * @return array
     */
    public static function declarationDiff(Declaration $declaration): array
    {
        $roles = wp_roles()->roles;
        $diff = array();
        foreach ($declaration->roles() as $slug => $role) {
            if (!isset($roles[$slug])) {
                $diff[] = array('role' => $slug, 'cap' => null, 'expected' => true, 'actual' => false);
                continue;
            }
            $current = (array) $roles[$slug]['capabilities'];
            foreach (isset($role['caps']) ? $role['caps'] : array() as $cap => $grant) {
                $actual = array_key_exists($cap, $current) ? (bool) $current[$cap] : null;
                if ($actual !== (bool) $grant) {
                    $diff[] = array('role' => $slug, 'cap' => $cap, 'expected' => (bool) $grant, 'actual' => $actual);
                }
            }
            foreach (isset($role['remove']) ? $role['remove'] : array() as $cap) {
                if (array_key_exists($cap, $current)) {
                    $diff[] = array('role' => $slug, 'cap' => $cap, 'expected' => null, 'actual' => (bool) $current[$cap]);
                }
            }
        }

        return $diff;
    }

    /**
     * Valeur d'une ligne d'écart en texte : true, false ou absente (ou présent / absent pour un rôle).
     *
     * @param bool|null $value
     * @param bool      $is_role
     * @return string
     */
    public static function diffValue($value, bool $is_role): string
    {
        if ($is_role) {
            return $value ? 'présent' : 'absent';
        }
        if ($value === null) {
            return 'absente';
        }

        return $value ? 'true' : 'false';
    }

    /**
     * Ligne d'écart en texte, par exemple « gestionnaire read : attendu true, actuel absente ».
     *
     * @param array $row
     * @return string
     */
    public static function diffLine(array $row): string
    {
        $is_role = $row['cap'] === null;

        return $row['role'] . ($is_role ? ' (rôle)' : ' ' . $row['cap'])
            . ' : attendu ' . self::diffValue($row['expected'], $is_role)
            . ', actuel ' . self::diffValue($row['actual'], $is_role);
    }
}
