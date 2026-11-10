#!/bin/bash
# Outils partagés par setup.sh et les scénarios. Exécuté DANS le conteneur « local », en tant
# que deployer, depuis /project.
#
# Un scénario source ce fichier, appelle reset_all (état de référence), lance dep avec dep_run
# et vérifie l'effet réel avec les assertions ci-dessous, puis termine par finish.

set -uo pipefail

PROJECT=/project
SNAPSHOTS=/home/deployer/snapshots
FIXTURES=/tests/fixtures

# Inventaire, aligné sur tests/integration/project/deploy.php.
declare -A SERVER=([staging]=server1 [recette]=server1 [production]=server2 [reseau-a]=server2 [reseau-b]=server1)
declare -A DPATH=([staging]=/srv/staging [recette]=/srv/recette [production]=/srv/production [reseau-a]=/srv/reseau-a [reseau-b]=/srv/reseau-b)
declare -A DBNAME=([local]=local [staging]=staging [recette]=recette [production]=production [reseau-a]=reseau_a [reseau-b]=reseau_b)
declare -A URL=([local]=http://local.test [staging]=http://staging.test [recette]=http://recette.test [production]=https://production.test [reseau-a]=http://reseau-a.test [reseau-b]=http://reseau-b.test)
declare -A WPENV=([local]=development [staging]=staging [recette]=staging [production]=production [reseau-a]=staging [reseau-b]=staging)
REMOTES=(staging recette production reseau-a reseau-b)
SINGLES=(staging recette production)

HDR_FAILS=0
HDR_PASSES=0

# ---------------------------------------------------------------------------------------------
# Exécution
# ---------------------------------------------------------------------------------------------

log() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

# dep_run <réponses> <arguments de dep…>
# Les réponses aux questions sont passées sur stdin, séparées par \n (printf %b) : c'est la
# voie qu'emprunte Symfony sans TTY. "" donne un stdin vide (EOF), comme un cron sans -n.
# Résultat : $OUT (sortie complète) et $RC (code de sortie).
dep_run() {
    local answers=$1
    shift
    printf '\n$ dep %s   [stdin: %q]\n' "$*" "$answers"
    OUT=$(cd "$PROJECT" && printf '%b' "$answers" | timeout 600 vendor/bin/dep --no-ansi "$@" 2>&1)
    RC=$?
    printf '%s\n[code de sortie : %s]\n' "$OUT" "$RC"
}

on_server() {
    local server=$1
    shift
    ssh -o BatchMode=yes deployer@"$server" "$@"
}

# Commande shell sur l'hôte d'un alias.
on_host() {
    local alias=$1
    shift
    on_server "${SERVER[$alias]}" "$@"
}

# wp_on <alias|local> <arguments wp…> : WP-CLI dans la release en ligne (ou le projet local).
wp_on() {
    local alias=$1
    shift
    if [ "$alias" = local ]; then
        (cd "$PROJECT" && wp "$@")
    else
        on_host "$alias" "cd ${DPATH[$alias]}/current && wp $(printf '%q ' "$@")"
    fi
}

# sql <alias|local> <requête> : lecture directe dans la base de l'environnement.
sql() {
    mysql -h db -uwp -pwp --default-character-set=utf8mb4 -N -B -r "${DBNAME[$1]}" -e "$2" 2>/dev/null
}

# ---------------------------------------------------------------------------------------------
# Assertions
# ---------------------------------------------------------------------------------------------

ok() { HDR_PASSES=$((HDR_PASSES + 1)); printf '  \033[32mOK\033[0m %s\n' "$1"; }
ko() { HDR_FAILS=$((HDR_FAILS + 1)); printf '  \033[31mÉCHEC\033[0m %s\n' "$1"; [ $# -gt 1 ] && printf '        %s\n' "${@:2}"; return 0; }

assert_eq() { # <obtenu> <attendu> <message>
    if [ "$1" = "$2" ]; then ok "$3"; else ko "$3" "obtenu : «$1»" "attendu : «$2»"; fi
}

assert_contains() { # <texte> <motif fixe> <message>
    if grep -qF -- "$2" <<<"$1"; then ok "$3"; else ko "$3" "motif absent : «$2»"; fi
}

assert_not_contains() {
    if grep -qF -- "$2" <<<"$1"; then ko "$3" "motif présent : «$2»"; else ok "$3"; fi
}

assert_matches() { # <texte> <regex étendue> <message>
    if grep -qE -- "$2" <<<"$1"; then ok "$3"; else ko "$3" "regex sans correspondance : «$2»"; fi
}

assert_success() { if [ "$RC" -eq 0 ]; then ok "${1:-dep réussit}"; else ko "${1:-dep réussit}" "code de sortie $RC"; fi; }
assert_failure() { if [ "$RC" -ne 0 ]; then ok "${1:-dep échoue}"; else ko "${1:-dep échoue}" "code de sortie 0"; fi; }

assert_local_file() { if [ -e "$1" ]; then ok "${2:-$1 existe}"; else ko "${2:-$1 existe}" "absent : $1"; fi; }
assert_no_local_file() { if [ -e "$1" ]; then ko "${2:-$1 absent}" "présent : $1"; else ok "${2:-$1 absent}"; fi; }

remote_exists() { on_host "$1" "test -e $(printf '%q' "$2")"; }
assert_remote_file() { if remote_exists "$1" "$2"; then ok "${3:-$2 existe sur $1}"; else ko "${3:-$2 existe sur $1}" "absent : $1:$2"; fi; }
assert_no_remote_file() { if remote_exists "$1" "$2"; then ko "${3:-$2 absent de $1}" "présent : $1:$2"; else ok "${3:-$2 absent de $1}"; fi; }

# Nombre de fichiers (hors dossiers) sous un chemin distant, glob accepté.
remote_count() { on_host "$1" "ls -1d $2 2>/dev/null | wc -l" | tr -d ' '; }

# Aucun répertoire de travail ne doit subsister après une tâche (ni local, ni distant).
assert_clean_workdirs() {
    local alias leftovers
    leftovers=$(find "$PROJECT/.dep/transfer" -mindepth 1 -maxdepth 1 2>/dev/null | wc -l | tr -d ' ')
    assert_eq "$leftovers" 0 "aucun répertoire de travail local résiduel"
    assert_no_local_file "$PROJECT/.dep/transfer-state.json" "état de nettoyage remis à zéro"
    for alias in "$@"; do
        leftovers=$(on_host "$alias" "find ${DPATH[$alias]}/.dep/transfer -mindepth 1 -maxdepth 1 2>/dev/null | wc -l" | tr -d ' ')
        assert_eq "$leftovers" 0 "aucun répertoire de travail résiduel sur $alias"
    done
}

skip_bug() {
    printf '\n  \033[33mIGNORÉ\033[0m BUG : %s\n' "$*"
    exit 77
}

finish() {
    printf '\nBilan : %d vérification(s) réussie(s), %d échec(s).\n' "$HDR_PASSES" "$HDR_FAILS"
    [ "$HDR_FAILS" -eq 0 ]
    exit $?
}

# ---------------------------------------------------------------------------------------------
# État de référence
# ---------------------------------------------------------------------------------------------

env_file() { # <alias> : contenu canonique de shared/.env (ou du .env local)
    local alias=$1 domain
    domain=${URL[$alias]#*://}
    printf 'DB_NAME=%s\nDB_USER=wp\nDB_PASSWORD=wp\nDB_HOST=db\nWP_ENV=%s\nWP_HOME=%s\n' \
        "${DBNAME[$alias]}" "${WPENV[$alias]}" "${URL[$alias]}"
    if [[ $alias == reseau-* ]] && [ "${HDR_NO_MULTISITE:-}" != 1 ]; then
        printf 'MULTISITE=true\nDOMAIN_CURRENT_SITE=%s\n' "$domain"
    fi
}

write_env() { # <alias> [contenu] : réécrit shared/.env (contenu canonique par défaut)
    local alias=$1 content=${2-}
    [ $# -lt 2 ] && content=$(env_file "$alias")
    if [ "$alias" = local ]; then
        printf '%s\n' "$content" >"$PROJECT/.env"
    else
        printf '%s\n' "$content" | on_host "$alias" "cat > ${DPATH[$alias]}/shared/.env"
    fi
}

uploads_dir() {
    if [ "$1" = local ]; then echo "$PROJECT/web/app/uploads"; else echo "${DPATH[$1]}/shared/web/app/uploads"; fi
}

# Uploads de référence : des fichiers propres à chaque environnement, et le favicon de production
# avec ses déclinaisons (fichiers factices : seuls leurs noms comptent). Rend le script shell.
seed_uploads_script() {
    local alias=$1 dir script
    dir=$(uploads_dir "$alias")
    script="rm -rf $dir && mkdir -p $dir && cd $dir"
    case $alias in
        local) script+=" && mkdir -p local && echo local > local/seulement-local.txt" ;;
        staging) script+=" && mkdir -p 2023/05 && echo staging > 2023/05/seulement-staging.txt && echo identique > commun.txt && touch -d '2024-01-01 00:00:00' commun.txt" ;;
        recette) script+=" && mkdir -p 2023/06 && echo recette > 2023/06/seulement-recette.txt" ;;
        production)
            script+=" && mkdir -p 2024/01 && head -c 300000 /dev/urandom > 2024/01/photo-production.jpg"
            script+=" && for f in cropped-icone.png cropped-icone-32x32.png cropped-icone-180x180.png cropped-icone-192x192.png; do echo \$f > 2024/01/\$f; done"
            script+=" && echo espace > '2024/01/nom avec espace.txt'"
            ;;
        *) script+=" && echo $alias > fichier-$alias.txt" ;;
    esac
    printf '%s\n' "$script"
}

