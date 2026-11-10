<?php
/*
 * Réécriture d'URLs : transferSearchReplaceSteps, transferDbRows, transferSearchReplaceRun,
 * transferSearchReplace (site simple et aiguillage multisite).
 */

namespace Deployer;

cas('transferSearchReplaceSteps : URL complète puis domaine nu, dans cet ordre', static function (): void {
    verifie('deux passes', transferSearchReplaceSteps('https://www.exemple.fr/', 'https://exemple.ddev.site'), array(
        array('URL complète', 'https://www.exemple.fr', 'https://exemple.ddev.site'),
        array('domaine nu', 'www.exemple.fr', 'exemple.ddev.site'),
    ));
    verifie('même domaine, protocole différent : une passe', transferSearchReplaceSteps('http://exemple.fr', 'https://exemple.fr/'), array(
        array('URL complète', 'http://exemple.fr', 'https://exemple.fr'),
    ));
    verifie('sous-dossier conservé', transferSearchReplaceSteps('https://a.test/wp', 'https://b.test/site')[0], array('URL complète', 'https://a.test/wp', 'https://b.test/site'));
    verifie('URL sans hôte : une passe', count(transferSearchReplaceSteps('exemple', 'https://b.test')), 1);
});

cas('transferDbRows : lignes marquées +dep|, bruit ignoré, échec vide', static function (): void {
    $h = hote('staging');
    Shell::quand('/db query/', "Deprecated: bruit\n+dep|a|b|c\r\n  +dep|1|x  \nautre\n");

    verifie('lignes', transferDbRows($h, "SELECT 'x'"), array(array('a', 'b', 'c'), array('1', 'x')));
    $appel = Shell::$appels[0];
    verifie('commande', $appel['commande'], "wp db query 'SELECT '\\''x'\\''' --skip-column-names --skip-plugins --skip-themes 2>/dev/null || true");
    verifie('depuis la release', $appel['cwd'], '/srv/' . $h->getAlias() . '/current');

    Shell::reinitialiser();
    Shell::quand('/db query/', new \RuntimeException('ssh'));
    verifie('exception : vide', transferDbRows($h, 'SELECT 1'), array());
});

cas('transferSearchReplaceRun : -n affiche les commandes à lancer, sans rien écrire', static function (): void {
    $etapes = transferSearchReplaceSteps('https://prod.test', 'https://exemple.ddev.site');

    transferSearchReplaceRun(null, $etapes, 'Réécrire ?');
    aucuneCommande('rien d\'exécuté', '/search-replace/');
    $sortie = sortie();
    contient('tables exclues annoncées', $sortie, 'Tables exclues : *_wf*');
    contient('commande locale préfixée pour l\'hôte', $sortie, "ddev exec wp search-replace 'https://prod.test' 'https://exemple.ddev.site' --all-tables --skip-columns=guid --report-changed-only --skip-plugins --skip-themes --skip-tables='*_wf*' < /dev/null");
    contient('seconde passe', $sortie, "ddev exec wp search-replace 'prod.test' 'exemple.ddev.site'");

    $h = hote('staging', array('transfer_search_replace_skip_tables' => ''));
    transferSearchReplaceRun($h, $etapes, 'Réécrire ?');
    $sortie = sortie();
    contient('distant : sans préfixe ddev', $sortie, "   wp search-replace 'https://prod.test'");
    absent('exclusion lue sur l\'hôte (vide)', $sortie, '--skip-tables');
    contient('rappel dep run', $sortie, 'Depuis le projet : dep run "…" ' . $h->getAlias());
});

cas('transferSearchReplaceRun : passes exécutées dans l\'ordre, --precise, stdin fermé', static function (): void {
    $h = hote('staging');
    entree(array('precise' => true), true, array('y'));

    transferSearchReplaceRun($h, transferSearchReplaceSteps('https://prod.test', 'https://staging.test'), 'Réécrire ?');
    $passes = array_values(array_filter(Shell::$appels, static function (array $a): bool {
        return strpos($a['commande'], 'search-replace') !== false;
    }));
    verifie('deux passes', count($passes), 2);
    verifie('ordre et options', array_column($passes, 'commande'), array(
        "wp search-replace 'https://prod.test' 'https://staging.test' --all-tables --skip-columns=guid --report-changed-only --skip-plugins --skip-themes --skip-tables='*_wf*' --precise < /dev/null",
        "wp search-replace 'prod.test' 'staging.test' --all-tables --skip-columns=guid --report-changed-only --skip-plugins --skip-themes --skip-tables='*_wf*' --precise < /dev/null",
    ));
    verifie('sur l\'hôte, depuis la release', $passes[0]['cwd'], '/srv/' . $h->getAlias() . '/current');
    verifie('délai', $passes[0]['timeout'], 3600);
    contient('terminé', sortie(), 'URLs réécrites');
});

