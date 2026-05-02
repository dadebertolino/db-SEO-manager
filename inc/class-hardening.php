<?php
/**
 * DBSEO_Hardening — Hard Privacy mode.
 *
 * Quando il toggle hard_privacy_enabled è attivo, applica una serie di hook
 * protettivi che riducono il fingerprinting e i caricamenti di terze parti
 * lato frontend. Funzionalità modulari (ognuna ha il suo flag).
 *
 * IMPORTANTE: alcune di queste opzioni possono rompere temi/plugin che
 * dipendono da Gravatar, oEmbed, emoji, ecc. Per questo:
 *  - Hard Privacy è OFF di default.
 *  - Le sotto-opzioni più rischiose (Google Fonts replacement) sono OFF anche
 *    quando Hard Privacy è ON, vanno attivate esplicitamente.
 *  - L'admin viene avvisato dalla UI.
 *
 * Effetti quando attivo:
 *  - Forza analytics_enabled, meta_pixel_enabled, indexnow_enabled a false.
 *    Questi flag vengono "letti" come false anche se nel DB sono true, finché
 *    Hard Privacy resta on. (Soft enforcement: il valore originale è preservato
 *    e tornerà attivo se Hard Privacy viene disattivato.)
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Hardening {

    /** @var DBSEO_Hardening|null */
    private static $instance = null;

    /** @var array Statistiche di hardening per la UI di stato. */
    private $stats = array(
        'emoji_stripped'     => false,
        'oembed_stripped'    => false,
        'dns_prefetch'       => false,
        'gravatar_blocked'   => false,
        'google_fonts'       => false,
        'xmlrpc_disabled'    => false,
        'rest_users_blocked' => false,
    );

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $core = DBSEO_Core::instance();
        if (!$core->get('hard_privacy_enabled')) return;

        // Soft enforcement dei flag esterni: filtriamo le opzioni in lettura.
        add_filter('option_' . DBSEO_OPTION_KEY, array($this, 'enforce_external_off'));

        if ($core->get('hard_privacy_strip_emoji'))          $this->strip_emojis();
        if ($core->get('hard_privacy_strip_oembed'))         $this->strip_oembed();
        if ($core->get('hard_privacy_strip_dns_prefetch'))   $this->strip_dns_prefetch();
        if ($core->get('hard_privacy_disable_gravatar'))     $this->disable_gravatar();
        if ($core->get('hard_privacy_strip_google_fonts'))   $this->strip_google_fonts();
        if ($core->get('hard_privacy_disable_xmlrpc'))       $this->disable_xmlrpc();
        if ($core->get('hard_privacy_disable_rest_api_users')) $this->protect_rest_users();
    }

    /* =====================================================================
     * Soft enforcement
     * ================================================================== */

    /**
     * Quando Hard Privacy è on, forziamo i flag delle integrazioni esterne a false
     * in lettura. Questo NON modifica il DB: se l'admin disattiva Hard Privacy,
     * le impostazioni originali tornano in vigore.
     *
     * Eseguito via filter option_* per intercettare ogni get_option('dbseo_settings').
     */
    public function enforce_external_off($value) {
        if (!is_array($value)) return $value;
        $value['analytics_enabled']   = false;
        $value['meta_pixel_enabled']  = false;
        $value['indexnow_enabled']    = false;
        return $value;
    }

    /* =====================================================================
     * Modules
     * ================================================================== */

    /**
     * Rimuove gli script di rilevamento emoji e gli stili relativi.
     * WP carica wp-emoji-release.min.js e fa una matrice di test che produce
     * fingerprint del browser. Bloccarlo è privacy-friendly e velocizza il sito.
     */
    private function strip_emojis() {
        remove_action('wp_head',             'print_emoji_detection_script', 7);
        remove_action('admin_print_scripts', 'print_emoji_detection_script');
        remove_action('wp_print_styles',     'print_emoji_styles');
        remove_action('admin_print_styles',  'print_emoji_styles');
        remove_filter('the_content_feed',    'wp_staticize_emoji');
        remove_filter('comment_text_rss',    'wp_staticize_emoji');
        remove_filter('wp_mail',             'wp_staticize_emoji_for_email');
        // Rimuove anche il prefetch verso s.w.org.
        add_filter('emoji_svg_url', '__return_false');
        $this->stats['emoji_stripped'] = true;
    }

    /**
     * Rimuove gli endpoint oEmbed di WP che possono esporre dati ad altri siti.
     */
    private function strip_oembed() {
        remove_action('wp_head', 'wp_oembed_add_discovery_links');
        remove_action('wp_head', 'wp_oembed_add_host_js');
        remove_action('rest_api_init', 'wp_oembed_register_route');
        add_filter('embed_oembed_discover', '__return_false');
        $this->stats['oembed_stripped'] = true;
    }

    /**
     * Rimuove i DNS prefetch automatici di WP (s.w.org per emoji, ecc.).
     */
    private function strip_dns_prefetch() {
        remove_action('wp_head', 'wp_resource_hints', 2);
        // Variante più chirurgica: filtro che rimuove SOLO i prefetch verso domini terzi.
        add_filter('wp_resource_hints', array($this, 'filter_resource_hints'), 99, 2);
        $this->stats['dns_prefetch'] = true;
    }

    public function filter_resource_hints($urls, $relation) {
        if (!in_array($relation, array('dns-prefetch', 'preconnect'), true)) return $urls;
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        return array_values(array_filter((array) $urls, function($url) use ($home_host) {
            $host = is_string($url) ? wp_parse_url($url, PHP_URL_HOST) : '';
            return $host === '' || $host === $home_host;
        }));
    }

    /**
     * Disabilita Gravatar: tutti gli avatar diventano placeholder locali.
     * Gravatar invia hash MD5 dell'email a Automattic. Rimuoverlo è una scelta
     * privacy-friendly comune.
     */
    private function disable_gravatar() {
        add_filter('get_avatar_url', array($this, 'replace_gravatar_url'), 99);
        add_filter('pre_get_avatar_data', array($this, 'replace_gravatar_data'), 99, 2);
        $this->stats['gravatar_blocked'] = true;
    }

    public function replace_gravatar_url($url) {
        // Restituisce un placeholder SVG inline (data URI), zero dipendenze.
        return $this->placeholder_avatar_uri();
    }

    public function replace_gravatar_data($args, $id_or_email) {
        $args['url']     = $this->placeholder_avatar_uri();
        $args['found_avatar'] = true;
        return $args;
    }

    private function placeholder_avatar_uri() {
        // SVG semplice, neutro: cerchio grigio con iniziale generica.
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96">'
             . '<circle cx="48" cy="48" r="48" fill="#dcdcde"/>'
             . '<circle cx="48" cy="38" r="14" fill="#a7aaad"/>'
             . '<path d="M20 88c4-16 14-24 28-24s24 8 28 24z" fill="#a7aaad"/>'
             . '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * Strip dei Google Fonts caricati dal tema/plugin.
     * Va attivato esplicitamente: ad alto rischio di rottura visiva.
     * Filtra l'src degli stili e rimuove quelli che puntano a fonts.googleapis.com
     * o fonts.gstatic.com.
     */
    private function strip_google_fonts() {
        add_filter('style_loader_src', array($this, 'filter_google_fonts_src'), 99, 2);
        $this->stats['google_fonts'] = true;
    }

    public function filter_google_fonts_src($src, $handle) {
        if (!is_string($src) || $src === '') return $src;
        if (strpos($src, 'fonts.googleapis.com') !== false || strpos($src, 'fonts.gstatic.com') !== false) {
            return false; // false rimuove lo stile dalla queue.
        }
        return $src;
    }

    /**
     * Disabilita XML-RPC: vettore di attacco e di exfiltration spesso non necessario.
     */
    private function disable_xmlrpc() {
        add_filter('xmlrpc_enabled', '__return_false');
        add_filter('wp_headers', array($this, 'remove_xpingback_header'));
        remove_action('wp_head', 'rsd_link');
        remove_action('wp_head', 'wlwmanifest_link');
        $this->stats['xmlrpc_disabled'] = true;
    }

    public function remove_xpingback_header($headers) {
        unset($headers['X-Pingback']);
        return $headers;
    }

    /**
     * Protegge l'endpoint REST /wp/v2/users dalle enumerazioni anonime.
     * Gli utenti non autenticati ricevono 401. Quelli autenticati con permessi
     * (list_users) accedono normalmente.
     */
    private function protect_rest_users() {
        add_filter('rest_authentication_errors', array($this, 'filter_rest_users'), 99);
        $this->stats['rest_users_blocked'] = true;
    }

    public function filter_rest_users($result) {
        if (!empty($result)) return $result;

        $route = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (strpos($route, '/wp-json/wp/v2/users') === false) return $result;
        if (is_user_logged_in()) return $result;

        return new WP_Error(
            'rest_user_cannot_view',
            __('Accesso negato.', 'db-seo-manager'),
            array('status' => 401)
        );
    }

    /* =====================================================================
     * Status reporting per la UI
     * ================================================================== */

    public function get_active_modules() {
        return $this->stats;
    }
}
