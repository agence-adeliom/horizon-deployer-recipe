#!/bin/bash
# db:pull : export distant, téléchargement, import local et réécriture d'URLs en deux passes,
# mode -n (aucune écriture locale), refus de l'import, réutilisation d'un dump récent (et
# rejet d'un dump tronqué), nettoyage des répertoires de travail.
source /tests/lib/common.sh
reset_all

log "db:pull production, import et réécriture acceptés"
dep_run 'y\ny\n' db:pull production
assert_success
assert_contains "$OUT" "Vider la base de données LOCALE" "la question d'import est posée"
assert_contains "$OUT" "Passe 1/2" "réécriture en deux passes"
assert_contains "$OUT" "URLs réécrites" "réécriture annoncée"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" production "la base locale est celle de la production"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='home'")" http://local.test "home réécrit vers l'URL locale"
content=$(sql local "SELECT post_content FROM wp_posts WHERE post_title='Marqueur production'")
assert_contains "$content" 'href="http://local.test/page-a/"' "lien absolu réécrit (passe URL complète, changement de protocole)"
assert_contains "$content" 'contact@local.test' "adresse e-mail du domaine réécrite (passe domaine nu)"
assert_contains "$content" ':\/\/local.test/app/uploads' "URL échappée en JSON (bloc Gutenberg) réécrite"
assert_eq "$(sql local "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%production.test%'")" 0 "plus aucune mention du domaine source dans wp_posts"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='hdr_serialise'")" 'a:1:{s:4:"lien";s:28:"http://local.test/serialise/";}' "valeur sérialisée réécrite avec sa longueur corrigée"
assert_eq "$(sql local "SELECT url FROM wp_wfhits")" "https://production.test/wp-login.php" "table *_wf* exclue de la réécriture"
dumps=$(ls "$PROJECT"/db-production-*.sql.gz 2>/dev/null | wc -l | tr -d ' ')
assert_eq "$dumps" 1 "le dump est conservé à la racine du projet"
assert_eq "$(sql production "SELECT option_value FROM wp_options WHERE option_name='home'")" https://production.test "la production n'est pas modifiée"
assert_clean_workdirs production

log "db:pull -n : dump téléchargé, base locale intacte"
reset_all
dep_run '' db:pull production -n
assert_success
assert_contains "$OUT" "Mode non interactif (-n) : question ignorée" "question d'import ignorée en -n"
assert_contains "$OUT" "la base locale n'a pas été touchée" "aucune écriture locale annoncée"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" local "base locale intacte"
assert_eq "$(ls "$PROJECT"/db-production-*.sql.gz 2>/dev/null | wc -l | tr -d ' ')" 1 "dump téléchargé"
assert_clean_workdirs production

log "db:pull, import refusé (Entrée = non)"
reset_all
dep_run '\n' db:pull production
assert_success
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" local "Entrée ne déclenche pas l'import"

log "db:pull, réutilisation du dump récent"
# Le dump de l'étape précédente est là : on le réutilise, sans rien créer sur le serveur.
on_host production "rm -rf ${DPATH[production]}/.dep/transfer"
dep_run 'y\ny\ny\n' db:pull production
assert_success
assert_contains "$OUT" "Réutiliser ce fichier" "réutilisation proposée"
assert_contains "$OUT" "Dump réutilisé" "dump réutilisé"
assert_not_contains "$OUT" "Export de la base" "aucun export distant"
assert_no_remote_file production "${DPATH[production]}/.dep/transfer" "rien n'est créé sur le serveur"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" production "base importée depuis le dump réutilisé"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='home'")" http://local.test "URLs réécrites après réutilisation"

log "db:pull, dump récent tronqué : écarté, nouvel export"
reset_all
dep_run '' db:pull production -n
dump=$(ls "$PROJECT"/db-production-*.sql.gz)
head -c 2000 "$dump" >"$dump.tmp" && mv "$dump.tmp" "$dump"
dep_run 'y\nn\n' db:pull production
assert_success
assert_contains "$OUT" "incomplet ou corrompu" "dump tronqué détecté"
assert_contains "$OUT" "Export de la base" "nouvel export lancé"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" local "base locale intacte (import refusé)"

log "db:pull, réutilisation désactivée (transfer_reuse_window = 0)"
HDR_REUSE_WINDOW=0 dep_run 'n\n' db:pull production
assert_success
assert_not_contains "$OUT" "Réutiliser ce fichier" "aucune proposition de réutilisation"

finish
