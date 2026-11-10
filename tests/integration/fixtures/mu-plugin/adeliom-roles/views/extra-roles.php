<?php

defined('ABSPATH') || exit;

/**
 * Cases à cocher « Rôles supplémentaires » de la fiche utilisateur (ExtraRoles::render()).
 *
 * @var array $view choices : liste de array(slug, name, checked).
 */

?>
<h2><?php echo esc_html__('Rôles supplémentaires', 'adeliom-roles'); ?></h2><table class="form-table" role="presentation"><tr><th><?php echo esc_html__('Rôles', 'adeliom-roles'); ?></th><td><fieldset><legend class="screen-reader-text"><?php echo esc_html__('Rôles supplémentaires', 'adeliom-roles'); ?></legend>
<?php wp_nonce_field('adeliom_roles_extra', 'adeliom_roles_extra_nonce'); ?>
<?php foreach ($view['choices'] as $choice) : ?>
<label style="display:block"><input type="checkbox" name="adeliom_roles_extra[]" value="<?php echo esc_attr($choice['slug']); ?>"<?php checked($choice['checked']); ?>> <?php echo esc_html($choice['name']); ?></label>
<?php endforeach; ?>
</fieldset></td></tr></table>