seed_uploads() {
    if [ "$1" = local ]; then bash -c "$(seed_uploads_script local)"; else on_host "$1" "$(seed_uploads_script "$1")"; fi
}

reset_git() {
    (
        cd "$PROJECT" || exit 1
        git checkout -q main
        git reset -q --hard base
        git clean -fdq
        git push -qf origin main 2>/dev/null
    )
}

reset_dbs() {
    local alias db
    for alias in local "${REMOTES[@]}"; do
        db=${DBNAME[$alias]}
        mysql -h db -uwp -pwp -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\`;" 2>/dev/null
        mysql -h db -uwp -pwp "$db" <"$SNAPSHOTS/$db.sql" 2>/dev/null
    done
}

# restore_db <alias> <nom de l'instantané>
restore_db() {
    local db=${DBNAME[$1]}
    mysql -h db -uwp -pwp -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\`;" 2>/dev/null
    mysql -h db -uwp -pwp "$db" <"$SNAPSHOTS/$2.sql" 2>/dev/null
}

# État de référence avant chaque scénario : dépôt git, bases, uploads, .env, sauvegardes et
# répertoires de travail. Une seule connexion SSH par serveur.
reset_all() {
    local alias server script
    reset_git
    reset_dbs
    rm -rf "$PROJECT"/db-*.sql.gz "$PROJECT"/uploads-*.tar.gz "$PROJECT/.dep" /tmp/hdr-roles-execute
    seed_uploads local
    write_env local
    for server in server1 server2; do
        script="set -e"
        for alias in "${REMOTES[@]}"; do
            [ "${SERVER[$alias]}" = "$server" ] || continue
            script+=$'\n'"rm -rf ${DPATH[$alias]}/.dep/backups ${DPATH[$alias]}/.dep/transfer ${DPATH[$alias]}/.dep/deploy.lock"
            script+=$'\n'"cat > ${DPATH[$alias]}/shared/.env <<'HDR_ENV'"$'\n'"$(env_file "$alias")"$'\n'"HDR_ENV"
            script+=$'\n'"$(seed_uploads_script "$alias")"
            script+=$'\n'"cd /"
        done
        on_server "$server" "$script"
    done
}

# commit_push <message> : commite et pousse l'état du projet (déclaration de rôles, etc.).
commit_push() {
    (
        cd "$PROJECT" || exit 1
        git add -A
        git commit -qm "$1"
        git push -q origin main 2>/dev/null
    )
}

install_mu_plugin() { # <déclaration de tests/integration/fixtures/roles>
    cp -R "$FIXTURES/mu-plugin/." "$PROJECT/web/app/mu-plugins/"
    cp "$FIXTURES/roles/$1" "$PROJECT/web/app/mu-plugins/adeliom-roles/roles.php"
}

release_count() { on_host "$1" "ls -1 ${DPATH[$1]}/releases | wc -l" | tr -d ' '; }
current_release() { on_host "$1" "basename \$(readlink ${DPATH[$1]}/current)"; }
