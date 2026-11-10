#!/bin/bash
# uploads:push : stratégie demandée (fusion / miroir) ou passée en option, local → distant,
# même serveur, hôte protégé, mode -n, simulation « rien à faire » et --checksum. Le cas de deux
# serveurs distincts est dans 41-uploads-push-serveurs-distincts.
source /tests/lib/common.sh
reset_all

remote_uploads() { echo "${DPATH[$1]}/shared/web/app/uploads"; }

log "uploads:push local → staging, fusion choisie à la question"
dep_run 'merge\ny\n' uploads:push staging --from=local --to=staging
assert_success
assert_contains "$OUT" "Stratégie de synchronisation des uploads" "la stratégie est demandée"
assert_contains "$OUT" "1 fichier(s) à transférer, 0 à supprimer" "simulation exacte avant écriture"
assert_remote_file staging "$(remote_uploads staging)/local/seulement-local.txt" "fichier local envoyé"
assert_remote_file staging "$(remote_uploads staging)/2023/05/seulement-staging.txt" "fusion : rien n'est supprimé à la destination"
assert_clean_workdirs staging

log "uploads:push local → staging, miroir choisi à la question"
reset_all
dep_run 'mirror\ny\n' uploads:push staging --from=local --to=staging
assert_success
assert_matches "$OUT" "1 fichier\(s\) à transférer, [1-9][0-9]* à supprimer" "la suppression est annoncée par la simulation"
assert_remote_file staging "$(remote_uploads staging)/local/seulement-local.txt" "fichier local reçu"
assert_no_remote_file staging "$(remote_uploads staging)/2023/05/seulement-staging.txt" "miroir : fichier propre à staging supprimé"
assert_local_file "$PROJECT/web/app/uploads/local/seulement-local.txt" "la source est intacte"
assert_clean_workdirs staging

log "uploads:push staging → recette (même serveur), --strategy=mirror"
reset_all
dep_run 'y\n' uploads:push recette --from=staging --to=recette --strategy=mirror
assert_success
assert_contains "$OUT" "(même serveur)" "même serveur détecté"
assert_not_contains "$OUT" "Stratégie de synchronisation des uploads" "pas de question avec --strategy"
assert_not_contains "$OUT" "Serveurs distincts" "pas de transit local"
assert_remote_file recette "$(remote_uploads recette)/2023/05/seulement-staging.txt" "fichier reçu"
assert_no_remote_file recette "$(remote_uploads recette)/2023/06/seulement-recette.txt" "miroir appliqué"

log "uploads:push, destination déjà conforme : rien à faire, sans question"
dep_run '' uploads:push recette --from=staging --to=recette --strategy=mirror
assert_success
assert_contains "$OUT" "Rien à faire : la destination est déjà conforme" "rien à faire"
assert_contains "$OUT" "relancer avec --checksum" "--checksum suggéré"

log "uploads:push, fichier modifié à taille et date identiques : vu seulement avec --checksum"
reset_all
# staging contient commun.txt (« identique », 2024-01-01) : même taille, même date, autre contenu.
(cd "$PROJECT/web/app/uploads" && echo IDENTIQUE >commun.txt && touch -d '2024-01-01 00:00:00' commun.txt)
dep_run '' uploads:push staging --from=local --to=staging --strategy=merge -n
assert_contains "$OUT" "1 fichier(s) à transférer, 0 à supprimer" "sans --checksum : commun.txt est ignoré"
dep_run '' uploads:push staging --from=local --to=staging --strategy=merge --checksum -n
assert_contains "$OUT" "2 fichier(s) à transférer, 0 à supprimer" "avec --checksum : commun.txt est vu"
assert_contains "$OUT" "Mode non interactif : aucune écriture sur « staging »" "-n : aucune écriture"
assert_eq "$(on_host staging "cat $(remote_uploads staging)/commun.txt")" identique "staging inchangée en -n"
dep_run 'y\n' uploads:push staging --from=local --to=staging --strategy=merge --checksum
assert_success
assert_eq "$(on_host staging "cat $(remote_uploads staging)/commun.txt")" IDENTIQUE "--checksum : fichier remplacé"

log "uploads:push vers la production (protégée)"
reset_all
dep_run 'y\n' uploads:push production --from=local --to=production --strategy=merge
assert_contains "$OUT" "Saisie incorrecte" "un « y » ne suffit pas"
assert_no_remote_file production "$(remote_uploads production)/local/seulement-local.txt" "production inchangée"
dep_run 'production\n' uploads:push production --from=local --to=production --strategy=merge
assert_success
assert_remote_file production "$(remote_uploads production)/local/seulement-local.txt" "écriture après saisie de l'alias"
assert_clean_workdirs production

log "uploads:push --strategy invalide"
dep_run '' uploads:push staging --from=local --to=staging --strategy=tout -n
assert_failure
assert_contains "$OUT" "--strategy accepte « merge » ou « mirror »" "option refusée"

finish
