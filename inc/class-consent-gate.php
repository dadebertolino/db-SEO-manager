<?php
/**
 * DBSEO_Consent_Gate — Gating GDPR per script di analytics e tracciamento.
 *
 * Filosofia (privacy by default):
 *  - Niente snippet di tracciamento viene mai eseguito finché non c'è consenso esplicito.
 *  - Il default è "denied". Il "granted" deve arrivare da una fonte affidabile.
 *  - Se non c'è alcun sistema di consenso rilevato, gli snippet che richiedono
 *    consenso NON vengono caricati (no fallback "permissivo").
 *
 * Cache-safe (dalla 1.5.0):
 *  - L'HTML emesso NON dipende più dal visitatore: la decisione sul consenso
 *    avviene lato client (assets/js/consent-listener.js). In precedenza la
 *    valutazione era server-side, e con un full-page cache (WP Rocket,
 *    LiteSpeed, Cloudflare APO…) la pagina generata per un visitatore che
 *    aveva accettato — Consent Mode 'granted' + snippet GA4/Pixel inline —
 *    veniva servita a TUTTI, anche a chi non aveva mai dato il consenso.
 *  - Il PHP emette solo: Consent Mode v2 default SEMPRE 'denied' (per le
 *    tipologie di storage non necessarie) + un loader JS idempotente con la
 *    configurazione dei tracker (ID, endpoint). Il loader inietta gli script
 *    solo quando il consenso risulta concesso e invia gtag('consent','update').
 *
 * Fonti del consenso lato client (in ordine di priorità):
 *  1. DB Cookie Manager — window.DBCM.hasConsent(), eventi 'dbcm:ready' e
 *     'dbcm:consent' su document.
 *  2. WP Consent API — wp_has_consent() JS, evento 'wp_listen_for_consent_change'.
 *  3. Cookiebot / Complianz — oggetto Cookiebot.consent o cookie standard
 *     (CookieConsent, cmplz_<categoria>), per siti senza ecosistema DB.
 *  4. Default: DENIED.
 *
 * Override lato server (filter dbseo_consent_granted, invariato):
 *  - true  → lo snippet viene stampato direttamente (nessun gate). Da usare
 *            solo per decisioni valide per TUTTO il sito (es. analytics
 *            self-hosted che il titolare ritiene esente da consenso).
 *  - false → il servizio non viene mai caricato.
 *  - null  → (default) gate client-side.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Consent_Gate {

    /** Handle dello script loader/listener. */
    const HANDLE = 'dbseo-consent-listener';

    /** @var DBSEO_Consent_Gate|null */
    private static $instance = null;

    /** @var array Cache della valutazione consenso per servizio. */
    private $consent_cache = array();

    /** @var array|null Cache dei servizi configurati (per request). */
    private $services = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // WP Consent API: dichiarazione di conformità + metadati cookie.
        // Registrata anche in admin: la Consent API la verifica nella sua
        // pagina/Site Health, non solo sul frontend.
        add_filter('wp_consent_api_registered_' . DBSEO_PLUGIN_BASE, '__return_true');
        add_action('init', array($this, 'register_cookie_info'));

        if (is_admin()) return;

        // Consent Mode default DENIED: deve essere il PRIMO snippet sulla pagina,
        // prima di qualsiasi gtag/GTM. Priorità 0 in wp_head.
        add_action('wp_head', array($this, 'output_consent_mode_default'), 0);

        // Snippet server-rendered: SOLO per servizi forzati a true via filter
        // dbseo_consent_granted (decisione site-wide). Analytics e Meta Pixel
        // sono indipendenti: il Pixel funziona anche con analytics disattivati.
        add_action('wp_head', array($this, 'output_analytics_head'), 99);
        add_action('wp_head', array($this, 'output_meta_pixel_head'), 99);
        add_action('wp_footer', array($this, 'output_analytics_footer'), 5);

        // Loader JS: carica i tracker lato client dopo il consenso e propaga
        // i cambi di consenso a Google Consent Mode v2 (assets/js/consent-listener.js).
        add_action('wp_enqueue_scripts', array($this, 'enqueue_consent_listener'));
    }

    /* =====================================================================
     * WP Consent API — dichiarazione cookie
     * ================================================================== */

    /**
     * Dichiara alla WP Consent API i cookie impostati dai tracker configurati,
     * così i consent manager compatibili (Complianz, Real Cookie Banner, ecc.)
     * possono elencarli nella cookie policy.
     *
     * La registrazione del plugin avviene col filter
     * wp_consent_api_registered_{plugin_basename} (vedi costruttore).
     * Tutto è additivo: se la Consent API non c'è, non succede nulla.
     */
    public function register_cookie_info() {
        if (!function_exists('wp_add_cookie_info')) return;

        foreach ($this->get_services() as $svc) {
            foreach ($this->cookie_catalog($svc['service']) as $cookie) {
                wp_add_cookie_info(
                    $cookie['name'],
                    $svc['label'],
                    $svc['category'],
                    $cookie['expires'],
                    $cookie['function'],
                    $cookie['personal_data']
                );
            }
        }
    }

    /**
     * Cookie impostati da ciascun servizio. Matomo è configurato cookieless
     * (disableCookies), Plausible e Umami sono cookieless by design.
     *
     * @param string $service
     * @return array
     */
    private function cookie_catalog($service) {
        $ga_cookies = array(
            array(
                'name'          => '_ga',
                'expires'       => __('2 anni', 'db-seo-manager'),
                'function'      => __('Distingue gli utenti per le statistiche di Google Analytics.', 'db-seo-manager'),
                'personal_data' => __('Identificatore client pseudonimo', 'db-seo-manager'),
            ),
            array(
                'name'          => '_ga_*',
                'expires'       => __('2 anni', 'db-seo-manager'),
                'function'      => __('Mantiene lo stato della sessione GA4.', 'db-seo-manager'),
                'personal_data' => __('Identificatore di sessione pseudonimo', 'db-seo-manager'),
            ),
        );

        switch ($service) {
            case 'ga4':
                return $ga_cookies;
            case 'gtm':
                return array(
                    array(
                        'name'          => '_dc_gtm_*',
                        'expires'       => __('1 minuto', 'db-seo-manager'),
                        'function'      => __('Limita la frequenza delle richieste dei tag caricati via Google Tag Manager.', 'db-seo-manager'),
                        'personal_data' => '',
                    ),
                );
            case 'meta_pixel':
                return array(
                    array(
                        'name'          => '_fbp',
                        'expires'       => __('90 giorni', 'db-seo-manager'),
                        'function'      => __('Identifica il browser per la misurazione delle conversioni Meta.', 'db-seo-manager'),
                        'personal_data' => __('Identificatore browser pseudonimo', 'db-seo-manager'),
                    ),
                    array(
                        'name'          => '_fbc',
                        'expires'       => __('90 giorni', 'db-seo-manager'),
                        'function'      => __('Memorizza l\'ultimo click da un annuncio Meta (fbclid).', 'db-seo-manager'),
                        'personal_data' => __('Identificatore click pseudonimo', 'db-seo-manager'),
                    ),
                );
        }
        return array();
    }

    /* =====================================================================
     * Servizi configurati
     * ================================================================== */

    /**
     * Elenco dei servizi di tracciamento effettivamente configurati, con
     * categoria di consenso e parametri per il loader. Non dipende dal
     * visitatore (solo dalle impostazioni): è quindi cache-safe.
     *
     * @return array[] Ogni voce: service, label, category, params.
     */
    public function get_services() {
        if ($this->services !== null) {
            return $this->services;
        }

        $core     = DBSEO_Core::instance();
        $services = array();

        // Hard Privacy: nessuna integrazione esterna, a prescindere dai flag.
        if (DBSEO_Core::is_hard_privacy()) {
            return $this->services = $services;
        }

        if ($core->get('analytics_enabled')) {
            $provider = (string) $core->get('analytics_provider', 'none');
            $id       = trim((string) $core->get('analytics_id'));
            $params   = ($id !== '' && $provider !== 'none') ? $this->provider_params($provider, $id) : null;
            if (is_array($params)) {
                $labels = array(
                    'ga4'       => 'Google Analytics 4',
                    'gtm'       => 'Google Tag Manager',
                    'matomo'    => 'Matomo',
                    'plausible' => 'Plausible Analytics',
                    'umami'     => 'Umami Analytics',
                );
                $services[] = array(
                    'service'  => $provider,
                    'label'    => $labels[$provider],
                    'category' => $this->category_for($provider, 'statistics'),
                    'params'   => $params,
                );
            }
        }

        if ($core->get('meta_pixel_enabled')) {
            $pixel_id = trim((string) $core->get('meta_pixel_id'));
            if ($pixel_id !== '') {
                $services[] = array(
                    'service'  => 'meta_pixel',
                    'label'    => 'Meta Pixel',
                    'category' => $this->category_for('meta_pixel', 'marketing'),
                    'params'   => array('id' => $pixel_id),
                );
            }
        }

        return $this->services = $services;
    }

    /**
     * Categoria WP Consent API richiesta dal servizio.
     *
     * Filter dbseo_consent_category: consente di spostare un servizio su
     * un'altra categoria (es. Plausible su 'statistics-anonymous').
     *
     * @param string $service
     * @param string $default
     * @return string
     */
    private function category_for($service, $default) {
        $category = (string) apply_filters('dbseo_consent_category', $default, $service);
        return $category !== '' ? sanitize_key($category) : $default;
    }

    /**
     * Normalizza i parametri del provider. Restituisce null se la
     * configurazione non è valida (lo snippet non viene emesso).
     *
     * @param string $provider
     * @param string $id
     * @return array|null
     */
    private function provider_params($provider, $id) {
        switch ($provider) {
            case 'ga4':
                $core   = DBSEO_Core::instance();
                $config = array();
                if ($core->get('analytics_anonymize_ip')) {
                    // GA4 anonimizza IP di default; lasciamo il flag per esplicità.
                    $config['anonymize_ip'] = true;
                }
                $config = (array) apply_filters('dbseo_ga4_config', $config, $id);
                return array('id' => $id, 'config' => (object) $config);

            case 'gtm':
                return array('id' => $id);

            case 'matomo':
                // $id contiene "url|siteId" (formato semplice configurabile in settings).
                list($url, $site_id) = array_pad(explode('|', $id, 2), 2, '');
                $url     = esc_url_raw(trim($url));
                $site_id = (int) $site_id;
                if ($url === '' || $site_id < 1) return null;
                return array('url' => rtrim($url, '/') . '/', 'siteId' => (string) $site_id);

            case 'plausible':
                $domain = sanitize_text_field($id);
                if ($domain === '') return null;
                // Endpoint custom (Plausible self-hosted) opzionale via filter.
                $script_url = esc_url_raw((string) apply_filters(
                    'dbseo_plausible_script_url',
                    'https://plausible.io/js/script.js'
                ));
                if ($script_url === '') return null;
                return array('domain' => $domain, 'src' => $script_url);

            case 'umami':
                // $id contiene "scriptUrl|websiteId".
                list($script, $website_id) = array_pad(explode('|', $id, 2), 2, '');
                $script     = esc_url_raw(trim($script));
                $website_id = sanitize_text_field(trim($website_id));
                if ($script === '' || $website_id === '') return null;
                return array('src' => $script, 'websiteId' => $website_id);
        }
        return null;
    }

    /**
     * Override server-side del consenso via filter dbseo_consent_granted.
     *
     * @param array $svc
     * @return bool|null true = stampa diretto, false = mai, null = gate client-side.
     */
    private function forced_decision($svc) {
        $forced = apply_filters('dbseo_consent_granted', null, $svc['service'], $svc['category']);
        return is_bool($forced) ? $forced : null;
    }

    /**
     * Servizi da caricare lato client (decisione null = gate JS).
     *
     * @return array[]
     */
    private function get_client_services() {
        $out = array();
        foreach ($this->get_services() as $svc) {
            if ($this->forced_decision($svc) === null) {
                $out[] = array(
                    'service'  => $svc['service'],
                    'category' => $svc['category'],
                    'params'   => $svc['params'],
                );
            }
        }
        return $out;
    }

    /**
     * Restituisce il servizio configurato per lo slug indicato, o null.
     *
     * @param string $service
     * @return array|null
     */
    private function find_service($service) {
        foreach ($this->get_services() as $svc) {
            if ($svc['service'] === $service) return $svc;
        }
        return null;
    }

    /**
     * True se Google Consent Mode v2 è attivo (GA4/GTM configurati).
     */
    private function consent_mode_active() {
        $core = DBSEO_Core::instance();
        if (!$core->get('analytics_consent_mode')) return false;
        return $this->find_service('ga4') !== null || $this->find_service('gtm') !== null;
    }

    /**
     * Mapping categorie WP Consent API → signals Consent Mode v2.
     *
     * Esempio (spostare statistics-anonymous su un signal custom):
     *   add_filter('dbseo_consent_mode_signal_map', function($map){
     *       $map['statistics-anonymous'] = array('analytics_storage');
     *       return $map;
     *   });
     *
     * @return array
     */
    private function signal_map() {
        return (array) apply_filters('dbseo_consent_mode_signal_map', array(
            'statistics' => array('analytics_storage'),
            'marketing'  => array('ad_storage', 'ad_user_data', 'ad_personalization'),
        ));
    }

    /* =====================================================================
     * Valutazione consenso
     * ================================================================== */

    /**
     * Determina se per il servizio indicato c'è consenso valido (lato server).
     *
     * NON più usato per decidere l'output del frontend (non cache-safe: il
     * risultato dipende dai cookie del singolo visitatore). Mantenuto come
     * API pubblica per retrocompatibilità con integrazioni di terze parti
     * e per contesti non cacheati (es. richieste AJAX / REST).
     *
     * @param string $service  ga4 | gtm | matomo | plausible | umami | meta_pixel | ...
     * @param string $category statistics | marketing | functional
     * @return bool
     */
    public function has_consent($service, $category = 'statistics') {
        $cache_key = $service . '|' . $category;
        if (isset($this->consent_cache[$cache_key])) {
            return $this->consent_cache[$cache_key];
        }

        // 1. Override esplicito via filter — utile per integrazioni custom o test.
        $forced = apply_filters('dbseo_consent_granted', null, $service, $category);
        if (is_bool($forced)) {
            return $this->consent_cache[$cache_key] = $forced;
        }

        // 2. WP Consent API (proposta ufficiale, supportata da diversi plugin).
        if (function_exists('wp_has_consent')) {
            $granted = (bool) wp_has_consent($category);
            return $this->consent_cache[$cache_key] = $granted;
        }

        // 3. DB Cookie Manager (delegation diretta).
        //
        // Se il plugin DB Cookie Manager 3.0.0+ è attivo, deleghiamo a lui:
        //  - lui sa lo schema del cookie (versionato),
        //  - lui sa quando il cookie è scaduto / malformato,
        //  - lui è la "single source of truth" del consenso (modello
        //    produttore-consumatore: Cookie Manager produce, SEO Manager
        //    consuma).
        // Questo path scatta solo se la WP Consent API NON è installata
        // (altrimenti il livello 2 ha già risposto via wp_has_consent).
        if (class_exists('DBCM_Consent_API')) {
            $granted = (bool) DBCM_Consent_API::has_consent($category);
            return $this->consent_cache[$cache_key] = $granted;
        }

        // 4. Plugin di terze parti diffusi (siti senza ecosistema DB):
        // rileviamo i cookie standard di Cookiebot / Complianz come fallback.
        $third_party = $this->detect_third_party_consent($category);
        if ($third_party !== null) {
            return $this->consent_cache[$cache_key] = $third_party;
        }

        // 5. Default: DENIED.
        return $this->consent_cache[$cache_key] = false;
    }

    /**
     * Rileva consenso da plugin consent manager di terze parti (non-DB).
     *
     * Si attiva solo se la WP Consent API non è installata E il Cookie
     * Manager DB non è presente: serve a coprire installazioni che usano
     * Cookiebot, Complianz o simili senza esposizione via wp_has_consent.
     *
     * Il rilevamento è best-effort: leggiamo cookie standard, restituiamo
     * null quando non c'è certezza per non assumere consenso.
     *
     * @return bool|null  null = non rilevato (fallback al default), bool = valore rilevato
     */
    private function detect_third_party_consent($category) {
        // Cookiebot: cookie CookieConsent contiene JSON con statistics:true / marketing:true
        if (isset($_COOKIE['CookieConsent'])) {
            $raw = wp_unslash($_COOKIE['CookieConsent']);
            // Formato: {stamp:'...',necessary:true,preferences:false,statistics:true,marketing:false,...}
            if (preg_match('/' . preg_quote($category, '/') . ':true/i', (string) $raw)) {
                return true;
            }
            if (preg_match('/' . preg_quote($category, '/') . ':false/i', (string) $raw)) {
                return false;
            }
        }

        // Iubenda: euconsent-v2 (TCF) oppure _iub_cs-* — la presenza non garantisce
        // sufficienza, quindi qui ritorniamo null per non assumere consenso.
        // (Per Iubenda è raccomandato usare il loro callback JS + dbseo_consent_granted filter.)

        // Complianz: cmplz_statistics / cmplz_marketing valgono "allow" se concessi.
        $key = 'cmplz_' . $category;
        if (isset($_COOKIE[$key])) {
            $val = strtolower((string) wp_unslash($_COOKIE[$key]));
            if (in_array($val, array('allow', 'true', '1'), true)) return true;
            if (in_array($val, array('deny', 'false', '0'), true)) return false;
        }

        // CookieYes: cky-consent contiene "yes"/"no" globale; non distingue categorie
        // in modo affidabile via cookie, quindi preferiamo non assumere.

        return null;
    }

    /* =====================================================================
     * Consent Mode v2 — default state
     * ================================================================== */

    /**
     * Emette il default "denied" per Google Consent Mode v2.
     * Questo deve essere il PRIMO script sulla pagina (priorità 0 in wp_head).
     *
     * Il default è SEMPRE 'denied' per le tipologie di storage non necessarie,
     * indipendentemente dal visitatore: l'HTML resta identico per tutti ed è
     * quindi sicuro con qualsiasi page cache. Il 'granted' arriva lato client
     * con gtag('consent','update', …) dal loader JS.
     *
     * Lo emettiamo solo se è abilitato un servizio Google (GA4/GTM) E il consent mode è on.
     */
    public function output_consent_mode_default() {
        if (!$this->consent_mode_active()) return;

        $defaults = array(
            'ad_storage'              => 'denied',
            'ad_user_data'            => 'denied',
            'ad_personalization'      => 'denied',
            'analytics_storage'       => 'denied',
            'functionality_storage'   => 'granted',
            'security_storage'        => 'granted',
            'wait_for_update'         => 500,
        );

        // Filter pubblico: temi/plugin possono modificare i default.
        $defaults = (array) apply_filters('dbseo_consent_mode_defaults', $defaults);

        $json = wp_json_encode($defaults); // slash escaped: nessun "</script>" possibile dai filter.
        if ($json === false) return;

        echo "\n<!-- DB SEO: Google Consent Mode v2 default -->\n";
        echo "<script>\n";
        echo "window.dataLayer = window.dataLayer || [];\n";
        echo "window.gtag = window.gtag || function(){window.dataLayer.push(arguments);};\n";
        echo "gtag('consent', 'default', {$json});\n";
        echo "</script>\n";
    }

    /**
     * Enqueue del loader/listener JS (assets/js/consent-listener.js).
     *
     * Il loader:
     *  - carica GA4/GTM/Matomo/Plausible/Umami/Meta Pixel solo quando il
     *    consenso per la categoria del servizio risulta concesso (idempotente);
     *  - invia gtag('consent','update', …) quando cambia il consenso, se
     *    Consent Mode v2 è attivo.
     *
     * La configurazione è emessa inline con wp_json_encode() di default
     * (slash escaped): i pattern URL del blocker di DB Cookie Manager non
     * matchano la config, quindi il loader non viene neutralizzato — è lui
     * stesso il gate.
     */
    public function enqueue_consent_listener() {
        $services     = $this->get_client_services();
        $consent_mode = $this->consent_mode_active();

        if (empty($services) && !$consent_mode) return;

        wp_register_script(
            self::HANDLE,
            DBSEO_PLUGIN_URL . 'assets/js/consent-listener.js',
            array(),
            DBSEO_VERSION,
            false // in head: deve registrarsi al listener prima che il banner emetta l'evento
        );

        $config = array(
            'consentMode' => $consent_mode,
            'signalMap'   => $this->signal_map(),
            'services'    => $services,
            'debug'       => (defined('WP_DEBUG') && WP_DEBUG) ? true : false,
        );

        wp_add_inline_script(self::HANDLE, 'var DBSEOConsent = ' . wp_json_encode($config) . ';', 'before');
        wp_enqueue_script(self::HANDLE);
    }

    /* =====================================================================
     * Snippet server-rendered (solo servizi forzati via filter)
     * ================================================================== */

    /**
     * Snippet analytics nell'<head> per il provider configurato, solo se il
     * filter dbseo_consent_granted lo forza a true. Altrimenti il caricamento
     * è demandato al loader JS.
     */
    public function output_analytics_head() {
        foreach ($this->get_services() as $svc) {
            if ($svc['service'] === 'meta_pixel') continue;
            $this->maybe_output_forced($svc);
        }
    }

    /**
     * Meta Pixel: indipendente dagli analytics (funziona anche con
     * analytics_enabled = false).
     */
    public function output_meta_pixel_head() {
        $svc = $this->find_service('meta_pixel');
        if ($svc !== null) {
            $this->maybe_output_forced($svc);
        }
    }

    /**
     * Stampa lo snippet server-side se forzato; commento debug se negato.
     *
     * @param array $svc
     */
    private function maybe_output_forced($svc) {
        $decision = $this->forced_decision($svc);
        if ($decision === false) {
            $this->emit_blocked_comment($svc['label']);
            return;
        }
        if ($decision !== true) return; // null: gate client-side.

        $p = $svc['params'];
        switch ($svc['service']) {
            case 'ga4':
                $this->snippet_ga4($p, $svc['category']);
                break;
            case 'gtm':
                $this->snippet_gtm($p, $svc['category']);
                break;
            case 'matomo':
                $this->snippet_matomo($p);
                break;
            case 'plausible':
                $this->snippet_plausible($p);
                break;
            case 'umami':
                $this->snippet_umami($p);
                break;
            case 'meta_pixel':
                $this->snippet_meta_pixel($p);
                break;
        }
    }

    /**
     * GTM noscript fallback: solo se GTM è forzato server-side (senza JS non
     * è possibile raccogliere né verificare il consenso lato client).
     */
    public function output_analytics_footer() {
        $svc = $this->find_service('gtm');
        if ($svc === null || $this->forced_decision($svc) !== true) return;

        printf(
            '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
            esc_attr($svc['params']['id'])
        );
    }

    /* =====================================================================
     * Snippet specifici per provider
     * ================================================================== */

    /**
     * Consent update 'granted' per i signals della categoria forzata, così
     * Consent Mode v2 non resta sul default 'denied' emesso in testa.
     *
     * @param string $category
     */
    private function forced_consent_update($category) {
        if (!$this->consent_mode_active()) return;
        $map     = $this->signal_map();
        $signals = isset($map[$category]) ? (array) $map[$category] : array();
        if (empty($signals)) return;
        $payload = array_fill_keys(array_map('strval', $signals), 'granted');
        printf("gtag('consent', 'update', %s);\n", wp_json_encode($payload));
    }

    private function snippet_ga4($p, $category) {
        echo "\n<!-- DB SEO: Google Analytics 4 -->\n";
        printf(
            '<script async src="https://www.googletagmanager.com/gtag/js?id=%s"></script>' . "\n",
            esc_attr($p['id'])
        );
        echo "<script>\n";
        echo "window.dataLayer = window.dataLayer || [];\n";
        echo "window.gtag = window.gtag || function(){window.dataLayer.push(arguments);};\n";
        $this->forced_consent_update($category);
        echo "gtag('js', new Date());\n";
        printf("gtag('config', %s, %s);\n", wp_json_encode($p['id']), wp_json_encode($p['config']));
        echo "</script>\n";
    }

    private function snippet_gtm($p, $category) {
        echo "\n<!-- DB SEO: Google Tag Manager -->\n";
        if ($this->consent_mode_active()) {
            echo "<script>\n";
            echo "window.dataLayer = window.dataLayer || [];\n";
            echo "window.gtag = window.gtag || function(){window.dataLayer.push(arguments);};\n";
            $this->forced_consent_update($category);
            echo "</script>\n";
        }
        echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':";
        echo "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],";
        echo "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=";
        echo "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);";
        echo "})(window,document,'script','dataLayer'," . wp_json_encode($p['id']) . ");</script>\n";
    }

    private function snippet_matomo($p) {
        echo "\n<!-- DB SEO: Matomo -->\n";
        echo "<script>\n";
        echo "var _paq = window._paq = window._paq || [];\n";
        echo "_paq.push(['disableCookies']);\n";              // privacy-friendly
        echo "_paq.push(['setDoNotTrack', true]);\n";
        echo "_paq.push(['enableHeartBeatTimer']);\n";
        echo "_paq.push(['trackPageView']);\n";
        echo "_paq.push(['enableLinkTracking']);\n";
        echo "(function() {\n";
        printf("  var u='%s';\n", esc_js($p['url']));
        echo "  _paq.push(['setTrackerUrl', u+'matomo.php']);\n";
        printf("  _paq.push(['setSiteId', '%d']);\n", (int) $p['siteId']);
        echo "  var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];\n";
        echo "  g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);\n";
        echo "})();\n";
        echo "</script>\n";
    }

    private function snippet_plausible($p) {
        echo "\n<!-- DB SEO: Plausible -->\n";
        printf(
            '<script defer data-domain="%s" src="%s"></script>' . "\n",
            esc_attr($p['domain']),
            esc_url($p['src'])
        );
    }

    private function snippet_umami($p) {
        echo "\n<!-- DB SEO: Umami -->\n";
        printf(
            '<script async defer data-website-id="%s" src="%s"></script>' . "\n",
            esc_attr($p['websiteId']),
            esc_url($p['src'])
        );
    }

    private function snippet_meta_pixel($p) {
        $id = $p['id'];
        echo "\n<!-- DB SEO: Meta Pixel -->\n";
        echo "<script>\n";
        echo "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?";
        echo "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;";
        echo "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;";
        echo "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,";
        echo "document,'script','https://connect.facebook.net/en_US/fbevents.js');\n";
        printf("fbq('init', %s);\nfbq('track', 'PageView');\n", wp_json_encode($id));
        echo "</script>\n";
        printf(
            '<noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=%s&ev=PageView&noscript=1"/></noscript>' . "\n",
            esc_attr($id)
        );
    }

    /**
     * Emette un commento HTML che indica perché lo script non è stato caricato.
     * Utile in fase di debug per verificare che il gating funzioni.
     */
    private function emit_blocked_comment($service) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            echo "\n<!-- DB SEO: " . esc_html($service) . " non caricato (dbseo_consent_granted = false) -->\n";
        }
    }
}
