<?php

/*
 * Contenu de référence d'un environnement, lancé par `wp eval-file -` (setup.sh). Variables :
 * HDR_MARKER (nom de l'environnement), HDR_FAVICON (1 : média site_icon et ses déclinaisons).
 *
 * L'URL du site est écrite sous plusieurs formes que la réécriture doit toutes atteindre : lien
 * absolu, adresse e-mail du domaine, URL échappée en JSON (bloc Gutenberg), valeur sérialisée.
 * Une table de type Wordfence (*_wf*) contient aussi l'URL : elle doit rester intacte.
 */

global $wpdb;

$marker = getenv('HDR_MARKER');
$url = home_url();
$domain = parse_url($url, PHP_URL_HOST);
$json = str_replace('/', '\\/', $url);

wp_insert_post([
    'post_title' => "Marqueur $marker",
    'post_name' => 'marqueur',
    'post_status' => 'publish',
    // wp_insert_post() retire une couche d'antislashs : wp_slash() garde l'échappement JSON.
    'post_content' => wp_slash("<p>Lien absolu <a href=\"$url/page-a/\">page A</a>. Écrire à contact@$domain.</p>\n"
        . "<!-- wp:image {\"url\":\"$json/app/uploads/2024/01/photo.jpg\"} /-->"),
]);

update_option('hdr_marker', $marker);
update_option('hdr_serialise', ['lien' => "$url/serialise/"]);

$wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wfhits (id INT AUTO_INCREMENT PRIMARY KEY, url TEXT) DEFAULT CHARSET=utf8mb4");
$wpdb->insert("{$wpdb->prefix}wfhits", ['url' => "$url/wp-login.php"]);

if (getenv('HDR_FAVICON') === '1') {
    $id = wp_insert_attachment([
        'post_title' => 'Icône',
        'post_mime_type' => 'image/png',
        'post_status' => 'inherit',
    ], '2024/01/cropped-icone.png');

    $size = static fn (int $px): array => ['file' => "cropped-icone-{$px}x{$px}.png", 'width' => $px, 'height' => $px, 'mime-type' => 'image/png'];

    wp_update_attachment_metadata($id, [
        'width' => 512,
        'height' => 512,
        'file' => '2024/01/cropped-icone.png',
        'sizes' => [
            'site_icon-32' => $size(32),
            'site_icon-180' => $size(180),
            'site_icon-192' => $size(192),
        ],
    ]);

    update_option('site_icon', $id);
}

echo "Contenu de référence : $marker ($url)\n";
