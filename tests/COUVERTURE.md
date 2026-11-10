# Couverture des tests

Deux suites complémentaires :

- **Unitaires** (`tests/unit/`, `php tests/run.php`) : le vrai Deployer (7 ou 8) charge `recipe/common.php` puis le recipe entier, comme un `deploy.php`. Seuls le client SSH et l'exécuteur local sont remplacés par un enregistreur (`Shell`, dans `tests/unit/bootstrap.php`) : chaque commande que le recipe enverrait est vérifiée comme chaîne, avec son hôte, son répertoire et ses options. Quand c'est local et sans risque, elle est exécutée pour de vrai par bash entre dossiers temporaires (rsync 3, gzip, git, tar, find, liens symboliques). Les questions reçoivent des réponses scriptées, y compris la fin d'entrée d'un `dep` sans TTY.
- **Intégration** (`tests/integration/`, `tests/integration/run.sh`) : vrai `dep` (Deployer 7 et 8) lancé depuis un conteneur « local » contre deux serveurs SSH (cinq hôtes, dont `production` protégée et deux hôtes sur le même compte) et MariaDB 10.11, WordPress installé partout avec des URLs différentes, deux réseaux multisite. Colonne « Intégration » : scénario de `tests/integration/scenarios/` qui le vérifie par son effet réel. Aucun scénario n'est ignoré : les bugs trouvés sont corrigés (voir « Bugs corrigés »).

## Résumé

