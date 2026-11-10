<?php

namespace Adeliom\Roles\Cli;

use Adeliom\Roles\Environment;
use Adeliom\Roles\Plugin;
use Adeliom\Roles\Sync\Fingerprint;
use Adeliom\Roles\Sync\State;
use Adeliom\Roles\Sync\Status;
use WP_CLI;

defined('ABSPATH') || exit;

/**
 * Rôles et permissions gérés par le mu-plugin Adeliom.
 *
 * Sortie technique (lue aussi par les recettes de déploiement) : espaces simples avant les deux-points.
 */
final class RolesCommand
{
    /**
     * Affiche la version déclarée, la version appliquée, le statut, la date, l'environnement,
     * la version du moteur et les écarts.
     *
     * ## EXAMPLES
     *
     *     wp adeliom-roles status
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function status($args, $assoc_args)
    {
        $declaration = Plugin::instance()->declaration();
        $state = State::get();

        if (!$declaration->isValid()) {
            WP_CLI::line('Déclaration invalide : rien n\'est appliqué tant que roles.php n\'est pas corrigé.');
            foreach ($declaration->errors() as $error) {
                WP_CLI::line('  ' . $error);
            }
            WP_CLI::line('Version appliquée : ' . (isset($state['applied_version']) ? (int) $state['applied_version'] : 'aucune'));
            WP_CLI::line('Version du moteur : ' . ADELIOM_ROLES_ENGINE_VERSION);
            WP_CLI::error('Déclaration des rôles invalide (roles.php).');
        }

        $status = Status::current($declaration);

        WP_CLI::line('Version déclarée : ' . $declaration->version());
        WP_CLI::line('Version appliquée : ' . (isset($state['applied_version']) ? (int) $state['applied_version'] : 'aucune'));
        WP_CLI::line('Statut : ' . $status . ' (' . Status::label($status) . ')');
        WP_CLI::line('Date : ' . (!empty($state['applied_at']) ? Status::formatDate($state['applied_at']) : 'aucune'));
        WP_CLI::line('Environnement : ' . Environment::type());
        WP_CLI::line('Version du moteur : ' . ADELIOM_ROLES_ENGINE_VERSION);

        $diff = $status === 'pending' && !empty($state['diff']) ? $state['diff'] : Fingerprint::declarationDiff($declaration);
        if (!$diff) {
            WP_CLI::line('Écarts : aucun');

            return;
        }
        WP_CLI::line('Écarts : ' . count($diff) . ($status === 'pending' ? ' (empreinte de la production)' : ' (code et base)'));
        foreach ($diff as $row) {
            WP_CLI::line('  ' . Fingerprint::diffLine($row));
        }
    }

    /**
     * Affiche l'empreinte actuelle de la base (rôles existants, caps surveillées) et son sha256.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp adeliom-roles fingerprint --format=json
     *
     * @param array $args
     * @param array $assoc_args
     */
    public function fingerprint($args, $assoc_args)
    {
        $declaration = Plugin::instance()->declaration();
        if (!$declaration->isValid()) {
            WP_CLI::error('Déclaration des rôles invalide (roles.php) : ' . implode(' ', $declaration->errors()));
        }

        $snapshot = Fingerprint::sortKeys(Fingerprint::snapshot($declaration));
        $hash = Fingerprint::hash($snapshot);
        $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        if (isset($assoc_args['format']) && $assoc_args['format'] === 'json') {
            WP_CLI::line(json_encode(array('fingerprint' => $snapshot, 'sha256' => $hash), $flags));

            return;
        }
        WP_CLI::line('sha256 : ' . $hash);
        WP_CLI::line(json_encode($snapshot, $flags));
    }
}
