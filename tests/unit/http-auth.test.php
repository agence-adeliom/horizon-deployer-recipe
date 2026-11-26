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

cas('httpAuthBlock : IP contrôlées une à une (IPv4, IPv6, partielle, masque)', static function (): void {
    $valides = ['203.0.113.10', '2001:db8::1', '::ffff:192.0.2.1', '10.1', '10.1.', '198.51.100.0/24', '10.0.0.0/255.0.0.0', '2001:db8::/32', ' 198.51.100.7 '];
    contient('formes acceptées par Apache', httpAuthBlock('/p', 'r', $valides, []), '    Require ip 127.0.0.1 ::1 ' . implode(' ', array_map('trim', $valides)) . "\n");

    $refus = [
        'liste séparée par des virgules' => '203.0.113.10, 198.51.100.2',
        'liste séparée par une espace' => '203.0.113.10 198.51.100.2',
        'nom d\'hôte' => 'staging.site.test',
        'chaîne vide' => '',
        'espaces seules' => '  ',
        'sans chiffre' => 'cafe::',
        'masque invalide' => '10.0.0.0/x',
    ];

    foreach ($refus as $libelle => $ip) {
        leve($libelle, static function () use ($ip): void {
            httpAuthBlock('/p', 'r', ['203.0.113.10', $ip], []);
        }, \InvalidArgumentException::class, json_encode($ip, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
    // Avant PHP 8.3, fileperms() rend la valeur mise en cache pour ce chemin au premier appel.
    clearstatcache();
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
    $appel = uneCommande('curl local', '/^curl -q -sS -o \/dev\/null -w .*--max-time 20 /');
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
        Shell::quand('/^curl -q -sS -o /', (string) $code, 'local');
        Shell::quand('/^curl -fsS /', '203.0.113.10');

        $resultat = httpAuthProbe($h, '/srv/s/current/web', 'https://site.test');
        verifie("$code : statut", $resultat['status'], $statut);
        verifie("$code : code", $resultat['code'], $code === '' ? null : (int) $code);
        verifie("$code : IP", $resultat['ips'], $statut === 'ok' ? ['203.0.113.10'] : []);
        uneCommande("$code : dossier supprimé", '/rm -rf .*horizon-http-auth-/');

        if ($statut === 'ok') {
            foreach (['-4', '-6'] as $famille) {
                $appel = uneCommande("ok : IP lue depuis le serveur en $famille", '#^curl -fsS ' . $famille . ' .*https://site\.test/horizon-http-auth-[0-9a-f]{12}/#');
                verifie("ok : curl $famille lancé sur l'hôte", $appel['env'] ?? null, $h->getAlias());
            }
        } else {
            aucuneCommande("$code : pas de curl depuis le serveur", '/^curl -fsS /');
        }
    }
});

cas('httpAuthProbe : curl distant en échec ou sortie qui n\'est pas une IP', static function (): void {
    $h = hote('http-auth-sonde-echec');
    Shell::quand('/^curl -q -sS -o /', '401', 'local');
    Shell::quand('/^curl -fsS /', new \RuntimeException('curl: (22) 403'));

    verifie('exception : ok sans IP', httpAuthProbe($h, '/srv/s/current/web', 'https://site.test'), ['status' => 'ok', 'code' => 401, 'ips' => []]);
    uneCommande('exception : dossier supprimé', '/rm -rf .*horizon-http-auth-/');

    Shell::reinitialiser();
    Shell::quand('/^curl -q -sS -o /', '401', 'local');
    Shell::quand('/^curl -fsS /', '<html>Erreur</html>');
    verifie('pas une IP : ok sans IP', httpAuthProbe($h, '/srv/s/current/web', 'https://site.test'), ['status' => 'ok', 'code' => 401, 'ips' => []]);

    Shell::reinitialiser();
    Shell::quand('/^curl -q -sS -o /', '401', 'local');
    Shell::quand('/base64 -d/', new \RuntimeException('Permission denied'));
    leve('écriture impossible : exception', static function () use ($h): void {
        httpAuthProbe($h, '/srv/s/current/web', 'https://site.test');
    }, \RuntimeException::class, 'Permission denied');
    uneCommande('écriture impossible : dossier supprimé', '/rm -rf .*horizon-http-auth-/');
});

cas('httpAuthProbe : IPv4 et IPv6 mesurées depuis le serveur, famille absente ignorée, doublon retiré', static function (): void {
    $absente = new \RuntimeException('curl: (7) Failed to connect');
    $variantes = [
        'deux familles' => ["203.0.113.10\n", '2001:db8::10', ['203.0.113.10', '2001:db8::10']],
        'IPv4 seule' => ['203.0.113.10', $absente, ['203.0.113.10']],
        'IPv6 seule' => [$absente, '2001:db8::10', ['2001:db8::10']],
        'aucune' => [$absente, $absente, []],
        'sortie non IP ignorée' => ['<html>Erreur</html>', '2001:db8::10', ['2001:db8::10']],
        'même adresse' => ['203.0.113.10', '203.0.113.10', ['203.0.113.10']],
    ];

    foreach ($variantes as $libelle => [$v4, $v6, $attendu]) {
        $h = hote('http-auth-sonde-familles');
        Shell::reinitialiser();
        Shell::quand('/^curl -q -sS -o /', '401', 'local');
        Shell::quand('/^curl -fsS -4 /', $v4);
        Shell::quand('/^curl -fsS -6 /', $v6);

        verifie("$libelle : résultat", httpAuthProbe($h, '/srv/s/current/web', 'https://site.test'), ['status' => 'ok', 'code' => 401, 'ips' => $attendu]);
        uneCommande("$libelle : requête IPv4", '#^curl -fsS -4 --max-time 20 \'https://site\.test/horizon-http-auth-[0-9a-f]{12}/\'$#');
        uneCommande("$libelle : requête IPv6", '#^curl -fsS -6 --max-time 20 \'https://site\.test/horizon-http-auth-[0-9a-f]{12}/\'$#');
        uneCommande("$libelle : dossier supprimé", '/rm -rf .*horizon-http-auth-/');
    }
});

cas('httpAuthProbe : URL sans double barre (barre finale, sous-dossier)', static function (): void {
    $h = hote('http-auth-sonde-url');
    Shell::quand('/^curl -q -sS -o /', '200', 'local');

    httpAuthProbe($h, '/srv/s/current/web', 'https://site.test/fr/');
    uneCommande('sans double barre', '#https://site\.test/fr/horizon-http-auth-[0-9a-f]{12}/#');
    aucuneCommande('aucune double barre', '#site\.test/fr//#');
});

cas('httpAuthProbe : fichiers de la sonde sur le disque, puis supprimés (réel)', static function (): void {
    $racine = dossierTemporaire();
    $h = hote('http-auth-sonde-reelle');
    Shell::$reel = true;
    $vu = [];

    Shell::quand('/^curl -q -sS -o /', static function (array $appel) use ($racine, &$vu): string {
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
    verifie('résultat', $resultat, ['status' => 'ok', 'code' => 401, 'ips' => ['203.0.113.10']]);
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
    Shell::quand('/^curl -q -sS -o /', '401', 'local');
    Shell::quand('/^curl -fsS /', '203.0.113.10');
    Shell::quand('/^rm -rf /', new \RuntimeException('Device busy'));

    verifie('résultat conservé', httpAuthProbe($h, '/srv/s/current/web', 'https://site.test'), ['status' => 'ok', 'code' => 401, 'ips' => ['203.0.113.10']]);
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

/*
 * Tâche http-auth:setup et garde-fou partagé httpAuthGuard.
 */

/**
 * Hôte prêt pour http-auth:setup : http_auth activé, shared/.env de staging, saisie du mot de
 * passe scriptée par http_auth_password_asker (closure qui rend la fonction de saisie).
 */
function httpAuthHoteSetup(string $alias, array $config = [], array $saisies = [''], ?array &$questions = null): \Deployer\Host\Host
{
    $questions = [];
    $saisie = httpAuthSaisieTest($saisies, $questions);

    return hote($alias, array_merge([
        'http_auth' => true,
        'http_auth_password_asker' => static function () use ($saisie): callable {
            return $saisie;
        },
    ], $config));
}

/** Réponse du shared/.env : lignes « +dep-env: » de transferHostEnvironmentValues(). */
function httpAuthEnvTest(string $lignes = "WP_ENV=staging\nWP_HOME=https://staging.site.test"): void
{
    Shell::quand('/^grep -E /', implode("\n", array_map(static function (string $ligne): string {
        return '+dep-env:' . $ligne;
    }, explode("\n", $lignes))));
}

/**
 * Codes des curl locaux : sonde, avec identifiants, puis sans identifiants dans l'ordre
 * (contrôle initial, vérification finale). Le dernier code sans identifiants se répète.
 * $ipServeur : IP lue par la sonde depuis le serveur ; null, curl distant en échec (aucune
 * IP autre que locale dans le bloc, vérification finale stricte).
 */
function httpAuthCurlTest(array $sans, string $sonde = '401', string $avec = '200', ?string $ipServeur = '203.0.113.10'): void
{
    Shell::quand('/^curl -q -sS -o /', static function (array $appel) use (&$sans, $sonde, $avec): string {
        if (strpos($appel['commande'], 'horizon-http-auth-') !== false) {
            return $sonde;
        }

        if (strpos($appel['commande'], ' -K ') !== false) {
            return $avec;
        }

        return count($sans) > 1 ? array_shift($sans) : $sans[0];
    }, 'local');
    Shell::quand('/^curl -fsS /', $ipServeur ?? new \RuntimeException('curl: (22) 403'));
}

/** Contenu écrit par httpAuthWriteFile() sur $chemin, null si rien n'y a été écrit. */
function httpAuthEcritTest(string $chemin): ?string
{
    $contenu = null;

    foreach (Shell::commandes() as $commande) {
        if (strpos($commande, 'base64 -d > ' . escapeshellarg($chemin . '.horizon-tmp')) !== false
            && preg_match("/^\\{ set -o pipefail; printf %s '([A-Za-z0-9+\\/=]*)'/", $commande, $m) === 1) {
            $contenu = (string) base64_decode($m[1], true);
        }
    }

    return $contenu;
}

/** Écritures hors du dossier de la sonde. */
function httpAuthEcrituresHorsSonde(): array
{
    return array_values(array_filter(Shell::commandes(), static function (string $commande): bool {
        return (strpos($commande, 'base64 -d') !== false || strpos($commande, 'rm -f ') !== false)
            && strpos($commande, 'horizon-http-auth-') === false;
    }));
}

cas('httpAuthGuard : refus en production, information si le type est introuvable', static function (): void {
    $h = hote('http-auth-garde');
    httpAuthEnvTest("WP_ENV=preprod\nWP_HOME=https://preprod.site.test");
    leve('preprod refusée', static function () use ($h): void {
        httpAuthGuard($h);
    }, \Throwable::class, 'WP_ENV=staging');

    Shell::reinitialiser();
    httpAuthEnvTest("WP_ENV=staging\nWP_HOME=https://staging.site.test");
    verifie('staging : valeurs rendues', httpAuthGuard($h), ['WP_ENV' => 'staging', 'WP_HOME' => 'https://staging.site.test']);

    Shell::reinitialiser();
    sortie();
    verifie('introuvable, non protégé : poursuite', httpAuthGuard($h), []);
    contient('introuvable : information', sortie(), 'introuvable');

    $p = hote('http-auth-garde-prod');
    Shell::reinitialiser();
    leve('introuvable, protégé : refus', static function () use ($p): void {
        httpAuthGuard($p);
    }, \Throwable::class, 'hôte protégé');
});

cas('http-auth:setup : http_auth faux, aucune commande', static function (): void {
    $h = hote('http-auth-inactif');
    sortie();
    tache('http-auth:setup', $h);
    verifie('aucune commande', Shell::commandes(), []);
    contient('clé d\'inventaire citée', sortie(), 'http_auth: true');
});

cas('http-auth:setup : production refusée, sans écriture', static function (): void {
    $h = httpAuthHoteSetup('http-auth-prod-env');
    httpAuthEnvTest("WP_ENV=production\nWP_HOME=https://www.site.test");
    httpAuthCurlTest(['200']);
    entree([], true, ['y']);

    leve('erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, 'WP_ENV=production');
    aucuneCommande('aucune écriture', '/base64 -d/');
    aucuneCommande('aucun curl', '/curl /');
});

cas('http-auth:setup : WP_HOME absent, erreur sans écriture', static function (): void {
    $h = httpAuthHoteSetup('http-auth-sans-home');
    httpAuthEnvTest('WP_ENV=staging');
    entree([], true, ['y']);

    leve('erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, 'WP_HOME');
    aucuneCommande('aucune écriture', '/base64 -d/');
});

cas('http-auth:setup : protection existante (401), « non » sans écriture, « oui » poursuit', static function (): void {
    $h = httpAuthHoteSetup('http-auth-deja');
    httpAuthEnvTest();
    httpAuthCurlTest(['401']);
    entree([], true, ['n']);
    sortie();

    tache('http-auth:setup', $h);
    contient('avertissement', sortie(), 'répond déjà 401');
    verifie('aucune écriture', httpAuthEcrituresHorsSonde(), []);
    aucuneCommande('pas de sonde', '/horizon-http-auth-/');

    $h = httpAuthHoteSetup('http-auth-deja-oui');
    Shell::reinitialiser();
    httpAuthEnvTest();
    httpAuthCurlTest(['401']);
    entree([], true, ['y', 'y']);
    tache('http-auth:setup', $h);
    uneCommande('sonde lancée', '/mkdir .*horizon-http-auth-/');
    vrai('.htaccess écrit', httpAuthEcritTest('/srv/' . $h->getAlias() . '/current/web/.htaccess') !== null);
});

cas('http-auth:setup : IP invalide dans http_auth_allow_ips, refus avant toute question ni sonde', static function (): void {
    foreach ([true, false] as $interactif) {
        $questions = [];
        $h = httpAuthHoteSetup('http-auth-ip-invalide-' . ($interactif ? 'i' : 'n'), ['http_auth_allow_ips' => ['staging.site.test']], [''], $questions);
        Shell::reinitialiser();
        httpAuthEnvTest();
        httpAuthCurlTest(['200']);
        entree([], $interactif, ['y']);

        leve(($interactif ? 'interactif' : '-n') . ' : erreur', static function () use ($h): void {
            tache('http-auth:setup', $h);
        }, \Throwable::class, '"staging.site.test"');
        aucuneCommande(($interactif ? 'interactif' : '-n') . ' : pas de sonde', '/horizon-http-auth-/');
        verifie(($interactif ? 'interactif' : '-n') . ' : aucune écriture', httpAuthEcrituresHorsSonde(), []);
        verifie(($interactif ? 'interactif' : '-n') . ' : mot de passe non demandé', $questions, []);
    }
});

cas('http-auth:setup : -n, description sans écriture', static function (): void {
    $h = httpAuthHoteSetup('http-auth-n');
    httpAuthEnvTest();
    httpAuthCurlTest(['200']);
    sortie();

    tache('http-auth:setup', $h);
    $sortie = sortie();
    contient('mode non interactif', $sortie, 'Mode non interactif');
    contient('.htpasswd décrit', $sortie, '/srv/' . $h->getAlias() . '/shared/.htpasswd');
    contient('.htaccess décrit', $sortie, '/srv/' . $h->getAlias() . '/current/web/.htaccess');
    verifie('aucune écriture', httpAuthEcrituresHorsSonde(), []);
    aucuneCommande('pas de sonde', '/horizon-http-auth-/');
});

cas('http-auth:setup : fin d\'entrée sans TTY, défaut « non »', static function (): void {
    $h = httpAuthHoteSetup('http-auth-eof');
    httpAuthEnvTest();
    httpAuthCurlTest(['200']);
    entree([], true, []);

    tache('http-auth:setup', $h);
    verifie('aucune écriture', httpAuthEcrituresHorsSonde(), []);
    aucuneCommande('pas de sonde', '/horizon-http-auth-/');
});

cas('http-auth:setup : sonde ignorée ou en erreur, refus sans écriture hors sonde', static function (): void {
    foreach (['200' => 'ignore', '500' => 'refuse'] as $code => $fragment) {
        $questions = [];
        $h = httpAuthHoteSetup('http-auth-sonde-' . $code, [], [''], $questions);
        Shell::reinitialiser();
        httpAuthEnvTest();
        httpAuthCurlTest(['200'], (string) $code);
        entree([], true, ['y']);

        leve("$code : erreur", static function () use ($h): void {
            tache('http-auth:setup', $h);
        }, \Throwable::class, $fragment);
        verifie("$code : aucune écriture hors sonde", httpAuthEcrituresHorsSonde(), []);
        uneCommande("$code : sonde supprimée", '/rm -rf .*horizon-http-auth-/');
        verifie("$code : mot de passe non demandé", $questions, []);
    }
});

cas('http-auth:setup : parcours complet, mot de passe généré, deploy_path en « ~/ »', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-complet', ['deploy_path' => '~/sites/s'], [''], $questions);
    Shell::quand('/^printf %s "\$HOME"$/', '/home/compte');
    httpAuthEnvTest();
    httpAuthCurlTest(['200', '401']);
    $wp = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
    Shell::quand('#^if \[ -f \'/home/compte/sites/s/current/web/\.htaccess\' \]#', "+dep\n" . base64_encode($wp));
    Shell::quand('/^stat -c %a /', "640\n");
    entree([], true, ['y']);
    sortie();

    tache('http-auth:setup', $h);
    $sortie = sortie();

    verifie('une saisie', count($questions), 1);
    vrai('mot de passe affiché', preg_match('/Mot de passe : ([a-hjkmnp-zA-HJKLMNP-Z2-9]{24})\b/', $sortie, $m) === 1);
    $clair = $m[1] ?? 'introuvable';
    verifie('affiché une seule fois', substr_count($sortie, $clair), 1);
    contient('consigne Bitwarden', $sortie, 'Copiez-le dans Bitwarden : il ne sera plus affiché.');
    contient('URL', $sortie, 'https://staging.site.test');
    contient('identifiant', $sortie, 'adeliom');
    contient('IP détectée', $sortie, '203.0.113.10');
    absent('mot de passe absent des commandes', implode("\n", Shell::commandes()), $clair);

    $htpasswd = httpAuthEcritTest('/home/compte/sites/s/shared/.htpasswd');
    vrai('.htpasswd écrit', $htpasswd !== null);
    vrai('hash vérifiable', password_verify($clair, substr(trim((string) $htpasswd), strlen('adeliom:'))));
    contient('.htpasswd en 644', implode("\n", Shell::commandes()), 'chmod 644 ' . escapeshellarg('/home/compte/sites/s/shared/.htpasswd.horizon-tmp'));

    $htaccess = (string) httpAuthEcritTest('/home/compte/sites/s/current/web/.htaccess');
    contient('AuthUserFile absolu', $htaccess, 'AuthUserFile "/home/compte/sites/s/shared/.htpasswd"');
    contient('IP de la sonde', $htaccess, 'Require ip 127.0.0.1 ::1 203.0.113.10');
    vrai('bloc en tête, reste conservé', substr($htaccess, -strlen("\n" . $wp)) === "\n" . $wp && strpos($htaccess, '# BEGIN Horizon HTTP auth') === 0);
    contient('droits conservés', implode("\n", Shell::commandes()), 'chmod 640 ' . escapeshellarg('/home/compte/sites/s/current/web/.htaccess.horizon-tmp'));

    $ecritures = httpAuthEcrituresHorsSonde();
    vrai('.htpasswd avant .htaccess', count($ecritures) === 2 && strpos($ecritures[0], '.htpasswd') !== false && strpos($ecritures[1], '.htaccess') !== false);
    uneCommande('vérification avec identifiants', '/^curl -q -sS -o .* -K /');
    uneCommande('sonde dans la racine publique de la release', '#^mkdir \'/home/compte/sites/s/current/web/horizon-http-auth-[0-9a-f]{12}\'$#');
});

cas('http-auth:setup : IP de la sonde puis http_auth_allow_ips, .htaccess créé en 644', static function (): void {
    $h = httpAuthHoteSetup('http-auth-ips', ['http_auth_allow_ips' => ['198.51.100.7']], ['secret', 'secret']);
    httpAuthEnvTest();
    httpAuthCurlTest(['200', '401']);
    entree([], true, ['y']);
    sortie();

    tache('http-auth:setup', $h);
    $chemin = '/srv/' . $h->getAlias() . '/current/web/.htaccess';
    $htaccess = (string) httpAuthEcritTest($chemin);
    contient('IP dans l\'ordre', $htaccess, 'Require ip 127.0.0.1 ::1 203.0.113.10 198.51.100.7');
    verifie('fichier créé : bloc seul', $htaccess, httpAuthBlock('/srv/' . $h->getAlias() . '/shared/.htpasswd', 'Accès restreint', ['203.0.113.10', '198.51.100.7'], ['/.well-known/acme-challenge/']));
    contient('créé en 644', implode("\n", Shell::commandes()), 'chmod 644 ' . escapeshellarg($chemin . '.horizon-tmp'));
    aucuneCommande('pas de stat sur un fichier absent', '/^stat /');
    absent('mot de passe saisi non affiché', sortie(), 'secret');
    absent('mot de passe saisi absent des commandes', implode("\n", Shell::commandes()), 'secret');
});

cas('http-auth:setup : IPv4 et IPv6 du serveur dans le bloc et le récapitulatif', static function (): void {
    $h = httpAuthHoteSetup('http-auth-deux-ip', ['http_auth_allow_ips' => ['198.51.100.7']]);
    httpAuthEnvTest();
    Shell::quand('/^curl -fsS -6 /', "2001:db8::10\n");
    httpAuthCurlTest(['200', '401']);
    entree([], true, ['y']);
    sortie();

    tache('http-auth:setup', $h);
    $sortie = sortie();
    contient('deux IP du serveur, puis http_auth_allow_ips', (string) httpAuthEcritTest(httpAuthCheminsTest($h)[0]), 'Require ip 127.0.0.1 ::1 203.0.113.10 2001:db8::10 198.51.100.7' . "\n");
    contient('récapitulatif', $sortie, 'IP détectées pour le serveur : 203.0.113.10, 2001:db8::10');
    absent('pas d\'avertissement d\'IP manquante', $sortie, 'non détectée');

    // Aucune IP : avertissement, récapitulatif « aucune ».
    $h = httpAuthHoteSetup('http-auth-aucune-ip');
    Shell::reinitialiser();
    httpAuthEnvTest();
    httpAuthCurlTest(['200', '401'], '401', '200', null);
    entree([], true, ['y']);
    sortie();

    tache('http-auth:setup', $h);
    $sortie = sortie();
    contient('avertissement', $sortie, 'Aucune IP du serveur détectée');
    contient('récapitulatif', $sortie, 'IP détectées pour le serveur : aucune');
    contient('bloc sans IP du serveur', (string) httpAuthEcritTest(httpAuthCheminsTest($h)[0]), 'Require ip 127.0.0.1 ::1' . "\n");
});

cas('http-auth:setup : 200 sans identifiants, IPv4 et IPv6 citées dans l\'avertissement non concluant', static function (): void {
    $h = httpAuthHoteSetup('http-auth-deux-ip-non-concluant');
    httpAuthEnvTest();
    Shell::quand('/^curl -fsS -6 /', '2001:db8::10');
    httpAuthCurlTest(['200', '200']);
    entree([], true, ['y']);
    sortie();

    tache('http-auth:setup', $h);
    contient('IP citées', sortie(), 'les IP du serveur (203.0.113.10, 2001:db8::10)');
});

cas('http-auth:setup : .htpasswd existant conservé, renvoi vers http-auth:update', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-existant', [], [], $questions);
    httpAuthEnvTest();
    httpAuthCurlTest(['200', '401']);
    Shell::quand('#^if \[ -f \'/srv/[^\']+/shared/\.htpasswd\' \]#', "+dep\n" . base64_encode("client:\$2y\$10\$abc\n"));
    entree([], true, ['y']);
    sortie();

    tache('http-auth:setup', $h);
    $sortie = sortie();
    verifie('aucune saisie', $questions, []);
    contient('renvoi vers update', $sortie, 'http-auth:update');
    contient('identifiant existant signalé', $sortie, 'client');
    contient('identifiant différent : avertissement', $sortie, 'http_auth_user');
    verifie('.htpasswd non réécrit', httpAuthEcritTest('/srv/' . $h->getAlias() . '/shared/.htpasswd'), null);
    vrai('.htaccess écrit', httpAuthEcritTest('/srv/' . $h->getAlias() . '/current/web/.htaccess') !== null);
    aucuneCommande('pas de vérification avec identifiants', '/ -K /');
    absent('pas de mot de passe affiché', $sortie, 'Bitwarden');
});

cas('http-auth:setup : vérification finale en échec, .htaccess d\'origine remis, tâche en erreur', static function (): void {
    $h = httpAuthHoteSetup('http-auth-verif');
    httpAuthEnvTest();
    httpAuthCurlTest(['200'], '401', '200', null);
    $wp = "# BEGIN WordPress\r\nRewriteEngine On\r\n# END WordPress";
    $chemin = '/srv/' . $h->getAlias() . '/current/web/.htaccess';
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($chemin), '#') . ' \]#', "+dep\n" . base64_encode($wp));
    entree([], true, ['y']);

    leve('erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, '401 attendu');
    verifie('.htaccess d\'origine réécrit tel quel', httpAuthEcritTest($chemin), $wp);

    $h = httpAuthHoteSetup('http-auth-verif-cree');
    Shell::reinitialiser();
    httpAuthEnvTest();
    httpAuthCurlTest(['200', '401'], '401', '401');
    entree([], true, ['y']);

    leve('avec identifiants refusés : erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, '200 ou 30x attendu');
    uneCommande('.htaccess créé puis supprimé', '#^rm -f ' . preg_quote(escapeshellarg('/srv/' . $h->getAlias() . '/current/web/.htaccess'), '#') . '$#');
});

cas('http-auth:setup : de bout en bout sur le disque (réel, curl simulés)', static function (): void {
    $racine = dossierTemporaire();
    mkdir("$racine/releases/1/web", 0777, true);
    mkdir("$racine/shared/web", 0777, true);
    symlink("$racine/releases/1", "$racine/current");
    file_put_contents("$racine/shared/.env", "WP_ENV='staging'\nWP_HOME='https://staging.site.test'\n");
    $wp = "# BEGIN WordPress\r\nRewriteEngine On\r\n# END WordPress";
    // .htaccess partagé : le bloc va dans shared/, la sonde dans la racine publique de la release.
    file_put_contents("$racine/shared/web/.htaccess", $wp);
    chmod("$racine/shared/web/.htaccess", 0640);
    symlink("$racine/shared/web/.htaccess", "$racine/releases/1/web/.htaccess");
    $sondes = [];

    $questions = [];
    $saisie = httpAuthSaisieTest(['mot de "passe"', 'mot de "passe"'], $questions);
    $h = hoteLocal('http-auth-reel', [
        'deploy_path' => $racine,
        'http_auth' => true,
        'http_auth_password_asker' => static function () use ($saisie): callable {
            return $saisie;
        },
    ]);
    Shell::$reel = true;
    $avec = null;
    Shell::quand('/^curl -q -sS -o /', static function (array $appel) use ($racine, &$avec, &$sondes): string {
        if (strpos($appel['commande'], 'horizon-http-auth-') !== false) {
            $sondes = glob("$racine/releases/1/web/horizon-http-auth-*");

            return '401';
        }

        if (preg_match("/ -K '([^']+)'/", $appel['commande'], $m) === 1) {
            $avec = file_get_contents($m[1]);

            return '302';
        }

        return strpos((string) file_get_contents("$racine/shared/web/.htaccess"), 'AuthType Basic') !== false ? '401' : '200';
    });
    Shell::quand('/^curl -fsS /', "203.0.113.10\n");
    entree([], true, ['y']);
    sortie();

    tache('http-auth:setup', $h);

    $htaccess = (string) file_get_contents("$racine/shared/web/.htaccess");
    verifie('.htaccess final, dans shared/', $htaccess, httpAuthBlock("$racine/shared/.htpasswd", 'Accès restreint', ['203.0.113.10'], ['/.well-known/acme-challenge/']) . "\n" . $wp);
    vrai('lien de la release intact', is_link("$racine/releases/1/web/.htaccess"));
    verifie('droits du .htaccess conservés', fileperms("$racine/shared/web/.htaccess") & 0777, 0640);
    verifie('sonde dans la racine publique de la release', count($sondes), 1);
    $htpasswd = (string) file_get_contents("$racine/shared/.htpasswd");
    vrai('.htpasswd : identifiant et hash', strpos($htpasswd, 'adeliom:$2y$') === 0 && password_verify('mot de "passe"', substr(trim($htpasswd), 8)));
    verifie('.htpasswd en 644', fileperms("$racine/shared/.htpasswd") & 0777, 0644);
    verifie('identifiants de la vérification', $avec, 'user = "adeliom:mot de \\"passe\\""' . "\n");
    verifie('sonde supprimée', glob("$racine/releases/1/web/horizon-http-auth-*"), []);
    absent('mot de passe absent des commandes', implode("\n", Shell::commandes()), 'mot de "passe"');
    contient('récapitulatif', sortie(), 'https://staging.site.test');
});

cas('http-auth:setup : site déjà protégé (401 initial), 401 avec identifiants : bloc conservé, avertissement', static function (): void {
    $h = httpAuthHoteSetup('http-auth-deja-verif');
    httpAuthEnvTest();
    httpAuthCurlTest(['401'], '401', '401');
    entree([], true, ['y', 'y']);
    sortie();

    tache('http-auth:setup', $h);
    $sortie = sortie();
    $chemin = '/srv/' . $h->getAlias() . '/current/web/.htaccess';
    contient('avertissement', $sortie, 'Vérification non concluante');
    contient('test au navigateur', $sortie, 'navigateur');
    contient('récapitulatif', $sortie, 'Authentification HTTP installée');
    vrai('bloc écrit', strpos((string) httpAuthEcritTest($chemin), '# BEGIN Horizon HTTP auth') === 0);
    aucuneCommande('pas de retrait', '#^rm -f #');
});

cas('http-auth:setup : site déjà protégé, mais 200 sans identifiants après installation : retrait et erreur', static function (): void {
    $h = httpAuthHoteSetup('http-auth-deja-echec');
    httpAuthEnvTest();
    httpAuthCurlTest(['401', '200'], '401', '200', null);
    entree([], true, ['y', 'y']);

    leve('erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, '401 attendu');
    uneCommande('.htaccess créé puis supprimé', '#^rm -f ' . preg_quote(escapeshellarg('/srv/' . $h->getAlias() . '/current/web/.htaccess'), '#') . '$#');
});

cas('http-auth:setup : site déjà protégé, identifiants en 500 : retrait et erreur', static function (): void {
    $h = httpAuthHoteSetup('http-auth-deja-500');
    httpAuthEnvTest();
    httpAuthCurlTest(['401'], '401', '500');
    entree([], true, ['y', 'y']);

    leve('erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, '200 ou 30x attendu');
    uneCommande('.htaccess retiré', '#^rm -f #');
});

cas('http-auth:setup : 200 ou 30x sans identifiants avec d\'autres IP autorisées, non concluant : bloc conservé, avertissement', static function (): void {
    $variantes = [
        'IP du serveur, 200' => [['200', '200'], '203.0.113.10', [], '203.0.113.10'],
        'http_auth_allow_ips seules, 302' => [['200', '302'], null, ['198.51.100.7'], '198.51.100.7'],
    ];

    foreach ($variantes as $libelle => [$sans, $ip, $autorisees, $citee]) {
        $h = httpAuthHoteSetup('http-auth-non-concluant-' . substr(md5($libelle), 0, 6), ['http_auth_allow_ips' => $autorisees]);
        Shell::reinitialiser();
        httpAuthEnvTest();
        httpAuthCurlTest($sans, '401', '200', $ip);
        entree([], true, ['y']);
        sortie();

        tache('http-auth:setup', $h);
        $sortie = sortie();
        contient("$libelle : avertissement", $sortie, 'Vérification non concluante');
        contient("$libelle : réglage cité", $sortie, 'http_auth_allow_ips');
        contient("$libelle : IP citée", $sortie, $citee);
        contient("$libelle : test depuis un autre réseau", $sortie, 'autre réseau');
        contient("$libelle : récapitulatif avec le mot de passe", $sortie, 'Copiez-le dans Bitwarden');
        vrai("$libelle : bloc écrit", strpos((string) httpAuthEcritTest(httpAuthCheminsTest($h)[0]), '# BEGIN Horizon HTTP auth') === 0);
        aucuneCommande("$libelle : pas de retrait", '#^rm -f #');
    }
});

cas('http-auth:setup : vérification stricte sans autre IP autorisée, ou code d\'erreur', static function (): void {
    $variantes = [
        '200 sans autre IP' => [['200', '200'], null, '401 attendu'],
        '500 malgré l\'IP du serveur' => [['200', '500'], '203.0.113.10', '401 attendu'],
        'aucune réponse malgré l\'IP du serveur' => [['200', '000'], '203.0.113.10', '401 attendu'],
    ];

    foreach ($variantes as $libelle => [$sans, $ip, $fragment]) {
        $h = httpAuthHoteSetup('http-auth-strict-' . substr(md5($libelle), 0, 6));
        Shell::reinitialiser();
        httpAuthEnvTest();
        httpAuthCurlTest($sans, '401', '200', $ip);
        entree([], true, ['y']);
        sortie();

        leve("$libelle : erreur", static function () use ($h): void {
            tache('http-auth:setup', $h);
        }, \Throwable::class, $fragment);
        absent("$libelle : pas d'avertissement non concluant", sortie(), 'non concluante');
        uneCommande("$libelle : .htaccess retiré", '#^rm -f ' . preg_quote(escapeshellarg(httpAuthCheminsTest($h)[0]), '#') . '$#');
    }
});

cas('http-auth:setup : sonde redirigée (30x), WP_HOME de shared/.env mis en cause', static function (): void {
    foreach (['301' => true, '403' => false] as $code => $redirection) {
        $h = httpAuthHoteSetup('http-auth-sonde-redirigee-' . $code);
        Shell::reinitialiser();
        httpAuthEnvTest();
        httpAuthCurlTest(['200'], (string) $code);
        entree([], true, ['y']);

        $e = leve("$code : erreur", static function () use ($h): void {
            tache('http-auth:setup', $h);
        }, \Throwable::class, "code $code");
        $message = $e !== null ? $e->getMessage() : '';

        if ($redirection) {
            contient("$code : WP_HOME de shared/.env cité", $message, 'WP_HOME de shared/.env');
        } else {
            absent("$code : sans mention de WP_HOME", $message, 'WP_HOME');
        }

        verifie("$code : aucune écriture hors sonde", httpAuthEcrituresHorsSonde(), []);
    }
});

cas('http-auth:setup : 401 dû au bloc Horizon déjà en place, information sans question supplémentaire', static function (): void {
    $h = httpAuthHoteSetup('http-auth-bloc-horizon');
    [$htaccess, $htpasswd] = httpAuthCheminsTest($h);
    httpAuthEnvTest();
    httpAuthCurlTest(['401']);
    $ancien = httpAuthBlock($htpasswd, 'Accès restreint', ['192.0.2.50'], ['/.well-known/acme-challenge/']) . "\n# BEGIN WordPress\n# END WordPress\n";
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htaccess), '#') . ' \]#', "+dep\n" . base64_encode($ancien));
    // Une seule réponse : une seconde question tomberait sur la fin d'entrée (« non »).
    entree([], true, ['y']);
    sortie();

    tache('http-auth:setup', $h);
    $sortie = sortie();
    contient('bloc Horizon signalé', $sortie, 'bloc Horizon');
    contient('remplacement annoncé', $sortie, 'sera remplacé');
    absent('pas d\'avertissement de protection inconnue', $sortie, 'mu-plugin');
    absent('pas de question « Installer quand même ? »', $sortie, 'Installer quand même');
    $ecrit = (string) httpAuthEcritTest($htaccess);
    contient('IP à jour', $ecrit, 'Require ip 127.0.0.1 ::1 203.0.113.10' . "\n");
    absent('ancienne IP retirée', $ecrit, '192.0.2.50');

    // Avec le bloc Horizon pour seule protection, la vérification reste stricte.
    $h = httpAuthHoteSetup('http-auth-bloc-horizon-strict');
    [$htaccess, $htpasswd] = httpAuthCheminsTest($h);
    Shell::reinitialiser();
    httpAuthEnvTest();
    httpAuthCurlTest(['401'], '401', '401');
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htaccess), '#') . ' \]#', "+dep\n" . base64_encode(httpAuthBlock($htpasswd, 'Accès restreint', [], [])));
    entree([], true, ['y']);
    sortie();

    leve('401 avec identifiants : erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, '200 ou 30x attendu');
    absent('pas d\'avertissement non concluant', sortie(), 'non concluante');

    // En -n : l'information, sans avertissement de protection inconnue.
    $h = httpAuthHoteSetup('http-auth-bloc-horizon-n');
    [$htaccess, $htpasswd] = httpAuthCheminsTest($h);
    Shell::reinitialiser();
    httpAuthEnvTest();
    httpAuthCurlTest(['401']);
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htaccess), '#') . ' \]#', "+dep\n" . base64_encode(httpAuthBlock($htpasswd, 'Accès restreint', [], [])));
    sortie();

    tache('http-auth:setup', $h);
    $sortie = sortie();
    contient('-n : bloc Horizon signalé', $sortie, 'bloc Horizon');
    absent('-n : pas d\'avertissement de protection inconnue', $sortie, 'mu-plugin');
    verifie('-n : aucune écriture', httpAuthEcrituresHorsSonde(), []);
});

