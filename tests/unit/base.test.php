<?php
/*
 * Base de données : collations (transferSupportedCollations, transferDumpCollations,
 * transferCollationCharsets, transferCollationFallback), hooks d'import (transferImportHooks), import
 * (transferDbImport) et export (transferDbExport).
 */

namespace Deployer;

/** Dump gzippé fabriqué dans un dossier temporaire. */
function baseDump(string $sql): string
{
    $fichier = dossierTemporaire() . '/db.sql.gz';
    file_put_contents($fichier, gzencode($sql));

    return $fichier;
}

/** Destination prête à l'import : wp db connu, collations du serveur, dump scanné. */
function baseDestinationPrete(array $collationsServeur, string $collationsDump): void
{
    Shell::quand('/cmd-dump/', '1');
    Shell::quand('/SHOW COLLATION/', implode("\n", array_map(static function (string $c): string {
        return "$c\tutf8mb4\t45\t\tYes\t1";
    }, $collationsServeur)));
    Shell::quand('/^gunzip -c .* \| grep -oiE/', $collationsDump);
}

cas('transferSupportedCollations', static function (): void {
    $h = hote('staging');
    Shell::quand('/SHOW COLLATION/', "utf8mb4_general_ci\tutf8mb4\t45\tYes\tYes\t1\n\nutf8mb3_bin\tutf8mb3\t83\t\tYes\t1\n");

    verifie('premières colonnes', transferSupportedCollations($h), array('utf8mb4_general_ci', 'utf8mb3_bin'));
    verifie('commande', Shell::$appels[0]['commande'], 'wp db query "SHOW COLLATION" --skip-column-names --skip-plugins --skip-themes 2>/dev/null || true');

    Shell::reinitialiser();
    verifie('serveur muet : vide', transferSupportedCollations($h), array());
});

cas('transferDumpCollations : vrai gunzip | grep | sed sur un dump', static function (): void {
    Shell::$reel = true;
    $dump = baseDump(implode("\n", array(
        'CREATE TABLE `a` (`x` text COLLATE utf8mb4_unicode_ci) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;',
        'CREATE TABLE `b` (`y` text) DEFAULT CHARSET=utf8 COLLATE=`utf8_general_ci`;',
        "INSERT INTO `c` VALUES ('collate utf8mb4_unicode_ci');",
        'CREATE TABLE `d` (`z` text) collate latin1_swedish_ci;',
    )));

    $collations = transferDumpCollations(null, $dump);
    sort($collations);
    verifie('collations distinctes, backticks retirés', $collations, array('latin1_swedish_ci', 'utf8_general_ci', 'utf8mb4_0900_ai_ci', 'utf8mb4_unicode_ci'));
    verifie('délai', Shell::$appels[0]['timeout'], 1800);

    verifie('dump sans collation', transferDumpCollations(null, baseDump('SELECT 1;')), array());
});

cas('transferCollationCharsets et transferCollationFallback', static function (): void {
    verifie('utf8 ↔ utf8mb3', transferCollationCharsets('utf8'), array('utf8', 'utf8mb3'));
    verifie('utf8mb3 ↔ utf8', transferCollationCharsets('utf8mb3'), array('utf8mb3', 'utf8'));
    verifie('autre charset : lui seul', transferCollationCharsets('latin1'), array('latin1'));

    $mariadb = array('utf8mb4_general_ci', 'utf8mb4_unicode_ci', 'utf8mb4_bin', 'utf8mb3_general_ci', 'utf8mb3_bin');
    verifie('MySQL 8 → MariaDB : unicode_ci', transferCollationFallback('utf8mb4_0900_ai_ci', $mariadb), 'utf8mb4_unicode_ci');
    verifie('suffixe d\'origine préféré', transferCollationFallback('utf8mb4_bin', array('utf8mb4_unicode_ci', 'utf8mb4_bin')), 'utf8mb4_bin');
    verifie('utf8_bin → utf8mb3_bin (même suffixe)', transferCollationFallback('utf8_bin', $mariadb), 'utf8mb3_bin');
    verifie('utf8_general_ci → utf8mb3_general_ci', transferCollationFallback('utf8_general_ci', $mariadb), 'utf8mb3_general_ci');
    verifie('ordre : unicode_ci avant general_ci', transferCollationFallback('utf8mb4_0900_as_cs', array('utf8mb4_general_ci', 'utf8mb4_unicode_ci')), 'utf8mb4_unicode_ci');
    verifie('general_ci avant bin', transferCollationFallback('utf8mb4_0900_as_cs', array('utf8mb4_bin', 'utf8mb4_general_ci')), 'utf8mb4_general_ci');
    verifie('aucun substitut', transferCollationFallback('latin7_estonian_cs', $mariadb), null);
});

