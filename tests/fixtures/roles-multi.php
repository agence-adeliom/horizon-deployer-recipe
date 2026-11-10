<?php
/*
 * Déclaration fictive d'un site à deux rôles métier cumulables (format du générateur du skill
 * wordpress-remplacer-ure). Sert de cas nominal aux tests de roles:check.
 */

return array(
    'version' => 3,
    'roles' => array(
        'agent' => array(
            'name' => 'Agent',
            'caps' => array(
                'level_0' => true,
                'read' => true,
                'upload_files' => false,
            ),
            'remove' => array('edit_posts'),
        ),
        'agent_support' => array(
            'name' => 'Agent support',
            'caps' => array(
                'read' => true,
                'list_users' => true,
            ),
            'remove' => array(),
        ),
    ),
    'expected' => array(
        'roles' => array('administrator', 'agent', 'agent_support', 'subscriber'),
        'caps' => array(
            'agent' => array(
                'edit_posts' => null,
                'level_0' => true,
                'read' => true,
                'upload_files' => false,
            ),
            'agent_support' => array(
                'list_users' => true,
                'read' => true,
            ),
        ),
    ),
    'extra_roles_ui' => true,
    'default_extra_roles' => array(),
    'debug_page' => true,
);