/*
 * Remise en état de http-auth:setup après la première écriture.
 */

/** Chemins de l'hôte de test : [.htaccess, .htpasswd]. */
function httpAuthCheminsTest(\Deployer\Host\Host $h): array
{
    return ['/srv/' . $h->getAlias() . '/current/web/.htaccess', '/srv/' . $h->getAlias() . '/shared/.htpasswd'];
}

/** Sortie qui lève une exception, une seule fois, à l'écriture d'un message contenant $marqueur. */
final class SortieQuiLeve extends \Symfony\Component\Console\Output\BufferedOutput
{
    /** @var string */
    public $marqueur = '';

    protected function doWrite(string $message, bool $newline): void
    {
        if ($this->marqueur !== '' && strpos($message, $this->marqueur) !== false) {
            $this->marqueur = '';

            throw new \RuntimeException('Sortie en échec pendant la vérification');
        }

        parent::doWrite($message, $newline);
    }
}

cas('http-auth:setup : vérification en échec, .htpasswd créé supprimé, message fidèle', static function (): void {
    $h = httpAuthHoteSetup('http-auth-nettoyage');
    [$htaccess, $htpasswd] = httpAuthCheminsTest($h);
    httpAuthEnvTest();
    httpAuthCurlTest(['200'], '401', '200', null);
    $wp = "# BEGIN WordPress\nRewriteEngine On\n# END WordPress\n";
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htaccess), '#') . ' \]#', "+dep\n" . base64_encode($wp));
    entree([], true, ['y']);

    $e = leve('erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, '401 attendu');
    $message = $e !== null ? $e->getMessage() : '';
    contient('.htaccess remis', $message, "Le .htaccess d'origine ($htaccess) est remis en place.");
    contient('.htpasswd supprimé', $message, "Le .htpasswd créé ($htpasswd) est supprimé.");
    uneCommande('rm du .htpasswd', '#^rm -f ' . preg_quote(escapeshellarg($htpasswd), '#') . '$#');
    verifie('.htaccess d\'origine réécrit', httpAuthEcritTest($htaccess), $wp);
});

