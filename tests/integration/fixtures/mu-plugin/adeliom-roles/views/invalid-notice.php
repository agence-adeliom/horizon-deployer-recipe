<?php

defined('ABSPATH') || exit;

/**
 * Bandeau « déclaration invalide » des pages d'administration (InvalidNotice::display()).
 *
 * @var array $view errors : erreurs de la déclaration (Declaration::errors()).
 */

?>
<div class="notice notice-error"><p><strong><?php echo esc_html("Rôles (Adeliom)\u{00A0}: déclaration invalide, rien n’est appliqué."); ?></strong> <?php echo esc_html('Le fichier adeliom-roles/roles.php du mu-plugin doit être corrigé puis redéployé. Les rôles en base restent tels quels.'); ?></p><ul>
<?php foreach ($view['errors'] as $error) : ?>
<li><?php echo esc_html($error); ?></li>
<?php endforeach; ?>
</ul></div>
