# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Nature du dépôt

Bibliothèque Composer d'un seul fichier : `recipe/horizon-transfer.php`, un recipe [Deployer](https://deployer.org) 7.4+ et 8 pour les projets WordPress Horizon (Bedrock + Sage + Acorn). Il ajoute quatre tâches — `db:pull`, `uploads:pull`, `db:push`, `uploads:push` — qui déplacent base de données et uploads entre environnements, plus des tâches greffées sur `dep deploy` : `build:check` et `roles:check` avant `deploy`, `db:backup` avant `deploy:symlink`, `wp:update-db` et `roles:status` à `deploy:success`. Les deux tâches `roles:*` ne servent qu'aux projets dotés du mu-plugin `adeliom-roles` et sont inertes ailleurs.

Pas de `vendor/` requis pour lire ou modifier le code : aucune dépendance n'est utilisée à l'exécution en dehors de Deployer lui-même, qui fournit l'environnement d'exécution. Pas de linter configuré. Les tests, eux, chargent le vrai Deployer (`composer install` d'abord). Toutes les fonctions et tâches du recipe ont des tests unitaires (`tests/unit/`, lancés par `php tests/run.php`), exécutés par GitHub Actions (`.github/workflows/tests.yml`) à chaque push sur `main` et à chaque PR, sur PHP 7.4 à 8.4 avec Deployer 7 et sur PHP 8.3 et 8.4 avec Deployer 8. `tests/COUVERTURE.md` indique, fonction par fonction, le fichier qui la couvre et ce qui ne se vérifie qu'en intégration.

## Commandes

```bash
php -l recipe/horizon-transfer.php
composer install                     # Deployer, requis par les tests
php tests/run.php                    # tests unitaires (tests/unit/*.test.php)
php tests/run.php rsync roles        # seulement tests/unit/rsync.test.php et roles.test.php
composer validate
```

Les tests unitaires chargent `recipe/common.php` de Deployer puis le recipe entier, comme un `deploy.php` ; seuls le client SSH et l'exécuteur local sont remplacés par l'enregistreur `Shell` de `tests/unit/bootstrap.php`. Un cas (`cas()`) déclare ses hôtes (`hote()`, `hoteLocal()` pour un hôte `localhost()`), pose les réponses aux commandes (`Shell::quand(motif, réponse)`), fournit options et réponses aux questions (`entree()`), puis vérifie les commandes envoyées (`uneCommande()`, `aucuneCommande()`) et la sortie (`sortie()`). Chaque cas a un hôte courant et une racine de projet (`DEPLOYER_ROOT`) neufs. `Shell::$reel = true` exécute pour de vrai, avec bash, les commandes sans réponse posée : à réserver aux dossiers temporaires (`dossierTemporaire()`). Les cas qui demandent rsync 3 ou git sont sautés sans eux. Un bug connu du recipe se marque `casBug()` : signalé sans faire échouer la suite, qui échoue en revanche dès qu'il ne se reproduit plus. Toute nouvelle fonction ou tâche ajoute ses cas dans le fichier de son domaine et sa ligne dans `tests/COUVERTURE.md`. `HORIZON_TESTS_VENDOR` désigne un autre `vendor/` (autre version de Deployer), `HORIZON_TESTS_RECIPE` une copie modifiée du recipe (vérifier qu'une mutation casse la suite). Les fixtures sont fictives (dépôt public : aucune donnée de client).

Le reste de la validation se fait **depuis un projet Horizon consommateur** : y déclarer le dépôt en `repositories` (type `path` vers ce clone pendant le développement), puis exécuter les tâches (`dep db:push <hôte> --from=…`). Les scripts les plus fragiles (lecture de la progression `rsync`, propagation des codes de sortie, résolution des symlinks) sont exécutés pour de vrai par les tests unitaires, mais en local seulement : leur comportement au travers de SSH ne s'observe que là.