cas('http-auth:setup : vérification en échec, .htpasswd existant jamais supprimé', static function (): void {
    $h = httpAuthHoteSetup('http-auth-nettoyage-existant', [], []);
    [$htaccess, $htpasswd] = httpAuthCheminsTest($h);
    httpAuthEnvTest();
    httpAuthCurlTest(['200'], '401', '200', null);
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htpasswd), '#') . ' \]#', "+dep\n" . base64_encode("adeliom:\$2y\$10\$abc\n"));
    entree([], true, ['y']);

    $e = leve('erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, '401 attendu');
    contient('.htaccess créé supprimé', $e !== null ? $e->getMessage() : '', "Le .htaccess créé ($htaccess) est supprimé.");
    absent('.htpasswd non cité', $e !== null ? $e->getMessage() : '', '.htpasswd');
    aucuneCommande('.htpasswd intact', '#^rm -f ' . preg_quote(escapeshellarg($htpasswd), '#') . '#');
});

cas('http-auth:setup : bloc identique déjà présent, vérification en échec : rien n\'est prétendu remis', static function (): void {
    $h = httpAuthHoteSetup('http-auth-identique', [], []);
    [$htaccess, $htpasswd] = httpAuthCheminsTest($h);
    httpAuthEnvTest();
    httpAuthCurlTest(['200'], '401', '200', null);
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htpasswd), '#') . ' \]#', "+dep\n" . base64_encode("adeliom:\$2y\$10\$abc\n"));
    $original = httpAuthBlock($htpasswd, 'Accès restreint', [], ['/.well-known/acme-challenge/']);
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htaccess), '#') . ' \]#', "+dep\n" . base64_encode($original));
    entree([], true, ['y']);

    $e = leve('erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, '401 attendu');
    contient('.htaccess non modifié', $e !== null ? $e->getMessage() : '', 'Le .htaccess n\'a pas été modifié.');
    absent('pas de « remis en place »', $e !== null ? $e->getMessage() : '', 'remis en place');
    verifie('aucune écriture', httpAuthEcrituresHorsSonde(), []);
});

