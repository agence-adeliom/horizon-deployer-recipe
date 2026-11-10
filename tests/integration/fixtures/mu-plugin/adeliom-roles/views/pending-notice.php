<?php

defined('ABSPATH') || exit;

/**
 * Bandeau « synchro en attente » des pages d'administration (PendingNotice::display()).
 *
 * @var array $view link : adresse de la page de debug, ou chaîne vide si elle n'est pas active.
 */

?>
<div class="notice notice-error"><p><strong><?php echo esc_html("Rôles (Adeliom)\u{00A0}: synchronisation en attente."); ?></strong> <?php echo esc_html('Les rôles de la production ont changé depuis l’audit, la déclaration n’a donc pas été appliquée. Adeliom doit refaire l’audit à partir d’un dump récent de la production, puis redéployer.'); ?>
<?php echo $view['link'] !== '' ? ' <a href="' . esc_url($view['link']) . '">' . esc_html('Voir le détail des écarts') . '</a>' : ''; ?></p></div>
