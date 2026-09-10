# horizon-deployer-recipe

Recipes [Deployer](https://deployer.org) pour les projets WordPress Horizon (Bedrock + Sage + Acorn).

Le recipe `horizon-transfer` ajoute quatre tâches pour déplacer la base de données et les
uploads entre environnements, avec confirmations explicites, progression en temps réel et
nettoyage systématique des fichiers temporaires.

## Prérequis

PHP >= 8.0 et Deployer ^7.4. Testé en exécution réelle sur PHP 8.0, 8.1 et 8.2 — un projet
encore en 8.0 ou 8.1 peut donc l'utiliser. PHP 7.4 n'est pas supporté : le code s'appuie sur
`catch` sans variable, les arguments nommés, l'opérateur `?->` et `str_contains`.

## Installation

Le dépôt n'étant pas sur Packagist, ajouter dans le `composer.json` du projet :

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:agence-adeliom/horizon-deployer-recipe.git"
        }
    ]
}
```

**Ce dépôt est privé.** Composer a donc besoin d'un token GitHub, sans quoi l'installation échoue sur
une erreur trompeuse : un `404` de l'API GitHub sur l'archive, celle-ci ne révélant pas l'existence
d'un dépôt privé. Renseigner dans le `auth.json` du projet, à garder hors du dépôt :

```json
{
    "github-oauth": {
        "github.com": "TOKEN_GITHUB"
    }
}
```

Un token *fine-grained* suffit, avec le minimum de droits : `agence-adeliom` comme propriétaire, accès
limité à ce dépôt, permission **Contents : Read-only**. En token *classic*, il faut le scope `repo`
complet, faute de variante en lecture seule.

Sans token, l'alternative est de cloner par SSH au lieu de télécharger l'archive :

```bash
composer config preferred-install.agence-adeliom/horizon-deployer-recipe source
```

Puis dans les deux cas :

```bash
composer require --dev agence-adeliom/horizon-deployer-recipe
```

Puis dans `deploy.php`, **avec `require_once`** :

```php
<?php

namespace Deployer;

require 'recipe/wordpress.php';
require_once __DIR__ . '/vendor/agence-adeliom/horizon-deployer-recipe/recipe/horizon-transfer.php';
```

> `require_once` est obligatoire. PHP déclare les fonctions d'un fichier inclus dès sa
> compilation : un second `require` provoquerait une erreur fatale de redéclaration
> qu'aucun garde-fou à l'exécution ne peut intercepter.

Le recipe fournit un défaut pour `bin/wp` (WP-CLI distant, téléchargé à la demande dans
`{{deploy_path}}/.dep/wp-cli.phar`). Pour le surcharger, redéfinissez-le **après** le
`require_once`.

Le recipe accroche également `wp:update-db` à la fin du déploiement (voir
[Mise à jour du schéma après déploiement](#mise-à-jour-du-schéma-après-déploiement)). C'est
le seul endroit où il modifie le comportement de `dep deploy` ; `set('transfer_update_db',
false)` le désarme.

Ajoutez à votre `.gitignore` :

```gitignore
/db-*.sql.gz
/uploads-*.tar.gz
/.dep/
```

## Tâches

| Tâche | Sens | Effet |
|---|---|---|
| `dep db:pull <hôte>` | distant → local | Dump `.sql.gz` à la racine du projet, puis propose l'import local et la réécriture d'URLs |
| `dep uploads:pull <hôte>` | distant → local | Archive `.tar.gz` à la racine du projet, puis propose l'extraction. `--favicon-only` ne récupère que le favicon |
| `dep db:push --from=X --to=Y` | local\|distant → distant | Sauvegarde la destination, importe, puis propose la réécriture d'URLs |
| `dep uploads:push --from=X --to=Y` | local\|distant → distant | Synchronise, au choix en fusion ou en miroir |
| `dep wp:update-db <hôte>` | — | Met à jour le schéma de base si le cœur déployé l'exige. Accrochée à `deploy:success`, donc lancée d'elle-même en fin de déploiement |

Un « environnement » est soit `local`, soit l'alias d'un hôte Deployer.

```bash
# Rapatrier la production en local
dep db:pull production
dep uploads:pull production

# Juste le favicon, pour que les onglets du navigateur ressemblent au site
dep uploads:pull production --favicon-only

