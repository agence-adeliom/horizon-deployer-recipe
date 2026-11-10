<?php
/*
 * Amorce des tests unitaires : vrai Deployer (7 ou 8, celui de vendor/), recipe/common.php puis le
 * recipe entier, chargés comme le fait un deploy.php. Seuls l'exécution des commandes (client SSH et
 * exécuteur local de Deployer), l'entrée et la sortie de la console sont remplacés :
 *
 *  - Shell enregistre chaque commande que le recipe enverrait, avec son environnement (alias de
 *    l'hôte, « local » pour runLocally()), son répertoire et ses options, après que Deployer l'a
 *    résolue ({{placeholders}}, within()). Elle répond selon des règles posées par le test, ou exécute
 *    la commande pour de vrai avec bash quand le test le demande (Shell::$reel), ce qui n'est fait que
 *    sur des dossiers temporaires ;
 *  - entree() fabrique l'entrée de la console (options, mode interactif, réponses aux questions) ;
 *  - la sortie est un BufferedOutput lu par sortie().
 *
 * Compatible PHP 7.4 : ni paramètres nommés, ni match, ni types d'union.
 */

namespace Deployer;

use Deployer\Host\Host;
use Deployer\Host\Localhost;
use Deployer\Task\Context;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

const RACINE_DEPOT = __DIR__ . '/../..';

$vendor = getenv('HORIZON_TESTS_VENDOR') ?: RACINE_DEPOT . '/vendor';

// Deployer 7 publie ses dépendances dans son propre vendor/, sans autoload Composer déclaré.
$autoloads = [$vendor . '/deployer/deployer/vendor/autoload.php', $vendor . '/autoload.php'];
$autoload = null;

foreach ($autoloads as $candidat) {
    if (is_file($candidat)) {
        $autoload = $candidat;
        break;
    }
}

if ($autoload === null) {
    fwrite(STDERR, "Deployer introuvable dans $vendor : lancer « composer install » à la racine du dépôt.\n");
    exit(1);
}

require $autoload;

// Les dépréciations viennent de Deployer ou de Symfony selon la version de PHP : hors sujet ici.
// Tout autre avertissement ou notice devient une exception, pour qu'un défaut du recipe
// (index absent, conversion douteuse) fasse échouer le test au lieu de passer inaperçu.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);
set_error_handler(static function (int $niveau, string $message, string $fichier, int $ligne): bool {
    if (!(error_reporting() & $niveau)) {
        return false;
    }

    throw new \ErrorException($message, 0, $niveau, $fichier, $ligne);
});

/**
 * Commandes envoyées par le recipe, et leurs réponses.
 */
final class Shell
{
    /** @var array<int, array{env: string, commande: string, cwd: ?string, timeout: ?int, temps_reel: bool}> */
    public static $appels = [];

    /** @var array<int, array{motif: string, reponse: mixed, env: ?string}> */
    public static $regles = [];

    /** Exécute réellement les commandes sans règle (dossiers temporaires seulement). */
    public static $reel = false;

    public static function reinitialiser(): void
    {
        self::$appels = [];
        self::$regles = [];
        self::$reel = false;
    }

    /**
     * Réponse à toute commande dont le texte correspond à $motif (expression régulière). La première
     * règle qui correspond l'emporte. $reponse : chaîne, \Throwable (levée), ou fonction recevant
     * l'appel et rendant une chaîne.
     */
    public static function quand(string $motif, $reponse, ?string $env = null): void
    {
        self::$regles[] = ['motif' => $motif, 'reponse' => $reponse, 'env' => $env];
    }

    /**
     * @param array|object $parametres options de Deployer 7 (tableau) ou RunParams de Deployer 8
     */
    public static function executer(Host $hote, string $commande, $parametres): string
    {
        $env = $hote instanceof Localhost && !Deployer::get()->hosts->has($hote->getAlias())
            ? 'local'
            : (string) $hote->getAlias();

        $cwd = null;

        if (is_object($parametres)) {
            // Deployer 8 rend '' après un within() : comme sous Deployer 7, pas de cd.
            $cwd = $parametres->cwd !== '' ? $parametres->cwd : null;
            $timeout = $parametres->timeout;
            $tempsReel = (bool) $parametres->forceOutput;
        } else {
            $timeout = $parametres['timeout'] ?? null;
            $tempsReel = (bool) ($parametres['real_time_output'] ?? false);

            // Deployer 7 préfixe la commande du `cd` de within() : on le retire pour comparer.
            if (preg_match('/^cd (\S+) && \((.*)\)$/s', $commande, $m) === 1) {
                $cwd = $m[1];
                $commande = $m[2];
            }
        }

        $appel = [
            'env' => $env,
            'commande' => $commande,
            'cwd' => $cwd,
            'timeout' => $timeout,
            'temps_reel' => $tempsReel,
        ];
        self::$appels[] = $appel;

        foreach (self::$regles as $regle) {
            if (($regle['env'] === null || $regle['env'] === $env) && preg_match($regle['motif'], $commande) === 1) {
                $reponse = $regle['reponse'];

                if ($reponse instanceof \Throwable) {
                    throw $reponse;
                }

                return is_callable($reponse) && !is_string($reponse) ? (string) $reponse($appel) : (string) $reponse;
            }
        }

        if (self::$reel) {
            return self::bash($commande, $cwd);
        }

        return '';
    }

