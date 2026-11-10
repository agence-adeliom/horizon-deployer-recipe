#!/bin/bash
# roles:check : production au sens de WordPress, lue dans shared/.env de l'hôte (WP_ENVIRONMENT_TYPE
# puis WP_ENV, normalisés comme Bedrock), repli sur transfer_protected quand aucune des deux
# variables n'existe. Hôte « recette » (non protégé) et « production » (protégé).
source /tests/lib/common.sh
reset_all
install_mu_plugin conforme.php
commit_push "mu-plugin adeliom-roles"

db_vars() { printf 'DB_NAME=%s\nDB_USER=wp\nDB_PASSWORD=wp\nDB_HOST=db\nWP_HOME=%s\n' "${DBNAME[$1]}" "${URL[$1]}"; }

# check <hôte> <contenu ajouté au .env> <contrôlé : oui|non> <motif attendu> <libellé>
check() {
    local alias=$1 extra=$2 controlled=$3 pattern=$4 label=$5
    write_env "$alias" "$(db_vars "$alias")"$'\n'"$extra"
    dep_run '' roles:check "$alias" -v
    assert_success "$label : roles:check réussit"
    [ -n "$pattern" ] && assert_contains "$OUT" "$pattern" "$label : message"
    if [ "$controlled" = oui ]; then
        assert_contains "$OUT" "conformes à l'empreinte" "$label : empreinte contrôlée"
    else
        assert_not_contains "$OUT" "conformes à l'empreinte" "$label : empreinte non contrôlée"
    fi
}

check recette 'WP_ENV=staging' non "est en environnement « staging » (shared/.env)" "WP_ENV=staging"
check recette 'WP_ENV=production' oui "" "WP_ENV=production"
check recette 'WP_ENV=preprod' oui "WP_ENV « preprod » dans shared/.env de « recette » : valeur vide ou non standard" "WP_ENV=preprod"
check recette 'WP_ENV=' oui "WP_ENV vide dans shared/.env" "WP_ENV vide"
check recette 'export WP_ENV="production"' oui "" "export et guillemets"
check recette $'WP_ENV=staging\nWP_ENV=production' non "environnement « staging »" "première occurrence retenue"
check recette $'WP_ENVIRONMENT_TYPE=staging\nWP_ENV=production' non "environnement « staging »" "WP_ENVIRONMENT_TYPE l'emporte"
check recette $'WP_ENVIRONMENT_TYPE=bidon\nWP_ENV=staging' oui "WP_ENVIRONMENT_TYPE « bidon »" "WP_ENVIRONMENT_TYPE non standard = production"
check recette $'WP_ENVIRONMENT_TYPE=\nWP_ENV=development' non "environnement « development »" "WP_ENVIRONMENT_TYPE vide ignoré"
check recette '' non "WP_ENV introuvable sur « recette », hôte non protégé" "sans variable, hôte non protégé"
check production '' oui "WP_ENV introuvable dans shared/.env de « production » : hôte protégé" "sans variable, hôte protégé"
check production 'WP_ENV=staging' non "environnement « staging »" "production en WP_ENV=staging"

log "shared/.env absent : repli sur l'hôte protégé"
on_host production "rm -f ${DPATH[production]}/shared/.env"
dep_run '' roles:check production
assert_success
assert_contains "$OUT" "hôte protégé, contrôlé si cet hôte est la production" "repli annoncé"
write_env production

finish
