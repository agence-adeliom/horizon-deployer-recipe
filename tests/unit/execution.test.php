<?php
/*
 * Exécution indifférenciée local / distant : transferLegacyRunApi, transferExec, transferRun,
 * transferWpRun, transferTest, transferResolve, transferResolvePath, transferSize, transferTryLocally,
 * transferRunWatched.
 */

namespace Deployer;

cas('transferLegacyRunApi : détecte l\'API de run()', static function (): void {
    verifie('Deployer 7 : tableau d\'options', transferLegacyRunApi(), versionDeployer() === 7);
});

cas('transferExec : options traduites pour les deux API', static function (): void {
    transferExec(true, 'echo local', array('timeout' => 1800));
    $appel = uneCommande('runLocally', '/^echo local$/');
    verifie('local', $appel['env'] ?? null, 'local');
    verifie('délai transmis', $appel['timeout'] ?? null, 1800);

    transferExec(true, 'echo defaut');
    if (versionDeployer() === 8) {
        verifie('Deployer 8 : délai par défaut de Deployer 7 conservé en local', Shell::appel('/^echo defaut$/')['timeout'] ?? null, 300);
    }

    dans(hote('distant'), static function (): void {
        transferExec(false, 'echo distant', array('timeout' => 14400, 'real_time_output' => true));
    });
    $appel = uneCommande('run', '/^echo distant$/');
    vrai('distant', ($appel['env'] ?? '') !== 'local');
    verifie('délai', $appel['timeout'] ?? null, 14400);
    verifie('sortie en temps réel', $appel['temps_reel'] ?? null, true);

    if (versionDeployer() === 8) {
        leve('option inconnue refusée sous Deployer 8', static function (): void {
            transferExec(true, 'echo x', array('tty' => true));
        }, \InvalidArgumentException::class, 'non prise(s) en charge sous Deployer 8 : tty');
    }
});

cas('transferRun et transferWpRun : environnement et répertoire', static function (): void {
    $h = hote('staging');
    Shell::quand('/^lire$/', "  valeur  \n");

    verifie('sortie rendue (rtrim par Deployer)', transferRun($h, 'lire'), '  valeur');
    verifie('transferRun distant : sur l\'hôte', Shell::$appels[0]['env'], $h->getAlias());
    verifie('transferRun distant : sans cd', Shell::$appels[0]['cwd'], null);

    transferRun(null, 'lire');
    verifie('transferRun local', Shell::$appels[1]['env'], 'local');

    transferWpRun($h, 'wp option get home');
    verifie('transferWpRun distant : depuis la release en ligne', Shell::$appels[2]['cwd'], '/srv/' . $h->getAlias() . '/current');

    transferWpRun(null, 'wp option get home');
    verifie('transferWpRun local : runLocally', Shell::$appels[3]['env'], 'local');
    verifie('transferWpRun local : sans cd', Shell::$appels[3]['cwd'], null);
});

cas('transferTest : marqueur +true', static function (): void {
    $h = hote('staging');
    Shell::quand('/\[ -d \/oui \]/', '+true');

    vrai('vrai', transferTest($h, '[ -d /oui ]'));
    vrai('faux', !transferTest($h, '[ -d /non ]'));
    verifie('commande', Shell::$appels[0]['commande'], 'if [ -d /oui ]; then echo +true; fi');
});

cas('transferResolve : placeholders résolus dans le contexte de l\'hôte visé', static function (): void {
    $h = hote('staging', array('deploy_path' => '/var/www/staging'));

    verifie('hôte visé', transferResolve($h, '{{deploy_path}}/x'), '/var/www/staging/x');
    verifie('null : hôte courant', transferResolve(null, '{{deploy_path}}/x'), '/srv/' . Suite::$courant->getAlias() . '/x');
    verifie('aucune commande', Shell::commandes(), array());
});

cas('transferResolvePath : « ~ » remplacé par le HOME de l\'environnement, lu une fois', static function (): void {
    $h = hote('mutualise', array('deploy_path' => '~/www'));
    Shell::quand('/^printf %s "\$HOME"$/', '/home/client');

    verifie('« ~/ » développé', transferResolvePath($h, '{{deploy_path}}/.dep'), '/home/client/www/.dep');
    verifie('« ~ » seul', transferResolvePath($h, '~'), '/home/client');
    verifie('HOME lu une seule fois', count(Shell::commandes()), 1);
    verifie('chemin absolu : inchangé, sans commande', transferResolvePath($h, '/abs'), '/abs');
    verifie('toujours une seule commande', count(Shell::commandes()), 1);

    $sansHome = hote('sans-home', array('deploy_path' => '~/www'));
    Shell::reinitialiser();
    verifie('HOME vide : chemin gardé', transferResolvePath($sansHome, '{{deploy_path}}'), '~/www');
    verifie('« ~utilisateur » non traité', transferResolvePath($sansHome, '~autre/x'), '~autre/x');
});

cas('transferSize', static function (): void {
    $h = hote('staging');
    Shell::quand('/^du /', " 12M\n");

    verifie('taille rognée', transferSize($h, "/srv/a b/c'd"), '12M');
    verifie('chemin échappé', Shell::$appels[0]['commande'], "du -h '/srv/a b/c'\\''d' | cut -f1");
    transferSize($h, '/x', true);
    verifie('résumé : du -hs', Shell::$appels[1]['commande'], "du -hs '/x' | cut -f1");
});

cas('transferTryLocally : jamais d\'exception', static function (): void {
    Shell::quand('/^casse$/', new \RuntimeException('code 1'));
    Shell::quand('/^vide$/', "  \n");
    Shell::quand('/^ok$/', " 3.2 \n");

    verifie('échec : null', transferTryLocally('casse'), null);
    verifie('vide : null', transferTryLocally('vide'), null);
    verifie('valeur rognée', transferTryLocally('ok'), '3.2');
    verifie('toujours en local', array_unique(array_column(Shell::$appels, 'env')), array('local'));
});

cas('transferRunWatched : tâche de fond, wait, options', static function (): void {
    $h = hote('staging');
    transferRunWatched($h, "wp db export '/tmp/d.sql'", '/tmp/d b.sql', 'dump SQL :');

    $appel = Shell::$appels[0];
    contient('commande en tâche de fond', $appel['commande'], "( wp db export '/tmp/d.sql' ) &");
    contient('code de sortie propagé', $appel['commande'], 'wait $dep_pid');
    contient('fichier surveillé échappé', $appel['commande'], "du -h '/tmp/d b.sql' | cut -f1");
    contient('libellé', $appel['commande'], '… dump SQL : $(du');
    verifie('délai par défaut', $appel['timeout'], 7200);
    verifie('temps réel', $appel['temps_reel'], true);
    verifie('depuis la release', $appel['cwd'], '/srv/' . $h->getAlias() . '/current');

    transferRunWatched($h, 'x', '/f', 'l', 60);
    verifie('délai surchargé', Shell::$appels[1]['timeout'], 60);
});

cas('transferRunWatched : le code de sortie de la commande est propagé (exécution réelle)', static function (): void {
    Shell::$reel = true;
    $dossier = dossierTemporaire();

    transferRunWatched(null, "echo ok > '$dossier/f'", "$dossier/f", 'fichier :');
    verifie('succès : fichier écrit', trim((string) file_get_contents("$dossier/f")), 'ok');

    leve('échec : exception', static function () use ($dossier): void {
        transferRunWatched(null, 'exit 3', "$dossier/g", 'fichier :');
    }, \RuntimeException::class, 'code 3');
});