    /**
     * Exécute une commande avec bash, comme le ferait Deployer ; une sortie non nulle lève.
     */
    public static function bash(string $commande, ?string $cwd = null): string
    {
        $tubes = [];
        $processus = proc_open(['bash', '-c', $commande], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, $cwd);

        if (!is_resource($processus)) {
            throw new \RuntimeException('bash introuvable');
        }

        $sortie = (string) stream_get_contents($tubes[1]);
        $erreurs = (string) stream_get_contents($tubes[2]);
        fclose($tubes[1]);
        fclose($tubes[2]);
        $code = proc_close($processus);

        if ($code !== 0) {
            throw new \RuntimeException("Commande en échec (code $code) : $commande\n$erreurs");
        }

        return $sortie;
    }

    /** @return string[] commandes envoyées, éventuellement filtrées par environnement */
    public static function commandes(?string $env = null): array
    {
        $commandes = [];

        foreach (self::$appels as $appel) {
            if ($env === null || $appel['env'] === $env) {
                $commandes[] = $appel['commande'];
            }
        }

        return $commandes;
    }

    /** Premier appel dont la commande correspond au motif, ou null. */
    public static function appel(string $motif): ?array
    {
        foreach (self::$appels as $appel) {
            if (preg_match($motif, $appel['commande']) === 1) {
                return $appel;
            }
        }

        return null;
    }
}

/**
 * Remplace le client SSH et l'exécuteur local de Deployer : run() et runLocally() aboutissent ici
 * avec la commande résolue. Signature volontairement non typée (Deployer 7 passe un tableau
 * d'options, Deployer 8 un RunParams).
 */
final class ExecuteurFactice
{
    public function run($hote, $commande, $parametres = [])
    {
        return Shell::executer($hote, $commande, $parametres);
    }
}

$deployer = new Deployer(new Application());
$executeur = new ExecuteurFactice();
$deployer['sshClient'] = static function () use ($executeur) {
    return $executeur;
};
$deployer['processRunner'] = static function () use ($executeur) {
    return $executeur;
};

const SORTIE = 'horizon_tests_sortie';
$GLOBALS[SORTIE] = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
$deployer['output'] = static function () {
    return $GLOBALS[SORTIE];
};

/**
 * Entrée de la console pour le test en cours : options (« to » => « staging », « precise » => true),
 * mode interactif, et réponses tapées aux questions dans l'ordre. Sans réponse restante, une question
 * rencontre la fin de l'entrée et Symfony retombe sur son défaut, comme sous `dep` sans TTY.
 */
function entree(array $options = [], bool $interactif = false, array $reponses = []): void
{
    $parametres = [];

    foreach ($options as $nom => $valeur) {
        $parametres['--' . $nom] = $valeur;
    }

    // Options du recipe, plus celles de la commande `dep` que Deployer lit lui-même (--profile).
    $definition = new InputDefinition();
    $definition->addOptions(Deployer::get()->inputDefinition->getOptions());
    $definition->addOption(new InputOption('profile', null, InputOption::VALUE_REQUIRED));

    $input = new ArrayInput($parametres, $definition);
    $input->setInteractive($interactif);

    $flux = fopen('php://memory', 'r+');
    fwrite($flux, $reponses === [] ? '' : implode("\n", $reponses) . "\n");
    rewind($flux);
    $input->setStream($flux);

    $deployer = Deployer::get();
    unset($deployer['input']);
    $deployer['input'] = static function () use ($input) {
        return $input;
    };
}