# Rafraîchir la préproduction depuis la production
dep db:push --from=production --to=staging
dep uploads:push --from=production --to=staging

# Envoyer sa base locale en préproduction
dep db:push --from=local --to=staging
```

`--from` et `--to` sont facultatifs : ils sont demandés interactivement si absents. Le
sélecteur d'hôte de Deployer fait office de destination quand `--to` est omis, ce qui rend
ces deux commandes équivalentes :

```bash
dep db:push --from=production --to=staging
dep db:push staging --from=production
```

La destination d'un push est toujours un environnement distant. Pour rapatrier vers le
local, utilisez les tâches `pull`, qui gèrent en plus l'import et la réécriture d'URLs.

### Mise à jour du schéma après déploiement

`wp:update-db` compare la version de schéma enregistrée en base à celle qu'exige le cœur de
WordPress qui vient d'être déployé, et propose la mise à jour quand elles diffèrent — en
pratique au premier déploiement suivant un bump du core.

Elle est accrochée à `deploy:success` par le recipe : rien à ajouter dans `deploy.php`. Un
déploiement où le schéma est déjà à jour affiche une ligne et coûte trois commandes
distantes (deux pour résoudre `bin/wp`, une pour la lecture). Sinon, deux questions :

```
 warning Le schéma de base de « production » doit passer de la version 57155 à 58975.
 Mettre à jour le schéma de base de « production » ? [y/N] y
 Sauvegarder la base avant la mise à jour ? [Y/n]
 info 🛟 Sauvegarde de la base de production avant écrasement...
 info ✅ Success: WordPress database upgraded successfully from db version 57155 to 58975.
