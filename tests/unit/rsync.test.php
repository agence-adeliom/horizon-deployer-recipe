<?php
/*
 * rsync : transferRsyncRunHost, transferRsyncLineBuffered, transferRsyncCommand, transferRsyncPreview,
 * transferRsync, transferFile. Les scripts générés sont vérifiés comme chaînes, puis exécutés pour de
 * vrai (bash, et rsync 3 quand il est installé) entre dossiers temporaires.
 */

namespace Deployer;

/** Paire d'hôtes sur la même machine et le même compte : rsync tourne sur le serveur. */
function rsyncMemeServeur(): array
{
    return array(
        hote('prod-rs', array('hostname' => 'srv.test', 'remote_user' => 'web')),
        hote('staging-rs', array('hostname' => 'srv.test', 'remote_user' => 'web')),
    );
}

/**
 * Règle qui exécute réellement le script généré en remplaçant l'appel à rsync par une sortie
 * fabriquée ($sortieRsync : commande shell), et garde ce que le script affiche dans $capture.
 */
function rsyncSimule(string $sortieRsync, ?string &$capture = null): callable
{
    return static function (array $appel) use ($sortieRsync, &$capture): string {
        $script = (string) preg_replace('/^rsync .* \| \{$/m', $sortieRsync . ' | {', $appel['commande'], 1);

        return $capture = Shell::bash($script);
    };
}

cas('transferRsyncRunHost : sur la source si les deux extrémités sont distantes', static function (): void {
    $a = hote('a');
    $b = hote('b');
    $boite = hoteLocal('boite-rh');

    verifie('distant → distant : la source', transferRsyncRunHost($a, $b), $a);
    verifie('distant → local', transferRsyncRunHost($a, null), null);
    verifie('local → distant', transferRsyncRunHost(null, $b), null);
    verifie('localhost() → distant', transferRsyncRunHost($boite, $b), null);
});

cas('transferRsyncLineBuffered : --outbuf=L après détection, une fois par environnement', static function (): void {
    $h = hote('outbuf');
    Shell::quand('/--outbuf/', '+true');

    verifie('rsync 3.1+', transferRsyncLineBuffered($h), array('--outbuf=L'));
    verifie('détection', Shell::$appels[0]['commande'], 'if rsync --help 2>&1 | grep -q -- --outbuf; then echo +true; fi');
    transferRsyncLineBuffered($h);
    verifie('mise en cache', count(Shell::$appels), 1);

    Shell::reinitialiser();
    verifie('rsync ancien', transferRsyncLineBuffered(hote('vieux-rsync')), array());
});

cas('transferRsyncCommand : local → local, même serveur, deux serveurs distincts', static function (): void {
    verifie('local → local', transferRsyncCommand(null, '/a b/', null, "/c'd/", array('-a', '--delete')), array(
        null,
        "rsync '-a' '--delete' '/a b/' '/c'\\''d/'",
    ));

    list($prod, $staging) = rsyncMemeServeur();
    verifie('même serveur : exécuté sur la source, chemins locaux, sans ssh', transferRsyncCommand($prod, '/srv/p/', $staging, '/srv/s/', array('-a')), array(
        $prod,
        "rsync '-a' '/srv/p/' '/srv/s/'",
    ));

    leve('deux serveurs distincts refusés', static function (): void {
        transferRsyncCommand(hote('x'), '/a', hote('y'), '/b', array('-a'));
    }, \RuntimeException::class, 'rsync direct impossible entre deux serveurs distincts');

    $boite = hoteLocal('boite-cmd');
    verifie('localhost() : un simple chemin', transferRsyncCommand($boite, '/a', null, '/b', array('-a')), array(null, "rsync '-a' '/a' '/b'"));
});

cas('transferRsyncCommand : une extrémité distante, options ssh de Deployer', static function (): void {
    $h = hote('distant', array('hostname' => 'srv.test', 'remote_user' => 'web', 'port' => 2222));

    $attendu = "rsync '-a' '-e' " . escapeshellarg(optionsSsh($h)) . " 'web@srv.test:/srv/x' '/tmp/y'";
    verifie('distant → local', transferRsyncCommand($h, '/srv/x', null, '/tmp/y', array('-a')), array(null, $attendu));
    contient('port transmis à ssh', optionsSsh($h), '-p 2222');

    $attendu = "rsync '-a' '-e' " . escapeshellarg(optionsSsh($h)) . " '/tmp/y' 'web@srv.test:/srv/x'";
    verifie('local → distant', transferRsyncCommand(null, '/tmp/y', $h, '/srv/x', array('-a')), array(null, $attendu));
});