/** Sortie produite depuis le dernier appel (puis vidée). */
function sortie(): string
{
    return (string) preg_replace('/\e\[[0-9;]*m/', '', $GLOBALS[SORTIE]->fetch());
}

function versionDeployer(): int
{
    return method_exists(Host::class, 'connectionOptionsArray') ? 7 : 8;
}

entree();

// Un deploy.php type : recipe/common.php de Deployer (tâches deploy, deploy:success, deploy:symlink…),
// puis ce recipe, ou une copie mutée désignée par HORIZON_TESTS_RECIPE.
$racineDeployer = is_dir($vendor . '/deployer/deployer/recipe') ? $vendor . '/deployer/deployer' : null;

if ($racineDeployer === null) {
    fwrite(STDERR, "recipe/common.php de Deployer introuvable dans $vendor.\n");
    exit(1);
}

define('Deployer\\AUTOLOAD_TESTS', $autoload);
define('Deployer\\RECIPE_TESTE', getenv('HORIZON_TESTS_RECIPE') ?: RACINE_DEPOT . '/recipe/horizon-transfer.php');

require $racineDeployer . '/recipe/common.php';
require RECIPE_TESTE;

/*
 * Hôtes, contexte et dossiers temporaires.
 */

/**
 * Déclare un hôte distant au nom unique (les caches du recipe sont indexés par alias). $alias est
 * suffixé d'un compteur s'il est déjà pris.
 */
function hote(string $alias, array $config = []): Host
{
    static $compteur = 0;

    $hotes = Deployer::get()->hosts;
    $nom = $alias;

    while ($hotes->has($nom)) {
        $nom = $alias . '-' . (++$compteur);
    }

    $h = host($nom);
    $h->set('hostname', $config['hostname'] ?? $nom . '.exemple.test');
    $h->set('remote_user', $config['remote_user'] ?? 'deploy');
    $h->set('deploy_path', $config['deploy_path'] ?? '/srv/' . $nom);
    hoteDefauts($h, $config);

    return $h;
}

/**
 * Étiquette sans couleur et binaires WP-CLI fixés à « wp » : les closures {{bin/wp}} et
 * {{bin/wp_local}} du recipe lanceraient sinon leurs propres commandes de détection. Avec
 * « _brut » => true, ces closures sont gardées (tests de la détection elle-même).
 */
function hoteDefauts(Host $h, array $config): void
{
    $h->set('tag', $h->getAlias());

    if (empty($config['_brut'])) {
        $h->set('bin/wp', 'wp');
        $h->set('bin/wp_local', 'wp');
    }

    foreach ($config as $cle => $valeur) {
        if (!in_array($cle, ['hostname', 'remote_user', 'deploy_path', '_brut'], true)) {
            $h->set($cle, $valeur);
        }
    }
}

/** Hôte déclaré via localhost() : exécution locale pour rsync. */
function hoteLocal(string $alias, array $config = []): Host
{
    $h = localhost($alias);
    $h->set('deploy_path', $config['deploy_path'] ?? '/srv/' . $alias);
    hoteDefauts($h, $config);

    return $h;
}

/** Exécute $fn dans le contexte de $hote, comme une tâche lancée sur lui. */
function dans(Host $hote, callable $fn)
{
    $resultat = null;
    Context::push(new Context($hote));

    try {
        $resultat = $fn();
    } finally {
        Context::pop();
    }

    return $resultat;
}

/** Lance le corps d'une tâche du recipe sur $hote. */
function tache(string $nom, Host $hote): void
{
    Deployer::get()->tasks->get($nom)->run(new Context($hote));
}

/** Dossier temporaire neuf, supprimé en fin de suite. */
function dossierTemporaire(): string
{
    $dossier = sys_get_temp_dir() . '/horizon-tests-' . getmypid() . '-' . substr(md5(uniqid('', true)), 0, 8);
    mkdir($dossier, 0777, true);
    $GLOBALS['horizon_tests_dossiers'][] = $dossier;

    return realpath($dossier);
}

register_shutdown_function(static function (): void {
    foreach ($GLOBALS['horizon_tests_dossiers'] ?? [] as $dossier) {
        if (is_dir($dossier)) {
            Shell::bash('rm -rf ' . escapeshellarg($dossier));
        }
    }
});

/*
 * Assertions et enregistrement des tests.
 */

final class Suite
{
    /** @var array<int, array{fichier: string, libelle: string, fn: callable, bug: ?string}> */
    public static $tests = [];
    public static $fichier = '';
    public static $verifications = 0;
    public static $echecsTest = [];
    /** Hôte du contexte par défaut d'un test (« courant »). */
    public static $courant;
}

