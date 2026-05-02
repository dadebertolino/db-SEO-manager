<?php
/**
 * DBSEO_Redirects — Gestore redirect.
 *
 * Funzionalità:
 *  - CRUD su tabella wp_dbseo_redirects.
 *  - Match esatto (path + querystring opzionale) o regex.
 *  - Codici supportati: 301, 302, 307, 410 (gone, no destination).
 *  - Solo conteggio hit aggregato (no IP, no user-agent salvato).
 *  - Cache transient per ridurre query DB ad ogni request.
 *  - Hook pubblici: dbseo_redirect_before, dbseo_redirect_after.
 *
 * Privacy:
 *  - Il log è ANONIMO: incrementa solo "hits" e aggiorna "last_hit". Nessun
 *    identificatore utente viene mai salvato.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Redirects {

    const CACHE_KEY = 'dbseo_redirects_map';
    const CACHE_TTL = HOUR_IN_SECONDS;

    /** @var DBSEO_Redirects|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!DBSEO_Core::instance()->get('redirects_enabled')) return;

        // Match all'inizio del template_redirect, prima di qualsiasi rendering.
        add_action('template_redirect', array($this, 'match_and_redirect'), 1);

        // Invalidazione cache quando le regole cambiano (gli admin handler la chiamano).
    }

    /* =====================================================================
     * Matching engine
     * ================================================================== */

    public function match_and_redirect() {
        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }

        $request = $this->current_request();
        if ($request === '') return;

        $rules = $this->load_rules();

        // 1. Match esatto (case-insensitive sul path).
        $hash = $this->hash_source($request);
        if (isset($rules['exact'][$hash])) {
            $this->execute($rules['exact'][$hash], $request);
            return;
        }

        // Anche senza querystring (utile per match generici).
        $request_no_qs = strtok($request, '?');
        if ($request_no_qs !== $request) {
            $hash_qs = $this->hash_source($request_no_qs);
            if (isset($rules['exact'][$hash_qs])) {
                $this->execute($rules['exact'][$hash_qs], $request);
                return;
            }
        }

        // 2. Match regex.
        if (!empty($rules['regex'])) {
            foreach ($rules['regex'] as $rule) {
                $pattern = $rule['source'];
                // Sicurezza: il pattern arriva dal DB, validato in fase di salvataggio.
                $delim = '#';
                if (@preg_match($delim . $pattern . $delim . 'i', $request, $m)) {
                    $target = $rule['target'];
                    // Sostituzione $1, $2, ecc.
                    if (count($m) > 1) {
                        for ($i = count($m) - 1; $i >= 1; $i--) {
                            $target = str_replace('$' . $i, $m[$i], $target);
                        }
                    }
                    $rule['target'] = $target;
                    $this->execute($rule, $request);
                    return;
                }
            }
        }
    }

    private function execute($rule, $request) {
        $type   = (int) $rule['type'];
        $target = (string) $rule['target'];

        do_action('dbseo_redirect_before', $request, $target, $type, $rule);

        // Conteggio hit aggregato (no dati personali).
        // Skippato se l'admin ha attivato la modalità "no counting" per privacy zero-trust.
        if (!DBSEO_Core::instance()->get('redirects_no_count')) {
            $this->bump_hits((int) $rule['id']);
        }

        // 410 Gone: nessuna destination, content-type minimale.
        if ($type === 410) {
            status_header(410);
            nocache_headers();
            header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
            echo '<!DOCTYPE html><html lang="' . esc_attr(get_locale()) . '"><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '"><title>410 Gone</title></head><body><h1>410 Gone</h1></body></html>';
            do_action('dbseo_redirect_after', $request, '', $type, $rule);
            exit;
        }

        // Target relativo → assoluto.
        if (strpos($target, 'http') !== 0) {
            $target = home_url($target);
        }

        // Anti-loop: se il target coincide con la richiesta, abort.
        $target_path = wp_parse_url($target, PHP_URL_PATH);
        if ($target_path && rtrim($target_path, '/') === rtrim(strtok($request, '?'), '/')) {
            return;
        }

        wp_safe_redirect(esc_url_raw($target), $type, 'DB SEO Manager');
        do_action('dbseo_redirect_after', $request, $target, $type, $rule);
        exit;
    }

    private function current_request() {
        $uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        $uri = '/' . ltrim($uri, '/');
        return $uri;
    }

    /* =====================================================================
     * Caching delle regole
     * ================================================================== */

    private function load_rules() {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) return $cached;

        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_redirects';

        // Verifica esistenza tabella prima di interrogare (just-in-time per hosting capricciose).
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tbl}'") !== $tbl) {
            return array('exact' => array(), 'regex' => array());
        }

        $rows = $wpdb->get_results("SELECT id, source, source_hash, target, type, is_regex FROM {$tbl}", ARRAY_A);

        $map = array('exact' => array(), 'regex' => array());
        foreach ((array) $rows as $r) {
            if ((int) $r['is_regex'] === 1) {
                $map['regex'][] = $r;
            } else {
                $map['exact'][$r['source_hash']] = $r;
            }
        }

        set_transient(self::CACHE_KEY, $map, self::CACHE_TTL);
        return $map;
    }

    public static function flush_cache() {
        delete_transient(self::CACHE_KEY);
    }

    private function hash_source($source) {
        return sha1(strtolower(trim($source)));
    }

    private function bump_hits($id) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_redirects';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query($wpdb->prepare(
            "UPDATE {$tbl} SET hits = hits + 1, last_hit = %s WHERE id = %d",
            current_time('mysql'),
            $id
        ));
    }

    /* =====================================================================
     * CRUD pubblico (usato da admin UI)
     * ================================================================== */

    public function add_rule($source, $target, $type = 301, $is_regex = false) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_redirects';

        $source = $this->normalize_source($source);
        $target = trim($target);

        if ($source === '' || $target === '') return new WP_Error('invalid', __('Origine e destinazione sono obbligatorie.', 'db-seo-manager'));
        if (!in_array((int) $type, array(301, 302, 307, 410), true)) {
            return new WP_Error('invalid_type', __('Codice di redirect non valido.', 'db-seo-manager'));
        }

        // Validazione regex se necessario.
        if ($is_regex && @preg_match('#' . $source . '#i', '') === false) {
            return new WP_Error('invalid_regex', __('Espressione regolare non valida.', 'db-seo-manager'));
        }

        $hash = $this->hash_source($source);

        $result = $wpdb->insert($tbl, array(
            'source'      => $source,
            'source_hash' => $hash,
            'target'      => $target,
            'type'        => (int) $type,
            'is_regex'    => $is_regex ? 1 : 0,
            'created'     => current_time('mysql'),
        ), array('%s', '%s', '%s', '%d', '%d', '%s'));

        if ($result === false) {
            return new WP_Error('db_error', __('Errore di salvataggio (origine già esistente?).', 'db-seo-manager'));
        }

        self::flush_cache();
        return (int) $wpdb->insert_id;
    }

    public function update_rule($id, $data) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_redirects';

        $update = array();
        $format = array();

        if (isset($data['source'])) {
            $update['source']      = $this->normalize_source($data['source']);
            $update['source_hash'] = $this->hash_source($update['source']);
            $format[] = '%s'; $format[] = '%s';
        }
        if (isset($data['target']))   { $update['target']   = trim($data['target']);  $format[] = '%s'; }
        if (isset($data['type']))     { $update['type']     = (int) $data['type'];    $format[] = '%d'; }
        if (isset($data['is_regex'])) { $update['is_regex'] = $data['is_regex'] ? 1 : 0; $format[] = '%d'; }

        if (empty($update)) return false;

        $r = $wpdb->update($tbl, $update, array('id' => (int) $id), $format, array('%d'));
        self::flush_cache();
        return $r !== false;
    }

    public function delete_rule($id) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_redirects';
        $r = $wpdb->delete($tbl, array('id' => (int) $id), array('%d'));
        self::flush_cache();
        return $r !== false;
    }

    public function get_all($limit = 200, $offset = 0) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_redirects';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tbl}'") !== $tbl) return array();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tbl} ORDER BY id DESC LIMIT %d OFFSET %d",
            (int) $limit, (int) $offset
        ));
    }

    private function normalize_source($source) {
        $source = trim((string) $source);
        if ($source === '') return '';
        if (strpos($source, 'http') === 0) {
            $parsed = wp_parse_url($source);
            $source = (isset($parsed['path']) ? $parsed['path'] : '/') . (isset($parsed['query']) ? '?' . $parsed['query'] : '');
        }
        return '/' . ltrim($source, '/');
    }
}