cas('transferSearchReplaceRun : une passe en échec est signalée et relevée', static function (): void {
    $h = hote('staging');
    entree(array(), true, array('y'));
    Shell::quand("/search-replace 'prod\\.test'/", new \RuntimeException('Allowed memory size exhausted'));

    leve('exception relevée', static function () use ($h): void {
        transferSearchReplaceRun($h, transferSearchReplaceSteps('https://prod.test', 'https://staging.test'), 'Réécrire ?');
    }, \RuntimeException::class, 'Allowed memory size');
    $sortie = sortie();
    contient('base partiellement réécrite', $sortie, 'La passe 2 a échoué : la base de « ' . $h->getAlias() . ' » est partiellement réécrite.');
    contient('leviers mémoire', $sortie, 'transfer_search_replace_skip_tables');
});

cas('transferSearchReplace : site simple', static function (): void {
    $h = hote('staging');

    transferSearchReplace($h, null, 'https://staging.test');
    contient('URL source illisible', sortie(), 'URL de la source introuvable : réécriture des URLs ignorée');

    transferSearchReplace($h, 'https://prod.test', null);
    contient('URL destination illisible', sortie(), 'URL de destination introuvable');

    transferSearchReplace($h, 'https://staging.test/', 'https://staging.test');
    verifie('même URL : silence', sortie(), '');
    aucuneCommande('même URL : rien', '/search-replace/');

    transferSearchReplace($h, 'https://prod.test', 'https://staging.test');
    $sortie = sortie();
    contient('annonce', $sortie, 'pointe encore vers https://prod.test');
    contient('-n : première passe à lancer', $sortie, "wp search-replace 'https://prod.test' 'https://staging.test'");
    contient('-n : seconde passe à lancer', $sortie, "wp search-replace 'prod.test' 'staging.test'");
    uneCommande('base lue en SQL pour détecter un multisite', '/FROM wp_site/');
});

cas('transferSearchReplace : multisite, null, déjà à jour, passes', static function (): void {
    $h = hote('staging');
    Shell::quand('/config get table_prefix/', 'wp_');
    Shell::quand('/FROM wp_site/', "+dep|site|1|prod.test\n+dep|blog|1|prod.test");

    transferSearchReplace($h, 'https://prod.test', null);
    contient('base du réseau introuvable : commandes manuelles', sortie(), 'domaine par domaine, du plus long au plus court');

    Shell::quand('/DOMAIN_CURRENT_SITE/', 'prod.test');
    transferSearchReplace($h, 'https://prod.test', 'https://prod.test');
    contient('déjà à jour', sortie(), 'Domaines du réseau déjà à jour.');

    Shell::reinitialiser();
    Shell::quand('/config get table_prefix/', 'wp_');
    Shell::quand('/FROM wp_site/', "+dep|site|1|prod.test\n+dep|blog|1|prod.test");
    Shell::quand('/DOMAIN_CURRENT_SITE/', 'staging.test');
    transferSearchReplace($h, 'https://prod.test', 'https://staging.test');
    $sortie = sortie();
    contient('correspondance du réseau', $sortie, 'prod.test → staging.test (domaine du réseau)');
    contient('-n : passe à lancer à la main', $sortie, "wp search-replace 'prod.test' 'staging.test' --all-tables");

    entree(array(), true, array('y'));
    transferSearchReplace($h, 'https://prod.test', 'https://staging.test');
    contient('question réseau', sortie(), 'Réécrire les domaines du réseau sur « ' . $h->getAlias() . ' » maintenant (1 passes) ?');
    uneCommande('passe exécutée', "/^wp search-replace 'prod\\.test' 'staging\\.test'/");
});
