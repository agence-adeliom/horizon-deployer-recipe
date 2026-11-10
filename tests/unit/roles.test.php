<?php
/*
 * roles:check et roles:status (mu-plugin adeliom-roles) : lecture de roles.php sans exécution,
 * validation de la déclaration, type d'environnement, rôles de l'hôte en SQL, comparaison, et les deux
 * tâches de bout en bout avec un hôte simulé.
 */

namespace Deployer;

const FIXTURES = __DIR__ . '/../fixtures';

/** Valeur que PHP donne au code (référence : le vrai interpréteur). */
function rolesEvalue(string $code)
{
    $fichier = tempnam(sys_get_temp_dir(), 'rc');
    file_put_contents($fichier, $code);
    $valeur = require $fichier;
    unlink($fichier);

    return $valeur;
}

/** Valeur lue par l'analyseur du recipe, ou « refus : … ». */
function rolesAnalyse(string $code)
{
    try {
        return rolesCheckParseData($code);
    } catch (\Throwable $e) {
        return 'refus : ' . $e->getMessage();
    }
}

function rolesIdentique(string $libelle, string $code): void
{
    verifie($libelle, rolesAnalyse($code), rolesEvalue($code));
}

function rolesRefuse(string $libelle, string $code): void
{
    $resultat = rolesAnalyse($code);
    verifie("$libelle : refusé", is_string($resultat) && strpos($resultat, 'refus : ') === 0, true);
}

function rolesMulti(): array
{
    return rolesEvalue((string) file_get_contents(FIXTURES . '/roles-multi.php'));
}

/** Rôles d'un hôte conforme à l'empreinte de roles-multi.php. */
function rolesHoteConforme(): array
{
    return array(
        'administrator' => array('capabilities' => array('read' => true)),
        'agent' => array('capabilities' => array('level_0' => true, 'read' => true, 'upload_files' => false)),
        'agent_support' => array('capabilities' => array('list_users' => true, 'read' => true)),
        'subscriber' => array('capabilities' => array('read' => true)),
    );
}

/** Installe la déclaration dans le projet temporaire (DEPLOYER_ROOT), à l'emplacement Bedrock. */
function rolesInstalleDeclaration(?string $contenu = null): string
{
    $fichier = getenv('DEPLOYER_ROOT') . '/web/app/mu-plugins/adeliom-roles/roles.php';
    @mkdir(dirname($fichier), 0777, true);
    file_put_contents($fichier, $contenu ?? (string) file_get_contents(FIXTURES . '/roles-multi.php'));

    return $fichier;
}

/**
 * Simule un hôte : .env (lignes renvoyées par grep), préfixe, options user_roles et
 * adeliom_roles_state lues en SQL.
 */
function rolesSimuleHote(array $envLignes, array $roles, array $etat = array(), string $prefixe = 'wp_'): void
{
    Shell::quand('/^grep -E .*shared\/\.env/', implode("\n", array_map(static function (string $l): string {
        return '+dep-env:' . $l;
    }, $envLignes)));
    Shell::quand('/config get table_prefix/', $prefixe);
    Shell::quand('/db query .*user_roles/', implode("\n", array(
        '+dep|' . $prefixe . 'user_roles|' . strtoupper(bin2hex(serialize($roles))),
        '+dep|adeliom_roles_state|' . strtoupper(bin2hex(serialize($etat))),
    )));
}

/*
 * Analyseur de littéraux : même résultat que PHP sur les fichiers acceptés.
 */

