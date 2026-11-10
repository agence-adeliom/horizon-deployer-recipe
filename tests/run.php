<?php
/*
 * Tests unitaires du recipe (recipe/horizon-transfer.php), avec le vrai Deployer de vendor/.
 *
 *     composer install
 *     php tests/run.php              # toute la suite
 *     php tests/run.php rsync roles  # les seuls fichiers tests/unit/<nom>.test.php cités
 *
 * Chaque cas tourne dans le contexte d'un hôte neuf, avec une racine de projet temporaire
 * (DEPLOYER_ROOT), une entrée non interactive par défaut et un enregistreur de commandes à la place
 * de SSH : voir tests/unit/bootstrap.php. Code de sortie 1 au moindre échec.
 *
 * Variables d'environnement : HORIZON_TESTS_VENDOR (autre dossier vendor/, pour essayer une autre
 * version de Deployer), HORIZON_TESTS_RECIPE (autre recipe, pour vérifier qu'une mutation casse la
 * suite).
 */

namespace Deployer;

use Deployer\Task\Context;

require __DIR__ . '/unit/bootstrap.php';

$filtres = array_slice($argv, 1);
$fichiers = glob(__DIR__ . '/unit/*.test.php');
sort($fichiers);

foreach ($fichiers as $fichier) {
    $nom = basename($fichier, '.test.php');

    if ($filtres !== [] && !in_array($nom, $filtres, true)) {
        continue;
    }

    Suite::$fichier = $nom;
    require $fichier;
}

if (Suite::$tests === []) {
    fwrite(STDERR, "Aucun test : vérifier les noms passés en argument.\n");
    exit(1);
}

printf("Deployer %d, PHP %s, %d cas\n\n", versionDeployer(), PHP_VERSION, count(Suite::$tests));

$echecs = 0;
$bugs = [];
$sautes = [];
$parFichier = [];

foreach (Suite::$tests as $test) {
    Shell::reinitialiser();
    entree();
    sortie();
    putenv('DEPLOYER_ROOT=' . dossierTemporaire());

    Suite::$echecsTest = [];
    Suite::$courant = hote('courant');
    Context::push(new Context(Suite::$courant));
    $exception = null;
    $saute = null;

    try {
        ($test['fn'])();
    } catch (TestSaute $e) {
        $saute = $e->getMessage();
    } catch (\Throwable $e) {
        $exception = $e;
    } finally {
        while (Context::has()) {
            Context::pop();
        }
    }

    $parFichier[$test['fichier']] = ($parFichier[$test['fichier']] ?? 0) + 1;
    $libelle = $test['fichier'] . ' › ' . $test['libelle'];

    if ($saute !== null) {
        $sautes[] = "$libelle : $saute";
        continue;
    }

    if ($exception !== null) {
        Suite::$echecsTest[] = sprintf(
            "exception %s : %s\n      %s:%d",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine()
        );
    }

    if ($test['bug'] !== null) {
        if (Suite::$echecsTest === []) {
            $echecs++;
            echo "ÉCHEC $libelle\n   le bug signalé ne se reproduit plus (« {$test['bug']} ») : retirer le marquage casBug().\n";
        } else {
            $bugs[] = "$libelle\n   {$test['bug']}\n   - " . implode("\n   - ", Suite::$echecsTest);
        }

        continue;
    }

    if (Suite::$echecsTest !== []) {
        $echecs++;
        echo "ÉCHEC $libelle\n   - " . implode("\n   - ", Suite::$echecsTest) . "\n";
    }
}

foreach ($sautes as $saute) {
    echo "SAUTÉ $saute\n";
}

foreach ($bugs as $bug) {
    echo "BUG CONNU (ignoré) $bug\n";
}

echo "\n";

foreach ($parFichier as $fichier => $nombre) {
    printf("  %-16s %3d cas\n", $fichier, $nombre);
}

printf(
    "\n%d cas, %d vérifications : %d échec(s), %d bug(s) connu(s), %d sauté(s)\n",
    count(Suite::$tests),
    Suite::$verifications,
    $echecs,
    count($bugs),
    count($sautes)
);

exit($echecs === 0 ? 0 : 1);
