<?php

namespace Adeliom\Roles;

defined('ABSPATH') || exit;

/**
 * Origine des permissions (utilisée par la page de debug) : core, plugin:<nom>, adeliom ou inconnue.
 *
 * Les origines connues viennent de data/cap-origins.php, copie de references/cap-origins.php du skill
 * wordpress-remplacer-ure : ne pas la modifier à la main sur un site.
 */
final class CapOrigins
{
    /** @var array|null Caps du cœur en clés, calculées une fois par requête. */
    private static $core = null;

    /** @var Declaration */
    private $declaration;

    /** @var array prefixes et core_dynamic */
    private $origins;

    /**
     * @param Declaration $declaration
     * @param array|null  $origins Origines connues (data/cap-origins.php si null).
     */
    public function __construct(Declaration $declaration, ?array $origins = null)
    {
        $this->declaration = $declaration;
        $this->origins = $origins !== null ? $origins : require dirname(__DIR__) . '/data/cap-origins.php';
    }

    /**
     * Origine d'une cap : core, plugin:<nom>, adeliom (déclarée dans roles.php) ou inconnue.
     *
     * @param string $cap
     * @return string
     */
    public function originOf(string $cap): string
    {
        $core = $this->coreCaps();
        if (isset($core[$cap])) {
            return 'core';
        }
        foreach ($this->origins['prefixes'] as $prefix => $plugin) {
            if (strpos($cap, (string) $prefix) === 0) {
                return 'plugin:' . $plugin;
            }
        }
        foreach ($this->declaration->roles() as $role) {
            if (isset($role['caps']) && array_key_exists($cap, $role['caps'])) {
                return 'adeliom';
            }
            if (isset($role['remove']) && in_array($cap, $role['remove'], true)) {
                return 'adeliom';
            }
        }

        return 'inconnue';
    }

    /**
     * Libellé d'une origine de cap.
     *
     * @param string $origin
     * @return string
     */
    public static function label(string $origin): string
    {
        if (strpos($origin, 'plugin:') === 0) {
            return 'Plugin ' . substr($origin, 7);
        }
        $labels = array('core' => 'WordPress', 'adeliom' => 'Code Adeliom', 'inconnue' => 'Inconnue');

        return isset($labels[$origin]) ? $labels[$origin] : $origin;
    }

    /**
     * Caps du cœur : celles des rôles par défaut, recalculés en mémoire (rien n'est écrit en base),
     * et les caps accordées dynamiquement par WordPress. Calculées une fois par requête.
     *
     * @return array Caps en clés.
     */
    private function coreCaps(): array
    {
        if (self::$core !== null) {
            return self::$core;
        }

        require_once ABSPATH . 'wp-admin/includes/schema.php';
        $wp_roles = wp_roles();
        $saved = array($wp_roles->use_db, $wp_roles->roles, $wp_roles->role_objects, $wp_roles->role_names);
        $wp_roles->use_db = false;
        $wp_roles->roles = array();
        $wp_roles->role_objects = array();
        $wp_roles->role_names = array();
        try {
            populate_roles();
            $defaults = $wp_roles->roles;
        } finally {
            list($wp_roles->use_db, $wp_roles->roles, $wp_roles->role_objects, $wp_roles->role_names) = $saved;
        }

        $core = array();
        foreach ($defaults as $role) {
            $core += array_fill_keys(array_keys((array) $role['capabilities']), true);
        }
        $core += array_fill_keys(array_keys($this->origins['core_dynamic']), true);
        self::$core = $core;

        return $core;
    }
}