cas('transferRsyncPreview : script de simulation (set -e, pipefail, paliers)', static function (): void {
    list($prod, $staging) = rsyncMemeServeur();
    Shell::quand('/^if rsync --help/', '+true');
    Shell::quand('/--dry-run/', "   … 2 fichiers analysés sur ~25\n   → 3 fichier(s) à transférer, 1 à supprimer à la destination.\n");

    verifie('bilan lu', transferRsyncPreview($prod, '/srv/p/', $staging, '/srv/s/', array('--delete'), 25), array('transfer' => 3, 'delete' => 1));

    $appel = Shell::appel('/--dry-run/');
    $script = $appel['commande'] ?? '';
    contient('set -e', $script, "set -e\n");
    contient('pipefail', $script, 'set -o pipefail');
    contient('options', $script, "rsync '-a' '--dry-run' '-ii' '--outbuf=L' '--delete' '/srv/p/' '/srv/s/' | {");
    contient('palier de 10 %', $script, 'dep_next=2');
    contient('total annoncé', $script, 'analysés sur ~25');
    contient('« < » (envoi) compté comme « > » (réception)', $script, "'<'*|'>'*) dep_t=");
    verifie('exécuté sur le serveur commun', $appel['env'] ?? null, $prod->getAlias());
    verifie('délai', $appel['timeout'] ?? null, 14400);

    Shell::reinitialiser();
    Shell::quand('/--dry-run/', "   → 0 fichier(s) à transférer, 0 à supprimer à la destination.\n");
    transferRsyncPreview($prod, '/srv/p/', $staging, '/srv/s/');
    $script = Shell::appel('/--dry-run/')['commande'] ?? '';
    contient('sans total : tous les 500', $script, 'dep_next=500');
    absent('sans total : pas de « sur ~ »', $script, 'sur ~');

    Shell::reinitialiser();
    leve('bilan absent : exception', static function () use ($prod, $staging): void {
        transferRsyncPreview($prod, '/srv/p/', $staging, '/srv/s/');
    }, \RuntimeException::class, 'Impossible de lire le bilan');
});

cas('transferRsyncPreview : comptage réel des lignes -ii (envoi, réception, suppression)', static function (): void {
    list($prod, $staging) = rsyncMemeServeur();
    $capture = null;
    Shell::quand('/--dry-run/', rsyncSimule(
        "printf '%s\\n' '<f+++++++++ a.jpg' '>f.st...... b.jpg' '.f          c.jpg' '*deleting   d.jpg' 'cd+++++++++ e/'",
        $capture
    ));

    verifie('2 transferts (dont un envoi), 1 suppression', transferRsyncPreview($prod, '/p/', $staging, '/s/', array(), 4), array('transfer' => 2, 'delete' => 1));
    contient('progression par palier', (string) $capture, '… 4 fichiers analysés sur ~4');

    Shell::reinitialiser();
    Shell::quand('/--dry-run/', rsyncSimule('false'));
    leve('rsync en échec : exception, jamais « 0 fichier »', static function () use ($prod, $staging): void {
        transferRsyncPreview($prod, '/p/', $staging, '/s/');
    }, \RuntimeException::class);
});

cas('transferRsync : script de progression (read -d $\'\\r\', jamais awk ni tr)', static function (): void {
    list($prod, $staging) = rsyncMemeServeur();
    transferRsync($prod, '/srv/p/', $staging, '/srv/s/', array('--delete'));

    $appel = Shell::$appels[0];
    contient('options', $appel['commande'], "rsync '-a' '--no-inc-recursive' '--info=progress2' '--human-readable' '--stats' '--delete' '/srv/p/' '/srv/s/' | {");
    contient('pipefail', $appel['commande'], 'set -o pipefail');
    contient('lecture octet par octet', $appel['commande'], "read -r -d \$'\\r' dep_line");
    verifie('aucun pipe vers awk ou tr', preg_match('/\|\s*(awk|tr)\b/', $appel['commande']), 0);
    verifie('sur le serveur commun', $appel['env'], $prod->getAlias());
    verifie('délai', $appel['timeout'], 14400);
    verifie('temps réel', $appel['temps_reel'], true);
});

