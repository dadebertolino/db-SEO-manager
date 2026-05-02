<?php
/**
 * DBSEO_Privacy — Conformità GDPR.
 *
 * Responsabilità:
 *  - Registrare exporter ed eraser per WP Privacy Tools (art. 15 e 17 GDPR).
 *  - Aggiungere il testo suggerito per l'informativa privacy alla pagina core
 *    di WordPress (Strumenti → Privacy → Guida policy).
 *  - Generare un "registro privacy" interno: l'elenco dei trattamenti tecnici
 *    attivati dal plugin, con base giuridica suggerita e dati raccolti.
 *
 * Nota importante: il plugin NON è il titolare del trattamento. Fornisce
 * trasparenza al titolare (l'admin del sito) sui trattamenti tecnici che
 * vengono attivati dalle sue scelte di configurazione.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Privacy {

    /** @var DBSEO_Privacy|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // WP Privacy Tools.
        add_filter('wp_privacy_personal_data_exporters', array($this, 'register_exporter'));
        add_filter('wp_privacy_personal_data_erasers',   array($this, 'register_eraser'));

        // Suggested privacy policy content.
        add_action('admin_init', array($this, 'add_policy_content'));

        // Export JSON / HTML stampabile del registro.
        add_action('admin_post_dbseo_export_register', array($this, 'handle_export'));
    }

    /* =====================================================================
     * Export del registro (JSON e HTML stampabile)
     * ================================================================== */

    /**
     * Gestisce l'export del registro privacy in JSON o HTML print-ready.
     */
    public function handle_export() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('dbseo_export_register', '_dbseo_export_nonce');

        $format = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'json';
        $register = $this->get_processing_register();

        $payload = array(
            'site_url'    => home_url('/'),
            'site_name'   => get_bloginfo('name'),
            'generated_at' => current_time('c'),
            'plugin'      => 'DB SEO Manager ' . DBSEO_VERSION,
            'note'        => __('Registro tecnico dei trattamenti attivi sul sito derivati dalle impostazioni del plugin DB SEO Manager. Il plugin NON è il titolare del trattamento.', 'db-seo-manager'),
            'processings' => $register,
        );

        if ($format === 'html') {
            $this->emit_html_register($payload);
        } else {
            $this->emit_json_register($payload);
        }
    }

    private function emit_json_register($payload) {
        $filename = 'dbseo-privacy-register-' . date('Ymd-His') . '.json';
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * HTML stampabile: l'admin clicca "Stampa" e il browser produce un PDF.
     * Niente librerie embedded, qualità tipografica controllata via CSS @page.
     */
    private function emit_html_register($payload) {
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        ?>
<!DOCTYPE html>
<html lang="<?php echo esc_attr(get_locale()); ?>">
<head>
<meta charset="utf-8">
<title><?php esc_html_e('Registro privacy SEO', 'db-seo-manager'); ?> — <?php echo esc_html($payload['site_name']); ?></title>
<style>
@page { size: A4; margin: 18mm; }
* { box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; color: #111; line-height: 1.5; max-width: 760px; margin: 0 auto; padding: 24px; }
h1 { font-size: 22px; margin: 0 0 6px; }
h2 { font-size: 15px; margin: 24px 0 10px; padding-bottom: 4px; border-bottom: 1px solid #ccc; }
.meta { color: #555; font-size: 12px; margin-bottom: 24px; }
.note { background: #f5f5f5; padding: 12px 14px; border-left: 3px solid #888; font-size: 12px; margin-bottom: 24px; }
.entry { margin: 0 0 16px; padding: 14px; border: 1px solid #ddd; border-radius: 6px; page-break-inside: avoid; }
.entry-header { display: flex; justify-content: space-between; align-items: baseline; margin: 0 0 8px; }
.entry-header strong { font-size: 14px; }
.status { font-size: 11px; padding: 2px 8px; border-radius: 99px; background: #eee; color: #555; }
.status.active { background: #e7f5e7; color: #1d6e3f; }
dl { margin: 0; font-size: 12px; }
dt { font-weight: 600; margin-top: 6px; }
dd { margin: 0 0 4px; }
.print-btn { background: #2271b1; color: #fff; border: 0; padding: 8px 16px; border-radius: 4px; cursor: pointer; font: inherit; }
@media print { .print-btn { display: none; } body { padding: 0; max-width: none; } }
</style>
</head>
<body>
<button class="print-btn" onclick="window.print()"><?php esc_html_e('Stampa / Salva come PDF', 'db-seo-manager'); ?></button>

<h1><?php esc_html_e('Registro privacy — Trattamenti tecnici SEO', 'db-seo-manager'); ?></h1>
<div class="meta">
    <strong><?php echo esc_html($payload['site_name']); ?></strong> — <?php echo esc_html($payload['site_url']); ?><br>
    <?php
    printf(
        /* translators: %s: timestamp */
        esc_html__('Generato il: %s', 'db-seo-manager'),
        esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $payload['generated_at']))
    );
    ?><br>
    <?php echo esc_html($payload['plugin']); ?>
</div>

<div class="note"><?php echo esc_html($payload['note']); ?></div>

<h2><?php esc_html_e('Trattamenti', 'db-seo-manager'); ?></h2>
<?php foreach ($payload['processings'] as $entry) : ?>
    <div class="entry">
        <div class="entry-header">
            <strong><?php echo esc_html($entry['label']); ?></strong>
            <span class="status <?php echo $entry['status'] === 'active' ? 'active' : ''; ?>">
                <?php echo $entry['status'] === 'active' ? esc_html__('ATTIVO', 'db-seo-manager') : esc_html__('disattivo', 'db-seo-manager'); ?>
            </span>
        </div>
        <dl>
            <dt><?php esc_html_e('Finalità', 'db-seo-manager'); ?></dt>
            <dd><?php echo esc_html($entry['purpose']); ?></dd>
            <dt><?php esc_html_e('Base giuridica', 'db-seo-manager'); ?></dt>
            <dd><?php echo esc_html($entry['legal_basis']); ?></dd>
            <dt><?php esc_html_e('Dati raccolti', 'db-seo-manager'); ?></dt>
            <dd><?php echo esc_html($entry['data_collected']); ?></dd>
            <dt><?php esc_html_e('Conservazione', 'db-seo-manager'); ?></dt>
            <dd><?php echo esc_html($entry['retention']); ?></dd>
            <dt><?php esc_html_e('Trasferimenti', 'db-seo-manager'); ?></dt>
            <dd><?php echo esc_html($entry['transfers']); ?></dd>
        </dl>
    </div>
<?php endforeach; ?>

</body>
</html>
        <?php
        exit;
    }

    /* =====================================================================
     * Registro privacy interno
     * ================================================================== */

    /**
     * Restituisce l'elenco dei trattamenti attualmente attivi sul sito,
     * derivati dalle impostazioni. Usato dalla pagina admin "Privacy SEO".
     *
     * Ogni voce: id, label, status (active/inactive), purpose, legal_basis,
     * data_collected, retention, transfers, _source.
     *
     * Il campo `_source` è valorizzato a 'self' per i trattamenti propri del
     * SEO Manager, oppure 'external' per voci aggiunte da altri plugin DB
     * via il filter `dbseo_processing_register`. È usato dalla UI Privacy
     * SEO per mostrare un badge "Plugin esterno" sulle voci di altri plugin.
     *
     * --- Contratto del filter `dbseo_processing_register` -------------------
     *
     * Altri plugin DB (es. Cookie Manager, Form Builder) possono dichiarare
     * i propri trattamenti agganciandosi a questo filter. Esempio:
     *
     *   add_filter('dbseo_processing_register', function($register) {
     *       $register[] = array(
     *           'id'             => 'mioplugin_invio_form',     // chiave univoca
     *           'label'          => __('Invio modulo contatti', 'mioplugin'),
     *           'status'         => 'active',                   // active|inactive
     *           'purpose'        => __('...', 'mioplugin'),
     *           'legal_basis'    => __('Consenso (art. 7 GDPR).', 'mioplugin'),
     *           'data_collected' => __('Nome, email, messaggio.', 'mioplugin'),
     *           'retention'      => __('365 giorni, poi cancellazione.', 'mioplugin'),
     *           'transfers'      => __('Nessuno.', 'mioplugin'),
     *       );
     *       return $register;
     *   });
     *
     * Tutti i campi sono obbligatori. Il SEO Manager non valida né normalizza
     * gli input: la responsabilità del contenuto è di chi dichiara.
     *
     * @return array
     */
    public function get_processing_register() {
        $core = DBSEO_Core::instance();
        $reg  = array();

        /* --- Funzioni core SEO (sempre attive, nessun dato personale) ---- */
        $reg[] = array(
            'id'             => 'core_seo',
            'label'          => __('SEO on-page (titoli, meta, OG, schema, sitemap)', 'db-seo-manager'),
            'status'         => 'active',
            'purpose'        => __('Generazione meta tag e dati strutturati per motori di ricerca.', 'db-seo-manager'),
            'legal_basis'    => __('Non applicabile: nessun dato personale trattato.', 'db-seo-manager'),
            'data_collected' => __('Nessuno.', 'db-seo-manager'),
            'retention'      => __('Non applicabile.', 'db-seo-manager'),
            'transfers'      => __('Nessun trasferimento. Tutto il rendering è server-side e locale.', 'db-seo-manager'),
        );

        /* --- 404 monitor ----------------------------------------------- */
        if ($core->get('monitor_404_enabled')) {
            $reg[] = array(
                'id'             => 'monitor_404',
                'label'          => __('Monitoraggio errori 404', 'db-seo-manager'),
                'status'         => 'active',
                'purpose'        => __('Identificare URL non funzionanti per migliorare la qualità del sito.', 'db-seo-manager'),
                'legal_basis'    => __('Legittimo interesse del titolare (manutenzione tecnica).', 'db-seo-manager'),
                'data_collected' => $core->get('monitor_404_log_ip')
                    ? __('URL richiesto, referer, indirizzo IP, conteggio, timestamp.', 'db-seo-manager')
                    : __('URL richiesto, referer, conteggio, timestamp. Nessun IP, nessun user-agent.', 'db-seo-manager'),
                'retention'      => sprintf(
                    /* translators: %d = giorni */
                    __('Auto-pulizia dopo %d giorni.', 'db-seo-manager'),
                    (int) $core->get('monitor_404_keep_days', 30)
                ),
                'transfers'      => __('Nessun trasferimento esterno. Dati salvati solo nel database locale.', 'db-seo-manager'),
            );
        }

        /* --- Redirect log ---------------------------------------------- */
        if ($core->get('redirects_enabled')) {
            $reg[] = array(
                'id'             => 'redirects',
                'label'          => __('Gestore redirect', 'db-seo-manager'),
                'status'         => 'active',
                'purpose'        => __('Reindirizzamento URL e conteggio aggregato degli hit.', 'db-seo-manager'),
                'legal_basis'    => __('Legittimo interesse (manutenzione tecnica).', 'db-seo-manager'),
                'data_collected' => __('Solo conteggi aggregati (numero hit, ultimo timestamp). Nessun dato personale.', 'db-seo-manager'),
                'retention'      => __('Conservati finché la regola di redirect è attiva.', 'db-seo-manager'),
                'transfers'      => __('Nessuno.', 'db-seo-manager'),
            );
        }

        /* --- IndexNow -------------------------------------------------- */
        if ($core->get('indexnow_enabled')) {
            $reg[] = array(
                'id'             => 'indexnow',
                'label'          => __('IndexNow (notifica motori di ricerca)', 'db-seo-manager'),
                'status'         => 'active',
                'purpose'        => __('Notifica server-to-server di URL aggiornati ai motori di ricerca compatibili (Bing, Yandex).', 'db-seo-manager'),
                'legal_basis'    => __('Legittimo interesse del titolare (visibilità del sito).', 'db-seo-manager'),
                'data_collected' => __('Solo URL pubblici del sito. Nessun dato di utenti.', 'db-seo-manager'),
                'retention'      => __('Coda interna ripulita dopo invio.', 'db-seo-manager'),
                'transfers'      => __('URL inviati a endpoint pubblici di Bing (Microsoft) e Yandex. Trasferimento extra-UE possibile per Yandex.', 'db-seo-manager'),
            );
        }

        /* --- Analytics provider ---------------------------------------- */
        if ($core->get('analytics_enabled')) {
            $provider = $core->get('analytics_provider', 'none');
            if ($provider !== 'none') {
                $reg[] = $this->describe_analytics_provider($provider);
            }
        }

        /* --- Meta Pixel ------------------------------------------------ */
        if ($core->get('meta_pixel_enabled')) {
            $reg[] = array(
                'id'             => 'meta_pixel',
                'label'          => __('Meta (Facebook) Pixel', 'db-seo-manager'),
                'status'         => 'active',
                'purpose'        => __('Misurazione conversioni e remarketing su piattaforme Meta.', 'db-seo-manager'),
                'legal_basis'    => __('Consenso (art. 7 GDPR e art. 122 Codice Privacy IT).', 'db-seo-manager'),
                'data_collected' => __('IP, user-agent, identificatori cookie Meta, eventi di navigazione, URL visitati.', 'db-seo-manager'),
                'retention'      => __('Definita dalle policy di Meta Platforms Ireland Ltd.', 'db-seo-manager'),
                'transfers'      => __('Trasferimento extra-UE (Stati Uniti) verso Meta Platforms. Necessarie SCC e valutazioni di adeguatezza (Schrems II / DPF).', 'db-seo-manager'),
            );
        }

        /* --- Verifiche search engine (solo meta tag) ------------------- */
        if ($core->get('verify_google') || $core->get('verify_bing') || $core->get('verify_yandex') || $core->get('verify_pinterest')) {
            $reg[] = array(
                'id'             => 'search_verification',
                'label'          => __('Verifica proprietà sito (search engines)', 'db-seo-manager'),
                'status'         => 'active',
                'purpose'        => __('Dimostrare la proprietà del sito ai motori di ricerca.', 'db-seo-manager'),
                'legal_basis'    => __('Legittimo interesse.', 'db-seo-manager'),
                'data_collected' => __('Solo emissione di meta tag statici nell\'HTML. Nessuna chiamata a server esterni.', 'db-seo-manager'),
                'retention'      => __('Non applicabile.', 'db-seo-manager'),
                'transfers'      => __('Nessuno.', 'db-seo-manager'),
            );
        }

        // Cattura gli id delle voci proprie (pre-filter) per poter
        // distinguere, dopo il filter, quali voci sono state aggiunte da
        // altri plugin DB. Usato dalla UI per il badge "Plugin esterno".
        $self_ids = array();
        foreach ($reg as $entry) {
            if (isset($entry['id'])) {
                $self_ids[] = $entry['id'];
            }
        }

        $reg = (array) apply_filters('dbseo_processing_register', $reg);

        // Annota la sorgente di ogni voce. Le entry pre-esistenti restano
        // 'self'; tutte le altre (aggiunte/modificate da plugin esterni)
        // sono marcate 'external'. Plugin che vogliono dichiarare la
        // sorgente in modo esplicito possono valorizzare _source da soli.
        foreach ($reg as $key => $entry) {
            if (!is_array($entry)) continue;
            if (isset($entry['_source'])) continue; // rispetta override esplicito
            $entry_id = $entry['id'] ?? '';
            $reg[$key]['_source'] = in_array($entry_id, $self_ids, true) ? 'self' : 'external';
        }

        return $reg;
    }

    private function describe_analytics_provider($provider) {
        $consent_mode = DBSEO_Core::instance()->get('analytics_consent_mode');

        switch ($provider) {
            case 'ga4':
                return array(
                    'id'             => 'ga4',
                    'label'          => __('Google Analytics 4', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Misurazione del traffico e analisi del comportamento di navigazione.', 'db-seo-manager'),
                    'legal_basis'    => __('Consenso (art. 7 GDPR e art. 122 Codice Privacy IT).', 'db-seo-manager'),
                    'data_collected' => __('IP, user-agent, identificatori client, eventi, URL visitati, riferimenti, dati dispositivo.', 'db-seo-manager'),
                    'retention'      => __('Definita nelle impostazioni della proprietà GA4 (default 14 mesi).', 'db-seo-manager'),
                    'transfers'      => $consent_mode
                        ? __('Trasferimento extra-UE (USA) verso Google LLC. Consent Mode v2 attivo: nessun cookie/identificatore prima del consenso. Necessarie SCC e valutazione DPF.', 'db-seo-manager')
                        : __('Trasferimento extra-UE (USA) verso Google LLC. Necessarie SCC e valutazione DPF. ATTENZIONE: Consent Mode v2 disattivato.', 'db-seo-manager'),
                );
            case 'gtm':
                return array(
                    'id'             => 'gtm',
                    'label'          => __('Google Tag Manager', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Container per il caricamento di tag di terze parti.', 'db-seo-manager'),
                    'legal_basis'    => __('Consenso (in funzione dei tag effettivamente caricati).', 'db-seo-manager'),
                    'data_collected' => __('Dipende dai tag configurati nel container GTM.', 'db-seo-manager'),
                    'retention'      => __('Definita dai singoli servizi caricati via GTM.', 'db-seo-manager'),
                    'transfers'      => __('Trasferimento extra-UE (USA) verso Google LLC. Necessarie SCC e valutazione DPF.', 'db-seo-manager'),
                );
            case 'matomo':
                return array(
                    'id'             => 'matomo',
                    'label'          => __('Matomo (self-hosted)', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Analisi del traffico in modalità privacy-friendly.', 'db-seo-manager'),
                    'legal_basis'    => __('Configurazione: cookieless con anonimizzazione IP. Verificare se il consenso è richiesto in base alle impostazioni del titolare.', 'db-seo-manager'),
                    'data_collected' => __('URL visitati, riferimenti, dati aggregati. Cookie disabilitati di default dal plugin.', 'db-seo-manager'),
                    'retention'      => __('Definita dall\'admin del Matomo self-hosted.', 'db-seo-manager'),
                    'transfers'      => __('Nessun trasferimento extra-UE se l\'istanza Matomo è ospitata in UE.', 'db-seo-manager'),
                );
            case 'plausible':
                return array(
                    'id'             => 'plausible',
                    'label'          => __('Plausible Analytics', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Analisi del traffico cookieless e aggregata.', 'db-seo-manager'),
                    'legal_basis'    => __('Plausible è progettato per non richiedere consenso. Verificare la valutazione del titolare in base al contesto.', 'db-seo-manager'),
                    'data_collected' => __('Dati aggregati senza cookie e senza identificatori persistenti.', 'db-seo-manager'),
                    'retention'      => __('Definita da Plausible Insights OÜ (Estonia, UE).', 'db-seo-manager'),
                    'transfers'      => __('Nessun trasferimento extra-UE. Server in UE (Plausible.io). Possibile self-hosting per controllo totale.', 'db-seo-manager'),
                );
            case 'umami':
                return array(
                    'id'             => 'umami',
                    'label'          => __('Umami Analytics', 'db-seo-manager'),
                    'status'         => 'active',
                    'purpose'        => __('Analisi del traffico cookieless.', 'db-seo-manager'),
                    'legal_basis'    => __('Umami non utilizza cookie. Verificare in base al contesto del titolare.', 'db-seo-manager'),
                    'data_collected' => __('Dati aggregati senza cookie e senza identificatori persistenti.', 'db-seo-manager'),
                    'retention'      => __('Definita dall\'admin dell\'istanza Umami.', 'db-seo-manager'),
                    'transfers'      => __('Dipende dall\'hosting dell\'istanza Umami (raccomandato: UE o self-hosted).', 'db-seo-manager'),
                );
        }
        return array();
    }

    /* =====================================================================
     * WP Privacy Tools — Exporter
     * ================================================================== */

    public function register_exporter($exporters) {
        $exporters['db-seo-manager'] = array(
            'exporter_friendly_name' => __('DB SEO Manager', 'db-seo-manager'),
            'callback'               => array($this, 'export_user_data'),
        );
        return $exporters;
    }

    /**
     * Export dei dati personali eventualmente collegati a un utente.
     * Il plugin stesso non raccoglie dati utente, ma se un autore ha campi SEO
     * personalizzati sui suoi post (titoli, descrizioni custom), li elenchiamo
     * per trasparenza.
     */
    public function export_user_data($email_address, $page = 1) {
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return array('data' => array(), 'done' => true);
        }

        $per_page = 50;
        $posts = get_posts(array(
            'author'         => $user->ID,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_type'      => 'any',
            'post_status'    => 'any',
        ));

        $data = array();
        foreach ($posts as $post) {
            $items = array();
            $keys  = array(
                '_dbseo_title'         => __('SEO title', 'db-seo-manager'),
                '_dbseo_description'   => __('SEO description', 'db-seo-manager'),
                '_dbseo_canonical'     => __('Canonical URL', 'db-seo-manager'),
                '_dbseo_focus_keyword' => __('Focus keyword', 'db-seo-manager'),
            );
            foreach ($keys as $key => $label) {
                $val = get_post_meta($post->ID, $key, true);
                if ($val !== '') {
                    $items[] = array('name' => $label, 'value' => $val);
                }
            }
            if (!empty($items)) {
                $data[] = array(
                    'group_id'    => 'db-seo-manager-post-meta',
                    'group_label' => __('Metadati SEO autore', 'db-seo-manager'),
                    'item_id'     => 'post-' . $post->ID,
                    'data'        => array_merge(
                        array(array('name' => __('Post', 'db-seo-manager'), 'value' => get_permalink($post->ID))),
                        $items
                    ),
                );
            }
        }

        return array(
            'data' => $data,
            'done' => count($posts) < $per_page,
        );
    }

    /* =====================================================================
     * WP Privacy Tools — Eraser
     * ================================================================== */

    public function register_eraser($erasers) {
        $erasers['db-seo-manager'] = array(
            'eraser_friendly_name' => __('DB SEO Manager', 'db-seo-manager'),
            'callback'             => array($this, 'erase_user_data'),
        );
        return $erasers;
    }

    /**
     * Cancella i metadati SEO custom su post di un utente.
     * Le voci sui post vengono rimosse, ma il post resta (è competenza di altri eraser).
     */
    public function erase_user_data($email_address, $page = 1) {
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return array(
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => array(),
                'done'           => true,
            );
        }

        $per_page = 50;
        $posts = get_posts(array(
            'author'         => $user->ID,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_type'      => 'any',
            'post_status'    => 'any',
            'fields'         => 'ids',
        ));

        $removed = false;
        $keys = array(
            '_dbseo_title', '_dbseo_description', '_dbseo_canonical',
            '_dbseo_focus_keyword', '_dbseo_og_title', '_dbseo_og_description',
            '_dbseo_og_image_id', '_dbseo_twitter_title', '_dbseo_twitter_description',
            '_dbseo_twitter_image_id', '_dbseo_robots_noindex', '_dbseo_robots_nofollow',
            '_dbseo_score', '_dbseo_cornerstone', '_dbseo_schema_type',
        );

        foreach ($posts as $post_id) {
            foreach ($keys as $key) {
                if (metadata_exists('post', $post_id, $key)) {
                    delete_post_meta($post_id, $key);
                    $removed = true;
                }
            }
        }

        return array(
            'items_removed'  => $removed,
            'items_retained' => false,
            'messages'       => array(),
            'done'           => count($posts) < $per_page,
        );
    }

    /* =====================================================================
     * Suggested privacy policy content
     * ================================================================== */

    /**
     * Aggiunge testo suggerito per l'informativa privacy alla pagina core di WP.
     * Il testo è dinamico: include solo le sezioni dei servizi effettivamente attivi.
     */
    public function add_policy_content() {
        if (!function_exists('wp_add_privacy_policy_content')) {
            return;
        }

        $content = $this->build_policy_content();
        if ($content === '') return;

        wp_add_privacy_policy_content('DB SEO Manager', wp_kses_post(wpautop($content)));
    }

    private function build_policy_content() {
        $register = $this->get_processing_register();
        if (empty($register)) return '';

        $out  = __('Il sito utilizza il plugin DB SEO Manager per la gestione dei meta dati SEO. Di seguito sono elencati i trattamenti tecnici attivi che possono interessare la tua privacy.', 'db-seo-manager') . "\n\n";

        foreach ($register as $entry) {
            // Saltiamo le voci che non comportano trattamento di dati personali.
            if (in_array($entry['id'], array('core_seo', 'redirects', 'search_verification'), true)) {
                continue;
            }
            $out .= '<strong>' . esc_html($entry['label']) . '</strong><br>';
            $out .= '<em>' . esc_html__('Finalità:', 'db-seo-manager') . '</em> ' . esc_html($entry['purpose']) . '<br>';
            $out .= '<em>' . esc_html__('Base giuridica:', 'db-seo-manager') . '</em> ' . esc_html($entry['legal_basis']) . '<br>';
            $out .= '<em>' . esc_html__('Dati raccolti:', 'db-seo-manager') . '</em> ' . esc_html($entry['data_collected']) . '<br>';
            $out .= '<em>' . esc_html__('Conservazione:', 'db-seo-manager') . '</em> ' . esc_html($entry['retention']) . '<br>';
            $out .= '<em>' . esc_html__('Trasferimenti:', 'db-seo-manager') . '</em> ' . esc_html($entry['transfers']) . "\n\n";
        }

        return $out;
    }
}
