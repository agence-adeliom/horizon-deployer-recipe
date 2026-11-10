<?php
/*
 * Environnements, alias, hôtes protégés et questions : transferProjectRoot, transferAliases,
 * transferResolveEnv, transferEnvLabel, transferSameServer, transferIsLocalEndpoint, transferIsProtected
 * (et le défaut transfer_protected), transferConfirm, transferConfirmDiskUsage, transferConfirmDestination,
 * transferChoice, transferSelectEnvs, transferAskUploadsStrategy.
 */

namespace Deployer;

use Symfony\Component\Console\Output\OutputInterface;

cas('transferProjectRoot : DEPLOYER_ROOT, sinon le dossier courant', static function (): void {
    $racine = (string) getenv('DEPLOYER_ROOT');
    verifie('DEPLOYER_ROOT', transferProjectRoot(), $racine);

    putenv('DEPLOYER_ROOT');

    try {
        verifie('sans DEPLOYER_ROOT ni deploy.php : getcwd()', transferProjectRoot(), getcwd());
    } finally {
        putenv("DEPLOYER_ROOT=$racine");
    }
});

cas('transferAliases et transferResolveEnv', static function (): void {
    $staging = hote('staging');
    vrai('alias déclarés', in_array($staging->getAlias(), transferAliases(), true));

    verifie('local accepté en source', transferResolveEnv('local', true), null);
    leve('local refusé en destination', static function (): void {
        transferResolveEnv('local', false);
    }, \RuntimeException::class, '« local » n\'est pas une destination valide');
    verifie('alias connu : le même hôte', transferResolveEnv($staging->getAlias(), false), $staging);

    leve('alias inconnu : liste des disponibles', static function (): void {
        transferResolveEnv('stagign', true);
    }, \RuntimeException::class, 'Environnement « stagign » inconnu. Disponibles : local, ');
    vrai('aucun hôte créé pour une faute de frappe', !Deployer::get()->hosts->has('stagign'));
});

cas('transferEnvLabel, transferSameServer, transferIsLocalEndpoint', static function (): void {
    $a = hote('a', array('hostname' => 'srv.test', 'remote_user' => 'web'));
    $b = hote('b', array('hostname' => 'srv.test', 'remote_user' => 'web'));
    $c = hote('c', array('hostname' => 'srv.test', 'remote_user' => 'autre'));
    $boite = hoteLocal('boite');

    verifie('libellé local', transferEnvLabel(null), 'local');
    verifie('libellé distant', transferEnvLabel($a), $a->getAlias());

    vrai('même machine et même compte', transferSameServer($a, $b));
    vrai('compte différent : serveurs distincts', !transferSameServer($a, $c));
    vrai('local : jamais le même serveur', !transferSameServer(null, $a));
    vrai('deux locaux : faux', !transferSameServer(null, null));

    vrai('null : extrémité locale', transferIsLocalEndpoint(null));
    vrai('localhost() : extrémité locale', transferIsLocalEndpoint($boite));
    vrai('hôte SSH : distant', !transferIsLocalEndpoint($a));
});

cas('transfer_protected par défaut et transferIsProtected', static function (): void {
    vrai('alias contenant prod', transferIsProtected(hote('production')));
    vrai('alias preprod', transferIsProtected(hote('preprod')));
    vrai('stage contenant prod', transferIsProtected(hote('live', array('stage' => 'prod-eu'))));
    vrai('alias quelconque', !transferIsProtected(hote('staging')));
    vrai('surcharge de l\'inventaire', !transferIsProtected(hote('production', array('transfer_protected' => false))));
    vrai('lu dans le contexte de l\'hôte visé, pas de l\'hôte courant', !dans(hote('production'), static function () {
        return transferIsProtected(hote('recette'));
    }));
});

cas('transferConfirm : -n vaut « non », défaut « non » à la fin de l\'entrée', static function (): void {
    vrai('non interactif : non', !transferConfirm('Écrire ?', true));
    contient('avertissement', sortie(), 'Mode non interactif (-n) : question ignorée, réponse « non ».');

    entree(array(), true, array('y'));
    vrai('réponse oui', transferConfirm('Écrire ?', false));

    entree(array(), true, array('n'));
    vrai('réponse non', !transferConfirm('Écrire ?', false));

    entree(array(), true, array());
    vrai('sans TTY ni -n : le défaut', !transferConfirm('Écrire ?', false));
});

cas('transferConfirmDiskUsage : -n poursuit, la question est posée sinon', static function (): void {
    vrai('non interactif : oui', transferConfirmDiskUsage('Créer l\'archive ?', '1,2G'));
    contient('volume annoncé', sortie(), 'poursuite sans confirmation (1,2G à écrire)');

    entree(array(), true, array('n'));
    vrai('réponse non', !transferConfirmDiskUsage('Créer l\'archive ?', '1G'));

    entree(array(), true, array());
    vrai('défaut oui', transferConfirmDiskUsage('Créer l\'archive ?', '1G'));
});

