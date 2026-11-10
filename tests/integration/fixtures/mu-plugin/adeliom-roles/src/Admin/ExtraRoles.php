<?php

namespace Adeliom\Roles\Admin;

use Adeliom\Roles\Declaration;

defined('ABSPATH') || exit;

/**
 * Rôles supplémentaires (actifs seulement si « extra_roles_ui » est vrai dans la déclaration).
 *
 * Reprend l'option « Autres rôles » de User Role Editor : un compte garde un rôle principal (le
 * premier) et peut en cumuler d'autres. WordPress ne gère qu'un rôle par formulaire : set_role()
 * efface les autres, d'où la restauration sur profile_update.
 */
final class ExtraRoles
{
    /** @var Declaration */
    private $declaration;

    public function __construct(Declaration $declaration)
    {
        $this->declaration = $declaration;
    }

    /**
     * Rôles supplémentaires d'un compte : tous les rôles sauf le premier (le rôle principal).
     *
     * @param array $roles Rôles d'un utilisateur, dans l'ordre.
     * @return array
     */
    public static function extraOf(array $roles): array
    {
        return array_values(array_slice(array_values($roles), 1));
    }

    /**
     * Remplace les rôles supplémentaires d'un compte. Le rôle principal (le premier) ne change pas.
     * Seuls les rôles que l'utilisateur courant peut attribuer (get_editable_roles) sont ajoutés ou retirés :
     * les rôles déjà présents mais non attribuables (par exemple « administrator ») sont conservés.
     *
     * @param int   $userId Compte à modifier.
     * @param array $roles  Slugs des rôles supplémentaires voulus.
     */
    public function save(int $userId, array $roles): void
    {
        $user = get_userdata($userId);
        if (!$user) {
            return;
        }
        $editable = array_keys(get_editable_roles());
        $main = $user->roles ? reset($user->roles) : '';
        $wanted = array();
        foreach ($roles as $role) {
            $role = (string) $role;
            if ($role !== $main && in_array($role, $editable, true)) {
                $wanted[] = $role;
            }
        }

        $user = new \WP_User($userId);
        foreach ($user->roles as $role) {
            if ($role !== $main && in_array($role, $editable, true) && !in_array($role, $wanted, true)) {
                $user->remove_role($role);
            }
        }
        foreach ($wanted as $role) {
            if (!in_array($role, $user->roles, true)) {
                $user->add_role($role);
            }
        }
    }

    /**
     * Remet à un compte les rôles supplémentaires qu'il avait avant une modification (sans jamais en ajouter d'autres).
     *
     * @param int   $userId   Compte concerné.
     * @param array $oldRoles Rôles avant la modification, rôle principal en premier.
     */
    public function restore(int $userId, array $oldRoles): void
    {
        $user = new \WP_User($userId);
        if (!$user->exists()) {
            return;
        }
        foreach (self::extraOf($oldRoles) as $role) {
            if (!in_array($role, $user->roles, true) && wp_roles()->is_role($role)) {
                $user->add_role($role);
            }
        }
    }

    /**
     * Le formulaire envoyé porte-t-il une sélection de rôles supplémentaires valide (nonce) pour un utilisateur autorisé ?
     * Retourne la liste des rôles cochés, ou null si le champ n'a pas été envoyé ou si la demande est refusée.
     *
     * @param int $userId Compte visé (0 pour une création).
     * @return array|null
     */
    public function posted(int $userId): ?array
    {
        if (!isset($_POST['adeliom_roles_extra_nonce'])) {
            return null;
        }
        $nonce = sanitize_text_field(wp_unslash($_POST['adeliom_roles_extra_nonce']));
        if (!wp_verify_nonce($nonce, 'adeliom_roles_extra') || !current_user_can('promote_users')) {
            return null;
        }
        if ($userId && !current_user_can('promote_user', $userId)) {
            return null;
        }
        $posted = isset($_POST['adeliom_roles_extra']) ? wp_unslash($_POST['adeliom_roles_extra']) : array();

        return array_values(array_map('sanitize_text_field', (array) $posted));
    }