cas('analyseur : mêmes valeurs que PHP sur les fichiers acceptés', static function (): void {
    rolesIdentique('gabarit vide', (string) file_get_contents(FIXTURES . '/roles-vide.php'));
    rolesIdentique('déclaration multi-rôles', (string) file_get_contents(FIXTURES . '/roles-multi.php'));
    rolesIdentique('syntaxe courte et clés entières', "<?php return ['a' => [1, -2, 0], 3 => 'x', 'b' => [true, FALSE, Null]];");
    rolesIdentique('virgule finale et commentaires', "<?php\n// c\nreturn array(/* c */ 'a' => 'b', # c\n);");
    rolesIdentique('préfixe binaire', "<?php return array(b'x\\'y', B\"a\\tb\");");
    rolesIdentique('séquences d\'échappement', "<?php return array(\"\\n\\t\\\\\\x41\\101\\\$a\", 'a\\\\b\\n');");
    rolesIdentique('\\u{10FFFF} et \\u{e9}', "<?php return array(\"\\u{10FFFF}\\u{e9}\");");
    rolesIdentique('\\u sans accolade', "<?php return array(\"a\\u0041\");");
    rolesIdentique('profondeur 33', '<?php return ' . str_repeat('[', 33) . str_repeat(']', 33) . ';');
});

cas('analyseur : tout ce qui n\'est pas une donnée littérale est refusé', static function (): void {
    rolesRefuse('appel de fonction', "<?php return array('touch'('x'));");
    rolesRefuse('appel par chaîne', "<?php return array('system'('id'));");
    rolesRefuse('constante', "<?php return array(PHP_VERSION);");
    rolesRefuse('variable', "<?php return array(\$a);");
    rolesRefuse('interpolation', "<?php return array(\"a{\$b}\");");
    rolesRefuse('concaténation', "<?php return array('a' . 'b');");
    rolesRefuse('heredoc', "<?php return array(<<<X\nx\nX);");
    rolesRefuse('code avant le return', "<?php touch('x'); return array();");
    rolesRefuse('code après le return', "<?php return array(); touch('x');");
    rolesRefuse('hexadécimal', '<?php return array(0x10);');
    rolesRefuse('octal implicite', '<?php return array(08);');
    rolesRefuse('séparateur de chiffres', '<?php return array(0_8);');
    rolesRefuse('flottant', '<?php return array(1.5);');
    rolesRefuse('\\u{} vide', "<?php return array(\"\\u{}\");");
    rolesRefuse('\\u{110000}', "<?php return array(\"\\u{110000}\");");
    rolesRefuse('\\u{ non fermée', "<?php return array(\"\\u{41\");");
    rolesRefuse('profondeur 34', '<?php return ' . str_repeat('[', 34) . str_repeat(']', 34) . ';');
});

cas('analyseur : refus supplémentaires (clé non scalaire, tableau non fermé, « - » seul)', static function (): void {
    rolesRefuse('clé tableau', "<?php return array(array() => 1);");
    rolesRefuse('tableau non fermé', "<?php return array(1, 2;");
    rolesRefuse('moins sans entier', "<?php return array(-'a');");
    rolesRefuse('array sans parenthèse', "<?php return array;");
    rolesRefuse('fichier vide', '');
    rolesRefuse('entier de 19 chiffres', '<?php return array(1234567890123456789);');
    rolesRefuse('backticks', "<?php return array(`id`);");
    rolesRefuse('new', "<?php return array(new \\stdClass());");
});

cas('rolesCheckUnquote : séquences prises une à une', static function (): void {
    verifie('apostrophes : \\\\ et \\\' seuls', rolesCheckUnquote("'a\\\\b\\'c\\n'"), "a\\b'c\\n");
    verifie('guillemets : \\e \\f \\v \\r', rolesCheckUnquote('"\\e\\f\\v\\r"'), "\e\f\v\r");
    verifie('octal \\400 tronqué à un octet', rolesCheckUnquote('"\\400"'), chr(0));
    verifie('\\x sur un chiffre', rolesCheckUnquote('"\\x9"'), "\t");
    verifie('séquence inconnue gardée', rolesCheckUnquote('"\\q"'), '\\q');
    verifie('UTF-8 sur deux, trois et quatre octets', rolesCheckUnquote('"\\u{e9}\\u{20ac}\\u{1F600}"'), "é€😀");
});

/*
 * Validation de la déclaration (mêmes règles que la classe Declaration du moteur).
 */

