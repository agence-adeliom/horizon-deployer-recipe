#!/bin/bash
# Suite d'intégration de horizon-deployer-recipe : un vrai `dep` lancé contre de vrais hôtes SSH,
# dans Docker.
#
#   tests/integration/run.sh                     tous les scénarios, Deployer 7
#   DEPLOYER_VERSION=8 tests/integration/run.sh  Deployer 8 (PHP 8.3)
#   tests/integration/run.sh db-push roles       seulement les scénarios dont le nom contient ces mots
#
# Options :
#   --keep           ne pas démonter l'environnement à la fin (inspection : docker compose -p … exec local bash)
#   --reuse          réutiliser un environnement laissé par --keep (pas de setup)
#   --recipe <dir>   tester une autre copie du recipe (dossier contenant recipe/horizon-transfer.php)
#   --no-build       ne pas reconstruire l'image (déjà chargée, par exemple en CI)
#
# Code de sortie : 0 si tous les scénarios passent (les scénarios ignorés pour un bug connu ne
# comptent pas comme des échecs), 1 sinon.
set -uo pipefail

HERE=$(cd "$(dirname "$0")" && pwd)
cd "$HERE"

KEEP=0
REUSE=0
BUILD=1
FILTERS=()

while [ $# -gt 0 ]; do
    case $1 in
        --keep) KEEP=1 ;;
        --reuse) REUSE=1; KEEP=1 ;;
        --no-build) BUILD=0 ;;
        --recipe) RECIPE_DIR=$(cd "$2" && pwd); shift ;;
        -h|--help) sed -n '2,17p' "$0"; exit 0 ;;
        *) FILTERS+=("$1") ;;
    esac
    shift
done

export DEPLOYER_VERSION=${DEPLOYER_VERSION:-7}
if [ -z "${PHP_VERSION:-}" ]; then
    # Deployer 8 exige PHP 8.3.
    if [ "$DEPLOYER_VERSION" = 8 ]; then PHP_VERSION=8.3; else PHP_VERSION=8.2; fi
fi
export PHP_VERSION
export RECIPE_DIR=${RECIPE_DIR:-$(cd "$HERE/../.." && pwd)}
export COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT_NAME:-hdr-integration-dep${DEPLOYER_VERSION}}

compose() { docker compose "$@"; }
in_local() { compose exec -T -u deployer -w /project local "$@"; }

teardown() {
    if [ "$KEEP" = 1 ]; then
        echo "Environnement conservé : docker compose -p $COMPOSE_PROJECT_NAME exec -u deployer -w /project local bash"
        return
    fi
    compose down -v --remove-orphans >/dev/null 2>&1
}

started=$(date +%s)
echo "Deployer $DEPLOYER_VERSION, PHP $PHP_VERSION, recipe : $RECIPE_DIR"

if [ "$REUSE" = 0 ]; then
    compose down -v --remove-orphans >/dev/null 2>&1
    trap teardown EXIT
    if [ "$BUILD" = 1 ]; then compose build local || exit 1; fi
    compose up -d --wait db >/dev/null 2>&1 || compose up -d db
    compose up -d local server1 server2 || exit 1
    mkdir -p "$HERE/.logs"
    if ! in_local bash /tests/lib/setup.sh >"$HERE/.logs/setup-deployer-$DEPLOYER_VERSION.log" 2>&1; then
        tail -60 "$HERE/.logs/setup-deployer-$DEPLOYER_VERSION.log"
        echo "Mise en place de l'environnement en échec (journal : tests/integration/.logs/setup-deployer-$DEPLOYER_VERSION.log)."
        exit 1
    fi
    echo "Environnement prêt en $(( $(date +%s) - started )) s."
else
    trap teardown EXIT
fi

scenarios=()
for file in "$HERE"/scenarios/*.sh; do
    name=$(basename "$file" .sh)
    if [ ${#FILTERS[@]} -gt 0 ]; then
        match=0
        for filter in "${FILTERS[@]}"; do
            [[ $name == *"$filter"* ]] && match=1
        done
        [ "$match" = 0 ] && continue
    fi
    scenarios+=("$name")
done

[ ${#scenarios[@]} -eq 0 ] && { echo "Aucun scénario ne correspond."; exit 1; }

LOGS=$HERE/.logs/deployer-$DEPLOYER_VERSION
rm -rf "$LOGS"
mkdir -p "$LOGS"
passed=()
failed=()
skipped=()

for name in "${scenarios[@]}"; do
    t0=$(date +%s)
    printf '%-40s ' "$name"
    in_local bash "/tests/scenarios/$name.sh" >"$LOGS/$name.log" 2>&1
    rc=$?
    elapsed=$(( $(date +%s) - t0 ))
    case $rc in
        0) passed+=("$name"); echo "OK      (${elapsed} s)" ;;
        77) skipped+=("$name"); echo "IGNORÉ  (${elapsed} s) $(grep -m1 'BUG :' "$LOGS/$name.log" | sed 's/.*BUG :/BUG :/')" ;;
        *) failed+=("$name"); echo "ÉCHEC   (${elapsed} s)"; grep -E 'ÉCHEC|^        ' "$LOGS/$name.log" | sed 's/^/    /' | head -30 ;;
    esac
done

echo
echo "Bilan : ${#passed[@]} réussi(s), ${#failed[@]} en échec, ${#skipped[@]} ignoré(s) pour bug connu, en $(( $(date +%s) - started )) s."
echo "Journaux : tests/integration/.logs/deployer-$DEPLOYER_VERSION/"

[ ${#failed[@]} -eq 0 ]
