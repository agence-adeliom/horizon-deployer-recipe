# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Nature du dépôt

Bibliothèque Composer d'un seul fichier : `recipe/horizon-transfer.php`, un recipe [Deployer](https://deployer.org) 7.4 pour les projets WordPress Horizon (Bedrock + Sage + Acorn). Il ajoute quatre tâches — `db:pull`, `uploads:pull`, `db:push`, `uploads:push` — qui déplacent base de données et uploads entre environnements.

Pas de `vendor/` requis pour lire ou modifier le code : aucune dépendance n'est utilisée à l'exécution en dehors de Deployer lui-même, qui fournit l'environnement d'exécution. Pas de suite de tests, pas de linter configuré.

## Commandes

```bash
php -l recipe/horizon-transfer.php   # seule vérification automatisable localement
composer validate
```

La validation réelle se fait **depuis un projet Horizon consommateur** : y déclarer le dépôt en `repositories` (type `path` vers ce clone pendant le développement), puis exécuter les tâches (`dep db:push --from=… --to=…`). Les chemins de code les plus fragiles — parsing de la progression `rsync`, propagation des codes de sortie, résolution des symlinks — ne sont observables que là.

Ajouter `-n` pour le mode non interactif (aucune écriture n'est alors effectuée) et `-vvv` pour voir les commandes shell générées.

## Versionnage

Composer résout ce paquet **par tags git** (dépôt privé, hors Packagist). Toute modification publiée implique donc :

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

**Règle à respecter systématiquement :** toute lecture de configuration ou résolution de placeholder concernant un environnement doit passer par `transferResolve($env, …)` ou un bloc `on($env, …)`. Hors contexte, `{{bin/wp}}` serait résolu contre l'hôte *courant* — non seulement faux, mais générateur d'un appel SSH parasite, sa valeur étant calculée par une closure qui teste la présence du binaire.

`transferResolveEnv()` n'utilise volontairement pas `host()`, qui *crée* un hôte pour un alias inconnu : une faute de frappe passerait inaperçue et la tâche tournerait dans le vide.

### État de nettoyage persisté sur disque

Deployer exécute chaque tâche dans un **processus distinct**. Une tâche enregistrée via `fail()` ne voit donc aucune variable statique de la tâche qui a échoué. La liste des répertoires de travail à supprimer est pour cette raison écrite dans `<racine projet>/.dep/transfer-state.json` (`transferRegisterWorkdir` / `transferReadState` / `transferResetState`), ce qui permet aussi de nettoyer un hôte source absent du sélecteur.

Chaque tâche commence par `transferResetState()` et se termine par `invoke('transfer:cleanup')`, plus un `fail('<tâche>', 'transfer:cleanup')` en fin de fichier. Toute nouvelle tâche créant un répertoire de travail doit suivre ce triptyque.

### Contraintes `rsync` encodées dans le code

- `rsync` n'accepte **qu'une seule extrémité distante**. `transferRsyncCommand()` décide donc *où* exécuter la commande : en local pour un sens local↔distant, sur le serveur lui-même quand source et destination partagent la même `connectionString()` (`transferSameServer()`), et refuse le cas deux-serveurs-distincts — que les tâches gèrent en passant par un staging local.
- Un hôte déclaré via `localhost()` est une extrémité **locale** pour `rsync` (`transferIsLocalEndpoint()`) : pas de `ssh`, pas de préfixe `hôte:`.
- La barre de progression de Deployer est inutilisable ici (elle lit le compteur `to-chk`, que `rsync` n'émet qu'à la fin pour un fichier unique). Le recipe utilise `--info=progress2` et lit la sortie avec `read -r -d $'\r'` — **jamais** via un pipe vers `awk`/`tr`, qui bufferisent par blocs et ne restitueraient la progression qu'à la fin.
- `--outbuf=L` n'existe qu'à partir de rsync 3.1 et n'est ajouté qu'après détection (`transferRsyncLineBuffered()`) ; il ne convient qu'aux sorties en lignes, pas à la progression séparée par `\r`.

### Robustesse des scripts shell générés

Les heredocs assemblés dans le code obéissent à trois règles, chacune corrigeant un faux succès observé :

- `set -o pipefail` sur tout pipe (`gunzip -c … | wp db import -` annoncerait sinon un import réussi sur un flux vide) ;
- `set -e` dans la simulation `rsync`, sans quoi un `rsync` en échec renvoie « 0 fichier à transférer » ;
- tâche de fond + `wait $pid` dans `transferRunWatched()`, pour propager le code de sortie d'une commande longue dont on surveille la taille du fichier de sortie.

Les chemins passent toujours par `escapeshellarg()`.

### Sûreté fonctionnelle

Ces invariants sont le contrat du recipe ; ne pas les affaiblir sans demande explicite.

- **Aucune lecture n'exécute de code de plugin** : toutes les commandes WP-CLI de lecture portent `--skip-plugins --skip-themes`, pour qu'aucune routine de mise à jour ne se déclenche au passage.
- **Un `pull` ne modifie jamais le distant**, hormis ses propres fichiers temporaires.
- **Un `push` exige une confirmation de la destination** (`transferConfirmDestination()`), et la saisie de l'alias en clair si l'hôte est protégé (`transfer_protected`, vrai par défaut dès que l'alias ou le stage contient `prod`).
- **La base de destination est dumpée avant tout import** dans `{{transfer_backup_dir}}`, et cette sauvegarde survit au nettoyage.
- **Mode non interactif (`-n`) = aucune écriture.** `transferConfirm()` se fie uniquement à `input()->isInteractive()` : tester `stream_isatty(STDIN)` casserait le mode interactif sous `ddev php`, qui n'alloue pas de TTY.

### Réécriture d'URLs en deux passes

`transferSearchReplaceSteps()` produit d'abord l'URL complète avec protocole, puis le domaine nu. **L'ordre compte** : inverser casserait la première passe. La passe « domaine nu » rattrape `http://`, les URLs protocol-relative, l'échappement JSON des blocs Gutenberg (`https:\/\/`) — mais touche aussi les adresses e-mail du domaine, d'où l'exclusion par défaut des tables de logs et caches de plugins (`transfer_search_replace_skip_tables`, `*_wf*`).

Le search-replace s'exécute **sur l'environnement qui a reçu la base**, distant compris.

### Symlinks côté distant

`find` et `du` ne descendent pas dans un symlink passé en point de départ et renverraient 0. Il y a deux symlinks à traverser (`current` → release, uploads shared_dir → `shared/`), donc `transferUploadsPath()` résout explicitement le chemin réel (`readlink -f`, repli `cd && pwd -P`).

## Documentation

`README.md` est la référence utilisateur et fait partie du livrable : toute nouvelle option, tâche ou clé de configuration doit y être reflétée (tableaux « Tâches » et « Configuration »).
