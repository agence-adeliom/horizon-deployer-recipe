<?php
/*
 * Déclaration fictive INVALIDE (version 0, debug_page non booléen) : le mu-plugin ne l'appliquerait pas.
 */

return array(
    'version' => 0,
    'roles' => array(
        'gestionnaire' => array(
            'name' => 'Gestionnaire',
            'caps' => array(
                'read' => true,
                'edit_posts' => true,
                'upload_files' => false,
            ),
            'remove' => array('delete_posts'),
        ),
    ),
    'expected' => array(
        'roles' => array('administrator', 'author', 'contributor', 'editor', 'subscriber'),
        'caps' => array(
            'gestionnaire' => array(
                'delete_posts' => null,
                'edit_posts' => null,
                'read' => null,
                'upload_files' => null,
            ),
            'editor' => array(
                'edit_others_posts' => true,
                'manage_options' => null,
            ),
        ),
    ),
    'extra_roles_ui' => false,
    'default_extra_roles' => array(),
    'debug_page' => 'oui',
);
