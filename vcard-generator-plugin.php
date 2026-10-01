<?php
/*
 * Plugin Name:       WP VCard Generator
 * Plugin URI:        https://www.cassiopea.ca
 * Description:       Generates VCards for contacts and allows users to download them.
 * Version:           1.0.2
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Cassiopea
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       vcard-generator
 * Domain Path:       /languages
 * Readme:            README.md
 */

if (!defined("ABSPATH")) {
    die("You are not allowed to access this file!");
}

function add_custom_button_to_acf_editor()
{
    add_meta_box(
        'custom_button_metabox',
        ' ',
        'render_custom_button_metabox',
        'employees',
        'normal',
        'low'
    );
}
add_action('add_meta_boxes', 'add_custom_button_to_acf_editor');

function render_custom_button_metabox()
{
?>
    <div class="wrap">
        <a href="#" class="button button-primary generate-vcard-btn">Générer une VCARD</a>
        <input type="text" name="url_de_carte" id="url_de_carte" data-name="url_de_carte" style="width: 100%; margin-top: 10px;">
        <div class="vcard-fields-container"></div>
    </div>
<?php
}

// Enqueue custom js file
add_action('admin_enqueue_scripts', 'wp_vcard_generator_enqueue_scripts');

function wp_vcard_generator_enqueue_scripts()
{
    wp_enqueue_script('wp-vcard-generator-script', plugin_dir_url(__FILE__) . '/assets/js/script.js', array('jquery'), '1.0.2', true);
    wp_localize_script('wp-vcard-generator-script', 'wp_vcard_generator_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        // SECURITY: nonce sent with every AJAX request and verified server-side.
        'nonce'    => wp_create_nonce('wp_vcard_generator_nonce'),
    ));
}

// Enqueue custom CSS file
add_action('admin_enqueue_scripts', 'wp_vcard_generator_enqueue_styles');

function wp_vcard_generator_enqueue_styles()
{
    wp_enqueue_style('wp-vcard-generator-style', plugin_dir_url(__FILE__) . '/assets/css/style.css', array(), '1.0.2', 'all');
}

/**
 * SECURITY: escapes a value for a vCard 3.0 TEXT property.
 * Prevents line-break injection (forging extra vCard properties) and
 * protects the separators used by the format.
 */
function wp_vcard_escape_text($value)
{
    $value = is_array($value) ? implode(', ', $value) : (string) $value;
    $value = wp_strip_all_tags($value);
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace(array("\r\n", "\r", "\n"), '\n', $value);
    $value = str_replace(array(';', ','), array('\;', '\,'), $value);
    return $value;
}

/**
 * SECURITY: cleans a value for a URI property (URL, social profiles).
 * Only valid URLs are kept and any control character (CR/LF...) is removed.
 */
function wp_vcard_clean_uri($value)
{
    $value = is_array($value) ? '' : (string) $value;
    $value = esc_url_raw(trim($value));
    return preg_replace('/[\x00-\x1F\x7F]/', '', $value);
}

/**
 * Builds and saves the VCard of one employee.
 * Shared by the AJAX handler and the cron job (no dependency on $_POST).
 *
 * @param int $post_id Employee post ID.
 * @return string|WP_Error URL of the generated file, or an error.
 */
