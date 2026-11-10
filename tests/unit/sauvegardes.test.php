<?php
/*
 * Sauvegardes : transferDbBackup, transferBackupRelease, transferRotateBackups, transferBackupWithRelease,
 * et les tâches db:backup et deploy:db-backup.
 */

namespace Deployer;

/** Réponse à `test('[ -d {{current_path}} ]')` : une release est en ligne (marqueur tiré au hasard). */
function sauvegardeReleaseEnLigne(): void
{
    Shell::quand('/^if \[ -d \S+\/current \]; then echo \+\w+; fi$/', static function (array $appel): string {
        return preg_match('/echo (\+\w+); fi$/', $appel['commande'], $m) === 1 ? $m[1] : '';
    });
}

/** Hôte avec une release en ligne, dont WordPress est utilisable et dont la base s'exporte. */
function sauvegardeHoteSain(): void
{
    sauvegardeReleaseEnLigne();
    Shell::quand('/core is-installed/', '+dep-installed');
    Shell::quand('/^du /', '3,1M');
}

cas('transferDbBackup : nom horodaté, dossier de sauvegarde, gzip', static function (): void {
    $h = hote('production');
    sauvegardeHoteSain();

    $chemin = transferDbBackup($h);
    $dossier = '/srv/' . $h->getAlias() . '/.dep/backups';
    verifie('nom horodaté', preg_match('#^' . preg_quote("$dossier/" . $h->getAlias(), '#') . '-\d{8}-\d{6}\.sql\.gz$#', (string) $chemin), 1);
    verifie('dossier créé', Shell::appel('/^mkdir/')['commande'] ?? null, "mkdir -p '$dossier'");
    contient('export surveillé', Shell::appel('/db export/')['commande'] ?? '', 'wp db export ' . escapeshellarg(substr((string) $chemin, 0, -3)));
    verifie('compression', Shell::appel('/^gzip/')['commande'] ?? null, 'gzip -9 ' . escapeshellarg(substr((string) $chemin, 0, -3)));
    contient('conservée', sortie(), "Conservée : $chemin (3,1M)");

    Shell::reinitialiser();
    sauvegardeHoteSain();
    verifie('nom imposé', transferDbBackup($h, 'nom-choisi'), "$dossier/nom-choisi.sql.gz");
});

cas('transferDbBackup : WordPress inutilisable, null sans exception', static function (): void {
    Shell::quand('/core is-installed/', 'Error: base vide');

    verifie('null', transferDbBackup(hote('production')), null);
    aucuneCommande('pas d\'export', '/db export/');
    $sortie = sortie();
    contient('avertissement', $sortie, 'pas de sauvegarde préalable');
    contient('diagnostic', $sortie, 'WP-CLI : Error: base vide');
});

cas('transferDbBackup : transfer_backup_dir relatif au HOME', static function (): void {
    $h = hote('mutualise', array('deploy_path' => '~/www'));
    sauvegardeHoteSain();
    Shell::quand('/HOME/', '/home/client');

    contient('« ~ » développé', (string) transferDbBackup($h, 'x'), '/home/client/www/.dep/backups/x.sql.gz');
});

cas('transferBackupRelease : release à venir, release en ligne, aucune', static function (): void {
    $h = hote('production');
    Shell::quand('/readlink/', 'upcoming 42');
    verifie('pendant le déploiement', transferBackupRelease($h), array('42', true));
    $commande = Shell::$appels[0]['commande'];
    contient('lien release lu sur le serveur', $commande, 'if [ -L /srv/' . $h->getAlias() . '/release ]');
    contient('repli sur current', $commande, 'elif [ -L /srv/' . $h->getAlias() . '/current ]');

    Shell::reinitialiser();
    Shell::quand('/readlink/', 'current 41');
    verifie('hors déploiement', transferBackupRelease($h), array('41', false));

    Shell::reinitialiser();
    verifie('premier déploiement', transferBackupRelease($h), array(null, false));
});

cas('transferBackupRelease : vrais liens symboliques', static function (): void {
    Shell::$reel = true;
    $racine = dossierTemporaire();
    mkdir("$racine/releases/12", 0777, true);
    mkdir("$racine/releases/13");
    symlink("$racine/releases/12", "$racine/current");
    $h = hote('production', array('deploy_path' => $racine));

    verifie('current seul', transferBackupRelease($h), array('12', false));

    symlink("$racine/releases/13", "$racine/release");
    verifie('release prioritaire', transferBackupRelease($h), array('13', true));
});