cas('transferRsync : paliers de 10 % et bilan --stats traduit (exécution réelle du script)', static function (): void {
    list($prod, $staging) = rsyncMemeServeur();
    $capture = null;
    $fichier = dossierTemporaire() . '/sortie-rsync';
    file_put_contents($fichier, implode("\r", array(
        '      1.00K   5%   1.00MB/s    0:00:09',
        '      2.00K  12%   1.00MB/s    0:00:08',
        '      3.00K  15%   1.00MB/s    0:00:07',
        '     10.00K 100%   1.00MB/s    0:00:00 (xfr#2, to-chk=0/2)',
        "\nNumber of files: 3\nNumber of regular files transferred: 2\nTotal transferred file size: 10,240 bytes\n",
    )));
    Shell::quand('/--info=progress2/', rsyncSimule('cat ' . escapeshellarg($fichier), $capture));

    transferRsync($prod, '/p/', $staging, '/s/');
    $sortie = (string) $capture;
    contient('premier palier', $sortie, '… 5% transférés (1.00K, 1.00MB/s, reste 0:00:09)');
    contient('palier suivant', $sortie, '… 15% transférés');
    absent('moins de 10 points d\'écart : ignoré', $sortie, '12%');
    contient('100 %', $sortie, '… 100% transférés');
    contient('fichiers transférés', $sortie, 'fichiers transférés : 2');
    contient('volume', $sortie, 'volume transféré : 10,240 octets');
    absent('autres lignes de --stats filtrées', $sortie, 'Number of files:');

    Shell::reinitialiser();
    Shell::quand('/--info=progress2/', rsyncSimule('false'));
    leve('rsync en échec : exception (pipefail)', static function () use ($prod, $staging): void {
        transferRsync($prod, '/p/', $staging, '/s/');
    }, \RuntimeException::class);
});

cas('rsync réel entre deux dossiers : simulation puis copie', static function (): void {
    if (!rsync3()) {
        saute('rsync 3 absent');
    }

    Shell::$reel = true;
    $source = dossierTemporaire();
    $destination = dossierTemporaire();
    file_put_contents("$source/a.txt", 'a');
    file_put_contents("$source/b.txt", 'b');
    mkdir("$source/2024");
    file_put_contents("$source/2024/c.txt", 'c');
    Shell::bash("cp -p '$source/b.txt' '$destination/b.txt'");
    file_put_contents("$destination/obsolete.txt", 'x');

    verifie('simulation miroir', transferRsyncPreview(null, "$source/", null, "$destination/", array('--delete'), 3), array('transfer' => 2, 'delete' => 1));
    vrai('simulation : rien d\'écrit', !is_file("$destination/a.txt") && is_file("$destination/obsolete.txt"));

    verifie('simulation fusion', transferRsyncPreview(null, "$source/", null, "$destination/"), array('transfer' => 2, 'delete' => 0));

    transferRsync(null, "$source/", null, "$destination/", array('--delete'));
    verifie('copie', array(is_file("$destination/a.txt"), is_file("$destination/2024/c.txt"), is_file("$destination/obsolete.txt")), array(true, true, false));
    verifie('plus rien à faire', transferRsyncPreview(null, "$source/", null, "$destination/", array('--delete')), array('transfer' => 0, 'delete' => 0));

    leve('source absente : exception', static function () use ($destination): void {
        transferRsync(null, '/nexiste/pas/', null, "$destination/");
    }, \RuntimeException::class);
});

cas('transferFile : même serveur, aucun transfert', static function (): void {
    list($prod, $staging) = rsyncMemeServeur();

    verifie('chemin inchangé', transferFile($prod, '/srv/p/.dep/transfer/x/db.sql.gz', $staging, 'db.sql.gz'), '/srv/p/.dep/transfer/x/db.sql.gz');
    verifie('aucune commande', Shell::commandes(), array());
    contient('annoncé', sortie(), 'Même serveur : aucun transfert réseau nécessaire.');
});

cas('transferFile : local → distant, puis entre deux serveurs via le local', static function (): void {
    $staging = hote('staging-tf');
    $chemin = transferFile(null, '/tmp/db.sql.gz', $staging, 'db.sql.gz');
    verifie('dans le répertoire de travail distant', preg_match('#^/srv/' . preg_quote($staging->getAlias(), '#') . '/\.dep/transfer/\d{8}-\d{6}-[0-9a-f]{6}/db\.sql\.gz$#', $chemin), 1);
    uneCommande('rsync vers l\'hôte', '#^set -o pipefail\nrsync .*\'/tmp/db\.sql\.gz\' \'deploy@' . preg_quote($staging->getAlias(), '#') . '\.exemple\.test:/srv/#');

    Shell::reinitialiser();
    $prod = hote('prod-tf');
    $autre = hote('autre-tf');
    $chemin = transferFile($prod, '/srv/p/db.sql.gz', $autre, 'db.sql.gz');
    $rsyncs = array_values(array_filter(Shell::commandes('local'), static function (string $c): bool {
        return strpos($c, 'rsync ') !== false;
    }));
    verifie('deux rsync lancés en local', count($rsyncs), 2);
    contient('1 : source → local', $rsyncs[0] ?? '', "deploy@" . $prod->getAlias() . '.exemple.test:/srv/p/db.sql.gz');
    contient('2 : local → destination', $rsyncs[1] ?? '', $chemin);
});