```

La sauvegarde va dans `{{transfer_backup_dir}}` et n'est jamais supprimée automatiquement,
comme celles des tâches `push`. Si elle est demandée mais impossible — WordPress inutilisable
sur l'hôte — le schéma est laissé inchangé plutôt que mis à jour sans filet.

**Aucune écriture ne peut avoir lieu sans réponse explicite.** En `-n` la question n'est pas
posée du tout. Et si elle est posée sans que personne ne puisse y répondre — `dep deploy`
lancé par un cron ou un runner CI, sans TTY mais sans `-n` — Symfony retombe sur la réponse
par défaut, qui est « non » précisément pour cette raison. Ne rien faire est sûr : WordPress
déclenche lui-même cette mise à jour à la première visite de wp-admin. La tâche est un
confort, pas une condition de correction, et reste lançable seule :

```bash
dep wp:update-db production
```

Pour la même raison, un échec de la mise à jour **n'échoue pas le déploiement** : la release
est publiée et le site en ligne. Un avertissement rappelle la commande à relancer.

Pour la désarmer sur un projet qui gère ses propres migrations : `set('transfer_update_db',
false)`, **après** le `require_once` comme toute surcharge. La clé se surcharge aussi par
hôte depuis l'inventaire (`transfer_update_db: false`).

Deux limites à connaître :

- **Elle fait résoudre `bin/wp` à chaque déploiement.** Un projet qui n'utilisait le recipe
  que pour `db:pull` verra donc son `dep deploy` chercher WP-CLI sur le serveur — et, avec le
  défaut du recipe, **télécharger `wp-cli.phar`** dans `{{deploy_path}}/.dep/` s'il n'y en a
  ni de global ni de déjà téléchargé. C'est la seule écriture distante que la tâche provoque
  hors mise à jour de schéma ; définir `bin/wp` sur un binaire existant l'évite, comme
  `set('transfer_update_db', false)`.
- **Un déploiement multi-hôtes pose la question par hôte**, et lancerait autant de
  `core update-db` concurrents si les nœuds partagent une base. La tâche n'est
  volontairement pas `once()` — des hôtes de stages distincts ont bien des bases distinctes,
  et Deployer ne sait pas exprimer « une fois par base ». Sur un cluster de nœuds web
  partageant une base, désarmez la clé et lancez `dep wp:update-db <un seul hôte>`.

## Sûreté

- **Un `pull` ne modifie jamais l'environnement distant**, hormis ses propres fichiers
  temporaires. Toutes les commandes WP-CLI tournent avec `--skip-plugins --skip-themes`,
  ce qui évite qu'une routine de mise à jour de plugin ne se déclenche au passage.
  À savoir : **les mu-plugins échappent à `--skip-plugins`**, WordPress les chargeant
  inconditionnellement. Leur code s'exécute donc malgré tout.
- **Un `push` demande toujours confirmation** de la destination. Si l'hôte est protégé
  (`transfer_protected`, vrai par défaut dès que l'alias ou le stage contient `prod`), il
  faut saisir son alias en clair : un simple `[y/N]` est trop facile à valider par réflexe.
- **La base de destination est dumpée avant tout import**, dans `{{transfer_backup_dir}}`.
  Ce dump est conservé : il survit au nettoyage.
- **Rien n'est vidé tant que l'import n'est pas jouable.** Avant le `db reset`, le recipe
  vérifie que la commande `wp db` existe et que les collations du dump sont acceptées par
  la destination. Sans ces contrôles, l'échec survient sur une base déjà vide.
- **Toute écriture volumineuse liée aux uploads est confirmée**, en annonçant le volume :
  la création de l'archive `tar.gz` sur le serveur (`uploads:pull`), et la copie de transit
  sur la machine locale quand `uploads:push` relie deux serveurs distincts. Un refus
  n'écrit rien, la question précédant la création du répertoire de travail. Les tâches de
  base de données ne posent pas cette question : leurs dumps sont d'un autre ordre de
  grandeur.
- **`wp:update-db` n'écrit qu'après une réponse explicite**, et la sauvegarde préalable
  demandée conditionne la mise à jour : si elle échoue, le schéma est laissé inchangé. Un
  échec de la mise à jour n'échoue pas le déploiement, la release étant déjà publiée.
- **Aucune trace en cas d'échec.** Les fichiers temporaires vivent dans un répertoire de
  travail unique par environnement, supprimé en fin de tâche et via `fail()`. Les
  répertoires orphelins d'une exécution interrompue brutalement sont purgés au démarrage
  suivant (au-delà de 24 h).
- **En mode non interactif** (`-n`), aucune écriture n'est effectuée : les tâches `pull`
  conservent le fichier téléchargé, les tâches `push` abandonnent. Seule exception, les
  confirmations d'espace disque ci-dessus : elles passent outre en annonçant le volume,
  puisque bloquer y rendrait `uploads:pull` inutilisable en scripté sans rien protéger de
  durable — l'archive est un fichier de travail, supprimé en fin de tâche.
- **La réponse par défaut d'une question qui écrit est toujours celle qui n'écrit pas.**
  Ce n'est pas une politesse : `-n` n'est pas le seul cas où personne ne peut répondre. Sans
  TTY et sans `-n` — cron, runner CI, `ssh serveur 'dep deploy'` — la question *est* posée,
  Symfony rencontre un EOF sur stdin et retombe silencieusement sur la réponse par défaut.
  C'est pourquoi `transferConfirm()` et `transferConfirmDestination()` refusent par défaut,
  et pourquoi `wp:update-db` pose deux questions à défauts sûrs plutôt qu'un choix unique
  dont le défaut serait la mise à jour.

## Réécriture d'URLs

Après un import, la base contient encore les URLs de la source. La réécriture est proposée
et s'exécute **sur l'environnement qui a reçu la base** (y compris distant), en deux passes :

1. l'URL complète, avec le protocole — `https://prod.tld` → `https://preprod.tld` ;
2. le domaine nu — `prod.tld` → `preprod.tld`.

La seconde passe rattrape ce que la première ne voit pas : `http://`, les URLs
protocol-relative (`//domaine`), celles échappées en JSON dans les blocs Gutenberg
(`https:\/\/domaine`) et les mentions du domaine en clair. L'ordre compte : inverser les
deux passes casserait la première.

> ⚠️ La passe « domaine nu » touche aussi les adresses e-mail du domaine
> (`contact@prod.tld`) et tout chemin de fichier contenant le domaine.

Les tables de logs et de caches de plugins sont exclues par défaut
(`transfer_search_replace_skip_tables`, `*_wf*`) : Wordfence stocke des dizaines de
milliers de chemins de fichiers que la passe « domaine nu » réécrirait inutilement.

### Mémoire et grosses bases