cas('transferRotateBackups : ne garde que les N plus récentes de l\'hôte (fichiers réels)', static function (): void {
    Shell::$reel = true;
    $racine = dossierTemporaire();
    $dossier = "$racine/.dep/backups";
    mkdir($dossier, 0777, true);
    $h = hote('production', array('deploy_path' => $racine));
    $alias = $h->getAlias();

    $fichiers = array(
        "$alias-avant-release-10-20260101-000000.sql.gz" => 500,
        "$alias-release-11-20260102-000000.sql.gz" => 400,
        "$alias-avant-release-12-20260103-000000.sql.gz" => 300,
        "$alias-avant-release-13-20260104-000000.sql.gz" => 200,
        "$alias-20250101-000000.sql.gz" => 900,
        "staging-avant-release-3-20250101-000000.sql.gz" => 900,
    );

    foreach ($fichiers as $nom => $age) {
        touch("$dossier/$nom", time() - $age);
    }

    $supprimes = transferRotateBackups($h, 2);
    verifie('les plus anciennes, par date de modification', $supprimes, array(
        "$dossier/$alias-release-11-20260102-000000.sql.gz",
        "$dossier/$alias-avant-release-10-20260101-000000.sql.gz",
    ));
    $restants = array_map('basename', glob("$dossier/*"));
    sort($restants);
    $attendus = array(
        "$alias-20250101-000000.sql.gz",
        "$alias-avant-release-12-20260103-000000.sql.gz",
        "$alias-avant-release-13-20260104-000000.sql.gz",
        'staging-avant-release-3-20250101-000000.sql.gz',
    );
    sort($attendus);
    verifie('sauvegardes d\'avant import et autres hôtes intactes', $restants, $attendus);

    verifie('rien de plus à supprimer', transferRotateBackups($h, 2), array());
    verifie('dossier absent : rien', transferRotateBackups(hote('vierge', array('deploy_path' => dossierTemporaire())), 2), array());
});

cas('transferBackupWithRelease : nom d\'après la release, rotation', static function (): void {
    $h = hote('production', array('transfer_backup_keep' => 3));
    sauvegardeHoteSain();
    Shell::quand('/readlink/', 'upcoming 42');
    Shell::quand('/^ls -1t/', "/b/4\n/b/3\n/b/2\n/b/1\n");

    // Appelée par les tâches sur l'hôte courant : transfer_backup_keep est lu dans ce contexte.
    dans($h, static function () use ($h): void {
        transferBackupWithRelease($h);
    });
    contient('nom de la release à venir', Shell::appel('/db export/')['commande'] ?? '', '/' . $h->getAlias() . '-avant-release-42-');
    verifie('motif de rotation', Shell::appel('/^ls -1t/')['commande'] ?? null, 'ls -1t /srv/' . $h->getAlias() . '/.dep/backups/' . $h->getAlias() . '-*release-*.sql.gz 2>/dev/null || true');
    verifie('la plus ancienne supprimée', Shell::appel('/^rm -f/')['commande'] ?? null, "rm -f '/b/1'");
    contient('bilan', sortie(), '1 sauvegarde(s) ancienne(s) supprimée(s), 3 conservée(s) au plus.');

    Shell::reinitialiser();
    sauvegardeHoteSain();
    Shell::quand('/readlink/', 'current 41');
    $sansRotation = hote('production', array('transfer_backup_keep' => 0));
    dans($sansRotation, static function () use ($sansRotation): void {
        transferBackupWithRelease($sansRotation);
    });
    contient('release en ligne', Shell::appel('/db export/')['commande'] ?? '', '-release-41-');
    aucuneCommande('keep 0 : pas de rotation', '/^ls -1t/');

    Shell::reinitialiser();
    sauvegardeHoteSain();
    transferBackupWithRelease(hote('production'));
    contient('release inconnue', Shell::appel('/db export/')['commande'] ?? '', '-release-inconnue-');
});

cas('transferBackupWithRelease : premier déploiement, pas de WordPress, rotation en échec', static function (): void {
    // Aucune release en ligne : ni WP-CLI (il se placerait dans {{current_path}}), ni export.
    $neuf = hote('production');
    transferBackupWithRelease($neuf);
    verifie('premier déploiement : une seule commande', count(Shell::commandes()), 1);
    contient('premier déploiement : test de current', Shell::$appels[0]['commande'] ?? '', '[ -d /srv/' . $neuf->getAlias() . '/current ]');
    contient('premier déploiement : avertissement', sortie(), 'aucune release en ligne (premier déploiement)');
    aucuneCommande('premier déploiement : pas de WP-CLI', '/core is-installed/');
    aucuneCommande('premier déploiement : pas de rotation', '/^ls -1t/');

    Shell::reinitialiser();
    sauvegardeReleaseEnLigne();
    Shell::quand('/core is-installed/', 'Error');
    transferBackupWithRelease(hote('production'));
    contient('WordPress inutilisable : avertissement', sortie(), 'aucun WordPress utilisable à dumper');
    aucuneCommande('WordPress inutilisable : pas de rotation', '/^ls -1t/');

    Shell::reinitialiser();
    sauvegardeHoteSain();
    Shell::quand('/^ls -1t/', new \RuntimeException('Permission denied'));
    transferBackupWithRelease(hote('production'));
    contient('rotation en échec : avertissement seulement', sortie(), 'impossible : Permission denied');

    Shell::reinitialiser();
    sauvegardeHoteSain();
    Shell::quand('/db export/', new \RuntimeException('mysqldump: Got error'));
    leve('export en échec : le déploiement échoue', static function (): void {
        transferBackupWithRelease(hote('production'));
    }, \RuntimeException::class, 'mysqldump');
});

cas('db:backup ignore transfer_backup_on_deploy, deploy:db-backup le respecte', static function (): void {
    sauvegardeHoteSain();

    tache('deploy:db-backup', hote('production', array('transfer_backup_on_deploy' => false)));
    verifie('désactivé au déploiement : rien', Shell::commandes(), array());

    tache('db:backup', hote('production', array('transfer_backup_on_deploy' => false)));
    uneCommande('db:backup à la main : dump', '/db export/');

    Shell::reinitialiser();
    sauvegardeHoteSain();
    tache('deploy:db-backup', hote('production'));
    uneCommande('activé par défaut au déploiement', '/db export/');
});
