#!/bin/bash
# dep deploy complet : build:check (commit local = commit déployé, commit non poussé, thème
# modifié, tag annoté, désarmement), deploy:db-backup avant la bascule et rotation
# transfer_backup_keep, db:backup manuel, échec de l'export qui arrête le déploiement avant la
# bascule, wp:update-db (oui, non, -n, stdin vide, lancement manuel).
source /tests/lib/common.sh
reset_all

backups_dir=${DPATH[staging]}/.dep/backups

log "dep deploy staging : déploiement nominal"
before=$(release_count staging)
dep_run '' deploy staging
assert_success
assert_contains "$OUT" "Code local = « main »" "build:check confirme le commit"
release=$(current_release staging)
assert_eq "$(release_count staging)" "$((before + 1))" "une release de plus"
assert_eq "$(remote_count staging "$backups_dir/staging-avant-release-$release-*.sql.gz")" 1 "dump avant-release-$release créé avant la bascule"
assert_contains "$OUT" "Schéma de base à jour sur « staging »" "wp:update-db : schéma à jour, aucune question"
assert_no_remote_file staging "${DPATH[staging]}/.dep/deploy.lock" "verrou relâché"
backup=$(on_host staging "ls $backups_dir/staging-avant-release-$release-*.sql.gz")
assert_eq "$(on_host staging "gunzip -c $backup | grep -c 'Marqueur staging'")" 1 "le dump contient la base de staging"

log "dep db:backup staging : dump manuel nommé d'après la release en ligne"
dep_run '' db:backup staging
assert_success
assert_eq "$(remote_count staging "$backups_dir/staging-release-$release-*.sql.gz")" 1 "dump release-$release créé"

log "Rotation : transfer_backup_keep = 2, dumps d'avant import jamais supprimés"
on_host staging "cd $backups_dir && for f in staging-avant-release-0-20200101-000000 staging-release-0-20200102-000000 staging-20200103-000000; do echo x | gzip > \$f.sql.gz; touch -d 2020-01-01 \$f.sql.gz; done"
HDR_BACKUP_KEEP=2 dep_run '' deploy staging
assert_success
assert_contains "$OUT" "sauvegarde(s) ancienne(s) supprimée(s), 2 conservée(s) au plus" "rotation annoncée"
assert_eq "$(remote_count staging "$backups_dir/staging-*release-*.sql.gz")" 2 "deux dumps de release conservés"
assert_no_remote_file staging "$backups_dir/staging-avant-release-0-20200101-000000.sql.gz" "le plus ancien est supprimé"
assert_remote_file staging "$backups_dir/staging-20200103-000000.sql.gz" "le dump d'avant import est conservé"
assert_eq "$(remote_count staging "$backups_dir/staging-avant-release-$(current_release staging)-*.sql.gz")" 1 "le dump du dernier déploiement est conservé"

log "transfer_backup_on_deploy = false : aucun dump"
reset_all
HDR_BACKUP_ON_DEPLOY=0 dep_run '' deploy staging
assert_success
assert_eq "$(remote_count staging "$backups_dir/*.sql.gz")" 0 "aucun dump créé"

log "Export impossible : le déploiement s'arrête avant la bascule"
reset_all
current=$(current_release staging)
before=$(release_count staging)
HDR_BACKUP_DIR=/proc/interdit dep_run '' deploy staging
assert_failure
assert_eq "$(current_release staging)" "$current" "la release en ligne n'a pas changé"
assert_contains "$OUT" "task deploy:db-backup" "échec dans deploy:db-backup"
assert_not_contains "$OUT" "task deploy:symlink" "deploy:symlink n'est pas atteint"

log "build:check : commit local non poussé"
reset_all
before=$(release_count staging)
(cd "$PROJECT" && echo "// local" >>deploy.php && git commit -qam "Commit non poussé")
dep_run '' deploy staging
assert_failure
assert_contains "$OUT" "n'est pas celui qui va être déployé" "écart de commit détecté"
assert_eq "$(release_count staging)" "$before" "aucune release créée"
assert_no_remote_file staging "${DPATH[staging]}/.dep/deploy.lock" "aucun verrou laissé"

log "build:check : thème modifié non commité"
reset_all
echo "/* modif */" >>"$PROJECT/web/app/themes/demo/style.css"
dep_run '' deploy staging
assert_failure
assert_contains "$OUT" "Modifications non commitées dans web/app/themes/demo" "thème sale détecté"
assert_eq "$(release_count staging)" "$before" "aucune release créée"

log "build:check : modification hors des chemins vérifiés tolérée"
reset_all
echo "notes" >"$PROJECT/notes-locales.txt"
dep_run '' build:check staging
assert_success
assert_contains "$OUT" "Code local = « main »" "fichier hors thème ignoré"

log "build:check : tag annoté (déréférencé) et tag sur un autre commit"
reset_all
(cd "$PROJECT" && git tag -a v-test -m "Tag de test" && git push -q origin v-test)
dep_run '' build:check staging --tag=v-test
assert_success
assert_contains "$OUT" "Code local = « v-test »" "tag annoté accepté"
(cd "$PROJECT" && echo "// suite" >>deploy.php && git commit -qam "Après le tag" && git push -q origin main)
dep_run '' build:check staging --tag=v-test
assert_failure
assert_contains "$OUT" "n'est pas celui qui va être déployé" "tag sur un autre commit refusé"
dep_run '' build:check staging --tag=inexistant
assert_failure
assert_contains "$OUT" "introuvable sur le dépôt distant" "cible inconnue"
HDR_BUILD_CHECK=0 dep_run '' build:check staging --tag=v-test
assert_success "build_check = false : contrôle désarmé"
(cd "$PROJECT" && git tag -d v-test >/dev/null && git push -q origin :refs/tags/v-test)

log "wp:update-db : schéma en retard, mise à jour acceptée avec sauvegarde"
reset_all
required=$(wp_on staging eval 'global $wp_db_version; echo $wp_db_version;' --skip-plugins --skip-themes)
sql staging "UPDATE wp_options SET option_value='57155' WHERE option_name='db_version'"
dep_run 'y\ny\n' deploy staging
assert_success
assert_contains "$OUT" "doit passer de la version 57155 à $required" "écart de schéma annoncé"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='db_version'")" "$required" "schéma mis à jour"
assert_eq "$(remote_count staging "$backups_dir/staging-2*.sql.gz")" 1 "sauvegarde d'avant mise à jour conservée"

log "wp:update-db : refus (Entrée), -n et stdin vide n'écrivent rien"
for mode in refus -n eof; do
    reset_all
    sql staging "UPDATE wp_options SET option_value='57155' WHERE option_name='db_version'"
    case $mode in
        refus) dep_run '\n' deploy staging ;;
        -n) dep_run '' deploy staging -n ;;
        eof) dep_run '' deploy staging ;;
    esac
    assert_success "déploiement réussi ($mode)"
    assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='db_version'")" 57155 "schéma inchangé ($mode)"
done
assert_contains "$OUT" "Schéma inchangé" "défaut « non » sur EOF"

log "wp:update-db lancée à la main, puis désarmée"
dep_run 'y\nn\n' wp:update-db staging
assert_success
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='db_version'")" "$required" "mise à jour sans sauvegarde"
assert_eq "$(remote_count staging "$backups_dir/staging-2*.sql.gz")" 0 "aucune sauvegarde demandée"
sql staging "UPDATE wp_options SET option_value='57155' WHERE option_name='db_version'"
HDR_UPDATE_DB=0 dep_run 'y\ny\n' wp:update-db staging
assert_success
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='db_version'")" 57155 "transfer_update_db = false : rien"

finish
