<?php
/**
 * Plugin Name:       DB SEO Manager
 * Plugin URI:        https://www.davidebertolino.it/progetti/db-seo-manager/
 * Description:       SEO completa e GDPR-first per WordPress: meta tag, OpenGraph, Schema.org JSON-LD, sitemap XML, redirect, breadcrumb, analisi contenuti. Niente servizi esterni, niente tracciamento, niente nag. Snippet di analytics gestiti tramite consent gate. Integrazione nativa con DB Cookie Manager 3.1.0+ e DB Privacy Hub 1.0.0+.
 * Version:           1.4.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Davide Bertolino
 * Author URI:        https://www.davidebertolino.it
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       db-seo-manager
 * Domain Path:       /languages
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

/* -------------------------------------------------------------------------
 * Costanti
 * ---------------------------------------------------------------------- */
define('DBSEO_VERSION',      '1.4.0');
define('DBSEO_PLUGIN_FILE',  __FILE__);
define('DBSEO_PLUGIN_DIR',   plugin_dir_path(__FILE__));
define('DBSEO_PLUGIN_URL',   plugin_dir_url(__FILE__));
define('DBSEO_PLUGIN_BASE',  plugin_basename(__FILE__));
define('DBSEO_TEXT_DOMAIN',  'db-seo-manager');
define('DBSEO_OPTION_KEY',   'dbseo_settings');
define('DBSEO_DB_VERSION',   '1.0.0');

/* -------------------------------------------------------------------------
 * GitHub Auto-Updater (componente condiviso)
 * ---------------------------------------------------------------------- */
require_once DBSEO_PLUGIN_DIR . 'inc/class-updater.php';
new DB_GitHub_Updater(__FILE__, 'dadebertolino', 'db-seo-manager');

/* -------------------------------------------------------------------------
 * Bootstrap del plugin
 * ---------------------------------------------------------------------- */
require_once DBSEO_PLUGIN_DIR . 'inc/class-core.php';

/**
 * Avvia il plugin dopo il caricamento dei plugin (così altri possono filtrare).
 */
function dbseo_boot() {
    DBSEO_Core::instance();
}
add_action('plugins_loaded', 'dbseo_boot', 5);

/**
 * Carica le traduzioni.
 */
function dbseo_load_textdomain() {
    load_plugin_textdomain(
        DBSEO_TEXT_DOMAIN,
        false,
        dirname(DBSEO_PLUGIN_BASE) . '/languages'
    );
}
add_action('init', 'dbseo_load_textdomain');

/* -------------------------------------------------------------------------
 * Activation / Deactivation
 * ---------------------------------------------------------------------- */
register_activation_hook(__FILE__, 'dbseo_activate');
register_deactivation_hook(__FILE__, 'dbseo_deactivate');

/**
 * Attivazione: crea tabelle, opzioni di default, flush rewrite per sitemap.
 * Le opzioni di default sono tutte privacy-safe (integrazioni esterne disattive).
 */
function dbseo_activate() {
    require_once DBSEO_PLUGIN_DIR . 'inc/class-core.php';

    // Verifica requisiti minimi.
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(DBSEO_PLUGIN_BASE);
        wp_die(
            esc_html__('DB SEO Manager richiede PHP 7.4 o superiore.', 'db-seo-manager'),
            'DB SEO Manager',
            array('back_link' => true)
        );
    }
    if (version_compare(get_bloginfo('version'), '5.8', '<')) {
        deactivate_plugins(DBSEO_PLUGIN_BASE);
        wp_die(
            esc_html__('DB SEO Manager richiede WordPress 5.8 o superiore.', 'db-seo-manager'),
            'DB SEO Manager',
            array('back_link' => true)
        );
    }

    DBSEO_Core::install();

    // Flush per registrare gli endpoint sitemap.
    flush_rewrite_rules();
}

/**
 * Disattivazione: niente di distruttivo. La rimozione completa avviene in uninstall.php.
 */
function dbseo_deactivate() {
    flush_rewrite_rules();
    wp_clear_scheduled_hook('dbseo_cron_broken_links');
    wp_clear_scheduled_hook('dbseo_cron_indexnow_flush');
}

/* -------------------------------------------------------------------------
 * Admin notice: suggerisce DB Privacy Hub
 *
 * Dalla 1.3.0 il registro privacy unificato (vecchia "Privacy SEO") è stato
 * estratto nel plugin dedicato DB Privacy Hub. Questa notice viene mostrata
 * una sola volta agli admin che aggiornano dalla 1.2.x alla 1.3.0+, finché
 * l'Hub non viene installato o la notice viene chiusa.
 * ---------------------------------------------------------------------- */

add_action('admin_notices', 'dbseo_privacy_hub_notice');
add_action('wp_ajax_dbseo_dismiss_privacy_hub_notice', 'dbseo_dismiss_privacy_hub_notice');

function dbseo_privacy_hub_notice() {
    if (!current_user_can('manage_options')) return;
    if (class_exists('DBPH_Register')) return; // Hub già installato.
    if (get_user_meta(get_current_user_id(), 'dbseo_privacy_hub_notice_dismissed', true)) return;

    $msg = sprintf(
        /* translators: 1: link al sito del plugin */
        wp_kses(
            __('<strong>DB SEO Manager 1.3.0:</strong> il registro privacy unificato è stato spostato nel nuovo plugin dedicato <a href="%s" target="_blank" rel="noopener">DB Privacy Hub</a>. Installalo per generare automaticamente la Privacy Policy completa del sito.', 'db-seo-manager'),
            array('strong' => array(), 'a' => array('href' => array(), 'target' => array(), 'rel' => array()))
        ),
        'https://www.davidebertolino.it/progetti/db-privacy-hub/'
    );
    ?>
    <div class="notice notice-info is-dismissible" data-dbseo-notice="privacy-hub">
        <p><?php echo $msg; // already escaped via wp_kses ?></p>
    </div>
    <script>
    (function(){
        var notice = document.querySelector('[data-dbseo-notice="privacy-hub"]');
        if (!notice) return;
        notice.addEventListener('click', function(e){
            if (!e.target.classList.contains('notice-dismiss')) return;
            var fd = new FormData();
            fd.append('action', 'dbseo_dismiss_privacy_hub_notice');
            fd.append('_wpnonce', '<?php echo esc_js(wp_create_nonce('dbseo_dismiss_ph_notice')); ?>');
            fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
        });
    })();
    </script>
    <?php
}

function dbseo_dismiss_privacy_hub_notice() {
    if (!current_user_can('manage_options')) wp_die('', '', array('response' => 403));
    check_ajax_referer('dbseo_dismiss_ph_notice', '_wpnonce');
    update_user_meta(get_current_user_id(), 'dbseo_privacy_hub_notice_dismissed', 1);
    wp_send_json_success();
}
