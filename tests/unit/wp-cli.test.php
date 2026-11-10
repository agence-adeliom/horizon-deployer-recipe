<?php
/*
 * WP-CLI : choix du binaire (bin/wp_local, bin/wp), transferWpBin, transferWpLastLine,
 * transferWpIsInstalled, transferWpHasCommand, transferWpMissingDiagnosis, transferSiteUrl,
 * transferDbSchemaVersions et la tâche wp:update-db.
 */

namespace Deployer;

/** Réponse « vrai » à un test() de Deployer, dont le marqueur est tiré au hasard. */
function wpVraiTest(): callable
{
    return static function (array $appel): string {
        return preg_match('/echo (\+\w+); fi$/', $appel['commande'], $m) === 1 ? $m[1] : '';
    };
}

cas('transferWpBin', static function (): void {
    verifie('local', transferWpBin(null), '{{bin/wp_local}}');
    verifie('distant', transferWpBin(hote('staging')), '{{bin/wp}}');
});

cas('transferWpLastLine : dernière ligne non vide, bruit PHP ignoré', static function (): void {
    verifie('dépréciations avant la réponse', transferWpLastLine("Deprecated: x in y on line 3\r\n\r\nhttps://exemple.test\n\n"), 'https://exemple.test');
    verifie('vide', transferWpLastLine("\n  \n"), '');
    verifie('une ligne rognée', transferWpLastLine('  wp_  '), 'wp_');
});

cas('bin/wp_local : premier candidat qui connaît « wp db »', static function (): void {
    $h = hote('local-brut', array('_brut' => true));
    Shell::quand('/^which -a wp/', "/app/vendor/bin/wp\n/usr/local/bin/wp\n/app/vendor/bin/wp\n");
    Shell::quand('/^\/usr\/local\/bin\/wp cli cmd-dump/', '1');
    Shell::quand('/cmd-dump/', '0');

    verifie('proxy Composer sans commandes écarté', dans($h, static function () {
        return get('bin/wp_local');
    }), '/usr/local/bin/wp');
    contient('avertissement', sortie(), '/app/vendor/bin/wp ignoré (installé sans ses paquets de commandes), /usr/local/bin/wp retenu');
    verifie('chaque candidat testé une fois', count(Shell::commandes()), 3);
    verifie('détection en local', array_unique(array_column(Shell::$appels, 'env')), array('local'));
});

cas('bin/wp_local : premier candidat valide sans avertissement, repli sur « wp »', static function (): void {
    Shell::quand('/^which -a wp/', "/usr/local/bin/wp\n");
    Shell::quand('/cmd-dump/', '2');
    verifie('candidat unique', dans(hote('local-brut', array('_brut' => true)), static function () {
        return get('bin/wp_local');
    }), '/usr/local/bin/wp');
    verifie('pas d\'avertissement', sortie(), '');

    Shell::reinitialiser();
    Shell::quand('/^which -a wp/', new \RuntimeException('which: code 1'));
    verifie('aucun candidat : « wp »', dans(hote('local-brut', array('_brut' => true)), static function () {
        return get('bin/wp_local');
    }), 'wp');
});

cas('bin/wp : phar du recipe, wp du serveur, sinon téléchargement en curl -fsS', static function (): void {
    $config = array('_brut' => true, 'bin/php' => '/usr/bin/php8.3');

    $h = hote('phar', $config);
    Shell::quand('/wp-cli\.phar \]/', wpVraiTest());
    verifie('phar présent', dans($h, static function () {
        return get('bin/wp');
    }), "/usr/bin/php8.3 -d 'error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED' /srv/" . $h->getAlias() . '/.dep/wp-cli.phar');

    Shell::reinitialiser();
    Shell::quand('/hash wp/', wpVraiTest());
    verifie('wp du serveur', dans(hote('serveur', $config), static function () {
        return get('bin/wp');
    }), 'wp');
    aucuneCommande('pas de téléchargement', '/curl/');

    Shell::reinitialiser();
    $h = hote('nu', $config + array('transfer_wp_php_args' => ''));
    verifie('téléchargé, sans options PHP', dans($h, static function () {
        return get('bin/wp');
    }), '/usr/bin/php8.3  /srv/' . $h->getAlias() . '/.dep/wp-cli.phar');
    verifie('curl -fsS (jamais une page d\'erreur enregistrée comme phar)', Shell::appel('/curl/')['commande'] ?? null, 'curl -fsS -o /srv/' . $h->getAlias() . '/.dep/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar');
    contient('avertissement', sortie(), 'WP-CLI introuvable');
});

