#!/bin/bash
# Mise en place de l'environnement, une fois par run (lancé par run.sh dans le conteneur local) :
# projet Bedrock minimal versionné dans un dépôt git partagé, recipe installé par un dépôt Composer
# « path », premier déploiement sur les cinq hôtes, WordPress installé partout avec des URLs
# différentes, contenu et uploads de référence, puis instantanés des bases.
source /tests/lib/common.sh
set -e

log "Attente des serveurs SSH"
for server in server1 server2; do
    for _ in $(seq 1 60); do
        on_server "$server" true 2>/dev/null && break
        sleep 1
    done
    on_server "$server" true
done

log "Projet de test (Deployer ${DEPLOYER_VERSION})"
cd "$PROJECT"
find "$PROJECT" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
cp -R /tests/project/. "$PROJECT/"
mv "$PROJECT/gitignore" "$PROJECT/.gitignore"
sed "s/__DEPLOYER_CONSTRAINT__/^${DEPLOYER_VERSION}.0/" "$PROJECT/composer.json.tpl" >"$PROJECT/composer.json"
rm "$PROJECT/composer.json.tpl"
composer install --no-interaction --no-progress --quiet
test -L vendor/agence-adeliom/horizon-deployer-recipe
vendor/bin/dep --version

git config --global user.email integration@example.test
git config --global user.name Integration
git config --global init.defaultBranch main
git config --global advice.detachedHead false
rm -rf /srv/git/project.git
git init -q --bare /srv/git/project.git
git init -q
git add -A
git commit -qm "Projet de test"
git tag base
git remote add origin /srv/git/project.git
git push -q origin main

log "WordPress local"
cp -R /opt/wp-core "$PROJECT/web/wp"
write_env local
wp core install --url="${URL[local]}" --title="Site local" --admin_user=admin --admin_password=admin \
    --admin_email=admin@local.test --skip-email --quiet
sql local "UPDATE wp_options SET option_value='${URL[local]}' WHERE option_name='home'"
HDR_MARKER=local wp eval-file /tests/lib/seed.php
seed_uploads local

log "Serveurs : dossiers partagés, cœur WordPress et .env"
for alias in "${REMOTES[@]}"; do
    path=${DPATH[$alias]}
    on_host "$alias" "rm -rf $path && mkdir -p $path/shared/web/app/uploads && cp -R /opt/wp-core $path/shared/web/wp"
    # Multisite activé après la conversion du réseau, plus bas.
    HDR_NO_MULTISITE=1 write_env "$alias"
done

log "Premier déploiement sur tous les hôtes"
# Sans sauvegarde : au tout premier déploiement, deploy:db-backup échoue (bug couvert par le
# scénario 50-deploy-premier).
(cd "$PROJECT" && HDR_BACKUP_ON_DEPLOY=0 vendor/bin/dep --no-ansi deploy "${REMOTES[@]}" -n)

log "Installation de WordPress sur les hôtes"
for alias in "${REMOTES[@]}"; do
    domain=${URL[$alias]#*://}
    wp_on "$alias" core install --url="${URL[$alias]}" --title="Site $alias" --admin_user=admin \
        --admin_password=admin --admin_email="admin@$domain" --skip-email --quiet
    # WP_SITEURL (…/wp) a servi aux deux options : home reprend l'URL publique, comme sous Bedrock
    # (en SQL : WP_HOME étant défini, `wp option update home` ne voit aucun changement).
    sql "$alias" "UPDATE wp_options SET option_value='${URL[$alias]}' WHERE option_name='home'"
done

for alias in "${SINGLES[@]}"; do
    favicon=0
    [ "$alias" = production ] && favicon=1
    on_host "$alias" "cd ${DPATH[$alias]}/current && HDR_MARKER=$alias HDR_FAVICON=$favicon wp eval-file -" </tests/lib/seed.php
    seed_uploads "$alias"
done

log "Réseaux multisite reseau-a et reseau-b"
# reseau-a : blog 2 en sous-domaine du réseau, blog 3 sur un domaine propre, blog 4 sur un domaine
# sans équivalent sur reseau-b. reseau-b : mêmes blogs 2 et 3, pas de blog 4.
for alias in reseau-a reseau-b; do
    suffix=${alias#reseau-}
    wp_on "$alias" core multisite-convert --subdomains --skip-config --base=/ --quiet
    write_env "$alias"
    wp_on "$alias" site create --slug=blog1 --title="Blog 1" --quiet
    wp_on "$alias" site create --slug=marque --title="Marque" --quiet
    sql "$alias" "UPDATE wp_blogs SET domain='www.marque-$suffix.test' WHERE blog_id=3"
    sql "$alias" "UPDATE wp_3_options SET option_value='http://www.marque-$suffix.test' WHERE option_name IN ('home','siteurl')"
    if [ "$alias" = reseau-a ]; then
        wp_on "$alias" site create --slug=autre --title="Autre" --quiet
        sql "$alias" "UPDATE wp_blogs SET domain='autre.example' WHERE blog_id=4"
        sql "$alias" "UPDATE wp_4_options SET option_value='http://autre.example' WHERE option_name IN ('home','siteurl')"
    fi
    for blog in $(sql "$alias" "SELECT blog_id FROM wp_blogs ORDER BY blog_id"); do
        domain=$(sql "$alias" "SELECT domain FROM wp_blogs WHERE blog_id=$blog")
        table=wp_posts
        [ "$blog" != 1 ] && table="wp_${blog}_posts"
        sql "$alias" "INSERT INTO $table (post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_type) VALUES (1, NOW(), NOW(), 'Lien http://$domain/page/', 'Marqueur $alias blog $blog', '', 'publish', 'marqueur', '', '', NOW(), NOW(), '', 'post')"
    done
    seed_uploads "$alias"
done

log "Instantanés des bases"
mkdir -p "$SNAPSHOTS"
for alias in local "${REMOTES[@]}"; do
    mysqldump -h db -uwp -pwp --single-transaction "${DBNAME[$alias]}" >"$SNAPSHOTS/${DBNAME[$alias]}.sql" 2>/dev/null
done

# Variante de la production dont les rôles s'écartent de l'empreinte de la déclaration fictive
# (rôle supplémentaire, cap modifiée), créée avant tout déploiement du mu-plugin.
wp_on production role create intrus Intrus --quiet
wp_on production cap remove editor edit_others_posts --quiet
mysqldump -h db -uwp -pwp --single-transaction production >"$SNAPSHOTS/production-ecart.sql" 2>/dev/null
restore_db production production

log "Environnement prêt"