- 82 fonctions dans `recipe/horizon-transfer.php` : les 82 ont des tests unitaires. Pour 35 d'entre elles, une partie du comportement ne se voit qu'en intégration (SSH réel, WP-CLI et MariaDB réels, proxy des questions entre worker et master) : colonne « Reste à l'intégration ».
- 11 tâches et 6 réglages calculés (closures) : tous couverts en unitaire, les quatre tâches de transfert de bout en bout avec un hôte `localhost()` seulement (sans SSH).
- 43 mutations représentatives du recipe, réparties sur les 14 fichiers, font toutes échouer la suite.
- Bugs exposés, marqués `casBug()` (signalés sans faire échouer la suite, qui échoue s'ils disparaissent) : voir « Bugs connus » en fin de document.

## Fonctions

| Fonction | Unitaire | Reste à l'intégration | Intégration |
|---|---|---|---|
| `transferProjectRoot` | `environnements` | | Tous (indirect) |
| `transferAliases` | `environnements` | | `30-db-push` (environnement inconnu) |
| `transferResolveEnv` | `environnements` | | `30-db-push` (alias inconnu, `local` refusé en destination) |
| `transferEnvLabel` | `environnements` | | Tous (messages vérifiés) |
| `transferSameServer` | `environnements` | | `30-db-push`, `40-uploads-push` (staging et recette sur le même compte) |
| `transferIsLocalEndpoint` | `environnements` | | `10-db-pull`, `30-db-push`, `40-uploads-push` (local ↔ distant) |
| `transferLegacyRunApi` | `execution` | | Tous, sous Deployer 7 et 8 (matrice) |
| `transferExec` | `execution` | Paramètres positionnels réellement acceptés par `run()` de Deployer 8 sur SSH | Tous, sous Deployer 7 et 8 (matrice) |
| `transferRun` | `execution` | Exécution SSH réelle | Tous |
| `transferWpRun` | `execution` | `cd {{current_path}}` réel, pipes et `pipefail` sur le serveur | Tous |
| `transferTest` | `execution` | | `40-uploads-push`, `60-roles` (`roles:status`) |
| `transferWpBin` | `wp-cli` | | Tous |
| `transferWpLastLine` | `wp-cli` | | `10-db-pull`, `30-db-push` (URLs lues) |
| `transferWpIsInstalled` | `wp-cli` | Sortie réelle de WP-CLI | `30-db-push`, `50-deploy` |
| `transferWpHasCommand` | `wp-cli` | `wp cli cmd-dump` réel, WP-CLI amputé de ses commandes | `10-db-pull`, `30-db-push` (WP-CLI complet seulement) |
| `transferWpMissingDiagnosis` | `wp-cli` | | non couvert (le premier déploiement s'arrête avant, faute de release en ligne) |
| `transferResolve` | `execution` | | `30-db-push` (commandes affichées résolues sur l'hôte) |
| `transferResolvePath` | `execution` | `$HOME` réel d'un `deploy_path` en `~/` | Tous (chemins absolus seulement, pas de `~/`) |
| `transferSize` | `execution` | | `20-uploads-pull`, `40-uploads-push` (indirect) |
| `transferTryLocally` | `execution` | | Tous (`bin/wp_local`) |
| `transferRunWatched` | `execution` (exécution réelle du code de sortie) | Affichage en temps réel au travers de SSH | `10-db-pull`, `30-db-push`, `50-deploy` (export, sauvegardes) |
| `transferRsyncRunHost` | `rsync` | | `40-uploads-push` (même serveur), `41` (transit local) |
| `transferRsyncLineBuffered` | `rsync` | `rsync --help` d'un serveur réel | `40-uploads-push` (rsync 3.2 réel) |
| `transferRsyncCommand` | `rsync` | Options `-e` interprétées par rsync et ssh | `10`, `20`, `30`, `40` (vrai ssh `-e`) , `41` (deux serveurs, en deux rsync) |
| `transferRsyncPreview` | `rsync` (rsync 3 réel en local) | Sens `<` d'un vrai push vers un serveur | `40-uploads-push` (comptes exacts, push `<`, `--checksum`, miroir), `41` (simulation depuis la copie locale) |
| `transferRsync` | `rsync` (rsync 3 réel en local) | Progression au travers de SSH | `10`, `20`, `30`, `40` (fichiers réellement transférés) |
| `transferStateFile` | `etat` | | Tous (`transfer-state.json` absent en fin de tâche) |
| `transferReadState` | `etat` | | `30-db-push` (nettoyage après échec) |
| `transferResetState` | `etat` | | Tous |
| `transferRegisterWorkdir` | `etat` | | `30-db-push`, `40-uploads-push` |
| `transferWorkdir` | `etat` (purge réelle en local) | Création et purge sur le serveur | `10`, `20`, `30`, `40` (aucun répertoire résiduel, local et distant) |
| `transferConfirm` | `environnements` | Question posée par le worker et proxifiée vers le master | `10`, `20`, `30` (réponses par stdin, Entrée, `-n`, EOF) |
| `transferConfirmDiskUsage` | `environnements` | Idem | `20-uploads-pull` (oui, non, `-n`), `41` (transit refusé) |
| `transferReuseLocalArchive` | `etat` (gzip -t réel) | | `10-db-pull` (réutilisé, tronqué, fenêtre 0), `20-uploads-pull`, `70-collations` |
| `transferChoice` | `environnements` | Idem `transferConfirm` | `40-uploads-push` (stratégie), `31` (`Select hosts`) |
| `transferIsProtected` | `environnements` | | `30`, `40` (production), `61` (repli) |
| `transferConfirmDestination` | `environnements` | Idem `transferConfirm` | `30-db-push`, `40-uploads-push` (saisie fausse, alias, `-n`, EOF) |
| `transferSelectEnvs` | `environnements` | Sélecteur d'hôtes de la ligne de commande | `30-db-push` (sélecteur = destination, `--from` exigé en `-n`), `31` (sélecteur requis, comme le dit le README) |
| `transferAskUploadsStrategy` | `environnements` | | `40-uploads-push` (question, option, option invalide) |
| `transferSiteUrl` | `wp-cli` | `WP_HOME` et option `home` réels | `10`, `30` (`WP_HOME` réel) |
| `transferSearchReplaceSteps` | `search-replace` | | `10-db-pull`, `30-db-push` (deux passes, https → http) |
| `transferDbRows` | `search-replace` | Requête réelle par `wp db query` | `60-roles`, `80-multisite` |
| `transferMultisiteSites` | `multisite` | Tables `site`, `blogs`, `domain_mapping` réelles | `80-multisite` (sans `domain_mapping`) |
| `transferMultisiteBase` | `multisite` | `DOMAIN_CURRENT_SITE` réel | `80-multisite` |
| `transferIsDomain` | `multisite` | | `80-multisite` (saisie libre) |
| `transferDdevHostnames` | `multisite` | | Non couvert (hors DDEV) |
| `transferDdevRoutes` | `multisite` | | Non couvert (hors DDEV) |
| `transferMultisiteTarget` | `multisite` | | `80-multisite` (réseau, sous-domaine, blog avant import, saisie libre ; pas le choix local) |
| `transferMultisiteSearchReplaceSteps` | `multisite` | | `80-multisite` |
| `transferSearchReplace` | `search-replace` | | `10`, `30`, `80` |
| `transferSearchReplaceRun` | `search-replace` | `wp search-replace` réel (sérialisé, mémoire) | `10-db-pull` (sérialisé, JSON, e-mail, `*_wf*` exclue), `30` (refus) |
| `transferDbExport` | `base` | Export réel | `10`, `30` |
| `transferDbBackup` | `sauvegardes` | Export réel conservé | `30-db-push`, `50-deploy` (contenu du dump vérifié) |
| `transferBackupRelease` | `sauvegardes` (liens réels en local) | Liens `release` et `current` pendant un vrai `dep deploy` | `50-deploy` (avant-release et release) |
| `transferRotateBackups` | `sauvegardes` (fichiers réels en local) | | `50-deploy` (`transfer_backup_keep` = 2, dumps d'avant import gardés) |
| `transferBackupWithRelease` | `sauvegardes` | | `50-deploy`, `51-deploy-premier` (aucune release : avertissement) |
| `transferSupportedCollations` | `base` | `SHOW COLLATION` d'un vrai MariaDB ou MySQL | `70-collations` (MariaDB 10.11) |
| `transferDumpCollations` | `base` (gunzip, grep et sed réels) | | `70-collations` |
| `transferCollationCharsets` | `base` | | `70-collations` (indirect) |
| `transferCollationFallback` | `base` | | `70-collations` (`utf8mb4_0900_ai_ci` → `utf8mb4_unicode_ci`) |
| `transferImportHooks` | `base` | Commandes du projet réelles | `30-db-push` (avant, après, échec qui annule) |
| `transferDbImport` | `base` (pipefail réel) | `wp db clean` et import réels, `--fix-collations` sur un vrai dump MySQL 8 | `10`, `30`, `70` (refus sans rien supprimer, `--fix-collations`) |
| `transferFaviconFiles` | `uploads` | Métadonnées réelles | `20-uploads-pull` |
| `transferPullFavicon` | `uploads` | rsync réel depuis le serveur | `20-uploads-pull` (base locale, repli distant, fichier absent, refus) |
| `transferUploadsPath` | `uploads` (liens réels en local) | `readlink -f` absent (repli `pwd -P`) | `20`, `40` (liens `current` et `shared` réels ; pas le repli `pwd -P`) |
| `transferFile` | `rsync` | Deux serveurs distincts | `30-db-push` (même serveur, serveurs distincts) |
| `transferDbSchemaVersions` | `wp-cli` | `wp eval` réel | `50-deploy` |
| `buildCheckRemoteCommit` | `build-check` (vrais dépôts git) | | `50-deploy` (branche, tag annoté, tag absent) |
| `buildCheckLocalCommit` | `build-check` (vrais dépôts git) | | Non couvert (`local_archive`) |
| `rolesCheckLocalFile` | `roles` | | `60-roles` (sans mu-plugin : aucune commande distante) |
| `rolesCheckLoaderPath` | `roles` | | `60-roles` (chargeur absent de la release) |
| `rolesCheckWarnLegacy` | `roles` | | `60-roles` |
| `rolesCheckParseData` | `roles` | | `60-roles` (déclaration exécutable refusée, non exécutée) |
| `rolesCheckParseValue` | `roles` (par `rolesCheckParseData`) | | `60-roles` (indirect) |
| `rolesCheckUnquote` | `roles` | | `60-roles` (indirect) |
| `rolesCheckDeclarationErrors` | `roles` | | `60-roles` (déclaration invalide) |
| `rolesCheckExpected` | `roles` | | `60-roles` |
| `rolesCheckCommittedCopy` | `roles` (vrai dépôt git) | | `60-roles` (HEAD, fichier jamais commité) |
| `rolesCheckHostEnvironmentValues` | `roles` (grep et sed réels) | `shared/.env` sur le serveur | `61-roles-environnements` (13 variantes de `shared/.env`) |
| `rolesCheckEnvironmentType` | `roles` | | `61-roles-environnements` |
| `rolesCheckHostRoles` | `roles` | Options sérialisées réelles en `HEX()` | `60-roles` (vraies options sérialisées) |
| `rolesCheckDiff` | `roles` | | `60-roles` (rôle en trop, cap retirée) |

## Tâches et réglages calculés

| Élément | Unitaire | Reste à l'intégration | Intégration |
|---|---|---|---|
| `db:pull` | `taches` (hôte `localhost()`) | SSH, WP-CLI et base réels | `10-db-pull`, `70-collations` |
| `uploads:pull` (et `--favicon-only`) | `taches` (hôte `localhost()`) | Idem, extraction réelle | `20-uploads-pull` |
| `db:push` | `taches` (hôte `localhost()`) | Deux serveurs distincts, base réelle | `30-db-push`, `80-multisite`, `31` (forme documentée) |
| `uploads:push` | `taches` (rsync réel vers un hôte `localhost()`) | Deux serveurs distincts (transit local) | `40-uploads-push`, `41` (deux serveurs distincts) |
| `transfer:cleanup` | `etat` (suppression réelle en local) | | `10`, `20`, `30`, `40` (après succès et après échec) |
| `build:check` | `build-check` (vrais dépôts git) | Avant un vrai `dep deploy` | `50-deploy` |
| `db:backup`, `deploy:db-backup` | `sauvegardes` | Avant `deploy:symlink` d'un vrai déploiement | `50-deploy`, `51-deploy-premier` (aucune release : avertissement) |
| `wp:update-db` | `wp-cli` | Après `deploy:success` d'un vrai déploiement | `50-deploy` (oui, non, `-n`, EOF, manuel, désarmée) |
| `roles:check` | `roles` | | `60-roles`, `61-roles-environnements` |
| `roles:status` | `roles` | Commande du mu-plugin réelle | `60-roles` (`up_to_date`, `pending`, invalide, chargeur absent) |
| Points d'accroche et `fail()` | `declarations` | Ordre d'exécution réel dans `dep deploy` | `60-roles` (ordre réel dans `dep deploy`), `30` (`fail()`) |
| Options, défauts, marqueur de version | `declarations` | | Indirect (`--strategy`, `--checksum`, `--fix-collations`, `--favicon-only`, `--from`, `--to`) |
| `bin/wp_local`, `bin/wp` | `wp-cli` | Téléchargement réel du phar | Tous (WP-CLI dans le PATH ; pas de téléchargement du phar) |
| `transfer_protected`, `transfer_local_tmp_dir` | `environnements`, `declarations` | | `30`, `40`, `61` |
| `build_check_paths`, `roles_check_file` | `build-check`, `roles` | | `50-deploy`, `60-roles` (valeurs par défaut) |

## Bugs corrigés

Trouvés par les tests et corrigés en 1.9.0. Leurs tests sont devenus des cas ordinaires.

1. **Deployer 8 : `Host::connectionOptionsArray()` n'existe plus.** Deployer 8 l'a renommée `connectionOptions()`. `transferRsyncCommand()` l'appelait dès qu'une extrémité était distante : sous Deployer 8, `db:pull`, `uploads:pull` (y compris `--favicon-only`), `db:push` et `uploads:push` vers ou depuis un serveur SSH s'arrêtaient sur « Call to undefined method ». Le recipe appelle désormais la méthode disponible. Tests : `rsync` (deux cas) et `uploads` (un cas).
2. **Multisite en local : défaut d'`askChoice()` invalide.** `transferMultisiteTarget()` passait une liste indexée et, comme défaut, la valeur « (laisser tel quel) » au lieu de sa clé : Deployer levait « Default choice is not available », et un `db:pull` interactif d'un multisite sous DDEV s'arrêtait après l'import dès qu'un domaine n'avait pas de correspondance unique parmi les hostnames DDEV. Les choix sont désormais indexés par leur valeur. Test : `multisite`.
3. **`uploads:push` entre deux serveurs distincts était inutilisable.** La simulation lançait un rsync direct entre les deux serveurs, avant la branche de transit par le local, et échouait sur « rsync direct impossible entre deux serveurs distincts ». Désormais, la copie de transit vers le local (après confirmation du volume) précède la simulation, faite entre cette copie et la destination ; la confirmation de la destination vient ensuite, et un refus supprime la copie locale. Scénario : `41-uploads-push-serveurs-distincts`.
4. **Le tout premier déploiement échouait.** `deploy:db-backup` lançait WP-CLI dans `{{current_path}}`, qui n'existe pas avant la première bascule. Sans release en ligne, la sauvegarde est maintenant sautée avec un avertissement, comme l'annonçait le README. Scénario : `51-deploy-premier`.
5. **Documentation des push.** `dep db:push --from=X --to=Y` sans sélecteur fait poser « Select hosts » par Deployer, et échoue en `-n`. Le README, l'en-tête du recipe et les descriptions des tâches donnent désormais la forme `dep db:push <hôte> --from=X`. Scénario : `31-push-sans-selecteur`.
