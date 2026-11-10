<?php

/*
 * Configuration WordPress du projet de test, à la manière de Bedrock (config/application.php)
 * mais sans dépendance : le .env de la racine est lu à la main, la première occurrence d'une
 * variable l'emporte, WP_ENV vide ou absent vaut production, et WP_ENVIRONMENT_TYPE est déduit
 * de WP_ENV s'il n'est pas fourni.
 */

$root_dir = dirname(__DIR__);
$hdr_env = [];

foreach ((array) @file($root_dir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (preg_match('/^\s*(?:export\s+)?([A-Z_]+)\s*=\s*(["\']?)(.*?)\2\s*$/', (string) $line, $m) && !array_key_exists($m[1], $hdr_env)) {
        $hdr_env[$m[1]] = $m[3];
    }
}

$hdr_get = static function (string $key, $default = null) use ($hdr_env) {
    return array_key_exists($key, $hdr_env) ? $hdr_env[$key] : $default;
};

define('WP_ENV', $hdr_get('WP_ENV') ?: 'production');

if ($hdr_get('WP_ENVIRONMENT_TYPE')) {
    define('WP_ENVIRONMENT_TYPE', $hdr_get('WP_ENVIRONMENT_TYPE'));
} elseif (in_array(WP_ENV, ['production', 'staging', 'development', 'local'], true)) {
    define('WP_ENVIRONMENT_TYPE', WP_ENV);
}

define('WP_HOME', $hdr_get('WP_HOME'));
define('WP_SITEURL', WP_HOME . '/wp');
define('WP_CONTENT_DIR', __DIR__ . '/app');
define('WP_CONTENT_URL', WP_HOME . '/app');

define('DB_NAME', $hdr_get('DB_NAME'));
define('DB_USER', $hdr_get('DB_USER', 'wp'));
define('DB_PASSWORD', $hdr_get('DB_PASSWORD', 'wp'));
define('DB_HOST', $hdr_get('DB_HOST', 'db'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = $hdr_get('DB_PREFIX', 'wp_');

if ($hdr_get('MULTISITE') === 'true') {
    define('WP_ALLOW_MULTISITE', true);
    define('MULTISITE', true);
    define('SUBDOMAIN_INSTALL', true);
    define('DOMAIN_CURRENT_SITE', $hdr_get('DOMAIN_CURRENT_SITE'));
    define('PATH_CURRENT_SITE', '/');
    define('SITE_ID_CURRENT_SITE', 1);
    define('BLOG_ID_CURRENT_SITE', 1);
}

// Valeurs de test, sans secret.
foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'] as $hdr_salt) {
    define($hdr_salt, 'integration-' . $hdr_salt);
}

define('AUTOMATIC_UPDATER_DISABLED', true);
define('DISABLE_WP_CRON', true);
define('WP_DEBUG', false);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/wp/');
}

require_once ABSPATH . 'wp-settings.php';
