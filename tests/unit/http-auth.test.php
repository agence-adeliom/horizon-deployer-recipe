<?php
/*
 * Authentification HTTP des préproductions : réglages par défaut, bloc du .htaccess (génération,
 * insertion, retrait, IP relues, bloc mal formé), ligne du .htpasswd, mot de passe généré et
 * garde-fou production. httpAuthBlock, httpAuthInsertBlock, httpAuthRemoveBlock, httpAuthBlockIps,
 * httpAuthGeneratePassword, httpAuthHtpasswdLine, httpAuthHtpasswdUser, httpAuthProductionRefusal.
 */

namespace Deployer;

/** Bloc de référence des cas ci-dessous. */
function httpAuthBlocTest(): string
{
    return httpAuthBlock(
        '/srv/s/shared/.htpasswd',
        'Accès "restreint"',
        ['203.0.113.10', '127.0.0.1'],
        ['/.well-known/acme-challenge/', '/wp-json/gf/v2/'],
    );
}

/** Blocs mal formés : marqueur seul, ordre inversé, deux blocs. */
function httpAuthBlocsMalFormes(): array
{
    return [
        'BEGIN sans END' => "# BEGIN Horizon HTTP auth\nx\n",
        'END sans BEGIN' => "x\n# END Horizon HTTP auth\n",
        'END avant BEGIN' => "# END Horizon HTTP auth\nx\n# BEGIN Horizon HTTP auth\n",
        'deux blocs' => "# BEGIN Horizon HTTP auth\n# END Horizon HTTP auth\n# BEGIN Horizon HTTP auth\n# END Horizon HTTP auth\n",
    ];
}

cas('réglages par défaut de l\'authentification HTTP', static function (): void {
    $h = hote('http-auth-defauts');
    $attendues = [
        'http_auth' => false,
        'http_auth_user' => 'adeliom',
        'http_auth_realm' => 'Accès restreint',
        'http_auth_htaccess' => 'web/.htaccess',
        'http_auth_htpasswd' => '/srv/' . $h->getAlias() . '/shared/.htpasswd',
        'http_auth_except_paths' => ['/.well-known/acme-challenge/'],
        'http_auth_allow_ips' => [],
    ];

    foreach ($attendues as $cle => $valeur) {
        verifie($cle, dans($h, static function () use ($cle) {
            return get($cle);
        }), $valeur);
    }
});

cas('httpAuthBlock : marqueurs, IP, exceptions, realm', static function (): void {
    $bloc = httpAuthBlocTest();

    verifie('marqueur début en tête', strpos($bloc, "# BEGIN Horizon HTTP auth\n"), 0);
    verifie('fin du bloc', substr($bloc, -24), "# END Horizon HTTP auth\n");
    contient('AuthType', $bloc, "\nAuthType Basic\n");
    contient('AuthUserFile entre guillemets', $bloc, "\nAuthUserFile \"/srv/s/shared/.htpasswd\"\n");
    contient('IP locales en tête, dédupliquées', $bloc, "    Require ip 127.0.0.1 ::1 203.0.113.10\n");
    contient('exception ACME échappée', $bloc, '    Require expr %{REQUEST_URI} =~ m#^/\.well\-known/acme\-challenge/#' . "\n");
    contient('seconde exception', $bloc, '    Require expr %{REQUEST_URI} =~ m#^/wp\-json/gf/v2/#' . "\n");
    contient('utilisateur valide', $bloc, "    Require valid-user\n</RequireAny>\n");
    contient('realm échappé', $bloc, 'AuthName "Accès \"restreint\""');
    contient('sans IP ni exception', httpAuthBlock('/p', 'r', [], []), "<RequireAny>\n    Require ip 127.0.0.1 ::1\n    Require valid-user\n</RequireAny>\n");
});

