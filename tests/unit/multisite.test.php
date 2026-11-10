<?php
/*
 * Multisite : transferMultisiteSites, transferMultisiteBase, transferIsDomain, transferDdevHostnames,
 * transferDdevRoutes, transferMultisiteTarget, transferMultisiteSearchReplaceSteps.
 */

namespace Deployer;

use Deployer\Host\Host;

/** Exécute $fn avec DDEV_HOSTNAME (et DDEV_TLD) posés, puis les retire. */
function avecDdev(string $hostnames, callable $fn, string $tld = '')
{
    putenv("DDEV_HOSTNAME=$hostnames");
    putenv($tld === '' ? 'DDEV_TLD' : "DDEV_TLD=$tld");

    try {
        return $fn();
    } finally {
        putenv('DDEV_HOSTNAME');
        putenv('DDEV_TLD');
    }
}

/** Arguments de transferMultisiteTarget() pour un réseau prod.test → staging.test. */
function cible(?Host $env, string $domaine, ?int $blog, array $options = array()): array
{
    return transferMultisiteTarget(
        $env,
        $domaine,
        $blog,
        'prod.test',
        $options['toBase'] ?? 'staging.test',
        $options['before'] ?? null,
        $options['sources'] ?? array('prod.test', $domaine),
        $options['hostnames'] ?? array(),
        $options['taken'] ?? array()
    );
}

cas('transferIsDomain', static function (): void {
    foreach (array('exemple.fr', 'a.b-c.exemple.ddev.site', 'X1.TEST') as $domaine) {
        vrai("valide : $domaine", transferIsDomain($domaine));
    }

    foreach (array('', 'localhost', 'exemple', '-a.test', 'a-.test', 'a..test', 'a b.test', 'https://a.test', 'a.test/', str_repeat('a', 250) . '.test') as $domaine) {
        vrai("invalide : $domaine", !transferIsDomain($domaine));
    }
});

cas('transferDdevHostnames et transferDdevRoutes', static function (): void {
    putenv('DDEV_HOSTNAME');
    verifie('hors DDEV : vide', transferDdevHostnames(), array());

    verifie('liste normalisée', avecDdev(' Projet.ddev.site,*.projet.ddev.site,, ', static function (): array {
        return transferDdevHostnames();
    }), array('projet.ddev.site', '*.projet.ddev.site'));

    $hostnames = array('projet.ddev.site', '*.multi.ddev.site');
    vrai('nom exact', transferDdevRoutes('projet.ddev.site', $hostnames));
    vrai('joker : sous-domaine', transferDdevRoutes('fr.multi.ddev.site', $hostnames));
    vrai('joker : sous-sous-domaine', transferDdevRoutes('a.fr.multi.ddev.site', $hostnames));
    vrai('joker : pas le domaine nu', !transferDdevRoutes('multi.ddev.site', $hostnames));
    vrai('joker : pas un suffixe collé', !transferDdevRoutes('xmulti.ddev.site', $hostnames));
    vrai('inconnu', !transferDdevRoutes('autre.ddev.site', $hostnames));
});

cas('transferMultisiteSites : réseau, blogs et domaines Mercator lus en SQL', static function (): void {
    $h = hote('staging');
    Shell::quand('/config get table_prefix/', "Deprecated: x\nsite_\n");
    Shell::quand('/FROM site_site/', "+dep|site|2|Autre.Test\n+dep|site|1|Prod.TEST\n+dep|blog|1|prod.test\n+dep|blog|2|fr.prod.test\n+dep|blog|3\n");
    Shell::quand('/domain_mapping/', "+dep|3|WWW.Marque.test\n+dep|x\n");

    verifie('sites', transferMultisiteSites($h), array(
        'base' => 'prod.test',
        'blogs' => array(1 => 'prod.test', 2 => 'fr.prod.test'),
        'mapped' => array(array(3, 'www.marque.test')),
    ));
    $sql = Shell::appel('/FROM site_site/')['commande'] ?? '';
    contient('UNION des deux tables', $sql, 'FROM site_blogs');
    aucuneCommande('jamais wp site list', '/site list/');
    contient('mapping actif seulement', Shell::appel('/domain_mapping/')['commande'] ?? '', 'site_domain_mapping WHERE active = 1');
});

cas('transferMultisiteSites : préfixe suspect, site simple, base illisible', static function (): void {
    $h = hote('staging');
    Shell::quand('/config get table_prefix/', "wp_; DROP TABLE x");
    transferMultisiteSites($h);
    uneCommande('préfixe non identifiant : wp_', '/FROM wp_site/');

    Shell::reinitialiser();
    Shell::quand('/config get table_prefix/', 'wp_');
    Shell::quand('/FROM wp_site/', '');
    verifie('site simple (tables absentes) : null', transferMultisiteSites($h), null);
    aucuneCommande('pas de lecture du mapping', '/domain_mapping/');

    Shell::reinitialiser();
    Shell::quand('/config get table_prefix/', new \RuntimeException('ssh'));
    Shell::quand('/FROM wp_site/', '+dep|site|1|prod.test');
    verifie('réseau sans blog : null', transferMultisiteSites($h), null);
});