cas('http-auth:setup : remise en état impossible, message qui le dit', static function (): void {
    $h = httpAuthHoteSetup('http-auth-remise-ko');
    [$htaccess, $htpasswd] = httpAuthCheminsTest($h);
    httpAuthEnvTest();
    httpAuthCurlTest(['200'], '401', '200', null);
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htaccess), '#') . ' \]#', "+dep\n" . base64_encode("# WordPress\n"));
    $ecritures = 0;
    Shell::quand('#base64 -d > ' . preg_quote(escapeshellarg($htaccess . '.horizon-tmp'), '#') . '#', static function () use (&$ecritures): string {
        if (++$ecritures > 1) {
            throw new \RuntimeException('Disque plein');
        }

        return '';
    });
    entree([], true, ['y']);

    $e = leve('erreur', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, '401 attendu');
    contient('échec de la remise signalé', $e !== null ? $e->getMessage() : '', "Le .htaccess ($htaccess) n'a pas pu être remis en état : à vérifier à la main.");
    absent('pas de « remis en place »', $e !== null ? $e->getMessage() : '', 'remis en place');
    contient('.htpasswd tout de même supprimé', $e !== null ? $e->getMessage() : '', "Le .htpasswd créé ($htpasswd) est supprimé.");
});

cas('http-auth:setup : exception à l\'écriture du .htaccess, .htpasswd créé supprimé, exception relancée', static function (): void {
    $h = httpAuthHoteSetup('http-auth-exception-ecriture');
    [$htaccess, $htpasswd] = httpAuthCheminsTest($h);
    httpAuthEnvTest();
    httpAuthCurlTest(['200', '401']);
    Shell::quand('#base64 -d > ' . preg_quote(escapeshellarg($htaccess . '.horizon-tmp'), '#') . '#', new \RuntimeException('Permission denied'));
    entree([], true, ['y']);
    sortie();

    leve('exception d\'origine', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \RuntimeException::class, 'Permission denied');
    $sortie = sortie();
    contient('interruption signalée', $sortie, 'Installation interrompue.');
    contient('.htpasswd supprimé', $sortie, "Le .htpasswd créé ($htpasswd) est supprimé.");
    uneCommande('rm du .htpasswd', '#^rm -f ' . preg_quote(escapeshellarg($htpasswd), '#') . '$#');
    aucuneCommande('pas de vérification', '/^curl -q -sS -o .* -K /');
});