Ajouter `-n` pour le mode non interactif (aucune écriture n'est alors effectuée) et `-vvv` pour voir les commandes shell générées.

## Versionnage

Composer résout ce paquet **par tags git** (dépôt public, mais hors Packagist). Toute modification publiée implique donc :

1. bumper `HORIZON_TRANSFER_RECIPE` (`recipe/horizon-transfer.php`), qui sert de marqueur de version testable depuis un `deploy.php` ;
2. créer le tag git correspondant.

Sans tag, un `composer update` chez le consommateur ne voit rien.

Commits en français, format conventionnel (`feat:`, `docs:`).

## Architecture

### Un fichier, des fonctions globales, `require_once` obligatoire

Tout vit dans le namespace `Deployer` sous forme de fonctions globales préfixées `transfer*`. Il n'y a pas d'autoloading : le consommateur fait un `require_once` du chemin dans `vendor/`. Le `require_once` n'est pas une précaution de style — PHP déclare les fonctions d'un fichier inclus **dès sa compilation**, donc un second `require` est une erreur fatale de redéclaration qu'aucun garde-fou à l'exécution (`if (defined(…)) return;`) ne peut intercepter. C'est aussi la raison pour laquelle il ne faut jamais ajouter de garde runtime au début du fichier : il donnerait une fausse impression de sûreté.

Corollaire : les `set()` de ce fichier sont des **défauts**. Le consommateur surcharge en redéfinissant après le `require_once`.

### L'abstraction centrale : `?Host $env`, où `null` signifie « local »

Toutes les fonctions de plomberie prennent un `?Host` et se comportent de façon identique en local et en distant :

| Fonction | Rôle |
|---|---|
| `transferRun` | `runLocally()` si `null`, sinon `run()` dans `on($env, …)` |
| `transferWpRun` | idem mais dans `within('{{current_path}}')` côté distant, pour que WP-CLI trouve le site |
| `transferTest` | test shell renvoyant un booléen |
| `transferResolve` | résout les `{{placeholders}}` **dans le contexte de l'hôte visé** |
| `transferSize`, `transferRunWatched`, `transferRsync` | opérations concrètes bâties sur les précédentes |

**Jamais d'appel direct à `run()` / `runLocally()` avec un tableau d'options** : Deployer 8 a remplacé ce tableau par des paramètres nommés (`$cwd` en second argument, d'où une TypeError), et les paramètres nommés sont inutilisables ici (PHP 7.4). Tout passe par `transferExec()`, qui prend les options au format Deployer 7 (`timeout`, `real_time_output`), détecte l'API par réflexion (`transferLegacyRunApi()`) et appelle Deployer 8 en positionnel. Une nouvelle option doit y être traduite, faute de quoi elle est refusée. Un `runLocally()` sans options reste compatible tel quel.

**Règle à respecter systématiquement :** toute lecture de configuration ou résolution de placeholder concernant un environnement doit passer par `transferResolve($env, …)` ou un bloc `on($env, …)`. Hors contexte, `{{bin/wp}}` serait résolu contre l'hôte *courant* — non seulement faux, mais générateur d'un appel SSH parasite, sa valeur étant calculée par une closure qui teste la présence du binaire.

`transferResolveEnv()` n'utilise volontairement pas `host()`, qui *crée* un hôte pour un alias inconnu : une faute de frappe passerait inaperçue et la tâche tournerait dans le vide.

### État de nettoyage persisté sur disque

Deployer exécute chaque tâche dans un **processus distinct**. Une tâche enregistrée via `fail()` ne voit donc aucune variable statique de la tâche qui a échoué. La liste des répertoires de travail à supprimer est pour cette raison écrite dans `<racine projet>/.dep/transfer-state.json` (`transferRegisterWorkdir` / `transferReadState` / `transferResetState`), ce qui permet aussi de nettoyer un hôte source absent du sélecteur.

Chaque tâche commence par `transferResetState()` et se termine par `invoke('transfer:cleanup')`, plus un `fail('<tâche>', 'transfer:cleanup')` en fin de fichier. Toute nouvelle tâche créant un répertoire de travail doit suivre ce triptyque. `wp:update-db` n'en crée aucun et n'y est donc volontairement pas soumise.

### Les points d'accroche dans le flux de déploiement

Ce fichier modifie `dep deploy` aux endroits suivants, chacun avec son garde-fou (`set(…, true)`) et un test d'existence de la tâche cible avant de poser le hook :

- `before('deploy', 'build:check')` (`build_check`) : les assets sont buildés sur la machine qui déploie, donc le commit local (`git rev-parse HEAD`) doit être celui que le serveur clonera pour `{{target}}`, lu sur le dépôt distant (`git ls-remote {{repository}}`, tag annoté déréférencé via `^{}`), et `{{build_check_paths}}` doit être propre. Comparer des commits et non des noms de branche couvre les commits non poussés, les branches locales en retard et les têtes détachées de CI. Seuls les chemins listés sont vérifiés, pour tolérer une modification temporaire de l'inventaire.
- `before('deploy:symlink', 'deploy:db-backup')` (`transfer_backup_on_deploy`) : dump de la base nommé d'après la release (`<alias>-avant-release-<N>-<date>.sql.gz`), puis rotation à `transfer_backup_keep` dumps. Dernier moment où la base est celle de la release en ligne ; aucune question n'est posée, donc tenir le verrou ne pose pas de problème. Un échec d'export **doit** faire échouer le déploiement (avant la bascule, donc sans dommage) ; un échec de rotation non. Le numéro vient du lien `{{deploy_path}}/release` lu sur le serveur, pas de `{{release_name}}` (qui, réévalué hors de `deploy:release`, donnerait la release suivante). `deploy:db-backup` est la variante cachée qui lit le réglage ; `db:backup` l'ignore, pour rester lançable à la main.
- `after('deploy:success', 'wp:update-db')` (`transfer_update_db`), détaillé ci-dessous.
- `before('deploy', 'roles:check')` et `after('deploy:success', 'roles:status')` (`roles_check`), détaillés dans « Rôles adeliom-roles ». Les `before()` s'empilent en tête (`array_unshift` dans `Task::addBefore()`) : `roles:check` est donc déclarée **avant** `build:check` pour s'exécuter après elle.

La rotation (`transferRotateBackups`) ne touche que les fichiers `<alias>-*release-*.sql.gz` : les dumps d'avant import (`db:push`, `wp:update-db`) ne sont jamais supprimés.

Le point d'accroche n'est pas interchangeable. `deploy:symlink` et `deploy:success` voient tous deux le symlink déjà basculé, mais la tâche pose une question : accrochée avant `deploy:unlock`, elle ferait attendre le verrou de déploiement, et un `Ctrl-C` sur la question laisserait le projet verrouillé. `deploy:unlock` lui-même est exclu pour une autre raison — c'est la commande qu'on lance à la main pour débloquer un déploiement interrompu, et une question sur la base y serait hors de propos. `deploy:success` clôt `deploy:publish`, est `->hidden()`, et n'est jamais atteinte par un déploiement en échec — celui-ci sort par `fail('deploy', 'deploy:failed')`, branche qui ne traverse pas `deploy:success`. Ne pas justifier cela par « `deploy:failed` est une tâche vide » : c'est vrai du `recipe/common.php` nu, mais la plupart des projets Horizon y accrochent `deploy:unlock` et de vraies actions de récupération — raison pour laquelle un échec de `wp:update-db` est rattrapé par un `try/catch` plutôt que propagé.

### Rôles adeliom-roles : `roles:check` et `roles:status`

Le mu-plugin `adeliom-roles` (skill Adeliom `wordpress-remplacer-ure`) synchronise des rôles déclarés dans le code au chargement de WordPress. Il se compose d'un chargeur `mu-plugins/adeliom-roles.php` et d'un dossier `mu-plugins/adeliom-roles/` dont `roles.php` est la déclaration, pur fichier de données (`return array(...)`). En production, sa **première application seulement** est conditionnée à une empreinte (`expected`) : écart ⇒ synchro en attente. `roles:check` refait ce contrôle avant le déploiement ; `roles:status` affiche `wp adeliom-roles status` après.

Invariants :

- **Sans mu-plugin, rien.** `rolesCheckLocalFile()` (purement local) rend `null` si `roles_check` est faux ou si `{{roles_check_file}}` (défaut : `web/app/mu-plugins/adeliom-roles/roles.php`, ou `wp-content/…` s'il est le seul présent) n'existe pas dans le projet : aucune commande distante, aucune question, une ligne en `-v` au plus. Tout nouveau code des deux tâches doit rester derrière ce test. Seule exception, locale : `rolesCheckWarnLegacy()` avertit si l'ancien mu-plugin d'un seul fichier (moteur 1.x) est là à la place du dossier.
- **Lectures en SQL, jamais `wp option get`.** `--skip-plugins` ne retient pas les mu-plugins (vérifié : un `wp option get … --skip-plugins` applique la synchro). `rolesCheckHostRoles()` lit donc le préfixe par `wp config get table_prefix` puis les options `<préfixe>user_roles` et `adeliom_roles_state` par `transferDbRows()`, en `HEX()` pour échapper à l'échappement du client mysql. Désérialisation avec `allowed_classes => false`.
- **Même règle que le mu-plugin.** `applied_version` non vide ⇒ une ligne d'info, pas de comparaison. Sinon `rolesCheckDiff()` reproduit `Fingerprint::snapshot()` + `Fingerprint::diff()` du moteur 2.x : tous les rôles triés, caps surveillées seulement, cap absente = `null`, comparaison stricte. À garder alignée sur le moteur.
- **Déclaration jamais exécutée.** `roles.php` n'est **jamais** passé à `require`, `include` ou `eval` : `rolesCheckParseData()` / `rolesCheckParseValue()` / `rolesCheckUnquote()` reconstruisent la valeur à partir des jetons (`token_get_all()`) du texte lu une seule fois (pas de fenêtre entre contrôle et lecture). Grammaire : `<?php`, `return`, une valeur, `;`, fin ; valeur = `array(…)` ou `[…]` (clés chaîne ou entier, virgule finale permise), `T_CONSTANT_ENCAPSED_STRING` (échappements de PHP), entier décimal avec `-` éventuel, `true`/`false`/`null` ; espaces et commentaires ignorés. Tout autre jeton lève une exception : avertissement, pas de contrôle. Chaîne : préfixe binaire `b` ignoré, `\u{…}` vide, non fermé ou au-delà de U+10FFFF refusé (comme PHP et le lecteur du skill). La valeur lue est ensuite validée par `rolesCheckDeclarationErrors()` (règles de `Declaration` du moteur, à garder alignées) : déclaration invalide ⇒ `\DomainException`, avertissement « déclaration invalide, la synchro ne s'appliquera pas », jamais « conformes ». Une simple liste blanche de jetons suivie d'un `require` ne suffit pas (`'f'(…)`, `[…][0](…)`, `('f')(…)` n'utilisent que des chaînes et des parenthèses) : ne jamais y revenir.
- **Production au sens de WordPress, pas de l'alias.** `rolesCheckHostEnvironmentValues()` lit `{{deploy_path}}/shared/.env` sur l'hôte (`grep`, lecture seule, première occurrence, valeur vide conservée) ; `rolesCheckEnvironmentType()`, fonction pure, normalise comme Bedrock puis `wp_get_environment_type()` : un `WP_ENVIRONMENT_TYPE` non vide l'emporte et vaut `production` s'il n'est pas valide (`production|staging|development|local`) ; sinon `WP_ENV` s'il est valide, sinon `production` (valeur vide ou non standard comprise : `WP_ENV=preprod` tourne en production pour WordPress et est contrôlé, avec une ligne d'information). Contrôle si le type est `production`, rien sinon (une `preprod` en `WP_ENV=staging` n'est pas contrôlée, une production peut s'appeler `live`). `null` (« introuvable ») seulement si aucune des deux variables n'existe dans le fichier (site non Bedrock, fichier absent) : repli sur `transferIsProtected()`, avec une ligne d'information « si cet hôte est la production ». Cette lecture reste derrière `rolesCheckLocalFile()` : sans mu-plugin, aucun appel au serveur.
- **Déclaration lue au commit `HEAD`** (`rolesCheckCommittedCopy()` : `git show HEAD:./roles.php` vers un fichier temporaire, supprimé après la lecture), celui que `build:check` compare à la cible déployée. Hors dépôt git ou fichier non commité : copie de travail, avec un avertissement.
- **Écart** : en interactif `askConfirmation('Continuer le déploiement ?', false)`, un « non » lève `error()` avant tout verrou ; en `-n`, avertissement et poursuite (décision du 09/11/2026). Sans TTY et sans `-n`, le défaut « non » arrête le déploiement. Une lecture en échec n'est qu'un avertissement.
- **`roles:status`** tourne sans `--skip-plugins` (la commande est déclarée par le mu-plugin ; son chargement applique la synchro si elle est due, comme la première requête). Présence du chargeur testée par `[ -f <dossier de roles_check_file>.php ]` (`rolesCheckLoaderPath()`, soit `mu-plugins/adeliom-roles.php`) dans la release, pas par `transferWpHasCommand()` : `wp cli cmd-dump` tourne avant le chargement de WordPress et ne voit jamais une commande de mu-plugin. `try/catch` et `|| echo` : jamais d'échec du déploiement.

### Le défaut d'une question qui écrit ne peut pas être l'écriture

`input()->isInteractive()` ne vaut faux qu'avec `-n` ou `-q`. Un `dep deploy` lancé sans TTY mais **sans** `-n` — cron, runner CI, `ssh serveur 'dep deploy'` — pose donc bel et bien la question, puis Symfony rencontre un EOF sur stdin et retombe **silencieusement** sur la réponse par défaut (`QuestionHelper::ask()`, `catch MissingInputException`).

Le rattraper après la question est impossible : les tâches tournent dans un worker, les questions sont proxifiées vers le master (`Deployer::proxyCallToMaster`), et c'est l'entrée du *master* que Symfony bascule en non interactive — dans un autre processus. Un `input()->isInteractive()` posé après la question voit toujours « vrai » côté worker.

C'est la raison de fond du `false` exigé de **tous** les appelants de `transferConfirm()`, et du `false` de `transferConfirmDestination()`, et la raison pour laquelle `wp:update-db` pose deux questions `askConfirmation` à défauts sûrs au lieu d'un `askChoice` dont le défaut aurait été la mise à jour.

### Contraintes `rsync` encodées dans le code

- `rsync` n'accepte **qu'une seule extrémité distante**. `transferRsyncCommand()` décide donc *où* exécuter la commande : en local pour un sens local↔distant, sur le serveur lui-même quand source et destination partagent la même `connectionString()` (`transferSameServer()`), et refuse le cas deux-serveurs-distincts — que les tâches gèrent en passant par un staging local.
- Un hôte déclaré via `localhost()` est une extrémité **locale** pour `rsync` (`transferIsLocalEndpoint()`) : pas de `ssh`, pas de préfixe `hôte:`.
- La barre de progression de Deployer est inutilisable ici (elle lit le compteur `to-chk`, que `rsync` n'émet qu'à la fin pour un fichier unique). Le recipe utilise `--info=progress2` et lit la sortie avec `read -r -d $'\r'` — **jamais** via un pipe vers `awk`/`tr`, qui bufferisent par blocs et ne restitueraient la progression qu'à la fin.
- `--outbuf=L` n'existe qu'à partir de rsync 3.1 et n'est ajouté qu'après détection (`transferRsyncLineBuffered()`) ; il ne convient qu'aux sorties en lignes, pas à la progression séparée par `\r`.

### Robustesse des scripts shell générés

Les heredocs et commandes assemblés dans le code obéissent à quatre règles, chacune corrigeant un faux succès observé :

- `set -o pipefail` sur tout pipe (`gunzip -c … | wp db import -` annoncerait sinon un import réussi sur un flux vide) ;
- `set -e` dans la simulation `rsync`, sans quoi un `rsync` en échec renvoie « 0 fichier à transférer » ;
- tâche de fond + `wait $pid` dans `transferRunWatched()`, pour propager le code de sortie d'une commande longue dont on surveille la taille du fichier de sortie.

- `curl -fsS` et jamais `curl -o` seul : sans `-f`, curl écrit la page d'erreur HTTP dans le fichier de destination et rend 0. Le `test -f {{deploy_path}}/.dep/wp-cli.phar` de la closure `bin/wp` accepterait alors définitivement un phar de 14 octets contenant « 404: Not Found ».

- `gzip -t` avant de réutiliser un dump ou une archive déjà présent en local (`transferReuseLocalArchive()`) : un transfert interrompu laisse un `.gz` tronqué, que la suppression des tables précédant l'import rendrait catastrophique — base vidée, puis import en échec.

Les chemins passent toujours par `escapeshellarg()`.

### Sûreté fonctionnelle

Ces invariants sont le contrat du recipe ; ne pas les affaiblir sans demande explicite.

- **Aucune lecture n'exécute de code de plugin** : toutes les commandes WP-CLI de lecture portent `--skip-plugins --skip-themes`, pour qu'aucune routine de mise à jour ne se déclenche au passage.
- **Un `pull` ne modifie jamais le distant**, hormis ses propres fichiers temporaires.
- **Un `push` exige une confirmation de la destination** (`transferConfirmDestination()`), et la saisie de l'alias en clair si l'hôte est protégé (`transfer_protected`, vrai par défaut dès que l'alias ou le stage contient `prod`).
- **La base de destination est dumpée avant tout import** dans `{{transfer_backup_dir}}`, et cette sauvegarde survit au nettoyage.
- **On ne supprime que les tables, jamais la base** : avant un import, `wp db clean` (tables du préfixe de la destination) et non `wp db reset`, dont le `DROP DATABASE` est refusé par les hébergements mutualisés (OVH) et emporterait les autres applications d'une base partagée.
- **Mode non interactif (`-n`) = aucune écriture.** `transferConfirm()` se fie uniquement à `input()->isInteractive()` : tester `stream_isatty(STDIN)` casserait le mode interactif sous `ddev php`, qui n'alloue pas de TTY.

### Réécriture d'URLs en deux passes

`transferSearchReplaceSteps()` produit d'abord l'URL complète avec protocole, puis le domaine nu. **L'ordre compte** : inverser casserait la première passe. La passe « domaine nu » rattrape `http://`, les URLs protocol-relative, l'échappement JSON des blocs Gutenberg (`https:\/\/`) — mais touche aussi les adresses e-mail du domaine, d'où l'exclusion par défaut des tables de logs et caches de plugins (`transfer_search_replace_skip_tables`, `*_wf*`).

Le search-replace s'exécute **sur l'environnement qui a reçu la base**, distant compris.

### Multisite : un domaine par site

Sur un réseau, `WP_HOME` ne désigne qu'un site : `transferSearchReplace()` bascule donc sur `transferMultisiteSearchReplaceSteps()` dès que la base **reçue** est un multisite. Deux invariants :

- **Les domaines se lisent en SQL** (`transferDbRows()` → `wp db query`, lignes marquées `+dep|`), jamais via `wp site list` : WordPress ne démarre pas tant que `{prefix}site.domain` diffère de `DOMAIN_CURRENT_SITE`, ce qui est l'état même d'une base fraîchement importée.
- **Les sites de la source sont lus sur la destination après l'import**, pas sur la source : c'est la base à réécrire, dump réutilisé compris. Seuls les sites de la destination *avant* import (corrélation par `blog_id`) sont lus en amont par les tâches et passés en argument. Cette corrélation est écartée quand le domaine trouvé est un domaine de la source : la base écrasée était alors une copie jamais réécrite.

En local, la corrélation et la vérification du routage s'appuient sur `DDEV_HOSTNAME`, disponible parce que `dep` tourne dans le conteneur ; hors DDEV, la variable est vide et ces étapes sont simplement sautées.

### Symlinks côté distant

`find` et `du` ne descendent pas dans un symlink passé en point de départ et renverraient 0. Il y a deux symlinks à traverser (`current` → release, uploads shared_dir → `shared/`), donc `transferUploadsPath()` résout explicitement le chemin réel (`readlink -f`, repli `cd && pwd -P`).

## Tests d'intégration

`tests/integration/run.sh` (Docker requis ; `DEPLOYER_VERSION=8` pour Deployer 8) lance un vrai `dep` depuis un conteneur « local » contre deux serveurs SSH et MariaDB, avec WordPress installé partout. Un scénario par fonctionnalité dans `tests/integration/scenarios/`, outils communs dans `tests/integration/lib/common.sh` (`reset_all`, `dep_run '<réponses>' …`, assertions). Les réponses aux questions passent par stdin, comme un `dep` sans TTY. Un bug avéré du recipe se marque par `skip_bug` (scénario « ignoré », non bloquant) tant qu'il n'est pas corrigé. Options : `--keep`, `--reuse`, `--recipe <dossier>` (tester une copie modifiée du recipe). Workflow : `.github/workflows/integration.yml`.

## Documentation

`README.md` est la référence utilisateur et fait partie du livrable : toute nouvelle option, tâche ou clé de configuration doit y être reflétée (tableaux « Tâches » et « Configuration »).
