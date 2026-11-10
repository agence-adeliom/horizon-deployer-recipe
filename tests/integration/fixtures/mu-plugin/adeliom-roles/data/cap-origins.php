<?php
/**
 * Origines connues des caps, utilisées par scripts/build-declaration.php et recopiées à l'identique dans
 * le mu-plugin (templates/mu-plugin/adeliom-roles/data/cap-origins.php, lu par la classe CapOrigins).
 *
 * - prefixes : préfixe de cap => nom du plugin qui l'ajoute lui-même. Ces caps sont exclues de la
 *   déclaration générée et signalées pour vérification (bloc plugin_caps_a_verifier).
 * - core_dynamic : caps du cœur absentes des rôles par défaut, que WordPress accorde à l'exécution
 *   (filtre user_has_cap) à tout compte qui a l'une des caps listées. Stockées sur un rôle qui a déjà
 *   l'une de ces caps, elles sont redondantes (User Role Editor les ajoute à administrator).
 *
 * Pour ajouter un plugin : une ligne dans prefixes, dans les deux copies, puis réinstaller le moteur
 * (build-declaration.php --install : roles.php existant conservé).
 */

return array(
    'prefixes' => array(
        'gravityforms_' => 'Gravity Forms',
        'gform_' => 'Gravity Forms',
        'wf2fa_' => 'Wordfence',
        'wfls_' => 'Wordfence',
        'rank_math_' => 'Rank Math',
        'wpseo_' => 'Yoast',
        'w3tc_' => 'W3 Total Cache',
        'ure_' => 'URE',
    ),
    'core_dynamic' => array(
        'install_languages' => array('update_core', 'install_plugins', 'install_themes'),
        'resume_plugins' => array('activate_plugins'),
        'resume_themes' => array('switch_themes'),
        'view_site_health_checks' => array('install_plugins'),
    ),
);
