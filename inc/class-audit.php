<?php
/**
 * DBSEO_Audit — Audit caricamenti di terze parti.
 *
 * Scarica la homepage del sito (via wp_remote_get interno) e analizza l'HTML
 * cercando risorse caricate da host esterni che potrebbero implicare:
 *  - Trasferimento di dati a terzi (cookie, IP, user-agent)
 *  - Necessità di consenso (analytics, advertising, embed social)
 *  - Privacy concern (Google Fonts, Gravatar, fingerprinting)
 *
 * L'audit è on-demand (l'admin clicca "Esegui audit") e i risultati sono cached
 * in opzione per 24h.
 *
 * Privacy: la richiesta è server-to-server, dal sito a sé stesso. Nessun dato
 * di utenti finali coinvolto.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Audit {

    /** @var DBSEO_Audit|null */
    private static $instance = null;

    /** @var array Catalogo host noti con categorizzazione. */
    private static $known_hosts = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Cron: deve essere registrato SEMPRE (wp-cron non passa da is_admin).
        add_action('dbseo_cron_audit', array($this, 'run_scheduled'));

        if (DBSEO_Core::instance()->get('audit_schedule_enabled')) {
            if (!wp_next_scheduled('dbseo_cron_audit')) {
                wp_schedule_event(time() + HOUR_IN_SECONDS, 'weekly', 'dbseo_cron_audit');
            }
        } elseif (wp_next_scheduled('dbseo_cron_audit')) {
            wp_clear_scheduled_hook('dbseo_cron_audit');
        }

        if (!is_admin()) return;
        add_action('admin_post_dbseo_run_audit', array($this, 'handle_run'));
    }

    /* =====================================================================
     * Audit schedulato — monitoraggio regressioni privacy
     * ================================================================== */

    /**
     * Esecuzione via cron: confronta gli host trovati con la baseline nota.
     * Se compaiono host esterni nuovi, invia una mail di alert e aggiorna
     * la baseline (alert one-shot per host, niente spam).
     */
    public function run_scheduled() {
        $core = DBSEO_Core::instance();
        if (!$core->get('audit_schedule_enabled')) return;

        $this->run();

        $results = (array) $core->get('audit_last_results', array());
        if (isset($results['error'])) return;

        // Estrae gli host dai findings (struttura: array di righe con 'host').
        $current = array();
        foreach ((array) ($results['findings'] ?? $results) as $row) {
            $host = '';
            if (is_array($row) && isset($row['host'])) $host = (string) $row['host'];
            elseif (is_object($row) && isset($row->host)) $host = (string) $row->host;
            if ($host !== '') $current[] = strtolower($host);
        }
        $current  = array_values(array_unique($current));
        $baseline = array_map('strtolower', (array) $core->get('audit_known_baseline', array()));

        $new_hosts = array_diff($current, $baseline);
        if (empty($new_hosts)) return;

        // Alert email.
        $to = trim((string) $core->get('audit_alert_email', ''));
        if ($to === '' || !is_email($to)) $to = get_option('admin_email');

        $subject = sprintf(
            /* translators: %s: site name */
            __('[%s] Nuove risorse esterne rilevate sul sito', 'db-seo-manager'),
            get_bloginfo('name')
        );
        $body  = __("L'audit schedulato di DB SEO Manager ha rilevato nuove risorse esterne caricate dalla homepage del sito:", 'db-seo-manager') . "

";
        foreach ($new_hosts as $h) {
            $body .= " - {$h}
";
        }
        $body .= "
" . __('Se queste risorse trattano dati personali potrebbero richiedere consenso o aggiornamento della privacy policy. Verifica nella pagina "Stato GDPR" del plugin.', 'db-seo-manager') . "
";
        $body .= admin_url('admin.php?page=dbseo-status') . "
";

        wp_mail($to, $subject, $body);

        // Aggiorna la baseline: alert una sola volta per host.
        $core->update(array('audit_known_baseline' => array_values(array_unique(array_merge($baseline, $new_hosts)))));

        do_action('dbseo_audit_new_hosts', $new_hosts);
    }

    /* =====================================================================
     * Catalogo host noti
     * ================================================================== */

    private static function known_hosts() {
        if (self::$known_hosts !== null) return self::$known_hosts;

        self::$known_hosts = array(
            // Analytics & advertising (richiedono consenso)
            'google-analytics.com'     => array('cat' => 'analytics',     'name' => 'Google Analytics',          'transfer' => 'us', 'consent' => 'statistics'),
            'googletagmanager.com'     => array('cat' => 'analytics',     'name' => 'Google Tag Manager',        'transfer' => 'us', 'consent' => 'statistics'),
            'doubleclick.net'          => array('cat' => 'advertising',   'name' => 'Google Ads / DoubleClick',  'transfer' => 'us', 'consent' => 'marketing'),
            'googleadservices.com'     => array('cat' => 'advertising',   'name' => 'Google Ads',                'transfer' => 'us', 'consent' => 'marketing'),
            'connect.facebook.net'     => array('cat' => 'advertising',   'name' => 'Meta Pixel',                'transfer' => 'us', 'consent' => 'marketing'),
            'facebook.com'             => array('cat' => 'social',        'name' => 'Facebook',                  'transfer' => 'us', 'consent' => 'marketing'),
            'static.hotjar.com'        => array('cat' => 'analytics',     'name' => 'Hotjar',                    'transfer' => 'mt', 'consent' => 'statistics'),
            'cdn.matomo.cloud'         => array('cat' => 'analytics',     'name' => 'Matomo Cloud',              'transfer' => 'de', 'consent' => 'statistics'),
            'plausible.io'             => array('cat' => 'analytics',     'name' => 'Plausible',                 'transfer' => 'ee', 'consent' => 'none'),
            'cdn.usefathom.com'        => array('cat' => 'analytics',     'name' => 'Fathom',                    'transfer' => 'ca', 'consent' => 'none'),

            // Font esterni
            'fonts.googleapis.com'     => array('cat' => 'fonts',         'name' => 'Google Fonts',              'transfer' => 'us', 'consent' => 'depends'),
            'fonts.gstatic.com'        => array('cat' => 'fonts',         'name' => 'Google Fonts (static)',     'transfer' => 'us', 'consent' => 'depends'),
            'use.typekit.net'          => array('cat' => 'fonts',         'name' => 'Adobe Fonts (Typekit)',     'transfer' => 'us', 'consent' => 'depends'),
            'fast.fonts.net'           => array('cat' => 'fonts',         'name' => 'Monotype Fonts',            'transfer' => 'us', 'consent' => 'depends'),

            // Avatar / immagini
            'gravatar.com'             => array('cat' => 'avatar',        'name' => 'Gravatar',                  'transfer' => 'us', 'consent' => 'depends'),
            'secure.gravatar.com'      => array('cat' => 'avatar',        'name' => 'Gravatar',                  'transfer' => 'us', 'consent' => 'depends'),

            // Embed video
            'youtube.com'              => array('cat' => 'embed',         'name' => 'YouTube',                   'transfer' => 'us', 'consent' => 'marketing'),
            'youtu.be'                 => array('cat' => 'embed',         'name' => 'YouTube',                   'transfer' => 'us', 'consent' => 'marketing'),
            'youtube-nocookie.com'     => array('cat' => 'embed',         'name' => 'YouTube nocookie',          'transfer' => 'us', 'consent' => 'depends'),
            'player.vimeo.com'         => array('cat' => 'embed',         'name' => 'Vimeo Player',              'transfer' => 'us', 'consent' => 'statistics'),
            'vimeo.com'                => array('cat' => 'embed',         'name' => 'Vimeo',                     'transfer' => 'us', 'consent' => 'statistics'),

            // Maps
            'maps.googleapis.com'      => array('cat' => 'maps',          'name' => 'Google Maps API',           'transfer' => 'us', 'consent' => 'marketing'),
            'maps.google.com'          => array('cat' => 'maps',          'name' => 'Google Maps',               'transfer' => 'us', 'consent' => 'marketing'),

            // CDN JS comuni (di solito accettabili, ma flag info)
            'cdn.jsdelivr.net'         => array('cat' => 'cdn',           'name' => 'jsDelivr CDN',              'transfer' => 'us', 'consent' => 'none'),
            'cdnjs.cloudflare.com'     => array('cat' => 'cdn',           'name' => 'Cloudflare CDN',            'transfer' => 'us', 'consent' => 'none'),
            'unpkg.com'                => array('cat' => 'cdn',           'name' => 'unpkg CDN',                 'transfer' => 'us', 'consent' => 'none'),
            'ajax.googleapis.com'      => array('cat' => 'cdn',           'name' => 'Google Hosted Libraries',   'transfer' => 'us', 'consent' => 'depends'),

            // Captcha
            'www.google.com/recaptcha' => array('cat' => 'captcha',       'name' => 'Google reCAPTCHA',          'transfer' => 'us', 'consent' => 'depends'),
            'hcaptcha.com'             => array('cat' => 'captcha',       'name' => 'hCaptcha',                  'transfer' => 'us', 'consent' => 'depends'),

            // Tracking misc
            's.w.org'                  => array('cat' => 'wp-emoji',      'name' => 'WordPress emoji',           'transfer' => 'us', 'consent' => 'depends'),
            'pixel.wp.com'             => array('cat' => 'analytics',     'name' => 'Jetpack Stats',             'transfer' => 'us', 'consent' => 'statistics'),
            'stats.wp.com'             => array('cat' => 'analytics',     'name' => 'Jetpack Stats',             'transfer' => 'us', 'consent' => 'statistics'),
        );

        return self::$known_hosts;
    }

    /* =====================================================================
     * Esecuzione audit
     * ================================================================== */

    public function handle_run() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('dbseo_audit', '_dbseo_audit_nonce');

        $this->run();

        $redirect = wp_get_referer() ?: admin_url('admin.php?page=dbseo-status');
        wp_safe_redirect(add_query_arg('dbseo_msg', 'audit_done', $redirect));
        exit;
    }

    /**
     * Esegue l'audit della homepage e salva i risultati in opzione.
     */
    public function run() {
        $url = home_url('/');
        $response = wp_remote_get($url, array(
            'timeout'   => 15,
            'sslverify' => false, // accettiamo self-signed/Let's Encrypt rotti, è una scansione locale
            'headers'   => array(
                'User-Agent' => 'DB-SEO-Audit/' . DBSEO_VERSION,
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            DBSEO_Core::instance()->update(array(
                'audit_last_run'     => time(),
                'audit_last_results' => array(
                    'error' => is_wp_error($response) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code($response),
                ),
            ));
            return;
        }

        $html = (string) wp_remote_retrieve_body($response);
        $findings = $this->parse_html($html);

        // Arricchimento opzionale con i dati dello scanner del Cookie Manager DB.
        // Se il Cookie Manager 3.0.2+ è attivo e ha dati di scansione, ogni
        // host noto viene arricchito con i cookie effettivamente registrati
        // dallo scanner per quel servizio. Inerte se DBCM non installato.
        $findings = $this->enrich_findings_with_scanner($findings);

        DBSEO_Core::instance()->update(array(
            'audit_last_run'     => time(),
            'audit_last_results' => $findings,
        ));
    }

    /**
     * Arricchisce i finding dell'audit con i cookie reali dello scanner del
     * Cookie Manager DB.
     *
     * Logica:
     *  - Per ogni finding con `name` valorizzato (host categorizzato), chiama
     *    DBCM_Scanner::get_cookies_by_provider_keyword($name).
     *  - I cookie trovati vengono memorizzati in $finding['scanned_cookies']
     *    come array di {name, category, duration} pronto da renderizzare.
     *  - Aggiunge $finding['scanned_count'] per la UI ("3 cookie scritti").
     *  - Voci unknown (host senza match nel known_hosts) NON vengono
     *    arricchite: non sappiamo che label cercare.
     *
     * Decisione di design: non duplichiamo la knowledge dei provider. Il SEO
     * Manager sa che googletagmanager.com → "Google Tag Manager", il Cookie
     * Manager sa quali cookie ha visto scrivere il provider "Google Tag
     * Manager". Il match avviene sulla label umana — single source of truth
     * di chi è chi.
     *
     * Inerte se:
     *  - Il Cookie Manager DB non è installato (class_exists check)
     *  - La sua versione non espone get_cookies_by_provider_keyword (3.0.1 e
     *    precedenti — method_exists check)
     *  - Lo scanner non ha mai girato (nessun risultato → nessun arricchimento)
     *
     * @param array $findings Output di parse_html().
     * @return array Stesso array, eventualmente arricchito.
     */
    private function enrich_findings_with_scanner($findings) {
        // Niente da fare se l'audit non ha trovato nulla o se manca il
        // Cookie Manager DB.
        if (empty($findings['findings']) || !is_array($findings['findings'])) {
            return $findings;
        }
        if (!class_exists('DBCM_Scanner')) {
            return $findings;
        }
        if (!method_exists('DBCM_Scanner', 'get_cookies_by_provider_keyword')) {
            // Cookie Manager < 3.0.2 — il metodo pubblico non esiste ancora.
            return $findings;
        }

        foreach ($findings['findings'] as &$f) {
            // Default: nessun cookie associato. Lo settiamo sempre per uniformità.
            $f['scanned_cookies'] = array();
            $f['scanned_count']   = 0;

            // Solo host categorizzati dal known_hosts hanno una label utile.
            if (empty($f['name']) || $f['name'] === $f['host']) {
                continue; // host non riconosciuto → nessuna keyword da cercare
            }

            $cookies = DBCM_Scanner::get_cookies_by_provider_keyword($f['name'], 20);
            if (empty($cookies)) {
                continue;
            }

            $list = array();
            foreach ($cookies as $row) {
                $list[] = array(
                    'name'     => isset($row->cookie_name) ? (string) $row->cookie_name : '',
                    'category' => isset($row->category)    ? (string) $row->category    : '',
                    'duration' => isset($row->cookie_duration) ? (string) $row->cookie_duration : '',
                );
            }
            $f['scanned_cookies'] = $list;
            $f['scanned_count']   = count($list);
        }
        unset($f); // safety: chiudi la reference

        return $findings;
    }

    /**
     * Parsifica l'HTML ed estrae host esterni con tipo di risorsa.
     */
    private function parse_html($html) {
        $home_host = wp_parse_url(home_url('/'), PHP_URL_HOST);

        $found = array(); // host => array(types: [], handles: [])

        // Pattern: src/href dei tag.
        if (preg_match_all('/<(?:script|link|img|iframe)\b[^>]*?\s(?:src|href)=[\'"]([^\'"#]+)[\'"]/i', $html, $m)) {
            foreach ($m[1] as $url) {
                $host = wp_parse_url($url, PHP_URL_HOST);
                if (!$host) continue;
                if (strcasecmp($host, $home_host) === 0) continue;

                if (!isset($found[$host])) {
                    $found[$host] = array(
                        'count' => 0,
                        'sample_urls' => array(),
                    );
                }
                $found[$host]['count']++;
                if (count($found[$host]['sample_urls']) < 3) {
                    $found[$host]['sample_urls'][] = $url;
                }
            }
        }

        // Categorizza usando il catalogo.
        $known = self::known_hosts();
        $categorized = array();
        foreach ($found as $host => $info) {
            $entry = array(
                'host'        => $host,
                'count'       => $info['count'],
                'sample_urls' => $info['sample_urls'],
                'category'    => 'unknown',
                'name'        => $host,
                'transfer'    => '',
                'consent'     => 'unknown',
            );

            // Match esatto o per suffisso.
            foreach ($known as $known_host => $meta) {
                if ($host === $known_host || $this->ends_with($host, '.' . $known_host) || strpos($host, $known_host) !== false) {
                    $entry['category'] = $meta['cat'];
                    $entry['name']     = $meta['name'];
                    $entry['transfer'] = $meta['transfer'];
                    $entry['consent']  = $meta['consent'];
                    break;
                }
            }

            $categorized[] = $entry;
        }

        // Ordina per categoria (analytics/advertising prima) e count desc.
        usort($categorized, function($a, $b) {
            $priority = array('advertising' => 1, 'analytics' => 2, 'social' => 3, 'embed' => 4, 'maps' => 5, 'avatar' => 6, 'fonts' => 7, 'captcha' => 8, 'cdn' => 9, 'wp-emoji' => 10, 'unknown' => 11);
            $ap = $priority[$a['category']] ?? 99;
            $bp = $priority[$b['category']] ?? 99;
            if ($ap !== $bp) return $ap - $bp;
            return $b['count'] - $a['count'];
        });

        return array(
            'fetched_at' => time(),
            'home_host'  => $home_host,
            'total_hosts' => count($categorized),
            'findings'   => $categorized,
        );
    }

    private function ends_with($haystack, $needle) {
        $len = strlen($needle);
        if ($len === 0) return true;
        return substr($haystack, -$len) === $needle;
    }

    /* =====================================================================
     * Score GDPR
     * ================================================================== */

    /**
     * Calcola un punteggio sintetico 0-100 dello "stato GDPR" del sito.
     * Pesi:
     *  - integrazioni esterne attive senza consent gate: -X
     *  - audit findings con consent=marketing: -X
     *  - audit findings con consent=statistics non gate: -X
     *  - retention 404 con IP loggato: -X
     *  - hard privacy attivo: bonus
     *  - analytics_consent_mode attivo: bonus
     */
    public function compute_score() {
        $core = DBSEO_Core::instance();
        $score = 100;
        $reasons = array();

        // Penalità: integrazioni esterne attive.
        if ($core->get('analytics_enabled')) {
            $score -= 5;
            $reasons[] = array('penalty' => 5, 'msg' => __('Analytics attivo', 'db-seo-manager'));

            if (!$core->get('analytics_consent_mode')) {
                $score -= 10;
                $reasons[] = array('penalty' => 10, 'msg' => __('Consent Mode v2 non attivo per GA/GTM', 'db-seo-manager'));
            }
            if (!$core->get('analytics_anonymize_ip')) {
                $score -= 3;
                $reasons[] = array('penalty' => 3, 'msg' => __('Anonimizzazione IP disattiva', 'db-seo-manager'));
            }
        }
        if ($core->get('meta_pixel_enabled')) {
            $score -= 8;
            $reasons[] = array('penalty' => 8, 'msg' => __('Meta Pixel attivo (trasferimento extra-UE)', 'db-seo-manager'));
        }
        if ($core->get('monitor_404_log_ip')) {
            $score -= 15;
            $reasons[] = array('penalty' => 15, 'msg' => __('Monitor 404 logga IP (sconsigliato)', 'db-seo-manager'));
        }

        // Audit findings.
        $audit = $core->get('audit_last_results', array());
        if (!empty($audit['findings'])) {
            foreach ($audit['findings'] as $f) {
                if ($f['consent'] === 'marketing') {
                    $score -= 3;
                    $reasons[] = array('penalty' => 3, 'msg' => sprintf(
                        /* translators: %s: hostname */
                        __('Risorsa marketing rilevata: %s', 'db-seo-manager'),
                        $f['host']
                    ));
                } elseif ($f['consent'] === 'statistics') {
                    $score -= 2;
                    $reasons[] = array('penalty' => 2, 'msg' => sprintf(
                        /* translators: %s: hostname */
                        __('Risorsa analytics rilevata: %s', 'db-seo-manager'),
                        $f['host']
                    ));
                }
            }
        }

        // Bonus.
        if ($core->get('hard_privacy_enabled')) {
            $score += 10;
            $reasons[] = array('penalty' => -10, 'msg' => __('Hard Privacy attivo', 'db-seo-manager'));
        }

        // Riconoscimento del Cookie Manager DB (Step 4 — 1.2.0).
        //
        // Cumulativo col bonus WP Consent API: avere il Cookie Manager DB
        // significa avere anche un banner GDPR funzionante, scanner cookie,
        // consent log con prova GDPR (art. 7) e blocker preventivo per
        // script terzi non collaborativi. È un set di funzionalità più
        // ampio della sola WP Consent API, quindi il bonus è separato.
        $dbcm_active = class_exists('DBCM_Consent_API');
        if ($dbcm_active) {
            $score += 5;
            $reasons[] = array('penalty' => -5, 'msg' => __('DB Cookie Manager attivo (banner + consent log + scanner)', 'db-seo-manager'));
        }
        if (function_exists('wp_has_consent')) {
            $score += 5;
            $reasons[] = array('penalty' => -5, 'msg' => __('WP Consent API rilevata', 'db-seo-manager'));
        }

        // Penalità "integrazioni esterne attive senza alcun consent manager".
        //
        // Caso peggiore: il sito carica GA4/Pixel senza alcun sistema di
        // gating del consenso. Né WP Consent API installata, né Cookie
        // Manager DB. Significa che gli snippet sono emessi (o bloccati a
        // tappeto, vedi class-consent-gate default DENIED) ma nessun
        // banner viene mai mostrato all'utente: configurazione potenzialmente
        // dannosa o inutile.
        //
        // La penalità si aggiunge a quelle già presenti su analytics_enabled
        // (-5) e meta_pixel_enabled (-8): la situazione "tracker attivi senza
        // consent manager" è strettamente peggio di "tracker attivi e basta".
        $has_external = $core->get('analytics_enabled') || $core->get('meta_pixel_enabled');
        $has_any_cm   = $dbcm_active || function_exists('wp_has_consent');
        if ($has_external && !$has_any_cm) {
            $score -= 15;
            $reasons[] = array('penalty' => 15, 'msg' => __('Integrazioni esterne attive senza alcun consent manager rilevato', 'db-seo-manager'));
        }

        $score = max(0, min(100, $score));

        return array(
            'score'   => $score,
            'reasons' => $reasons,
        );
    }
}
