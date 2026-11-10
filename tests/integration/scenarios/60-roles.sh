#!/bin/bash
# roles:check / roles:status avec le mu-plugin adeliom-roles (gabarit du skill
# wordpress-remplacer-ure, déclaration fictive) : projet sans mu-plugin (aucune commande
# distante), ancien mu-plugin d'un seul fichier, rôles conformes puis synchronisés au
# déploiement, version déjà appliquée, écart en production (refus, EOF, -n, poursuite),
# déclaration invalide, déclaration exécutable jamais exécutée, lecture au commit HEAD,
# roles:status sans chargeur dans la release, désarmement.
source /tests/lib/common.sh

state() { sql production "SELECT option_value FROM wp_options WHERE option_name='adeliom_roles_state'"; }
roles() { sql production "SELECT option_value FROM wp_options WHERE option_name='wp_user_roles'"; }

log "Projet sans mu-plugin : aucune commande distante"
reset_all
dep_run '' roles:check production -vvv
assert_success
assert_contains "$OUT" "Pas de mu-plugin adeliom-roles" "absence annoncée en -v"
assert_not_contains "$OUT" "[production] run" "aucune commande distante (roles:check)"
dep_run '' roles:status production -vvv
assert_success
assert_not_contains "$OUT" "[production] run" "aucune commande distante (roles:status)"
dep_run '' deploy production -n
assert_success
assert_not_contains "$OUT" "Rôles" "rien ne s'affiche au déploiement"

log "Ancien mu-plugin d'un seul fichier : avertissement local"
cp "$FIXTURES/roles/legacy-adeliom-roles.php" "$PROJECT/web/app/mu-plugins/adeliom-roles.php"
dep_run '' roles:check production -vvv
assert_success
assert_contains "$OUT" "Ancien mu-plugin adeliom-roles d'un seul fichier" "migration suggérée"
assert_not_contains "$OUT" "[production] run" "aucune commande distante"

log "Rôles conformes : contrôle sans écriture, puis synchro au déploiement"
reset_all
install_mu_plugin conforme.php
commit_push "mu-plugin adeliom-roles"
dep_run '' roles:check production
assert_success
assert_contains "$OUT" "Rôles de « production » conformes à l'empreinte de l'audit" "empreinte conforme"
assert_eq "$(state)" "" "roles:check n'a pas chargé le mu-plugin (aucun état écrit)"
dep_run '' deploy production -n
assert_success
assert_contains "$OUT" "conformes à l'empreinte" "roles:check passe avant le déploiement"
assert_contains "$OUT" "Rôles adeliom-roles sur « production »" "roles:status affiché en fin de déploiement"
assert_contains "$OUT" "Version appliquée : 1" "synchro appliquée au chargement"
assert_contains "$OUT" "Statut : up_to_date" "statut à jour"
assert_contains "$(roles)" "gestionnaire" "le rôle déclaré existe en production"
assert_contains "$(state)" 's:15:"applied_version";i:1;' "état adeliom_roles_state enregistré"
order=$(grep -E '^task (build:check|roles:check|deploy:info|deploy:lock|wp:update-db|roles:status)$' <<<"$OUT" | sed 's/^task //' | tr '\n' ' ')
assert_eq "$order" "build:check roles:check deploy:info deploy:lock wp:update-db roles:status " "ordre des tâches : build:check puis roles:check avant le verrou, wp:update-db puis roles:status à la fin"

log "Version déjà appliquée : pas de contrôle d'empreinte"
dep_run '' roles:check production
assert_success
assert_contains "$OUT" "Rôles déjà synchronisés par adeliom-roles (version 1)" "version appliquée détectée"

log "Écart en production : Entrée refuse, le déploiement s'arrête avant tout verrou"
reset_all
restore_db production production-ecart
install_mu_plugin conforme.php
commit_push "mu-plugin adeliom-roles"
before=$(release_count production)
dep_run '\n' deploy production
assert_failure
assert_contains "$OUT" "Les rôles de « production » ont changé depuis l'audit" "écart annoncé"
assert_contains "$OUT" "Rôle intrus : absent de l'audit, présent sur l'hôte" "rôle en trop listé"
assert_contains "$OUT" "Rôle editor, cap edit_others_posts : attendu true, hôte absente" "cap modifiée listée"
assert_contains "$OUT" "Continuer le déploiement ?" "question posée"
assert_contains "$OUT" "Déploiement interrompu" "déploiement interrompu"
assert_eq "$(release_count production)" "$before" "aucune release créée"
assert_no_remote_file production "${DPATH[production]}/.dep/deploy.lock" "aucun verrou laissé"
assert_eq "$(state)" "" "aucune écriture de l'état"

