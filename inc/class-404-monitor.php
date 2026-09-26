<?php
/**
 * DBSEO_404_Monitor — Monitor 404 anonimo.
 *
 * Privacy by design:
 *  - DEFAULT: spento. L'admin deve attivarlo esplicitamente.
 *  - Quando attivo, salva SOLO: percorso dell'URL richiesto, referer (se
 *    presente), conteggio, timestamp dell'ultimo evento. NESSUN IP, NESSUN
 *    user-agent.
 *  - Query string scartate sia dall'URL sia dal referer (dalla 1.5.0): possono
 *    contenere token, email, click ID (fbclid/gclid). Whitelist opzionale di
 *    parametri via filter dbseo_404_allowed_query_args (default vuota).
 *  - L'opzione 'monitor_404_log_ip' esiste ma è hardcoded come "sconsigliato"
 *    nei default e non esposta in UI con la stessa enfasi delle altre.
 *  - Auto-pulizia delle righe più vecchie di N giorni via WP-Cron. Il cron
 *    resta attivo anche a monitor spento, così i dati già raccolti rispettano
 *    comunque il periodo di conservazione dichiarato.
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
        // Cron di pulizia: registrato SEMPRE (anche a monitor spento), così le
        // righe già salvate vengono comunque eliminate alla scadenza del
        // periodo di conservazione. Una DELETE al giorno su una tabella
        // piccola: costo trascurabile.
        add_action('dbseo_cron_404_cleanup', array($this, 'cleanup'));
        if (!wp_next_scheduled('dbseo_cron_404_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'dbseo_cron_404_cleanup');
        }

        if (!DBSEO_Core::instance()->get('monitor_404_enabled')) return;

        // Logging: priorità tardiva su template_redirect, dopo che redirect manager
        // ha avuto la sua chance.
        add_action('template_redirect', array($this, 'maybe_log'), 999);
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
        // Solo percorso (+ eventuali parametri in whitelist): la query string
        // può contenere dati personali (token, email, fbclid/gclid…).
        $url = $this->strip_request_query($url);
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

        // Opzionale: logga solo 404 con referer interno (link rotti "veri",
        // ignora gli scan dei bot che non inviano referer o arrivano da fuori).
        if (DBSEO_Core::instance()->get('monitor_404_internal_referer_only')) {
            $home_host = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
            $ref_host  = strtolower((string) wp_parse_url($referer, PHP_URL_HOST));
            if ($referer === '' || $ref_host !== $home_host) return;
        }

        $this->insert_or_increment($url, $referer);

        // Anti-flooding: enforcement probabilistico del tetto righe (1 su 50),
        // enforcement deterministico nel cron giornaliero di cleanup.
        if (wp_rand(1, 50) === 1) {
            $this->enforce_max_rows();
        }

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

        // Pattern regex configurabili (uno per riga, case-insensitive).
        // Default: probe di vulnerabilità (.php, .env, .git) e asset statici.
        $custom = (string) DBSEO_Core::instance()->get('monitor_404_exclude_patterns', '');
        foreach (preg_split('/\r\n|\r|\n/', $custom) as $pattern) {
            $pattern = trim($pattern);
            if ($pattern === '') continue;
            if (@preg_match('#' . str_replace('#', '\#', $pattern) . '#i', $url)) return true;
        }
        return false;
    }

    /**
     * Tetto righe anti-flooding: elimina le voci meno rilevanti
     * (count più basso, più vecchie) oltre monitor_404_max_rows.
     */
    public function enforce_max_rows() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_404_log';
        $max = max(100, (int) DBSEO_Core::instance()->get('monitor_404_max_rows', 5000));

        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$tbl}");
        if ($total <= $max) return;

        $excess = $total - $max;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$tbl} ORDER BY count ASC, last_seen ASC LIMIT %d",
            $excess
        ));
    }

    private function truncate_url($url) {
        return mb_substr($url, 0, 2000);
    }

    /**
     * Rimuove username:password, query string e frammento; tronca il referer.
     */
    private function sanitize_referer($referer) {
        $parsed = wp_parse_url($referer);
        if (!$parsed || empty($parsed['host'])) return '';
        // Ricostruzione minima: schema + host + path (+ parametri in whitelist).
        $clean = (isset($parsed['scheme']) ? $parsed['scheme'] : 'https') . '://' . $parsed['host'];
        if (!empty($parsed['port']))  $clean .= ':' . $parsed['port'];
        if (!empty($parsed['path']))  $clean .= $parsed['path'];
        $query = $this->filter_query(isset($parsed['query']) ? $parsed['query'] : '');
        if ($query !== '') $clean .= '?' . $query;
        return mb_substr($clean, 0, 2000);
    }

    /**
     * Riduce un REQUEST_URI al solo percorso, più gli eventuali parametri
     * ammessi in whitelist.
     *
     * @param string $uri
     * @return string
     */
    private function strip_request_query($uri) {
        $uri   = (string) $uri;
        $uri   = explode('#', $uri, 2)[0];
        $parts = explode('?', $uri, 2);
        $path  = $parts[0];
        $query = $this->filter_query(isset($parts[1]) ? $parts[1] : '');
        return $query !== '' ? $path . '?' . $query : $path;
    }

    /**
     * Mantiene solo i parametri in whitelist (default: nessuno).
     *
     * Filter dbseo_404_allowed_query_args: array di nomi di parametro da
     * conservare (es. array('lang', 'page_id')). Da usare solo per parametri
     * che non possono contenere dati personali.
     *
     * @param string $query
     * @return string
     */
    private function filter_query($query) {
        if ($query === '') return '';
        $allowed = array_filter(array_map('strval', (array) apply_filters('dbseo_404_allowed_query_args', array())));
        if (empty($allowed)) return '';

        $args = array();
        wp_parse_str($query, $args);
        $kept = array_intersect_key($args, array_flip($allowed));
        return empty($kept) ? '' : http_build_query($kept);
    }

    /**
     * Migrazione (DB 1.1.0): rimuove le query string dalle righe salvate
     * dalle versioni precedenti. Gli URL che diventano duplicati di una
     * riga già esistente vengono eliminati (UPDATE IGNORE + DELETE).
     */
    public static function strip_stored_query_strings() {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_404_log';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tbl)) !== $tbl) return;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nome tabella da $wpdb->prefix, nessun input utente.
        $wpdb->query("UPDATE {$tbl} SET referer = SUBSTRING_INDEX(referer, '?', 1) WHERE referer LIKE '%?%'");
        $wpdb->query(
            "UPDATE IGNORE {$tbl}
             SET url = SUBSTRING_INDEX(url, '?', 1),
                 url_hash = SHA1(LOWER(SUBSTRING_INDEX(url, '?', 1)))
             WHERE url LIKE '%?%'"
        );
        $wpdb->query("DELETE FROM {$tbl} WHERE url LIKE '%?%'");
        // phpcs:enable
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

        // Enforcement deterministico del tetto righe.
        $this->enforce_max_rows();
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
