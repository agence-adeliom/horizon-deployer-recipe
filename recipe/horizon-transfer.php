<?php

/*
|--------------------------------------------------------------------------
| Transfert de données entre environnements (base de données & uploads)
|--------------------------------------------------------------------------
|
| Un « environnement » est soit `local` (la machine courante), soit l'alias d'un
| hôte Deployer. Tout passe par la configuration Deployer : ce recipe est
| réutilisable tel quel d'un projet à l'autre.
|
|   dep db:pull       <hôte>                  distant → local
|   dep uploads:pull  <hôte>                  distant → local
|   dep db:push       --from=X --to=Y         local|distant → distant
|   dep uploads:push  --from=X --to=Y         local|distant → distant
|
| --from / --to sont facultatifs : ils sont demandés interactivement si absents.
| La destination d'un push est toujours un environnement distant ; pour rapatrier
| vers le local, ce sont les tâches pull, qui gèrent en plus l'import et la
| réécriture d'URLs en local.
|
| Sûreté :
|  - un pull ne modifie JAMAIS le distant, hormis ses propres fichiers temporaires ;
|  - toutes les commandes WP-CLI tournent avec --skip-plugins --skip-themes, ce qui
|    évite qu'une routine de mise à jour de plugin ne se déclenche au passage.
|    Attention : les mu-plugins échappent à --skip-plugins, WordPress les chargeant
|    inconditionnellement ;
|  - un push demande toujours confirmation de la destination, et exige la saisie de
|    l'alias en clair quand l'hôte est protégé ({{transfer_protected}}, vrai par
|    défaut si l'alias ou le stage contient « prod ») ;
|  - avant tout import, la base de la destination est dumpée et CONSERVÉE dans
|    {{transfer_backup_dir}} ;
|  - tous les fichiers temporaires vivent dans un répertoire de travail unique par
|    environnement, supprimé en fin de tâche et via fail() en cas d'échec.
|
*/

namespace Deployer;

use Deployer\Host\Host;
use Deployer\Host\Localhost;
use Symfony\Component\Console\Input\InputOption;

// À inclure avec require_once. Aucun garde runtime ne peut protéger d'un double
// `require` : PHP déclare les fonctions d'un fichier inclus dès sa compilation,
// donc la redéclaration est fatale avant même qu'un `if (defined(...)) return;`
// n'ait pu s'exécuter. La constante ci-dessous sert donc de marqueur de version,
// utile pour tester la présence du recipe depuis un deploy.php.
define('HORIZON_TRANSFER_RECIPE', '1.2.0');

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
|
| Toutes ces valeurs sont surchargeables depuis le deploy.php du projet ou
| depuis l'inventaire, à condition de le faire APRÈS le require de ce fichier.
|
*/

// Répertoires de travail temporaires (supprimés en fin de tâche).
set('transfer_tmp_dir', '{{deploy_path}}/.dep/transfer');
set('transfer_local_tmp_dir', static fn(): string => transferProjectRoot() . '/.dep/transfer');

// Sauvegardes de base conservées (jamais nettoyées automatiquement).
set('transfer_backup_dir', '{{deploy_path}}/.dep/backups');

// Chemin du dossier uploads, relatif à la racine du projet comme à celle d'une
// release. Valeur par défaut Bedrock ; pour un WordPress classique :
// set('uploads_path', 'wp-content/uploads');
set('uploads_path', 'web/app/uploads');

// WP-CLI local. Les tâches s'exécutent dans le conteneur DDEV, où `wp` est dans le PATH.
//
// Le PATH ne suffit pourtant pas à désigner un binaire utilisable : un projet qui
// requiert « wp-cli/wp-cli » obtient le framework SANS aucune commande (ni `db`, ni
// `search-replace`, ni `core` — elles vivent dans « wp-cli/wp-cli-bundle »), et
// Composer en place un proxy dans vendor/bin, prioritaire dans le PATH, qui masque
// le phar complet fourni par ddev. On retient donc le premier candidat qui connaît
// réellement `wp db`, ce qui préfère naturellement ce phar sans coder son chemin en
// dur — le recipe reste utilisable hors ddev.
set('bin/wp_local', static function (): string {
    $candidates = [];

    foreach (explode("\n", (string) transferTryLocally('which -a wp 2>/dev/null || command -v wp 2>/dev/null')) as $line) {
        if (($line = trim($line)) !== '' && !in_array($line, $candidates, true)) {
            $candidates[] = $line;
        }
    }

    foreach ($candidates as $index => $candidate) {
        if (!transferWpHasCommand(null, 'db', $candidate)) {
            continue;
        }

        if ($index > 0) {
            warning(sprintf(
                'WP-CLI local : %s ignoré (installé sans ses paquets de commandes), %s retenu.',
                $candidates[0],
                $candidate,
            ));
        }

        return $candidate;
    }

    // Aucun candidat exploitable : on garde le comportement historique et on laisse
    // l'erreur se produire là où elle sera diagnostiquée.
    return 'wp';
});

// Préfixe utilisé uniquement dans les commandes suggérées à l'utilisateur, qui
// seront tapées depuis l'hôte et non depuis le conteneur. `ddev exec` et non
// `ddev` : le binaire retenu ci-dessus peut être un chemin absolu, que `ddev`
// n'accepte pas comme sous-commande. Le stdin est transmis dans les deux cas, ce
// qui laisse les pipes `gunzip -c … | …` fonctionner depuis l'hôte.
set('transfer_local_prefix', 'ddev exec ');

// Tables exclues du search-replace. Les tables de logs et de caches de plugins
// (Wordfence en tête) stockent des chemins de fichiers et du trafic, jamais du
// contenu éditorial : la passe « domaine nu » y réécrirait des chemins système par
// centaines de milliers de lignes. Les jokers sont supportés par WP-CLI.
// Chaîne vide pour ne rien exclure.
set('transfer_search_replace_skip_tables', '*_wf*');

// Un hôte protégé exige la saisie de son alias pour être écrasé. Surchargeable
// par hôte dans l'inventaire : `transfer_protected: false`.
set('transfer_protected', static function (): bool {
    return str_contains(currentHost()->getAlias(), 'prod') || str_contains((string) get('stage', ''), 'prod');
});

// WP-CLI distant, installé à la demande s'il n'est pas disponible. Défini ici pour
// que le recipe soit autonome ; un projet qui définit son propre `bin/wp` après le
// require de ce fichier garde la main.
set('bin/wp', function () {
    if (test('[ -f {{deploy_path}}/.dep/wp-cli.phar ]')) {
        return '{{bin/php}} {{deploy_path}}/.dep/wp-cli.phar';
    }

    if (commandExist('wp')) {
        return 'wp';
    }

    warning('WP-CLI introuvable. Installation dans "{{deploy_path}}/.dep/wp-cli.phar".');
    run('curl -o {{deploy_path}}/.dep/wp-cli.phar https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar');

    return '{{bin/php}} {{deploy_path}}/.dep/wp-cli.phar';
});

option('favicon-only', null, InputOption::VALUE_NONE, 'uploads:pull : ne récupère que le favicon du site (site_icon) au lieu de tous les uploads');
option('from', null, InputOption::VALUE_REQUIRED, 'Environnement source : local ou alias d\'hôte');
option('to', null, InputOption::VALUE_REQUIRED, 'Environnement de destination : alias d\'hôte');
option('strategy', null, InputOption::VALUE_REQUIRED, 'uploads:push : merge (fusion) ou mirror (miroir, supprime à la destination)');
option('checksum', null, InputOption::VALUE_NONE, 'uploads:push : compare les fichiers sur leur contenu et non sur taille + date (lent)');
option('precise', null, InputOption::VALUE_NONE, 'search-replace : force le traitement PHP de toutes les colonnes (plus lent, gourmand en mémoire)');

/*
|--------------------------------------------------------------------------
| Environnements
|--------------------------------------------------------------------------
*/

/**
 * Racine locale du projet. rsync n'hérite pas du répertoire de travail de
 * Deployer contrairement à runLocally() : on manipule donc toujours des chemins
 * locaux absolus.
 */
function transferProjectRoot(): string
{
    if ($root = getenv('DEPLOYER_ROOT')) {
        return $root;
    }

    return defined('DEPLOYER_DEPLOY_FILE') ? dirname(DEPLOYER_DEPLOY_FILE) : (string) getcwd();
}

/**
 * @return string[] alias de tous les hôtes déclarés
 */