    /**
     * Action profile_update (priorité 20) : applique la sélection envoyée, sinon conserve les rôles
     * supplémentaires d'avant. L'ancien état vient de $old_user_data (lu avant que set_role() n'efface les rôles).
     * Un rôle explicitement vide (« Aucun rôle ») est respecté : rien n'est restauré ni ajouté.
     *
     * @param int           $user_id
     * @param \WP_User|null $old_user_data
     * @param array         $userdata
     */
    public function onProfileUpdate($user_id, $old_user_data = null, $userdata = array()): void
    {
        if (is_array($userdata) && isset($userdata['role']) && $userdata['role'] === '') {
            return;
        }
        $old_roles = ($old_user_data && !empty($old_user_data->roles)) ? (array) $old_user_data->roles : array();
        $posted = $this->posted((int) $user_id);
        if ($posted === null) {
            $this->restore((int) $user_id, $old_roles);

            return;
        }
        // Sélection envoyée : le rôle principal est celui que le cœur vient de définir ; on ne remet que les
        // anciens extras que l'utilisateur courant ne peut pas attribuer (ils ne figuraient pas dans les cases).
        $editable = array_keys(get_editable_roles());
        $kept = array();
        foreach (self::extraOf($old_roles) as $role) {
            if (!in_array($role, $editable, true)) {
                $kept[] = $role;
            }
        }
        array_unshift($kept, '');
        $this->restore((int) $user_id, $kept);
        $this->save((int) $user_id, $posted);
    }

    /**
     * Action user_register (priorité 20) : rôles cochés, sinon rôles supplémentaires par défaut de la déclaration.
     *
     * @param int $user_id
     */
    public function onUserRegister($user_id): void
    {
        $posted = $this->posted(0);
        if ($posted !== null) {
            // Formulaire envoyé : la sélection fait foi, même vide (comme User Role Editor).
            $this->save((int) $user_id, $posted);

            return;
        }
        $user = new \WP_User((int) $user_id);
        foreach ($this->declaration->defaultExtraRoles() as $role) {
            // Jamais « administrator » par défaut : un compte créé par un formulaire public ne doit pas l'obtenir.
            if ($role !== 'administrator' && wp_roles()->is_role($role) && !in_array($role, $user->roles, true)) {
                $user->add_role($role);
            }
        }
    }

    /**
     * Action set_user_role : changement de rôle en masse (Utilisateurs › « Changer le rôle en… », sauf
     * « Aucun rôle »). set_role() efface les autres rôles sans passer par profile_update, donc on les remet.
     *
     * @param int    $user_id
     * @param string $role
     * @param array  $old_roles
     */
    public function onSetRole($user_id, $role, $old_roles): void
    {
        global $pagenow;
        // Le formulaire envoie « changeit » et « new_role » (le champ « action » est celui des actions groupées).
        if ($pagenow !== 'users.php' || !isset($_REQUEST['changeit'], $_REQUEST['new_role']) || (string) $role === '') {
            return;
        }
        $this->restore((int) $user_id, (array) $old_roles);
    }

    /**
     * Actions edit_user_profile et user_new_form : cases à cocher « Rôles supplémentaires ».
     *
     * @param \WP_User|string $user Compte modifié (objet sur la fiche, chaîne « add-new-user » à la création).
     */
    public function render($user = null): void
    {
        if (!current_user_can('promote_users')) {
            return;
        }
        $is_user = $user instanceof \WP_User;
        $current = $is_user ? $user->roles : array();
        $main = $current ? reset($current) : '';
        if (!$is_user) {
            // Création : on pré-coche les rôles supplémentaires par défaut de la déclaration.
            $current = $this->declaration->defaultExtraRoles();
        }
        $choices = array();
        foreach (get_editable_roles() as $slug => $details) {
            if ($slug === $main) {
                continue;
            }
            $choices[] = array(
                'slug' => (string) $slug,
                'name' => translate_user_role($details['name']),
                'checked' => in_array($slug, $current, true),
            );
        }

        echo View::render('extra-roles', array('choices' => $choices)); // Vue échappée.
    }
}
