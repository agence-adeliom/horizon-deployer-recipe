<?php
/*
 * Déclaration des rôles gérés par le mu-plugin adeliom-roles (remplace User Role Editor).
 *
 * C'est le seul fichier du mu-plugin à modifier sur un site. Pur fichier de données : il ne contient que
 * le tableau ci-dessous, lu par le moteur, par les scripts du skill wordpress-remplacer-ure et par la
 * recette de déploiement (sans WordPress). Un fichier invalide n'est jamais appliqué : les administrateurs
 * sont avertis dans le back-office.
 *
 * - version : à augmenter à chaque modification, la synchronisation ne s'exécute que si elle change.
 * - roles : slug => nom affiché (name, le slug s'il est omis),
 *   caps à garantir (caps, cap => true ou false), caps à retirer explicitement (remove).
 *   Les caps non citées ne sont jamais touchées.
 * - expected : empreinte de la base de production au moment de l'audit, contrôlée en production
 *   avant la première application seulement. roles est la liste triée des rôles existants, caps donne
 *   pour chaque rôle géré les caps surveillées (true ou false : cap présente avec cette valeur,
 *   null : cap absente).
 * - extra_roles_ui : cases « Rôles supplémentaires » sur la fiche utilisateur.
 * - default_extra_roles : rôles supplémentaires donnés aux nouveaux comptes.
 * - debug_page : page Outils › Rôles (Adeliom).
 */

return array(
    'version' => 1,
    'roles' => array(),
    'expected' => array(
        'roles' => array(),
        'caps' => array(),
    ),
    'extra_roles_ui' => false,
    'default_extra_roles' => array(),
    'debug_page' => false,
);
