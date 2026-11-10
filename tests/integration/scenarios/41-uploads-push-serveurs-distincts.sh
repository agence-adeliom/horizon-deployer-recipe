#!/bin/bash
# uploads:push entre deux serveurs distincts (production sur server2 → staging sur server1) :
# rsync n'accepte qu'une extrémité distante, le transit passe par le local, après confirmation
# du volume à écrire en local. La copie de transit précède la simulation, faite entre la copie
# locale et la destination ; la confirmation de la destination vient ensuite.
source /tests/lib/common.sh
reset_all

remote_uploads() { echo "${DPATH[$1]}/shared/web/app/uploads"; }

log "uploads:push production → staging, miroir, transit local confirmé"
dep_run 'mirror\ny\ny\n' uploads:push staging --from=production --to=staging

assert_success
assert_contains "$OUT" "Serveurs distincts : le transit passe par cette machine" "transit local annoncé"
assert_contains "$OUT" "Copier les uploads en local pour le transit" "copie de transit confirmée"
assert_contains "$OUT" "à supprimer à la destination" "simulation affichée"
assert_remote_file staging "$(remote_uploads staging)/2024/01/photo-production.jpg" "fichier de production reçu"
assert_remote_file staging "$(remote_uploads staging)/2024/01/nom avec espace.txt" "nom avec espace reçu"
assert_no_remote_file staging "$(remote_uploads staging)/2023/05/seulement-staging.txt" "miroir : fichier propre à staging supprimé"
assert_remote_file production "$(remote_uploads production)/2024/01/photo-production.jpg" "la source est intacte"
assert_clean_workdirs production staging

log "uploads:push production → staging, transit refusé"
reset_all
dep_run 'merge\nn\n' uploads:push staging --from=production --to=staging
assert_success
assert_contains "$OUT" "aucune modification, ni en local ni à la destination" "abandon annoncé"
assert_no_remote_file staging "$(remote_uploads staging)/2024/01/photo-production.jpg" "rien n'est copié"
assert_clean_workdirs production staging

log "uploads:push production → staging, transit accepté puis destination refusée"
reset_all
dep_run 'merge\ny\nn\n' uploads:push staging --from=production --to=staging
assert_success
assert_contains "$OUT" "Abandon, aucune modification." "abandon annoncé"
assert_no_remote_file staging "$(remote_uploads staging)/2024/01/photo-production.jpg" "rien n'est copié"
assert_clean_workdirs production staging

log "uploads:push staging → production (protégée, serveurs distincts)"
reset_all
dep_run 'y\nproduction\n' uploads:push production --from=staging --to=production --strategy=merge
assert_success
assert_remote_file production "$(remote_uploads production)/2023/05/seulement-staging.txt" "écriture après saisie de l'alias"
assert_clean_workdirs staging production

finish