/** Déclare un test. Il tourne dans le contexte d'un hôte « courant » neuf. */
function cas(string $libelle, callable $fn): void
{
    Suite::$tests[] = ['fichier' => Suite::$fichier, 'libelle' => $libelle, 'fn' => $fn, 'bug' => null];
}

/**
 * Test qui expose un bug connu du recipe : son échec est attendu et signalé sans faire échouer la
 * suite ; s'il passe, le bug est corrigé et la suite échoue pour qu'on retire le marquage.
 * $condition permet de ne marquer que la configuration concernée (ex. Deployer 8 seulement).
 */
function casBug(string $libelle, string $bug, callable $fn, bool $condition = true): void
{
    Suite::$tests[] = ['fichier' => Suite::$fichier, 'libelle' => $libelle, 'fn' => $fn, 'bug' => $condition ? $bug : null];
}

function verifie(string $libelle, $obtenu, $attendu): void
{
    Suite::$verifications++;

    if ($obtenu !== $attendu) {
        Suite::$echecsTest[] = "$libelle\n      obtenu  : " . var_export($obtenu, true) . "\n      attendu : " . var_export($attendu, true);
    }
}

function vrai(string $libelle, bool $condition): void
{
    verifie($libelle, $condition, true);
}

function contient(string $libelle, string $texte, string $fragment): void
{
    Suite::$verifications++;

    if (strpos($texte, $fragment) === false) {
        Suite::$echecsTest[] = "$libelle\n      « $fragment » absent de :\n      " . str_replace("\n", "\n      ", $texte);
    }
}

function absent(string $libelle, string $texte, string $fragment): void
{
    Suite::$verifications++;

    if (strpos($texte, $fragment) !== false) {
        Suite::$echecsTest[] = "$libelle\n      « $fragment » présent dans :\n      " . str_replace("\n", "\n      ", $texte);
    }
}

/**
 * $fn doit lever une exception de la classe $classe dont le message contient $fragment.
 *
 * @return ?\Throwable l'exception levée
 */
function leve(string $libelle, callable $fn, string $classe = \Throwable::class, string $fragment = ''): ?\Throwable
{
    Suite::$verifications++;

    try {
        $fn();
    } catch (\Throwable $e) {
        if (!$e instanceof $classe || ($fragment !== '' && strpos($e->getMessage(), $fragment) === false)) {
            Suite::$echecsTest[] = "$libelle\n      exception inattendue " . get_class($e) . ' : ' . $e->getMessage();
        }

        return $e;
    }

    Suite::$echecsTest[] = "$libelle\n      aucune exception (attendu : $classe" . ($fragment !== '' ? " « $fragment »" : '') . ')';

    return null;
}

/** Aucune commande ne doit correspondre au motif. */
function aucuneCommande(string $libelle, string $motif): void
{
    $trouve = Shell::appel($motif);
    verifie($libelle, $trouve === null ? null : $trouve['commande'], null);
}

/** Au moins une commande doit correspondre au motif ; renvoie l'appel. */
function uneCommande(string $libelle, string $motif): ?array
{
    $trouve = Shell::appel($motif);
    Suite::$verifications++;

    if ($trouve === null) {
        Suite::$echecsTest[] = "$libelle\n      aucune commande ne correspond à $motif parmi :\n      - "
            . implode("\n      - ", Shell::commandes());
    }

    return $trouve;
}

/** Une commande est-elle disponible localement (rsync 3, git…) ? */
function disponible(string $commande): bool
{
    try {
        Shell::bash($commande . ' >/dev/null 2>&1');

        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

/** Options ssh qu'attend rsync -e pour un hôte, quelle que soit la version de Deployer. */
function optionsSsh(Host $h): string
{
    return 'ssh ' . implode(' ', method_exists($h, 'connectionOptionsArray') ? $h->connectionOptionsArray() : $h->connectionOptions());
}

function rsync3(): bool
{
    static $ok = null;

    if ($ok === null) {
        $ok = false;

        try {
            $ok = preg_match('/rsync\s+version\s+3\./', Shell::bash('rsync --version 2>/dev/null | head -1')) === 1;
        } catch (\Throwable $e) {
        }
    }

    return $ok;
}

/** Signale un test sauté (outil absent) sans le compter comme échec. */
function saute(string $raison): void
{
    throw new TestSaute($raison);
}

final class TestSaute extends \Exception
{
}
