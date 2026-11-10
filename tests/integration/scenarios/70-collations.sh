#!/bin/bash
# Collations : un dump MySQL 8 (utf8mb4_0900_ai_ci) importé dans MariaDB 10.11. Sans option,
# refus AVANT toute suppression de table ; avec --fix-collations, réécriture à la volée dans le
# tube, le fichier de dump restant intact. Le dump est fabriqué à partir d'un vrai dump de la
# production, puis proposé à la réutilisation par db:pull.
source /tests/lib/common.sh
reset_all

log "Fabrication d'un dump de production contenant une collation MySQL 8"
dep_run '' db:pull production -n
dump=$(ls "$PROJECT"/db-production-*.sql.gz)
{
    gunzip -c "$dump"
    printf '%s\n' \
        'CREATE TABLE `wp_hdr_mysql8` (`id` int NOT NULL, `nom` varchar(50) COLLATE utf8mb4_0900_ai_ci DEFAULT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;' \
        "INSERT INTO \`wp_hdr_mysql8\` VALUES (1,'Élément');"
} | gzip >"$dump.tmp" && mv "$dump.tmp" "$dump"
checksum=$(md5sum <"$dump")

log "db:pull, réutilisation du dump : collation absente, rien n'est supprimé"
dep_run 'y\ny\n' db:pull production
assert_failure
assert_contains "$OUT" "Le dump utilise des collations absentes de « local » : utf8mb4_0900_ai_ci" "collation absente détectée"
assert_contains "$OUT" "utf8mb4_0900_ai_ci → utf8mb4_unicode_ci" "substitut proposé"
assert_not_contains "$OUT" "Suppression des tables" "aucune table supprimée"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" local "base locale intacte"

log "db:pull --fix-collations : réécriture à la volée"
dep_run 'y\ny\ny\n' db:pull production --fix-collations
assert_success
assert_contains "$OUT" "Collation réécrite : utf8mb4_0900_ai_ci → utf8mb4_unicode_ci" "réécriture annoncée"
assert_eq "$(sql local "SELECT option_value FROM wp_options WHERE option_name='hdr_marker'")" production "dump importé"
assert_eq "$(sql local "SELECT TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA='local' AND TABLE_NAME='wp_hdr_mysql8'")" utf8mb4_unicode_ci "table importée avec la collation de repli"
assert_eq "$(sql local "SELECT nom FROM wp_hdr_mysql8")" "Élément" "données intactes"
assert_eq "$(md5sum <"$dump")" "$checksum" "le fichier de dump n'est pas modifié"

finish