cas('httpAuthBlock : valeur qui casserait le .htaccess refusée', static function (): void {
    $refus = [
        'realm avec saut de ligne' => ['/p', "a\nRequire all granted", [], []],
        'realm avec retour chariot' => ['/p', "a\rb", [], []],
        'realm avec barre oblique inverse' => ['/p', 'a\\b', [], []],
        'chemin du .htpasswd avec saut de ligne' => ["/p\nx", 'r', [], []],
        'chemin du .htpasswd avec guillemet' => ['/p"x', 'r', [], []],
        'IP avec saut de ligne' => ['/p', 'r', ["203.0.113.10\nRequire all granted"], []],
        'IP avec retour chariot' => ['/p', 'r', ["203.0.113.10\r"], []],
        'exception avec saut de ligne' => ['/p', 'r', [], ["/a\nb/"]],
        'exception avec retour chariot' => ['/p', 'r', [], ["/a\rb/"]],
        'exception avec #' => ['/p', 'r', [], ['/a#b/']],
    ];

    foreach ($refus as $libelle => $arguments) {
        leve($libelle, static function () use ($arguments): void {
            httpAuthBlock(...$arguments);
        }, \InvalidArgumentException::class);
    }
});

cas('httpAuthInsertBlock : en tête, reste du fichier intact (CRLF, sans saut final)', static function (): void {
    $bloc = httpAuthBlocTest();
    $wp = "# BEGIN WordPress\r\nRewriteEngine On\r\n# END WordPress";

    verifie('insertion en tête', httpAuthInsertBlock($wp, $bloc), $bloc . "\n" . $wp);
    verifie('remplacement idempotent', httpAuthInsertBlock(httpAuthInsertBlock($wp, $bloc), $bloc), $bloc . "\n" . $wp);

    $autre = httpAuthBlock('/autre/.htpasswd', 'Autre', [], []);
    verifie('bloc différent remplacé', httpAuthInsertBlock(httpAuthInsertBlock($wp, $bloc), $autre), $autre . "\n" . $wp);
    verifie('bloc placé ailleurs : déplacé en tête', httpAuthInsertBlock("# avant\n" . $bloc . "\n# après\n", $autre), $autre . "\n# avant\n# après\n");
    verifie('fichier vide', httpAuthInsertBlock('', $bloc), $bloc);
    verifie('fichier ne contenant que le bloc', httpAuthInsertBlock($bloc, $autre), $autre);
});

cas('httpAuthRemoveBlock : bloc et ligne vide suivante retirés', static function (): void {
    $bloc = httpAuthBlocTest();
    $wp = "# BEGIN WordPress\r\nRewriteEngine On\r\n# END WordPress";

    verifie('retrait', httpAuthRemoveBlock(httpAuthInsertBlock($wp, $bloc)), $wp);
    verifie('sans bloc : inchangé', httpAuthRemoveBlock($wp), $wp);
    verifie('vide : inchangé', httpAuthRemoveBlock(''), '');
    verifie('bloc seul : fichier vide', httpAuthRemoveBlock($bloc), '');
    verifie('ligne vide CRLF retirée', httpAuthRemoveBlock($bloc . "\r\n" . $wp), $wp);
    verifie('seule la première ligne vide est retirée', httpAuthRemoveBlock($bloc . "\n\n" . $wp), "\n" . $wp);
    verifie('bloc au milieu', httpAuthRemoveBlock("# avant\n" . $bloc . "# après\n"), "# avant\n# après\n");
});

cas('httpAuthBlockIps : IP du bloc hors IP locales', static function (): void {
    $bloc = httpAuthBlocTest();

    verifie('IP relues', httpAuthBlockIps($bloc), ['203.0.113.10']);
    verifie('dans un .htaccess complet', httpAuthBlockIps(httpAuthInsertBlock("# BEGIN WordPress\r\n# END WordPress\r\n", httpAuthBlock('/p', 'r', ['198.51.100.7', '2001:db8::1'], []))), ['198.51.100.7', '2001:db8::1']);
    verifie('IP locales seules', httpAuthBlockIps(httpAuthBlock('/p', 'r', [], [])), []);
    verifie('sans bloc', httpAuthBlockIps("Require ip 198.51.100.7\n"), []);
});