cas('transferWpIsInstalled : marqueur, sortie de WP-CLI conservée', static function (): void {
    $h = hote('staging');
    Shell::quand('/core is-installed/', "Error: This does not seem to be a WordPress installation.\n");
    verifie('non installé, explication gardée', transferWpIsInstalled($h), array(false, 'Error: This does not seem to be a WordPress installation.'));
    verifie('commande', Shell::$appels[0]['commande'], "if wp core is-installed --skip-plugins --skip-themes --no-color 2>&1; then echo '+dep-installed'; fi");

    Shell::reinitialiser();
    Shell::quand('/core is-installed/', "Deprecated: bruit\n+dep-installed\n");
    verifie('installé', transferWpIsInstalled(null), array(true, 'Deprecated: bruit'));
});

cas('transferWpHasCommand : registre de cmd-dump', static function (): void {
    $h = hote('staging');
    Shell::quand('/cmd-dump/', "1\n");

    vrai('commande connue', transferWpHasCommand($h, 'db'));
    verifie('commande lue', Shell::$appels[0]['commande'], "wp cli cmd-dump --skip-plugins --skip-themes 2>/dev/null | grep -c '\"name\":\"db\"' || true");

    Shell::reinitialiser();
    Shell::quand('/cmd-dump/', '0');
    vrai('commande absente', !transferWpHasCommand(null, 'search-replace', '/opt/wp'));
    contient('binaire imposé', Shell::$appels[0]['commande'], '/opt/wp cli cmd-dump');
});

cas('transferWpMissingDiagnosis', static function (): void {
    $h = hote('staging');
    $diagnostic = transferWpMissingDiagnosis($h, "Error: a\n\n  b  \n");

    verifie('diagnostic', $diagnostic, implode("\n", array(
        '   WP-CLI : Error: a',
        '   WP-CLI : b',
        '   Binaire : wp',
        '   Exécuté depuis : /srv/' . $h->getAlias() . '/current',
        '   Piste la plus fréquente : le « path » du wp-cli.yml du projet doit désigner le',
        '   cœur de WordPress — sous Bedrock « web/wp », et non « web ».',
    )));

    contient('local : racine du projet', transferWpMissingDiagnosis(null, ''), 'Exécuté depuis : ' . getenv('DEPLOYER_ROOT'));
});

cas('transferSiteUrl : WP_HOME, puis option home, forme stricte', static function (): void {
    $h = hote('staging');
    Shell::quand('/config get WP_HOME/', "PHP Deprecated: bruit\nhttps://staging.exemple.test\n");
    verifie('WP_HOME', transferSiteUrl($h), 'https://staging.exemple.test');
    verifie('une seule lecture', count(Shell::commandes()), 1);

    Shell::reinitialiser();
    Shell::quand('/config get WP_HOME/', new \RuntimeException('code 1'));
    Shell::quand('/option get home/', 'http://exemple.test/sous');
    verifie('repli sur l\'option home', transferSiteUrl($h), 'http://exemple.test/sous');
    contient('lecture sans plugins', Shell::$appels[1]['commande'], 'option get home --skip-plugins --skip-themes');

    Shell::reinitialiser();
    Shell::quand('/config get WP_HOME/', 'Error: WP_HOME not defined');
    Shell::quand('/option get home/', 'https://a b');
    verifie('texte parasite : null', transferSiteUrl($h), null);
});

cas('transferDbSchemaVersions', static function (): void {
    $h = hote('staging');
    Shell::quand('/ eval /', "Deprecated: x\n+dep-dbv:57155:58975:1\n");
    verifie('versions et multisite', transferDbSchemaVersions($h)[0], array('current' => 57155, 'required' => 58975, 'multisite' => true));
    $commande = Shell::$appels[0]['commande'];
    contient('code PHP échappé', $commande, "wp eval 'global \$wp_db_version;");
    contient('sans plugins', $commande, '--skip-plugins --skip-themes --no-color');

    Shell::reinitialiser();
    Shell::quand('/ eval /', '+dep-dbv:0:0:0');
    verifie('version requise 0 : illisible', transferDbSchemaVersions($h), array(null, '+dep-dbv:0:0:0'));

    Shell::reinitialiser();
    Shell::quand('/ eval /', '+dep-dbv:0:58975:0');
    verifie('version courante 0 : exploitable', transferDbSchemaVersions($h)[0], array('current' => 0, 'required' => 58975, 'multisite' => false));

    Shell::reinitialiser();
    Shell::quand('/ eval /', 'Error: base injoignable');
    verifie('sans marqueur : sortie brute', transferDbSchemaVersions($h), array(null, 'Error: base injoignable'));
});

