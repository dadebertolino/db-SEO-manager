<?php
/**
 * DBSEO_IndexNow — Notifica motori di ricerca via IndexNow.
 *
 * IndexNow è un protocollo aperto (Bing, Yandex, Seznam, Naver) per notificare
 * URL aggiornati ai motori di ricerca senza richiedere consenso utente:
 *  - La comunicazione è server-to-server (POST JSON).
 *  - Vengono inviati SOLO URL pubblici del sito.
 *  - Nessun cookie utente, nessun dato personale.
 *
 * Implementazione:
 *  - Genera/recupera la key IndexNow (file txt servito alla root del sito).
 *  - Endpoint /{key}.txt servito virtualmente via init + parse_request.
 *  - Su save_post / wp_update_nav_menu / etc accoda l'URL.
 *  - Cron orario svuota la coda inviando in batch all'endpoint api.indexnow.org.
 *
 * Privacy:
 *  - L'invio avviene solo lato server WP. Nessun browser dell'utente è coinvolto.
 *  - URL inviati: solo pagine pubblicate dell'admin, mai URL di amministrazione.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_IndexNow {

    const ENDPOINT = 'https://api.indexnow.org/indexnow';

    /** @var DBSEO_IndexNow|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $core = DBSEO_Core::instance();

        // Disattivato o sospeso da Hard Privacy: nessun hook, nessun cron.
        // Rimuoviamo anche un eventuale evento schedulato in precedenza, così
        // WP-Cron non resta con un evento orfano.
        if (!$core->get('indexnow_enabled') || DBSEO_Core::is_hard_privacy()) {
            if (wp_next_scheduled('dbseo_cron_indexnow_flush')) {
                wp_clear_scheduled_hook('dbseo_cron_indexnow_flush');
            }
            return;
        }

        // Genera la key se manca.
        if (trim((string) $core->get('indexnow_key')) === '') {
            $key = $this->generate_key();
            $core->update(array('indexnow_key' => $key));
        }

        // Endpoint virtuale per servire la key txt.
        add_action('init',           array($this, 'add_rewrite_rule'));
        add_filter('query_vars',     array($this, 'add_query_var'));
        add_action('parse_request',  array($this, 'serve_key_file'));

        // Hook su pubblicazioni / aggiornamenti.
        add_action('transition_post_status', array($this, 'on_transition'), 10, 3);

        // Cron flush.
        add_action('dbseo_cron_indexnow_flush', array($this, 'flush_queue'));
        if (!wp_next_scheduled('dbseo_cron_indexnow_flush')) {
            wp_schedule_event(time() + 5 * MINUTE_IN_SECONDS, 'hourly', 'dbseo_cron_indexnow_flush');
        }
    }

    /* =====================================================================
     * Key & verifica
     * ================================================================== */

    /**
     * Genera una key alfanumerica 32 caratteri (IndexNow accetta 8-128 hex).
     */
    private function generate_key() {
        return strtolower(wp_generate_password(32, false, false));
    }

    public function add_rewrite_rule() {
        // Match: /{key}.txt — solo se key valida configurata.
        $key = trim((string) DBSEO_Core::instance()->get('indexnow_key'));
        if ($key === '') return;
        add_rewrite_rule('^' . preg_quote($key, '/') . '\.txt$', 'index.php?dbseo_indexnow_key=1', 'top');
    }

    public function add_query_var($vars) {
        $vars[] = 'dbseo_indexnow_key';
        return $vars;
    }

    public function serve_key_file($wp) {
        if (empty($wp->query_vars['dbseo_indexnow_key'])) return;

        $key = trim((string) DBSEO_Core::instance()->get('indexnow_key'));
        if ($key === '') {
            status_header(404);
            exit;
        }

        nocache_headers();
        header('Content-Type: text/plain; charset=UTF-8');
        echo $key;
        exit;
    }

    /* =====================================================================
     * Coda
     * ================================================================== */

    public function on_transition($new_status, $old_status, $post) {
        if (!$post instanceof WP_Post) return;
        // Notifichiamo solo transizioni da/verso publish.
        if ($new_status !== 'publish' && $old_status !== 'publish') return;
        if (wp_is_post_revision($post->ID) || wp_is_post_autosave($post->ID)) return;

        // Skippa post type non pubblici.
        $pt = get_post_type_object($post->post_type);
        if (!$pt || !$pt->public) return;

        // Skippa post che hanno noindex meta.
        if ((int) get_post_meta($post->ID, '_dbseo_robots_noindex', true) === 1) return;

        $url = get_permalink($post);
        if (!$url) return;

        $this->enqueue($url);
    }

    public function enqueue($url) {
        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_indexnow_queue';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tbl}'") !== $tbl) return;

        $wpdb->insert($tbl, array(
            'url'      => esc_url_raw($url),
            'status'   => 'pending',
            'attempts' => 0,
            'queued'   => current_time('mysql'),
        ), array('%s', '%s', '%d', '%s'));
    }

    /* =====================================================================
     * Flush — invia in batch
     * ================================================================== */

    public function flush_queue() {
        // Difesa in profondità: mai richieste esterne con Hard Privacy attivo
        // (es. flush invocato manualmente o da codice terzo).
        if (DBSEO_Core::is_hard_privacy()) return;

        global $wpdb;
        $tbl = $wpdb->prefix . 'dbseo_indexnow_queue';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tbl}'") !== $tbl) return;

        $rows = $wpdb->get_results(
            "SELECT id, url FROM {$tbl}
             WHERE status = 'pending' AND attempts < 3
             ORDER BY id ASC LIMIT 100"
        );
        if (empty($rows)) return;

        $key = trim((string) DBSEO_Core::instance()->get('indexnow_key'));
        if ($key === '') return;

        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!$host) return;

        $urls = array();
        $ids  = array();
        foreach ($rows as $r) {
            $urls[] = $r->url;
            $ids[]  = (int) $r->id;
        }

        $payload = array(
            'host'        => $host,
            'key'         => $key,
            'keyLocation' => home_url('/' . $key . '.txt'),
            'urlList'     => $urls,
        );

        $response = wp_remote_post(self::ENDPOINT, array(
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body'    => wp_json_encode($payload),
            'timeout' => 15,
        ));

        $code = wp_remote_retrieve_response_code($response);

        // 200 / 202 = OK. 422 = errore lato IndexNow ma non riproveremo (URL invalidi/non del nostro host).
        if (!is_wp_error($response) && in_array((int) $code, array(200, 202, 422), true)) {
            $now = current_time('mysql');
            // Marca come submitted.
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$tbl} SET status = 'submitted', submitted = %s WHERE id IN ({$placeholders})",
                array_merge(array($now), $ids)
            ));

            // Pulizia: rimuovi le righe submitted da più di 7 giorni.
            $wpdb->query(
                "DELETE FROM {$tbl}
                 WHERE status = 'submitted' AND submitted < DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
        } else {
            // Aumenta attempts. Dopo 3 fallimenti la riga resta in coda ma non viene più tentata.
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$tbl} SET attempts = attempts + 1 WHERE id IN ({$placeholders})",
                $ids
            ));
        }

        do_action('dbseo_indexnow_flushed', $urls, $code);
    }
}