cas('bloc mal formé : exception, pour les trois fonctions', static function (): void {
    foreach (httpAuthBlocsMalFormes() as $libelle => $texte) {
        leve("$libelle (insertion)", static function () use ($texte): void {
            httpAuthInsertBlock($texte, 'b');
        }, \UnexpectedValueException::class);
        leve("$libelle (retrait)", static function () use ($texte): void {
            httpAuthRemoveBlock($texte);
        }, \UnexpectedValueException::class);
        leve("$libelle (IP)", static function () use ($texte): void {
            httpAuthBlockIps($texte);
        }, \UnexpectedValueException::class);
    }
});

cas('httpAuthGeneratePassword : 24 caractères sans ambiguïté', static function (): void {
    $mdp = httpAuthGeneratePassword();
    vrai('24 caractères de l\'alphabet', preg_match('/^[a-hjkmnp-zA-HJKLMNP-Z2-9]{24}$/', $mdp) === 1);

    $tirages = [];

    for ($i = 0; $i < 20; $i++) {
        $tirages[httpAuthGeneratePassword()] = true;
    }

    verifie('tirages tous différents', count($tirages), 20);
});

cas('httpAuthHtpasswdLine et httpAuthHtpasswdUser', static function (): void {
    $clair = "a\"b\\c\$d'e f";
    $ligne = httpAuthHtpasswdLine('adeliom', $clair);

    verifie('identifiant en tête', substr($ligne, 0, 8), 'adeliom:');
    verifie('saut de ligne final', substr($ligne, -1), "\n");
    vrai('bcrypt', strpos($ligne, 'adeliom:$2y$') === 0);
    vrai('hash vérifiable', password_verify($clair, substr(trim($ligne), 8)));
    absent('clair absent', $ligne, $clair);

    foreach (['' => 'vide', 'a:b' => 'avec :', "a\nb" => 'avec saut de ligne', "a\rb" => 'avec retour chariot'] as $user => $libelle) {
        leve("identifiant $libelle", static function () use ($user): void {
            httpAuthHtpasswdLine((string) $user, 'x');
        }, \InvalidArgumentException::class);
    }

    verifie('identifiant relu', httpAuthHtpasswdUser("\nadeliom:\$2y\$10\$abc\n"), 'adeliom');
    verifie('première ligne seulement', httpAuthHtpasswdUser("client:\$2y\$10\$abc\nautre:\$2y\$10\$def\n"), 'client');
    verifie('fichier vide', httpAuthHtpasswdUser(''), null);
    verifie('lignes vides seules', httpAuthHtpasswdUser("\n  \n"), null);
});

cas('httpAuthProductionRefusal : type WordPress, à défaut hôte protégé', static function (): void {
    verifie('staging', httpAuthProductionRefusal(['WP_ENV' => 'staging'], true), null);
    verifie('development', httpAuthProductionRefusal(['WP_ENV' => 'development'], false), null);

    $production = (string) httpAuthProductionRefusal(['WP_ENV' => 'production'], false);
    vrai('production', $production !== '');
    contient('production : variable citée', $production, 'WP_ENV=production');
    absent('production : pas de suggestion', $production, 'WP_ENV=staging');

    $preprod = (string) httpAuthProductionRefusal(['WP_ENV' => 'preprod'], false);
    contient('preprod : valeur citée', $preprod, 'WP_ENV=preprod');
    contient('preprod : suggestion', $preprod, 'WP_ENV=staging');

    $type = (string) httpAuthProductionRefusal(['WP_ENV' => 'staging', 'WP_ENVIRONMENT_TYPE' => 'production'], false);
    vrai('type prioritaire', $type !== '');
    contient('type prioritaire : variable citée', $type, 'WP_ENVIRONMENT_TYPE=production');

    vrai('introuvable, protégé', httpAuthProductionRefusal([], true) !== null);
    verifie('introuvable, non protégé', httpAuthProductionRefusal([], false), null);
    verifie('WP_HOME seul : introuvable', httpAuthProductionRefusal(['WP_HOME' => 'https://site.test'], false), null);
});