/*
 * Tâche wp:update-db.
 */

cas('wp:update-db : désactivée, à jour, illisible, lecture en échec', static function (): void {
    tache('wp:update-db', hote('production', array('transfer_update_db' => false)));
    verifie('désactivée : aucune commande', Shell::commandes(), array());

    Shell::quand('/ eval /', '+dep-dbv:58975:58975:0');
    tache('wp:update-db', hote('production'));
    contient('à jour', sortie(), 'Schéma de base à jour');

    Shell::reinitialiser();
    Shell::quand('/ eval /', 'Error: x');
    tache('wp:update-db', hote('production'));
    $sortie = sortie();
    contient('illisible', $sortie, 'Version du schéma de base illisible');
    contient('diagnostic', $sortie, 'WP-CLI : Error: x');

    Shell::reinitialiser();
    Shell::quand('/ eval /', new \RuntimeException('curl: (22) 404'));
    tache('wp:update-db', hote('production'));
    contient('lecture en échec : avertissement', sortie(), 'la lecture a échoué');
});

cas('wp:update-db : écart, -n et refus n\'écrivent rien', static function (): void {
    Shell::quand('/ eval /', '+dep-dbv:57155:58975:0');

    tache('wp:update-db', hote('production'));
    contient('-n : avertissement', sortie(), 'Mode non interactif : aucune écriture');
    aucuneCommande('-n : pas de mise à jour', '/core update-db/');

    entree(array(), true, array('n'));
    tache('wp:update-db', hote('production'));
    aucuneCommande('refus : pas de mise à jour', '/core update-db/');

    entree(array(), true, array());
    tache('wp:update-db', hote('production'));
    aucuneCommande('sans TTY ni -n : défaut non', '/core update-db/');
});

cas('wp:update-db : mise à jour, avec ou sans sauvegarde, réseau', static function (): void {
    Shell::quand('/ eval /', '+dep-dbv:57155:58975:1');
    Shell::quand('/core update-db/', 'Success: WordPress database upgraded on 3/3 sites.');

    entree(array(), true, array('y', 'n'));
    tache('wp:update-db', hote('production'));
    verifie('multisite : --network', Shell::appel('/core update-db/')['commande'] ?? null, 'wp core update-db --skip-plugins --skip-themes --no-color --network');
    aucuneCommande('sans sauvegarde', '/db export/');
    contient('résultat affiché', sortie(), 'Success: WordPress database upgraded');

    Shell::reinitialiser();
    Shell::quand('/ eval /', '+dep-dbv:57155:58975:0');
    Shell::quand('/core is-installed/', '+dep-installed');
    entree(array(), true, array('y'));
    tache('wp:update-db', hote('production'));
    uneCommande('sauvegarde par défaut avant la mise à jour', '/db export/');
    verifie('site simple : sans --network', Shell::appel('/core update-db/')['commande'] ?? null, 'wp core update-db --skip-plugins --skip-themes --no-color');
    $ordre = array_keys(array_filter(Shell::commandes(), static function (string $c): bool {
        return preg_match('/db export|core update-db/', $c) === 1;
    }));
    vrai('sauvegarde avant mise à jour', count($ordre) === 2 && preg_match('/db export/', Shell::commandes()[$ordre[0]]) === 1);
});

cas('wp:update-db : sauvegarde impossible ou mise à jour en échec, jamais d\'exception', static function (): void {
    Shell::quand('/ eval /', '+dep-dbv:57155:58975:0');
    Shell::quand('/core is-installed/', 'Error: base');

    entree(array(), true, array('y', 'y'));
    tache('wp:update-db', hote('production'));
    contient('sauvegarde impossible', sortie(), 'Sauvegarde impossible : le schéma');
    aucuneCommande('pas d\'écriture sans sauvegarde', '/core update-db/');

    Shell::reinitialiser();
    Shell::quand('/ eval /', '+dep-dbv:57155:58975:0');
    Shell::quand('/core update-db/', new \RuntimeException('Fatal error'));
    entree(array(), true, array('y', 'n'));
    tache('wp:update-db', hote('production'));
    $sortie = sortie();
    contient('échec signalé', $sortie, 'Le déploiement reste valide');
    contient('commande à relancer', $sortie, 'dep wp:update-db production');
});