cas('rolesCheckDeclarationErrors : fixtures valides, quatorze déclarations invalides', static function (): void {
    $vide = rolesEvalue((string) file_get_contents(FIXTURES . '/roles-vide.php'));
    $multi = rolesMulti();

    verifie('gabarit vide valide', rolesCheckDeclarationErrors($vide), array());
    verifie('déclaration multi-rôles valide', rolesCheckDeclarationErrors($multi), array());

    $sansDebug = $multi;
    unset($sansDebug['debug_page']);

    $invalides = array(
        'clé manquante' => $sansDebug,
        'version 0' => array('version' => 0) + $multi,
        'version en chaîne' => array('version' => '1') + $multi,
        'roles non tableau' => array('roles' => 'x') + $multi,
        'rôle non tableau' => array('roles' => array('a' => true)) + $multi,
        'name non chaîne' => array('roles' => array('a' => array('name' => 1))) + $multi,
        'cap à null' => array('roles' => array('a' => array('caps' => array('read' => null)))) + $multi,
        'remove non liste de chaînes' => array('roles' => array('a' => array('remove' => array(1)))) + $multi,
        'expected sans caps' => array('expected' => array('roles' => array())) + $multi,
        'expected.roles non chaînes' => array('expected' => array('roles' => array(1), 'caps' => array())) + $multi,
        'expected.caps valeur 1' => array('expected' => array('roles' => array(), 'caps' => array('a' => array('read' => 1)))) + $multi,
        'extra_roles_ui à 1' => array('extra_roles_ui' => 1) + $multi,
        'default_extra_roles en chaîne' => array('default_extra_roles' => 'a') + $multi,
        'debug_page à null' => array('debug_page' => null) + $multi,
    );

    foreach ($invalides as $libelle => $declaration) {
        verifie("déclaration invalide ($libelle)", count(rolesCheckDeclarationErrors($declaration)) > 0, true);
    }

    verifie('toutes les clés manquantes citées', rolesCheckDeclarationErrors(array()), array(
        'clé manquante : version',
        'clé manquante : roles',
        'clé manquante : expected',
        'clé manquante : extra_roles_ui',
        'clé manquante : default_extra_roles',
        'clé manquante : debug_page',
    ));
    verifie('expected.caps non tableau', rolesCheckDeclarationErrors(array('expected' => array('roles' => array(), 'caps' => 'x')) + $multi), array('expected[caps] doit être un tableau'));
});

cas('rolesCheckExpected : lecture sans exécution, refus d\'une déclaration invalide', static function (): void {
    $multi = rolesMulti();
    $fichier = tempnam(sys_get_temp_dir(), 'rc');
    copy(FIXTURES . '/roles-multi.php', $fichier);
    verifie('empreinte lue', rolesCheckExpected($fichier), $multi['expected']);

    file_put_contents($fichier, "<?php\nreturn " . var_export(array('version' => '1') + $multi, true) . ";\n");
    $e = leve('déclaration invalide : DomainException', static function () use ($fichier): void {
        rolesCheckExpected($fichier);
    }, \DomainException::class);
    verifie('message cite la clé fautive', $e !== null && strpos($e->getMessage(), 'version') !== false, true);

    file_put_contents($fichier, "<?php\nfile_put_contents('" . $fichier . ".pwn', 'x');\nreturn array();\n");
    try {
        rolesCheckExpected($fichier);
    } catch (\Throwable $e) {
    }
    verifie('code jamais exécuté', file_exists($fichier . '.pwn'), false);

    file_put_contents($fichier, "<?php\nreturn 'x';\n");
    leve('valeur non tableau refusée', static function () use ($fichier): void {
        rolesCheckExpected($fichier);
    }, \RuntimeException::class, 'doit renvoyer un tableau');

    file_put_contents($fichier, "<?php\n// adeliom-roles:declaration\nfunction adeliom_roles_declaration() {}\n");
    leve('ancien format 1.x refusé', static function () use ($fichier): void {
        rolesCheckExpected($fichier);
    }, \RuntimeException::class, 'moteur 1.x');

    file_put_contents($fichier, '');
    leve('fichier vide : illisible', static function () use ($fichier): void {
        rolesCheckExpected($fichier);
    }, \RuntimeException::class, 'illisible');

    unlink($fichier);
    leve('fichier absent : illisible', static function () use ($fichier): void {
        rolesCheckExpected($fichier);
    }, \RuntimeException::class, 'illisible');
});