/*
 * Plomberie distante : chemin du .htaccess, lecture et écriture de fichiers, statut HTTP, sonde.
 */

cas('httpAuthReadFile / httpAuthWriteFile : aller-retour réel (CRLF, accents, sans saut final)', static function (): void {
    $dossier = dossierTemporaire();
    $h = hoteLocal('http-auth-fichiers');
    Shell::$reel = true;
    $contenu = "# Règles\r\nRewriteEngine On\r\n# Accès réservé à l'équipe";

    verifie('fichier absent : null', httpAuthReadFile($h, "$dossier/.htaccess"), null);

    httpAuthWriteFile($h, "$dossier/.htaccess", $contenu, '644');
    verifie('octet pour octet sur le disque', file_get_contents("$dossier/.htaccess"), $contenu);
    verifie('droits 644', fileperms("$dossier/.htaccess") & 0777, 0644);
    verifie('relu à l\'identique', httpAuthReadFile($h, "$dossier/.htaccess"), $contenu);
    vrai('fichier temporaire retiré', !file_exists("$dossier/.htaccess.horizon-tmp"));

    httpAuthWriteFile($h, "$dossier/.htaccess", '', '600');
    verifie('fichier vide : chaîne vide', httpAuthReadFile($h, "$dossier/.htaccess"), '');
    verifie('droits 600', fileperms("$dossier/.htaccess") & 0777, 0600);

    $gros = str_repeat("RewriteRule ^é(.*)$ /index.php [L]\n", 400);
    httpAuthWriteFile($h, "$dossier/gros", $gros, '644');
    verifie('fichier sur plusieurs lignes base64', httpAuthReadFile($h, "$dossier/gros"), $gros);
});

cas('httpAuthWriteFile : contenu en base64, temporaire à côté de la cible, droits contrôlés', static function (): void {
    $h = hote('http-auth-ecriture');

    httpAuthWriteFile($h, '/srv/s/web/.htaccess', "secret \$x 'y'", '644');
    $appel = uneCommande('base64 décodé sur l\'hôte', '/base64 -d/');
    contient('contenu encodé', $appel['commande'] ?? '', escapeshellarg(base64_encode("secret \$x 'y'")));
    absent('contenu en clair absent', $appel['commande'] ?? '', 'secret');
    contient('temporaire à côté', $appel['commande'] ?? '', escapeshellarg('/srv/s/web/.htaccess.horizon-tmp'));
    contient('mv sur la cible', $appel['commande'] ?? '', 'mv -f ' . escapeshellarg('/srv/s/web/.htaccess.horizon-tmp') . ' ' . escapeshellarg('/srv/s/web/.htaccess'));
    verifie('sur l\'hôte', $appel['env'] ?? null, $h->getAlias());
    contient('pipefail', $appel['commande'] ?? '', 'set -o pipefail; ');

    leve('droits invalides', static function () use ($h): void {
        httpAuthWriteFile($h, '/srv/s/web/.htaccess', 'x', '644; rm -rf /');
    }, \InvalidArgumentException::class);
});

cas('httpAuthHtaccessPath : .htaccess partagé, chemin de shared/ (réel)', static function (): void {
    $racine = dossierTemporaire();
    mkdir("$racine/shared/web", 0777, true);
    mkdir("$racine/releases/1/web", 0777, true);
    file_put_contents("$racine/shared/web/.htaccess", "x\n");
    symlink("$racine/shared/web/.htaccess", "$racine/releases/1/web/.htaccess");
    symlink("$racine/releases/1", "$racine/current");
    $h = hote('http-auth-chemin', ['deploy_path' => $racine]);
    Shell::$reel = true;

    verifie('lien résolu vers shared/', httpAuthHtaccessPath($h, '{{current_path}}'), "$racine/shared/web/.htaccess");

    unlink("$racine/releases/1/web/.htaccess");
    verifie('fichier absent : chemin réel du dossier', httpAuthHtaccessPath($h, '{{current_path}}'), "$racine/releases/1/web/.htaccess");

    dans($h, static function (): void {
        set('release_path', '{{deploy_path}}/releases/2');
    });
    verifie('readlink muet : chemin non résolu', httpAuthHtaccessPath($h, '{{release_path}}'), "$racine/releases/2/web/.htaccess");
});