cas('transferMultisiteBase : DOMAIN_CURRENT_SITE, sinon l\'hôte de l\'URL', static function (): void {
    $h = hote('staging');
    Shell::quand('/DOMAIN_CURRENT_SITE/', 'Staging.Test');
    verifie('constante', transferMultisiteBase($h, 'https://autre.test'), 'staging.test');
    contient('lecture sans plugins', Shell::$appels[0]['commande'], 'config get DOMAIN_CURRENT_SITE --skip-plugins --skip-themes');

    Shell::reinitialiser();
    Shell::quand('/DOMAIN_CURRENT_SITE/', "Error: The constant 'DOMAIN_CURRENT_SITE' is not defined");
    verifie('constante absente : hôte de l\'URL', transferMultisiteBase($h, 'https://WWW.Site.test/x'), 'www.site.test');

    Shell::reinitialiser();
    Shell::quand('/DOMAIN_CURRENT_SITE/', new \RuntimeException('ssh'));
    verifie('lecture en échec, sans URL : null', transferMultisiteBase($h, null), null);
});

cas('transferMultisiteTarget : règles par ordre de confiance', static function (): void {
    verifie('1. domaine du réseau', cible(null, 'prod.test', null), array('staging.test', 'domaine du réseau'));
    verifie('1. sous-domaine du réseau', cible(null, 'fr.prod.test', 2), array('fr.staging.test', 'sous-domaine du réseau'));
    verifie('pas un suffixe collé', cible(null, 'xprod.test', 2, array('before' => array('blogs' => array(2 => 'x.local'))))[1], 'blog 2 avant import');

    verifie('2. blog avant import', cible(null, 'marque.fr', 3, array('before' => array('blogs' => array(3 => 'marque.ddev.site')))), array('marque.ddev.site', 'blog 3 avant import'));
    verifie('2. écarté si c\'est un domaine de la source', cible(null, 'marque.fr', 3, array(
        'before' => array('blogs' => array(3 => 'marque.fr')),
        'sources' => array('prod.test', 'marque.fr'),
        'hostnames' => array('marque.ddev.site', 'prod.ddev.site'),
    )), array('marque.ddev.site', 'hostname DDEV'));

    verifie('3. hostname DDEV par premier libellé, www ignoré', cible(null, 'www.newsandco.fr', 4, array(
        'hostnames' => array('newsandco.webzoom.ddev.site', '*.webzoom.ddev.site', 'webzoom.ddev.site'),
    )), array('newsandco.webzoom.ddev.site', 'hostname DDEV'));
    verifie('3. hostname déjà attribué écarté', cible(null, 'www.newsandco.fr', 4, array(
        'hostnames' => array('newsandco.webzoom.ddev.site'),
        'taken' => array('newsandco.webzoom.ddev.site'),
    )), array(null, 'aucune correspondance (-n)'));
    verifie('3. le TLD n\'est pas un libellé', cible(null, 'site.fr', 4, array('hostnames' => array('fr.ddev.site'))), array(null, 'aucune correspondance (-n)'));
    verifie('3. plusieurs candidats en -n : rien', cible(null, 'shop.marque.fr', 5, array(
        'hostnames' => array('shop.ddev.site', 'marque.ddev.site'),
    )), array(null, 'aucune correspondance (-n)'));
});

cas(
    'transferMultisiteTarget : question en local, choix parmi les hostnames DDEV',
    static function (): void {
        $options = array('hostnames' => array('shop.ddev.site', 'marque.ddev.site'));

        entree(array(), true, array('marque.ddev.site'));
        verifie('local : choix parmi les candidats', cible(null, 'shop.marque.fr', 5, $options), array('marque.ddev.site', 'choisi'));
        contient('question', sortie(), 'Domaine local pour « shop.marque.fr » (blog 5) ?');

        entree(array(), true, array());
        verifie('local : défaut « laisser tel quel »', cible(null, 'shop.marque.fr', 5, $options), array(null, 'ignoré'));
    }
);

cas('transferMultisiteTarget : question en distant (saisie)', static function (): void {
    $h = hote('staging');
    entree(array(), true, array(' Marque.Staging.test '));
    verifie('distant : domaine saisi', cible($h, 'marque.fr', 3), array('marque.staging.test', 'saisi'));

    entree(array(), true, array(''));
    verifie('distant : vide', cible($h, 'marque.fr', 3), array(null, 'ignoré'));

    entree(array(), true, array('pas un domaine'));
    verifie('distant : saisie invalide', cible($h, 'marque.fr', 3), array(null, 'ignoré'));
    contient('avertissement', sortie(), "« pas un domaine » n'est pas un domaine");
});

