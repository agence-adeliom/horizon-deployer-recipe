<?php

defined('ABSPATH') || exit;

/**
 * Page de debug Outils › Rôles (Adeliom), en lecture seule (DebugPage::render()). Tout est échappé ici.
 *
 * @var array $view errors, pending, status_label, date, applied, declared, multi, engine, production_diff (écarts
 *                  enregistrés tant que l'état est en attente, même si la déclaration est devenue invalide),
 *                  declaration_diff (null si la déclaration est invalide), roles_table (columns, rows), special.
 */

$nbsp = "\u{00A0}";

/**
 * Tableau d'écarts (lignes préparées par DebugPage).
 *
 * @param array $rows
 */
$diff_table = static function (array $rows) {
    ?>
<table class="widefat striped"><thead><tr><th scope="col">Rôle</th><th scope="col">Permission</th><th scope="col">Attendu</th><th scope="col">Actuel</th></tr></thead><tbody>
    <?php foreach ($rows as $row) : ?>
<tr><td><code><?php echo esc_html($row['role']); ?></code></td><td><?php echo $row['cap'] === null ? esc_html('(rôle)') : '<code>' . esc_html($row['cap']) . '</code>'; ?></td><td><?php echo esc_html($row['expected']); ?></td><td><?php echo esc_html($row['actual']); ?></td></tr>
    <?php endforeach; ?>
</tbody></table>
    <?php
};

?>
<div class="wrap"><h1><?php echo esc_html('Rôles (Adeliom)'); ?></h1>
<p><?php echo esc_html('Page en lecture seule. Les rôles se modifient dans la déclaration du mu-plugin, adeliom-roles/roles.php.'); ?></p>

<?php if ($view['errors']) : ?>
<div class="notice notice-error inline"><p><strong><?php echo esc_html('La déclaration des rôles est invalide.'); ?></strong> <?php echo esc_html('Rien n’est appliqué tant que le fichier adeliom-roles/roles.php n’est pas corrigé puis redéployé. Les rôles en base restent tels quels.'); ?></p>
<ul>
    <?php foreach ($view['errors'] as $error) : ?>
<li><?php echo esc_html($error); ?></li>
    <?php endforeach; ?>
</ul></div>
<?php endif; ?>
<?php if ($view['pending']) : ?>
<div class="notice notice-error inline"><p><strong><?php echo esc_html('La synchronisation est en attente.'); ?></strong> <?php echo esc_html('Les rôles de la production ont changé depuis l’audit' . $nbsp . ': la déclaration n’a pas été appliquée. Adeliom doit refaire l’audit à partir d’un dump récent de la production, puis redéployer.'); ?></p></div>
<?php endif; ?>

<h2><?php echo esc_html('Résumé'); ?></h2>
<table class="widefat fixed"><thead><tr>
<th scope="col"><?php echo esc_html('État de la synchro'); ?></th>
<th scope="col"><?php echo esc_html('Version appliquée / déclarée'); ?></th>
<th scope="col"><?php echo esc_html('Utilisateurs à plusieurs rôles'); ?></th>
<th scope="col"><?php echo esc_html('Version du moteur'); ?></th>
</tr></thead><tbody><tr>
<td><strong><?php echo esc_html($view['status_label']); ?></strong><br><?php echo esc_html('Dernière application' . $nbsp . ': ' . $view['date']); ?></td>
<td><?php echo esc_html($view['applied'] . ' / ' . $view['declared']); ?></td>
<td><?php echo esc_html($view['multi']); ?></td>
<td><?php echo esc_html($view['engine']); ?></td>
</tr></tbody></table>

<h2><?php echo esc_html('Écarts entre le code et la base'); ?></h2>
<?php if ($view['production_diff']) : ?>
<p><?php echo esc_html('Écarts entre l’empreinte attendue (audit) et la production, cause de l’attente' . $nbsp . ':'); ?></p>
    <?php $diff_table($view['production_diff']); ?>
<?php endif; ?>
<?php if ($view['declaration_diff'] === null) : ?>
<p><?php echo esc_html('Écarts non calculés' . $nbsp . ': la déclaration est invalide.'); ?></p>
<?php elseif ($view['declaration_diff']) : ?>
<p><?php echo esc_html('Écarts entre la déclaration et les rôles en base' . $nbsp . ':'); ?></p>
    <?php $diff_table($view['declaration_diff']); ?>
<?php else : ?>
<p><?php echo esc_html('Aucun écart entre la déclaration et les rôles en base.'); ?></p>
<?php endif; ?>

<h2><?php echo esc_html('Rôles et permissions'); ?></h2>
<table class="widefat striped"><thead><tr><th scope="col">Permission</th><th scope="col">Origine</th>
<?php foreach ($view['roles_table']['columns'] as $column) : ?>
<th scope="col"><?php echo esc_html($column['name']); ?><br><code><?php echo esc_html($column['slug']); ?></code></th>
<?php endforeach; ?>
</tr></thead><tbody>
<?php foreach ($view['roles_table']['rows'] as $row) : ?>
<tr><th scope="row"><code><?php echo esc_html($row['cap']); ?></code></th><td><?php echo esc_html($row['origin']); ?></td>
    <?php foreach ($row['values'] as $value) : ?>
<td><?php echo esc_html($value); ?></td>
    <?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody></table>

<h2><?php echo esc_html('Utilisateurs particuliers'); ?></h2>
<?php if (!$view['special']) : ?>
<p><?php echo esc_html('Aucun utilisateur à plusieurs rôles ni à permissions individuelles.'); ?></p>
<?php else : ?>
<table class="widefat striped"><thead><tr><th scope="col">Utilisateur</th><th scope="col">Rôle principal</th><th scope="col">Rôles supplémentaires</th><th scope="col">Permissions individuelles</th></tr></thead><tbody>
    <?php foreach ($view['special'] as $user) : ?>
<tr><td><?php echo $user['link'] !== '' ? '<a href="' . esc_url($user['link']) . '">' . esc_html($user['login']) . '</a>' : esc_html($user['login']); ?></td><td><?php echo esc_html($user['main']); ?></td><td><?php echo esc_html($user['extra']); ?></td><td><?php echo esc_html($user['individual']); ?></td></tr>
    <?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>