cas('httpAuthHtaccessPath : deploy_path en « ~/ » développé avant readlink', static function (): void {
    $h = hote('http-auth-chemin-home', ['deploy_path' => '~/sites/s']);
    Shell::quand('/^printf %s "\$HOME"$/', '/home/compte');

    verifie('sortie vide : chemin non résolu', httpAuthHtaccessPath($h, '{{current_path}}'), '/home/compte/sites/s/current/web/.htaccess');
    $appel = uneCommande('readlink sur le chemin absolu', '/readlink -f /');
    contient('sans « ~ »', $appel['commande'] ?? '', escapeshellarg('/home/compte/sites/s/current/web/.htaccess'));
});

cas('httpAuthHttpStatus : code HTTP, null si curl échoue', static function (): void {
    Shell::quand('/curl /', '401');
    verifie('401', httpAuthHttpStatus(null, 'https://site.test/'), 401);
    $appel = uneCommande('curl local', '/^curl -sS -o \/dev\/null -w .*--max-time 20 /');
    verifie('en local', $appel['env'] ?? null, 'local');
    contient('URL échappée', $appel['commande'] ?? '', escapeshellarg('https://site.test/'));
    absent('sans fichier de configuration', $appel['commande'] ?? '', ' -K ');

    Shell::reinitialiser();
    Shell::quand('/curl /', '');
    verifie('réponse vide', httpAuthHttpStatus(null, 'https://site.test/'), null);

    Shell::reinitialiser();
    Shell::quand('/curl /', '000');
    verifie('000 (aucune réponse)', httpAuthHttpStatus(null, 'https://site.test/'), null);

    Shell::reinitialiser();
    Shell::quand('/curl /', new \RuntimeException('curl: (6) Could not resolve host'));
    verifie('curl en échec', httpAuthHttpStatus(null, 'https://site.test/'), null);

    $h = hote('http-auth-statut');
    Shell::reinitialiser();
    Shell::quand('/curl /', '200');
    verifie('depuis un hôte', httpAuthHttpStatus($h, 'https://site.test/'), 200);
    verifie('sur l\'hôte', Shell::$appels[0]['env'] ?? null, $h->getAlias());
});

cas('httpAuthHttpStatus : identifiants dans un fichier de configuration local, jamais dans la commande', static function (): void {
    $clair = "p\"a\\s\$s 'x";
    $lu = ['fichier' => null, 'contenu' => null, 'droits' => null];
    Shell::quand('/curl /', static function (array $appel) use (&$lu): string {
        if (preg_match("/ -K '([^']+)'/", $appel['commande'], $m) === 1) {
            $lu = ['fichier' => $m[1], 'contenu' => file_get_contents($m[1]), 'droits' => fileperms($m[1]) & 0777];
        }

        return '200';
    });

    verifie('code', httpAuthHttpStatus(null, 'https://site.test/', 'adeliom', $clair), 200);
    $commandes = implode("\n", Shell::commandes());
    absent('clair hors des commandes', $commandes, 'p"a');
    absent('clair hors des commandes (fin)', $commandes, "\$s 'x");
    verifie('configuration échappée', $lu['contenu'], 'user = "adeliom:p\\"a\\\\s$s \'x"' . "\n");
    verifie('droits 600', $lu['droits'], 0600);
    vrai('fichier de configuration supprimé', $lu['fichier'] !== null && !file_exists($lu['fichier']));

    Shell::reinitialiser();
    $fichier = null;
    Shell::quand('/curl /', static function (array $appel) use (&$fichier): string {
        preg_match("/ -K '([^']+)'/", $appel['commande'], $m);
        $fichier = $m[1] ?? null;

        throw new \RuntimeException('curl: (28) timeout');
    });
    verifie('échec : null', httpAuthHttpStatus(null, 'https://site.test/', 'adeliom', 'x'), null);
    vrai('échec : fichier supprimé', $fichier !== null && !file_exists($fichier));

    $h = hote('http-auth-statut-distant');
    Shell::reinitialiser();
    leve('identifiants refusés hors du poste local', static function () use ($h): void {
        httpAuthHttpStatus($h, 'https://site.test/', 'adeliom', 'x');
    }, \LogicException::class);
    verifie('aucune commande', Shell::commandes(), []);

    $refus = ['identifiant avec saut de ligne' => ["a\nb", 'x'], 'identifiant avec retour chariot' => ["a\rb", 'x'], 'mot de passe avec saut de ligne' => ['adeliom', "x\ny"], 'mot de passe avec retour chariot' => ['adeliom', "x\ry"]];

    foreach ($refus as $libelle => $identifiants) {
        Shell::reinitialiser();
        $avant = glob(sys_get_temp_dir() . '/horizon-curl-*');
        leve($libelle, static function () use ($identifiants): void {
            httpAuthHttpStatus(null, 'https://site.test/', $identifiants[0], $identifiants[1]);
        }, \InvalidArgumentException::class);
        verifie("$libelle : aucune commande", Shell::commandes(), []);
        verifie("$libelle : aucun fichier", glob(sys_get_temp_dir() . '/horizon-curl-*'), $avant);
    }
});

