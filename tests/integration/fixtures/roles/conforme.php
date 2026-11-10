<?php
/*
 * Déclaration fictive de la suite d'intégration : un rôle métier « gestionnaire » à créer, sur une
 * base WordPress fraîchement installée (cinq rôles par défaut). L'empreinte « expected » est celle
 * de cette base : roles:check doit la trouver conforme.
 */

return array(
    'version' => 1,
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
    'debug_page' => false,
);
