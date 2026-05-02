<?php
/**
 * DBSEO_Core — Bootstrap singleton.
 *
 * Responsabilità:
 *  - Caricare i moduli del plugin (frontend, admin, schema, sitemap, ecc.)
 *  - Mantenere la registry delle opzioni globali con default privacy-safe
 *  - Gestire installazione/upgrade/uninstall delle tabelle DB
 *  - Esporre helper per accedere alle impostazioni
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Core {

    /** @var DBSEO_Core|null */
    private static $instance = null;

    /** @var array Cache delle impostazioni unite con i default. */
    private $settings = null;

    /** @var array Moduli caricati (slug => istanza). */
    private $modules = array();

    /**
     * Singleton accessor.
     *
     * IMPORTANTE: l'istanza viene assegnata PRIMA di eseguire __construct().
     * Diversi moduli (Schema, Sitemap, Redirects, 404 Monitor, Breadcrumbs,
     * IndexNow, Hardening) chiamano DBSEO_Core::instance()->get(...) dentro
     * il proprio costruttore. Se assegnassimo self::$instance dopo new self(),
     * quei moduli — istanziati da init_modules() durante __construct di Core —
     * troverebbero self::$instance ancora null e creerebbero un secondo Core,
     * innescando ricorsione infinita e memory exhaustion.
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->boot();
        }
        return self::$instance;
    }

    /**
     * Costruttore privato: solo inizializzazione minimale.
     *
     * NON istanzia moduli qui — vedi boot(). I moduli vengono caricati solo
     * dopo che self::$instance è stato assegnato in instance(), così le loro
     * chiamate a DBSEO_Core::instance() risolvono correttamente senza
     * ri-entrare in new self().
     */
    private function __construct() {
        // No-op: tutto il bootstrap è in boot().
    }

    /**
     * Bootstrap: include file moduli, istanzia singletons, registra hook.
     * Chiamato una sola volta da instance() dopo l'assegnazione di self::$instance.
     */
    private function boot() {
        $this->load_dependencies();
        $this->init_modules();

        // Auto-upgrade DB schema se necessario.
        add_action('plugins_loaded', array($this, 'maybe_upgrade_db'), 10);
    }

    /* =====================================================================
     * Defaults — TUTTI privacy-safe.
     * Le integrazioni esterne sono OFF di default. L'utente deve attivarle
     * esplicitamente leggendo l'avviso GDPR.
     * ================================================================== */

    /**
     * Restituisce i default delle impostazioni.
     *
     * @return array
     */
    public static function get_defaults() {
        return array(

            // -- Generali --
            'separator'              => '-',
            'site_name'              => '',           // Vuoto = usa get_bloginfo('name')

            // -- Titoli & meta description --
            'title_home'             => '%sitename% %sep% %tagline%',
            'title_post'             => '%title% %sep% %sitename%',
            'title_page'             => '%title% %sep% %sitename%',
            'title_archive'          => '%archive_title% %sep% %sitename%',
            'title_search'           => '%search_query% %sep% %sitename%',
            'title_404'              => '404 %sep% %sitename%',
            'desc_home'              => '',
            'desc_post'              => '%excerpt%',
            'desc_page'              => '%excerpt%',

            // -- Robots --
            'noindex_search'         => true,
            'noindex_404'            => true,
            'noindex_author'         => false,
            'noindex_date'           => true,
            'noindex_attachment'     => true,

            // -- OpenGraph / Twitter --
            'og_enabled'             => true,
            'og_default_image_id'    => 0,
            'twitter_card'           => 'summary_large_image',
            'twitter_site'           => '',           // @handle, opzionale

            // -- Schema.org --
            'schema_enabled'         => true,
            'schema_org_type'        => 'Organization', // Organization | Person
            'schema_org_name'        => '',
            'schema_org_logo_id'     => 0,
            'schema_org_telephone'   => '',
            'schema_org_email'       => '',

            // -- Sitemap --
            'sitemap_enabled'        => true,
            'sitemap_post_types'     => array('post', 'page'),
            'sitemap_taxonomies'     => array('category'),
            'sitemap_exclude_ids'    => array(),

            // -- Breadcrumb --
            'breadcrumb_enabled'     => true,
            'breadcrumb_home_label'  => 'Home',
            'breadcrumb_separator'   => '/',

            // -- Redirect & 404 --
            'redirects_enabled'      => true,
            'monitor_404_enabled'    => false,        // OFF di default (anche se anonimo)
            'monitor_404_log_ip'     => false,        // MAI consigliato. Resta off.
            'monitor_404_keep_days'  => 30,           // Auto-pulizia log.

            // -- robots.txt virtuale --
            'robots_txt_custom'      => '',

            // -- IndexNow (server-to-server, no cookie utente) --
            'indexnow_enabled'       => false,
            'indexnow_key'           => '',           // Generato alla prima attivazione.

            // -- Hard Privacy mode (toggle globale) --
            // Quando attivo: forza analytics_enabled=false, meta_pixel_enabled=false,
            // indexnow_enabled=false, e applica gli hardening filters.
            'hard_privacy_enabled'   => false,
            'hard_privacy_strip_emoji'      => true,
            'hard_privacy_strip_oembed'     => true,
            'hard_privacy_strip_dns_prefetch' => true,
            'hard_privacy_disable_gravatar' => true,
            'hard_privacy_strip_google_fonts' => false, // Off di default: rischio rottura temi.
            'hard_privacy_disable_xmlrpc'   => true,
            'hard_privacy_disable_rest_api_users' => true, // /wp-json/wp/v2/users → 401 ai non autenticati

            // -- Redirect: zero counting opzionale --
            'redirects_no_count'     => false,        // Se true, non incrementa nemmeno gli hit aggregati.

            // -- Cache audit terze parti --
            'audit_last_run'         => 0,
            'audit_last_results'     => array(),

            // -- Verifiche search engine (solo meta tag) --
            'verify_google'          => '',
            'verify_bing'            => '',
            'verify_yandex'          => '',
            'verify_pinterest'       => '',

            // -- Integrazioni analytics (gated da consenso) --
            // Tutte OFF di default. Quando ON, lo snippet viene emesso SOLO
            // dopo il segnale di consenso (vedi class-consent-gate.php).
            'analytics_enabled'      => false,
            'analytics_provider'     => 'none',       // none | ga4 | gtm | matomo | plausible | umami
            'analytics_id'           => '',
            'analytics_consent_mode' => true,         // GA4 Consent Mode v2
            'analytics_anonymize_ip' => true,
            'meta_pixel_enabled'     => false,
            'meta_pixel_id'          => '',

            // -- DB version (per upgrade schema) --
            'db_version'             => DBSEO_DB_VERSION,
        );
    }

    /* =====================================================================
     * Helper accesso impostazioni
     * ================================================================== */

    /**
     * Restituisce l'array completo delle impostazioni (saved + defaults).
     */
    public function get_settings() {
        if ($this->settings === null) {
            $saved = get_option(DBSEO_OPTION_KEY, array());
            if (!is_array($saved)) {
                $saved = array();
            }
            $this->settings = wp_parse_args($saved, self::get_defaults());
        }
        return $this->settings;
    }

    /**
     * Restituisce una singola opzione.
     *
     * @param string $key
     * @param mixed  $fallback
     * @return mixed
     */
    public function get($key, $fallback = null) {
        $s = $this->get_settings();
        return array_key_exists($key, $s) ? $s[$key] : $fallback;
    }

    /**
     * Aggiorna una o più opzioni.
     *
     * @param array $updates
     * @return bool
     */
    public function update($updates) {
        $current = $this->get_settings();
        $new     = array_merge($current, $updates);
        $this->settings = $new;
        return update_option(DBSEO_OPTION_KEY, $new);
    }

    /* =====================================================================
     * Caricamento moduli
     * ================================================================== */

    private function load_dependencies() {
        // I file vengono inclusi solo se esistono — ci permette uno sviluppo
        // incrementale modulo per modulo senza fatal error.
        $files = array(
            'inc/class-frontend.php',
            'inc/class-schema.php',
            'inc/class-sitemap.php',
            'inc/class-robots.php',
            'inc/class-redirects.php',
            'inc/class-404-monitor.php',
            'inc/class-breadcrumbs.php',
            'inc/class-consent-gate.php',
            'inc/class-indexnow.php',
            'inc/class-wp-privacy-tools.php',
            'inc/class-importer.php',
            'inc/class-metabox.php',
            'inc/class-analyzer.php',
            'inc/class-hardening.php',
            'inc/class-audit.php',
            'inc/class-settings.php',
        );
        foreach ($files as $rel) {
            $path = DBSEO_PLUGIN_DIR . $rel;
            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    private function init_modules() {
        // Ogni modulo è un singleton. Si registrano qui per poter essere
        // recuperati via DBSEO_Core::instance()->module('frontend').
        $map = array(
            'consent_gate' => 'DBSEO_Consent_Gate',
            'frontend'     => 'DBSEO_Frontend',
            'schema'       => 'DBSEO_Schema',
            'sitemap'      => 'DBSEO_Sitemap',
            'robots'       => 'DBSEO_Robots',
            'redirects'    => 'DBSEO_Redirects',
            'monitor_404'  => 'DBSEO_404_Monitor',
            'breadcrumbs'  => 'DBSEO_Breadcrumbs',
            'indexnow'         => 'DBSEO_IndexNow',
            'wp_privacy_tools' => 'DBSEO_WP_Privacy_Tools',
            'importer'         => 'DBSEO_Importer',
            'metabox'      => 'DBSEO_Metabox',
            'analyzer'     => 'DBSEO_Analyzer',
            'hardening'    => 'DBSEO_Hardening',
            'audit'        => 'DBSEO_Audit',
            'settings'     => 'DBSEO_Settings',
        );

        foreach ($map as $slug => $class) {
            if (class_exists($class) && method_exists($class, 'instance')) {
                $this->modules[$slug] = call_user_func(array($class, 'instance'));
            }
        }

        do_action('dbseo_modules_loaded', $this);
    }

    /**
     * Recupera un modulo per slug.
     *
     * @param string $slug
     * @return object|null
     */
    public function module($slug) {
        return $this->modules[$slug] ?? null;
    }

    /* =====================================================================
     * Install / Upgrade / Uninstall
     * ================================================================== */

    /**
     * Installa tabelle DB e opzioni di default. Idempotente.
     */
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $tbl_redirects = $wpdb->prefix . 'dbseo_redirects';
        $sql = "CREATE TABLE {$tbl_redirects} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source VARCHAR(2048) NOT NULL,
            source_hash CHAR(40) NOT NULL,
            target VARCHAR(2048) NOT NULL,
            type SMALLINT(3) NOT NULL DEFAULT 301,
            is_regex TINYINT(1) NOT NULL DEFAULT 0,
            hits BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            last_hit DATETIME NULL,
            created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY source_hash (source_hash)
        ) {$charset};";
        dbDelta($sql);

        $tbl_404 = $wpdb->prefix . 'dbseo_404_log';
        $sql = "CREATE TABLE {$tbl_404} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            url VARCHAR(2048) NOT NULL,
            url_hash CHAR(40) NOT NULL,
            referer VARCHAR(2048) NULL,
            count BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
            last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY url_hash (url_hash)
        ) {$charset};";
        dbDelta($sql);

        $tbl_indexnow = $wpdb->prefix . 'dbseo_indexnow_queue';
        $sql = "CREATE TABLE {$tbl_indexnow} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            url VARCHAR(2048) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts SMALLINT(3) NOT NULL DEFAULT 0,
            queued DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            submitted DATETIME NULL,
            PRIMARY KEY  (id),
            KEY status (status)
        ) {$charset};";
        dbDelta($sql);

        // Opzioni: solo se non esistono già, per non sovrascrivere config utente.
        if (get_option(DBSEO_OPTION_KEY) === false) {
            add_option(DBSEO_OPTION_KEY, self::get_defaults(), '', 'yes');
        }
    }

    /**
     * Esegue upgrade dello schema se la versione DB salvata è < di DBSEO_DB_VERSION.
     * Eseguito anche just-in-time perché alcune hosting non lanciano i register_activation_hook.
     */
    public function maybe_upgrade_db() {
        $saved = $this->get('db_version', '0.0.0');
        if (version_compare($saved, DBSEO_DB_VERSION, '<')) {
            self::install();
            $this->update(array('db_version' => DBSEO_DB_VERSION));
        }
    }
}