cas('httpAuthProbe : statut selon le code HTTP, dossier toujours supprimé', static function (): void {
    $attendus = ['401' => 'ok', '200' => 'ignored', '500' => 'error', '' => 'unreachable', '403' => 'unreachable'];

    foreach ($attendus as $code => $statut) {
        $h = hote('http-auth-sonde');
        Shell::reinitialiser();
        Shell::quand('/^curl -sS -o /', (string) $code, 'local');
        Shell::quand('/^curl -fsS /', '203.0.113.10');

        $resultat = httpAuthProbe($h, '/srv/s/current/web', 'https://site.test');
        verifie("$code : statut", $resultat['status'], $statut);
        verifie("$code : code", $resultat['code'], $code === '' ? null : (int) $code);
        verifie("$code : IP", $resultat['ip'], $statut === 'ok' ? '203.0.113.10' : null);
        uneCommande("$code : dossier supprimé", '/rm -rf .*horizon-http-auth-/');

        if ($statut === 'ok') {
            $appel = uneCommande('ok : IP lue depuis le serveur', '#^curl -fsS .*https://site\.test/horizon-http-auth-[0-9a-f]{12}/#');
            verifie('ok : curl lancé sur l\'hôte', $appel['env'] ?? null, $h->getAlias());
        } else {
            aucuneCommande("$code : pas de curl depuis le serveur", '/^curl -fsS /');
        }
    }
});

cas('httpAuthProbe : curl distant en échec ou sortie qui n\'est pas une IP', static function (): void {
    $h = hote('http-auth-sonde-echec');
    Shell::quand('/^curl -sS -o /', '401', 'local');
    Shell::quand('/^curl -fsS /', new \RuntimeException('curl: (22) 403'));

    verifie('exception : ok sans IP', httpAuthProbe($h, '/srv/s/current/web', 'https://site.test'), ['status' => 'ok', 'code' => 401, 'ip' => null]);
    uneCommande('exception : dossier supprimé', '/rm -rf .*horizon-http-auth-/');

    Shell::reinitialiser();
    Shell::quand('/^curl -sS -o /', '401', 'local');
    Shell::quand('/^curl -fsS /', '<html>Erreur</html>');
    verifie('pas une IP : ok sans IP', httpAuthProbe($h, '/srv/s/current/web', 'https://site.test'), ['status' => 'ok', 'code' => 401, 'ip' => null]);

    Shell::reinitialiser();
    Shell::quand('/^curl -sS -o /', '401', 'local');
    Shell::quand('/base64 -d/', new \RuntimeException('Permission denied'));
    leve('écriture impossible : exception', static function () use ($h): void {
        httpAuthProbe($h, '/srv/s/current/web', 'https://site.test');
    }, \RuntimeException::class, 'Permission denied');
    uneCommande('écriture impossible : dossier supprimé', '/rm -rf .*horizon-http-auth-/');
});