/*
 * Type d'environnement, comme wp_get_environment_type() sous Bedrock.
 */

cas('rolesCheckEnvironmentType', static function (): void {
    $environnements = array(
        'aucune variable' => array(array(), null),
        'WP_ENV production' => array(array('WP_ENV' => 'production'), 'production'),
        'WP_ENV staging' => array(array('WP_ENV' => 'staging'), 'staging'),
        'WP_ENV development' => array(array('WP_ENV' => 'development'), 'development'),
        'WP_ENV non standard' => array(array('WP_ENV' => 'preprod'), 'production'),
        'WP_ENV vide' => array(array('WP_ENV' => ''), 'production'),
        'WP_ENVIRONMENT_TYPE prioritaire' => array(array('WP_ENVIRONMENT_TYPE' => 'staging', 'WP_ENV' => 'production'), 'staging'),
        'WP_ENVIRONMENT_TYPE invalide' => array(array('WP_ENVIRONMENT_TYPE' => 'foo', 'WP_ENV' => 'staging'), 'production'),
        'WP_ENVIRONMENT_TYPE vide : WP_ENV' => array(array('WP_ENVIRONMENT_TYPE' => '', 'WP_ENV' => 'local'), 'local'),
    );

    foreach ($environnements as $libelle => $cas) {
        verifie("environnement ($libelle)", rolesCheckEnvironmentType($cas[0]), $cas[1]);
    }
});

cas('rolesCheckHostEnvironmentValues : commande en lecture seule sur shared/.env', static function (): void {
    $h = hote('prod-env');
    Shell::quand('/^grep/', "+dep-env:WP_ENV=staging\n+dep-env:export WP_ENVIRONMENT_TYPE='local' # commentaire\n+dep-env:WP_ENV=production");

    verifie('première occurrence de chaque variable', rolesCheckHostEnvironmentValues($h), array('WP_ENV' => 'staging', 'WP_ENVIRONMENT_TYPE' => 'local'));
    $appel = uneCommande('grep sur shared/.env', '/^grep -E /');
    contient('chemin résolu dans le contexte de l\'hôte', $appel['commande'] ?? '', escapeshellarg('/srv/' . $h->getAlias() . '/shared/.env'));
    verifie('exécutée sur l\'hôte', $appel['env'] ?? null, $h->getAlias());
});

cas('rolesCheckHostEnvironmentValues : vrai grep sur un .env (quotes, export, vide, commentaire)', static function (): void {
    $racine = dossierTemporaire();
    mkdir("$racine/shared");
    file_put_contents("$racine/shared/.env", implode("\n", array(
        '# WP_ENV=commentaire',
        'DB_NAME=wp',
        '  export WP_ENV="preprod"',
        'WP_ENV=production',
        "WP_ENVIRONMENT_TYPE=''",
        'WP_HOME=https://exemple.test',
    )) . "\n");
    $h = hote('prod-env-reel', array('deploy_path' => $racine));
    Shell::$reel = true;

    verifie('valeurs lues', rolesCheckHostEnvironmentValues($h), array('WP_ENV' => 'preprod', 'WP_ENVIRONMENT_TYPE' => ''));

    unlink("$racine/shared/.env");
    verifie('fichier absent : aucune variable', rolesCheckHostEnvironmentValues($h), array());
});

/*
 * Rôles de l'hôte en SQL.
 */

