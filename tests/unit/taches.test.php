<?php
/*
 * Les quatre tâches de transfert de bout en bout, avec un hôte déclaré via localhost() (rsync sans ssh,
 * donc identique sous Deployer 7 et 8) : enchaînement des commandes, garde-fous de -n, nettoyage.
 * Les échanges SSH réels relèvent des tests d'intégration.
 */

namespace Deployer;

/** Source ou destination « boîte » (localhost()) avec WordPress installé. */
function tachesBoite(array $config = array()): Host\Host
{
    $boite = hoteLocal('boite', $config);
    $alias = $boite->getAlias();

    Shell::quand('/core is-installed/', '+dep-installed');
    Shell::quand('/cmd-dump/', '1');
    Shell::quand('/config get WP_HOME/', 'https://boite.test', $alias);
    Shell::quand('/config get WP_HOME/', 'https://local.test', 'local');
    Shell::quand('/config get DB_NAME/', 'wp');
    Shell::quand('/^du /', '1M');
    Shell::quand('/command -v wp/', '+true');

    return $boite;
}

/** Les commandes dans l'ordre, chacune précédée de son environnement. */
function tachesJournal(): array
{
    return array_map(static function (array $a): string {
        return $a['env'] . ' : ' . $a['commande'];
    }, Shell::$appels);
}

/** Index de la première commande qui correspond, ou -1. */
function tachesRang(string $motif, ?string $env = null): int
{
    foreach (Shell::$appels as $i => $appel) {
        if (($env === null || $appel['env'] === $env) && preg_match($motif, $appel['commande']) === 1) {
            return $i;
        }
    }

    return -1;
}

cas('db:pull -n : dump exporté, téléchargé, serveur nettoyé, base locale intacte', static function (): void {
    $boite = tachesBoite();
    $alias = $boite->getAlias();
    $racine = (string) getenv('DEPLOYER_ROOT');

    tache('db:pull', $boite);
    $sortie = sortie();

    vrai('export sur la source', tachesRang('/db export/', $alias) >= 0);
    $rsync = Shell::appel('/^set -o pipefail\nrsync /');
    verifie('rsync lancé en local', $rsync['env'] ?? null, 'local');
    verifie(
        'vers la racine du projet, sans ssh',
        preg_match("#'--stats' '/srv/$alias/\\.dep/transfer/\\d{8}-\\d{6}-[0-9a-f]{6}/db\\.sql\\.gz' '" . preg_quote($racine, '#') . "/db-$alias-\\d{8}-\\d{6}\\.sql\\.gz' \\| \\{#", $rsync['commande'] ?? ''),
        1
    );
    vrai('serveur nettoyé après le téléchargement', tachesRang('/^rm -rf /', $alias) > tachesRang('/^set -o pipefail\nrsync /'));
    contient('import manuel proposé', $sortie, "Import manuel : gunzip -c db-$alias-");
    contient('préfixe ddev', $sortie, '.sql.gz | ddev exec wp db import -');
    aucuneCommande('base locale intacte', '/db (clean|import)/');
    verifie('état remis à zéro', transferReadState(), array());
});

cas('db:pull : import local, hooks, réécriture d\'URLs', static function (): void {
    // Hooks lus dans le contexte de la tâche : celui de l'hôte source pour un db:pull.
    $boite = tachesBoite(array('transfer_before_import' => array('acorn snapshot'), 'transfer_after_import' => array('acorn restore')));
    entree(array(), true, array('y', 'y'));

    tache('db:pull', $boite);

    $ordre = array(
        'hook avant' => tachesRang('/acorn snapshot/', 'local'),
        'suppression des tables' => tachesRang('/db clean/', 'local'),
        'import' => tachesRang('/db import/', 'local'),
        'réécriture' => tachesRang("/search-replace 'https:\\/\\/boite\\.test' 'https:\\/\\/local\\.test'/", 'local'),
        'hook après' => tachesRang('/acorn restore/', 'local'),
    );
    verifie('étapes présentes', count(array_filter($ordre, static function (int $i): bool {
        return $i >= 0;
    })), 5);
    $trie = $ordre;
    asort($trie);
    verifie('dans cet ordre', array_keys($trie), array_keys($ordre));
    contient('import du dump téléchargé', Shell::appel('/db import/')['commande'] ?? '', "gunzip -c '" . getenv('DEPLOYER_ROOT') . '/db-' . $boite->getAlias() . '-');
});

cas('uploads:pull -n : archive sur la source, téléchargée, jamais extraite', static function (): void {
    $boite = tachesBoite();
    $alias = $boite->getAlias();
    Shell::quand('/^if \[ -d /', '+true');
    Shell::quand('/readlink -f/', "/srv/$alias/shared/uploads");
    Shell::quand('/find .* \| wc -l/', '12');

    tache('uploads:pull', $boite);
    $sortie = sortie();

    contient('volume annoncé', $sortie, '12 fichiers, 1M à archiver');
    contient('archive des uploads réels', Shell::appel('/tar -czf/')['commande'] ?? '', "-C '/srv/$alias/shared/uploads' .");
    vrai('archive téléchargée en local', tachesRang('/^set -o pipefail\nrsync .*uploads\.tar\.gz/', 'local') >= 0);
    vrai('serveur nettoyé', tachesRang('/^rm -rf /', $alias) >= 0);
    contient('extraction manuelle proposée', $sortie, 'Extraction manuelle : mkdir -p web/app/uploads && tar -xzf uploads-' . $alias . '-');
    aucuneCommande('rien extrait', '/tar -xzf/');
});