`--precise` n'est **pas** activé par défaut. Cette option force WP-CLI à traiter toutes les
colonnes en PHP, ce qui sature la mémoire sur les grosses tables (`wp_gf_entry` et autres
journaux de plugins). Sans elle, WP-CLI n'emploie PHP que pour les colonnes contenant du
sérialisé — et corrige alors correctement les longueurs `s:NN` — et passe par un `REPLACE()`
SQL ailleurs, exécuté par le moteur de base. Pour l'activer malgré tout :

```bash
dep db:pull production --precise
```

Si une passe échoue par saturation mémoire, la base est partiellement réécrite. Relancer la
commande est sans risque : ce qui est déjà remplacé ne correspond plus. Deux leviers :

```php
// exclure la table fautive
set('transfer_search_replace_skip_tables', '*_wf*,*_gf_entry');

// relever la limite mémoire — WP-CLI étant un phar à shebang, WP_CLI_PHP_ARGS est ignoré,
// il faut invoquer PHP explicitement
set('bin/wp_local', 'php -d memory_limit=-1 /usr/local/bin/wp');
```

## Pourquoi « 0 fichier transféré » ?

`uploads:push` simule le transfert avant d'écrire et annonce ce qui va réellement se
passer :

```
🔍 Simulation avant écriture (aucune modification)...
   0 fichier(s) à transférer, 0 à supprimer à la destination.
✅ Rien à faire : la destination est déjà conforme à la source.
   La comparaison porte sur la taille et la date de modification. Pour comparer
   sur le contenu des fichiers : relancer avec --checksum.
```

**La vérification rapide de `rsync` compare la taille et la date de modification, pas le
contenu.** Un fichier modifié à taille et date identiques est donc ignoré — ce qui arrive
avec des fichiers restaurés d'une sauvegarde en préservant leurs dates. `--checksum`
compare le contenu octet par octet ; c'est nettement plus lent (lecture intégrale des deux
côtés) mais insensible aux dates.

```bash
dep uploads:push --from=production --to=staging --checksum
```

La simulation affiche sa propre progression, utile quand `--checksum` doit relire
plusieurs gigaoctets des deux côtés :

```
🔍 Simulation avant écriture (aucune modification, progression ci-dessous)...
   … 400 fichiers analysés sur ~4181
   … 800 fichiers analysés sur ~4181
   ...
   → 1 fichier(s) à transférer, 1 à supprimer à la destination.
```

Le nombre de fichiers annoncé pour la source et la destination peut légitimement différer :
en fusion, les fichiers présents uniquement à la destination sont conservés. Le mode miroir
les supprime, et la simulation en donne le compte avant confirmation.

## « Unknown collation » à l'import

Avant de vider quoi que ce soit, le recipe compare les collations du dump à celles que
la destination accepte. S'il en manque, il refuse et n'a **rien supprimé** :

```
🔎 Contrôle des collations du dump...
Le dump utilise des collations absentes de « local » : utf8mb4_0900_ai_ci.
   Rien n'a été supprimé : l'import aurait échoué en cours de route, sur une
   base déjà vidée. Une collation « utf8mb4_0900_* » vient de MySQL 8, que
   MariaDB n'implémente dans aucune version.
```

Le cas type est une production sous MySQL 8 et un local sous MariaDB — le moteur par
défaut de DDEV. Aucune version de MariaDB n'acceptera ces collations : mettre le local à
jour ne change rien. Deux issues.

**Réécrire les collations à la volée**, quand la table concernée ne porte pas de contenu
éditorial (un cache de plugin, typiquement) :

```bash
dep db:pull production --fix-collations
```

La substitution se fait dans le tube, à charset identique — `utf8mb4_0900_ai_ci` devient
`utf8mb4_unicode_ci` : le fichier de dump n'est pas modifié et reste réimportable ailleurs.
Chaque réécriture est annoncée. À savoir : le filtre porte sur tout le flux, donc une
occurrence du nom de la collation à l'intérieur d'une donnée serait réécrite elle aussi.

**Aligner le moteur local sur la production**, la solution de fond, qui règle aussi les
écarts de `sql_mode` et de fonctions JSON :

```bash
ddev config --database=mysql:8.0 && ddev restart
```

`.ddev/config.yaml` étant versionné, ce choix engage l'équipe : chacun·e devra recréer sa
base locale.

## Récupérer le seul favicon