cas('httpAuthProbe : URL sans double barre (barre finale, sous-dossier)', static function (): void {
    $h = hote('http-auth-sonde-url');
    Shell::quand('/^curl -sS -o /', '200', 'local');

    httpAuthProbe($h, '/srv/s/current/web', 'https://site.test/fr/');
    uneCommande('sans double barre', '#https://site\.test/fr/horizon-http-auth-[0-9a-f]{12}/#');
    aucuneCommande('aucune double barre', '#site\.test/fr//#');
});

cas('httpAuthProbe : fichiers de la sonde sur le disque, puis supprimés (réel)', static function (): void {
    $racine = dossierTemporaire();
    $h = hote('http-auth-sonde-reelle');
    Shell::$reel = true;
    $vu = [];

    Shell::quand('/^curl -sS -o /', static function (array $appel) use ($racine, &$vu): string {
        $dossiers = glob("$racine/horizon-http-auth-*");
        $vu['dossiers'] = count($dossiers);
        $vu['htaccess'] = (string) @file_get_contents($dossiers[0] . '/.htaccess');
        $vu['htpasswd'] = @file_get_contents($dossiers[0] . '/.htpasswd');
        $vu['index'] = @file_get_contents($dossiers[0] . '/index.php');

        return '401';
    }, 'local');
    Shell::quand('/^curl -fsS /', static function (array $appel) use ($racine, &$vu): string {
        $dossiers = glob("$racine/horizon-http-auth-*");
        $vu['htaccess_apres'] = @file_get_contents($dossiers[0] . '/.htaccess');

        return "203.0.113.10\n";
    });

    $resultat = httpAuthProbe($h, $racine, 'https://site.test');
    verifie('résultat', $resultat, ['status' => 'ok', 'code' => 401, 'ip' => '203.0.113.10']);
    verifie('un dossier de sonde', $vu['dossiers'] ?? null, 1);
    contient('.htaccess exige une authentification', $vu['htaccess'] ?? '', "AuthType Basic\n");
    contient('.htaccess désigne le .htpasswd de la sonde', $vu['htaccess'] ?? '', '/.htpasswd"');
    contient('.htaccess : utilisateur valide', $vu['htaccess'] ?? '', 'Require valid-user');
    verifie('.htpasswd vide', $vu['htpasswd'] ?? null, '');
    verifie('index.php', $vu['index'] ?? null, "<?php echo \$_SERVER['REMOTE_ADDR'];\n");
    verifie('accès ouvert avant le curl du serveur (auth héritée neutralisée)', $vu['htaccess_apres'] ?? null, "Require all granted\n");
    verifie('dossier supprimé', glob("$racine/horizon-http-auth-*"), []);
});

cas('httpAuthProbe : racine publique qui n\'est pas un chemin absolu résolu, refusée sans commande', static function (): void {
    $h = hote('http-auth-sonde-racine');

    foreach (['web' => 'relatif', '~/www' => 'en « ~ »', '{{current_path}}/web' => 'placeholder non résolu', '' => 'vide'] as $racine => $libelle) {
        Shell::reinitialiser();
        leve("racine $libelle", static function () use ($h, $racine): void {
            httpAuthProbe($h, (string) $racine, 'https://site.test');
        }, \InvalidArgumentException::class);
        verifie("racine $libelle : aucune commande", Shell::commandes(), []);
    }
});