function wp_vcard_generate_for_post($post_id)
{
    $post_id = absint($post_id);

    // SECURITY: ACF must be active, and the post must be an existing employee.
    if (!function_exists('get_field')) {
        return new WP_Error('acf_missing', 'ACF is required.');
    }
    if (!$post_id || get_post_type($post_id) !== 'employees') {
        return new WP_Error('invalid_post', 'Invalid employee.');
    }

    // Language: guarded so the plugin does not crash if Polylang is inactive.
    $lang_code = function_exists('pll_get_post_language') ? pll_get_post_language($post_id) : '';
    $lang_code = $lang_code ? $lang_code : '';
    $other_lang = ($lang_code === 'fr') ? 'en' : 'fr';

    $other_post_id = apply_filters('wpml_object_id', $post_id, 'post', false, $other_lang);
    $titre_other   = $other_post_id ? get_field('titre_employee', $other_post_id) : '';

    $keys = array(
        'adresse_de_courriel',
        'nom_employe',
        'prenom_employe',
        'texte_slogan_services',
        'nom_de_lorganisation',
        'site_web',
        'telephone_bureau',
        'telephone_mobile',
        'adresse_postale_bureau',
        'facebook',
        'linkedin',
        'instagram',
        'twitter',
        'youtube',
        'pinterest',
        'houzz',
        'autres_liens',
        'prise_de_rdv',
    );
    $f = array();
    foreach ($keys as $key) {
        $f[$key] = get_field($key, $post_id);
    }
    $f['titre_employe']    = get_field('titre_employee', $post_id);
    $f['titre_employe_en'] = $titre_other;

    // SECURITY: sanitized file name + post ID (avoids overwriting a homonym's file).
    $file_name = sanitize_file_name(
        $f['nom_employe'] . '_' . $f['prenom_employe'] . '_' . $lang_code . '_' . $post_id . '.vcf'
    );

    $t = 'wp_vcard_escape_text';
    $u = 'wp_vcard_clean_uri';

    // NOTE: slogan and appointment link (plain text only, HTML tags are not valid in a vCard).
    $note_parts = array();
    if (!empty($f['texte_slogan_services'])) {
        $note_parts[] = 'Slogan: ' . $f['texte_slogan_services'];
    }
    if (!empty($f['prise_de_rdv'])) {
        $note_parts[] = 'Prendre rendez-vous: ' . $f['prise_de_rdv'];
    }
    $note = implode(' - ', $note_parts);

    // TITLE: "titre FR/titre EN", without a dangling "/" when a translation is missing.
    $titles = array();
    foreach (array($f['titre_employe'], $f['titre_employe_en']) as $title) {
        if (!empty($title)) {
            $titles[] = $t($title);
        }
    }

    $vcard_content  = "BEGIN:VCARD\r\n";
    $vcard_content .= "VERSION:3.0\r\n";
    $vcard_content .= "EMAIL:" . $t(sanitize_email($f['adresse_de_courriel'])) . "\r\n";
    $vcard_content .= "N:" . $t($f['nom_employe']) . ";" . $t($f['prenom_employe']) . ";;;\r\n";
    $vcard_content .= "NOTE:" . $t($note) . "\r\n";
    $vcard_content .= "TITLE:" . implode('/', $titles) . "\r\n";
    $vcard_content .= "ORG:" . $t($f['nom_de_lorganisation']) . "\r\n";
    $vcard_content .= "URL:" . $u($f['site_web']) . "\r\n";
    $vcard_content .= "TEL;TYPE=WORK:" . $t($f['telephone_bureau']) . "\r\n";
    $vcard_content .= "TEL;TYPE=CELL:" . $t($f['telephone_mobile']) . "\r\n";
    $vcard_content .= "ADR;TYPE=WORK:;;" . $t($f['adresse_postale_bureau']) . ";;;;\r\n";
    $vcard_content .= "X-SOCIALPROFILE;TYPE=facebook:" . $u($f['facebook']) . "\r\n";
    $vcard_content .= "X-SOCIALPROFILE;TYPE=linkedin:" . $u($f['linkedin']) . "\r\n";
    $vcard_content .= "X-SOCIALPROFILE;TYPE=instagram:" . $u($f['instagram']) . "\r\n";
    $vcard_content .= "X-SOCIALPROFILE;TYPE=twitter:" . $u($f['twitter']) . "\r\n";
    $vcard_content .= "X-SOCIALPROFILE;TYPE=youtube:" . $u($f['youtube']) . "\r\n";
    $vcard_content .= "X-SOCIALPROFILE;TYPE=pinterest:" . $u($f['pinterest']) . "\r\n";
    $vcard_content .= "X-SOCIALPROFILE;TYPE=houzz:" . $u($f['houzz']) . "\r\n";
    $vcard_content .= "X-SOCIALPROFILE;TYPE=other:" . $u($f['autres_liens']) . "\r\n";
    $vcard_content .= "END:VCARD\r\n";

    $upload_dir = wp_upload_dir();
    $vcard_dir  = $upload_dir['basedir'] . '/vcf-cards';
    if (!file_exists($vcard_dir)) {
        wp_mkdir_p($vcard_dir);
    }

    $file_path = $vcard_dir . '/' . $file_name;
    if (file_put_contents($file_path, $vcard_content) === false) {
        return new WP_Error('write_failed', 'Could not write the file.');
    }

    // URL built from the uploads base URL (not by replacing ABSPATH).
    $file_url = $upload_dir['baseurl'] . '/vcf-cards/' . $file_name;
    update_field('url_de_carte', $file_url, $post_id);

    return $file_url;
}

add_action('wp_ajax_save_vcard_to_server', 'save_vcard_to_server');

function save_vcard_to_server()
{
    // SECURITY: 1) valid nonce (CSRF protection).
    check_ajax_referer('wp_vcard_generator_nonce', 'nonce');

    // SECURITY: 2) input cast to an integer.
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

    // SECURITY: 3) the user must be allowed to edit this specific post.
    if (!$post_id || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error(array('message' => 'Forbidden'), 403);
    }

    $result = wp_vcard_generate_for_post($post_id);

    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('file_url' => $result));
}

// Planifier la génération de VCards
add_action('init', 'schedule_vcard_generation');

function schedule_vcard_generation()
{
    if (!wp_next_scheduled('generate_vcards_event')) {
        wp_schedule_event(time(), 'vcf_card_cron', 'generate_vcards_event');
    }
}

add_action('generate_vcards_event', 'generate_vcards');

function generate_vcards()
{
    $args = array(
        'post_type'        => 'employees',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'suppress_filters' => true, // include every language when run from cron
    );

    $vcard_query = new WP_Query($args);

    foreach ($vcard_query->posts as $post_id) {
        if (empty(get_field('url_de_carte', $post_id))) {
            // Fixed: the cron calls the shared function, not the AJAX handler.
            wp_vcard_generate_for_post($post_id);
        }
    }
}

add_filter('cron_schedules', 'add_cron_interval');

function add_cron_interval($schedules)
{
    $schedules['vcf_card_cron'] = array(
        'interval' => 43200,
        'display'  => __('Every 12 Hours')
    );
    return $schedules;
}