function transferAliases(): array
{
    return array_keys(Deployer::get()->hosts->all());
}

/**
 * Résout un nom d'environnement. Retourne null pour `local`.
 *
 * On n'utilise pas host() qui CRÉE un hôte quand l'alias est inconnu : une faute
 * de frappe passerait alors inaperçue et la tâche s'exécuterait dans le vide.
 */
function transferResolveEnv(string $name, bool $allowLocal): ?Host
{
    if ($name === 'local') {
        if (!$allowLocal) {
            throw new \RuntimeException('« local » n\'est pas une destination valide : utiliser db:pull / uploads:pull.');
        }

        return null;
    }

    $hosts = Deployer::get()->hosts;

    if (!$hosts->has($name)) {
        throw new \RuntimeException(sprintf(
            'Environnement « %s » inconnu. Disponibles : %s.',
            $name,
            implode(', ', array_merge(['local'], transferAliases())),
        ));
    }

    return $hosts->get($name);
}

function transferEnvLabel(?Host $env): string
{
    return $env === null ? 'local' : $env->getAlias();
}

/**
 * Deux hôtes sur la même machine et le même compte : le transfert réseau est
 * inutile, les fichiers sont déjà visibles de part et d'autre.
 */
function transferSameServer(?Host $a, ?Host $b): bool
{
    return $a !== null && $b !== null && $a->connectionString() === $b->connectionString();
}

/**
 * Un hôte déclaré via localhost() s'exécute sur la machine courante, comme le fait
 * déjà run() : pour rsync, son extrémité est un simple chemin, sans ssh ni préfixe
 * « hôte: ».
 */
function transferIsLocalEndpoint(?Host $env): bool
{
    return $env === null || $env instanceof Localhost;
}

/*
|--------------------------------------------------------------------------
| Exécution indifférenciée local / distant
|--------------------------------------------------------------------------
*/

/**
 * Exécute une commande sur un environnement, local ou distant.
 */
function transferRun(?Host $env, string $command, array $options = []): string
{
    if ($env === null) {
        return runLocally($command, $options);
    }

    $output = '';

    on($env, static function () use ($command, $options, &$output): void {
        $output = run($command, $options);
    });

    return $output;
}

/**
 * Comme transferRun(), mais depuis la racine de l'installation WordPress pour que
 * WP-CLI trouve le site. run() encadre la commande de parenthèses après le `cd` :
 * les pipes et les `set -o pipefail` restent donc corrects.
 */
function transferWpRun(?Host $env, string $command, array $options = []): string
{
    if ($env === null) {
        return runLocally($command, $options);
    }

    $output = '';

    on($env, static function () use ($command, $options, &$output): void {
        within('{{current_path}}', static function () use ($command, $options, &$output): void {
            $output = run($command, $options);
        });
    });

    return $output;
}

function transferTest(?Host $env, string $command): bool
{
    return trim(transferWpRun($env, "if $command; then echo +true; fi")) === '+true';
}

function transferWpBin(?Host $env): string
{
    return $env === null ? '{{bin/wp_local}}' : '{{bin/wp}}';
}

/**
 * Vérifie qu'une installation WordPress est exploitable, en CONSERVANT ce que
 * WP-CLI répond quand elle ne l'est pas.
 *
 * Le stderr est capturé au lieu d'être jeté dans /dev/null : sans lui, n'importe
 * quelle défaillance se réduisait à un « WordPress n'est pas installé » trompeur,
 * alors que WordPress est le plus souvent bel et bien installé. Les causes
 * réellement rencontrées sont ailleurs — un `path` de wp-cli.yml qui ne désigne
 * pas le cœur de WordPress (sous Bedrock « web/wp », pas « web »), une base
 * injoignable depuis le CLI, ou un {{bin/wp}} incompatible.
 *
 * @return array{0: bool, 1: string} [installé, sortie de WP-CLI]
 */
function transferWpIsInstalled(?Host $env): array
{
    // --no-color : sinon le message d'erreur arrive truffé de codes ANSI.
    $command = transferWpBin($env) . ' core is-installed --skip-plugins --skip-themes --no-color';

    // On se fie à un marqueur et non au code de sortie : un exit non nul ferait
    // lever run() par Deployer avant qu'on ait pu lire l'explication de WP-CLI.
    $output = transferWpRun($env, "if $command 2>&1; then echo '+dep-installed'; fi");

    return [
        str_contains($output, '+dep-installed'),
        trim(str_replace('+dep-installed', '', $output)),
    ];
}

/**
 * Vérifie qu'une commande WP-CLI est réellement enregistrée sur un environnement.
 *
 * Tester l'existence du binaire (`command -v wp`) ne dit rien de ses capacités :
 * « wp-cli/wp-cli » installé seul ne connaît aucune commande. Pire, l'échec est
 * silencieux quand la base est vide — WP-CLI charge WordPress pour chercher la
 * commande ailleurs, `wp_not_installed()` déclenche une redirection, et le processus
 * se termine avec le code de sortie 0. Autrement dit `wp db reset` rend la main sans
 * rien faire ET sans erreur, juste avant un import.
 *
 * `cli cmd-dump` interroge le registre et ne dépend pas de l'état de la base.
 */
function transferWpHasCommand(?Host $env, string $command, ?string $bin = null): bool
{
    $bin ??= transferWpBin($env);

    $count = trim(transferWpRun($env, sprintf(
        '%s cli cmd-dump --skip-plugins --skip-themes 2>/dev/null | grep -c %s || true',
        $bin,
        escapeshellarg(sprintf('"name":"%s"', $command)),
    )));

    return (int) $count > 0;
}

/**
 * Diagnostic exploitable quand WordPress n'est pas utilisable sur un environnement.
 *
 * C'est presque toujours la configuration du projet qui est en cause, pas le
 * recipe : sans le message de WP-CLI, le binaire employé et le répertoire
 * d'exécution, l'utilisateur n'a aucune prise sur l'erreur.
 */
function transferWpMissingDiagnosis(?Host $env, string $wpOutput): string
{
    $lines = [];

    foreach (explode("\n", $wpOutput) as $line) {
        if (trim($line) !== '') {
            $lines[] = '   WP-CLI : ' . trim($line);
        }
    }

    $lines[] = '   Binaire : ' . transferResolve($env, transferWpBin($env));
    $lines[] = '   Exécuté depuis : ' . ($env === null
        ? transferProjectRoot()
        : transferResolvePath($env, '{{current_path}}'));
    $lines[] = '   Piste la plus fréquente : le « path » du wp-cli.yml du projet doit désigner le';
    $lines[] = '   cœur de WordPress — sous Bedrock « web/wp », et non « web ».';

    return implode("\n", $lines);
}

/**
 * Résout les {{placeholders}} dans le contexte d'un environnement donné.
 *
 * Indispensable pour afficher une commande contenant {{bin/wp}} : hors du contexte
 * de son hôte, ce placeholder serait résolu contre l'hôte courant — ce qui, en
 * plus d'être faux, déclencherait un appel distant parasite puisque sa valeur est
 * calculée par une closure qui teste la présence du binaire.
 */
function transferResolve(?Host $env, string $value): string
{
    if ($env === null) {
        return parse($value);
    }

    $resolved = '';

    on($env, static function () use ($value, &$resolved): void {
        $resolved = parse($value);
    });

    return $resolved;
}

/**
 * Comme transferResolve(), mais rend le chemin exploitable ENTRE QUOTES.
 *
 * Deployer tolère un `deploy_path` relatif au HOME (« ~/public_html ») parce qu'il
 * ne quote pas ses `cd`. Ici, tout chemin passe par escapeshellarg() — et un « ~ »
 * entre quotes simples n'est pas expansé par le shell. Sans cette résolution, le
 * recipe crée un répertoire *littéralement* nommé « ~ », y range les sauvegardes
 * tout en annonçant un autre emplacement, et ne trouve plus le dossier uploads.
 *
 * Le HOME est lu une fois par environnement. Les formes « ~utilisateur/… » ne sont
 * pas traitées : Deployer ne les produit pas.
 */
function transferResolvePath(?Host $env, string $value): string
{
    $path = transferResolve($env, $value);

    if ($path !== '~' && !str_starts_with($path, '~/')) {
        return $path;
    }

    static $homes = [];
    $key = transferEnvLabel($env);

    if (!array_key_exists($key, $homes)) {
        $homes[$key] = trim(transferRun($env, 'printf %s "$HOME"'));
    }

    return $homes[$key] === '' ? $path : $homes[$key] . substr($path, 1);
}

