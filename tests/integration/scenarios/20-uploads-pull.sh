#!/bin/bash
# uploads:pull : archive distante, téléchargement, extraction en fusion ; mode -n (archive gardée,
# rien d'extrait) ; refus de l'archive ; réutilisation d'une archive ; --favicon-only (base locale
# puis repli sur la base distante, fichier introuvable, refus).
source /tests/lib/common.sh
reset_all
uploads=$PROJECT/web/app/uploads

log "uploads:pull production, archive et extraction acceptées"
dep_run 'y\ny\n' uploads:pull production
assert_success
assert_contains "$OUT" "Créer l'archive sur « production »" "le volume à écrire sur le serveur est confirmé"
assert_local_file "$uploads/2024/01/photo-production.jpg" "fichier de production extrait"
assert_local_file "$uploads/2024/01/nom avec espace.txt" "nom de fichier avec espace extrait"
assert_local_file "$uploads/local/seulement-local.txt" "fusion : le fichier local est conservé"
assert_eq "$(ls "$PROJECT"/uploads-production-*.tar.gz 2>/dev/null | wc -l | tr -d ' ')" 0 "archive locale supprimée après extraction"
assert_clean_workdirs production

log "uploads:pull -n : archive téléchargée et conservée, rien d'extrait"
reset_all
dep_run '' uploads:pull production -n
assert_success
assert_contains "$OUT" "Mode non interactif : poursuite sans confirmation" "l'archive est créée sans confirmation en -n"
assert_contains "$OUT" "n'a pas été touché" "extraction ignorée"
assert_no_local_file "$uploads/2024/01/photo-production.jpg" "rien n'est extrait"
assert_eq "$(ls "$PROJECT"/uploads-production-*.tar.gz 2>/dev/null | wc -l | tr -d ' ')" 1 "archive conservée"
assert_clean_workdirs production

log "uploads:pull, archive récente réutilisée puis extraite"
dep_run 'y\ny\n' uploads:pull production
assert_success
assert_contains "$OUT" "Archive réutilisée" "archive réutilisée"
assert_not_contains "$OUT" "Création de l'archive sur le serveur" "aucune archive créée sur le serveur"
assert_local_file "$uploads/2024/01/photo-production.jpg" "extraction de l'archive réutilisée"

log "uploads:pull, création de l'archive refusée"
reset_all
dep_run 'n\n' uploads:pull production
assert_success
assert_contains "$OUT" "rien n'a été écrit sur le serveur" "abandon annoncé"
assert_no_remote_file production "${DPATH[production]}/.dep/transfer" "aucun répertoire créé sur le serveur"
assert_eq "$(ls "$PROJECT"/uploads-production-*.tar.gz 2>/dev/null | wc -l | tr -d ' ')" 0 "aucune archive téléchargée"

log "uploads:pull --favicon-only, site_icon lu dans la base distante (base locale sans favicon)"
reset_all
dep_run 'y\n' uploads:pull production --favicon-only
assert_success
assert_contains "$OUT" "Favicon référencé par la base de « production »" "repli sur la base distante"
for f in cropped-icone.png cropped-icone-32x32.png cropped-icone-180x180.png cropped-icone-192x192.png; do
    assert_local_file "$uploads/2024/01/$f" "favicon récupéré : $f"
done
assert_no_local_file "$uploads/2024/01/photo-production.jpg" "les autres uploads ne sont pas récupérés"
assert_no_remote_file production "${DPATH[production]}/.dep/transfer" "aucune archive créée sur le serveur"

log "uploads:pull --favicon-only, écriture refusée"
reset_all
dep_run 'n\n' uploads:pull production --favicon-only
assert_success
assert_contains "$OUT" "Rien n'a été écrit" "refus respecté"
assert_no_local_file "$uploads/2024/01/cropped-icone.png" "aucun fichier écrit"

log "uploads:pull --favicon-only depuis un hôte qui n'a pas le fichier (base locale = production)"
reset_all
restore_db local production
dep_run 'y\n' uploads:pull staging --favicon-only
assert_success
assert_contains "$OUT" "Favicon référencé par la base locale" "la base locale est lue en premier"
assert_contains "$OUT" "introuvable sur « staging »" "fichier absent de l'hôte signalé"
assert_no_local_file "$uploads/2024/01/cropped-icone.png" "rien n'est annoncé comme récupéré"

log "uploads:pull --favicon-only, aucun favicon défini"
reset_all
dep_run '' uploads:pull staging --favicon-only
assert_success
assert_contains "$OUT" "Aucun favicon défini" "absence de favicon signalée"

finish
