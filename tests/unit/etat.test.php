<?php
/*
 * État de nettoyage et répertoires de travail : transferStateFile, transferReadState, transferResetState,
 * transferRegisterWorkdir, transferWorkdir, la tâche transfer:cleanup et transferReuseLocalArchive.
 */

namespace Deployer;

cas('état : fichier, lecture, ajout, remise à zéro', static function (): void {
    $racine = (string) getenv('DEPLOYER_ROOT');
    verifie('emplacement', transferStateFile(), "$racine/.dep/transfer-state.json");
    verifie('absent : vide', transferReadState(), array());

    $h = hote('staging');
    transferRegisterWorkdir(null, '/tmp/a');
    transferRegisterWorkdir($h, '/srv/b/.dep/transfer/c');
    verifie('entrées accumulées', transferReadState(), array(
        array('env' => null, 'workdir' => '/tmp/a'),
        array('env' => $h->getAlias(), 'workdir' => '/srv/b/.dep/transfer/c'),
    ));
    contient('JSON lisible, barres non échappées', (string) file_get_contents(transferStateFile()), '"workdir": "/srv/b/.dep/transfer/c"');

    transferResetState();
    vrai('supprimé', !is_file(transferStateFile()));
    transferResetState();
    verifie('remise à zéro idempotente', transferReadState(), array());

    file_put_contents(transferStateFile(), '{corrompu');
    verifie('JSON illisible : vide', transferReadState(), array());
});

cas('transferWorkdir : un répertoire unique par environnement, orphelins purgés', static function (): void {
    $racine = (string) getenv('DEPLOYER_ROOT');
    $h = hote('staging');

    $local = transferWorkdir(null);
    verifie('local : sous .dep/transfer du projet', preg_match('#^' . preg_quote($racine, '#') . '/\.dep/transfer/\d{8}-\d{6}-[0-9a-f]{6}$#', $local), 1);
    verifie('création', Shell::$appels[0]['commande'], 'mkdir -p ' . escapeshellarg($local));
    verifie(
        'purge des seuls dossiers horodatés de plus d\'un jour',
        Shell::$appels[1]['commande'],
        'find ' . escapeshellarg("$racine/.dep/transfer") . ' -mindepth 1 -maxdepth 1 -type d -name "20*-*-*" -mtime +1 -exec rm -rf {} + 2>/dev/null || true'
    );
    contient('annoncé', sortie(), "Répertoire temporaire (local) : $local");

    verifie('réutilisé ensuite', transferWorkdir(null), $local);
    verifie('sans nouvelle commande', count(Shell::$appels), 2);

    $distant = transferWorkdir($h);
    verifie('distant : sous transfer_tmp_dir de l\'hôte', preg_match('#^/srv/' . preg_quote($h->getAlias(), '#') . '/\.dep/transfer/\d{8}-\d{6}-[0-9a-f]{6}$#', $distant), 1);
    verifie('créé sur l\'hôte', Shell::$appels[2]['env'], $h->getAlias());
    verifie('les deux enregistrés', array_column(transferReadState(), 'env'), array(null, $h->getAlias()));

    Shell::reinitialiser();
    $mutualise = hote('mutualise', array('deploy_path' => '~/site'));
    Shell::quand('/HOME/', '/home/client');
    contient('« ~ » développé (jamais un dossier nommé « ~ »)', transferWorkdir($mutualise), '/home/client/site/.dep/transfer/');
});

cas('transferWorkdir : exécution réelle de la purge', static function (): void {
    Shell::$reel = true;
    $racine = (string) getenv('DEPLOYER_ROOT');
    $base = "$racine/.dep/transfer";
    mkdir("$base/20200101-000000-abcdef", 0777, true);
    mkdir("$base/20200101-000000-recent");
    mkdir("$base/ancien-mais-pas-a-nous");
    Shell::bash("touch -t 202001010000 '$base/20200101-000000-abcdef' '$base/ancien-mais-pas-a-nous'");

    $workdir = transferWorkdir(null);
    vrai('créé', is_dir($workdir));
    vrai('orphelin horodaté de plus d\'un jour supprimé', !is_dir("$base/20200101-000000-abcdef"));
    vrai('horodaté récent gardé', is_dir("$base/20200101-000000-recent"));
    vrai('dossier étranger gardé', is_dir("$base/ancien-mais-pas-a-nous"));
});

