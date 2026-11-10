<?php
/*
 * Tests des fonctions pures de roles:check (recipe/horizon-transfer.php), sans Deployer ni dépendance.
 *
 *     php tests/run.php
 *
 * Les fonctions testées sont extraites du recipe et chargées seules : le recipe entier ne peut pas être
 * inclus hors de Deployer (il déclare des tâches). Code de sortie 1 au premier échec ou si une fonction
 * attendue a disparu du recipe.
 */

namespace Deployer;

const FONCTIONS = array(
    'rolesCheckParseData',
    'rolesCheckParseValue',
    'rolesCheckUnquote',
    'rolesCheckDeclarationErrors',
    'rolesCheckExpected',
    'rolesCheckEnvironmentType',
    'rolesCheckDiff',
);

$source = file_get_contents(__DIR__ . '/../recipe/horizon-transfer.php');
$manquantes = array();

foreach (FONCTIONS as $fonction) {
    if (preg_match('/^function ' . $fonction . '\(.*?^}\n/ms', $source, $m) !== 1) {
        $manquantes[] = $fonction;
        continue;
    }
    eval($m[0]);
}

if ($manquantes !== array()) {
    fwrite(STDERR, 'Fonctions introuvables dans le recipe : ' . implode(', ', $manquantes) . "\n");
    exit(1);
}

$reussis = 0;
$echecs = 0;

function verifie(string $libelle, $obtenu, $attendu): void
{
    global $reussis, $echecs;

    if ($obtenu === $attendu) {
        $reussis++;
        return;
    }

    $echecs++;
    echo "ÉCHEC $libelle\n  obtenu  : ", var_export($obtenu, true), "\n  attendu : ", var_export($attendu, true), "\n";
}

/** Valeur que PHP donne au code (référence : le vrai interpréteur). */
function evalue(string $code)
{
    $fichier = tempnam(sys_get_temp_dir(), 'rc');
    file_put_contents($fichier, $code);
    $valeur = require $fichier;
    unlink($fichier);

    return $valeur;
}

/** Valeur lue par l'analyseur du recipe, ou « refus : … ». */
function analyse(string $code)
{
    try {
        return rolesCheckParseData($code);
    } catch (\Throwable $e) {
        return 'refus : ' . $e->getMessage();
    }
}

function identique(string $libelle, string $code): void
{
    verifie($libelle, analyse($code), evalue($code));
}

function refuse(string $libelle, string $code): void
{
    $resultat = analyse($code);
    verifie("$libelle : refusé", is_string($resultat) && strpos($resultat, 'refus : ') === 0, true);
}

$vide = evalue(file_get_contents(__DIR__ . '/fixtures/roles-vide.php'));
$multi = evalue(file_get_contents(__DIR__ . '/fixtures/roles-multi.php'));

// Analyseur de littéraux : même résultat que PHP sur les fichiers acceptés.
identique('gabarit vide', file_get_contents(__DIR__ . '/fixtures/roles-vide.php'));
identique('déclaration multi-rôles', file_get_contents(__DIR__ . '/fixtures/roles-multi.php'));
identique('syntaxe courte et clés entières', "<?php return ['a' => [1, -2, 0], 3 => 'x', 'b' => [true, FALSE, Null]];");
identique('virgule finale et commentaires', "<?php\n// c\nreturn array(/* c */ 'a' => 'b', # c\n);");
identique('préfixe binaire', "<?php return array(b'x\\'y', B\"a\\tb\");");
identique('séquences d\'échappement', "<?php return array(\"\\n\\t\\\\\\x41\\101\\\$a\", 'a\\\\b\\n');");
identique('\\u{10FFFF} et \\u{e9}', "<?php return array(\"\\u{10FFFF}\\u{e9}\");");
identique('\\u sans accolade', "<?php return array(\"a\\u0041\");");
identique('profondeur 33', '<?php return ' . str_repeat('[', 33) . str_repeat(']', 33) . ';');