cas('http-auth:setup : exception pendant la vérification, .htaccess remis octet pour octet, .htpasswd supprimé', static function (): void {
    // L'avertissement « non concluante » de l'étape 11 lève : toute exception après la première
    // écriture doit remettre les fichiers en état.
    $h = httpAuthHoteSetup('http-auth-exception-verif');
    [$htaccess, $htpasswd] = httpAuthCheminsTest($h);
    httpAuthEnvTest();
    httpAuthCurlTest(['401'], '401', '401');
    $wp = "# BEGIN WordPress\r\nRewriteEngine On\r\n# END WordPress";
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htaccess), '#') . ' \]#', "+dep\n" . base64_encode($wp));
    entree([], true, ['y', 'y']);

    $deployer = Deployer::get();
    $sortie = new SortieQuiLeve(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_NORMAL, false);
    $sortie->marqueur = 'Vérification non concluante';
    unset($deployer['output']);
    $deployer['output'] = static function () use ($sortie) {
        return $sortie;
    };

    try {
        leve('exception relancée', static function () use ($h): void {
            tache('http-auth:setup', $h);
        }, \RuntimeException::class, 'Sortie en échec pendant la vérification');
    } finally {
        unset($deployer['output']);
        $deployer['output'] = static function () {
            return $GLOBALS[SORTIE];
        };
    }

    uneCommande('vérification lancée', '/^curl -q -sS -o .* -K /');
    verifie('.htaccess d\'origine réécrit octet pour octet', httpAuthEcritTest($htaccess), $wp);
    uneCommande('rm du .htpasswd', '#^rm -f ' . preg_quote(escapeshellarg($htpasswd), '#') . '$#');
    contient('remise en état signalée', $sortie->fetch(), "Le .htaccess d'origine ($htaccess) est remis en place.");
});

cas('http-auth:setup : -n sur un site qui répond 401, description sans question', static function (): void {
    $h = httpAuthHoteSetup('http-auth-n-401');
    httpAuthEnvTest();
    httpAuthCurlTest(['401']);
    sortie();

    tache('http-auth:setup', $h);
    $sortie = sortie();
    contient('avertissement 401', $sortie, 'répond déjà 401');
    contient('description', $sortie, 'Sans -n, la tâche :');
    absent('pas de question ignorée', $sortie, 'question ignorée');
    verifie('aucune écriture', httpAuthEcrituresHorsSonde(), []);
});

cas('http-auth:setup : .htpasswd sans identifiant lisible, refus et renvoi vers http-auth:update', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-htpasswd-vide', [], [], $questions);
    [, $htpasswd] = httpAuthCheminsTest($h);
    httpAuthEnvTest();
    httpAuthCurlTest(['200', '401']);
    Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($htpasswd), '#') . ' \]#', "+dep\n" . base64_encode("\n"));
    entree([], true, ['y']);

    leve('refus', static function () use ($h): void {
        tache('http-auth:setup', $h);
    }, \Throwable::class, 'http-auth:update');
    verifie('aucune écriture', httpAuthEcrituresHorsSonde(), []);
    verifie('aucune saisie', $questions, []);
});

/** Espion : ne doit jamais être appelé par la tâche. */
function httpAuthSaisieEspion(string $question): string
{
    $GLOBALS['http_auth_espion'] = $question;

    return 'piege';
}

cas('http-auth:setup : http_auth_password_asker qui n\'est pas une closure, ignoré', static function (): void {
    unset($GLOBALS['http_auth_espion']);
    $h = hote('http-auth-asker-chaine', ['http_auth' => true, 'http_auth_password_asker' => 'Deployer\httpAuthSaisieEspion']);
    httpAuthEnvTest();
    httpAuthCurlTest(['200', '401']);
    entree([], true, ['y']);

    leve('saisie masquée réelle, impossible sans TTY', static function () use ($h): void {
        tache('http-auth:setup', $h);
    });
    vrai('chaîne jamais appelée', !isset($GLOBALS['http_auth_espion']));
    verifie('aucune écriture', httpAuthEcrituresHorsSonde(), []);
});

/*
 * Tâche http-auth:update.
 */

/**
 * Installation existante sur l'hôte de test : .htpasswd de $htpasswd (null : absent) et
 * .htaccess de $htaccess (null : absent). Rend [.htaccess, .htpasswd].
 */
function httpAuthInstallationTest(\Deployer\Host\Host $h, ?string $htpasswd = "adeliom:\$2y\$10\$abc\n", ?string $htaccess = null): array
{
    [$cheminHtaccess, $cheminHtpasswd] = httpAuthCheminsTest($h);
    $htaccess = $htaccess ?? httpAuthBlock($cheminHtpasswd, 'Accès restreint', [], ['/.well-known/acme-challenge/']) . "\n# BEGIN WordPress\n# END WordPress\n";

    if ($htpasswd !== null) {
        Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($cheminHtpasswd), '#') . ' \]#', "+dep\n" . base64_encode($htpasswd));
    }

    if ($htaccess !== '') {
        Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($cheminHtaccess), '#') . ' \]#', "+dep\n" . base64_encode($htaccess));
    }

    return [$cheminHtaccess, $cheminHtpasswd];
}

/** Code des curl locaux avec identifiants ; tout curl sans identifiants répond $sans. */
function httpAuthCurlUpdateTest(string $avec, string $sans = '401'): void
{
    Shell::quand('/^curl -q -sS -o /', static function (array $appel) use ($avec, $sans): string {
        return strpos($appel['commande'], ' -K ') !== false ? $avec : $sans;
    }, 'local');
}

/** Aucune écriture : ni copie, ni fichier écrit, ni déplacement, ni suppression. */
function httpAuthAucuneEcritureUpdate(string $prefixe): void
{
    aucuneCommande("$prefixe : aucune écriture", '/base64 -d|^cp |^mv |^rm /');
}

cas('http-auth:update : refus sans .htpasswd, renvoi vers http-auth:setup', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-maj-sans-htpasswd', [], [], $questions);
    httpAuthInstallationTest($h, null);
    httpAuthEnvTest();
    entree([], true, ['', 'y']);

    leve('refus', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \Throwable::class, 'http-auth:setup');
    httpAuthAucuneEcritureUpdate('sans .htpasswd');
    verifie('aucune saisie', $questions, []);
});

cas('http-auth:update : refus sans bloc (absent, .htaccess absent, bloc mal formé)', static function (): void {
    $cas = [
        'sans-bloc' => ["# BEGIN WordPress\n# END WordPress\n", 'http-auth:setup'],
        'sans-htaccess' => ['', 'http-auth:setup'],
        'mal-forme' => ["# BEGIN Horizon HTTP auth\nAuthType Basic\n", 'à la main'],
    ];

    foreach ($cas as $nom => [$htaccess, $fragment]) {
        $questions = [];
        $h = httpAuthHoteSetup('http-auth-maj-' . $nom, [], [], $questions);
        Shell::reinitialiser();
        httpAuthInstallationTest($h, "adeliom:\$2y\$10\$abc\n", $htaccess);
        httpAuthEnvTest();
        entree([], true, ['', 'y']);

        leve("$nom : refus", static function () use ($h): void {
            tache('http-auth:update', $h);
        }, \Throwable::class, $fragment);
        httpAuthAucuneEcritureUpdate($nom);
        verifie("$nom : aucune saisie", $questions, []);
    }
});

cas('http-auth:update : refus en production, sans écriture', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-maj-prod', [], [], $questions);
    httpAuthInstallationTest($h);
    httpAuthEnvTest("WP_ENV=production\nWP_HOME=https://www.site.test");
    httpAuthCurlUpdateTest('200');
    entree([], true, ['', 'y']);

    leve('refus', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \Throwable::class, 'WP_ENV=production');
    httpAuthAucuneEcritureUpdate('production');
    aucuneCommande('aucun curl', '/curl /');
    verifie('aucune saisie', $questions, []);
});