cas('rolesCheckHostRoles : lecture HEX désérialisée, jamais wp option get', static function (): void {
    $h = hote('prod-roles');
    $roles = rolesHoteConforme();
    rolesSimuleHote(array(), $roles, array('applied_version' => 3), 'site_');

    verifie('rôles et état lus', rolesCheckHostRoles($h), array('roles' => $roles, 'state' => array('applied_version' => 3)));
    $sql = uneCommande('requête SQL', '/db query/');
    contient('table du préfixe', $sql['commande'] ?? '', 'site_options');
    contient('option user_roles du préfixe', $sql['commande'] ?? '', 'site_user_roles');
    contient('HEX pour échapper au client mysql', $sql['commande'] ?? '', 'HEX(option_value)');
    aucuneCommande('aucun wp option get (chargerait le mu-plugin)', '/option get/');
    verifie('dans la release en ligne', $sql['cwd'] ?? null, '/srv/' . $h->getAlias() . '/current');
});

cas('rolesCheckHostRoles : préfixe illisible, user_roles absente ou objet sérialisé', static function (): void {
    $h = hote('prod-roles-ko');
    Shell::quand('/config get table_prefix/', "Deprecated: bruit\nwp-; DROP");
    leve('préfixe non identifiant refusé', static function () use ($h): void {
        rolesCheckHostRoles($h);
    }, \RuntimeException::class, 'Préfixe');

    Shell::reinitialiser();
    Shell::quand('/config get table_prefix/', 'wp_');
    Shell::quand('/db query/', '+dep|adeliom_roles_state|' . bin2hex(serialize(array())));
    leve('user_roles absente', static function () use ($h): void {
        rolesCheckHostRoles($h);
    }, \RuntimeException::class, 'wp_user_roles introuvable');

    Shell::reinitialiser();
    Shell::quand('/config get table_prefix/', 'wp_');
    Shell::quand('/db query/', '+dep|wp_user_roles|' . bin2hex(serialize(new \ArrayObject(array(1)))));
    leve('objet sérialisé jamais instancié, donc illisible', static function () use ($h): void {
        rolesCheckHostRoles($h);
    }, \RuntimeException::class, 'illisible');

    Shell::reinitialiser();
    Shell::quand('/config get table_prefix/', 'wp_');
    Shell::quand('/db query/', '+dep|wp_user_roles|' . bin2hex(serialize(rolesHoteConforme())));
    verifie('état absent : tableau vide', rolesCheckHostRoles($h)['state'], array());
});

/*
 * Comparaison des rôles de l'hôte à l'empreinte.
 */

cas('rolesCheckDiff : conforme, écarts de rôles et de caps', static function (): void {
    $multi = rolesMulti();
    $hote = rolesHoteConforme();
    verifie('hôte conforme', rolesCheckDiff($multi['expected'], $hote), array());

    $ecart = $hote;
    unset($ecart['subscriber']);
    $ecart['editor'] = array('capabilities' => array());
    $ecart['agent']['capabilities']['edit_posts'] = true;
    $ecart['agent']['capabilities']['upload_files'] = true;
    unset($ecart['agent_support']['capabilities']['list_users']);
    verifie('écarts détectés', rolesCheckDiff($multi['expected'], $ecart), array(
        'Rôle editor : absent de l\'audit, présent sur l\'hôte',
        'Rôle subscriber : attendu, absent sur l\'hôte',
        'Rôle agent, cap edit_posts : attendu absente, hôte true',
        'Rôle agent, cap upload_files : attendu false, hôte true',
        'Rôle agent_support, cap list_users : attendu true, hôte absente',
    ));

    $ajout = $hote;
    $ajout['agent']['capabilities']['cap_de_plugin'] = true;
    verifie('cap non surveillée : jamais un écart', rolesCheckDiff($multi['expected'], $ajout), array());

    $entier = $hote;
    $entier['agent']['capabilities']['read'] = 1;
    verifie('valeur 1 en base comparée comme true', rolesCheckDiff($multi['expected'], $entier), array());
});

/*
 * Fichiers locaux.
 */