/**
 * Taille lisible d'un fichier ou d'un dossier, sur n'importe quel environnement.
 */
function transferSize(?Host $env, string $path, bool $summarize = false): string
{
    return trim(transferRun($env, sprintf('du -h%s %s | cut -f1', $summarize ? 's' : '', escapeshellarg($path))));
}

/**
 * Commande locale purement informative : ne doit jamais faire échouer la tâche.
 */
function transferTryLocally(string $command): ?string
{
    try {
        return trim(runLocally($command)) ?: null;
    } catch (\Throwable) {
        return null;
    }
}

/**
 * Lance une commande longue en tâche de fond et affiche toutes les 5 secondes la
 * taille du fichier en cours d'écriture. Le code de sortie est propagé (`wait`),
 * ce qui évite qu'un export raté passe pour un succès.
 */
function transferRunWatched(?Host $env, string $command, string $watchedFile, string $label, int $timeout = 7200): void
{
    $script = strtr(
        <<<'SH'
        ( :COMMAND: ) &
        dep_pid=$!
        while kill -0 $dep_pid 2>/dev/null; do
            sleep 5
            if [ -f :FILE: ]; then
                echo "   … :LABEL: $(du -h :FILE: | cut -f1)"
            fi
        done
        wait $dep_pid
        SH,
        [
            ':COMMAND:' => $command,
            ':FILE:' => escapeshellarg($watchedFile),
            ':LABEL:' => $label,
        ],
    );

    transferWpRun($env, $script, ['timeout' => $timeout, 'real_time_output' => true]);
}

/**
 * rsync entre deux environnements, avec progression en pourcentage.
 *
 * rsync n'accepte qu'une seule extrémité distante : on exécute donc la commande là
 * où il faut — en local pour un sens local↔distant, sur le serveur lui-même quand
 * les deux hôtes le partagent.
 *
 * On n'utilise ni download()/upload() ni la barre de progression de Deployer :
 * celle-ci se base sur le compteur `to-chk` de rsync, que rsync n'émet qu'à la
 * toute fin pour un fichier unique (la barre saute de 0 à 100 %). La progression
 * native de rsync sépare par ailleurs ses mises à jour par des \r que Deployer
 * n'interprète pas : on les convertit en lignes et on n'en garde qu'une par palier
 * de 10 %.
 */
function transferRsyncRunHost(?Host $from, ?Host $to): ?Host
{
    return !transferIsLocalEndpoint($from) && !transferIsLocalEndpoint($to) ? $from : null;
}

/**
 * Force rsync à vider sa sortie ligne à ligne.
 *
 * Hors terminal, rsync bufferise par blocs de 4 Ko : une progression faite de lignes
 * courtes n'apparaîtrait que par paquets de ~160. --outbuf n'existe qu'à partir de
 * rsync 3.1, d'où la détection — une option inconnue ferait échouer la commande.
 *
 * À n'utiliser QUE pour une sortie en lignes : la progression de transfert est
 * séparée par des \r, que le mode ligne ne viderait jamais.
 *
 * @return string[]
 */
function transferRsyncLineBuffered(?Host $env): array
{
    static $supported = [];
    $key = transferEnvLabel($env);

    if (!array_key_exists($key, $supported)) {
        $supported[$key] = transferTest($env, 'rsync --help 2>&1 | grep -q -- --outbuf');
    }

    return $supported[$key] ? ['--outbuf=L'] : [];
}

function transferRsyncCommand(?Host $from, string $fromPath, ?Host $to, string $toPath, array $options): array
{
    $runOn = null;
    $sshHost = null;
    $source = $fromPath;
    $destination = $toPath;
    $fromIsLocal = transferIsLocalEndpoint($from);
    $toIsLocal = transferIsLocalEndpoint($to);

    if (!$fromIsLocal && !$toIsLocal) {
        if (!transferSameServer($from, $to)) {
            throw new \RuntimeException('rsync direct impossible entre deux serveurs distincts : passer par le local.');
        }

        // Même machine : rsync tourne là-bas, avec deux chemins locaux.
        $runOn = $from;
    } elseif (!$fromIsLocal) {
        $sshHost = $from;
        $source = $from->connectionString() . ':' . $fromPath;
    } elseif (!$toIsLocal) {
        $sshHost = $to;
        $destination = $to->connectionString() . ':' . $toPath;
    }

    if ($sshHost !== null) {
        // rsync retire les quotes en découpant la valeur de -e : les options de
        // connexion calculées par Deployer sont réutilisables telles quelles.
        $options[] = '-e';
        $options[] = 'ssh ' . implode(' ', $sshHost->connectionOptionsArray());
    }

    $command = 'rsync ' . implode(' ', array_map('escapeshellarg', $options))
        . ' ' . escapeshellarg($source) . ' ' . escapeshellarg($destination);

    return [$runOn, $command];
}

/**
 * Simule un rsync et compte ce qu'il ferait réellement, sans rien écrire.
 *
 * Sert à répondre AVANT d'agir à la question « pourquoi rien n'a été copié ? » :
 * la vérification rapide de rsync compare la taille et la date de modification,
 * pas le contenu. Un fichier modifié à taille et date identiques serait donc
 * ignoré — d'où l'option --checksum pour comparer sur le contenu.
 *
 * @return array{transfer: int, delete: int}
 */
function transferRsyncPreview(
    ?Host $from,
    string $fromPath,
    ?Host $to,
    string $toPath,
    array $extraOptions = [],
    int $totalFiles = 0,
): array {
    // -ii (double) et non -i : avec -i simple, rsync n'émet AUCUNE ligne pour les
    // fichiers inchangés, il n'y aurait donc rien à compter pour jauger l'avancement.
    // Doublé, il sort une ligne par fichier examiné : « .f » inchangé, « > » à
    // recevoir, « *deleting » à supprimer.
    $options = array_merge(
        ['-a', '--dry-run', '-ii'],
        transferRsyncLineBuffered(transferRsyncRunHost($from, $to)),
        $extraOptions,
    );
    [$runOn, $command] = transferRsyncCommand($from, $fromPath, $to, $toPath, $options);

    // Un palier tous les 10 % du nombre de fichiers source, à défaut tous les 500.
    $step = $totalFiles > 0 ? max(1, (int) floor($totalFiles / 10)) : 500;
    $suffix = $totalFiles > 0 ? sprintf(' sur ~%d', $totalFiles) : '';

    // `set -e` est indispensable : sans lui, un rsync en échec renverrait
    // silencieusement « 0 fichier à transférer ».
    $script = strtr(
        <<<'SH'
        set -e
        set -o pipefail
        :RSYNC: | {
            dep_n=0
            dep_t=0
            dep_d=0
            dep_next=:STEP:
            while IFS= read -r dep_line; do
                dep_n=$((dep_n + 1))
                case $dep_line in
                    '>'*) dep_t=$((dep_t + 1)) ;;
                    '*deleting'*) dep_d=$((dep_d + 1)) ;;
                esac
                if [ "$dep_n" -ge "$dep_next" ]; then
                    dep_next=$((dep_n + :STEP:))
                    printf '   … %d fichiers analysés:SUFFIX:\n' "$dep_n"
                fi
            done
            printf '   → %d fichier(s) à transférer, %d à supprimer à la destination.\n' "$dep_t" "$dep_d"
        }
        SH,
        [
            ':RSYNC:' => $command,
            ':STEP:' => (string) $step,
            ':SUFFIX:' => $suffix,
        ],
    );

    $output = transferRun($runOn, $script, ['timeout' => 14400, 'real_time_output' => true]);

    // La dernière ligne porte le bilan, et sert à la fois d'affichage et de résultat.
    if (!preg_match('/(\d+) fichier\(s\) à transférer, (\d+) à supprimer/', $output, $matches)) {
        throw new \RuntimeException('Impossible de lire le bilan de la simulation rsync.');
    }

    return [
        'transfer' => (int) $matches[1],
        'delete' => (int) $matches[2],
    ];
}

