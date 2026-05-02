<?php
/**
 * DBSEO_Consent_Gate — Gating GDPR per script di analytics e tracciamento.
 *
 * Filosofia (privacy by default):
 *  - Niente snippet di tracciamento viene mai stampato finché non c'è consenso esplicito.
 *  - Il default è "denied". Il "granted" deve arrivare da una fonte affidabile.
 *  - Se non c'è alcun sistema di consenso rilevato, gli snippet che richiedono
 *    consenso vengono SOPPRESSI (no fallback "permissivo").
 *  - Strumenti privacy-respectful (Plausible, Umami self-hosted con anonimizzazione)
 *    possono essere marcati come "no-consent-required" via filter, ma è OFF di default
 *    perché la valutazione dipende dal contesto giuridico del titolare.
 *
 * Integrazione consenso (in ordine di priorità):
 *  1. Filter dbseo_consent_granted($default, $service) — override esplicito
 *  2. WP Consent API (wp_has_consent('statistics' / 'marketing'))
 *     Quando DB Cookie Manager 3.0.0+ è attivo si registra come consent
 *     manager via filter wp_get_consent_type e propaga le scelte a
 *     wp_set_consent(): in pratica questo è il percorso che copre il 99%
 *     dei casi reali in un'installazione DB.
 *  3. DBCM_Consent_API::has_consent() — delegation diretta al Cookie Manager
 *     come fallback per siti DB senza WP Consent API installata. Non
 *     parsiamo più il cookie a mano: il Cookie Manager è l'unico produttore
 *     del consenso (vedi modello produttore-consumatore).
 *  4. Cookiebot / Complianz (rilevamento cookie standard) — fallback per
 *     siti che hanno SEO Manager ma NON hanno il Cookie Manager DB e usano
 *     un consent manager terzo non integrato con WP Consent API.
 *  5. Default: DENIED.
 *
 * Per Google Consent Mode v2:
 *  - Emettiamo SEMPRE il default "denied" prima di gtag.js (anche se non carichiamo
 *    gtag.js in quel momento). Quando il consenso arriva lato client, il sito
 *    può fare gtag('consent','update',...) — supportato out of the box.
 *  - Il listener JS (assets/js/consent-listener.js) ascolta l'evento
 *    'dbcm:consent' del Cookie Manager DB e emette automaticamente
 *    gtag('consent','update', {...}) senza ricaricare la pagina, così
 *    GA4/GTM ricevono il consenso aggiornato anche durante la stessa
 *    sessione.
 *  - Per scelta esplicita gli script GA4/GTM/Pixel restano server-rendered:
 *    se l'utente accetta dopo aver rifiutato, vedrà i tracker al prossimo
 *    page-load (zero byte invece che <script type="text/plain">).
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Consent_Gate {

    /** @var DBSEO_Consent_Gate|null */
    private static $instance = null;

    /** @var array Cache della valutazione consenso per servizio. */
    private $consent_cache = array();

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (is_admin()) return;

        // Consent Mode default DENIED: deve essere il PRIMO snippet sulla pagina,
        // prima di qualsiasi gtag/GTM. Priorità 0 in wp_head.
        add_action('wp_head', array($this, 'output_consent_mode_default'), 0);

        // Snippet analytics: priorità alta per stare in fondo al wp_head/footer
        // ed essere comunque sopra ad altri inserimenti tardivi.
        add_action('wp_head', array($this, 'output_analytics_head'), 99);
        add_action('wp_footer', array($this, 'output_analytics_footer'), 5);

        // WP Consent API registration: ci dichiariamo come servizio gestito.
        // Funziona se il sito ha installato un consent plugin compatibile (Complianz,
        // Real Cookie Banner, GDPR Cookie Compliance, ecc.).
        add_action('wp_loaded', array($this, 'register_with_consent_api'));

        // Consent listener JS — propaga i cambi di consenso emessi dal Cookie
        // Manager DB a Google Consent Mode v2 senza reload. Vedi assets/js/
        // consent-listener.js. Enqueued solo se Consent Mode v2 è attivo.
        add_action('wp_enqueue_scripts', array($this, 'enqueue_consent_listener'));
    }

    /* =====================================================================
     * WP Consent API — registrazione servizio
     * ================================================================== */

    /**
     * Si registra come "plugin" presso la WP Consent API e dichiara i servizi
     * che attiviamo. Il consent manager userà queste informazioni per:
     *  - Mostrare i nostri cookie nella sua UI cookie policy
     *  - Bloccare/sbloccare i nostri script in base al consenso
     *  - Comunicarci via JS quando il consenso cambia
     *
     * Tutto è additivo: se la Consent API non c'è, non succede nulla.
     */
    public function register_with_consent_api() {
        if (!function_exists('wp_register_consent_plugin')) return;

        $core = DBSEO_Core::instance();
        if (!$core->get('analytics_enabled') && !$core->get('meta_pixel_enabled')) return;

        // Registriamo il "plugin" presso la Consent API.
        wp_register_consent_plugin(
            'db-seo-manager',
            __('DB SEO Manager', 'db-seo-manager')
        );

        // Per ogni servizio attivo, dichiariamo: categoria, cookie, script bloccati.
        if ($core->get('analytics_enabled')) {
            $this->register_analytics_service($core);
        }

        if ($core->get('meta_pixel_enabled')) {
            $this->register_meta_pixel_service($core);
        }
    }

    private function register_analytics_service($core) {
        if (!function_exists('wp_register_consent_service')) return;

        $provider = $core->get('analytics_provider', 'none');
        if ($provider === 'none') return;

        $catalog = array(
            'ga4' => array(
                'name'     => 'Google Analytics 4',
                'category' => 'statistics',
                'cookies'  => array(
                    array('name' => '_ga',     'duration' => '2 years',  'purpose' => __('Identifica l\'utente in modo univoco.', 'db-seo-manager')),
                    array('name' => '_ga_*',   'duration' => '2 years',  'purpose' => __('Identifica la sessione GA4.', 'db-seo-manager')),
                    array('name' => '_gid',    'duration' => '24 hours', 'purpose' => __('Identifica l\'utente per 24 ore.', 'db-seo-manager')),
                ),
            ),
            'gtm' => array(
                'name'     => 'Google Tag Manager',
                'category' => 'statistics',
                'cookies'  => array(
                    array('name' => '_dc_gtm_*', 'duration' => '1 minute', 'purpose' => __('Throttle delle richieste GA via GTM.', 'db-seo-manager')),
                ),
            ),
            'matomo' => array(
                'name'     => 'Matomo',
                'category' => 'statistics',
                'cookies'  => array(), // Configurato cookieless di default dal plugin.
            ),
            'plausible' => array(
                'name'     => 'Plausible Analytics',
                'category' => 'statistics',
                'cookies'  => array(), // Cookieless by design.
            ),
            'umami' => array(
                'name'     => 'Umami Analytics',
                'category' => 'statistics',
                'cookies'  => array(), // Cookieless by design.
            ),
        );

        if (!isset($catalog[$provider])) return;
        $svc = $catalog[$provider];

        wp_register_consent_service(
            'db-seo-manager',
            sanitize_key($provider),
            $svc['category'],
            $svc['name'],
            array(
                'cookies'         => $svc['cookies'],
                'privacy_policy'  => $this->privacy_policy_url($provider),
                'description'     => sprintf(
                    /* translators: %s: provider name */
                    __('Misurazione del traffico tramite %s.', 'db-seo-manager'),
                    $svc['name']
                ),
            )
        );
    }

    private function register_meta_pixel_service($core) {
        if (!function_exists('wp_register_consent_service')) return;

        wp_register_consent_service(
            'db-seo-manager',
            'meta_pixel',
            'marketing',
            'Meta (Facebook) Pixel',
            array(
                'cookies' => array(
                    array('name' => '_fbp', 'duration' => '3 months', 'purpose' => __('Identifica il browser per misurazione conversioni Meta.', 'db-seo-manager')),
                    array('name' => '_fbc', 'duration' => '2 years',  'purpose' => __('Identifica i click dagli annunci Meta.', 'db-seo-manager')),
                ),
                'privacy_policy' => 'https://www.facebook.com/policy.php',
                'description'    => __('Misurazione conversioni e remarketing su piattaforme Meta. Trasferimento extra-UE (USA).', 'db-seo-manager'),
            )
        );
    }

    private function privacy_policy_url($provider) {
        $urls = array(
            'ga4'       => 'https://policies.google.com/privacy',
            'gtm'       => 'https://policies.google.com/privacy',
            'matomo'    => '', // self-hosted, dipende dall'admin del Matomo
            'plausible' => 'https://plausible.io/privacy',
            'umami'     => '', // dipende dall'istanza
        );
        return $urls[$provider] ?? '';
    }

    /* =====================================================================
     * Valutazione consenso
     * ================================================================== */

    /**
     * Determina se per il servizio indicato c'è consenso valido.
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
     * Lo emettiamo solo se è abilitato un servizio Google (GA4/GTM) E il consent mode è on.
     */
    public function output_consent_mode_default() {
        $core = DBSEO_Core::instance();

        if (!$core->get('analytics_enabled')) return;
        if (!$core->get('analytics_consent_mode')) return;

        $provider = $core->get('analytics_provider', 'none');
        if (!in_array($provider, array('ga4', 'gtm'), true)) return;

        // Se il consenso è già concesso lato server (es. utente loggato di test),
        // emettiamo "granted" per non rallentare il caricamento.
        $stats_granted = $this->has_consent('ga4', 'statistics') ? 'granted' : 'denied';
        $mkt_granted   = $this->has_consent('ga4', 'marketing')  ? 'granted' : 'denied';

        $defaults = array(
            'ad_storage'              => $mkt_granted,
            'ad_user_data'            => $mkt_granted,
            'ad_personalization'      => $mkt_granted,
            'analytics_storage'       => $stats_granted,
            'functionality_storage'   => 'granted',
            'security_storage'        => 'granted',
            'wait_for_update'         => 500,
        );

        // Filter pubblico: temi/plugin possono modificare i default.
        $defaults = (array) apply_filters('dbseo_consent_mode_defaults', $defaults);

        $json = wp_json_encode($defaults, JSON_UNESCAPED_SLASHES);
        if ($json === false) return;

        echo "\n<!-- DB SEO: Google Consent Mode v2 default -->\n";
        echo "<script>\n";
        echo "window.dataLayer = window.dataLayer || [];\n";
        echo "function gtag(){dataLayer.push(arguments);}\n";
        echo "gtag('consent', 'default', {$json});\n";
        echo "</script>\n";
    }

    /**
     * Enqueue del listener JS che reagisce all'evento 'dbcm:consent' del
     * Cookie Manager DB ed emette gtag('consent','update', ...).
     *
     * Stampato solo quando ha senso emetterlo:
     *  - analytics_enabled E analytics_consent_mode E provider in [ga4, gtm]
     *  - Lo stesso gate di output_consent_mode_default(): se non emettiamo
     *    il default 'denied', non serve nemmeno il listener per fare update.
     *
     * Il mapping categorie WP Consent API → signals Google Consent Mode v2 è
     * filtrabile via 'dbseo_consent_mode_signal_map'. Default:
     *   statistics → analytics_storage
     *   marketing  → ad_storage, ad_user_data, ad_personalization
     */
    public function enqueue_consent_listener() {
        $core = DBSEO_Core::instance();

        if (!$core->get('analytics_enabled')) return;
        if (!$core->get('analytics_consent_mode')) return;

        $provider = $core->get('analytics_provider', 'none');
        if (!in_array($provider, array('ga4', 'gtm'), true)) return;

        $handle = 'dbseo-consent-listener';
        wp_register_script(
            $handle,
            DBSEO_PLUGIN_URL . 'assets/js/consent-listener.js',
            array(),
            DBSEO_VERSION,
            false // in head: deve registrarsi al listener prima che il banner emetta l'evento
        );

        /**
         * Filtra il mapping categorie WP Consent API → signals Consent Mode v2.
         *
         * Esempio (spostare statistics-anonymous su un signal custom):
         *   add_filter('dbseo_consent_mode_signal_map', function($map){
         *       $map['statistics-anonymous'] = array('analytics_storage');
         *       return $map;
         *   });
         *
         * @param array $map  Mappa categoria WP Consent API → array di signals.
         */
        $signal_map = (array) apply_filters('dbseo_consent_mode_signal_map', array(
            'statistics' => array('analytics_storage'),
            'marketing'  => array('ad_storage', 'ad_user_data', 'ad_personalization'),
        ));

        wp_localize_script($handle, 'DBSEOConsent', array(
            'signalMap' => $signal_map,
            'debug'     => (defined('WP_DEBUG') && WP_DEBUG) ? true : false,
        ));

        wp_enqueue_script($handle);
    }

    /* =====================================================================
     * Snippet analytics
     * ================================================================== */

    /**
     * Snippet che vanno nell'<head> (GA4, GTM, Matomo).
     */
    public function output_analytics_head() {
        $core     = DBSEO_Core::instance();
        if (!$core->get('analytics_enabled')) return;

        $provider = $core->get('analytics_provider', 'none');
        $id       = trim((string) $core->get('analytics_id'));
        if ($id === '' || $provider === 'none') return;

        switch ($provider) {
            case 'ga4':
                $this->snippet_ga4($id);
                break;
            case 'gtm':
                $this->snippet_gtm($id);
                break;
            case 'matomo':
                $this->snippet_matomo($id);
                break;
            case 'plausible':
                $this->snippet_plausible($id);
                break;
            case 'umami':
                $this->snippet_umami($id);
                break;
        }

        // Meta Pixel: separato, abilitazione e ID indipendenti.
        if ($core->get('meta_pixel_enabled')) {
            $pixel_id = trim((string) $core->get('meta_pixel_id'));
            if ($pixel_id !== '') {
                $this->snippet_meta_pixel($pixel_id);
            }
        }
    }

    /**
     * GTM noscript fallback nel <body> (subito dopo l'apertura).
     */
    public function output_analytics_footer() {
        $core = DBSEO_Core::instance();
        if (!$core->get('analytics_enabled')) return;
        if ($core->get('analytics_provider') !== 'gtm') return;

        $id = trim((string) $core->get('analytics_id'));
        if ($id === '' || !$this->has_consent('gtm', 'statistics')) return;

        printf(
            '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=%s" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n",
            esc_attr($id)
        );
    }

    /* =====================================================================
     * Snippet specifici per provider
     * ================================================================== */

    private function snippet_ga4($id) {
        if (!$this->has_consent('ga4', 'statistics')) {
            $this->emit_blocked_comment('GA4');
            return;
        }
        $core = DBSEO_Core::instance();
        $config = array();
        if ($core->get('analytics_anonymize_ip')) {
            // GA4 anonimizza IP di default; lasciamo il flag per esplicità.
            $config['anonymize_ip'] = true;
        }
        $config = apply_filters('dbseo_ga4_config', $config, $id);
        $config_json = wp_json_encode($config, JSON_UNESCAPED_SLASHES);

        echo "\n<!-- DB SEO: Google Analytics 4 -->\n";
        printf(
            '<script async src="https://www.googletagmanager.com/gtag/js?id=%s"></script>' . "\n",
            esc_attr($id)
        );
        echo "<script>\n";
        echo "window.dataLayer = window.dataLayer || [];\n";
        echo "function gtag(){dataLayer.push(arguments);}\n";
        echo "gtag('js', new Date());\n";
        printf("gtag('config', %s, %s);\n", wp_json_encode($id), $config_json);
        echo "</script>\n";
    }

    private function snippet_gtm($id) {
        if (!$this->has_consent('gtm', 'statistics')) {
            $this->emit_blocked_comment('GTM');
            return;
        }
        echo "\n<!-- DB SEO: Google Tag Manager -->\n";
        echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':";
        echo "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],";
        echo "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=";
        echo "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);";
        echo "})(window,document,'script','dataLayer'," . wp_json_encode($id) . ");</script>\n";
    }

    private function snippet_matomo($endpoint) {
        if (!$this->has_consent('matomo', 'statistics')) {
            $this->emit_blocked_comment('Matomo');
            return;
        }
        // $endpoint qui contiene "url|siteId" (formato semplice configurabile in settings).
        list($url, $site_id) = array_pad(explode('|', $endpoint, 2), 2, '');
        $url     = esc_url_raw(trim($url));
        $site_id = (int) $site_id;
        if ($url === '' || $site_id < 1) return;

        $url = rtrim($url, '/');

        echo "\n<!-- DB SEO: Matomo -->\n";
        echo "<script>\n";
        echo "var _paq = window._paq = window._paq || [];\n";
        echo "_paq.push(['disableCookies']);\n";              // privacy-friendly
        echo "_paq.push(['setDoNotTrack', true]);\n";
        echo "_paq.push(['enableHeartBeatTimer']);\n";
        echo "_paq.push(['trackPageView']);\n";
        echo "_paq.push(['enableLinkTracking']);\n";
        echo "(function() {\n";
        printf("  var u='%s/';\n", esc_js($url));
        printf("  _paq.push(['setTrackerUrl', u+'matomo.php']);\n");
        printf("  _paq.push(['setSiteId', '%d']);\n", $site_id);
        echo "  var d=document, g=d.createElement('script'), s=d.getElementsByTagName('script')[0];\n";
        echo "  g.async=true; g.src=u+'matomo.js'; s.parentNode.insertBefore(g,s);\n";
        echo "})();\n";
        echo "</script>\n";
    }

    private function snippet_plausible($domain) {
        // Plausible è cookieless e GDPR-friendly. Consenso comunque rispettato:
        // se l'utente ha negato "statistics", non lo carichiamo.
        if (!$this->has_consent('plausible', 'statistics')) {
            $this->emit_blocked_comment('Plausible');
            return;
        }
        $domain = sanitize_text_field($domain);
        if ($domain === '') return;

        // Endpoint custom (Plausible self-hosted) opzionale via filter.
        $script_url = (string) apply_filters(
            'dbseo_plausible_script_url',
            'https://plausible.io/js/script.js'
        );

        echo "\n<!-- DB SEO: Plausible -->\n";
        printf(
            '<script defer data-domain="%s" src="%s"></script>' . "\n",
            esc_attr($domain),
            esc_url($script_url)
        );
    }

    private function snippet_umami($id) {
        if (!$this->has_consent('umami', 'statistics')) {
            $this->emit_blocked_comment('Umami');
            return;
        }
        // Umami: $id qui contiene "scriptUrl|websiteId"
        list($script, $website_id) = array_pad(explode('|', $id, 2), 2, '');
        $script     = esc_url_raw(trim($script));
        $website_id = sanitize_text_field(trim($website_id));
        if ($script === '' || $website_id === '') return;

        echo "\n<!-- DB SEO: Umami -->\n";
        printf(
            '<script async defer data-website-id="%s" src="%s"></script>' . "\n",
            esc_attr($website_id),
            esc_url($script)
        );
    }

    private function snippet_meta_pixel($id) {
        if (!$this->has_consent('meta_pixel', 'marketing')) {
            $this->emit_blocked_comment('Meta Pixel');
            return;
        }
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
            echo "\n<!-- DB SEO: {$service} non caricato (consenso non concesso) -->\n";
        }
    }
}