Un dossier uploads pèse couramment plusieurs gigaoctets, alors que rendre un onglet de
navigateur conforme au site en réclame quelques dizaines de kilo-octets :

```bash
dep uploads:pull production --favicon-only
```

L'option lit l'attachment désigné par `site_icon` et récupère l'original **avec ses
déclinaisons** — 32×32 pour l'onglet, 180×180 pour Apple, 192×192 pour Android. Ce sont
elles que le navigateur demande, pas l'original : ne rapatrier que ce dernier laisserait
l'onglet vide.

```
🎨 Favicon trouvé dans la base locale :
   2022/06/cropped-favicon.png
   2022/06/cropped-favicon-32x32.png
   2022/06/cropped-favicon-180x180.png
   …
Écrire ces 7 fichier(s) dans ./web/app/uploads ? [Y/n]
```

L'option `site_icon` est lue **dans la base locale d'abord**, avec repli sur la base
distante : c'est la base locale qui sert le site, donc c'est elle qui détermine le fichier
réclamé par le navigateur. Le repli permet d'utiliser l'option avant tout `db:pull`.

Aucune archive n'est créée sur le serveur et le dossier uploads distant n'est pas parcouru.
Un favicon posé hors médiathèque — `favicon.ico` à la racine, option de thème — n'est pas
concerné : il ne vit pas dans les uploads.

⚠️ **Ciblez l'hôte dont provient votre base.** Un identifiant de média ne désigne pas le
même fichier d'un site à l'autre : avec une base importée de `production` et un
`uploads:pull staging --favicon-only`, le fichier attendu n'existe pas à la destination.
Le cas est détecté et signalé plutôt qu'annoncé comme un succès.

## Pourquoi « WordPress est inutilisable » ?

Avant tout export, le recipe vérifie que WP-CLI parvient à charger WordPress. En cas
d'échec, il rapporte ce que WP-CLI a répondu, le binaire employé et le répertoire depuis
lequel la commande a tourné :

```
WordPress est inutilisable sur « production » : export impossible.
   WP-CLI : Error: This does not seem to be a WordPress installation.
   WP-CLI : Pass --path=`path/to/wordpress` or run `wp core download`.
   Binaire : wp
   Exécuté depuis : ~/public_html/current
   Piste la plus fréquente : le « path » du wp-cli.yml du projet doit désigner le
   cœur de WordPress — sous Bedrock « web/wp », et non « web ».
```

**Le message ne signifie pas que WordPress est absent** : il signifie que WP-CLI n'a pas
pu le charger. La cause la plus fréquente est un `wp-cli.yml` dont le `path` ne désigne pas
le cœur de WordPress. Sous Bedrock, `wp-config.php` est dans `web/` mais `wp-load.php` est
dans `web/wp` : c'est ce dernier que WP-CLI attend.

```yaml
# wp-cli.yml, à la racine du projet
path: web/wp
```

Ce fichier étant versionné et déployé avec le code, le corriger suppose un déploiement
pour que les tâches distantes en bénéficient. Pour vérifier sans rien déployer :

```bash
dep run 'cd {{current_path}} && {{bin/wp}} core is-installed --no-color; echo "exit=$?"' production
```

Les autres causes possibles, dans l'ordre de fréquence : base de données injoignable depuis
le CLI (identifiants ou socket différents de ceux du web), et `{{bin/wp}}` tournant sous un
PHP incompatible — la ligne « Binaire » indique lequel a été retenu, un `wp` système pouvant
être bien plus ancien que le `bin/php` de l'inventaire.

## Configuration

Surchargeable depuis `deploy.php` ou l'inventaire, **après** le `require_once`.

