#!/bin/bash
# Multisite : db:push d'un réseau en sous-domaines (reseau-a, server2) vers un autre (reseau-b,
# server1). Chaque domaine reçoit sa correspondance : domaine du réseau, sous-domaine du réseau
# (couvert par la passe du réseau), même blog_id sur la destination avant l'import, et saisie
# libre pour un domaine sans équivalent.
source /tests/lib/common.sh
reset_all

domain_of() { sql reseau-b "SELECT domain FROM wp_blogs WHERE blog_id=$1"; }

log "db:push reseau-a → reseau-b"
dep_run 'y\nautre-b.test\ny\n' db:push reseau-b --from=reseau-a --to=reseau-b
assert_success
assert_contains "$OUT" "Multisite : réseau reseau-a.test → reseau-b.test" "réseau détecté"
assert_contains "$OUT" "(domaine du réseau)" "règle : domaine du réseau"
assert_contains "$OUT" "blog1.reseau-a.test → blog1.reseau-b.test (sous-domaine du réseau)" "règle : sous-domaine du réseau"
assert_contains "$OUT" "www.marque-a.test → www.marque-b.test (blog 3 avant import)" "règle : même blog_id avant import"
assert_contains "$OUT" "Domaine sur « reseau-b » pour « autre.example » (blog 4)" "saisie demandée pour un domaine sans équivalent"
assert_eq "$(sql reseau-b "SELECT domain FROM wp_site WHERE id=1")" reseau-b.test "wp_site réécrit"
assert_eq "$(domain_of 1)" reseau-b.test "blog 1"
assert_eq "$(domain_of 2)" blog1.reseau-b.test "blog 2"
assert_eq "$(domain_of 3)" www.marque-b.test "blog 3"
assert_eq "$(domain_of 4)" autre-b.test "blog 4 (saisi)"
assert_contains "$(sql reseau-b "SELECT post_content FROM wp_3_posts WHERE post_title LIKE 'Marqueur%'")" "http://www.marque-b.test/page/" "contenu du blog 3 réécrit"
assert_contains "$(sql reseau-b "SELECT post_title FROM wp_4_posts WHERE post_title LIKE 'Marqueur%'")" "Marqueur reseau-a blog 4" "le blog 4 vient de reseau-a"
assert_eq "$(sql reseau-b "SELECT COUNT(*) FROM wp_2_posts WHERE post_content LIKE '%reseau-a.test%'")" 0 "plus aucun domaine source dans le blog 2"
sites=$(wp_on reseau-b site list --field=url 2>&1)
assert_contains "$sites" "http://www.marque-b.test/" "WordPress démarre sur le réseau réécrit"
assert_clean_workdirs reseau-a reseau-b

finish
