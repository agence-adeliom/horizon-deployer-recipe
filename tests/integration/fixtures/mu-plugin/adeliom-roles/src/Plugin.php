<?php

namespace Adeliom\Roles;

use Adeliom\Roles\Admin\AdminProtection;
use Adeliom\Roles\Admin\DebugPage;
use Adeliom\Roles\Admin\ExtraRoles;
use Adeliom\Roles\Admin\InvalidNotice;
use Adeliom\Roles\Admin\PendingNotice;
use Adeliom\Roles\Cli\RolesCommand;
use Adeliom\Roles\Sync\Synchronizer;

defined('ABSPATH') || exit;

if (!defined('ADELIOM_ROLES_ENGINE_VERSION')) {
    define('ADELIOM_ROLES_ENGINE_VERSION', '2.0.0');
}

/**
 * Point d'entrée du moteur : lit la déclaration et branche les hooks qu'elle demande.
 *
 * Déclaration invalide : rien n'est appliqué (aucune synchronisation), l'erreur est écrite dans l'état
 * (declaration_errors) et affichée aux administrateurs (Admin\InvalidNotice) ; le site continue de fonctionner.
 */
final class Plugin
{
    /** @var Plugin|null */
    private static $instance = null;

    /** @var bool */
    private static $cli_registered = false;

    /** @var Declaration */
    private $declaration;

    /** @var Synchronizer */
    private $synchronizer;

    /** @var ExtraRoles */
    private $extraRoles;

    /** @var PendingNotice */
    private $pendingNotice;

    /** @var InvalidNotice */
    private $invalidNotice;

    /** @var DebugPage */
    private $debugPage;

    /** @var array Hooks branchés : array(hook, callback, priorité). */
    private $hooks = array();

    private function __construct(Declaration $declaration)
    {
        $this->declaration = $declaration;
        $this->synchronizer = new Synchronizer($declaration);
        $this->extraRoles = new ExtraRoles($declaration);
        $this->pendingNotice = new PendingNotice($declaration);
        $this->invalidNotice = new InvalidNotice($declaration);
        $this->debugPage = new DebugPage($declaration);
    }

    /**
     * Démarre le moteur avec la déclaration du site (roles.php, à côté de src/).
     */
    public static function boot(): void
    {
        self::bootWith(Declaration::fromFile(dirname(__DIR__) . '/roles.php'));
    }

    /**
     * Démarre le moteur avec une déclaration donnée (tests). Un moteur déjà démarré est d'abord décroché.
     *
     * @param Declaration $declaration
     * @return Plugin
     */
    public static function bootWith(Declaration $declaration): Plugin
    {
        if (self::$instance !== null) {
            self::$instance->unhook();
        }
        self::$instance = new self($declaration);
        self::$instance->hook();

        if (!self::$cli_registered && defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('adeliom-roles', RolesCommand::class);
            self::$cli_registered = true;
        }

        return self::$instance;
    }

    /**
     * Moteur démarré, ou null.
     *
     * @return Plugin|null
     */
    public static function instance(): ?Plugin
    {
        return self::$instance;
    }

    public function declaration(): Declaration
    {
        return $this->declaration;
    }

    public function synchronizer(): Synchronizer
    {
        return $this->synchronizer;
    }

    public function extraRoles(): ExtraRoles
    {
        return $this->extraRoles;
    }

    public function pendingNotice(): PendingNotice
    {
        return $this->pendingNotice;
    }

    public function invalidNotice(): InvalidNotice
    {
        return $this->invalidNotice;
    }

    public function debugPage(): DebugPage
    {
        return $this->debugPage;
    }

    /**
     * Branche les hooks selon la déclaration.
     */
    private function hook(): void
    {
        // Protection des administrateurs et avertissement d'attente : toujours actifs, même déclaration invalide.
        $this->add('editable_roles', array(AdminProtection::class, 'editableRoles'), 10);
        $this->add('map_meta_cap', array(AdminProtection::class, 'mapMetaCap'), 10, 4);
        $this->add('pre_user_query', array(AdminProtection::class, 'userQuery'), 10);
        $this->add('views_users', array(AdminProtection::class, 'views'), 10);
        $this->add('admin_notices', array($this->pendingNotice, 'display'), 10);

        // Page de debug : debug_page vrai, ou déclaration invalide (diagnostic pour les administrateurs).
        if (DebugPage::enabledFor($this->declaration)) {
            $this->add('admin_menu', array($this->debugPage, 'register'), 10);
        }

        if (!$this->declaration->isValid()) {
            $this->add('init', array($this->invalidNotice, 'record'), 1);
            $this->add('admin_notices', array($this->invalidNotice, 'display'), 10);

            return;
        }

        $this->add('init', array($this->invalidNotice, 'clear'), 1);
        $this->add('init', array($this->synchronizer, 'maybeSync'), 1);

        if ($this->declaration->extraRolesUi()) {
            $this->add('edit_user_profile', array($this->extraRoles, 'render'), 10);
            $this->add('user_new_form', array($this->extraRoles, 'render'), 10);
            $this->add('profile_update', array($this->extraRoles, 'onProfileUpdate'), 20, 3);
            $this->add('user_register', array($this->extraRoles, 'onUserRegister'), 20);
            $this->add('set_user_role', array($this->extraRoles, 'onSetRole'), 10, 3);
        }
    }

    /**
     * @param string   $hook
     * @param callable $callback
     * @param int      $priority
     * @param int      $args
     */
    private function add(string $hook, $callback, int $priority, int $args = 1): void
    {
        add_filter($hook, $callback, $priority, $args);
        $this->hooks[] = array($hook, $callback, $priority);
    }

    /**
     * Décroche tous les hooks de ce moteur.
     */
    private function unhook(): void
    {
        foreach ($this->hooks as $hook) {
            remove_filter($hook[0], $hook[1], $hook[2]);
        }
        $this->hooks = array();
    }
}