cas('httpAuthProbe : suppression du dossier en échec, avertissement sans masquer le résultat ni l\'exception', static function (): void {
    $h = hote('http-auth-sonde-rm');
    sortie();
    Shell::quand('/^curl -sS -o /', '401', 'local');
    Shell::quand('/^curl -fsS /', '203.0.113.10');
    Shell::quand('/^rm -rf /', new \RuntimeException('Device busy'));

    verifie('résultat conservé', httpAuthProbe($h, '/srv/s/current/web', 'https://site.test'), ['status' => 'ok', 'code' => 401, 'ip' => '203.0.113.10']);
    $sortie = sortie();
    contient('avertissement', $sortie, 'laissé en place');
    vrai('dossier nommé', preg_match('#/srv/s/current/web/horizon-http-auth-[0-9a-f]{12}#', $sortie) === 1);

    Shell::reinitialiser();
    Shell::quand('/base64 -d/', new \RuntimeException('Permission denied'));
    Shell::quand('/^rm -rf /', new \RuntimeException('Device busy'));
    leve('exception d\'origine conservée', static function () use ($h): void {
        httpAuthProbe($h, '/srv/s/current/web', 'https://site.test');
    }, \RuntimeException::class, 'Permission denied');
    contient('avertissement après l\'exception', sortie(), 'laissé en place');
});

/** Saisie scriptée : rend les réponses dans l'ordre et note les questions posées. */
function httpAuthSaisieTest(array $reponses, array &$questions): callable
{
    return static function (string $question) use (&$reponses, &$questions): string {
        $questions[] = $question;

        if ($reponses === []) {
            throw new \LogicException("Question non prévue : $question");
        }

        return array_shift($reponses);
    };
}

cas('httpAuthAskPassword : saisie vide, mot de passe généré', static function (): void {
    $questions = [];
    $resultat = httpAuthAskPassword(httpAuthSaisieTest([''], $questions));
    verifie('généré', $resultat['generated'], true);
    vrai('24 caractères de l\'alphabet', preg_match('/^[a-hjkmnp-zA-HJKLMNP-Z2-9]{24}$/', $resultat['password']) === 1);
    verifie('une seule question', $questions, ['Mot de passe HTTP (laisser vide pour en générer un) :']);
});

cas('httpAuthAskPassword : deux saisies identiques', static function (): void {
    $questions = [];
    verifie('résultat', httpAuthAskPassword(httpAuthSaisieTest(['abc', 'abc'], $questions)), ['password' => 'abc', 'generated' => false]);
    verifie('questions', $questions, ['Mot de passe HTTP (laisser vide pour en générer un) :', 'Confirmez le mot de passe :']);
});

cas('httpAuthAskPassword : saisies différentes, avertissement et nouvel essai', static function (): void {
    sortie();
    $questions = [];
    verifie('résultat', httpAuthAskPassword(httpAuthSaisieTest(['abc', 'abd', 'xyz', 'xyz'], $questions)), ['password' => 'xyz', 'generated' => false]);
    verifie('quatre questions', count($questions), 4);
    contient('avertissement', sortie(), 'Les deux saisies diffèrent, recommencez.');
});

cas('httpAuthAskPassword : trois écarts, exception', static function (): void {
    $questions = [];
    leve('exception', static function () use (&$questions): void {
        httpAuthAskPassword(httpAuthSaisieTest(['a', 'b', 'c', 'd', 'e', 'f'], $questions));
    }, \RuntimeException::class, 'Les mots de passe saisis diffèrent.');
    verifie('six questions', count($questions), 6);
});

cas('httpAuthAskPassword : saut de ligne refusé, compté comme un écart', static function (): void {
    sortie();
    $questions = [];
    verifie('résultat', httpAuthAskPassword(httpAuthSaisieTest(["a\nb", "c\rd", 'xyz', 'xyz'], $questions)), ['password' => 'xyz', 'generated' => false]);
    verifie('pas de confirmation demandée pour une saisie refusée', count($questions), 4);
    contient('avertissement', sortie(), 'saut de ligne');

    $questions = [];
    leve('trois refus : exception', static function () use (&$questions): void {
        httpAuthAskPassword(httpAuthSaisieTest(["a\n", "\rb", "c\r\n"], $questions));
    }, \RuntimeException::class, 'Les mots de passe saisis diffèrent.');
    verifie('trois questions', count($questions), 3);
});