log "Écart en production, sans TTY ni -n (stdin vide) : défaut « non »"
dep_run '' deploy production
assert_failure
assert_contains "$OUT" "Déploiement interrompu" "EOF = non"

log "Écart en production, -n : avertissement et déploiement poursuivi"
dep_run '' deploy production -n
assert_success
assert_contains "$OUT" "Mode non interactif : déploiement poursuivi malgré l'écart" "poursuite annoncée"
assert_eq "$(release_count production)" "$((before + 1))" "release publiée"
assert_contains "$OUT" "Statut : pending" "roles:status : synchro en attente"
assert_not_contains "$(roles)" "gestionnaire" "rien n'est appliqué par le mu-plugin"

log "Écart en production, « y » : déploiement poursuivi"
dep_run 'y\n' deploy production
assert_success
assert_eq "$(release_count production)" "$((before + 2))" "release publiée"

log "Déclaration invalide : avertissement, jamais « conformes »"
reset_all
install_mu_plugin invalide.php
commit_push "Déclaration invalide"
dep_run '' roles:check production
assert_success
assert_contains "$OUT" "Déclaration adeliom-roles/roles.php invalide : la synchro ne s'appliquera pas" "déclaration invalide annoncée"
assert_contains "$OUT" "version doit être un entier positif" "erreur de version listée"
assert_contains "$OUT" "debug_page doit valoir true ou false" "erreur de debug_page listée"
assert_not_contains "$OUT" "conformes" "jamais « conformes »"
dep_run '' deploy production -n
assert_success "le déploiement continue"
assert_contains "$OUT" "État des rôles illisible sur « production » (wp adeliom-roles status)" "roles:status avertit sans échouer"
assert_contains "$OUT" "Déclaration invalide" "sortie du mu-plugin relayée"

log "Déclaration exécutable : refusée, jamais exécutée"
reset_all
install_mu_plugin executable.php
commit_push "Déclaration exécutable"
dep_run '' roles:check production
assert_success
assert_contains "$OUT" "refusée, jamais exécutée" "déclaration refusée"
assert_contains "$OUT" "Rôles de « production » non contrôlés : la lecture a échoué" "contrôle sauté"
assert_no_local_file /tmp/hdr-roles-execute "le code de la déclaration n'a pas été exécuté"

log "Déclaration lue au commit HEAD, pas dans la copie de travail"
reset_all
install_mu_plugin conforme.php
commit_push "mu-plugin adeliom-roles"
cp "$FIXTURES/roles/invalide.php" "$PROJECT/web/app/mu-plugins/adeliom-roles/roles.php"
dep_run '' roles:check production
assert_success
assert_contains "$OUT" "conformes à l'empreinte" "modification non commitée ignorée"
assert_not_contains "$OUT" "copie de travail" "pas d'avertissement"

log "Déclaration jamais commitée : copie de travail, avec un avertissement"
reset_all
install_mu_plugin conforme.php
dep_run '' roles:check production
assert_success
assert_contains "$OUT" "Déclaration adeliom-roles lue dans la copie de travail" "avertissement"
assert_contains "$OUT" "conformes à l'empreinte" "contrôle fait sur la copie de travail"

log "roles:status : mu-plugin absent de la release en ligne"
reset_all
dep_run '' deploy production -n
install_mu_plugin conforme.php
dep_run '' roles:status production -v
assert_success
assert_contains "$OUT" "Mu-plugin adeliom-roles absent de la release de « production »" "absence du chargeur détectée"
assert_not_contains "$OUT" "Rôles adeliom-roles sur" "commande non lancée"

log "roles_check = false : rien, même avec le mu-plugin"
restore_db production production-ecart
commit_push "mu-plugin adeliom-roles"
HDR_ROLES_CHECK=0 dep_run '' roles:check production -vvv
assert_success
assert_not_contains "$OUT" "[production] run" "aucune commande distante"
assert_not_contains "$OUT" "ont changé" "aucun contrôle"
HDR_ROLES_CHECK=0 dep_run '' deploy production
assert_success "le déploiement passe malgré l'écart"
assert_not_contains "$OUT" "Rôles adeliom-roles sur" "roles:status inerte"

# La production retrouve une release sans mu-plugin pour les scénarios suivants.
reset_all
dep_run '' deploy production -n
finish
