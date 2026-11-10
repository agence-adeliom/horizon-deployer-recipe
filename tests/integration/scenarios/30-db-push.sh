#!/bin/bash
# db:push : local → distant, distant → distant (serveurs distincts, transit par le local),
# même serveur (aucun transfert), confirmation et refus, hôte protégé (saisie de l'alias),
# mode -n, sauvegarde de la destination avant import, commandes avant / après import,
# nettoyage en cas d'échec.
source /tests/lib/common.sh
reset_all

backups() { remote_count "$1" "${DPATH[$1]}/.dep/backups/$1-*.sql.gz"; }

log "db:push local → staging, confirmé"
dep_run 'y\ny\n' db:push staging --from=local --to=staging
assert_success
assert_contains "$OUT" "Écraser les données de « staging »" "confirmation [y/N] pour un hôte non protégé"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" local "staging a reçu la base locale"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='home'")" http://staging.test "URLs réécrites sur la destination"
assert_eq "$(sql staging "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%local.test%'")" 0 "plus aucune mention du domaine local"
assert_eq "$(backups staging)" 1 "sauvegarde de staging conservée avant import"
backup=$(on_host staging "ls ${DPATH[staging]}/.dep/backups/staging-*.sql.gz")
assert_contains "$(on_host staging "gunzip -c $backup | grep -c 'Marqueur staging'")" 1 "la sauvegarde contient l'ancienne base de staging"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" local "la source n'est pas modifiée"
assert_clean_workdirs staging

log "db:push local → staging, refusé (Entrée)"
reset_all
dep_run '\n' db:push staging --from=local --to=staging
assert_success
assert_contains "$OUT" "Abandon, aucune modification" "abandon annoncé"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" staging "staging inchangée"
assert_eq "$(backups staging)" 0 "aucune sauvegarde ni écriture"

log "db:push -n : aucune écriture"
dep_run '' db:push staging --from=local --to=staging -n
assert_success
assert_contains "$OUT" "Mode non interactif : aucune écriture sur « staging »" "refus en -n"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" staging "staging inchangée"

log "db:push sans TTY ni -n, stdin vide : défaut « non »"
dep_run '' db:push staging --from=local --to=staging
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" staging "EOF sur stdin : rien n'est écrit"

log "db:push -n sans --from : refus explicite"
dep_run '' db:push staging -n
assert_failure
assert_contains "$OUT" "--from est obligatoire en mode non interactif" "message d'erreur"

log "db:push vers un environnement inconnu ou vers local"
dep_run '' db:push staging --from=local --to=stagign -n
assert_failure
assert_contains "$OUT" "Environnement « stagign » inconnu" "faute de frappe détectée"
dep_run '' db:push staging --from=recette --to=local -n
assert_failure
assert_contains "$OUT" "n'est pas une destination valide" "local refusé comme destination"

log "db:push production → staging (serveurs distincts, sélecteur d'hôte comme destination)"
reset_all
dep_run 'y\ny\n' db:push staging --from=production
assert_success
assert_contains "$OUT" "Destination : staging (hôte sélectionné)" "le sélecteur fait office de --to"
assert_contains "$OUT" "Serveurs distincts : production → local" "transit par le local"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" production "staging a reçu la base de production"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='home'")" http://staging.test "URLs réécrites (https → http)"
assert_contains "$(sql staging "SELECT post_content FROM wp_posts WHERE post_title='Marqueur production'")" "contact@staging.test" "domaine nu réécrit"
assert_clean_workdirs production staging

log "db:push staging → recette (même serveur)"
reset_all
dep_run 'y\ny\n' db:push recette --from=staging --to=recette
assert_success
assert_contains "$OUT" "(même serveur)" "même serveur détecté"
assert_contains "$OUT" "Même serveur : aucun transfert réseau nécessaire" "aucun transfert"
assert_eq "$(sql recette "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" staging "recette a reçu la base de staging"
assert_eq "$(sql recette "SELECT option_value FROM wp_options WHERE option_name='home'")" http://recette.test "URLs réécrites"
assert_eq "$(backups recette)" 1 "sauvegarde de recette"
assert_clean_workdirs staging recette

log "db:push vers la production (protégée), mauvaise saisie"
reset_all
dep_run 'y\n' db:push production --from=staging --to=production
assert_success
assert_contains "$OUT" "« production » est un environnement PROTÉGÉ" "avertissement d'hôte protégé"
assert_contains "$OUT" "Saisie incorrecte" "un « y » ne suffit pas"
assert_eq "$(sql production "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" production "production inchangée"
assert_eq "$(backups production)" 0 "aucune sauvegarde ni écriture"

log "db:push vers la production, alias saisi"
dep_run 'production\ny\n' db:push production --from=staging --to=production
assert_success
assert_eq "$(sql production "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" staging "production écrasée après saisie de l'alias"
assert_eq "$(sql production "SELECT option_value FROM wp_options WHERE option_name='home'")" https://production.test "URLs réécrites vers la production"
assert_eq "$(backups production)" 1 "sauvegarde de la production"

log "db:push, réécriture d'URLs refusée : commandes à lancer affichées"
reset_all
dep_run 'y\nn\n' db:push staging --from=local --to=staging
assert_success
assert_contains "$OUT" "URLs laissées en place" "réécriture refusée"
assert_contains "$OUT" "search-replace 'http://local.test' 'http://staging.test'" "commande résolue dans le contexte de l'hôte"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='home'")" http://local.test "URLs non réécrites"

log "db:push, commandes avant et après import (transfer_before_import / transfer_after_import)"
reset_all
HDR_BEFORE_IMPORT='option update hdr_hook_avant oui' HDR_AFTER_IMPORT='option update hdr_hook_apres oui' \
    dep_run 'y\ny\n' db:push staging --from=local --to=staging
assert_success
assert_contains "$OUT" "transfer_before_import sur « staging »" "hook avant lancé"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='hdr_hook_apres'")" oui "hook après lancé sur la base importée"
assert_eq "$(sql staging "SELECT COUNT(*) FROM wp_options WHERE option_name='hdr_hook_avant'")" 0 "le hook avant a tourné sur l'ancienne base (écrasée ensuite)"
assert_eq "$(on_host staging "gunzip -c ${DPATH[staging]}/.dep/backups/staging-*.sql.gz | grep -c hdr_hook_avant")" 1 "la sauvegarde contient l'effet du hook avant"

log "db:push, échec du hook avant : import annulé et répertoires de travail nettoyés"
reset_all
HDR_BEFORE_IMPORT='commande-inexistante' dep_run 'y\n' db:push staging --from=local --to=staging
assert_failure
assert_contains "$OUT" "import annulé" "annulation annoncée"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" staging "staging inchangée"
assert_clean_workdirs staging

finish