cas('transferConfirmDestination : -n, hôte simple, hôte protégé', static function (): void {
    $staging = hote('staging');
    $prod = hote('production');

    vrai('-n : refus', !transferConfirmDestination($staging));
    contient('-n : abandon annoncé', sortie(), 'aucune écriture sur « ' . $staging->getAlias() . ' »');

    entree(array(), true, array('y'));
    vrai('hôte simple : oui', transferConfirmDestination($staging));
    contient('question', sortie(), 'Écraser les données de « ' . $staging->getAlias() . ' » ?');

    entree(array(), true, array());
    vrai('hôte simple : défaut non', !transferConfirmDestination($staging));

    entree(array(), true, array('y'));
    vrai('hôte protégé : un « y » ne suffit pas', !transferConfirmDestination($prod));
    contient('saisie incorrecte', sortie(), 'Saisie incorrecte.');

    entree(array(), true, array(' ' . $prod->getAlias() . ' '));
    vrai('hôte protégé : alias saisi (espaces ignorés)', transferConfirmDestination($prod));
    contient('avertissement protégé', sortie(), 'est un environnement PROTÉGÉ');
});

cas('transferChoice : chaîne, ou clé du tableau rendu en sortie « quiet »', static function (): void {
    entree(array(), true, array('mirror'));
    verifie('réponse', transferChoice('Stratégie ?', array('merge' => 'fusion', 'mirror' => 'miroir'), 'merge'), 'mirror');

    $sortie = Deployer::get()->output;
    $sortie->setVerbosity(OutputInterface::VERBOSITY_QUIET);

    try {
        verifie('quiet : clé du défaut', transferChoice('Stratégie ?', array('merge' => 'fusion', 'mirror' => 'miroir'), 'mirror'), 'mirror');
    } finally {
        $sortie->setVerbosity(OutputInterface::VERBOSITY_NORMAL);
    }
});

cas('transferSelectEnvs : options --from et --to', static function (): void {
    $staging = hote('staging');
    $prod = hote('production');

    entree(array('from' => 'local', 'to' => $staging->getAlias()));
    verifie('local → staging', transferSelectEnvs('la base'), array(null, $staging));

    entree(array('from' => $prod->getAlias(), 'to' => $staging->getAlias()));
    verifie('production → staging', transferSelectEnvs('la base'), array($prod, $staging));

    entree(array('from' => 'local', 'to' => 'local'));
    leve('local en destination', static function (): void {
        transferSelectEnvs('la base');
    }, \RuntimeException::class, 'pas une destination valide');

    entree(array('from' => $staging->getAlias(), 'to' => $staging->getAlias()));
    leve('source = destination', static function (): void {
        transferSelectEnvs('la base');
    }, \RuntimeException::class, 'doivent être différentes');

    entree(array('to' => $staging->getAlias()));
    leve('-n sans --from', static function (): void {
        transferSelectEnvs('la base');
    }, \RuntimeException::class, '--from est obligatoire');
});

cas('transferSelectEnvs : sélecteur d\'hôte et questions', static function (): void {
    $staging = hote('staging');
    $prod = hote('production');

    entree(array('from' => 'local'));
    leve('-n sans --to ni sélecteur', static function (): void {
        transferSelectEnvs('la base');
    }, \RuntimeException::class, '--to est obligatoire en mode non interactif');

    Suite::$courant->set('selected_hosts', array($staging->getAlias()));
    verifie('un seul hôte sélectionné : la destination', transferSelectEnvs('la base'), array(null, $staging));
    contient('annoncé', sortie(), 'Destination : ' . $staging->getAlias() . ' (hôte sélectionné)');

    Suite::$courant->set('selected_hosts', array());
    entree(array(), true, array($staging->getAlias(), $prod->getAlias()));
    verifie('questions : destination puis source', transferSelectEnvs('la base'), array($prod, $staging));
    $questions = sortie();
    contient('question destination', $questions, 'Destination pour la base ?');
    contient('question source', $questions, 'Source pour la base ?');

    entree(array(), true, array($staging->getAlias(), $staging->getAlias()));
    leve('la destination n\'est pas proposée en source', static function (): void {
        transferSelectEnvs('la base');
    });
});

cas('transferAskUploadsStrategy', static function (): void {
    entree(array('strategy' => 'mirror'));
    verifie('--strategy=mirror', transferAskUploadsStrategy(), 'mirror');

    entree(array('strategy' => 'miroir'));
    leve('--strategy invalide', static function (): void {
        transferAskUploadsStrategy();
    }, \RuntimeException::class, '--strategy accepte « merge » ou « mirror »');

    entree();
    verifie('-n : merge', transferAskUploadsStrategy(), 'merge');

    entree(array(), true, array('mirror'));
    verifie('question : mirror', transferAskUploadsStrategy(), 'mirror');
    contient('conséquences affichées avant', sortie(), 'SUPPRIMÉ');

    entree(array(), true, array());
    verifie('défaut : merge', transferAskUploadsStrategy(), 'merge');
});