function transferRsync(?Host $from, string $fromPath, ?Host $to, string $toPath, array $extraOptions = []): void
{
    // --no-inc-recursive : rsync connaît la taille totale dès le départ, sans quoi
    // le pourcentage serait faux tant que le parcours des fichiers n'est pas fini.
    $options = array_merge(['-a', '--no-inc-recursive', '--info=progress2', '--human-readable', '--stats'], $extraOptions);
    [$runOn, $rsync] = transferRsyncCommand($from, $fromPath, $to, $toPath, $options);

    // rsync sépare ses mises à jour de progression par des \r. On les lit avec une
    // boucle `read -d`, et surtout pas via un pipe vers awk ou tr : ceux-ci lisent
    // leur entrée par blocs, ce qui ne restituait toute la progression qu'à la fin
    // du transfert (mesuré). `read` consomme l'entrée octet par octet, donc chaque
    // palier s'affiche à l'instant où rsync l'émet.
    $script = strtr(
        <<<'SH'
        set -o pipefail
        :RSYNC: | {
            dep_last=-100
            while IFS= read -r -d $'\r' dep_line; do
                set -- $dep_line
                dep_pct=${2%\%}
                case $dep_pct in (*[!0-9]*|'') continue;; esac
                if [ "$dep_pct" -ge "$((dep_last + 10))" ]; then
                    dep_last=$dep_pct
                    printf '   … %s transférés (%s, %s, reste %s)\n' "$2" "$1" "$3" "$4"
                fi
            done
            # Ce qui suit le dernier \r porte le bilan --stats de rsync. Il lève
            # l'ambiguïté d'un transfert à 0 % : rsync n'envoie que ce qui diffère,
            # donc « 0 fichier » signifie que la destination était déjà à jour, et
            # non qu'il ne s'est rien passé.
            printf '%s\n' "$dep_line" \
                | grep -E '^(Number of regular files transferred|Total transferred file size)' \
                | sed -e 's/^Number of regular files transferred: /   bilan : /' \
                      -e 's/^Total transferred file size: /   volume transféré : /' \
                      -e 's/^   bilan : /   fichiers transférés : /' \
                      -e 's/ bytes$/ octets/' || true
        }
        SH,
        [':RSYNC:' => $rsync],
    );

    transferRun($runOn, $script, ['timeout' => 14400, 'real_time_output' => true]);
}

/*
|--------------------------------------------------------------------------
| Répertoires de travail et nettoyage
|--------------------------------------------------------------------------
*/

/**
 * Fichier d'état listant les répertoires temporaires créés, tous environnements
 * confondus (un push en implique jusqu'à trois : source, local, destination).
 *
 * L'état est persisté sur le disque local et non dans une variable statique :
 * Deployer exécute chaque tâche dans un processus distinct, donc une tâche
 * enregistrée via fail() ne verrait rien de ce que la tâche en échec a mis en
 * mémoire. C'est aussi ce qui permet de nettoyer un hôte source qui n'était pas
 * dans le sélecteur.
 */
function transferStateFile(): string
{
    return transferProjectRoot() . '/.dep/transfer-state.json';
}

/**
 * @return array<int, array{env: ?string, workdir: string}>
 */
function transferReadState(): array
{
    $file = transferStateFile();

    if (!is_file($file)) {
        return [];
    }

    $data = json_decode((string) file_get_contents($file), true);

    return is_array($data) ? $data : [];
}

function transferResetState(): void
{
    $file = transferStateFile();

    if (is_file($file)) {
        unlink($file);
    }
}

