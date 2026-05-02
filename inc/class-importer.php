<?php
/**
 * DBSEO_Importer — Import meta da plugin SEO concorrenti.
 *
 * Plugin supportati:
 *  - Yoast SEO (meta keys: _yoast_wpseo_*)
 *  - Rank Math (meta keys: rank_math_*)
 *  - All in One SEO Pack v4 (meta keys: _aioseo_*)
 *
 * Strategia:
 *  - Batch processing via AJAX: ogni round elabora N post (default 50) per evitare timeout.
 *  - Modalità "preserve": non sovrascrive un valore _dbseo_* già esistente
 *    (la modalità "replace" lo sovrascrive sempre, scelta esplicita dell'admin).
 *  - Solo lettura dei meta del plugin sorgente: mai cancellati. L'admin può
 *    disinstallare l'altro plugin a sua discrezione, dopo aver verificato la migrazione.
 *
 * Sicurezza:
 *  - Capability manage_options.
 *  - Nonce per ogni round AJAX.
 *  - Sanitizzazione di ogni valore prima del salvataggio.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Importer {

    const NONCE_ACTION = 'dbseo_importer';
    const BATCH_SIZE   = 50;

    /** @var DBSEO_Importer|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!is_admin()) return;

        add_action('admin_menu',                array($this, 'register_submenu'), 20);
        add_action('admin_post_dbseo_import_run', array($this, 'handle_run'));
        add_action('wp_ajax_dbseo_import_batch', array($this, 'ajax_batch'));
    }

    public function register_submenu() {
        add_submenu_page(
            'dbseo',
            __('Importa SEO', 'db-seo-manager'),
            __('Importa SEO', 'db-seo-manager'),
            'manage_options',
            'dbseo-importer',
            array($this, 'render_page')
        );
    }

    /* =====================================================================
     * Sources — definizione dei mapping
     * ================================================================== */

    /**
     * Restituisce i mapping di meta keys per ogni plugin sorgente.
     * Ogni mapping: source_meta_key => destination_meta_key
     * Per i campi booleani usiamo callable di trasformazione.
     */
    private function get_sources() {
        return array(
            'yoast' => array(
                'label'   => 'Yoast SEO',
                'detect'  => '_yoast_wpseo_title',
                'mapping' => array(
                    '_yoast_wpseo_title'                => '_dbseo_title',
                    '_yoast_wpseo_metadesc'             => '_dbseo_description',
                    '_yoast_wpseo_canonical'            => '_dbseo_canonical',
                    '_yoast_wpseo_focuskw'              => '_dbseo_focus_keyword',
                    '_yoast_wpseo_opengraph-title'      => '_dbseo_og_title',
                    '_yoast_wpseo_opengraph-description'=> '_dbseo_og_description',
                    '_yoast_wpseo_opengraph-image-id'   => '_dbseo_og_image_id',
                    '_yoast_wpseo_twitter-title'        => '_dbseo_twitter_title',
                    '_yoast_wpseo_twitter-description'  => '_dbseo_twitter_description',
                    '_yoast_wpseo_twitter-image-id'     => '_dbseo_twitter_image_id',
                ),
                'transformers' => array(
                    // Yoast salva 'noindex' come "1" se noindex, "2" se index, vuoto se default.
                    '_yoast_wpseo_meta-robots-noindex' => array(
                        'dest' => '_dbseo_robots_noindex',
                        'fn'   => array(__CLASS__, 'transform_yoast_noindex'),
                    ),
                    '_yoast_wpseo_meta-robots-nofollow' => array(
                        'dest' => '_dbseo_robots_nofollow',
                        'fn'   => array(__CLASS__, 'transform_bool_int'),
                    ),
                    '_yoast_wpseo_is_cornerstone' => array(
                        'dest' => '_dbseo_cornerstone',
                        'fn'   => array(__CLASS__, 'transform_bool_int'),
                    ),
                ),
            ),

            'rankmath' => array(
                'label'   => 'Rank Math',
                'detect'  => 'rank_math_title',
                'mapping' => array(
                    'rank_math_title'             => '_dbseo_title',
                    'rank_math_description'       => '_dbseo_description',
                    'rank_math_canonical_url'     => '_dbseo_canonical',
                    'rank_math_focus_keyword'     => '_dbseo_focus_keyword',
                    'rank_math_facebook_title'    => '_dbseo_og_title',
                    'rank_math_facebook_description' => '_dbseo_og_description',
                    'rank_math_facebook_image_id' => '_dbseo_og_image_id',
                    'rank_math_twitter_title'     => '_dbseo_twitter_title',
                    'rank_math_twitter_description' => '_dbseo_twitter_description',
                    'rank_math_twitter_image_id'  => '_dbseo_twitter_image_id',
                ),
                'transformers' => array(
                    'rank_math_robots' => array(
                        'dest' => '_dbseo_robots_noindex',
                        'fn'   => array(__CLASS__, 'transform_rankmath_robots_noindex'),
                    ),
                    'rank_math_robots_nofollow' => array(
                        'dest' => '_dbseo_robots_nofollow',
                        'fn'   => array(__CLASS__, 'transform_rankmath_robots_nofollow'),
                    ),
                    'rank_math_pillar_content' => array(
                        'dest' => '_dbseo_cornerstone',
                        'fn'   => array(__CLASS__, 'transform_bool_int'),
                    ),
                ),
            ),

            'aioseo' => array(
                'label'   => 'All in One SEO',
                'detect'  => '_aioseo_title',
                'mapping' => array(
                    '_aioseo_title'             => '_dbseo_title',
                    '_aioseo_description'       => '_dbseo_description',
                    '_aioseo_canonical_url'     => '_dbseo_canonical',
                    '_aioseo_keywords'          => '_dbseo_focus_keyword',
                    '_aioseo_og_title'          => '_dbseo_og_title',
                    '_aioseo_og_description'    => '_dbseo_og_description',
                    '_aioseo_og_image_custom_url' => '_dbseo_og_image_id',
                    '_aioseo_twitter_title'     => '_dbseo_twitter_title',
                    '_aioseo_twitter_description' => '_dbseo_twitter_description',
                ),
                'transformers' => array(
                    '_aioseo_robots_noindex' => array(
                        'dest' => '_dbseo_robots_noindex',
                        'fn'   => array(__CLASS__, 'transform_bool_int'),
                    ),
                    '_aioseo_robots_nofollow' => array(
                        'dest' => '_dbseo_robots_nofollow',
                        'fn'   => array(__CLASS__, 'transform_bool_int'),
                    ),
                ),
            ),
        );
    }

    /* =====================================================================
     * Transformers (statici per usabilità da array callable)
     * ================================================================== */

    public static function transform_yoast_noindex($val) {
        // Yoast: "1" = noindex (escluso). Altri valori → vuoto = default.
        return ($val === '1' || $val === 1) ? 1 : null;
    }

    public static function transform_bool_int($val) {
        return !empty($val) ? 1 : null;
    }

    public static function transform_rankmath_robots_noindex($val) {
        // Rank Math salva un array serializzato con direttive: ['noindex','nofollow',...]
        if (is_string($val)) $val = maybe_unserialize($val);
        return (is_array($val) && in_array('noindex', $val, true)) ? 1 : null;
    }

    public static function transform_rankmath_robots_nofollow($val) {
        if (is_string($val)) $val = maybe_unserialize($val);
        return (is_array($val) && in_array('nofollow', $val, true)) ? 1 : null;
    }

    /* =====================================================================
     * Detection: quanti post hanno almeno un meta del plugin sorgente
     * ================================================================== */

    private function count_source_posts($source_key) {
        $sources = $this->get_sources();
        if (!isset($sources[$source_key])) return 0;

        global $wpdb;
        $detect_key = $sources[$source_key]['detect'];

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            $detect_key
        ));
    }

    /* =====================================================================
     * Render della pagina
     * ================================================================== */

    public function render_page() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        ?>
        <div class="wrap dbseo-wrap">
            <div class="db-ui-page-header">
                <h1><?php esc_html_e('Importa meta SEO', 'db-seo-manager'); ?></h1>
            </div>

            <div class="db-ui-alert db-ui-alert-info">
                <span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
                <span><?php esc_html_e(
                    'L\'importazione legge i meta dal plugin sorgente senza modificarli. Disinstalla l\'altro plugin solo dopo aver verificato la migrazione.',
                    'db-seo-manager'
                ); ?></span>
            </div>

            <?php
            foreach ($this->get_sources() as $key => $cfg) :
                $count = $this->count_source_posts($key);
                ?>
                <div class="db-ui-card">
                    <div class="db-ui-card-header" style="display:flex;align-items:center;justify-content:space-between">
                        <h3 style="margin:0"><?php echo esc_html($cfg['label']); ?></h3>
                        <span class="db-ui-badge <?php echo $count > 0 ? 'db-ui-badge-primary' : 'db-ui-badge-muted'; ?>">
                            <?php echo esc_html(sprintf(
                                /* translators: %d = numero di post */
                                _n('%d post rilevato', '%d post rilevati', $count, 'db-seo-manager'),
                                $count
                            )); ?>
                        </span>
                    </div>
                    <div class="db-ui-card-body">
                        <?php if ($count === 0) : ?>
                            <p class="description" style="margin:0">
                                <?php esc_html_e('Nessun meta trovato per questo plugin.', 'db-seo-manager'); ?>
                            </p>
                        <?php else : ?>
                            <fieldset class="db-ui-field">
                                <legend><strong><?php esc_html_e('Modalità', 'db-seo-manager'); ?></strong></legend>
                                <label style="display:block;margin:4px 0">
                                    <input type="radio" name="mode_<?php echo esc_attr($key); ?>" value="preserve" checked>
                                    <?php esc_html_e('Preserva (non sovrascrive valori già impostati in DB SEO)', 'db-seo-manager'); ?>
                                </label>
                                <label style="display:block;margin:4px 0">
                                    <input type="radio" name="mode_<?php echo esc_attr($key); ?>" value="replace">
                                    <?php esc_html_e('Sovrascrivi (rimpiazza ogni valore presente in DB SEO)', 'db-seo-manager'); ?>
                                </label>
                            </fieldset>
                            <button type="button"
                                    class="db-ui-btn db-ui-btn-primary dbseo-import-start"
                                    data-source="<?php echo esc_attr($key); ?>"
                                    data-total="<?php echo esc_attr((string) $count); ?>">
                                <?php esc_html_e('Avvia importazione', 'db-seo-manager'); ?>
                            </button>
                            <div class="dbseo-import-progress" data-progress="<?php echo esc_attr($key); ?>" style="margin-top:12px;display:none">
                                <div class="db-ui-progress"><div class="db-ui-progress-fill" style="width:0%"></div></div>
                                <p class="dbseo-import-status" style="margin:6px 0 0"></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function ($) {
            $(document).on('click', '.dbseo-import-start', function () {
                var $btn    = $(this);
                var source  = $btn.data('source');
                var total   = parseInt($btn.data('total'), 10) || 0;
                var mode    = $('input[name="mode_' + source + '"]:checked').val() || 'preserve';
                var $prog   = $('[data-progress="' + source + '"]');
                var $bar    = $prog.find('.db-ui-progress-fill');
                var $status = $prog.find('.dbseo-import-status');
                var processed = 0;
                var migrated  = 0;
                var skipped   = 0;
                var page    = 1;

                $btn.prop('disabled', true);
                $prog.show();

                runBatch();

                function runBatch() {
                    $.ajax({
                        url:    ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'dbseo_import_batch',
                            nonce:  '<?php echo esc_js(wp_create_nonce(self::NONCE_ACTION)); ?>',
                            source: source,
                            mode:   mode,
                            page:   page
                        }
                    })
                    .done(function (resp) {
                        if (!resp || !resp.success) {
                            $status.text('<?php echo esc_js(__('Errore durante l\'importazione.', 'db-seo-manager')); ?>');
                            $btn.prop('disabled', false);
                            return;
                        }
                        processed += resp.data.processed;
                        migrated  += resp.data.migrated;
                        skipped   += resp.data.skipped;

                        var pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 100;
                        $bar.css('width', pct + '%');
                        $status.text(processed + ' / ' + total + ' — <?php echo esc_js(__('migrati:', 'db-seo-manager')); ?> ' + migrated + ' · <?php echo esc_js(__('saltati:', 'db-seo-manager')); ?> ' + skipped);

                        if (resp.data.done) {
                            $bar.css('width', '100%');
                            $status.append(' · <strong><?php echo esc_js(__('Completato', 'db-seo-manager')); ?></strong>');
                            $btn.text('<?php echo esc_js(__('Importazione completata', 'db-seo-manager')); ?>');
                        } else {
                            page++;
                            runBatch();
                        }
                    })
                    .fail(function () {
                        $status.text('<?php echo esc_js(__('Errore di rete.', 'db-seo-manager')); ?>');
                        $btn.prop('disabled', false);
                    });
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    /* =====================================================================
     * AJAX batch handler
     * ================================================================== */

    public function ajax_batch() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'forbidden'), 403);
        }

        $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
        $mode   = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : 'preserve';
        $page   = isset($_POST['page']) ? max(1, (int) $_POST['page']) : 1;

        $sources = $this->get_sources();
        if (!isset($sources[$source])) {
            wp_send_json_error(array('message' => 'invalid_source'));
        }
        if (!in_array($mode, array('preserve', 'replace'), true)) {
            $mode = 'preserve';
        }

        $cfg = $sources[$source];

        // Recupera i post che hanno il detect key (paginati).
        global $wpdb;
        $offset = ($page - 1) * self::BATCH_SIZE;
        $batch_size = self::BATCH_SIZE;

        $post_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = %s
             ORDER BY post_id ASC
             LIMIT %d OFFSET %d",
            $cfg['detect'], $batch_size, $offset
        ));

        $processed = 0;
        $migrated  = 0;
        $skipped   = 0;

        foreach ((array) $post_ids as $post_id) {
            $post_id = (int) $post_id;
            $processed++;

            $touched = $this->migrate_post($post_id, $cfg, $mode);
            if ($touched > 0) {
                $migrated++;
            } else {
                $skipped++;
            }
        }

        $done = count((array) $post_ids) < $batch_size;

        wp_send_json_success(array(
            'processed' => $processed,
            'migrated'  => $migrated,
            'skipped'   => $skipped,
            'done'      => $done,
        ));
    }

    /**
     * Migra i meta di un singolo post. Ritorna il numero di campi effettivamente scritti.
     */
    private function migrate_post($post_id, $cfg, $mode) {
        $touched = 0;

        // Mapping semplice 1:1.
        foreach ($cfg['mapping'] as $src_key => $dst_key) {
            $src_val = get_post_meta($post_id, $src_key, true);
            if ($src_val === '' || $src_val === null) continue;

            // Mode preserve: non sovrascrivere se valore destination esiste già.
            if ($mode === 'preserve' && metadata_exists('post', $post_id, $dst_key)) {
                continue;
            }

            // Sanitizzazione in base al tipo di campo.
            $clean = $this->sanitize_for_destination($dst_key, $src_val);
            if ($clean === null || $clean === '') continue;

            update_post_meta($post_id, $dst_key, $clean);
            $touched++;
        }

        // Trasformatori (logica custom per booleani / formati speciali).
        if (!empty($cfg['transformers'])) {
            foreach ($cfg['transformers'] as $src_key => $rule) {
                $src_val = get_post_meta($post_id, $src_key, true);
                if ($src_val === '' || $src_val === null) continue;

                $dst_key = $rule['dest'];
                if ($mode === 'preserve' && metadata_exists('post', $post_id, $dst_key)) {
                    continue;
                }

                $clean = call_user_func($rule['fn'], $src_val);
                if ($clean === null) {
                    // Per i bool: se il risultato è null, rimuoviamo eventuali valori esistenti
                    // solo in modalità replace.
                    if ($mode === 'replace') {
                        delete_post_meta($post_id, $dst_key);
                    }
                    continue;
                }

                update_post_meta($post_id, $dst_key, $clean);
                $touched++;
            }
        }

        return $touched;
    }

    private function sanitize_for_destination($dst_key, $val) {
        // Image ID fields.
        if (in_array($dst_key, array('_dbseo_og_image_id', '_dbseo_twitter_image_id'), true)) {
            // AIOSEO usa URL custom: convertiamo in ID se è già un ID, altrimenti scartiamo.
            if (is_numeric($val)) return (int) $val;
            return null;
        }
        // URL.
        if ($dst_key === '_dbseo_canonical') {
            return esc_url_raw((string) $val);
        }
        // Textarea (description).
        if (in_array($dst_key, array('_dbseo_description', '_dbseo_og_description', '_dbseo_twitter_description'), true)) {
            return sanitize_textarea_field((string) $val);
        }
        // Default: text.
        return sanitize_text_field((string) $val);
    }

    /* =====================================================================
     * Form classico (fallback non-JS)
     * ================================================================== */

    public function handle_run() {
        // Riservato a future estensioni (esecuzione sincrona da CLI/cron).
        wp_safe_redirect(admin_url('admin.php?page=dbseo-importer'));
        exit;
    }
}
