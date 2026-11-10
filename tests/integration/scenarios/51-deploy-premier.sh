#!/bin/bash
# Tout premier déploiement sur un hôte vierge (hôte « neuf ») : le README annonce qu'en
# l'absence de WordPress en ligne, deploy:db-backup se contente d'un avertissement et que le
# déploiement continue ; wp:update-db ne fait qu'avertir.
source /tests/lib/common.sh
reset_all

path=/srv/neuf
on_server server1 "rm -rf $path && mkdir -p $path/shared/web/app/uploads && cp -R /opt/wp-core $path/shared/web/wp"
printf 'DB_NAME=neuf\nDB_USER=wp\nDB_PASSWORD=wp\nDB_HOST=db\nWP_ENV=staging\nWP_HOME=http://neuf.test\n' |
    on_server server1 "cat > $path/shared/.env"

log "dep deploy neuf (aucune release, aucun WordPress installé)"
dep_run '' deploy neuf

assert_success "le premier déploiement aboutit"
assert_contains "$OUT" "Pas de sauvegarde de « neuf » : aucune release en ligne (premier déploiement)" "sauvegarde sautée avec un avertissement"
assert_contains "$OUT" "Version du schéma de base illisible sur « neuf »" "wp:update-db avertit sans échouer"
assert_eq "$(on_server server1 "readlink $path/current | xargs basename")" 1 "release 1 en ligne"

on_server server1 "rm -rf $path"
finish
