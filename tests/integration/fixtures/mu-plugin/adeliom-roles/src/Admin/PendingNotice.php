<?php

namespace Adeliom\Roles\Admin;

use Adeliom\Roles\Declaration;
use Adeliom\Roles\Sync\State;

defined('ABSPATH') || exit;

/**
 * Avertissement « synchro en attente » (toujours actif, indépendant de la page de debug).
 */
final class PendingNotice
{
    /** @var Declaration */
    private $declaration;

    public function __construct(Declaration $declaration)
    {
        $this->declaration = $declaration;
    }

    /**
     * Action admin_notices : bandeau rouge sur toutes les pages d'administration, pour manage_options,
     * tant que la synchro attend.
     */
    public function display(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $state = State::get();
        if (!isset($state['status']) || $state['status'] !== 'pending') {
            return;
        }
        // La page de debug affiche déjà son propre bandeau.
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && $screen->id === DebugPage::SCREEN) {
                return;
            }
        }

        echo View::render('pending-notice', array(
            'link' => DebugPage::enabledFor($this->declaration) ? admin_url('tools.php?page=' . DebugPage::SLUG) : '',
        )); // Vue échappée.
    }
}