cas('roles_check_file : Bedrock par défaut, wp-content s\'il est le seul présent', static function (): void {
    // Valeur calculée une fois par hôte (Deployer la met en cache) : un hôte neuf à chaque lecture.
    $lire = static function (): string {
        return (string) dans(hote('fichier'), static function () {
            return get('roles_check_file');
        });
    };

    verifie('défaut Bedrock', $lire(), 'web/app/mu-plugins/adeliom-roles/roles.php');

    $racine = (string) getenv('DEPLOYER_ROOT');
    mkdir("$racine/wp-content/mu-plugins/adeliom-roles", 0777, true);
    touch("$racine/wp-content/mu-plugins/adeliom-roles/roles.php");
    verifie('WordPress classique', $lire(), 'wp-content/mu-plugins/adeliom-roles/roles.php');

    rolesInstalleDeclaration();
    verifie('les deux : Bedrock', $lire(), 'web/app/mu-plugins/adeliom-roles/roles.php');
});

cas('rolesCheckLocalFile : désactivé, absent, relatif, absolu', static function (): void {
    verifie('sans mu-plugin : null', rolesCheckLocalFile(), null);

    $fichier = rolesInstalleDeclaration();
    verifie('chemin relatif rendu absolu', rolesCheckLocalFile(), $fichier);

    Suite::$courant->set('roles_check', false);
    verifie('roles_check à false : null', rolesCheckLocalFile(), null);

    Suite::$courant->set('roles_check', true);
    Suite::$courant->set('roles_check_file', $fichier);
    verifie('chemin absolu gardé', rolesCheckLocalFile(), $fichier);

    Suite::$courant->set('roles_check_file', '');
    verifie('chemin vide : null', rolesCheckLocalFile(), null);
    verifie('aucune commande', Shell::commandes(), array());
});

cas('rolesCheckLoaderPath', static function (): void {
    verifie('chargeur à côté du dossier', rolesCheckLoaderPath('web/app/mu-plugins/adeliom-roles/roles.php'), 'web/app/mu-plugins/adeliom-roles.php');
    verifie('chemin absolu', rolesCheckLoaderPath('/a/b/roles.php'), '/a/b.php');
});

cas('rolesCheckWarnLegacy : ancien mu-plugin d\'un seul fichier', static function (): void {
    $racine = (string) getenv('DEPLOYER_ROOT');
    mkdir("$racine/web/app/mu-plugins", 0777, true);
    file_put_contents("$racine/web/app/mu-plugins/adeliom-roles.php", "<?php\nfunction adeliom_roles_sync() {}\n");

    rolesCheckWarnLegacy();
    contient('avertissement', sortie(), 'Ancien mu-plugin adeliom-roles');

    Suite::$courant->set('roles_check', false);
    rolesCheckWarnLegacy();
    verifie('désactivé : silence', sortie(), '');

    Suite::$courant->set('roles_check', true);
    mkdir("$racine/web/app/mu-plugins/adeliom-roles");
    rolesCheckWarnLegacy();
    verifie('dossier présent (structure en classes) : silence', sortie(), '');

    rmdir("$racine/web/app/mu-plugins/adeliom-roles");
    file_put_contents("$racine/web/app/mu-plugins/adeliom-roles.php", "<?php\n// chargeur sans fonction\n");
    rolesCheckWarnLegacy();
    verifie('chargeur sans fonction adeliom_roles_ : silence', sortie(), '');
});

cas('rolesCheckCommittedCopy : contenu du commit HEAD, pas de la copie de travail', static function (): void {
    if (!disponible('git --version')) {
        saute('git absent');
    }

    Shell::$reel = true;
    $depot = dossierTemporaire();
    mkdir("$depot/sous");
    file_put_contents("$depot/sous/roles.php", "commité\n");
    Shell::bash('git init -q && git -c user.name=t -c user.email=t@t add . && git -c user.name=t -c user.email=t@t commit -qm init', $depot);
    file_put_contents("$depot/sous/roles.php", "modifié\n");

    $copie = rolesCheckCommittedCopy("$depot/sous/roles.php");
    verifie('copie temporaire créée', is_string($copie) && is_file($copie), true);
    verifie('contenu de HEAD', $copie !== null ? file_get_contents($copie) : null, "commité\n");

    if ($copie !== null) {
        unlink($copie);
    }

    file_put_contents("$depot/sous/nouveau.php", 'x');
    verifie('fichier jamais commité : null', rolesCheckCommittedCopy("$depot/sous/nouveau.php"), null);

    $horsDepot = dossierTemporaire();
    file_put_contents("$horsDepot/roles.php", 'x');
    verifie('hors dépôt git : null', rolesCheckCommittedCopy("$horsDepot/roles.php"), null);
});