cas('transferMultisiteSearchReplaceSteps : réseau, sous-domaines couverts, mapping, protocole', static function (): void {
    $h = hote('staging');
    Shell::quand('/DOMAIN_CURRENT_SITE/', 'staging.test');

    $etapes = transferMultisiteSearchReplaceSteps($h, array(
        'base' => 'prod.test',
        'blogs' => array(1 => 'prod.test', 2 => 'fr.prod.test', 3 => 'marque.fr'),
        'mapped' => array(array(3, 'www.marque.fr')),
    ), array('base' => 'staging.test', 'blogs' => array(3 => 'marque.staging.test'), 'mapped' => array()), 'https://prod.test', 'http://staging.test');

    verifie('passes du plus long au plus court, URL complète si le protocole change', $etapes, array(
        array('URL complète', 'https://www.marque.fr', 'http://marque.staging.test'),
        array('domaine nu', 'www.marque.fr', 'marque.staging.test'),
        array('URL complète', 'https://marque.fr', 'http://marque.staging.test'),
        array('domaine nu', 'marque.fr', 'marque.staging.test'),
        array('URL complète', 'https://prod.test', 'http://staging.test'),
        array('domaine nu', 'prod.test', 'staging.test'),
    ));
    $sortie = sortie();
    contient('résumé', $sortie, 'réseau prod.test → staging.test, 4 domaine(s) à faire correspondre');
    contient('règle affichée', $sortie, 'fr.prod.test → fr.staging.test (sous-domaine du réseau)');
});

cas('transferMultisiteSearchReplaceSteps : cas où l\'on ne réécrit pas', static function (): void {
    $h = hote('staging');
    $sites = array('base' => 'prod.test', 'blogs' => array(1 => 'prod.test'), 'mapped' => array());

    Shell::quand('/DOMAIN_CURRENT_SITE/', '');
    verifie('base introuvable : null', transferMultisiteSearchReplaceSteps($h, $sites, null, 'https://prod.test', null), null);
    contient('avertissement', sortie(), 'DOMAIN_CURRENT_SITE introuvable');

    Shell::reinitialiser();
    Shell::quand('/DOMAIN_CURRENT_SITE/', 'prod.test');
    verifie('déjà à jour : aucune passe', transferMultisiteSearchReplaceSteps($h, $sites, null, 'https://prod.test', 'https://prod.test'), array());

    Shell::reinitialiser();
    Shell::quand('/DOMAIN_CURRENT_SITE/', 'site.test.local');
    verifie('passes incompatibles : null', transferMultisiteSearchReplaceSteps($h, array(
        'base' => 'site.test',
        'blogs' => array(1 => 'site.test', 2 => 'blog.test'),
        'mapped' => array(),
    ), array('blogs' => array(2 => 'blog.site.test')), 'https://site.test', 'https://site.test.local'), null);
    contient('explication', sortie(), 'Correspondances incompatibles : « blog.test » devient « blog.site.test », que la passe de « site.test » réécrirait ensuite.');
});

cas('transferMultisiteSearchReplaceSteps : en local, corrélation et routage DDEV', static function (): void {
    Shell::quand('/DOMAIN_CURRENT_SITE/', 'reseau.ddev.site');

    $etapes = avecDdev('reseau.ddev.site,*.reseau.ddev.site,marque.ddev.site', static function () {
        return transferMultisiteSearchReplaceSteps(null, array(
            'base' => 'prod.test',
            'blogs' => array(1 => 'prod.test', 2 => 'www.marque.fr', 3 => 'fr.prod.test'),
            'mapped' => array(),
        ), null, 'https://prod.test', 'https://reseau.ddev.site');
    });

    verifie('passes', $etapes, array(
        array('domaine nu', 'www.marque.fr', 'marque.ddev.site'),
        array('domaine nu', 'prod.test', 'reseau.ddev.site'),
    ));
    absent('domaines routés : pas d\'avertissement', sortie(), 'routé');

    Shell::reinitialiser();
    Shell::quand('/DOMAIN_CURRENT_SITE/', 'reseau.ddev.site');
    avecDdev('reseau.ddev.site', static function (): void {
        entree(array(), true, array());
        transferMultisiteSearchReplaceSteps(null, array(
            'base' => 'prod.test',
            'blogs' => array(1 => 'prod.test', 2 => 'fr.prod.test'),
            'mapped' => array(),
        ), array('blogs' => array(2 => 'shop.exemple.com')), 'https://prod.test', 'https://reseau.ddev.site');
    });
    $sortie = sortie();
    contient('sous-domaine non routé', $sortie, '« fr.reseau.ddev.site » n\'est pas routé par DDEV.');
    contient('conseil additional_hostnames', $sortie, 'ajouter fr.reseau à additional_hostnames');
});
