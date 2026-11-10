<?php

namespace Adeliom\Roles\Sync;

use Adeliom\Roles\Declaration;
use Adeliom\Roles\Environment;
use Adeliom\Roles\Ure\UreBackup;
use Adeliom\Roles\Ure\UreCleaner;

defined('ABSPATH') || exit;

/**
 * Synchronisation des rôles avec la déclaration, accrochée à init (priorité 1).
 */
final class Synchronizer
{
    /** @var Declaration */
    private $declaration;

    public function __construct(Declaration $declaration)
    {
        $this->declaration = $declaration;
    }

    /**
     * Synchronise les rôles si la version déclarée n'est pas encore appliquée.
     *
     * Valeurs renvoyées :
     * - up_to_date : la version déclarée est déjà appliquée, rien n'est fait ;
     * - synced : la déclaration vient d'être appliquée ;
     * - pending : en production, avant la première application, la base diffère de l'empreinte attendue,
     *   rien n'est appliqué (l'écart est enregistré dans l'état, sans réécriture tant qu'il ne change pas).
     *   Une fois une version appliquée, les versions suivantes s'appliquent directement, production comprise ;
     * - locked : une autre requête tient le verrou, rien n'est fait ;
     * - invalid : la déclaration est invalide, rien n'est fait (garde-fou : Plugin n'accroche pas la
     *   synchro dans ce cas).
     *
     * @return string
     */
    public function maybeSync(): string
    {
        if (!$this->declaration->isValid()) {
            return 'invalid';
        }

        $version = $this->declaration->version();
        $state = State::get();

        if (isset($state['applied_version']) && (int) $state['applied_version'] === $version) {
            // Retour à la version déjà appliquée (code revenu en arrière) : l'attente enregistrée n'a plus
            // d'objet, on l'efface pour retirer l'avertissement (seule écriture de ce cas).
            if (isset($state['status']) && $state['status'] === 'pending') {
                $state['status'] = 'synced';
                $state['diff'] = array();
                State::save($state);
            }

            return 'up_to_date';
        }

        // Lecture seule : un écart déjà enregistré à l'identique ne provoque aucune écriture.
        $diff = $this->productionDiff($state);
        $already_pending = isset($state['status'], $state['diff']) && $state['status'] === 'pending';
        if ($diff && $already_pending && $state['diff'] === $diff) {
            return 'pending';
        }

        if (!Lock::acquire()) {
            return 'locked';
        }

        try {
            $this->reload();
            $state = State::get();
            if (isset($state['applied_version']) && (int) $state['applied_version'] === $version) {
                return 'up_to_date';
            }

            // En production, première application seulement : rien n'est appliqué si la base a changé depuis l'audit.
            $diff = $this->productionDiff($state);
            if ($diff) {
                $state['status'] = 'pending';
                $state['diff'] = $diff;
                State::save($state);

                return 'pending';
            }

            if (empty($state['ure_cleaned'])) {
                $this->backupRoles();
                UreBackup::backup();
                UreCleaner::clean();
                $state['ure_cleaned'] = true;
            }

            $this->apply($this->declaration->roles());

            $state['applied_version'] = $version;
            $state['applied_at'] = time();
            $state['status'] = 'synced';
            $state['diff'] = array();
            State::save($state);

            return 'synced';
        } finally {
            Lock::release();
        }
    }

    /**
     * Écart de production entre l'empreinte attendue et la base.
     *
     * Vide hors production, et vide dès qu'une version a déjà été appliquée : le contrôle d'empreinte ne
     * concerne que la première application en production. Ensuite, URE étant retiré, les rôles ne changent
     * plus que par le code, et une empreinte figée bloquerait toute évolution. Sans risque : la synchro ne
     * retire que les caps listées dans remove.
     *
     * @param array $state État de la synchro (State::get()).
     * @return array
     */
    public function productionDiff(array $state): array
    {
        if (Environment::type() !== 'production' || !empty($state['applied_version'])) {
            return array();
        }

        return Fingerprint::diff($this->declaration->expected(), Fingerprint::snapshot($this->declaration));
    }

    /**
     * Recharge les rôles et l'état depuis la base (et non depuis le cache), une fois le verrou pris.
     */
    private function reload(): void
    {
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete(wp_roles()->role_key, 'options');
        wp_cache_delete(State::OPTION, 'options');
        wp_roles()->for_site();
    }

    /**
     * Sauvegarde l'option des rôles avant la première application (jamais écrasée ensuite).
     */
    private function backupRoles(): void
    {
        add_option('adeliom_roles_backup', get_option(wp_roles()->role_key), '', 'no');
    }

    /**
     * Applique la déclaration : rôles manquants, caps à garantir, caps à retirer, noms affichés.
     * Les rôles des utilisateurs ne sont jamais modifiés.
     *
     * @param array $roles
     */
    private function apply(array $roles): void
    {
        $wp_roles = wp_roles();

        foreach ($roles as $slug => $role) {
            $caps = isset($role['caps']) ? $role['caps'] : array();
            $remove = isset($role['remove']) ? $role['remove'] : array();

            $name = isset($role['name']) ? (string) $role['name'] : null;

            if (!$wp_roles->is_role($slug)) {
                add_role($slug, $name !== null ? $name : $slug, $caps);
            }

            $current = $wp_roles->roles[$slug]['capabilities'];
            foreach ($caps as $cap => $grant) {
                if (!array_key_exists($cap, $current) || (bool) $current[$cap] !== (bool) $grant) {
                    get_role($slug)->add_cap($cap, (bool) $grant);
                }
            }
            foreach ($remove as $cap) {
                if (array_key_exists($cap, $wp_roles->roles[$slug]['capabilities'])) {
                    get_role($slug)->remove_cap($cap);
                }
            }

            if ($name !== null && $wp_roles->roles[$slug]['name'] !== $name) {
                $wp_roles->roles[$slug]['name'] = $name;
                $wp_roles->role_names[$slug] = $name;
                update_option($wp_roles->role_key, $wp_roles->roles);
            }
        }
    }
}
