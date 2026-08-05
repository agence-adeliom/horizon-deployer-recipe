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
| `dep uploads:pull <hôte>` | distant → local | Archive `.tar.gz` à la racine du projet, puis propose l'extraction |
| `dep db:push --from=X --to=Y` | local\|distant → distant | Sauvegarde la destination, importe, puis propose la réécriture d'URLs |
| `dep uploads:push --from=X --to=Y` | local\|distant → distant | Synchronise, au choix en fusion ou en miroir |

Un « environnement » est soit `local`, soit l'alias d'un hôte Deployer.

```bash
# Rapatrier la production en local
dep db:pull production
dep uploads:pull production

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
- **Toute écriture volumineuse liée aux uploads est confirmée**, en annonçant le volume :
  la création de l'archive `tar.gz` sur le serveur (`uploads:pull`), et la copie de transit
  sur la machine locale quand `uploads:push` relie deux serveurs distincts. Un refus
  n'écrit rien, la question précédant la création du répertoire de travail. Les tâches de
  base de données ne posent pas cette question : leurs dumps sont d'un autre ordre de
  grandeur.
- **Aucune trace en cas d'échec.** Les fichiers temporaires vivent dans un répertoire de
  travail unique par environnement, supprimé en fin de tâche et via `fail()`. Les
  répertoires orphelins d'une exécution interrompue brutalement sont purgés au démarrage
  suivant (au-delà de 24 h).
- **En mode non interactif** (`-n`), aucune écriture n'est effectuée : les tâches `pull`
  conservent le fichier téléchargé, les tâches `push` abandonnent. Seule exception, les
  confirmations d'espace disque ci-dessus : elles passent outre en annonçant le volume,
  puisque bloquer y rendrait `uploads:pull` inutilisable en scripté sans rien protéger de
  durable — l'archive est un fichier de travail, supprimé en fin de tâche.

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
| `transfer_search_replace_skip_tables` | `*_wf*` | Tables exclues du search-replace (jokers acceptés) |
| `bin/wp_local` | premier `wp` du PATH connaissant `wp db` | WP-CLI local. Un projet qui requiert `wp-cli/wp-cli` (le framework seul, sans les commandes) obtient un proxy dans `vendor/bin` prioritaire dans le PATH : il est écarté au profit d'un phar complet |
| `bin/wp` | phar téléchargé à la demande | WP-CLI distant |
| `transfer_local_prefix` | `ddev exec ` | Préfixe des commandes suggérées, tapées depuis l'hôte. `ddev exec` accepte un chemin absolu de binaire, ce que `ddev` refuse |

Options de ligne de commande : `--from`, `--to`, `--strategy=merge|mirror`, `--checksum`, `--precise`.

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
- **État du nettoyage.** Deployer exécute chaque tâche dans un processus distinct : une
  tâche `fail()` ne voit pas les variables statiques de la tâche qui a échoué. La liste des
  répertoires à nettoyer est donc persistée dans `<racine projet>/.dep/transfer-state.json`.

## Licence

MIT
