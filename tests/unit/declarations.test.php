<?php
/*
 * Ce que le recipe déclare en se chargeant : marqueur de version, options, valeurs par défaut, tâches,
 * points d'accroche sur `dep deploy` et tâches de nettoyage en cas d'échec.
 */

namespace Deployer;

cas('marqueur de version', static function (): void {
    vrai('HORIZON_TRANSFER_RECIPE défini', defined('HORIZON_TRANSFER_RECIPE'));
    verifie('forme x.y.z', preg_match('/^\d+\.\d+\.\d+$/', (string) HORIZON_TRANSFER_RECIPE), 1);
});

cas('options de la ligne de commande', static function (): void {
    $definition = Deployer::get()->inputDefinition;
    $attendues = array(
        'favicon-only' => false,
        'fix-collations' => false,
        'from' => true,
        'to' => true,
        'strategy' => true,
        'checksum' => false,
        'precise' => false,
    );

    foreach ($attendues as $nom => $valeur) {
        vrai("--$nom déclarée", $definition->hasOption($nom));

        if ($definition->hasOption($nom)) {
            verifie("--$nom " . ($valeur ? 'attend une valeur' : 'est un drapeau'), $definition->getOption($nom)->isValueRequired(), $valeur);
        }
    }
});

cas('valeurs par défaut', static function (): void {
    $h = hote('defauts');
    $racine = (string) getenv('DEPLOYER_ROOT');
    $attendues = array(
        'transfer_tmp_dir' => '/srv/' . $h->getAlias() . '/.dep/transfer',
        'transfer_local_tmp_dir' => "$racine/.dep/transfer",
        'transfer_backup_dir' => '/srv/' . $h->getAlias() . '/.dep/backups',
        'uploads_path' => 'web/app/uploads',
        'transfer_local_prefix' => 'ddev exec ',
        'transfer_search_replace_skip_tables' => '*_wf*',
        'transfer_reuse_window' => 3600,
        'transfer_update_db' => true,
        'transfer_before_import' => array(),
        'transfer_after_import' => array(),
        'transfer_backup_on_deploy' => true,
        'build_check' => true,
        'transfer_backup_keep' => 10,
        'transfer_wp_php_args' => "-d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED'",
        'roles_check' => true,
    );

    foreach ($attendues as $cle => $valeur) {
        verifie($cle, dans($h, static function () use ($cle) {
            return get($cle);
        }), $valeur);
    }
});

cas('tâches déclarées, visibles ou cachées, once', static function (): void {
    $taches = Deployer::get()->tasks;

    foreach (array('db:pull', 'uploads:pull', 'db:push', 'uploads:push', 'build:check', 'db:backup', 'wp:update-db', 'roles:check', 'roles:status') as $nom) {
        vrai("$nom visible", $taches->has($nom) && !$taches->get($nom)->isHidden());
        vrai("$nom décrite", $taches->has($nom) && (string) $taches->get($nom)->getDescription() !== '');
    }

    foreach (array('transfer:cleanup', 'deploy:db-backup') as $nom) {
        vrai("$nom cachée", $taches->has($nom) && $taches->get($nom)->isHidden());
    }

    vrai('db:push une seule fois quel que soit le sélecteur', $taches->get('db:push')->isOnce());
    vrai('uploads:push une seule fois', $taches->get('uploads:push')->isOnce());
    vrai('db:pull sur chaque hôte sélectionné', !$taches->get('db:pull')->isOnce());
});

cas('points d\'accroche sur dep deploy', static function (): void {
    $taches = Deployer::get()->tasks;

    verifie('avant deploy : build:check puis roles:check', $taches->get('deploy')->getBefore(), array('build:check', 'roles:check'));
    verifie('avant deploy:symlink : sauvegarde', $taches->get('deploy:symlink')->getBefore(), array('deploy:db-backup'));
    verifie('après deploy:success : wp:update-db puis roles:status', $taches->get('deploy:success')->getAfter(), array('wp:update-db', 'roles:status'));
    verifie('rien sur deploy:unlock', array($taches->get('deploy:unlock')->getBefore(), $taches->get('deploy:unlock')->getAfter()), array(array(), array()));
});

cas('nettoyage en cas d\'échec', static function (): void {
    $echecs = Deployer::get()->fail;

    foreach (array('db:pull', 'uploads:pull', 'db:push', 'uploads:push') as $nom) {
        verifie("$nom → transfer:cleanup", $echecs->has($nom) ? $echecs->get($nom) : null, 'transfer:cleanup');
    }

    vrai('wp:update-db sans nettoyage (aucun répertoire de travail)', !$echecs->has('wp:update-db'));
});

cas('chargé sans recipe/common.php : aucun point d\'accroche, aucune erreur', static function (): void {
    $script = dossierTemporaire() . '/sans-common.php';
    file_put_contents($script, '<?php
namespace Deployer;
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
require ' . var_export(AUTOLOAD_TESTS, true) . ';
new Deployer(new \Symfony\Component\Console\Application());
require ' . var_export(RECIPE_TESTE, true) . ';
$t = Deployer::get()->tasks;
echo json_encode(array($t->has("db:pull"), $t->has("deploy"), $t->has("build:check") ? $t->get("build:check")->getBefore() : null));
');

    verifie('tâches du recipe seules', Shell::bash(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script)), '[true,false,[]]');
});