function transferRegisterWorkdir(?Host $env, string $workdir): void
{
    $entries = transferReadState();
    $entries[] = ['env' => $env?->getAlias(), 'workdir' => $workdir];

    $file = transferStateFile();

    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }

    file_put_contents($file, (string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/**
 * Crée (une seule fois par environnement) un répertoire de travail unique, et
 * purge au passage les orphelins d'une exécution interrompue brutalement (Ctrl+C,
 * coupure SSH). Le filtre sur le nom garantit qu'on ne supprime que nos propres
 * dossiers horodatés.
 */
function transferWorkdir(?Host $env): string
{
    foreach (transferReadState() as $entry) {
        if ($entry['env'] === $env?->getAlias()) {
            return $entry['workdir'];
        }
    }

    $base = $env === null
        ? get('transfer_local_tmp_dir')
        : transferResolvePath($env, '{{transfer_tmp_dir}}');

    $workdir = $base . '/' . date('Ymd-His') . '-' . substr(md5(uniqid('', true)), 0, 6);

    transferRun($env, 'mkdir -p ' . escapeshellarg($workdir));
    transferRun($env, sprintf(
        'find %s -mindepth 1 -maxdepth 1 -type d -name "20*-*-*" -mtime +1 -exec rm -rf {} + 2>/dev/null || true',
        escapeshellarg($base),
    ));

    transferRegisterWorkdir($env, $workdir);
    info(sprintf('Répertoire temporaire (%s) : <comment>%s</comment>', transferEnvLabel($env), $workdir));

    return $workdir;
}

/**
 * Supprime tous les répertoires de travail créés, sur tous les environnements.
 */
task('transfer:cleanup', static function (): void {
    $hosts = Deployer::get()->hosts;

    foreach (transferReadState() as $entry) {
        $alias = $entry['env'] ?? null;

        if ($alias !== null && !$hosts->has($alias)) {
            warning(sprintf('Hôte « %s » introuvable : %s n\'a pas été nettoyé.', $alias, $entry['workdir']));

            continue;
        }

        $env = $alias === null ? null : $hosts->get($alias);

        info(sprintf(
            '🧹 Nettoyage du répertoire temporaire (%s) : <comment>%s</comment>',
            transferEnvLabel($env),
            $entry['workdir'],
        ));
        transferRun($env, 'rm -rf ' . escapeshellarg($entry['workdir']));
        // Retire le dossier parent s'il est vide : aucune trace résiduelle.
        transferRun($env, 'rmdir ' . escapeshellarg(dirname($entry['workdir'])) . ' 2>/dev/null || true');
    }

    transferResetState();
})->hidden();

/*
|--------------------------------------------------------------------------
| Questions et garde-fous
|--------------------------------------------------------------------------
*/

/**
 * Demande confirmation avant une action qui modifie un environnement.
 *
 * On se fie uniquement à Deployer pour détecter l'interactivité : `ddev php`
 * n'alloue pas de TTY, donc tester stream_isatty(STDIN) ferait sauter la question
 * alors qu'on est bien en interactif. Pour sauter l'étape : `dep ... -n`.
 */
function transferConfirm(string $question, bool $default): bool
{
    if (!input()->isInteractive()) {
        warning('Mode non interactif (-n) : question ignorée, réponse « non ».');

        return false;
    }

    return askConfirmation($question, $default);
}

/**
 * Demande confirmation avant une écriture de travail volumineuse — une archive, un
 * staging — dont le seul enjeu est l'espace disque : le site n'est pas touché et le
 * fichier est supprimé en fin de tâche.
 *
 * Le mode non interactif ne vaut PAS refus ici, contrairement à transferConfirm() :
 * bloquer rendrait les tâches inutilisables en scripté sans rien protéger de
 * durable. Les écritures qui modifient réellement un environnement — import, rsync
 * vers une destination — restent, elles, refusées en -n.
 */
function transferConfirmDiskUsage(string $question, string $size): bool
{
    if (!input()->isInteractive()) {
        warning(sprintf('Mode non interactif : poursuite sans confirmation (%s à écrire).', $size));

        return true;
    }

    return askConfirmation($question, true);
}

/**
 * Normalise le retour d'askChoice(), qui renvoie un tableau en sortie « quiet ».
 */
function transferChoice(string $question, array $choices, ?string $default = null): string
{
    $answer = askChoice($question, $choices, $default);

    return (string) (is_array($answer) ? array_key_first($answer) : $answer);
}

function transferIsProtected(Host $host): bool
{
    $protected = false;

    on($host, static function () use (&$protected): void {
        $protected = (bool) get('transfer_protected');
    });

    return $protected;
}

/**
 * Confirmation d'écrasement d'un environnement de destination. Un hôte protégé
 * exige la saisie de son alias : un [y/N] est trop facile à valider par réflexe.
 */
function transferConfirmDestination(Host $to): bool
{
    $alias = $to->getAlias();

    if (!input()->isInteractive()) {
        warning(sprintf('Mode non interactif : aucune écriture sur « %s ». Abandon.', $alias));

        return false;
    }

    if (!transferIsProtected($to)) {
        return askConfirmation(sprintf('Écraser les données de « %s » ?', $alias), false);
    }

    warning(sprintf('« %s » est un environnement PROTÉGÉ. Ses données vont être ÉCRASÉES.', $alias));
    $typed = ask(sprintf('Saisir « %s » pour confirmer (ou Entrée pour annuler) :', $alias));

    if (trim((string) $typed) !== $alias) {
        warning('Saisie incorrecte.');

        return false;
    }

    return true;
}

/**
 * Détermine les environnements source et destination : options --from / --to si
 * fournies, sinon question interactive.
 *
 * @return array{0: ?Host, 1: Host}
 */
function transferSelectEnvs(string $what): array
{
    $aliases = transferAliases();

    if ($aliases === []) {
        throw new \RuntimeException('Aucun hôte déclaré : rien à cibler.');
    }

    $toName = input()->getOption('to');
    $to = null;

    if ($toName !== null) {
        $to = transferResolveEnv((string) $toName, false);
    } else {
        // Pas de --to : le sélecteur d'hôte de Deployer fait office de destination.
        // C'est aussi ce qui répond à sa question « Select hosts » quand la commande
        // est lancée sans sélecteur.
        $selected = selectedHosts();

        if (count($selected) === 1) {
            $to = $selected[0];
            info(sprintf('Destination : <comment>%s</comment> (hôte sélectionné)', $to->getAlias()));
        } elseif (!input()->isInteractive()) {
            throw new \RuntimeException('--to est obligatoire en mode non interactif, ou passer un seul hôte en sélecteur.');
        } else {
            $to = transferResolveEnv(
                transferChoice(sprintf('Destination pour %s ?', $what), array_combine($aliases, $aliases)),
                false,
            );
        }
    }

    if ($to === null) {
        throw new \RuntimeException('La destination doit être un environnement distant.');
    }

    $fromName = input()->getOption('from');

    if ($fromName === null) {
        if (!input()->isInteractive()) {
            throw new \RuntimeException('--from est obligatoire en mode non interactif.');
        }

        $choices = array_values(array_diff(array_merge(['local'], $aliases), [$to->getAlias()]));
        $fromName = transferChoice(sprintf('Source pour %s ?', $what), array_combine($choices, $choices));
    }

    if ((string) $fromName === $to->getAlias()) {
        throw new \RuntimeException('La source et la destination doivent être différentes.');
    }

    return [transferResolveEnv((string) $fromName, true), $to];
}

/**
 * Stratégie de synchronisation des uploads. L'option --strategy permet un usage
 * scripté, mais l'usage normal est de répondre à la question, avec le détail des
 * conséquences affiché juste avant.
 */
function transferAskUploadsStrategy(): string
{
    $option = input()->getOption('strategy');

    if ($option !== null) {
        if (!in_array($option, ['merge', 'mirror'], true)) {
            throw new \RuntimeException('--strategy accepte « merge » ou « mirror ».');
        }

        return $option;
    }

    if (!input()->isInteractive()) {
        return 'merge';
    }

    info('Deux stratégies possibles :');
    info('   <comment>merge</comment>  fusion : ajoute et écrase les fichiers de même nom, ne supprime RIEN.');
    info('   <comment>mirror</comment> miroir : la destination devient identique à la source.');
    info('           Tout fichier présent à la destination mais absent de la source y sera SUPPRIMÉ.');

    return transferChoice('Stratégie de synchronisation des uploads ?', [
        'merge' => 'fusion (ne supprime rien)',
        'mirror' => 'miroir exact (--delete, supprime à la destination)',
    ], 'merge');
}

/*
|--------------------------------------------------------------------------
| Base de données
|--------------------------------------------------------------------------
*/

/**
 * URL publique d'un environnement. On interroge d'abord la configuration, qui
 * survit à un écrasement de base, puis la base en dernier recours.
 */
function transferSiteUrl(?Host $env): ?string
{
    $bin = transferWpBin($env);

    foreach (["$bin config get WP_HOME", "$bin option get home --skip-plugins --skip-themes"] as $command) {
        try {
            $value = trim(transferWpRun($env, $command));

            if ($value !== '' && str_contains($value, '://')) {
                return $value;
            }
        } catch (\Throwable) {
            continue;
        }
    }

    return null;
}

/**
 * Étapes de search-replace : d'abord l'URL complète (avec le protocole), puis le
 * domaine nu. La seconde passe rattrape ce que la première ne voit pas : http://,
 * les URLs protocol-relative (//domaine), celles échappées en JSON dans les blocs
 * Gutenberg (https:\/\/domaine) et les mentions du domaine en clair. L'ordre
 * compte : inverser les deux passes casserait la première.
 *
 * Attention : la passe « domaine nu » touche aussi les adresses e-mail du domaine.
 *
 * @return array<int, array{0: string, 1: string, 2: string}> [libellé, recherche, remplacement]
 */
function transferSearchReplaceSteps(string $fromUrl, string $toUrl): array
{
    $steps = [['URL complète', rtrim($fromUrl, '/'), rtrim($toUrl, '/')]];

    $fromDomain = parse_url($fromUrl, PHP_URL_HOST);
    $toDomain = parse_url($toUrl, PHP_URL_HOST);

    if ($fromDomain && $toDomain && $fromDomain !== $toDomain) {
        $steps[] = ['domaine nu', $fromDomain, $toDomain];
    }

    return $steps;
}

/**
 * Propose puis applique la réécriture d'URLs sur un environnement.
 */
function transferSearchReplace(?Host $env, ?string $fromUrl, ?string $toUrl): void
{
    if (!$fromUrl || !$toUrl || rtrim($fromUrl, '/') === rtrim($toUrl, '/')) {
        return;
    }

    $steps = transferSearchReplaceSteps($fromUrl, $toUrl);
    $bin = transferWpBin($env);
    $label = transferEnvLabel($env);
    $commands = [];

    $skipTables = trim((string) transferResolve($env, '{{transfer_search_replace_skip_tables}}'));

    if ($skipTables !== '') {
        info(sprintf('   Tables exclues : <comment>%s</comment>', $skipTables));
    }

    // --precise reste optionnel : il force le traitement PHP de TOUTES les colonnes
    // et sature la mémoire sur les grosses tables. Par défaut, WP-CLI n'emploie PHP
    // que pour les colonnes contenant du sérialisé (et corrige alors les longueurs
    // s:NN, vérifié) et passe par un REPLACE() SQL ailleurs — bien plus léger.
    $precise = input()->getOption('precise') ? ' --precise' : '';

    // stdin fermé : sur erreur fatale, WP-CLI propose de relancer la commande et
    // attend une réponse. Sans cela, la tâche resterait bloquée sur cette question.
    foreach ($steps as [, $search, $replace]) {
        $commands[] = sprintf(
            '%s search-replace %s %s --all-tables --skip-columns=guid'
                . ' --report-changed-only --skip-plugins --skip-themes%s%s < /dev/null',
            $bin,
            escapeshellarg($search),
            escapeshellarg($replace),
            $skipTables === '' ? '' : ' --skip-tables=' . escapeshellarg($skipTables),
            $precise,
        );
    }

    info(sprintf('🔗 La base de <comment>%s</comment> pointe encore vers <comment>%s</comment>.', $label, $fromUrl));

    $question = sprintf('Réécrire les URLs de « %s » vers %s maintenant (%d passes) ?', $label, $toUrl, count($steps));

    if (!transferConfirm($question, true)) {
        info(sprintf('   URLs laissées en place. À lancer sur « %s », dans cet ordre :', $label));

        // Les commandes sont résolues dans le contexte de leur environnement, sinon
        // {{bin/wp}} viserait l'hôte courant et déclencherait un appel parasite.
        $prefix = $env === null ? parse('{{transfer_local_prefix}}') : '';

        foreach ($commands as $command) {
            info('   <comment>' . $prefix . transferResolve($env, $command) . '</comment>');
        }

        if ($env !== null) {
            info(sprintf('   Depuis le projet : <comment>dep run "…" %s</comment>', $label));
        }

        return;
    }

    foreach ($steps as $index => [$label, $search, $replace]) {
        info(sprintf(
            '   Passe %d/%d — %s : <comment>%s</comment> → <comment>%s</comment>',
            $index + 1,
            count($steps),
            $label,
            $search,
            $replace,
        ));

        try {
            transferWpRun($env, $commands[$index], ['timeout' => 3600, 'real_time_output' => true]);
        } catch (\Throwable $exception) {
            warning(sprintf(
                'La passe %d a échoué : la base de « %s » est partiellement réécrite.',
                $index + 1,
                $label,
            ));
            info('   Relancer la même commande est sans risque : ce qui est déjà remplacé ne correspond plus.');
            info('   En cas de saturation mémoire, deux leviers dans le deploy.php :');
            info('     exclure la table fautive  <comment>set(\'transfer_search_replace_skip_tables\', \'*_wf*,*_gf_entry\')</comment>');
            info('     relever la limite PHP     <comment>set(\'bin/wp_local\', \'php -d memory_limit=-1 /usr/local/bin/wp\')</comment>');

            throw $exception;
        }
    }

    info('✅ URLs réécrites.');
}

/**
 * Exporte la base d'un environnement dans son répertoire de travail, gzippée.
 */
function transferDbExport(?Host $env, string $workdir): string
{
    $label = transferEnvLabel($env);
    $bin = transferWpBin($env);
    $dump = $workdir . '/db.sql';

    [$installed, $wpOutput] = transferWpIsInstalled($env);

    if (!$installed) {
        throw new \RuntimeException(sprintf(
            "WordPress est inutilisable sur « %s » : export impossible.\n%s",
            $label,
            transferWpMissingDiagnosis($env, $wpOutput),
        ));
    }

    info("🔎 Lecture de la configuration de <comment>$label</comment> (lecture seule)...");
    info('   Base : <comment>' . transferWpRun($env, "$bin config get DB_NAME") . '</comment>');
    info('   Préfixe : <comment>' . transferWpRun($env, "$bin config get table_prefix") . '</comment>');

    info("💾 Export de la base de <comment>$label</comment> (progression toutes les 5s)...");
    transferRunWatched(
        $env,
        sprintf('%s db export %s --skip-plugins --skip-themes', $bin, escapeshellarg($dump)),
        $dump,
        'dump SQL :',
    );
    info('   Dump brut : <comment>' . transferSize($env, $dump) . '</comment>');

    info('🗜️  Compression gzip...');
    transferRun($env, 'gzip -9 ' . escapeshellarg($dump), ['timeout' => 3600]);
    info('   Archive gzip : <comment>' . transferSize($env, "$dump.gz") . '</comment>');

    return $dump . '.gz';
}

/**
 * Dump de sécurité de la base de destination, CONSERVÉ hors du répertoire de
 * travail pour survivre au nettoyage.
 */
function transferDbBackup(Host $env): ?string
{
    $label = $env->getAlias();
    $bin = transferWpBin($env);

    [$installed, $wpOutput] = transferWpIsInstalled($env);

    if (!$installed) {
        warning("WordPress est inutilisable sur « $label » : pas de sauvegarde préalable.");
        writeln(transferWpMissingDiagnosis($env, $wpOutput));

        return null;
    }

    $dir = transferResolvePath($env, '{{transfer_backup_dir}}');
    $sql = sprintf('%s/%s-%s.sql', $dir, $label, date('Ymd-His'));

    transferRun($env, 'mkdir -p ' . escapeshellarg($dir));
    info("🛟 Sauvegarde de la base de <comment>$label</comment> avant écrasement...");
    transferRunWatched(
        $env,
        sprintf('%s db export %s --skip-plugins --skip-themes', $bin, escapeshellarg($sql)),
        $sql,
        'sauvegarde :',
    );
    transferRun($env, 'gzip -9 ' . escapeshellarg($sql), ['timeout' => 3600]);

    info(sprintf('   Conservée : <comment>%s.gz</comment> (%s)', $sql, transferSize($env, "$sql.gz")));

    return $sql . '.gz';
}

/**
 * Vide la base d'un environnement puis y importe un dump gzippé.
 *
 * On passe par un pipe gunzip → wp db import avec `set -o pipefail`, sans quoi un
 * gunzip en échec laisserait WP-CLI annoncer un import réussi sur un flux vide.
 *
 * `--skip-plugins --skip-themes` comme partout ailleurs, et pas seulement par
 * principe : si WP-CLI doit charger WordPress ici — ce qui arrive quand il ne
 * connaît pas la commande `db` et la cherche du côté des extensions — un thème qui
 * démarre une session échoue en Fatal error, parce que la moindre notice émise par
 * le projet a déjà rendu headers_sent() vrai. Sans le thème, ce code ne tourne pas.
 */
function transferDbImport(?Host $env, string $dumpGz): void
{
    $bin = transferWpBin($env);
    $label = transferEnvLabel($env);

    // Vérifié AVANT le reset : sur une base vide, un WP-CLI amputé de ses commandes
    // rend la main en code 0 sans rien faire, et la tâche enchaînerait sur l'import.
    if (!transferWpHasCommand($env, 'db')) {
        throw new \RuntimeException(sprintf(
            "La commande « wp db » n'existe pas sur « %s » : rien n'a été modifié.\n"
            . "   Binaire : %s\n"
            . "   WP-CLI est installé sans ses paquets de commandes : « wp-cli/wp-cli » est le\n"
            . "   framework seul. Installer « wp-cli/wp-cli-bundle », ou faire pointer %s vers\n"
            . '   un phar complet.',
            $label,
            transferResolve($env, $bin),
            $env === null ? '{{bin/wp_local}}' : '{{bin/wp}}',
        ));
    }

    info(sprintf('🗑️  Suppression des tables de <comment>%s</comment>...', $label));
    transferWpRun($env, "$bin db reset --yes --skip-plugins --skip-themes", ['timeout' => 600]);

    info('📥 Import du dump...');
    transferWpRun(
        $env,
        sprintf(
            'set -o pipefail; gunzip -c %s | %s db import - --skip-plugins --skip-themes',
            escapeshellarg($dumpGz),
            $bin,
        ),
        ['timeout' => 7200, 'real_time_output' => true],
    );

    info('✅ Base à jour.');
}

/*
|--------------------------------------------------------------------------
| Uploads
|--------------------------------------------------------------------------
*/

/**
 * Fichiers composant le favicon du site (option `site_icon`), en chemins relatifs
 * au dossier uploads.
 *
 * Le navigateur ne réclame pas l'original mais ses déclinaisons — 32x32 pour
 * l'onglet, 180 pour Apple, 192 pour Android. La liste est donc lue dans
 * `_wp_attachment_metadata` plutôt que devinée par un motif « nom* », qui
 * ramasserait les médias voisins sans garantir les tailles réellement servies.
 *
 * @return string[] vide si aucun favicon n'est défini
 */
function transferFaviconFiles(?Host $env): array
{
    $bin = transferWpBin($env);

    // `|| true` : une option ou une méta absente sort en code 1, ce qui n'est pas
    // une erreur ici — c'est une réponse.
    $read = static fn(string $command): string => trim(transferWpRun(
        $env,
        sprintf('%s %s --skip-plugins --skip-themes 2>/dev/null || true', $bin, $command),
    ));

    $id = $read('option get site_icon');

    if (!ctype_digit($id) || (int) $id === 0) {
        return [];
    }

    $file = $read("post meta get $id _wp_attached_file");

    if ($file === '') {
        return [];
    }

    $files = [$file];
    $directory = dirname($file);
    $prefix = $directory === '.' ? '' : $directory . '/';
    $meta = json_decode($read("post meta get $id _wp_attachment_metadata --format=json"), true);

    foreach ($meta['sizes'] ?? [] as $size) {
        if (is_array($size) && is_string($size['file'] ?? null) && $size['file'] !== '') {
            $files[] = $prefix . $size['file'];
        }
    }

    return array_values(array_unique($files));
}

/**
 * Récupère le seul favicon plutôt que la totalité des uploads, qui peuvent peser
 * plusieurs gigaoctets pour quelques kilo-octets réellement utiles à l'affichage
 * d'un onglet.
 */
function transferPullFavicon(Host $from, string $remoteUploads, string $uploads): void
{
    // La base LOCALE d'abord : c'est elle qui sert le site local, donc c'est son
    // site_icon qui désigne le fichier que le navigateur va réclamer. Repli sur la
    // distante pour que l'option reste utilisable avant tout db:pull.
    $source = 'locale';
    $files = transferFaviconFiles(null);

    if ($files === []) {
        $source = sprintf('de « %s »', $from->getAlias());
        $files = transferFaviconFiles($from);
    }

    if ($files === []) {
        warning('Aucun favicon défini : l\'option « site_icon » est vide des deux côtés.');
        info('   Il se définit dans Réglages → Général → Icône du site.');

        return;
    }

    info(sprintf('🎨 Favicon trouvé dans la base <comment>%s</comment> :', $source));

    foreach ($files as $file) {
        info("   <comment>$file</comment>");
    }

    if (!transferConfirm(sprintf('Écrire ces %d fichier(s) dans ./%s ?', count($files), $uploads), true)) {
        info('   Rien n\'a été écrit.');

        return;
    }

    // Toutes les déclinaisons partagent le dossier de l'original : un seul rsync
    // suffit, restreint à ces fichiers par --include suivi d'un --exclude global.
    $directory = dirname($files[0]);
    $suffix = $directory === '.' ? '' : $directory . '/';
    $options = array_map(static fn(string $file): string => '--include=' . basename($file), $files);
    $options[] = '--exclude=*';

    $localUploads = rtrim(transferProjectRoot() . '/' . $uploads, '/');
    $localDirectory = $localUploads . '/' . $suffix;
    runLocally('mkdir -p ' . escapeshellarg($localDirectory));
    transferRsync($from, rtrim($remoteUploads, '/') . '/' . $suffix, null, $localDirectory, $options);

    // rsync ne considère pas comme une erreur un filtre qui ne retient rien : sans
    // ce contrôle, la tâche annoncerait un succès en n'ayant rien rapatrié.
    $written = count(array_filter(
        $files,
        static fn(string $file): bool => is_file($localUploads . '/' . $file),
    ));

    if ($written === 0) {
        warning(sprintf(
            'Aucun fichier récupéré : le favicon référencé en base est introuvable sur « %s ».',
            $from->getAlias(),
        ));
        info('   La cause habituelle est une base locale importée depuis un AUTRE environnement');
        info('   que l\'hôte ciblé : un identifiant de média n\'y désigne pas le même fichier.');

        return;
    }

    info(sprintf(
        '✅ Favicon récupéré : <comment>%d</comment> fichier(s) dans <comment>./%s/%s</comment>',
        $written,
        trim($uploads, '/'),
        $suffix,
    ));
}

/**
 * Chemin réel du dossier uploads d'un environnement.
 *
 * Côté distant il y a deux symlinks à traverser : `current` vers la release, et
 * uploads (shared_dir) vers shared/. `find` et `du` ne descendent pas dans un
 * symlink passé en point de départ et renverraient 0 — d'où la résolution
 * explicite, avec repli sur `cd && pwd -P` si `readlink -f` n'existe pas.
 */
function transferUploadsPath(?Host $env): string
{
    $path = $env === null
        ? transferProjectRoot() . '/' . get('uploads_path')
        : transferResolvePath($env, '{{current_path}}/{{uploads_path}}');

    if (!transferTest($env, sprintf('[ -d %s ]', escapeshellarg($path)))) {
        throw new \RuntimeException(sprintf('Dossier uploads introuvable sur « %s » : %s', transferEnvLabel($env), $path));
    }

    $escaped = escapeshellarg($path);
    $real = trim(transferRun($env, sprintf('readlink -f %s 2>/dev/null || (cd %s && pwd -P)', $escaped, $escaped)));

    return $real !== '' ? $real : $path;
}

/**
 * Amène un fichier de la source vers le répertoire de travail de la destination et
 * retourne son chemin là-bas. Aucun transfert quand les deux environnements
 * partagent le serveur ; passage par le local entre deux serveurs distincts.
 */
function transferFile(?Host $from, string $path, ?Host $to, string $basename): string
{
    if (transferSameServer($from, $to)) {
        info('   Même serveur : aucun transfert réseau nécessaire.');

        return $path;
    }

    $destination = transferWorkdir($to) . '/' . $basename;

    if ($from === null || $to === null) {
        info(sprintf('🚚 Transfert <comment>%s</comment> → <comment>%s</comment>...', transferEnvLabel($from), transferEnvLabel($to)));
        transferRsync($from, $path, $to, $destination);

        return $destination;
    }

    $localPath = transferWorkdir(null) . '/' . $basename;
    info(sprintf('🚚 Serveurs distincts : <comment>%s</comment> → local...', transferEnvLabel($from)));
    transferRsync($from, $path, null, $localPath);
    info(sprintf('🚚 local → <comment>%s</comment>...', transferEnvLabel($to)));
    transferRsync(null, $localPath, $to, $destination);

    return $destination;
}

/*
|--------------------------------------------------------------------------
| Tâches
|--------------------------------------------------------------------------
*/

desc('Exporte la base distante, la télécharge en .sql.gz à la racine du projet, puis propose l\'import local');
task('db:pull', static function (): void {
    transferResetState();
    $from = currentHost();
    $label = $from->getAlias();

    info("🌍 Source : <comment>$label</comment> — {{deploy_path}}");

    $remoteUrl = transferSiteUrl($from);
    $dumpGz = transferDbExport($from, transferWorkdir($from));

    $localName = sprintf('db-%s-%s.sql.gz', $label, date('Ymd-His'));
    $localPath = transferProjectRoot() . '/' . $localName;

    info("⬇️  Téléchargement vers <comment>./$localName</comment>...");
    transferRsync($from, $dumpGz, null, $localPath);

    // Le serveur est libéré tout de suite : plus rien n'en dépend à partir d'ici.
    invoke('transfer:cleanup');

    info(sprintf('✅ Dump récupéré : <comment>./%s</comment> (%s)', $localName, transferSize(null, $localPath)));

    $manual = sprintf('gunzip -c %s | {{transfer_local_prefix}}{{bin/wp_local}} db import -', $localName);

    if (!testLocally('command -v {{bin/wp_local}} > /dev/null 2>&1')) {
        warning('WP-CLI est introuvable en local : import automatique impossible.');
        info("   Import manuel : <comment>$manual</comment>");

        return;
    }

    if (!transferConfirm('Vider la base de données LOCALE et y importer ce dump maintenant ?', true)) {
        info('   Dump conservé, la base locale n\'a pas été touchée.');
        info("   Import manuel : <comment>$manual</comment>");

        return;
    }

    transferDbImport(null, $localPath);
    transferSearchReplace(null, $remoteUrl, transferSiteUrl(null));
});

desc('Archive les uploads distants, les télécharge, puis propose l\'extraction dans le dossier uploads local (--favicon-only pour ne récupérer que le favicon)');
task('uploads:pull', static function (): void {
    transferResetState();
    $from = currentHost();
    $label = $from->getAlias();
    $uploads = get('uploads_path');

    info("🌍 Source : <comment>$label</comment> — {{deploy_path}}");

    $remoteUploads = transferUploadsPath($from);
    info("   Uploads distants : <comment>$remoteUploads</comment>");

    // Court-circuit avant toute analyse : inutile de parcourir des milliers de
    // fichiers pour n'en récupérer qu'une poignée.
    if (input()->getOption('favicon-only')) {
        transferPullFavicon($from, $remoteUploads, $uploads);

        return;
    }

    info('🔎 Analyse du dossier uploads distant (lecture seule)...');
    $fileCount = trim(transferRun($from, sprintf('find %s -type f | wc -l', escapeshellarg($remoteUploads))));
    // Mesuré une seule fois : `du -hs` parcourt tout l'arbre, ce n'est pas gratuit.
    $uploadsSize = transferSize($from, $remoteUploads, true);
    info(sprintf('   <comment>%s</comment> fichiers, <comment>%s</comment> à archiver.', $fileCount, $uploadsSize));

    // Demandé AVANT transferWorkdir(), qui crée déjà un répertoire sur le serveur :
    // un refus ne doit rien y laisser. On annonce le volume, le disque du serveur
    // n'ayant aucune raison d'être saturé à l'insu de qui lance la tâche.
    info(sprintf(
        '⚠️  L\'archive sera écrite sur le serveur, dans <comment>%s</comment>,',
        transferResolvePath($from, '{{transfer_tmp_dir}}'),
    ));
    info(sprintf('   où elle occupera jusqu\'à <comment>%s</comment> le temps du transfert.', $uploadsSize));

    if (!transferConfirmDiskUsage(sprintf('Créer l\'archive sur « %s » ?', $label), $uploadsSize)) {
        info('Abandon : rien n\'a été écrit sur le serveur.');
        invoke('transfer:cleanup');

        return;
    }

    $archive = transferWorkdir($from) . '/uploads.tar.gz';
    $localName = sprintf('uploads-%s-%s.tar.gz', $label, date('Ymd-His'));
    $localPath = transferProjectRoot() . '/' . $localName;

    info('🗜️  Création de l\'archive sur le serveur (progression toutes les 5s)...');
    transferRunWatched(
        $from,
        sprintf('tar -czf %s -C %s .', escapeshellarg($archive), escapeshellarg($remoteUploads)),
        $archive,
        'archive :',
    );
    info('   Archive créée : <comment>' . transferSize($from, $archive) . '</comment>');

    info("⬇️  Téléchargement vers <comment>./$localName</comment>...");
    transferRsync($from, $archive, null, $localPath);

    invoke('transfer:cleanup');

    info(sprintf('✅ Archive récupérée : <comment>./%s</comment> (%s)', $localName, transferSize(null, $localPath)));

    $localUploads = transferProjectRoot() . '/' . $uploads;
    $question = sprintf(
        'Extraire l\'archive dans ./%s maintenant (fusion, les fichiers de même nom seront écrasés) ?',
        $uploads,
    );

    if (!transferConfirm($question, true)) {
        info("   Archive conservée, ./$uploads n'a pas été touché.");
        info(sprintf(
            '   Extraction manuelle : <comment>mkdir -p %s && tar -xzf %s -C %s</comment>',
            $uploads,
            $localName,
            $uploads,
        ));

        return;
    }

    info("📦 Extraction dans <comment>./$uploads</comment>...");
    runLocally('mkdir -p ' . escapeshellarg($localUploads));
    runLocally(sprintf('tar -xzf %s -C %s', escapeshellarg($localPath), escapeshellarg($localUploads)), timeout: 3600);

    $extracted = trim(runLocally(sprintf('find %s -type f | wc -l', escapeshellarg($localUploads))));
    info(sprintf('   <comment>%s</comment> fichiers présents en local après extraction.', $extracted));

    info('🧹 Suppression de l\'archive locale...');
    runLocally('rm -f ' . escapeshellarg($localPath));

    info("✅ Uploads récupérés dans <comment>./$uploads</comment>");
});

desc('Copie la base d\'un environnement vers un autre : dep db:push --from=production --to=staging (le sélecteur d\'hôte fait office de destination si --to est omis)');
task('db:push', static function (): void {
    transferResetState();
    [$from, $to] = transferSelectEnvs('la base de données');

    info(sprintf(
        '📋 Base de données : <comment>%s</comment> → <comment>%s</comment>%s',
        transferEnvLabel($from),
        $to->getAlias(),
        transferSameServer($from, $to) ? ' (même serveur)' : '',
    ));

    // Lues avant tout écrasement : l'URL de destination sert au search-replace.
    $fromUrl = transferSiteUrl($from);
    $toUrl = transferSiteUrl($to);

    if ($fromUrl && $toUrl) {
        info("   URLs : <comment>$fromUrl</comment> → <comment>$toUrl</comment>");
    }

    if (!transferConfirmDestination($to)) {
        info('Abandon, aucune modification.');

        return;
    }

    $dumpGz = transferDbExport($from, transferWorkdir($from));
    transferDbBackup($to);
    transferDbImport($to, transferFile($from, $dumpGz, $to, 'db.sql.gz'));

    invoke('transfer:cleanup');

    // Exécuté SUR la destination : sans ça l'environnement receveur servirait les
    // URLs de la source.
    transferSearchReplace($to, $fromUrl, $toUrl);
})->once();

desc('Copie les uploads d\'un environnement vers un autre : dep uploads:push --from=production --to=staging (le sélecteur d\'hôte fait office de destination si --to est omis)');
task('uploads:push', static function (): void {
    transferResetState();
    [$from, $to] = transferSelectEnvs('les uploads');

    $sourcePath = transferUploadsPath($from);
    $targetPath = transferUploadsPath($to);

    info(sprintf(
        '📋 Uploads : <comment>%s</comment> → <comment>%s</comment>%s',
        transferEnvLabel($from),
        $to->getAlias(),
        transferSameServer($from, $to) ? ' (même serveur)' : '',
    ));

    info('🔎 Analyse (lecture seule)...');
    $sourceCount = trim(transferRun($from, sprintf('find %s -type f | wc -l', escapeshellarg($sourcePath))));
    $targetCount = trim(transferRun($to, sprintf('find %s -type f | wc -l', escapeshellarg($targetPath))));
    // Mesurée une seule fois : réutilisée pour annoncer le volume du staging local.
    $sourceSize = transferSize($from, $sourcePath, true);
    info(sprintf(
        '   Source      <comment>%s</comment> : %s fichiers, %s',
        $sourcePath,
        $sourceCount,
        $sourceSize,
    ));
    info(sprintf(
        '   Destination <comment>%s</comment> : %s fichiers, %s',
        $targetPath,
        $targetCount,
        transferSize($to, $targetPath, true),
    ));

    $strategy = transferAskUploadsStrategy();

    info(sprintf(
        '   Stratégie retenue : <comment>%s</comment>',
        $strategy === 'mirror'
            ? 'miroir exact — les fichiers absents de la source seront SUPPRIMÉS à la destination'
            : 'fusion — rien ne sera supprimé à la destination',
    ));

    $options = $strategy === 'mirror' ? ['--delete'] : [];

    if (input()->getOption('checksum')) {
        $options[] = '--checksum';
        info('   Comparaison sur le <comment>contenu</comment> des fichiers (--checksum) : plus lent, mais insensible aux dates.');
    }

    // Slash final : on synchronise le CONTENU du dossier, pas le dossier lui-même.
    $source = rtrim($sourcePath, '/') . '/';
    $target = rtrim($targetPath, '/') . '/';

    info('🔍 Simulation avant écriture (aucune modification, progression ci-dessous)...');
    $preview = transferRsyncPreview($from, $source, $to, $target, $options, (int) $sourceCount);

    if ($preview['transfer'] === 0 && $preview['delete'] === 0) {
        info('✅ Rien à faire : la destination est déjà conforme à la source.');

        if (!input()->getOption('checksum')) {
            info('   La comparaison porte sur la taille et la date de modification. Pour comparer');
            info('   sur le contenu des fichiers : relancer avec <comment>--checksum</comment>.');
        }

        invoke('transfer:cleanup');

        return;
    }

    if (!transferConfirmDestination($to)) {
        info('Abandon, aucune modification.');

        return;
    }

    if ($from !== null && !transferSameServer($from, $to)) {
        // rsync n'accepte qu'une extrémité distante : entre deux serveurs, le transit
        // passe par la machine locale. C'est donc le disque de qui lance la tâche qui
        // se remplit, ce qui n'a rien d'évident — d'où la confirmation, demandée avant
        // transferWorkdir() pour qu'un refus ne laisse aucun répertoire derrière lui.
        info(sprintf(
            '⚠️  Serveurs distincts : le transit passe par cette machine, dans <comment>%s</comment>,',
            get('transfer_local_tmp_dir'),
        ));
        info(sprintf('   qui recevra jusqu\'à <comment>%s</comment> le temps du transfert.', $sourceSize));

        if (!transferConfirmDiskUsage('Copier les uploads en local pour le transit ?', $sourceSize)) {
            info('Abandon : aucune modification, ni en local ni à la destination.');
            invoke('transfer:cleanup');

            return;
        }

        $staging = transferWorkdir(null) . '/uploads/';
        info(sprintf('🚚 Serveurs distincts : <comment>%s</comment> → local...', transferEnvLabel($from)));
        runLocally('mkdir -p ' . escapeshellarg($staging));
        transferRsync($from, $source, null, $staging, ['--delete']);
        info(sprintf('🚚 local → <comment>%s</comment>...', $to->getAlias()));
        transferRsync(null, $staging, $to, $target, $options);
    } else {
        info('🚚 Synchronisation...');
        transferRsync($from, $source, $to, $target, $options);
    }

    invoke('transfer:cleanup');

    info(sprintf(
        '✅ Uploads synchronisés : <comment>%s</comment> fichiers sur « %s » (%s)',
        trim(transferRun($to, sprintf('find %s -type f | wc -l', escapeshellarg($targetPath)))),
        $to->getAlias(),
        transferSize($to, $targetPath, true),
    ));
})->once();

// En cas d'échec, on supprime les répertoires temporaires de tous les environnements.
fail('db:pull', 'transfer:cleanup');
fail('uploads:pull', 'transfer:cleanup');
fail('db:push', 'transfer:cleanup');
fail('uploads:push', 'transfer:cleanup');
