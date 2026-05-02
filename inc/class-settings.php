<?php
/**
 * DBSEO_Settings — Pagine admin (menu + tabs + form).
 *
 * Architettura:
 *  - Un menu di primo livello "DB SEO" + sottopagine.
 *  - Le sottopagine sono "tab logici": ognuna è un template in templates/admin/.
 *  - Salvataggio centralizzato via admin-post.php (action dbseo_save_settings)
 *    con nonce e capability check. Sanitizzazione per chiave.
 *  - Le sezioni Privacy, Redirect, 404 hanno UI dedicate (renderer specifici).
 *
 * Sicurezza:
 *  - Nonce su ogni form.
 *  - capability_check 'manage_options'.
 *  - Sanitizzazione esplicita di ogni campo per tipo.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Settings {

    const MENU_SLUG    = 'dbseo';
    const NONCE_ACTION = 'dbseo_save_settings';
    const NONCE_FIELD  = 'dbseo_settings_nonce';
    const SAVE_ACTION  = 'dbseo_save_settings';

    /** @var DBSEO_Settings|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!is_admin()) return;

        add_action('admin_menu',                          array($this, 'register_menu'));
        add_action('admin_enqueue_scripts',               array($this, 'enqueue_assets'));
        add_action('admin_post_' . self::SAVE_ACTION,     array($this, 'handle_save'));
        add_action('admin_post_dbseo_redirect_action',    array($this, 'handle_redirect_action'));
        add_action('admin_post_dbseo_404_action',         array($this, 'handle_404_action'));
        add_action('admin_notices',                       array($this, 'admin_notices'));
    }

    /* =====================================================================
     * Registrazione menu
     * ================================================================== */

    public function register_menu() {
        add_menu_page(
            __('DB SEO Manager', 'db-seo-manager'),
            __('DB SEO', 'db-seo-manager'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_dashboard'),
            'dashicons-search',
            58
        );

        $subpages = array(
            // Niente 'dashboard' qui: la voce auto-generata da add_menu_page()
            // usa già lo stesso slug e callback. La rinominiamo sotto.
            'titles'    => array(__('Titoli & Meta',      'db-seo-manager'), 'render_titles'),
            'social'    => array(__('Social',             'db-seo-manager'), 'render_social'),
            'schema'    => array(__('Schema.org',         'db-seo-manager'), 'render_schema'),
            'sitemap'   => array(__('Sitemap & Robots',   'db-seo-manager'), 'render_sitemap'),
            'redirects' => array(__('Redirect',           'db-seo-manager'), 'render_redirects'),
            'log404'    => array(__('Errori 404',         'db-seo-manager'), 'render_404'),
            'analytics' => array(__('Analytics & Consent','db-seo-manager'), 'render_analytics'),
            'indexnow'  => array(__('IndexNow',            'db-seo-manager'), 'render_indexnow'),
            'verify'    => array(__('Verifiche',          'db-seo-manager'), 'render_verify'),
            'hardening' => array(__('Hard Privacy',       'db-seo-manager'), 'render_hardening'),
            'status'    => array(__('Stato GDPR',         'db-seo-manager'), 'render_status'),
            'privacy'   => array(__('Privacy SEO',        'db-seo-manager'), 'render_privacy'),
        );

        foreach ($subpages as $slug => $cfg) {
            list($label, $callback) = $cfg;
            add_submenu_page(
                self::MENU_SLUG,
                $label,
                $label,
                'manage_options',
                self::MENU_SLUG . '-' . $slug,
                array($this, $callback)
            );
        }

        // Rinomina la prima voce auto-generata (default = label menu).
        global $submenu;
        if (isset($submenu[self::MENU_SLUG]) && isset($submenu[self::MENU_SLUG][0])) {
            $submenu[self::MENU_SLUG][0][0] = __('Dashboard', 'db-seo-manager');
        }
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, self::MENU_SLUG) === false) return;

        wp_enqueue_style(
            'db-admin-ui',
            DBSEO_PLUGIN_URL . 'assets/css/db-admin-ui.css',
            array(),
            DBSEO_VERSION
        );
        wp_enqueue_style(
            'dbseo-admin',
            DBSEO_PLUGIN_URL . 'assets/css/admin.css',
            array('db-admin-ui'),
            DBSEO_VERSION
        );

        wp_enqueue_media();
        wp_enqueue_script(
            'dbseo-admin',
            DBSEO_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            DBSEO_VERSION,
            true
        );
        wp_localize_script('dbseo-admin', 'DBSEOAdmin', array(
            'i18n' => array(
                'mediaTitle'  => __('Seleziona immagine', 'db-seo-manager'),
                'mediaButton' => __('Usa questa immagine', 'db-seo-manager'),
                'remove'      => __('Rimuovi', 'db-seo-manager'),
                'confirmDelete' => __('Eliminare questa voce? Operazione non annullabile.', 'db-seo-manager'),
            ),
        ));
    }

    public function admin_notices() {
        if (empty($_GET['dbseo_msg']) || empty($_GET['page']) || strpos((string) $_GET['page'], self::MENU_SLUG) !== 0) return;

        $msg  = sanitize_key(wp_unslash($_GET['dbseo_msg']));
        $messages = array(
            'saved'         => array('success', __('Impostazioni salvate.', 'db-seo-manager')),
            'redirect_added'=> array('success', __('Redirect aggiunto.', 'db-seo-manager')),
            'redirect_deleted' => array('success', __('Redirect eliminato.', 'db-seo-manager')),
            'redirect_error'=> array('error',   __('Errore: redirect non valido o duplicato.', 'db-seo-manager')),
            '404_cleared'   => array('success', __('Log 404 svuotato.', 'db-seo-manager')),
            '404_deleted'   => array('success', __('Voce 404 eliminata.', 'db-seo-manager')),
            'audit_done'    => array('success', __('Audit completato.', 'db-seo-manager')),
        );
        if (!isset($messages[$msg])) return;

        list($type, $text) = $messages[$msg];
        printf(
            '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
            esc_attr($type),
            esc_html($text)
        );
    }

    /* =====================================================================
     * Wrapper di pagina (header comune)
     * ================================================================== */

    private function open_wrap($title, $subtitle = '') {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'db-seo-manager'));
        }
        echo '<div class="wrap dbseo-wrap">';
        echo '<div class="db-ui-page-header">';
        echo '<div>';
        echo '<h1>' . esc_html($title) . '</h1>';
        if ($subtitle !== '') {
            echo '<p class="description" style="margin:4px 0 0">' . esc_html($subtitle) . '</p>';
        }
        echo '</div>';
        echo '</div>';
    }

    private function close_wrap() {
        echo '</div>'; // .wrap
    }

    /**
     * Render del form-handler shell: open form + nonce + section + close.
     */
    private function form_open($section) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dbseo-form">
            <input type="hidden" name="action"  value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
            <input type="hidden" name="section" value="<?php echo esc_attr($section); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
        <?php
    }

    private function form_close() {
        echo '<p><button type="submit" class="db-ui-btn db-ui-btn-primary db-ui-btn-lg">' .
             esc_html__('Salva impostazioni', 'db-seo-manager') . '</button></p>';
        echo '</form>';
    }

    /* =====================================================================
     * DASHBOARD
     * ================================================================== */

    public function render_dashboard() {
        $this->open_wrap(__('DB SEO Manager — Dashboard', 'db-seo-manager'),
            __('Stato delle funzionalità e accesso rapido.', 'db-seo-manager'));

        $core = DBSEO_Core::instance();
        ?>
        <div class="db-ui-alert db-ui-alert-info">
            <span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
            <span><?php esc_html_e(
                'Tutte le integrazioni con servizi esterni sono disattivate per default. Attiva solo ciò che ti serve, dopo aver letto la sezione "Privacy SEO".',
                'db-seo-manager'
            ); ?></span>
        </div>

        <div class="dbseo-grid">
            <?php
            $cards = array(
                array(__('Titoli & Meta', 'db-seo-manager'),  'titles',     true,  __('Title, description, robots per ogni tipo di contenuto.', 'db-seo-manager')),
                array(__('Social',         'db-seo-manager'), 'social',     (bool) $core->get('og_enabled'),  __('OpenGraph e Twitter Card.', 'db-seo-manager')),
                array(__('Schema.org',     'db-seo-manager'), 'schema',     (bool) $core->get('schema_enabled'), __('JSON-LD per organizzazione, articoli, breadcrumb.', 'db-seo-manager')),
                array(__('Sitemap',        'db-seo-manager'), 'sitemap',    (bool) $core->get('sitemap_enabled'), __('XML sitemap estesa con immagini.', 'db-seo-manager')),
                array(__('Redirect',       'db-seo-manager'), 'redirects',  (bool) $core->get('redirects_enabled'), __('301/302/307/410 con conteggio aggregato.', 'db-seo-manager')),
                array(__('Errori 404',     'db-seo-manager'), 'log404',     (bool) $core->get('monitor_404_enabled'), __('Monitor anonimo, nessun IP loggato.', 'db-seo-manager')),
                array(__('Analytics',      'db-seo-manager'), 'analytics',  (bool) $core->get('analytics_enabled'), __('Snippet emessi solo dopo consenso.', 'db-seo-manager')),
                array(__('Privacy SEO',    'db-seo-manager'), 'privacy',    true,  __('Registro dei trattamenti tecnici attivi.', 'db-seo-manager')),
            );
            foreach ($cards as $c) :
                list($label, $slug, $active, $desc) = $c;
                $url = admin_url('admin.php?page=' . self::MENU_SLUG . '-' . $slug);
                ?>
                <a href="<?php echo esc_url($url); ?>" class="db-ui-card dbseo-dash-card">
                    <div class="db-ui-card-body">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:6px">
                            <strong style="font-size:15px"><?php echo esc_html($label); ?></strong>
                            <span class="db-ui-badge <?php echo $active ? 'db-ui-badge-success' : 'db-ui-badge-muted'; ?>">
                                <?php echo $active ? esc_html__('Attivo', 'db-seo-manager') : esc_html__('Disattivo', 'db-seo-manager'); ?>
                            </span>
                        </div>
                        <p class="description" style="margin:0"><?php echo esc_html($desc); ?></p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php
        $this->close_wrap();
    }

    /* =====================================================================
     * TITOLI & META
     * ================================================================== */

    public function render_titles() {
        $this->open_wrap(__('Titoli & Meta', 'db-seo-manager'));
        $s = DBSEO_Core::instance()->get_settings();
        $this->form_open('titles');
        ?>
        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Generali', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->text_field('separator', $s['separator'], __('Separatore', 'db-seo-manager'), __('Esempio: -, |, »', 'db-seo-manager')); ?>
                <?php $this->text_field('site_name', $s['site_name'], __('Nome del sito', 'db-seo-manager'), __('Lascia vuoto per usare il nome del sito di WP.', 'db-seo-manager')); ?>
            </div>
        </div>

        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Template title & description', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <p class="description" style="margin-top:0">
                    <?php esc_html_e('Variabili disponibili: %title%, %sitename%, %sep%, %tagline%, %excerpt%, %archive_title%, %search_query%, %category%.', 'db-seo-manager'); ?>
                </p>
                <?php $this->text_field('title_home',     $s['title_home'],     __('Title homepage', 'db-seo-manager')); ?>
                <?php $this->text_field('title_post',     $s['title_post'],     __('Title post', 'db-seo-manager')); ?>
                <?php $this->text_field('title_page',     $s['title_page'],     __('Title pagine', 'db-seo-manager')); ?>
                <?php $this->text_field('title_archive',  $s['title_archive'],  __('Title archivi', 'db-seo-manager')); ?>
                <?php $this->text_field('title_search',   $s['title_search'],   __('Title risultati di ricerca', 'db-seo-manager')); ?>
                <?php $this->text_field('title_404',      $s['title_404'],      __('Title pagina 404', 'db-seo-manager')); ?>
                <hr class="db-ui-sep">
                <?php $this->textarea_field('desc_home',  $s['desc_home'],  __('Description homepage', 'db-seo-manager')); ?>
                <?php $this->textarea_field('desc_post',  $s['desc_post'],  __('Description post (default)', 'db-seo-manager')); ?>
                <?php $this->textarea_field('desc_page',  $s['desc_page'],  __('Description pagine (default)', 'db-seo-manager')); ?>
            </div>
        </div>

        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Direttive robots', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->checkbox_field('noindex_search',     $s['noindex_search'],     __('Noindex sui risultati di ricerca', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('noindex_404',        $s['noindex_404'],        __('Noindex sulla pagina 404', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('noindex_author',     $s['noindex_author'],     __('Noindex sugli archivi autore', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('noindex_date',       $s['noindex_date'],       __('Noindex sugli archivi per data', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('noindex_attachment', $s['noindex_attachment'], __('Noindex sugli allegati', 'db-seo-manager')); ?>
            </div>
        </div>
        <?php
        $this->form_close();
        $this->close_wrap();
    }

    /* =====================================================================
     * SOCIAL
     * ================================================================== */

    public function render_social() {
        $this->open_wrap(__('Social — OpenGraph & Twitter', 'db-seo-manager'));
        $s = DBSEO_Core::instance()->get_settings();
        $this->form_open('social');
        ?>
        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('OpenGraph', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->checkbox_field('og_enabled', $s['og_enabled'], __('Abilita OpenGraph e Twitter Card', 'db-seo-manager')); ?>
                <?php $this->image_field('og_default_image_id', $s['og_default_image_id'], __('Immagine OG di default', 'db-seo-manager'),
                    __('Usata quando un post non ha immagine in evidenza né override.', 'db-seo-manager')); ?>
            </div>
        </div>

        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Twitter / X', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->select_field('twitter_card', $s['twitter_card'], array(
                    'summary'              => 'summary',
                    'summary_large_image'  => 'summary_large_image',
                ), __('Tipo Twitter Card', 'db-seo-manager')); ?>
                <?php $this->text_field('twitter_site', $s['twitter_site'], __('Handle del sito (con o senza @)', 'db-seo-manager'), '@example'); ?>
            </div>
        </div>
        <?php
        $this->form_close();
        $this->close_wrap();
    }

    /* =====================================================================
     * SCHEMA
     * ================================================================== */

    public function render_schema() {
        $this->open_wrap(__('Schema.org — Dati strutturati', 'db-seo-manager'));
        $s = DBSEO_Core::instance()->get_settings();
        $this->form_open('schema');
        ?>
        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Configurazione', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->checkbox_field('schema_enabled', $s['schema_enabled'], __('Abilita output JSON-LD', 'db-seo-manager')); ?>
                <?php $this->select_field('schema_org_type', $s['schema_org_type'], array(
                    'Organization' => 'Organization',
                    'Person'       => 'Person',
                ), __('Tipo entità', 'db-seo-manager'),
                __('Organization per aziende/enti, Person per portfolio personali.', 'db-seo-manager')); ?>
                <?php $this->text_field('schema_org_name', $s['schema_org_name'], __('Nome entità', 'db-seo-manager')); ?>
                <?php $this->image_field('schema_org_logo_id', $s['schema_org_logo_id'], __('Logo', 'db-seo-manager'),
                    __('Usato come logo dell\'organizzazione e immagine principale.', 'db-seo-manager')); ?>
                <?php $this->text_field('schema_org_telephone', $s['schema_org_telephone'], __('Telefono (opzionale)', 'db-seo-manager')); ?>
                <?php $this->text_field('schema_org_email',     $s['schema_org_email'],     __('Email (opzionale)', 'db-seo-manager')); ?>
            </div>
        </div>

        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Breadcrumb', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->checkbox_field('breadcrumb_enabled', $s['breadcrumb_enabled'], __('Abilita briciole di pane', 'db-seo-manager')); ?>
                <?php $this->text_field('breadcrumb_home_label', $s['breadcrumb_home_label'], __('Etichetta Home', 'db-seo-manager')); ?>
                <?php $this->text_field('breadcrumb_separator',  $s['breadcrumb_separator'],  __('Separatore', 'db-seo-manager')); ?>
                <p class="description">
                    <?php esc_html_e('Inseriscile con shortcode [dbseo_breadcrumbs], blocco "Briciole di pane" o template tag dbseo_breadcrumbs() nel tema.', 'db-seo-manager'); ?>
                </p>
            </div>
        </div>
        <?php
        $this->form_close();
        $this->close_wrap();
    }

    /* =====================================================================
     * SITEMAP & ROBOTS
     * ================================================================== */

    public function render_sitemap() {
        $this->open_wrap(__('Sitemap & robots.txt', 'db-seo-manager'));
        $s = DBSEO_Core::instance()->get_settings();
        $this->form_open('sitemap');
        ?>
        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Sitemap XML', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->checkbox_field('sitemap_enabled', $s['sitemap_enabled'],
                    __('Abilita sitemap estesa', 'db-seo-manager'),
                    __('Sostituisce la sitemap core di WordPress. URL: /sitemap.xml', 'db-seo-manager')); ?>

                <?php
                $public_types = get_post_types(array('public' => true), 'objects');
                $current_types = (array) $s['sitemap_post_types'];
                ?>
                <fieldset class="db-ui-field">
                    <legend><strong><?php esc_html_e('Post type da includere', 'db-seo-manager'); ?></strong></legend>
                    <?php foreach ($public_types as $name => $obj) : if ($name === 'attachment') continue; ?>
                        <label style="display:block;margin:4px 0">
                            <input type="checkbox" name="dbseo[sitemap_post_types][]"
                                   value="<?php echo esc_attr($name); ?>"
                                   <?php checked(in_array($name, $current_types, true)); ?>>
                            <?php echo esc_html($obj->labels->name); ?>
                            <code><?php echo esc_html($name); ?></code>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <?php
                $public_tax = get_taxonomies(array('public' => true), 'objects');
                $current_tax = (array) $s['sitemap_taxonomies'];
                ?>
                <fieldset class="db-ui-field">
                    <legend><strong><?php esc_html_e('Tassonomie da includere', 'db-seo-manager'); ?></strong></legend>
                    <?php foreach ($public_tax as $name => $obj) : ?>
                        <label style="display:block;margin:4px 0">
                            <input type="checkbox" name="dbseo[sitemap_taxonomies][]"
                                   value="<?php echo esc_attr($name); ?>"
                                   <?php checked(in_array($name, $current_tax, true)); ?>>
                            <?php echo esc_html($obj->labels->name); ?>
                            <code><?php echo esc_html($name); ?></code>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <?php $this->text_field('sitemap_exclude_csv',
                    is_array($s['sitemap_exclude_ids']) ? implode(',', $s['sitemap_exclude_ids']) : '',
                    __('ID da escludere (CSV)', 'db-seo-manager'),
                    __('Esempio: 12,34,89', 'db-seo-manager')
                ); ?>
            </div>
        </div>

        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('robots.txt virtuale', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->textarea_field('robots_txt_custom', $s['robots_txt_custom'],
                    __('Direttive personalizzate', 'db-seo-manager'),
                    __('Aggiunte alle regole di base. Una direttiva per riga.', 'db-seo-manager'),
                    8); ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: robots.txt URL */
                        esc_html__('Anteprima: %s', 'db-seo-manager'),
                        '<a href="' . esc_url(home_url('/robots.txt')) . '" target="_blank" rel="noopener">' . esc_html(home_url('/robots.txt')) . '</a>'
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
        $this->form_close();
        $this->close_wrap();
    }

    /* =====================================================================
     * REDIRECTS — UI dedicata
     * ================================================================== */

    public function render_redirects() {
        $this->open_wrap(__('Gestione redirect', 'db-seo-manager'),
            __('301, 302, 307, 410. Conteggio hit aggregato, nessun IP loggato.', 'db-seo-manager'));

        $s = DBSEO_Core::instance()->get_settings();
        $module = DBSEO_Core::instance()->module('redirects');
        ?>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dbseo-form">
            <input type="hidden" name="action"  value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
            <input type="hidden" name="section" value="redirects_settings">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
            <div class="db-ui-card">
                <div class="db-ui-card-body">
                    <?php $this->checkbox_field('redirects_enabled', $s['redirects_enabled'],
                        __('Abilita redirect manager', 'db-seo-manager')); ?>
                    <?php $this->checkbox_field('redirects_no_count', $s['redirects_no_count'],
                        __('Non contare gli hit (zero-trust)', 'db-seo-manager'),
                        __('Salta anche il conteggio aggregato. I dati aggregati non sono dati personali, ma alcune policy di privacy zero-trust preferiscono non averne traccia.', 'db-seo-manager')); ?>
                    <button type="submit" class="db-ui-btn db-ui-btn-primary db-ui-btn-sm">
                        <?php esc_html_e('Salva', 'db-seo-manager'); ?>
                    </button>
                </div>
            </div>
        </form>

        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Aggiungi redirect', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action"  value="dbseo_redirect_action">
                    <input type="hidden" name="op"      value="add">
                    <?php wp_nonce_field('dbseo_redirect', '_dbseo_redirect_nonce'); ?>

                    <div style="display:grid;grid-template-columns:1fr 1fr 130px 100px auto;gap:10px;align-items:end">
                        <div class="db-ui-field" style="margin:0">
                            <label><?php esc_html_e('Origine', 'db-seo-manager'); ?></label>
                            <input type="text" name="source" placeholder="/vecchia-pagina" required>
                        </div>
                        <div class="db-ui-field" style="margin:0">
                            <label><?php esc_html_e('Destinazione', 'db-seo-manager'); ?></label>
                            <input type="text" name="target" placeholder="/nuova-pagina">
                        </div>
                        <div class="db-ui-field" style="margin:0">
                            <label><?php esc_html_e('Codice', 'db-seo-manager'); ?></label>
                            <select name="type">
                                <option value="301">301 (permanente)</option>
                                <option value="302">302 (temporaneo)</option>
                                <option value="307">307 (temporaneo, no metodo)</option>
                                <option value="410">410 (gone, no destination)</option>
                            </select>
                        </div>
                        <div class="db-ui-field" style="margin:0">
                            <label><?php esc_html_e('Regex', 'db-seo-manager'); ?></label>
                            <input type="checkbox" name="is_regex" value="1">
                        </div>
                        <button type="submit" class="db-ui-btn db-ui-btn-primary"><?php esc_html_e('Aggiungi', 'db-seo-manager'); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <?php
        $rules = $module ? $module->get_all(200) : array();
        if (empty($rules)) :
            ?>
            <div class="db-ui-empty">
                <span class="db-ui-empty-icon" aria-hidden="true">↪</span>
                <span class="db-ui-empty-text"><?php esc_html_e('Nessun redirect configurato.', 'db-seo-manager'); ?></span>
            </div>
            <?php
        else :
            ?>
            <table class="db-ui-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Origine', 'db-seo-manager'); ?></th>
                        <th><?php esc_html_e('Destinazione', 'db-seo-manager'); ?></th>
                        <th><?php esc_html_e('Codice', 'db-seo-manager'); ?></th>
                        <th><?php esc_html_e('Tipo', 'db-seo-manager'); ?></th>
                        <th><?php esc_html_e('Hit', 'db-seo-manager'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rules as $r) : ?>
                    <tr>
                        <td><code><?php echo esc_html($r->source); ?></code></td>
                        <td><code><?php echo esc_html($r->target); ?></code></td>
                        <td><span class="db-ui-badge db-ui-badge-primary"><?php echo (int) $r->type; ?></span></td>
                        <td>
                            <?php if ((int) $r->is_regex) : ?>
                                <span class="db-ui-badge db-ui-badge-warning">regex</span>
                            <?php else : ?>
                                <span class="db-ui-badge db-ui-badge-muted">esatto</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo (int) $r->hits; ?></td>
                        <td>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" data-confirm-delete>
                                <input type="hidden" name="action" value="dbseo_redirect_action">
                                <input type="hidden" name="op"     value="delete">
                                <input type="hidden" name="id"     value="<?php echo (int) $r->id; ?>">
                                <?php wp_nonce_field('dbseo_redirect', '_dbseo_redirect_nonce'); ?>
                                <button type="submit" class="db-ui-btn db-ui-btn-sm db-ui-btn-danger"><?php esc_html_e('Elimina', 'db-seo-manager'); ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        endif;

        $this->close_wrap();
    }

    /* =====================================================================
     * 404 LOG
     * ================================================================== */

    public function render_404() {
        $this->open_wrap(__('Monitor errori 404', 'db-seo-manager'),
            __('Anonimo per design: nessun IP, nessun user-agent salvato.', 'db-seo-manager'));

        $s = DBSEO_Core::instance()->get_settings();
        ?>
        <div class="db-ui-alert db-ui-alert-warning">
            <span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
            <span><?php esc_html_e(
                'Anche se anonimo, il monitor 404 implica un trattamento tecnico (URL richiesti). Attivalo solo se serve davvero alla manutenzione del sito. Base giuridica suggerita: legittimo interesse.',
                'db-seo-manager'
            ); ?></span>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action"  value="<?php echo esc_attr(self::SAVE_ACTION); ?>">
            <input type="hidden" name="section" value="monitor_404">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD); ?>
            <div class="db-ui-card">
                <div class="db-ui-card-body">
                    <?php $this->checkbox_field('monitor_404_enabled', $s['monitor_404_enabled'], __('Abilita monitor 404', 'db-seo-manager')); ?>
                    <?php $this->number_field('monitor_404_keep_days', $s['monitor_404_keep_days'], __('Conserva i log per (giorni)', 'db-seo-manager'), 1, 365); ?>
                    <button type="submit" class="db-ui-btn db-ui-btn-primary db-ui-btn-sm"><?php esc_html_e('Salva', 'db-seo-manager'); ?></button>
                </div>
            </div>
        </form>

        <?php
        $module = DBSEO_Core::instance()->module('monitor_404');
        $rows = $module ? $module->get_top(100) : array();

        if (empty($rows)) :
            ?>
            <div class="db-ui-empty">
                <span class="db-ui-empty-icon" aria-hidden="true">📭</span>
                <span class="db-ui-empty-text"><?php esc_html_e('Nessun 404 registrato.', 'db-seo-manager'); ?></span>
            </div>
            <?php
        else :
            ?>
            <p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" data-confirm-delete>
                    <input type="hidden" name="action" value="dbseo_404_action">
                    <input type="hidden" name="op"     value="clear">
                    <?php wp_nonce_field('dbseo_404', '_dbseo_404_nonce'); ?>
                    <button type="submit" class="db-ui-btn db-ui-btn-danger db-ui-btn-sm"><?php esc_html_e('Svuota log', 'db-seo-manager'); ?></button>
                </form>
            </p>
            <table class="db-ui-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e('URL richiesto', 'db-seo-manager'); ?></th>
                        <th><?php esc_html_e('Hit', 'db-seo-manager'); ?></th>
                        <th><?php esc_html_e('Ultimo', 'db-seo-manager'); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r) : ?>
                        <tr>
                            <td><code><?php echo esc_html($r->url); ?></code></td>
                            <td><?php echo (int) $r->count; ?></td>
                            <td><?php echo esc_html($r->last_seen); ?></td>
                            <td>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" data-confirm-delete>
                                    <input type="hidden" name="action" value="dbseo_404_action">
                                    <input type="hidden" name="op"     value="delete">
                                    <input type="hidden" name="id"     value="<?php echo (int) $r->id; ?>">
                                    <?php wp_nonce_field('dbseo_404', '_dbseo_404_nonce'); ?>
                                    <button type="submit" class="db-ui-btn db-ui-btn-sm"><?php esc_html_e('Elimina', 'db-seo-manager'); ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        endif;

        $this->close_wrap();
    }

    /* =====================================================================
     * ANALYTICS & CONSENT
     * ================================================================== */

    public function render_analytics() {
        $this->open_wrap(__('Analytics & gestione consenso', 'db-seo-manager'),
            __('Tutti gli snippet vengono caricati SOLO dopo il consenso esplicito.', 'db-seo-manager'));

        $s = DBSEO_Core::instance()->get_settings();
        ?>
        <div class="db-ui-alert db-ui-alert-info">
            <span class="db-ui-alert-icon" aria-hidden="true">🔒</span>
            <span><?php
            esc_html_e(
                'Il consent gate delega a DB Cookie Manager (se attivo) o alla WP Consent API; in fallback rileva Cookiebot e Complianz. Per altri sistemi puoi usare il filter dbseo_consent_granted.',
                'db-seo-manager'
            );
            ?></span>
        </div>
        <?php

        $this->form_open('analytics');
        ?>
        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Provider analytics', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->checkbox_field('analytics_enabled', $s['analytics_enabled'], __('Abilita integrazione analytics', 'db-seo-manager')); ?>
                <?php $this->select_field('analytics_provider', $s['analytics_provider'], array(
                    'none'      => __('Nessuno', 'db-seo-manager'),
                    'ga4'       => 'Google Analytics 4',
                    'gtm'       => 'Google Tag Manager',
                    'matomo'    => 'Matomo (self-hosted) — formato: url|siteId',
                    'plausible' => 'Plausible — formato: dominio',
                    'umami'     => 'Umami — formato: scriptUrl|websiteId',
                ), __('Provider', 'db-seo-manager')); ?>
                <?php $this->text_field('analytics_id', $s['analytics_id'], __('ID / Endpoint', 'db-seo-manager'),
                    __('Vedi le note per il formato richiesto in base al provider scelto.', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('analytics_consent_mode', $s['analytics_consent_mode'],
                    __('Google Consent Mode v2 (consigliato per GA4/GTM)', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('analytics_anonymize_ip', $s['analytics_anonymize_ip'],
                    __('Anonimizza IP', 'db-seo-manager')); ?>
            </div>
        </div>

        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Meta (Facebook) Pixel', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <div class="db-ui-alert db-ui-alert-warning">
                    <span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
                    <span><?php esc_html_e(
                        'Meta Pixel comporta trasferimento extra-UE (USA) e richiede consenso per "marketing".',
                        'db-seo-manager'
                    ); ?></span>
                </div>
                <?php $this->checkbox_field('meta_pixel_enabled', $s['meta_pixel_enabled'], __('Abilita Meta Pixel', 'db-seo-manager')); ?>
                <?php $this->text_field('meta_pixel_id', $s['meta_pixel_id'], __('ID Pixel', 'db-seo-manager')); ?>
            </div>
        </div>
        <?php
        $this->form_close();
        $this->close_wrap();
    }

    /* =====================================================================
     * INDEXNOW
     * ================================================================== */

    public function render_indexnow() {
        $this->open_wrap(__('IndexNow — Notifica motori di ricerca', 'db-seo-manager'),
            __('Comunicazione server-to-server. Nessun cookie, nessun dato utente.', 'db-seo-manager'));

        $s = DBSEO_Core::instance()->get_settings();
        ?>
        <div class="db-ui-alert db-ui-alert-info">
            <span class="db-ui-alert-icon" aria-hidden="true">📡</span>
            <span><?php esc_html_e(
                'Quando attivo, IndexNow invia gli URL pubblicati o aggiornati a Bing, Yandex e altri motori partecipanti. Vengono inviati solo URL pubblici del sito (mai pagine in noindex), in batch ogni ora.',
                'db-seo-manager'
            ); ?></span>
        </div>
        <?php
        $this->form_open('indexnow');
        ?>
        <div class="db-ui-card">
            <div class="db-ui-card-body">
                <?php $this->checkbox_field('indexnow_enabled', $s['indexnow_enabled'],
                    __('Abilita IndexNow', 'db-seo-manager'),
                    __('Alla prima attivazione viene generata automaticamente una chiave univoca.', 'db-seo-manager')); ?>

                <?php if (!empty($s['indexnow_key'])) : ?>
                    <div class="db-ui-field">
                        <label><?php esc_html_e('Chiave IndexNow', 'db-seo-manager'); ?></label>
                        <code style="display:inline-block;padding:6px 10px;background:var(--db-bg-subtle);border-radius:var(--db-radius-sm)"><?php echo esc_html($s['indexnow_key']); ?></code>
                        <p class="db-ui-field-help">
                            <?php
                            printf(
                                /* translators: %s = full key URL */
                                esc_html__('File di verifica disponibile all\'indirizzo: %s', 'db-seo-manager'),
                                '<a href="' . esc_url(home_url('/' . $s['indexnow_key'] . '.txt')) . '" target="_blank" rel="noopener"><code>' . esc_html(home_url('/' . $s['indexnow_key'] . '.txt')) . '</code></a>'
                            );
                            ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        $this->form_close();
        $this->close_wrap();
    }

    /* =====================================================================
     * VERIFICHE
     * ================================================================== */

    public function render_verify() {
        $this->open_wrap(__('Verifiche motori di ricerca', 'db-seo-manager'),
            __('Solo emissione di meta tag statici. Nessuna chiamata esterna.', 'db-seo-manager'));

        $s = DBSEO_Core::instance()->get_settings();
        $this->form_open('verify');
        ?>
        <div class="db-ui-card">
            <div class="db-ui-card-body">
                <?php $this->text_field('verify_google',    $s['verify_google'],    __('Google Search Console', 'db-seo-manager'), __('Solo il valore content="..." del meta google-site-verification.', 'db-seo-manager')); ?>
                <?php $this->text_field('verify_bing',      $s['verify_bing'],      __('Bing Webmaster', 'db-seo-manager')); ?>
                <?php $this->text_field('verify_yandex',    $s['verify_yandex'],    __('Yandex Webmaster', 'db-seo-manager')); ?>
                <?php $this->text_field('verify_pinterest', $s['verify_pinterest'], __('Pinterest', 'db-seo-manager')); ?>
            </div>
        </div>
        <?php
        $this->form_close();
        $this->close_wrap();
    }

    /* =====================================================================
     * HARD PRIVACY mode
     * ================================================================== */

    public function render_hardening() {
        $this->open_wrap(__('Hard Privacy mode', 'db-seo-manager'),
            __('Disabilita in blocco le integrazioni esterne e applica hardening anti-fingerprinting.', 'db-seo-manager'));

        $s = DBSEO_Core::instance()->get_settings();
        ?>
        <div class="db-ui-alert db-ui-alert-warning">
            <span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
            <span><?php esc_html_e(
                'Modalità avanzata. Quando attiva, forza la disabilitazione di Analytics, Meta Pixel, IndexNow e applica una serie di hook che riducono fingerprinting e caricamenti di terze parti. Alcune sotto-opzioni (es. Google Fonts) possono rompere visivamente temi che dipendono da risorse esterne. Testa il sito dopo l\'attivazione.',
                'db-seo-manager'
            ); ?></span>
        </div>
        <?php
        $this->form_open('hardening');
        ?>
        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Toggle principale', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->checkbox_field('hard_privacy_enabled', $s['hard_privacy_enabled'],
                    __('Attiva Hard Privacy', 'db-seo-manager'),
                    __('Forza la soppressione di Analytics, Meta Pixel e IndexNow indipendentemente dalle loro impostazioni individuali.', 'db-seo-manager')); ?>
            </div>
        </div>

        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Hardening attivo (quando Hard Privacy è on)', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <?php $this->checkbox_field('hard_privacy_strip_emoji', $s['hard_privacy_strip_emoji'],
                    __('Rimuovi script di rilevamento emoji', 'db-seo-manager'),
                    __('WP carica wp-emoji-release.min.js che esegue test di fingerprinting del browser e fa prefetch a s.w.org. Bloccarlo è privacy-friendly.', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('hard_privacy_strip_oembed', $s['hard_privacy_strip_oembed'],
                    __('Rimuovi endpoint oEmbed', 'db-seo-manager'),
                    __('Disabilita la discovery oEmbed che può esporre dati a terzi.', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('hard_privacy_strip_dns_prefetch', $s['hard_privacy_strip_dns_prefetch'],
                    __('Rimuovi DNS prefetch verso domini esterni', 'db-seo-manager'),
                    __('WP aggiunge prefetch automatici a domini Automattic e altri.', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('hard_privacy_disable_gravatar', $s['hard_privacy_disable_gravatar'],
                    __('Sostituisci Gravatar con avatar locale', 'db-seo-manager'),
                    __('Gravatar invia hash MD5 dell\'email a Automattic. Sostituiamo con un placeholder SVG inline.', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('hard_privacy_strip_google_fonts', $s['hard_privacy_strip_google_fonts'],
                    __('Blocca Google Fonts (rischio rottura tema)', 'db-seo-manager'),
                    __('Rimuove dalla queue gli stili che caricano fonts.googleapis.com / fonts.gstatic.com. Verifica il tema dopo.', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('hard_privacy_disable_xmlrpc', $s['hard_privacy_disable_xmlrpc'],
                    __('Disabilita XML-RPC', 'db-seo-manager'),
                    __('Vettore di attacco e di pingback poco usato. Disattivare se non serve a integrazioni esterne (es. app mobile WP).', 'db-seo-manager')); ?>
                <?php $this->checkbox_field('hard_privacy_disable_rest_api_users', $s['hard_privacy_disable_rest_api_users'],
                    __('Proteggi REST /wp/v2/users dagli anonimi', 'db-seo-manager'),
                    __('Restituisce 401 a richieste non autenticate. Previene enumerazione utenti.', 'db-seo-manager')); ?>
            </div>
        </div>
        <?php
        $this->form_close();
        $this->close_wrap();
    }

    /* =====================================================================
     * STATO GDPR — dashboard sintetica
     * ================================================================== */

    public function render_status() {
        $this->open_wrap(__('Stato GDPR del sito', 'db-seo-manager'),
            __('Sintesi in tempo reale di ciò che è attivo e cosa il sito potrebbe caricare.', 'db-seo-manager'));

        $core    = DBSEO_Core::instance();
        $audit   = $core->module('audit');
        $score   = $audit ? $audit->compute_score() : array('score' => 0, 'reasons' => array());
        $results = $core->get('audit_last_results', array());
        $last_run = (int) $core->get('audit_last_run', 0);

        // Score block.
        $sc = (int) $score['score'];
        $score_class = $sc >= 80 ? 'success' : ($sc >= 50 ? 'warning' : 'danger');
        ?>

        <div class="db-ui-card">
            <div class="db-ui-card-body" style="display:grid;grid-template-columns:auto 1fr;gap:24px;align-items:center">
                <div>
                    <div class="db-ui-feedback db-ui-feedback-<?php echo esc_attr($score_class); ?>" style="margin:0;padding:24px;min-width:160px">
                        <span class="db-ui-feedback-title" style="font-size:42px;line-height:1"><?php echo esc_html((string) $sc); ?></span>
                        <span class="db-ui-feedback-text"><?php esc_html_e('Score GDPR', 'db-seo-manager'); ?></span>
                    </div>
                </div>
                <div>
                    <h3 style="margin:0 0 8px"><?php esc_html_e('Fattori che influenzano il punteggio', 'db-seo-manager'); ?></h3>
                    <?php if (empty($score['reasons'])) : ?>
                        <p class="description"><?php esc_html_e('Nessun fattore rilevante. Il punteggio è al massimo.', 'db-seo-manager'); ?></p>
                    <?php else : ?>
                        <ul style="margin:0;padding-left:18px;font-size:13px">
                            <?php foreach ($score['reasons'] as $r) : ?>
                                <li>
                                    <strong style="color:<?php echo $r['penalty'] > 0 ? 'var(--db-danger)' : 'var(--db-success)'; ?>">
                                        <?php echo $r['penalty'] > 0 ? '−' . (int) $r['penalty'] : '+' . (int) abs($r['penalty']); ?>
                                    </strong>
                                    <?php echo esc_html($r['msg']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="db-ui-card">
            <div class="db-ui-card-header" style="display:flex;justify-content:space-between;align-items:center">
                <h3 style="margin:0"><?php esc_html_e('Audit risorse di terze parti', 'db-seo-manager'); ?></h3>
                <span class="db-ui-badge db-ui-badge-muted">
                    <?php
                    if ($last_run > 0) {
                        printf(
                            /* translators: %s: human readable time difference */
                            esc_html__('Eseguito %s fa', 'db-seo-manager'),
                            esc_html(human_time_diff($last_run, time()))
                        );
                    } else {
                        esc_html_e('Mai eseguito', 'db-seo-manager');
                    }
                    ?>
                </span>
            </div>
            <div class="db-ui-card-body">
                <p class="description" style="margin-top:0">
                    <?php esc_html_e(
                        'Scansiona la homepage del sito ed elenca le risorse caricate da host esterni (script, immagini, font, iframe). Operazione server-to-server, nessun dato utente coinvolto.',
                        'db-seo-manager'
                    ); ?>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:16px">
                    <input type="hidden" name="action" value="dbseo_run_audit">
                    <?php wp_nonce_field('dbseo_audit', '_dbseo_audit_nonce'); ?>
                    <button type="submit" class="db-ui-btn db-ui-btn-primary">
                        <?php echo $last_run > 0 ? esc_html__('Riesegui audit', 'db-seo-manager') : esc_html__('Esegui audit', 'db-seo-manager'); ?>
                    </button>
                </form>

                <?php if (!empty($results['error'])) : ?>
                    <div class="db-ui-alert db-ui-alert-danger">
                        <span class="db-ui-alert-icon" aria-hidden="true">❌</span>
                        <span><?php
                        printf(
                            /* translators: %s: error message */
                            esc_html__('Errore durante l\'audit: %s', 'db-seo-manager'),
                            esc_html($results['error'])
                        );
                        ?></span>
                    </div>
                <?php elseif (!empty($results['findings'])) : ?>
                    <p>
                        <?php
                        printf(
                            esc_html(_n(
                                'Rilevato %d host esterno.',
                                'Rilevati %d host esterni.',
                                (int) $results['total_hosts'],
                                'db-seo-manager'
                            )),
                            (int) $results['total_hosts']
                        );
                        ?>
                    </p>
                    <table class="db-ui-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Host', 'db-seo-manager'); ?></th>
                                <th><?php esc_html_e('Servizio', 'db-seo-manager'); ?></th>
                                <th><?php esc_html_e('Categoria', 'db-seo-manager'); ?></th>
                                <th><?php esc_html_e('Consenso', 'db-seo-manager'); ?></th>
                                <th><?php esc_html_e('Risorse', 'db-seo-manager'); ?></th>
                                <?php if (class_exists('DBCM_Scanner')) : ?>
                                    <th>
                                        <?php esc_html_e('Cookie scritti', 'db-seo-manager'); ?>
                                        <span title="<?php esc_attr_e('Numero di cookie effettivamente rilevati dallo scanner di DB Cookie Manager per questo servizio. Disponibile solo se è stata eseguita una scansione recente del sito.', 'db-seo-manager'); ?>" style="cursor:help">ⓘ</span>
                                    </th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($results['findings'] as $f) :
                            $consent_badge = array(
                                'marketing'  => 'danger',
                                'statistics' => 'warning',
                                'depends'    => 'warning',
                                'none'       => 'success',
                                'unknown'    => 'muted',
                            );
                            $cb = $consent_badge[$f['consent']] ?? 'muted';
                            $scanned_count = isset($f['scanned_count']) ? (int) $f['scanned_count'] : 0;
                            $scanned_list  = isset($f['scanned_cookies']) && is_array($f['scanned_cookies']) ? $f['scanned_cookies'] : array();
                            ?>
                            <tr>
                                <td><code><?php echo esc_html($f['host']); ?></code></td>
                                <td><?php echo esc_html($f['name']); ?></td>
                                <td><span class="db-ui-badge db-ui-badge-muted"><?php echo esc_html($f['category']); ?></span></td>
                                <td><span class="db-ui-badge db-ui-badge-<?php echo esc_attr($cb); ?>"><?php echo esc_html($f['consent']); ?></span></td>
                                <td><?php echo (int) $f['count']; ?></td>
                                <?php if (class_exists('DBCM_Scanner')) : ?>
                                    <td>
                                        <?php if ($scanned_count > 0) :
                                            // Tooltip: lista compatta dei cookie name (max 8).
                                            $names = array_slice(array_map(function($c){ return $c['name']; }, $scanned_list), 0, 8);
                                            $tooltip_text = implode(', ', $names);
                                            if ($scanned_count > 8) {
                                                $tooltip_text .= ', …';
                                            }
                                            ?>
                                            <span class="db-ui-badge db-ui-badge-primary"
                                                  title="<?php echo esc_attr($tooltip_text); ?>">
                                                🍪 <?php echo (int) $scanned_count; ?>
                                            </span>
                                        <?php else : ?>
                                            <span class="db-ui-badge db-ui-badge-muted" title="<?php esc_attr_e('Nessun cookie rilevato dallo scanner per questo servizio (servizio non scansionato o senza cookie).', 'db-seo-manager'); ?>">—</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php elseif ($last_run > 0) : ?>
                    <div class="db-ui-empty">
                        <span class="db-ui-empty-icon" aria-hidden="true">✓</span>
                        <span class="db-ui-empty-text"><?php esc_html_e('Nessuna risorsa esterna rilevata.', 'db-seo-manager'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="db-ui-card">
            <div class="db-ui-card-header"><h3><?php esc_html_e('Stato moduli', 'db-seo-manager'); ?></h3></div>
            <div class="db-ui-card-body">
                <table class="db-ui-table">
                    <tbody>
                        <?php
                        $rows = array(
                            array(__('Hard Privacy mode',         'db-seo-manager'), $core->get('hard_privacy_enabled')),
                            array(__('Google Analytics / GTM',     'db-seo-manager'), $core->get('analytics_enabled')),
                            array(__('Consent Mode v2',            'db-seo-manager'), $core->get('analytics_enabled') && $core->get('analytics_consent_mode')),
                            array(__('Meta Pixel',                 'db-seo-manager'), $core->get('meta_pixel_enabled')),
                            array(__('IndexNow',                   'db-seo-manager'), $core->get('indexnow_enabled')),
                            array(__('Monitor 404',                'db-seo-manager'), $core->get('monitor_404_enabled')),
                            array(__('Monitor 404 logga IP',       'db-seo-manager'), $core->get('monitor_404_log_ip')),
                            array(__('Redirect senza conteggio',   'db-seo-manager'), $core->get('redirects_no_count')),
                            // Riconoscimento ecosistema DB (Step 4 — 1.2.0): segnaliamo
                            // sia il Cookie Manager DB sia la WP Consent API. Avere
                            // entrambi è la configurazione raccomandata.
                            array(__('DB Cookie Manager',          'db-seo-manager'), class_exists('DBCM_Consent_API')),
                            array(__('WP Consent API rilevata',    'db-seo-manager'), function_exists('wp_has_consent')),
                        );
                        foreach ($rows as $row) :
                            $is_warn = ($row[0] === __('Monitor 404 logga IP', 'db-seo-manager') && $row[1]);
                            ?>
                            <tr>
                                <td><?php echo esc_html($row[0]); ?></td>
                                <td style="text-align:right">
                                    <?php if ($row[1]) : ?>
                                        <span class="db-ui-badge db-ui-badge-<?php echo $is_warn ? 'danger' : 'success'; ?>"><?php esc_html_e('attivo', 'db-seo-manager'); ?></span>
                                    <?php else : ?>
                                        <span class="db-ui-badge db-ui-badge-muted"><?php esc_html_e('disattivo', 'db-seo-manager'); ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::MENU_SLUG . '-privacy')); ?>" class="db-ui-btn">
                <?php esc_html_e('Vai al registro privacy →', 'db-seo-manager'); ?>
            </a>
        </p>
        <?php
        $this->close_wrap();
    }

    /* =====================================================================
     * PRIVACY SEO — registro trattamenti
     * ================================================================== */

    public function render_privacy() {
        $this->open_wrap(__('Privacy SEO — Registro trattamenti', 'db-seo-manager'),
            __('Elenco dei trattamenti tecnici attualmente attivi sul sito, derivati dalle impostazioni del plugin.', 'db-seo-manager'));

        $module = DBSEO_Core::instance()->module('privacy');
        if (!$module) {
            echo '<div class="db-ui-alert db-ui-alert-warning">' .
                 esc_html__('Modulo privacy non disponibile.', 'db-seo-manager') . '</div>';
            $this->close_wrap();
            return;
        }
        $register = $module->get_processing_register();
        ?>
        <div class="db-ui-alert db-ui-alert-info">
            <span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
            <span><?php esc_html_e(
                'Questo è un registro tecnico interno: il plugin NON è il titolare del trattamento. Usa queste informazioni come base per la tua informativa privacy.',
                'db-seo-manager'
            ); ?></span>
        </div>

        <?php
        // Conta le voci dichiarate da plugin esterni via filter
        // dbseo_processing_register, così possiamo segnalarlo nell'intestazione.
        $external_count = 0;
        foreach ($register as $entry) {
            if (isset($entry['_source']) && $entry['_source'] === 'external') {
                $external_count++;
            }
        }
        if ($external_count > 0) :
        ?>
        <div class="db-ui-alert db-ui-alert-info" style="margin-top:8px">
            <span class="db-ui-alert-icon" aria-hidden="true">🔌</span>
            <span><?php
                printf(
                    /* translators: %d: numero di trattamenti dichiarati da plugin esterni */
                    esc_html(_n(
                        'Trattamento dichiarato da %d altro plugin DB tramite il filter dbseo_processing_register. Le voci marcate "Plugin esterno" sono di responsabilità del plugin che le dichiara.',
                        'Trattamenti dichiarati da %d altri plugin DB tramite il filter dbseo_processing_register. Le voci marcate "Plugin esterno" sono di responsabilità del plugin che le dichiara.',
                        $external_count,
                        'db-seo-manager'
                    )),
                    (int) $external_count
                );
            ?></span>
        </div>
        <?php endif; ?>

        <?php foreach ($register as $entry) :
            $is_external = isset($entry['_source']) && $entry['_source'] === 'external';
        ?>
            <div class="db-ui-card">
                <div class="db-ui-card-header" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap">
                    <h3 style="margin:0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <?php echo esc_html($entry['label']); ?>
                        <?php if ($is_external) : ?>
                            <span class="db-ui-badge db-ui-badge-primary"
                                  title="<?php esc_attr_e('Voce dichiarata da un altro plugin DB tramite il filter dbseo_processing_register.', 'db-seo-manager'); ?>"
                                  style="font-size:11px;font-weight:normal">
                                🔌 <?php esc_html_e('Plugin esterno', 'db-seo-manager'); ?>
                            </span>
                        <?php endif; ?>
                    </h3>
                    <span class="db-ui-badge <?php echo $entry['status'] === 'active' ? 'db-ui-badge-success' : 'db-ui-badge-muted'; ?>">
                        <?php echo $entry['status'] === 'active' ? esc_html__('Attivo', 'db-seo-manager') : esc_html__('Disattivo', 'db-seo-manager'); ?>
                    </span>
                </div>
                <div class="db-ui-card-body">
                    <p><strong><?php esc_html_e('Finalità:', 'db-seo-manager'); ?></strong> <?php echo esc_html($entry['purpose']); ?></p>
                    <p><strong><?php esc_html_e('Base giuridica:', 'db-seo-manager'); ?></strong> <?php echo esc_html($entry['legal_basis']); ?></p>
                    <p><strong><?php esc_html_e('Dati raccolti:', 'db-seo-manager'); ?></strong> <?php echo esc_html($entry['data_collected']); ?></p>
                    <p><strong><?php esc_html_e('Conservazione:', 'db-seo-manager'); ?></strong> <?php echo esc_html($entry['retention']); ?></p>
                    <p style="margin-bottom:0"><strong><?php esc_html_e('Trasferimenti:', 'db-seo-manager'); ?></strong> <?php echo esc_html($entry['transfers']); ?></p>
                </div>
            </div>
        <?php endforeach; ?>

        <p style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <a href="<?php echo esc_url(admin_url('options-privacy.php')); ?>" class="db-ui-btn">
                <?php esc_html_e('Vai alla Guida policy di WordPress →', 'db-seo-manager'); ?>
            </a>

            <?php
            $export_url_json = wp_nonce_url(
                admin_url('admin-post.php?action=dbseo_export_register&format=json'),
                'dbseo_export_register',
                '_dbseo_export_nonce'
            );
            $export_url_html = wp_nonce_url(
                admin_url('admin-post.php?action=dbseo_export_register&format=html'),
                'dbseo_export_register',
                '_dbseo_export_nonce'
            );
            ?>
            <a href="<?php echo esc_url($export_url_json); ?>" class="db-ui-btn">
                <?php esc_html_e('Esporta JSON', 'db-seo-manager'); ?>
            </a>
            <a href="<?php echo esc_url($export_url_html); ?>" class="db-ui-btn" target="_blank" rel="noopener">
                <?php esc_html_e('Stampa / PDF', 'db-seo-manager'); ?>
            </a>
        </p>
        <?php
        $this->close_wrap();
    }

    /* =====================================================================
     * Field renderers
     * ================================================================== */

    private function text_field($key, $value, $label, $help = '') {
        ?>
        <div class="db-ui-field">
            <label for="dbseo_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <input type="text"
                   id="dbseo_<?php echo esc_attr($key); ?>"
                   name="dbseo[<?php echo esc_attr($key); ?>]"
                   value="<?php echo esc_attr($value); ?>"
                   class="regular-text">
            <?php if ($help) : ?>
                <span class="db-ui-field-help"><?php echo esc_html($help); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    private function textarea_field($key, $value, $label, $help = '', $rows = 3) {
        ?>
        <div class="db-ui-field">
            <label for="dbseo_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <textarea id="dbseo_<?php echo esc_attr($key); ?>"
                      name="dbseo[<?php echo esc_attr($key); ?>]"
                      rows="<?php echo (int) $rows; ?>"
                      class="large-text"><?php echo esc_textarea($value); ?></textarea>
            <?php if ($help) : ?>
                <span class="db-ui-field-help"><?php echo esc_html($help); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    private function checkbox_field($key, $value, $label, $help = '') {
        ?>
        <div class="db-ui-field">
            <label>
                <input type="checkbox"
                       name="dbseo[<?php echo esc_attr($key); ?>]"
                       value="1"
                       <?php checked($value); ?>>
                <?php echo esc_html($label); ?>
            </label>
            <?php if ($help) : ?>
                <span class="db-ui-field-help" style="margin-left:24px"><?php echo esc_html($help); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    private function select_field($key, $value, $options, $label, $help = '') {
        ?>
        <div class="db-ui-field">
            <label for="dbseo_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <select id="dbseo_<?php echo esc_attr($key); ?>" name="dbseo[<?php echo esc_attr($key); ?>]">
                <?php foreach ($options as $val => $opt_label) : ?>
                    <option value="<?php echo esc_attr($val); ?>" <?php selected($value, $val); ?>>
                        <?php echo esc_html($opt_label); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if ($help) : ?>
                <span class="db-ui-field-help"><?php echo esc_html($help); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    private function number_field($key, $value, $label, $min = 0, $max = 9999) {
        ?>
        <div class="db-ui-field">
            <label for="dbseo_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
            <input type="number"
                   id="dbseo_<?php echo esc_attr($key); ?>"
                   name="dbseo[<?php echo esc_attr($key); ?>]"
                   value="<?php echo esc_attr((string) $value); ?>"
                   min="<?php echo (int) $min; ?>"
                   max="<?php echo (int) $max; ?>"
                   class="small-text">
        </div>
        <?php
    }

    private function image_field($key, $value, $label, $help = '') {
        $url = $value ? wp_get_attachment_image_url((int) $value, 'medium') : '';
        ?>
        <div class="db-ui-field dbseo-image-field" data-field="<?php echo esc_attr($key); ?>">
            <label><?php echo esc_html($label); ?></label>
            <div class="dbseo-image-preview">
                <?php if ($url) : ?>
                    <img src="<?php echo esc_url($url); ?>" alt="" style="max-width:200px;border-radius:4px;border:1px solid var(--db-border)">
                <?php endif; ?>
            </div>
            <input type="hidden"
                   name="dbseo[<?php echo esc_attr($key); ?>]"
                   value="<?php echo esc_attr($value); ?>"
                   class="dbseo-image-id">
            <button type="button" class="db-ui-btn dbseo-image-select">
                <?php echo $value ? esc_html__('Cambia immagine', 'db-seo-manager') : esc_html__('Seleziona immagine', 'db-seo-manager'); ?>
            </button>
            <?php if ($value) : ?>
                <button type="button" class="db-ui-btn dbseo-image-remove"><?php esc_html_e('Rimuovi', 'db-seo-manager'); ?></button>
            <?php endif; ?>
            <?php if ($help) : ?>
                <span class="db-ui-field-help"><?php echo esc_html($help); ?></span>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =====================================================================
     * Handlers: salvataggio
     * ================================================================== */

    public function handle_save() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        if (!isset($_POST[self::NONCE_FIELD]) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) {
            wp_die('bad nonce');
        }

        $section = isset($_POST['section']) ? sanitize_key(wp_unslash($_POST['section'])) : '';
        $input   = isset($_POST['dbseo']) && is_array($_POST['dbseo']) ? wp_unslash($_POST['dbseo']) : array();

        $sanitized = $this->sanitize_section($section, $input);
        DBSEO_Core::instance()->update($sanitized);

        // Invalida cache rilevanti.
        if ($section === 'sitemap') {
            $sm = DBSEO_Core::instance()->module('sitemap');
            if ($sm && method_exists($sm, 'invalidate_cache')) $sm->invalidate_cache();
            flush_rewrite_rules();
        }
        if ($section === 'indexnow') {
            // Le rewrite rules dipendono dalla key; flushiamo per registrare il file txt virtuale.
            flush_rewrite_rules();
        }

        $redirect = wp_get_referer() ?: admin_url('admin.php?page=' . self::MENU_SLUG);
        wp_safe_redirect(add_query_arg('dbseo_msg', 'saved', $redirect));
        exit;
    }

    /**
     * Sanitizzazione per sezione: ogni sezione tocca solo le proprie chiavi.
     * Questo evita che un form "titoli" possa sovrascrivere flag di "analytics".
     */
    private function sanitize_section($section, $input) {
        $out = array();

        $text_keys = array(
            'titles' => array('separator', 'site_name', 'title_home', 'title_post', 'title_page', 'title_archive', 'title_search', 'title_404'),
            'social' => array('twitter_card', 'twitter_site'),
            'schema' => array('schema_org_type', 'schema_org_name', 'schema_org_telephone', 'schema_org_email'),
            'analytics' => array('analytics_provider', 'analytics_id', 'meta_pixel_id'),
            'verify' => array('verify_google', 'verify_bing', 'verify_yandex', 'verify_pinterest'),
        );

        $textarea_keys = array(
            'titles' => array('desc_home', 'desc_post', 'desc_page'),
            'sitemap' => array('robots_txt_custom'),
        );

        $bool_keys = array(
            'titles' => array('noindex_search', 'noindex_404', 'noindex_author', 'noindex_date', 'noindex_attachment'),
            'social' => array('og_enabled'),
            'schema' => array('schema_enabled', 'breadcrumb_enabled'),
            'sitemap' => array('sitemap_enabled'),
            'redirects_settings' => array('redirects_enabled', 'redirects_no_count'),
            'monitor_404' => array('monitor_404_enabled'),
            'analytics'   => array('analytics_enabled', 'analytics_consent_mode', 'analytics_anonymize_ip', 'meta_pixel_enabled'),
            'indexnow'    => array('indexnow_enabled'),
            'hardening'   => array(
                'hard_privacy_enabled',
                'hard_privacy_strip_emoji',
                'hard_privacy_strip_oembed',
                'hard_privacy_strip_dns_prefetch',
                'hard_privacy_disable_gravatar',
                'hard_privacy_strip_google_fonts',
                'hard_privacy_disable_xmlrpc',
                'hard_privacy_disable_rest_api_users',
            ),
        );

        // Text.
        foreach (($text_keys[$section] ?? array()) as $k) {
            if (array_key_exists($k, $input)) {
                $out[$k] = sanitize_text_field((string) $input[$k]);
            }
        }
        // Textarea.
        foreach (($textarea_keys[$section] ?? array()) as $k) {
            if (array_key_exists($k, $input)) {
                $out[$k] = sanitize_textarea_field((string) $input[$k]);
            }
        }
        // Bool checkbox.
        foreach (($bool_keys[$section] ?? array()) as $k) {
            $out[$k] = !empty($input[$k]);
        }

        // Casi speciali.
        switch ($section) {
            case 'social':
                if (isset($input['og_default_image_id'])) $out['og_default_image_id'] = (int) $input['og_default_image_id'];
                break;

            case 'schema':
                if (isset($input['schema_org_logo_id'])) $out['schema_org_logo_id'] = (int) $input['schema_org_logo_id'];
                // breadcrumb_home_label / separator come testo libero.
                if (isset($input['breadcrumb_home_label'])) $out['breadcrumb_home_label'] = sanitize_text_field((string) $input['breadcrumb_home_label']);
                if (isset($input['breadcrumb_separator']))  $out['breadcrumb_separator']  = sanitize_text_field((string) $input['breadcrumb_separator']);
                break;

            case 'sitemap':
                $pt = isset($input['sitemap_post_types']) && is_array($input['sitemap_post_types'])
                    ? array_map('sanitize_key', $input['sitemap_post_types'])
                    : array();
                $tx = isset($input['sitemap_taxonomies']) && is_array($input['sitemap_taxonomies'])
                    ? array_map('sanitize_key', $input['sitemap_taxonomies'])
                    : array();
                $out['sitemap_post_types'] = array_values(array_unique($pt));
                $out['sitemap_taxonomies'] = array_values(array_unique($tx));

                $excl = isset($input['sitemap_exclude_csv']) ? (string) $input['sitemap_exclude_csv'] : '';
                $excl_ids = array();
                foreach (preg_split('/[,\s]+/', $excl) as $token) {
                    $n = (int) trim($token);
                    if ($n > 0) $excl_ids[] = $n;
                }
                $out['sitemap_exclude_ids'] = array_values(array_unique($excl_ids));
                break;

            case 'monitor_404':
                if (isset($input['monitor_404_keep_days'])) {
                    $out['monitor_404_keep_days'] = max(1, min(365, (int) $input['monitor_404_keep_days']));
                }
                break;
        }

        return apply_filters('dbseo_sanitize_settings', $out, $section, $input);
    }

    /* =====================================================================
     * Handlers: redirect CRUD
     * ================================================================== */

    public function handle_redirect_action() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('dbseo_redirect', '_dbseo_redirect_nonce');

        $module = DBSEO_Core::instance()->module('redirects');
        $op     = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';

        $redirect = wp_get_referer() ?: admin_url('admin.php?page=' . self::MENU_SLUG . '-redirects');

        if ($op === 'add' && $module) {
            $r = $module->add_rule(
                isset($_POST['source']) ? wp_unslash($_POST['source']) : '',
                isset($_POST['target']) ? wp_unslash($_POST['target']) : '',
                isset($_POST['type']) ? (int) $_POST['type'] : 301,
                !empty($_POST['is_regex'])
            );
            $msg = is_wp_error($r) ? 'redirect_error' : 'redirect_added';
        } elseif ($op === 'delete' && $module) {
            $module->delete_rule((int) ($_POST['id'] ?? 0));
            $msg = 'redirect_deleted';
        } else {
            $msg = 'redirect_error';
        }

        wp_safe_redirect(add_query_arg('dbseo_msg', $msg, $redirect));
        exit;
    }

    /* =====================================================================
     * Handlers: 404 actions
     * ================================================================== */

    public function handle_404_action() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('dbseo_404', '_dbseo_404_nonce');

        $module = DBSEO_Core::instance()->module('monitor_404');
        $op     = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : '';
        $redirect = wp_get_referer() ?: admin_url('admin.php?page=' . self::MENU_SLUG . '-log404');

        if ($op === 'clear' && $module) {
            $module->clear_all();
            $msg = '404_cleared';
        } elseif ($op === 'delete' && $module) {
            $module->delete_entry((int) ($_POST['id'] ?? 0));
            $msg = '404_deleted';
        } else {
            $msg = 'redirect_error';
        }

        wp_safe_redirect(add_query_arg('dbseo_msg', $msg, $redirect));
        exit;
    }
}