// Analyseur de littéraux : tout ce qui n'est pas une donnée littérale est refusé, sans être exécuté.
refuse('appel de fonction', "<?php return array('touch'('x'));");
refuse('appel par chaîne', "<?php return array('system'('id'));");
refuse('constante', "<?php return array(PHP_VERSION);");
refuse('variable', "<?php return array(\$a);");
refuse('interpolation', "<?php return array(\"a{\$b}\");");
refuse('concaténation', "<?php return array('a' . 'b');");
refuse('heredoc', "<?php return array(<<<X\nx\nX);");
refuse('code avant le return', "<?php touch('x'); return array();");
refuse('code après le return', "<?php return array(); touch('x');");
refuse('hexadécimal', '<?php return array(0x10);');
refuse('octal implicite', '<?php return array(08);');
refuse('séparateur de chiffres', '<?php return array(0_8);');
refuse('flottant', '<?php return array(1.5);');
refuse('\\u{} vide', "<?php return array(\"\\u{}\");");
refuse('\\u{110000}', "<?php return array(\"\\u{110000}\");");
refuse('\\u{ non fermée', "<?php return array(\"\\u{41\");");
refuse('profondeur 34', '<?php return ' . str_repeat('[', 34) . str_repeat(']', 34) . ';');

// Validation de la déclaration (mêmes règles que la classe Declaration du moteur).
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

// rolesCheckExpected : lit le fichier sans l'exécuter, refuse une déclaration invalide.
$fichier = tempnam(sys_get_temp_dir(), 'rc');
copy(__DIR__ . '/fixtures/roles-multi.php', $fichier);
verifie('empreinte lue', rolesCheckExpected($fichier), $multi['expected']);

file_put_contents($fichier, "<?php\nreturn " . var_export(array('version' => '1') + $multi, true) . ";\n");
try {
    rolesCheckExpected($fichier);
    verifie('déclaration invalide : DomainException', 'aucune exception', 'DomainException');
} catch (\DomainException $e) {
    verifie('déclaration invalide : DomainException', 'DomainException', 'DomainException');
    verifie('message cite la clé fautive', strpos($e->getMessage(), 'version') !== false, true);
}

file_put_contents($fichier, "<?php\nfile_put_contents('" . $fichier . ".pwn', 'x');\nreturn array();\n");
try {
    rolesCheckExpected($fichier);
} catch (\Throwable $e) {
}
verifie('code jamais exécuté', file_exists($fichier . '.pwn'), false);
unlink($fichier);

// Type d'environnement, comme wp_get_environment_type() sous Bedrock.
$environnements = array(
    'aucune variable' => array(array(), null),
    'WP_ENV production' => array(array('WP_ENV' => 'production'), 'production'),
    'WP_ENV staging' => array(array('WP_ENV' => 'staging'), 'staging'),
    'WP_ENV development' => array(array('WP_ENV' => 'development'), 'development'),
    'WP_ENV non standard' => array(array('WP_ENV' => 'preprod'), 'production'),
    'WP_ENV vide' => array(array('WP_ENV' => ''), 'production'),
    'WP_ENVIRONMENT_TYPE prioritaire' => array(array('WP_ENVIRONMENT_TYPE' => 'staging', 'WP_ENV' => 'production'), 'staging'),
    'WP_ENVIRONMENT_TYPE invalide' => array(array('WP_ENVIRONMENT_TYPE' => 'foo', 'WP_ENV' => 'staging'), 'production'),
);

foreach ($environnements as $libelle => $cas) {
    verifie("environnement ($libelle)", rolesCheckEnvironmentType($cas[0]), $cas[1]);
}

// Comparaison des rôles de l'hôte à l'empreinte.
$hote = array(
    'administrator' => array('capabilities' => array('read' => true)),
    'agent' => array('capabilities' => array('level_0' => true, 'read' => true, 'upload_files' => false)),
    'agent_support' => array('capabilities' => array('list_users' => true, 'read' => true)),
    'subscriber' => array('capabilities' => array('read' => true)),
);
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

echo "\n$reussis réussis, $echecs échoués\n";
exit($echecs === 0 ? 0 : 1);