cas('http-auth:update : WP_HOME absent, erreur avant toute question', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-maj-sans-home', [], [], $questions);
    httpAuthInstallationTest($h);
    httpAuthEnvTest('WP_ENV=staging');
    entree([], true, ['', 'y']);

    leve('erreur', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \Throwable::class, 'WP_HOME');
    httpAuthAucuneEcritureUpdate('sans WP_HOME');
    verifie('aucune saisie', $questions, []);
});

cas('http-auth:update : -n, identifiant actuel affiché, rien d\'écrit ni demandé', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-maj-n', [], [], $questions);
    httpAuthInstallationTest($h, "client:\$2y\$10\$abc\n");
    httpAuthEnvTest();
    sortie();

    tache('http-auth:update', $h);
    $sortie = sortie();
    contient('mode non interactif', $sortie, 'Mode non interactif');
    contient('identifiant actuel', $sortie, '« client »');
    httpAuthAucuneEcritureUpdate('-n');
    verifie('aucune saisie', $questions, []);
    aucuneCommande('aucun curl', '/curl /');
});

cas('http-auth:update : identifiant proposé (réponse vide), mot de passe généré, copie supprimée', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-maj-ok', [], [''], $questions);
    [, $htpasswd] = httpAuthInstallationTest($h);
    httpAuthEnvTest();
    httpAuthCurlUpdateTest('200');
    Shell::quand('/^stat -c %a /', "600\n");
    entree([], true, ['', 'y']);
    sortie();

    tache('http-auth:update', $h);
    $sortie = sortie();
    $commandes = implode("\n", Shell::commandes());

    contient('question de l\'identifiant, défaut de l\'inventaire', $sortie, 'Identifiant HTTP');
    absent('pas de rappel d\'inventaire', $sortie, '.inventory.yaml');
    vrai('mot de passe affiché', preg_match('/Mot de passe : ([a-hjkmnp-zA-HJKLMNP-Z2-9]{24})\b/', $sortie, $m) === 1);
    $clair = $m[1] ?? 'introuvable';
    verifie('affiché une seule fois', substr_count($sortie, $clair), 1);
    contient('consigne Bitwarden', $sortie, 'Mettez à jour l\'entrée Bitwarden : le mot de passe ne sera plus affiché.');
    contient('URL', $sortie, 'https://staging.site.test');
    absent('mot de passe absent des commandes', $commandes, $clair);

    $ligne = (string) httpAuthEcritTest($htpasswd);
    vrai('.htpasswd : identifiant proposé et hash', strpos($ligne, 'adeliom:') === 0 && password_verify($clair, substr(trim($ligne), 8)));
    contient('droits conservés', $commandes, 'chmod 600 ' . escapeshellarg($htpasswd . '.horizon-tmp'));
    $copie = uneCommande('copie avant écriture', '#^cp -p ' . preg_quote(escapeshellarg($htpasswd) . ' ' . escapeshellarg($htpasswd . '.bak'), '#') . '$#');
    vrai('copie avant l\'écriture', $copie !== null && strpos($commandes, 'cp -p') < strpos($commandes, 'base64 -d'));
    uneCommande('vérification avec identifiants', '/^curl -q -sS -o .* -K /');
    uneCommande('copie supprimée', '/rm -f .*\.htpasswd\.bak/');
    aucuneCommande('pas de restauration', '/mv .*\.htpasswd\.bak/');
    aucuneCommande('.htaccess jamais écrit', '/base64 -d.*\.htaccess/');
});

cas('http-auth:update : identifiant différent, rappel d\'inventaire ; mot de passe saisi jamais affiché', static function (): void {
    $h = httpAuthHoteSetup('http-auth-maj-client', [], ['secret', 'secret']);
    [, $htpasswd] = httpAuthInstallationTest($h);
    httpAuthEnvTest();
    httpAuthCurlUpdateTest('302');
    entree([], true, ['client', 'y']);
    sortie();

    tache('http-auth:update', $h);
    $sortie = sortie();
    contient('rappel d\'inventaire', $sortie, 'Pensez à reporter http_auth_user: client dans .inventory.yaml.');
    vrai('.htpasswd : nouvel identifiant', strpos((string) httpAuthEcritTest($htpasswd), 'client:') === 0);
    contient('identifiant récapitulé', $sortie, 'client');
    absent('mot de passe saisi non affiché', $sortie, 'secret');
    absent('pas de consigne Bitwarden', $sortie, 'Bitwarden');
    absent('mot de passe absent des commandes', implode("\n", Shell::commandes()), 'secret');
    contient('droits illisibles : 644', implode("\n", Shell::commandes()), 'chmod 644 ' . escapeshellarg($htpasswd . '.horizon-tmp'));
    uneCommande('copie supprimée', '/rm -f .*\.htpasswd\.bak/');
    aucuneCommande('.htaccess jamais écrit', '/base64 -d.*\.htaccess/');
});

cas('http-auth:update : identifiant invalide redemandé, trois échecs refusés', static function (): void {
    $h = httpAuthHoteSetup('http-auth-maj-deux-points');
    [, $htpasswd] = httpAuthInstallationTest($h);
    httpAuthEnvTest();
    httpAuthCurlUpdateTest('200');
    entree([], true, ['a:b', 'client', 'y']);
    sortie();

    tache('http-auth:update', $h);
    $sortie = sortie();
    contient('avertissement', $sortie, 'Identifiant invalide');
    vrai('.htpasswd : identifiant redemandé', strpos((string) httpAuthEcritTest($htpasswd), 'client:') === 0);
    aucuneCommande('.htaccess jamais écrit', '/base64 -d.*\.htaccess/');

    $questions = [];
    $h = httpAuthHoteSetup('http-auth-maj-trois', ['http_auth_user' => ''], [''], $questions);
    Shell::reinitialiser();
    httpAuthInstallationTest($h);
    httpAuthEnvTest();
    httpAuthCurlUpdateTest('200');
    entree([], true, ['a:b', '', 'x:y', 'y']);

    leve('trois échecs : erreur', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \Throwable::class, 'Identifiant');
    httpAuthAucuneEcritureUpdate('trois échecs');
    verifie('mot de passe non demandé', $questions, []);
});

cas('http-auth:update : confirmation refusée, et fin d\'entrée sans TTY, sans écriture', static function (): void {
    foreach (['refus' => ['', 'n'], 'eof' => ['']] as $nom => $reponses) {
        $h = httpAuthHoteSetup('http-auth-maj-' . $nom);
        Shell::reinitialiser();
        httpAuthInstallationTest($h);
        httpAuthEnvTest();
        httpAuthCurlUpdateTest('200');
        entree([], true, $reponses);

        tache('http-auth:update', $h);
        httpAuthAucuneEcritureUpdate($nom);
        aucuneCommande("$nom : aucun curl", '/curl /');
    }
});

cas('http-auth:update : vérification en 401, ancien .htpasswd remis en place, tâche en erreur', static function (): void {
    $h = httpAuthHoteSetup('http-auth-maj-401');
    [, $htpasswd] = httpAuthInstallationTest($h);
    httpAuthEnvTest();
    httpAuthCurlUpdateTest('401');
    entree([], true, ['', 'y']);

    $e = leve('erreur', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \Throwable::class, '200 ou 30x attendu');
    contient('restauration signalée', $e !== null ? $e->getMessage() : '', 'remis en place');
    uneCommande('restauration', '#^mv -f ' . preg_quote(escapeshellarg($htpasswd . '.bak') . ' ' . escapeshellarg($htpasswd), '#') . '$#');
    aucuneCommande('copie non supprimée', '/rm -f .*\.htpasswd\.bak/');
    aucuneCommande('.htaccess jamais écrit', '/base64 -d.*\.htaccess/');
});

cas('http-auth:update : exception à l\'écriture, ancien .htpasswd remis, exception d\'origine relancée', static function (): void {
    $h = httpAuthHoteSetup('http-auth-maj-exception');
    [, $htpasswd] = httpAuthInstallationTest($h);
    httpAuthEnvTest();
    httpAuthCurlUpdateTest('200');
    Shell::quand('#base64 -d > ' . preg_quote(escapeshellarg($htpasswd . '.horizon-tmp'), '#') . '#', new \RuntimeException('Disque plein'));
    entree([], true, ['', 'y']);
    sortie();

    leve('exception d\'origine', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \RuntimeException::class, 'Disque plein');
    contient('restauration signalée', sortie(), 'remis en place');
    uneCommande('restauration', '/mv -f .*\.htpasswd\.bak/');
    aucuneCommande('pas de vérification', '/curl /');
});

cas('http-auth:update : copie impossible, rien n\'est prétendu restauré', static function (): void {
    $h = httpAuthHoteSetup('http-auth-maj-cp-ko');
    httpAuthInstallationTest($h);
    httpAuthEnvTest();
    Shell::quand('/^cp -p /', new \RuntimeException('Permission denied'));
    entree([], true, ['', 'y']);
    sortie();

    leve('exception d\'origine', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \RuntimeException::class, 'Permission denied');
    contient('rien de modifié', sortie(), 'n\'a pas été modifié');
    aucuneCommande('pas de restauration', '/^mv /');
    aucuneCommande('pas d\'écriture', '/base64 -d/');
});

cas('http-auth:update : restauration impossible, message qui le dit', static function (): void {
    $h = httpAuthHoteSetup('http-auth-maj-mv-ko');
    [, $htpasswd] = httpAuthInstallationTest($h);
    httpAuthEnvTest();
    httpAuthCurlUpdateTest('500');
    Shell::quand('/^mv -f /', new \RuntimeException('Read-only file system'));
    entree([], true, ['', 'y']);

    $e = leve('erreur', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \Throwable::class, '200 ou 30x attendu');
    $message = $e !== null ? $e->getMessage() : '';
    contient('échec de la restauration signalé', $message, $htpasswd . '.bak');
    absent('pas de « remis en place »', $message, 'est remis en place');
});