| Clé | Défaut | Rôle |
|---|---|---|
| `uploads_path` | `web/app/uploads` | Dossier uploads, relatif à la racine du projet et d'une release. Pour un WordPress classique : `wp-content/uploads` |
| `transfer_tmp_dir` | `{{deploy_path}}/.dep/transfer` | Répertoires de travail distants |
| `transfer_local_tmp_dir` | `<racine projet>/.dep/transfer` | Répertoire de travail local |
| `transfer_backup_dir` | `{{deploy_path}}/.dep/backups` | Sauvegardes conservées |
| `transfer_protected` | alias ou stage contenant `prod` | Exige la saisie de l'alias pour écraser l'hôte |
| `transfer_update_db` | `true` | Accroche `wp:update-db` à `deploy:success`. À `false`, la tâche ne fait rien, même lancée à la main |
| `transfer_search_replace_skip_tables` | `*_wf*` | Tables exclues du search-replace (jokers acceptés) |
| `bin/wp_local` | premier `wp` du PATH connaissant `wp db` | WP-CLI local. Un projet qui requiert `wp-cli/wp-cli` (le framework seul, sans les commandes) obtient un proxy dans `vendor/bin` prioritaire dans le PATH : il est écarté au profit d'un phar complet |
| `bin/wp` | phar téléchargé à la demande | WP-CLI distant |
| `transfer_local_prefix` | `ddev exec ` | Préfixe des commandes suggérées, tapées depuis l'hôte. `ddev exec` accepte un chemin absolu de binaire, ce que `ddev` refuse |

Options de ligne de commande : `--from`, `--to`, `--strategy=merge|mirror`, `--checksum`, `--precise`,
`--favicon-only`, `--fix-collations`.

Exemple d'inventaire retirant la protection d'un hôte de recette :

```yaml
hosts:
  preprod:
    deploy_path: /var/www/preprod
    transfer_protected: false
```

## Détails d'implémentation

- **Détection du même serveur.** Quand la source et la destination partagent la même
  chaîne de connexion, aucun transfert réseau n'a lieu : le dump est lu directement, et les
  uploads sont synchronisés par un `rsync` local exécuté sur le serveur. Entre deux
  serveurs distincts, le transit passe par la machine locale.
- **Progression.** La barre de Deployer s'appuie sur le compteur `to-chk` de `rsync`, que
  celui-ci n'émet qu'à la toute fin pour un fichier unique. Le recipe utilise donc
  `--info=progress2`, convertit les `\r` en lignes et n'en affiche qu'une par palier de
  10 %. Les opérations longues sans sortie (export SQL, `tar`) affichent la taille du
  fichier en cours d'écriture toutes les 5 secondes.
- **Symlinks.** Côté distant, `current` pointe vers la release et `uploads` (shared dir)
  vers `shared/`. `find` et `du` ne descendent pas dans un symlink passé en point de
  départ et renverraient 0 : le chemin réel est résolu explicitement.
- **`deploy_path` en `~/…`.** Deployer accepte un chemin relatif au HOME parce qu'il ne
  quote pas ses `cd`. Le recipe, lui, protège tous ses chemins par `escapeshellarg()`, et
  un `~` entre quotes simples n'est pas expansé par le shell : il est donc résolu contre le
  `$HOME` de l'hôte, une fois par environnement. Sans cela, le recipe créerait un
  répertoire littéralement nommé `~` et ne trouverait pas le dossier uploads.
- **Codes de sortie.** Les commandes longues tournent en tâche de fond avec propagation
  via `wait`, et les pipes utilisent `set -o pipefail` — sans quoi un `gunzip` en échec
  laisserait WP-CLI annoncer un import réussi sur un flux vide.
- **Point d'accroche de `wp:update-db`.** `deploy:success` et non `deploy:symlink`, bien que
  les deux voient le même symlink déjà basculé : la question ne doit pas être posée tant que
  le verrou de déploiement est tenu, sinon un `Ctrl-C` dessus laisse le projet verrouillé.
  `deploy:success` clôt `deploy:publish`, après `deploy:unlock`. C'est aussi une tâche cachée
  que personne ne lance à la main, contrairement à `deploy:unlock` qu'on invoque justement
  pour débloquer un déploiement interrompu. Un déploiement en échec n'y passe pas non plus :
  il sort par `fail('deploy', 'deploy:failed')`, une branche qui ne traverse jamais
  `deploy:success`. Attention en revanche à ne pas s'appuyer sur l'idée que `deploy:failed`
  serait vide — c'est vrai du `recipe/common.php` nu, mais la plupart des projets y
  accrochent `deploy:unlock` et des actions de récupération.
- **État du nettoyage.** Deployer exécute chaque tâche dans un processus distinct : une
  tâche `fail()` ne voit pas les variables statiques de la tâche qui a échoué. La liste des
  répertoires à nettoyer est donc persistée dans `<racine projet>/.dep/transfer-state.json`.

## Licence

MIT
