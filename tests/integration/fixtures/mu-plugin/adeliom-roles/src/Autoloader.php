<?php

namespace Adeliom\Roles;

defined('ABSPATH') || exit;

/**
 * Chargement automatique minimal (PSR-4, sans Composer) : Adeliom\Roles\Sync\State => src/Sync/State.php.
 */
final class Autoloader
{
    private const PREFIX = 'Adeliom\\Roles\\';

    /** @var string|null */
    private static $directory = null;

    /**
     * Enregistre le chargement automatique des classes du moteur (une seule fois).
     *
     * @param string $directory Dossier src/ du mu-plugin.
     */
    public static function register(string $directory): void
    {
        if (self::$directory !== null) {
            return;
        }
        self::$directory = rtrim($directory, '/\\');
        spl_autoload_register(array(self::class, 'load'));
    }

    /**
     * Charge une classe du namespace Adeliom\Roles.
     *
     * @param string $class
     */
    public static function load(string $class): void
    {
        // Seuls les noms de classe PHP ordinaires sont convertis en chemin (jamais de ., / ni ..).
        if (strpos($class, self::PREFIX) !== 0 || preg_match('/^[A-Za-z0-9_\\\\]+$/', $class) !== 1) {
            return;
        }
        $file = self::$directory . '/' . str_replace('\\', '/', substr($class, strlen(self::PREFIX))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
}