cas('uploads:pull --favicon-only : seuls les fichiers du favicon', static function (): void {
    $boite = tachesBoite();
    $alias = $boite->getAlias();
    Shell::quand('/^if \[ -d /', '+true');
    Shell::quand('/readlink -f/', "/srv/$alias/shared/uploads");
    Shell::quand('/option get site_icon/', '5', 'local');
    Shell::quand('/_wp_attached_file/', 'icone.png', 'local');
    Shell::quand('/^ls -1 /', "/srv/$alias/shared/uploads/icone.png");
    entree(array('favicon-only' => true), true, array('y'));

    tache('uploads:pull', $boite);
    aucuneCommande('aucune analyse ni archive', '/find |tar /');
    contient('rsync filtré', Shell::appel('/rsync /')['commande'] ?? '', "'--include=icone.png' '--exclude=*' '/srv/$alias/shared/uploads/'");
});

cas('db:push -n : rien n\'est exporté ni écrasé', static function (): void {
    $boite = tachesBoite();
    entree(array('from' => 'local', 'to' => $boite->getAlias()));

    tache('db:push', Suite::$courant);
    contient('abandon', sortie(), 'Abandon, aucune modification.');
    aucuneCommande('aucun export', '/db export/');
    aucuneCommande('aucun répertoire de travail', '/^mkdir/');
    vrai('URLs lues avant la question', tachesRang('/config get WP_HOME/', $boite->getAlias()) >= 0);
});

cas('db:push : export, sauvegarde de la destination, import, nettoyage, réécriture', static function (): void {
    $boite = tachesBoite();
    $alias = $boite->getAlias();
    entree(array('from' => 'local', 'to' => $alias), true, array('y', 'n'));

    tache('db:push', Suite::$courant);

    $ordre = array(
        'export de la source' => tachesRang('/db export .*\/db\.sql/', 'local'),
        'sauvegarde de la destination' => tachesRang('/db export .*\.dep\/backups\//', $alias),
        'transfert' => tachesRang('/^set -o pipefail\nrsync /', 'local'),
        'suppression des tables' => tachesRang('/db clean/', $alias),
        'import' => tachesRang('/db import/', $alias),
        'nettoyage' => tachesRang('/^rm -rf /', $alias),
    );
    verifie('étapes présentes', count(array_filter($ordre, static function (int $i): bool {
        return $i >= 0;
    })), 6);
    $trie = $ordre;
    asort($trie);
    verifie('dans cet ordre', array_keys($trie), array_keys($ordre));
    contient('dump transféré dans le répertoire de travail de la destination', Shell::appel('/db import/')['commande'] ?? '', "/srv/$alias/.dep/transfer/");
    contient('réécriture proposée sur la destination', sortie(), "wp search-replace 'https://local.test' 'https://boite.test'");
    aucuneCommande('réécriture refusée : non exécutée', '/^wp search-replace/');
});

cas('uploads:push -n : simulation, puis aucune écriture', static function (): void {
    $boite = tachesBoite();
    Shell::quand('/^if \[ -d /', '+true');
    Shell::quand('/--dry-run/', '   → 2 fichier(s) à transférer, 0 à supprimer à la destination.');
    entree(array('from' => 'local', 'to' => $boite->getAlias()));

    tache('uploads:push', Suite::$courant);
    contient('abandon', sortie(), 'Abandon, aucune modification.');
    aucuneCommande('aucune synchronisation', '/--info=progress2/');
});

cas('uploads:push réel vers un hôte localhost() : miroir, puis rien à faire', static function (): void {
    if (!rsync3()) {
        saute('rsync 3 absent');
    }

    Shell::$reel = true;
    $racine = (string) getenv('DEPLOYER_ROOT');
    $deploy = dossierTemporaire();
    mkdir("$racine/web/app/uploads/2024", 0777, true);
    file_put_contents("$racine/web/app/uploads/2024/a.jpg", 'a');
    file_put_contents("$racine/web/app/uploads/b.jpg", 'b');
    mkdir("$deploy/current/web/app/uploads", 0777, true);
    file_put_contents("$deploy/current/web/app/uploads/obsolete.jpg", 'x');
    $boite = hoteLocal('boite', array('deploy_path' => $deploy));

    entree(array('from' => 'local', 'to' => $boite->getAlias(), 'strategy' => 'mirror'), true, array('y'));
    tache('uploads:push', Suite::$courant);
    $destination = "$deploy/current/web/app/uploads";
    verifie('miroir', array(is_file("$destination/2024/a.jpg"), is_file("$destination/b.jpg"), is_file("$destination/obsolete.jpg")), array(true, true, false));
    contient('bilan', sortie(), '2 fichiers sur « ' . $boite->getAlias() . ' »');

    entree(array('from' => 'local', 'to' => $boite->getAlias(), 'strategy' => 'mirror'), true, array());
    tache('uploads:push', Suite::$courant);
    contient('deuxième passage', sortie(), 'Rien à faire : la destination est déjà conforme à la source.');
});
