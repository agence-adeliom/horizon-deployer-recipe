<?php
/*
 * build:check : buildCheckRemoteCommit, buildCheckLocalCommit et la tâche, sur de vrais dépôts git
 * temporaires (un dépôt nu fait office de dépôt distant).
 */

namespace Deployer;

const GIT = 'git -c user.name=Tests -c user.email=tests@exemple.test -c commit.gpgsign=false -c tag.gpgsign=false';

/**
 * Dépôt distant nu et clone local sur la branche main, un commit poussé. Rend [clone, distant].
 */
function buildDepots(): array
{
    if (!disponible('git --version')) {
        saute('git absent');
    }

    Shell::$reel = true;
    $distant = dossierTemporaire();
    $clone = dossierTemporaire();
    Shell::bash('git init -q --bare . && git symbolic-ref HEAD refs/heads/main', $distant);
    Shell::bash('git init -q . && git checkout -q -b main', $clone);
    mkdir("$clone/web/app/themes/sage", 0777, true);
    file_put_contents("$clone/web/app/themes/sage/app.js", "1\n");
    file_put_contents("$clone/README", "x\n");
    Shell::bash(GIT . ' add . && ' . GIT . ' commit -qm init && git remote add origin ' . escapeshellarg($distant) . ' && git push -q origin main', $clone);

    return array($clone, $distant);
}

/** Exécute $fn depuis $dossier : runLocally() tourne dans le dossier courant, comme dep. */
function depuis(string $dossier, callable $fn)
{
    $avant = getcwd();
    chdir($dossier);

    try {
        return $fn();
    } finally {
        chdir($avant);
    }
}

function buildCommit(string $depot, string $ref = 'HEAD'): string
{
    return trim(Shell::bash('git rev-parse ' . escapeshellarg($ref), $depot));
}

cas('buildCheckRemoteCommit : branche, tags léger et annoté, HEAD, révision, inconnue', static function (): void {
    list($clone, $distant) = buildDepots();
    Suite::$courant->set('repository', $distant);
    $commit = buildCommit($clone);
    Shell::bash(GIT . ' tag v1 && ' . GIT . ' tag -a v2 -m v2 && git push -q origin v1 v2', $clone);

    depuis($clone, static function () use ($commit): void {
        verifie('branche', buildCheckRemoteCommit('main'), $commit);
        verifie('tag léger', buildCheckRemoteCommit('v1'), $commit);
        verifie('tag annoté : le commit, pas l\'objet tag', buildCheckRemoteCommit('v2'), $commit);
        verifie('HEAD du dépôt distant', buildCheckRemoteCommit('HEAD'), $commit);
        verifie('cible inconnue', buildCheckRemoteCommit('nexiste-pas'), null);
    });

    Shell::reinitialiser();
    verifie('révision explicite : sans appel réseau', buildCheckRemoteCommit('ABCDEF1'), 'abcdef1');
    verifie('aucune commande', Shell::commandes(), array());
});

cas('buildCheckLocalCommit', static function (): void {
    list($clone) = buildDepots();

    depuis($clone, static function () use ($clone): void {
        verifie('branche locale', buildCheckLocalCommit('main'), buildCommit($clone));
        verifie('inconnue : null', buildCheckLocalCommit('nexiste-pas'), null);
    });
});

cas('build:check : désactivé, conforme', static function (): void {
    list($clone, $distant) = buildDepots();

    tache('build:check', hote('production', array('build_check' => false)));
    verifie('désactivé : aucune commande', Shell::commandes(), array());

    $h = hote('production', array('repository' => $distant, 'target' => 'main', 'theme' => 'sage'));
    depuis($clone, static function () use ($h): void {
        tache('build:check', $h);
    });
    contient('conforme', sortie(), 'Code local = « main » (' . substr(buildCommit($clone), 0, 8) . ')');
    uneCommande('thème vérifié', "#^git status --porcelain -- 'web/app/themes/sage'$#");
});

cas('build:check : commit non poussé, cible absente, modification du thème', static function (): void {
    list($clone, $distant) = buildDepots();
    $h = hote('production', array('repository' => $distant, 'target' => 'main', 'theme' => 'sage'));
    $tache = static function () use ($h, $clone): void {
        depuis($clone, static function () use ($h): void {
            tache('build:check', $h);
        });
    };

    file_put_contents("$clone/README", "y\n");
    $tache();
    contient('autre dossier modifié : toléré', sortie(), 'Code local = « main »');

    file_put_contents("$clone/web/app/themes/sage/app.js", "2\n");
    leve('thème modifié', $tache, \Throwable::class, "Modifications non commitées dans web/app/themes/sage");

    Shell::bash(GIT . ' commit -qam suite', $clone);
    leve('commit non poussé', $tache, \Throwable::class, "n'est pas celui qui va être déployé (« main » = ");

    $h->set('target', 'release');
    leve('cible absente du dépôt distant', $tache, \Throwable::class, 'Cible « release » introuvable sur le dépôt distant : la pousser avant de déployer.');
});

cas('build:check : stratégie local_archive, commit lu dans le dépôt local', static function (): void {
    list($clone) = buildDepots();
    Shell::bash(GIT . ' commit -q --allow-empty -m local', $clone);
    $h = hote('production', array('repository' => '/nexiste/pas', 'target' => 'main', 'update_code_strategy' => 'local_archive'));

    depuis($clone, static function () use ($h): void {
        tache('build:check', $h);
    });
    contient('commit non poussé accepté', sortie(), 'Code local = « main »');
    aucuneCommande('aucun ls-remote', '/ls-remote/');

    $h->set('target', 'nexiste-pas');
    leve('cible absente du dépôt local', static function () use ($h, $clone): void {
        depuis($clone, static function () use ($h): void {
            tache('build:check', $h);
        });
    }, \Throwable::class, 'introuvable dans le dépôt local');
});

cas('build_check_paths : le thème s\'il est défini, sinon rien', static function (): void {
    verifie('sans thème', dans(hote('sans-theme'), static function () {
        return get('build_check_paths');
    }), array());
    verifie('avec thème', dans(hote('avec-theme', array('theme' => 'sage')), static function () {
        return get('build_check_paths');
    }), array('web/app/themes/{{theme}}'));
});