/*
 * Tâche roles:check.
 */

cas('roles:check sans mu-plugin : rien, aucune commande', static function (): void {
    tache('roles:check', hote('production'));
    verifie('aucune commande', Shell::commandes(), array());
    verifie('aucune sortie', sortie(), '');
});

cas('roles:check en staging (shared/.env) : pas de contrôle', static function (): void {
    rolesInstalleDeclaration();
    rolesSimuleHote(array('WP_ENV=staging'), rolesHoteConforme());

    tache('roles:check', hote('production'));
    aucuneCommande('aucune lecture SQL', '/db query/');
    verifie('silence hors -v', sortie(), '');
});

cas('roles:check en production : rôles conformes', static function (): void {
    rolesInstalleDeclaration();
    rolesSimuleHote(array('WP_ENV=production'), rolesHoteConforme());

    tache('roles:check', hote('live'));
    $sortie = sortie();
    contient('conformes', $sortie, 'conformes à l\'empreinte');
    contient('copie de travail hors dépôt git signalée', $sortie, 'copie de travail');
});

cas('roles:check : synchro déjà appliquée, pas de comparaison', static function (): void {
    rolesInstalleDeclaration();
    $roles = rolesHoteConforme();
    unset($roles['subscriber']);
    rolesSimuleHote(array('WP_ENV=production'), $roles, array('applied_version' => 2));

    tache('roles:check', hote('production'));
    $sortie = sortie();
    contient('information', $sortie, 'déjà synchronisés par adeliom-roles (version 2)');
    absent('aucun écart affiché', $sortie, 'ont changé');
});

cas('roles:check : écart en -n, avertissement et poursuite', static function (): void {
    rolesInstalleDeclaration();
    $roles = rolesHoteConforme();
    unset($roles['subscriber']);
    rolesSimuleHote(array('WP_ENV=production'), $roles);

    tache('roles:check', hote('production'));
    $sortie = sortie();
    contient('écart signalé', $sortie, 'Rôle subscriber : attendu, absent sur l\'hôte');
    contient('poursuite en -n', $sortie, 'déploiement poursuivi malgré l\'écart');
});

cas('roles:check : écart en interactif, « non » ou fin d\'entrée arrêtent, « oui » poursuit', static function (): void {
    rolesInstalleDeclaration();
    $roles = rolesHoteConforme();
    unset($roles['subscriber']);
    rolesSimuleHote(array('WP_ENV=production'), $roles);
    $h = hote('production');

    entree(array(), true, array('n'));
    leve('« non » : déploiement interrompu', static function () use ($h): void {
        tache('roles:check', $h);
    }, \Throwable::class, 'Déploiement interrompu');

    entree(array(), true, array());
    leve('sans TTY ni -n : défaut « non »', static function () use ($h): void {
        tache('roles:check', $h);
    }, \Throwable::class, 'Déploiement interrompu');

    entree(array(), true, array('y'));
    tache('roles:check', $h);
    contient('question posée', sortie(), 'Continuer le déploiement ?');
});

cas('roles:check : WP_ENV non standard contrôlé, avec une information', static function (): void {
    rolesInstalleDeclaration();
    rolesSimuleHote(array('WP_ENV=preprod'), rolesHoteConforme());

    tache('roles:check', hote('preprod'));
    $sortie = sortie();
    contient('information', $sortie, 'WP_ENV « preprod »');
    contient('contrôle fait', $sortie, 'conformes');
});