cas('http-auth:update : de bout en bout sur le disque (réel, curl simulés)', static function (): void {
    $racine = dossierTemporaire();
    mkdir("$racine/releases/1/web", 0777, true);
    mkdir("$racine/shared", 0777, true);
    symlink("$racine/releases/1", "$racine/current");
    file_put_contents("$racine/shared/.env", "WP_ENV='staging'\nWP_HOME='https://staging.site.test'\n");
    $htaccess = httpAuthBlock("$racine/shared/.htpasswd", 'Accès restreint', [], ['/.well-known/acme-challenge/']) . "\n# BEGIN WordPress\r\n# END WordPress";
    file_put_contents("$racine/releases/1/web/.htaccess", $htaccess);
    file_put_contents("$racine/shared/.htpasswd", "adeliom:\$2y\$10\$ancien\n");
    chmod("$racine/shared/.htpasswd", 0640);

    $questions = [];
    $saisie = httpAuthSaisieTest(['mot de "passe"', 'mot de "passe"'], $questions);
    $h = hoteLocal('http-auth-maj-reel', [
        'deploy_path' => $racine,
        'http_auth_password_asker' => static function () use ($saisie): callable {
            return $saisie;
        },
    ]);
    Shell::$reel = true;
    $avec = null;
    $bakPendantVerif = null;
    Shell::quand('/^curl -q -sS -o /', static function (array $appel) use ($racine, &$avec, &$bakPendantVerif): string {
        if (preg_match("/ -K '([^']+)'/", $appel['commande'], $m) === 1) {
            $avec = file_get_contents($m[1]);
            $bakPendantVerif = @file_get_contents("$racine/shared/.htpasswd.bak");

            return '200';
        }

        return '401';
    });
    entree([], true, ['client', 'y']);
    sortie();

    tache('http-auth:update', $h);

    $htpasswd = (string) file_get_contents("$racine/shared/.htpasswd");
    vrai('.htpasswd : identifiant et hash', strpos($htpasswd, 'client:$2y$') === 0 && password_verify('mot de "passe"', substr(trim($htpasswd), 7)));
    verifie('droits conservés', fileperms("$racine/shared/.htpasswd") & 0777, 0640);
    verifie('copie présente pendant la vérification', $bakPendantVerif, "adeliom:\$2y\$10\$ancien\n");
    vrai('copie supprimée', !file_exists("$racine/shared/.htpasswd.bak"));
    verifie('.htaccess intact', file_get_contents("$racine/releases/1/web/.htaccess"), $htaccess);
    verifie('identifiants de la vérification', $avec, 'user = "client:mot de \\"passe\\""' . "\n");
    absent('mot de passe absent des commandes', implode("\n", Shell::commandes()), 'mot de "passe"');
    contient('récapitulatif', sortie(), 'https://staging.site.test');
});

cas('httpAuthValidUser : identifiant vide, avec « : » ou saut de ligne refusé, sans hash', static function (): void {
    foreach (['', 'a:b', "a\nb", "a\rb"] as $id) {
        leve('refusé : ' . json_encode($id), static function () use ($id): void {
            httpAuthValidUser($id);
        }, \InvalidArgumentException::class, 'Identifiant invalide');
    }

    httpAuthValidUser('client');
    vrai('identifiant valide accepté', true);
});

cas('http-auth:update : copie .htpasswd.bak déjà présente, refus avant toute question', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-maj-bak', [], [''], $questions);
    [, $htpasswd] = httpAuthInstallationTest($h);
    httpAuthEnvTest();
    httpAuthCurlUpdateTest('200');
    Shell::quand('#\[ -e ' . preg_quote(escapeshellarg($htpasswd . '.bak'), '#') . ' \]#', '+true');
    entree([], true, ['', 'y']);
    sortie();

    $e = leve('refus', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \Throwable::class, $htpasswd . '.bak');
    $message = $e !== null ? $e->getMessage() : '';
    contient('.htpasswd cité', $message, "$htpasswd ");
    contient('consigne', $message, 'à la main');
    absent('aucune question', sortie(), 'Identifiant HTTP');
    verifie('aucune saisie', $questions, []);
    httpAuthAucuneEcritureUpdate('copie présente');
});

cas('http-auth:update : AuthUserFile du bloc différent de http_auth_htpasswd, refus avant toute question', static function (): void {
    $questions = [];
    $h = httpAuthHoteSetup('http-auth-maj-autre', [], [''], $questions);
    $ancien = '/srv/ancien/.htpasswd';
    [, $htpasswd] = httpAuthInstallationTest($h, "adeliom:\$2y\$10\$abc\n", httpAuthBlock($ancien, 'Accès restreint', [], []) . "\n# BEGIN WordPress\n# END WordPress\n");
    httpAuthEnvTest();
    httpAuthCurlUpdateTest('200');
    entree([], true, ['', 'y']);
    sortie();

    $e = leve('refus', static function () use ($h): void {
        tache('http-auth:update', $h);
    }, \Throwable::class, 'http-auth:setup');
    $message = $e !== null ? $e->getMessage() : '';
    contient('chemin du bloc cité', $message, $ancien);
    contient('chemin de l\'inventaire cité', $message, $htpasswd);
    absent('aucune question', sortie(), 'Identifiant HTTP');
    verifie('aucune saisie', $questions, []);
    httpAuthAucuneEcritureUpdate('AuthUserFile différent');
});

/*
 * Tâche cachée deploy:http-auth (réinjection du bloc dans la nouvelle release).
 */

/** Hôte prêt pour deploy:http-auth : http_auth activé, release 2 en préparation. */
function httpAuthHoteDeploy(string $alias, array $config = []): \Deployer\Host\Host
{
    $h = hote($alias, $config);
    $h->set('http_auth', $config['http_auth'] ?? true);
    $h->set('release_path', '/srv/' . $h->getAlias() . '/releases/2');

    return $h;
}

/** .htpasswd présent ou non, pour le test d'existence de deploy:http-auth. */
function httpAuthHtpasswdPresentTest(\Deployer\Host\Host $h, bool $present = true): void
{
    if ($present) {
        Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg('/srv/' . $h->getAlias() . '/shared/.htpasswd'), '#') . ' \]; then echo \+true#', '+true');
    }
}

/** Contenu des .htaccess de current et de la release (null : absent). */
function httpAuthHtaccessDeployTest(\Deployer\Host\Host $h, ?string $current, ?string $release): void
{
    $chemins = [
        '/srv/' . $h->getAlias() . '/current/web/.htaccess' => $current,
        '/srv/' . $h->getAlias() . '/releases/2/web/.htaccess' => $release,
    ];

    foreach ($chemins as $chemin => $contenu) {
        if ($contenu !== null) {
            Shell::quand('#^if \[ -f ' . preg_quote(escapeshellarg($chemin), '#') . ' \]; then echo \+dep#', "+dep\n" . base64_encode($contenu));
        }
    }
}

cas('deploy:http-auth : http_auth faux, aucune commande', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-inactif', ['http_auth' => false]);
    tache('deploy:http-auth', $h);
    verifie('aucune commande', Shell::commandes(), []);
});

cas('deploy:http-auth : production, avertissement sans écriture, déploiement poursuivi', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-prod-env');
    httpAuthEnvTest("WP_ENV=production\nWP_HOME=https://www.site.test");
    httpAuthHtpasswdPresentTest($h);
    httpAuthHtaccessDeployTest($h, httpAuthBlock('/srv/x/.htpasswd', 'r', [], []), "# BEGIN WordPress\n# END WordPress\n");
    sortie();

    tache('deploy:http-auth', $h);
    contient('avertissement', sortie(), 'WP_ENV=production');
    aucuneCommande('aucune écriture', '/base64 -d/');
    aucuneCommande('aucune lecture de .htaccess', '/\.htaccess/');
});

cas('deploy:http-auth : .htpasswd absent, avertissement sans écriture', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-sans-htpasswd');
    httpAuthEnvTest();
    httpAuthHtpasswdPresentTest($h, false);
    sortie();

    tache('deploy:http-auth', $h);
    $sortie = sortie();
    contient('avertissement', $sortie, '/shared/.htpasswd');
    contient('renvoi vers setup', $sortie, 'http-auth:setup');
    aucuneCommande('aucune écriture', '/base64 -d/');
    aucuneCommande('aucune lecture de .htaccess', '/\.htaccess/');
    aucuneCommande('test hors de current (absent au premier déploiement)', '/^cd /');
});

cas('deploy:http-auth : IP du bloc de current reprise, avec http_auth_allow_ips, droits conservés', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-ip', ['http_auth_allow_ips' => ['198.51.100.7']]);
    $htpasswd = '/srv/' . $h->getAlias() . '/shared/.htpasswd';
    $release = '/srv/' . $h->getAlias() . '/releases/2/web/.htaccess';
    $wp = "# BEGIN WordPress\n# END WordPress\n";
    httpAuthEnvTest();
    httpAuthHtpasswdPresentTest($h);
    httpAuthHtaccessDeployTest($h, httpAuthBlock($htpasswd, 'Accès restreint', ['203.0.113.10'], []) . "\n" . $wp, $wp);
    Shell::quand('/^stat -c %a /', '640');
    entree([], false, []);

    tache('deploy:http-auth', $h);
    verifie('bloc réinjecté dans la release', httpAuthEcritTest($release), httpAuthBlock($htpasswd, 'Accès restreint', ['203.0.113.10', '198.51.100.7'], ['/.well-known/acme-challenge/']) . "\n" . $wp);
    uneCommande('droits conservés', '/chmod 640 /');
    aucuneCommande('current jamais écrit', '/base64 -d > .*current/');
    aucuneCommande('.htpasswd jamais écrit', '/base64 -d > .*\.htpasswd/');
    aucuneCommande('aucune sonde ni curl', '/horizon-http-auth-|curl /');
});

cas('deploy:http-auth : premier déploiement, .htaccess de release absent, créé en 644', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-premier');
    $htpasswd = '/srv/' . $h->getAlias() . '/shared/.htpasswd';
    httpAuthEnvTest();
    httpAuthHtpasswdPresentTest($h);

    tache('deploy:http-auth', $h);
    verifie('bloc sans IP du serveur', httpAuthEcritTest('/srv/' . $h->getAlias() . '/releases/2/web/.htaccess'), httpAuthBlock($htpasswd, 'Accès restreint', [], ['/.well-known/acme-challenge/']));
    uneCommande('créé en 644', '/chmod 644 /');
    aucuneCommande('droits non lus', '/^stat /');
});

cas('deploy:http-auth : bloc identique dans la release, aucune écriture', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-identique');
    $bloc = httpAuthBlock('/srv/' . $h->getAlias() . '/shared/.htpasswd', 'Accès restreint', ['203.0.113.10'], ['/.well-known/acme-challenge/']);
    httpAuthEnvTest();
    httpAuthHtpasswdPresentTest($h);
    httpAuthHtaccessDeployTest($h, $bloc, $bloc . "\n# BEGIN WordPress\n");

    tache('deploy:http-auth', $h);
    aucuneCommande('aucune écriture', '/base64 -d/');
});

