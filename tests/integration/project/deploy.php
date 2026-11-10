<?php

/*
 * deploy.php du projet de test de la suite d'intégration. Inventaire fixe (six hôtes sur deux
 * serveurs SSH) et quelques réglages pilotés par des variables d'environnement HDR_*, que seuls
 * les scénarios positionnent.
 */

namespace Deployer;

require 'recipe/common.php';
require_once __DIR__ . '/vendor/agence-adeliom/horizon-deployer-recipe/recipe/horizon-transfer.php';

set('application', 'integration');
set('repository', '/srv/git/project.git');
set('keep_releases', 100);
set('theme', 'demo');
set('shared_files', ['.env']);
// web/wp : le cœur WordPress, installé une fois sur chaque serveur et partagé entre les releases
// (un vrai projet Bedrock l'obtient par Composer).
set('shared_dirs', ['web/app/uploads', 'web/wp']);
set('writable_dirs', []);

$hosts = [
    // alias => [serveur, deploy_path]. staging et recette partagent le compte deployer@server1 :
    // c'est le cas « même serveur » du recipe.
    'staging' => ['server1', '/srv/staging'],
    'recette' => ['server1', '/srv/recette'],
    'production' => ['server2', '/srv/production'],
    'reseau-a' => ['server2', '/srv/reseau-a'],
    'reseau-b' => ['server1', '/srv/reseau-b'],
    // Jamais préparé par setup.sh : sert au scénario du tout premier déploiement.
    'neuf' => ['server1', '/srv/neuf'],
];

foreach ($hosts as $alias => [$hostname, $path]) {
    host($alias)
        ->setHostname($hostname)
        ->setRemoteUser('deployer')
        ->setIdentityFile('~/.ssh/id_ed25519')
        ->setSshArguments(['-o StrictHostKeyChecking=no', '-o UserKnownHostsFile=/dev/null', '-o LogLevel=ERROR'])
        ->set('deploy_path', $path)
        ->set('branch', 'main');
}

// Comme la plupart des projets Horizon : un déploiement en échec relâche le verrou.
after('deploy:failed', 'deploy:unlock');

// Réglages surchargés par les scénarios.
$knobs = [
    'HDR_BACKUP_KEEP' => static fn (string $v) => set('transfer_backup_keep', (int) $v),
    'HDR_BACKUP_ON_DEPLOY' => static fn (string $v) => set('transfer_backup_on_deploy', $v === '1'),
    'HDR_BACKUP_DIR' => static fn (string $v) => set('transfer_backup_dir', $v),
    'HDR_BUILD_CHECK' => static fn (string $v) => set('build_check', $v === '1'),
    'HDR_UPDATE_DB' => static fn (string $v) => set('transfer_update_db', $v === '1'),
    'HDR_ROLES_CHECK' => static fn (string $v) => set('roles_check', $v === '1'),
    'HDR_BEFORE_IMPORT' => static fn (string $v) => set('transfer_before_import', [$v]),
    'HDR_AFTER_IMPORT' => static fn (string $v) => set('transfer_after_import', [$v]),
    'HDR_REUSE_WINDOW' => static fn (string $v) => set('transfer_reuse_window', (int) $v),
];

foreach ($knobs as $name => $apply) {
    if (($value = getenv($name)) !== false && $value !== '') {
        $apply($value);
    }
}
