<?php
/*
 * Uploads : transferFaviconFiles, transferPullFavicon (uploads:pull --favicon-only), transferUploadsPath.
 */

namespace Deployer;

/** Favicon déclaré en base sur un environnement ($env : alias ou « local »). */
function uploadsFavicon(string $env, string $fichier = '2024/05/icone.png', ?string $meta = null): void
{
    Shell::quand('/option get site_icon/', '42', $env);
    Shell::quand('/post meta get 42 _wp_attached_file/', $fichier, $env);
    Shell::quand('/post meta get 42 _wp_attachment_metadata/', $meta ?? json_encode(array(
        'file' => $fichier,
        'sizes' => array(
            'site_icon-32' => array('file' => 'icone-32x32.png'),
            'site_icon-180' => array('file' => 'icone-180x180.png'),
            'vide' => array('file' => ''),
            'cassee' => 'x',
            'double' => array('file' => 'icone-32x32.png'),
        ),
    )), $env);
}

cas('transferFaviconFiles : original et déclinaisons lues dans les métadonnées', static function (): void {
    $h = hote('production');
    uploadsFavicon($h->getAlias());

    verifie('fichiers', transferFaviconFiles($h), array('2024/05/icone.png', '2024/05/icone-32x32.png', '2024/05/icone-180x180.png'));
    foreach (Shell::$appels as $appel) {
        contient('lecture sans plugins, code 1 toléré', $appel['commande'], '--skip-plugins --skip-themes 2>/dev/null || true');
    }

    Shell::reinitialiser();
    uploadsFavicon('local', 'icone.png', '{"sizes":{"a":{"file":"icone-32x32.png"}}}');
    verifie('à la racine des uploads', transferFaviconFiles(null), array('icone.png', 'icone-32x32.png'));

    Shell::reinitialiser();
    uploadsFavicon('local', 'icone.png', 'pas du json');
    verifie('métadonnées illisibles : original seul', transferFaviconFiles(null), array('icone.png'));
});

cas('transferFaviconFiles : aucun favicon', static function (): void {
    Shell::quand('/option get site_icon/', "Deprecated: bruit\n0");
    verifie('site_icon à 0', transferFaviconFiles(null), array());

    Shell::reinitialiser();
    Shell::quand('/option get site_icon/', 'Error: Could not get');
    verifie('site_icon illisible', transferFaviconFiles(null), array());

    Shell::reinitialiser();
    Shell::quand('/option get site_icon/', '7');
    verifie('fichier attaché absent', transferFaviconFiles(null), array());
});

cas('transferPullFavicon : base locale d\'abord, sinon la source ; contrôles avant écriture', static function (): void {
    $h = hote('production');

    transferPullFavicon($h, '/srv/p/shared/uploads', 'web/app/uploads');
    contient('aucun favicon des deux côtés', sortie(), 'l\'option « site_icon » est vide des deux côtés');

    uploadsFavicon($h->getAlias());
    Shell::quand('/^ls -1 /', '');
    transferPullFavicon($h, '/srv/p/shared/uploads/', 'web/app/uploads');
    $sortie = sortie();
    contient('repli sur la base de la source', $sortie, 'Favicon référencé par la base de « ' . $h->getAlias() . ' »');
    contient('introuvable sur la source', $sortie, 'introuvable sur « ' . $h->getAlias() . ' »');
    verifie('présence contrôlée sur la source', Shell::appel('/^ls -1 /')['commande'] ?? null, "ls -1 '/srv/p/shared/uploads/2024/05/icone.png' '/srv/p/shared/uploads/2024/05/icone-32x32.png' '/srv/p/shared/uploads/2024/05/icone-180x180.png' 2>/dev/null || true");

    Shell::reinitialiser();
    uploadsFavicon('local');
    Shell::quand('/^ls -1 /', "/srv/p/shared/uploads/2024/05/icone.png\n/srv/p/shared/uploads/2024/05/icone-32x32.png\n");
    transferPullFavicon($h, '/srv/p/shared/uploads', 'web/app/uploads');
    $sortie = sortie();
    contient('base locale prioritaire', $sortie, 'Favicon référencé par la base locale');
    contient('fichiers manquants signalés', $sortie, '1 des 3 fichiers attendus sont absents');
    contient('-n : rien d\'écrit', $sortie, 'Rien n\'a été écrit.');
    aucuneCommande('-n : pas de rsync', '/rsync/');
});