cas('deploy:http-auth : bloc de current mal formé, avertissement, IP de l\'inventaire seules', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-current-mal-forme', ['http_auth_allow_ips' => ['198.51.100.7']]);
    $htpasswd = '/srv/' . $h->getAlias() . '/shared/.htpasswd';
    httpAuthEnvTest();
    httpAuthHtpasswdPresentTest($h);
    httpAuthHtaccessDeployTest($h, "# BEGIN Horizon HTTP auth\nRequire ip 203.0.113.10\n", '');
    sortie();

    tache('deploy:http-auth', $h);
    contient('avertissement', sortie(), '/current/web/.htaccess');
    verifie('bloc réinjecté sans l\'IP du bloc mal formé', httpAuthEcritTest('/srv/' . $h->getAlias() . '/releases/2/web/.htaccess'), httpAuthBlock($htpasswd, 'Accès restreint', ['198.51.100.7'], ['/.well-known/acme-challenge/']));
});

cas('deploy:http-auth : bloc de la release mal formé, erreur sans écriture', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-release-mal-forme');
    httpAuthEnvTest();
    httpAuthHtpasswdPresentTest($h);
    httpAuthHtaccessDeployTest($h, null, "# BEGIN Horizon HTTP auth\nx\n");

    leve('erreur', static function () use ($h): void {
        tache('deploy:http-auth', $h);
    }, \Throwable::class, '/releases/2/web/.htaccess');
    aucuneCommande('aucune écriture', '/base64 -d/');
});

cas('deploy:http-auth : IP invalide dans http_auth_allow_ips, déploiement interrompu avant la bascule, sans écriture', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-ip-invalide', ['http_auth_allow_ips' => ['203.0.113.10, 198.51.100.2']]);
    httpAuthEnvTest();
    httpAuthHtpasswdPresentTest($h);
    httpAuthHtaccessDeployTest($h, null, "# BEGIN WordPress\n# END WordPress\n");

    $e = leve('erreur', static function () use ($h): void {
        tache('deploy:http-auth', $h);
    }, \Throwable::class, '"203.0.113.10, 198.51.100.2"');
    contient('bascule évitée', $e !== null ? $e->getMessage() : '', 'avant la bascule');
    aucuneCommande('aucune écriture', '/base64 -d/');
});

cas('deploy:http-auth : écriture en échec, exception propagée', static function (): void {
    $h = httpAuthHoteDeploy('http-auth-deploy-ecriture-ko');
    httpAuthEnvTest();
    httpAuthHtpasswdPresentTest($h);
    Shell::quand('/base64 -d > /', new \RuntimeException('Disque plein'));
    sortie();

    leve('exception d\'origine', static function () use ($h): void {
        tache('deploy:http-auth', $h);
    }, \RuntimeException::class, 'Disque plein');
    contient('avertissement explicite', sortie(), 'avant la bascule');
});

cas('deploy:http-auth : de bout en bout sur le disque, IP de current reprise (réel)', static function (): void {
    $racine = dossierTemporaire();
    mkdir("$racine/releases/1/web", 0777, true);
    mkdir("$racine/releases/2/web", 0777, true);
    mkdir("$racine/shared", 0777, true);
    symlink("$racine/releases/1", "$racine/current");
    file_put_contents("$racine/shared/.env", "WP_ENV='staging'\nWP_HOME='https://staging.site.test'\n");
    file_put_contents("$racine/shared/.htpasswd", "adeliom:\$2y\$10\$abc\n");
    $wp = "# BEGIN WordPress\r\nRewriteEngine On\r\n# END WordPress";
    file_put_contents("$racine/releases/1/web/.htaccess", httpAuthBlock("$racine/shared/.htpasswd", 'Accès restreint', ['203.0.113.10'], ['/.well-known/acme-challenge/']) . "\n" . $wp);
    file_put_contents("$racine/releases/2/web/.htaccess", $wp);
    chmod("$racine/releases/2/web/.htaccess", 0640);

    $h = hoteLocal('http-auth-deploy-reel', ['deploy_path' => $racine, 'http_auth' => true, 'release_path' => "$racine/releases/2"]);
    Shell::$reel = true;

    tache('deploy:http-auth', $h);
    verifie('bloc réinjecté', file_get_contents("$racine/releases/2/web/.htaccess"), httpAuthBlock("$racine/shared/.htpasswd", 'Accès restreint', ['203.0.113.10'], ['/.well-known/acme-challenge/']) . "\n" . $wp);
    verifie('droits conservés', fileperms("$racine/releases/2/web/.htaccess") & 0777, 0640);
    verifie('.htpasswd intact', file_get_contents("$racine/shared/.htpasswd"), "adeliom:\$2y\$10\$abc\n");

    // Premier déploiement : ni current ni .htaccess dans la release.
    unlink("$racine/current");
    unlink("$racine/releases/2/web/.htaccess");
    tache('deploy:http-auth', $h);
    verifie('premier déploiement : bloc seul', file_get_contents("$racine/releases/2/web/.htaccess"), httpAuthBlock("$racine/shared/.htpasswd", 'Accès restreint', [], ['/.well-known/acme-challenge/']));
});

/*
 * Tâche http-auth:remove.
 */

cas('http-auth:remove : bloc retiré sur le disque, .htpasswd conservé, avertissement si http_auth vaut true (réel)', static function (): void {
    $racine = dossierTemporaire();
    mkdir("$racine/releases/1/web", 0777, true);
    mkdir("$racine/shared/web", 0777, true);
    symlink("$racine/releases/1", "$racine/current");
    file_put_contents("$racine/shared/.htpasswd", "adeliom:\$2y\$10\$abc\n");
    $wp = "# BEGIN WordPress\r\nRewriteEngine On\r\n# END WordPress";
    // .htaccess partagé : le bloc est retiré du fichier de shared/, le lien reste en place.
    file_put_contents("$racine/shared/web/.htaccess", httpAuthBlock("$racine/shared/.htpasswd", 'Accès restreint', [], []) . "\n" . $wp);
    chmod("$racine/shared/web/.htaccess", 0640);
    symlink("$racine/shared/web/.htaccess", "$racine/releases/1/web/.htaccess");

    $h = hoteLocal('http-auth-remove-reel', ['deploy_path' => $racine, 'http_auth' => true]);
    Shell::$reel = true;
    entree([], true, ['y']);
    sortie();

    tache('http-auth:remove', $h);
    verifie('bloc retiré, reste intact', file_get_contents("$racine/shared/web/.htaccess"), $wp);
    vrai('lien de la release intact', is_link("$racine/releases/1/web/.htaccess"));
    verifie('droits conservés', fileperms("$racine/shared/web/.htaccess") & 0777, 0640);
    verifie('.htpasswd conservé', file_get_contents("$racine/shared/.htpasswd"), "adeliom:\$2y\$10\$abc\n");
    contient('avertissement http_auth', sortie(), 'http_auth vaut encore true : le prochain déploiement remettra la protection. Passez-le à false dans .inventory.yaml.');

    // Fichier ne contenant que le bloc : conservé, vide.
    file_put_contents("$racine/shared/web/.htaccess", httpAuthBlock("$racine/shared/.htpasswd", 'Accès restreint', [], []));
    $h2 = hoteLocal('http-auth-remove-reel-vide', ['deploy_path' => $racine]);
    entree([], true, ['y']);
    sortie();
    tache('http-auth:remove', $h2);
    verifie('fichier conservé, vide', file_get_contents("$racine/shared/web/.htaccess"), '');
    absent('pas d\'avertissement sans http_auth', sortie(), 'http_auth vaut encore true');
});

cas('http-auth:remove : -n, description sans écriture', static function (): void {
    $h = hote('http-auth-remove-n');
    httpAuthInstallationTest($h);
    sortie();

    tache('http-auth:remove', $h);
    $sortie = sortie();
    contient('mode non interactif', $sortie, 'Mode non interactif');
    contient('.htaccess décrit', $sortie, '/srv/' . $h->getAlias() . '/current/web/.htaccess');
    httpAuthAucuneEcritureUpdate('-n');
});

cas('http-auth:remove : confirmation refusée, et fin d\'entrée sans TTY, sans écriture', static function (): void {
    foreach (['refus' => ['n'], 'eof' => []] as $nom => $reponses) {
        $h = hote('http-auth-remove-' . $nom);
        Shell::reinitialiser();
        httpAuthInstallationTest($h);
        entree([], true, $reponses);

        tache('http-auth:remove', $h);
        httpAuthAucuneEcritureUpdate($nom);
    }
});

cas('http-auth:remove : sans bloc ni .htaccess, information sans écriture ni question', static function (): void {
    foreach (['sans-bloc' => "# BEGIN WordPress\n# END WordPress\n", 'sans-htaccess' => ''] as $nom => $htaccess) {
        $h = hote('http-auth-remove-' . $nom);
        Shell::reinitialiser();
        httpAuthInstallationTest($h, null, $htaccess);
        entree([], true, ['y']);
        sortie();

        tache('http-auth:remove', $h);
        $sortie = sortie();
        contient("$nom : information", $sortie, 'Aucun bloc');
        absent("$nom : aucune question", $sortie, 'Retirer l\'authentification HTTP');
        httpAuthAucuneEcritureUpdate($nom);
    }
});

cas('http-auth:remove : bloc mal formé, erreur sans écriture', static function (): void {
    $h = hote('http-auth-remove-mal-forme');
    httpAuthInstallationTest($h, null, "# BEGIN Horizon HTTP auth\nAuthType Basic\n");
    entree([], true, ['y']);

    leve('erreur', static function () use ($h): void {
        tache('http-auth:remove', $h);
    }, \Throwable::class, 'Rien n\'a été écrit');
    httpAuthAucuneEcritureUpdate('mal formé');
});

cas('http-auth:remove : sans garde-fou production', static function (): void {
    $h = hote('http-auth-remove-prod');
    [$htaccess] = httpAuthInstallationTest($h);
    httpAuthEnvTest("WP_ENV=production\nWP_HOME=https://www.site.test");
    entree([], true, ['y']);

    tache('http-auth:remove', $h);
    verifie('bloc retiré', httpAuthEcritTest($htaccess), "# BEGIN WordPress\n# END WordPress\n");
    aucuneCommande('shared/.env non lu', '/^grep -E /');
});
