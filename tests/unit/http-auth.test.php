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