cas('roles:check : sans .env, repli sur l\'hôte protégé', static function (): void {
    rolesInstalleDeclaration();
    rolesSimuleHote(array(), rolesHoteConforme());

    tache('roles:check', hote('recette'));
    aucuneCommande('hôte non protégé : aucune lecture SQL', '/db query/');

    tache('roles:check', hote('production'));
    $sortie = sortie();
    contient('hôte protégé contrôlé', $sortie, 'hôte protégé, contrôlé si cet hôte est la production');
    contient('contrôle fait', $sortie, 'conformes');
});

cas('roles:check : déclaration invalide ou lecture en échec, jamais d\'arrêt', static function (): void {
    $multi = rolesMulti();
    rolesInstalleDeclaration("<?php\nreturn " . var_export(array('debug_page' => 'oui') + $multi, true) . ";\n");
    rolesSimuleHote(array('WP_ENV=production'), rolesHoteConforme());

    tache('roles:check', hote('production'));
    $sortie = sortie();
    contient('déclaration invalide signalée', $sortie, 'la synchro ne s\'appliquera pas');
    absent('jamais « conformes »', $sortie, 'conformes');

    Shell::reinitialiser();
    rolesInstalleDeclaration();
    Shell::quand('/^grep/', '+dep-env:WP_ENV=production');
    Shell::quand('/config get table_prefix/', new \RuntimeException('ssh: connexion refusée'));

    tache('roles:check', hote('production'));
    contient('lecture en échec : avertissement', sortie(), 'la lecture a échoué. Le déploiement continue');
});

/*
 * Tâche roles:status.
 */

cas('roles:status : état affiché, chargeur testé dans la release', static function (): void {
    rolesInstalleDeclaration();
    $h = hote('production');
    Shell::quand('/^if \[ -f /', '+true');
    Shell::quand('/adeliom-roles status/', "Synchro : appliquée\nVersion : 3");

    tache('roles:status', $h);
    $sortie = sortie();
    contient('titre', $sortie, 'Rôles adeliom-roles sur « ' . $h->getAlias() . ' »');
    contient('lignes reprises', $sortie, 'Version : 3');
    $test = uneCommande('présence du chargeur', '/^if \[ -f /');
    contient('chargeur adeliom-roles.php', $test['commande'] ?? '', "'web/app/mu-plugins/adeliom-roles.php'");
    $status = uneCommande('commande status', '/adeliom-roles status/');
    absent('sans --skip-plugins (commande du mu-plugin)', $status['commande'] ?? '', '--skip-plugins');
    verifie('dans la release en ligne', $status['cwd'] ?? null, '/srv/' . $h->getAlias() . '/current');
});

cas('roles:status : chargeur absent, échec de la commande, exception', static function (): void {
    rolesInstalleDeclaration();
    $h = hote('production');

    tache('roles:status', $h);
    aucuneCommande('chargeur absent : pas de status', '/adeliom-roles status/');

    Shell::quand('/^if \[ -f /', '+true');
    Shell::quand('/adeliom-roles status/', "Error: 'adeliom-roles' is not a registered wp command.\n+dep-failed");
    tache('roles:status', $h);
    $sortie = sortie();
    contient('échec signalé', $sortie, 'illisible sur « ' . $h->getAlias() . ' » (wp adeliom-roles status)');
    absent('marqueur retiré', $sortie, '+dep-failed');

    Shell::reinitialiser();
    Shell::quand('/^if \[ -f /', new \RuntimeException('ssh: coupure'));
    tache('roles:status', $h);
    contient('exception : avertissement seulement', sortie(), 'ssh: coupure');
});

cas('roles:status : sans mu-plugin, rien ; chemin absolu, pas de test distant', static function (): void {
    tache('roles:status', hote('production'));
    verifie('aucune commande', Shell::commandes(), array());

    $fichier = rolesInstalleDeclaration();
    Suite::$courant->set('roles_check_file', $fichier);
    $h = hote('production', array('roles_check_file' => $fichier));
    Shell::quand('/adeliom-roles status/', 'ok');
    tache('roles:status', $h);
    aucuneCommande('pas de test du chargeur', '/^if \[ -f /');
    uneCommande('status lancé directement', '/adeliom-roles status/');
});
