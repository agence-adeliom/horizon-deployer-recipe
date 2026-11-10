<?php

namespace Adeliom\Roles\Admin;

use Adeliom\Roles\Declaration;
use Adeliom\Roles\Sync\State;

defined('ABSPATH') || exit;

/**
 * Déclaration invalide : erreurs enregistrées dans l'état (declaration_errors) et bandeau « déclaration invalide »
 * (symétrique de PendingNotice). Plugin branche record() et display() si roles.php est invalide, clear() sinon.
 */
final class InvalidNotice
{
    /** @var Declaration */
    private $declaration;

    public function __construct(Declaration $declaration)
    {
        $this->declaration = $declaration;
    }

    /**
     * Action init : écrit les erreurs de la déclaration invalide dans l'état (seulement si elles changent).
     */
    public function record(): void
    {
        $state = State::get();
        $errors = $this->declaration->errors();
        if (isset($state['declaration_errors']) && $state['declaration_errors'] === $errors) {
            return;
        }
        $state['declaration_errors'] = $errors;
        State::save($state);
    }

    /**
     * Action init, déclaration valide : efface les erreurs d'une déclaration invalide précédente (seule écriture de
     * ce cas).
     */
    public function clear(): void
    {
        $state = State::get();
        if (!array_key_exists('declaration_errors', $state)) {
            return;
        }
        unset($state['declaration_errors']);
        State::save($state);
    }

    /**
     * Action admin_notices : bandeau rouge sur toutes les pages d'administration, pour manage_options, tant que
     * roles.php est invalide. Pas sur la page de debug, qui affiche déjà les erreurs (comme PendingNotice).
     */
    public function display(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && $screen->id === DebugPage::SCREEN) {
                return;
            }
        }

        echo View::render('invalid-notice', array('errors' => $this->declaration->errors())); // Vue échappée.
    }
}
