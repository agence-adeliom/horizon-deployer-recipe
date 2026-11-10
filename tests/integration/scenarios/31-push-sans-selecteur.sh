#!/bin/bash
# Le sélecteur d'hôte désigne la destination d'un push (forme documentée dans le README).
# --to seul ne dispense pas du sélecteur : avec plusieurs hôtes, Deployer demande « Select hosts »
# avant la tâche, et échoue en -n (« No host selected »). Le README le dit : on vérifie les deux.
source /tests/lib/common.sh
reset_all

log "db:push staging --from=local -n : la forme documentée atteint le refus propre du recipe"
dep_run '' db:push staging --from=local -n
assert_success
assert_contains "$OUT" "Mode non interactif : aucune écriture sur « staging »" "refus propre en -n"

log "db:push staging --from=local : la forme documentée pousse la base"
dep_run 'y\nn\n' db:push staging --from=local
assert_success
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" local "staging a reçu la base locale"
assert_not_contains "$OUT" "Select hosts" "aucune question de sélection d'hôte"

log "db:push --from=local --to=staging -n : sans sélecteur, Deployer s'arrête avant la tâche"
reset_all
dep_run '' db:push --from=local --to=staging -n
assert_failure
assert_contains "$OUT" "No host selected" "comportement de Deployer documenté dans le README"
assert_eq "$(sql staging "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" staging "staging est intacte"

finish