cas('transferImportHooks : liste lue sur l\'hôte, sans --skip-plugins', static function (): void {
    $h = hote('staging', array('transfer_before_import' => array('acorn encryption:snapshot', 'cache flush')));
    Shell::quand('/core is-installed/', '+dep-installed');
    Shell::quand('/acorn/', 'Snapshot écrit.');

    transferImportHooks($h, 'transfer_before_import', true);
    verifie('commandes du projet', Shell::commandes($h->getAlias()), array(
        "if wp core is-installed --skip-plugins --skip-themes --no-color 2>&1; then echo '+dep-installed'; fi",
        'wp acorn encryption:snapshot',
        'wp cache flush',
    ));
    verifie('depuis la release', Shell::appel('/acorn/')['cwd'] ?? null, '/srv/' . $h->getAlias() . '/current');
    contient('sortie reprise', sortie(), 'Snapshot écrit.');

    Shell::reinitialiser();
    transferImportHooks(hote('vide'), 'transfer_before_import', true);
    transferImportHooks(null, 'transfer_after_import', false);
    verifie('liste vide : aucune commande', Shell::commandes(), array());

    Suite::$courant->set('transfer_after_import', 'pas une liste');
    transferImportHooks(null, 'transfer_after_import', false);
    verifie('valeur non tableau : ignorée', Shell::commandes(), array());

    Suite::$courant->set('transfer_after_import', array('acorn x'));
    transferImportHooks(null, 'transfer_after_import', false);
    verifie('local : liste du contexte courant, sans test d\'installation', Shell::commandes('local'), array('wp acorn x'));
});

cas('transferImportHooks : avant l\'import, WordPress absent ou échec ; après, avertissement', static function (): void {
    $h = hote('staging', array('transfer_before_import' => array('acorn a', 'acorn b'), 'transfer_after_import' => array('acorn c', 'acorn d')));

    transferImportHooks($h, 'transfer_before_import', true);
    aucuneCommande('WordPress absent : hooks avant ignorés', '/acorn/');
    contient('annoncé', sortie(), 'transfer_before_import ignoré : WordPress n\'est pas installé');

    Shell::quand('/core is-installed/', '+dep-installed');
    Shell::quand('/acorn a/', new \RuntimeException('Fatal error'));
    leve('échec avant : import annulé', static function () use ($h): void {
        transferImportHooks($h, 'transfer_before_import', true);
    }, \RuntimeException::class, 'import annulé');
    aucuneCommande('commande suivante non lancée', '/acorn b/');

    Shell::reinitialiser();
    Shell::quand('/acorn c/', new \RuntimeException('Fatal error'));
    transferImportHooks($h, 'transfer_after_import', false);
    uneCommande('échec après : on continue', '/acorn d/');
    aucuneCommande('après : pas de test d\'installation', '/is-installed/');
    contient('avertissement', sortie(), '« acorn c » a échoué sur « ' . $h->getAlias() . ' ». À relancer à la main.');
});

cas('transferDbImport : contrôles avant suppression, puis clean et import', static function (): void {
    $h = hote('staging');
    baseDestinationPrete(array('utf8mb4_unicode_ci'), "utf8mb4_unicode_ci\n");

    transferDbImport($h, '/srv/s/.dep/transfer/x/db.sql.gz');
    $commandes = Shell::commandes();
    verifie('ordre', array_map(static function (string $c): string {
        return (string) preg_replace('/^(wp \S+ \S+|gunzip -c|set -o pipefail).*$/s', '$1', $c);
    }, $commandes), array('wp cli cmd-dump', 'wp db query', 'gunzip -c', 'wp db clean', 'set -o pipefail'));
    verifie('db clean et non db reset', $commandes[3], 'wp db clean --yes --skip-plugins --skip-themes');
    verifie('import en pipe avec pipefail', $commandes[4], "set -o pipefail; gunzip -c '/srv/s/.dep/transfer/x/db.sql.gz' | wp db import - --skip-plugins --skip-themes");
    aucuneCommande('jamais db reset', '/db reset/');
    $import = Shell::appel('/db import/');
    verifie('délai', $import['timeout'] ?? null, 7200);
    verifie('depuis la release', $import['cwd'] ?? null, '/srv/' . $h->getAlias() . '/current');
});

cas('transferDbImport : wp db absent, rien n\'est supprimé', static function (): void {
    Shell::quand('/cmd-dump/', '0');

    leve('exception explicite', static function (): void {
        transferDbImport(hote('staging'), '/x.sql.gz');
    }, \RuntimeException::class, 'La commande « wp db » n\'existe pas');
    aucuneCommande('pas de suppression', '/db clean/');
});

