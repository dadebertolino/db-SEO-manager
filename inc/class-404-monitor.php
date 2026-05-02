<?php
/**
 * DBSEO_404_Monitor — Monitor 404 anonimo.
 *
 * Privacy by design:
 *  - DEFAULT: spento. L'admin deve attivarlo esplicitamente.
 *  - Quando attivo, salva SOLO: URL richiesto, referer (se presente), conteggio,
 *    timestamp dell'ultimo evento. NESSUN IP, NESSUN user-agent.
 *  - L'opzione 'monitor_404_log_ip' esiste ma è hardcoded come "sconsigliato"
 *    nei default e non esposta in UI con la stessa enfasi delle altre.
 *  - Auto-pulizia delle righe più vecchie di N giorni via WP-Cron.
 *
 * Nota tecnica:
 *  - Aggregazione: stesso URL → incrementa il contatore della riga esistente.
 *    Tabella con UNIQUE KEY su url_hash, INSERT...ON DUPLICATE KEY UPDATE.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_404_Monitor {

    /** @var DBSEO_404_Monitor|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!DBSEO_Core::instance()->get('monitor_404_enabled')) return;

        // Logging: priorità tardiva su template_redirect, dopo che redirect manager
        // ha avuto la sua chance.
        add_action('template_redirect', array($this, 'maybe_log'), 999);

        // Cron di pulizia.
        add_action('dbseo_cron_404_cleanup', array($this, 'cleanup'));
        if (!wp_next_scheduled('dbseo_cron_404_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dbseo_cron_404_cleanup');
        }
    }

    /**
     * Logga il 404 corrente, in forma anonima.
     */
    public function maybe_log() {
        if (!is_404()) return;
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) return;
        if (is_user_logged_in() && current_user_can('manage_options')) {
            // Non loggare i 404 dell'admin durante navigazione admin (rumore).
            return;
        }

        $url = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($url === '') return;

        // Filter pubblico per blacklist (es. ignora /wp-json/, /favicon.ico).
        if (apply_filters('dbseo_404_should_ignore', $this->should_ignore($url), $url)) {
            return;
        }

        $url     = $this->truncate_url($url);
        $referer = isset($_SERVER['HTTP_REFERER']) ? wp_unslash($_SERVER['HTTP_REFERER']) : '';

        // Sicurezza: tronchiamo anche il referer e ne stripiamo eventuali credenziali.
        if ($referer !== '') {
            $referer = $this->sanitize_referer($referer);
        }

        $this->insert_or_increment($url, $referer);

        do_action('dbseo_404_logged', $url);
    }

    private function should_ignore($url) {
        $ignore_patterns = array(
            '/wp-json/',
            '/wp-login.php',
            '/wp-admin/',
            '/favicon.ico',
            '/robots.txt',
            '/.well-known/',
            '/sitemap.xml',
            '/sitemap-',
        );
        foreach ($ignore_patterns as $p) {
            if (strpos($url, $p) !== false) return true;
        }
        return false;
    }

    private function truncate_url($url) {
        return mb_substr($url, 0, 2000);
    }

    /**
     * Rimuove username:password e tronca il referer.
     */
    private function sanitize_referer($referer) {
        $parsed = wp_parse_url($referer);
        if (!$parsed || empty($parsed['host'])) return '';
        // Ricostruzione minima: schema + host + path + query.
        $clean = (isset($parsed['scheme']) ? $parsed['scheme'] : 'https') . '://' . $parsed['host'];
        if (!empty($parsed['port']))  $clean .= ':' . $parsed['port'];
        if (!empty($parsed['path']))  $clean .= $parsed['path'];
        if (!empty($parsed['query'])) $clean .= '?' . $parsed['query'];
        return mb_substr($clean, 0, 2000);
    }

    private function insert_or_increment($url, $referer) {
        global $wpdb;
        $tbl  = $wpdb->prefix . 'dbseo_404_log';
        $hash = sha1(strtolower($url));
        $now  = current_time('mysql');

        // INSERT ... ON DUPLICATE KEY UPDATE (richiede MySQL/MariaDB, OK per WP).
        $sql = $wpdb->prepare(
            "INSERT INTO {$tbl} (url, url_hash, referer, count, last_seen)
             VALUES (%s, %s, %s, 1, %s)
             ON DUPLICATE KEY UPDATE count = count + 1, last_seen = VALUES(last_seen), referer = VALUES(referer)",
            $url, $hash, $referer, $now
        );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($sql);
    }

    /**
     * Cron di pulizia: rimuove righe più vecchie di monitor_404_keep_days.
     */
    public function cleanup() {
        global $wpdb;
        $tbl  = $wpdb->prefix . 'dbseo_404_log';
        $days = max(1, (int) DBSEO_Core::instance()->get('monitor_404_keep_days', 30));

        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$tbl} WHERE last_seen < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }

    /* =====================================================================
     * Query API per la UI admin
     * ================================================================== */

    public function get_top($limit = 100, $offset = 0) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_404_log';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tbl}'") !== $tbl) return array();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, url, referer, count, last_seen
             FROM {$tbl}
             ORDER BY count DESC, last_seen DESC
             LIMIT %d OFFSET %d",
            (int) $limit, (int) $offset
        ));
    }

    public function delete_entry($id) {
        global $wpdb;
        return false !== $wpdb->delete(
            $wpdb->prefix . 'dbseo_404_log',
            array('id' => (int) $id),
            array('%d')
        );
    }

    public function clear_all() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_404_log';
        $wpdb->query("TRUNCATE TABLE {$tbl}");
    }
}