cas('transferPullFavicon : un seul rsync restreint aux fichiers présents', static function (): void {
    $h = hote('production');
    uploadsFavicon('local');
    Shell::quand('/^ls -1 /', "/srv/p/shared/uploads/2024/05/icone.png\n/srv/p/shared/uploads/2024/05/icone-32x32.png\n");
    entree(array(), true, array('y'));

    transferPullFavicon($h, '/srv/p/shared/uploads', 'web/app/uploads');
    $racine = (string) getenv('DEPLOYER_ROOT');
    uneCommande('dossier local créé', '#^mkdir -p ' . preg_quote(escapeshellarg("$racine/web/app/uploads/2024/05/"), '#') . '$#');
    $rsync = uneCommande('rsync', '/rsync /');
    contient('filtres', $rsync['commande'] ?? '', "'--include=icone.png' '--include=icone-32x32.png' '--exclude=*'");
    contient('du dossier distant vers le dossier local', $rsync['commande'] ?? '', "'deploy@" . $h->getAlias() . ".exemple.test:/srv/p/shared/uploads/2024/05/' " . escapeshellarg("$racine/web/app/uploads/2024/05/"));
    contient('bilan', sortie(), 'Favicon récupéré : 2 fichier(s) dans ./web/app/uploads/2024/05/');
});

cas('transferUploadsPath : chemins, dossier absent, résolution', static function (): void {
    $racine = (string) getenv('DEPLOYER_ROOT');
    $h = hote('production');

    leve('dossier absent : exception', static function () use ($h): void {
        transferUploadsPath($h);
    }, \RuntimeException::class, 'Dossier uploads introuvable sur « ' . $h->getAlias() . ' » : /srv/' . $h->getAlias() . '/current/web/app/uploads');

    Shell::quand('/^if \[ -d /', '+true');
    Shell::quand('/readlink -f/', "/srv/p/shared/web/app/uploads\n");
    verifie('distant : chemin réel', transferUploadsPath($h), '/srv/p/shared/web/app/uploads');
    $commande = Shell::appel('/readlink/')['commande'] ?? '';
    verifie('repli pwd -P', $commande, "readlink -f '/srv/" . $h->getAlias() . "/current/web/app/uploads' 2>/dev/null || (cd '/srv/" . $h->getAlias() . "/current/web/app/uploads' && pwd -P)");

    Shell::reinitialiser();
    Shell::quand('/^if \[ -d /', '+true');
    verifie('local : relatif à la racine, sortie vide gardée', transferUploadsPath(null), "$racine/web/app/uploads");

    Suite::$courant->set('uploads_path', 'wp-content/uploads');
    Shell::reinitialiser();
    Shell::quand('/^if \[ -d /', '+true');
    verifie('WordPress classique', transferUploadsPath(null), "$racine/wp-content/uploads");
});

cas('transferUploadsPath : deux liens symboliques traversés (exécution réelle)', static function (): void {
    Shell::$reel = true;
    $racine = dossierTemporaire();
    mkdir("$racine/releases/7/web/app", 0777, true);
    mkdir("$racine/shared/web/app/uploads", 0777, true);
    symlink("$racine/releases/7", "$racine/current");
    symlink("$racine/shared/web/app/uploads", "$racine/releases/7/web/app/uploads");

    verifie('chemin réel dans shared/', transferUploadsPath(hote('production', array('deploy_path' => $racine))), realpath("$racine/shared/web/app/uploads"));
});