cas('transfer:cleanup : tous les environnements, hôte inconnu signalé, état remis à zéro', static function (): void {
    $h = hote('staging');
    transferRegisterWorkdir(null, '/tmp/projet/.dep/transfer/20260101-000000-aaaaaa');
    transferRegisterWorkdir($h, '/srv/s/.dep/transfer/20260101-000000-bbbbbb');
    transferRegisterWorkdir(hote('fantome'), '/srv/f/x');
    Deployer::get()->hosts->remove(transferReadState()[2]['env']);

    tache('transfer:cleanup', Suite::$courant);

    verifie('commandes', array_map(static function (array $a): string {
        return $a['env'] . ' : ' . $a['commande'];
    }, Shell::$appels), array(
        "local : rm -rf '/tmp/projet/.dep/transfer/20260101-000000-aaaaaa'",
        "local : rmdir '/tmp/projet/.dep/transfer' 2>/dev/null || true",
        $h->getAlias() . " : rm -rf '/srv/s/.dep/transfer/20260101-000000-bbbbbb'",
        $h->getAlias() . " : rmdir '/srv/s/.dep/transfer' 2>/dev/null || true",
    ));
    contient('hôte introuvable signalé', sortie(), "/srv/f/x n'a pas été nettoyé");
    verifie('état remis à zéro', transferReadState(), array());
});

cas('transfer:cleanup : exécution réelle, dossier parent retiré s\'il est vide', static function (): void {
    Shell::$reel = true;
    $premier = transferWorkdir(null);
    file_put_contents("$premier/db.sql.gz", 'x');

    tache('transfer:cleanup', Suite::$courant);
    vrai('répertoire de travail supprimé', !is_dir($premier));
    vrai('parent vide supprimé', !is_dir(dirname($premier)));

    $second = transferWorkdir(null);
    mkdir(dirname($second) . '/autre');
    tache('transfer:cleanup', Suite::$courant);
    vrai('parent non vide gardé', is_dir(dirname($second)));
});

/**
 * Archive gzip (intacte ou tronquée) à la racine du projet, datée de $age secondes.
 */
function etatArchive(string $nom, int $age, bool $intacte = true): string
{
    $chemin = getenv('DEPLOYER_ROOT') . '/' . $nom;
    $donnees = gzencode(str_repeat('INSERT INTO t VALUES (1);', 200));
    file_put_contents($chemin, $intacte ? $donnees : substr($donnees, 0, (int) (strlen($donnees) / 2)));
    touch($chemin, time() - $age);

    return $chemin;
}

cas('transferReuseLocalArchive : conditions pour proposer la réutilisation', static function (): void {
    etatArchive('db-prod-20260101-000000.sql.gz', 60);

    verifie('-n : jamais proposé', transferReuseLocalArchive('db-prod-*.sql.gz', 'Un dump'), null);

    entree(array(), true, array('y'));
    Suite::$courant->set('transfer_reuse_window', 0);
    verifie('fenêtre 0 : jamais proposé', transferReuseLocalArchive('db-prod-*.sql.gz', 'Un dump'), null);

    Suite::$courant->set('transfer_reuse_window', 30);
    verifie('hors fenêtre : null', transferReuseLocalArchive('db-prod-*.sql.gz', 'Un dump'), null);

    Suite::$courant->set('transfer_reuse_window', 3600);
    verifie('aucun fichier du motif : null', transferReuseLocalArchive('db-staging-*.sql.gz', 'Un dump'), null);
    verifie('aucune question posée jusqu\'ici', sortie(), '');
});

cas('transferReuseLocalArchive : le plus récent par date de modification, gzip -t avant réutilisation', static function (): void {
    Shell::$reel = true;
    etatArchive('db-prod-20991231-235959.sql.gz', 7200);
    $recent = etatArchive('db-prod-20200101-000000.sql.gz', 120);

    entree(array(), true, array('y'));
    verifie('réutilisé', transferReuseLocalArchive('db-prod-*.sql.gz', 'Un dump de « prod »'), $recent);
    $sortie = sortie();
    contient('le plus récent par mtime, pas par nom', $sortie, './db-prod-20200101-000000.sql.gz');
    contient('âge en minutes', $sortie, 'Un dump de « prod » datant de 2 min est déjà là');
    uneCommande('intégrité vérifiée', '/^if gzip -t /');

    entree(array(), true, array('n'));
    verifie('refus : null', transferReuseLocalArchive('db-prod-*.sql.gz', 'Un dump'), null);

    entree(array(), true, array());
    verifie('défaut : non', transferReuseLocalArchive('db-prod-*.sql.gz', 'Un dump'), null);

    etatArchive('uploads-prod-1.tar.gz', 5400, false);
    Suite::$courant->set('transfer_reuse_window', 7200);
    sortie();
    entree(array(), true, array('y'));
    verifie('archive tronquée : ignorée', transferReuseLocalArchive('uploads-prod-*.tar.gz', 'Une archive'), null);
    $sortie = sortie();
    contient('âge en heures', $sortie, 'datant de 1h30');
    contient('avertissement', $sortie, 'incomplet ou corrompu');
});