cas('transferDbImport : collations absentes, --fix-collations', static function (): void {
    $h = hote('staging');
    baseDestinationPrete(array('utf8mb4_unicode_ci', 'utf8mb4_general_ci'), "utf8mb4_0900_ai_ci\nutf8mb4_unicode_ci\n");

    $e = leve('sans --fix-collations : refus avant suppression', static function () use ($h): void {
        transferDbImport($h, '/x.sql.gz');
    }, \RuntimeException::class, 'Le dump utilise des collations absentes');
    contient('substitut proposé', $e !== null ? $e->getMessage() : '', 'utf8mb4_0900_ai_ci → utf8mb4_unicode_ci');
    aucuneCommande('pas de suppression', '/db clean/');

    Shell::reinitialiser();
    baseDestinationPrete(array('utf8mb4_unicode_ci'), "latin7_estonian_cs\n");
    entree(array('fix-collations' => true));
    leve('sans substitut : refus même avec --fix-collations', static function () use ($h): void {
        transferDbImport($h, '/x.sql.gz');
    }, \RuntimeException::class, 'Aucun substitut disponible');
    aucuneCommande('toujours rien de supprimé', '/db clean/');

    Shell::reinitialiser();
    baseDestinationPrete(array('utf8mb4_unicode_ci'), "utf8mb4_0900_ai_ci\n");
    transferDbImport($h, '/x.sql.gz');
    verifie('réécriture dans le pipe, dump intact', Shell::appel('/db import/')['commande'] ?? null, "set -o pipefail; gunzip -c '/x.sql.gz' | sed -e 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' | wp db import - --skip-plugins --skip-themes");
    contient('avertissement', sortie(), 'Collation réécrite : utf8mb4_0900_ai_ci → utf8mb4_unicode_ci');

    Shell::reinitialiser();
    Shell::quand('/cmd-dump/', '1');
    transferDbImport($h, '/x.sql.gz');
    aucuneCommande('collations du serveur illisibles : dump non scanné', '/grep -oiE/');
    uneCommande('import quand même', '/db import/');
});

cas('transferDbImport : pipefail fait échouer un import sur un dump tronqué (exécution réelle)', static function (): void {
    Shell::$reel = true;
    // « wp » qui lit tout son entrée et réussit toujours : seul pipefail peut faire échouer le pipe.
    Suite::$courant->set('bin/wp_local', 'cat > /dev/null #');
    Shell::quand('/cmd-dump/', '1');
    Shell::quand('/SHOW COLLATION/', '');
    Shell::quand('/db clean/', '');

    $dump = baseDump(str_repeat("INSERT INTO t VALUES (1);\n", 500));
    file_put_contents($dump, substr((string) file_get_contents($dump), 0, 40));

    leve('gunzip en échec : exception malgré un « wp » qui réussit', static function () use ($dump): void {
        transferDbImport(null, $dump);
    }, \RuntimeException::class);
});

cas('transferDbExport : WordPress inutilisable, puis export, compression', static function (): void {
    $h = hote('production');
    Shell::quand('/core is-installed/', 'Error: Error establishing a database connection.');

    $e = leve('inutilisable : exception avec diagnostic', static function () use ($h): void {
        transferDbExport($h, '/srv/p/.dep/transfer/w');
    }, \RuntimeException::class, 'WordPress est inutilisable sur « ' . $h->getAlias() . ' » : export impossible.');
    contient('message de WP-CLI', $e !== null ? $e->getMessage() : '', 'WP-CLI : Error: Error establishing a database connection.');
    aucuneCommande('pas d\'export', '/db export/');

    Shell::reinitialiser();
    Shell::quand('/core is-installed/', '+dep-installed');
    Shell::quand('/config get DB_NAME/', 'prod_db');
    Shell::quand('/^du /', '12M');

    verifie('archive rendue', transferDbExport($h, '/srv/p/.dep/transfer/w'), '/srv/p/.dep/transfer/w/db.sql.gz');
    contient('export surveillé', Shell::appel('/db export/')['commande'] ?? '', "( wp db export '/srv/p/.dep/transfer/w/db.sql' --skip-plugins --skip-themes ) &");
    $gzip = uneCommande('compression', '/^gzip -9 /');
    verifie('gzip -9 du dump', $gzip['commande'] ?? null, "gzip -9 '/srv/p/.dep/transfer/w/db.sql'");
    verifie('délai de compression', $gzip['timeout'] ?? null, 3600);
    contient('base annoncée', sortie(), 'Base : prod_db');
    verifie('tout sur la source', array_unique(array_column(Shell::$appels, 'env')), array($h->getAlias()));
});
