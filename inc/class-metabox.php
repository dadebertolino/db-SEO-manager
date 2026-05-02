<?php
/**
 * DBSEO_Metabox — Metabox editor per post/page/CPT.
 *
 * Funzionalità:
 *  - Tab: Generale (title, description, focus keyword), Avanzate (canonical, robots,
 *    schema type, cornerstone), Social (OG, Twitter), Analisi (live).
 *  - Anteprima SERP live aggiornata via JS.
 *  - Counter caratteri per title (max 60) e description (max 160).
 *  - Salvataggio con nonce + capability check + sanitizzazione completa.
 *  - Compatibile sia con classic editor sia con block editor (registriamo
 *    il metabox in modo standard; nel block editor appare in sidebar laterale).
 *
 * Sicurezza:
 *  - Nonce su ogni form.
 *  - Capability check 'edit_post' sul singolo post.
 *  - Sanitizzazione per tipo di campo (text, textarea, url, key, int, bool).
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Metabox {

    const NONCE_ACTION = 'dbseo_save_meta';
    const NONCE_FIELD  = 'dbseo_meta_nonce';

    /** @var DBSEO_Metabox|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!is_admin()) return;

        add_action('add_meta_boxes', array($this, 'register_metabox'));
        add_action('save_post',      array($this, 'save'), 10, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    /* =====================================================================
     * Registrazione
     * ================================================================== */

    public function register_metabox() {
        $post_types = $this->get_supported_post_types();
        foreach ($post_types as $pt) {
            add_meta_box(
                'dbseo_metabox',
                __('DB SEO', 'db-seo-manager'),
                array($this, 'render'),
                $pt,
                'normal',
                'high'
            );
        }
    }

    /**
     * Post type su cui mostrare il metabox.
     * Tutti i post type pubblici. Filtrabile.
     */
    private function get_supported_post_types() {
        $types = get_post_types(array('public' => true), 'names');
        // Escludiamo gli attachment di default (non hanno bisogno di SEO custom in genere).
        unset($types['attachment']);
        return apply_filters('dbseo_metabox_post_types', array_values($types));
    }

    public function enqueue_assets($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) return;

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, $this->get_supported_post_types(), true)) {
            return;
        }

        wp_enqueue_style(
            'db-admin-ui',
            DBSEO_PLUGIN_URL . 'assets/css/db-admin-ui.css',
            array(),
            DBSEO_VERSION
        );
        wp_enqueue_style(
            'dbseo-metabox',
            DBSEO_PLUGIN_URL . 'assets/css/metabox.css',
            array('db-admin-ui'),
            DBSEO_VERSION
        );

        wp_enqueue_media();

        wp_enqueue_script(
            'dbseo-metabox',
            DBSEO_PLUGIN_URL . 'assets/js/metabox.js',
            array('jquery'),
            DBSEO_VERSION,
            true
        );

        wp_enqueue_script(
            'dbseo-analyzer',
            DBSEO_PLUGIN_URL . 'assets/js/analyzer.js',
            array('jquery'),
            DBSEO_VERSION,
            true
        );

        wp_localize_script('dbseo-metabox', 'DBSEO', array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('dbseo_analyzer'),
            'siteName'   => get_bloginfo('name'),
            'permalink'  => isset($_GET['post']) ? get_permalink((int) $_GET['post']) : home_url('/'),
            'maxTitle'   => 60,
            'maxDesc'    => 160,
            'i18n'       => array(
                'mediaTitle'  => __('Seleziona immagine social', 'db-seo-manager'),
                'mediaButton' => __('Usa questa immagine', 'db-seo-manager'),
                'remove'      => __('Rimuovi', 'db-seo-manager'),
                'tooLong'     => __('Troppo lungo', 'db-seo-manager'),
                'tooShort'    => __('Troppo corto', 'db-seo-manager'),
                'good'        => __('Lunghezza ottimale', 'db-seo-manager'),
            ),
        ));
    }

    /* =====================================================================
     * Render
     * ================================================================== */

    public function render($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);

        $values = array(
            'title'                => get_post_meta($post->ID, '_dbseo_title', true),
            'description'          => get_post_meta($post->ID, '_dbseo_description', true),
            'focus_keyword'        => get_post_meta($post->ID, '_dbseo_focus_keyword', true),
            'canonical'            => get_post_meta($post->ID, '_dbseo_canonical', true),
            'noindex'              => (bool) get_post_meta($post->ID, '_dbseo_robots_noindex', true),
            'nofollow'             => (bool) get_post_meta($post->ID, '_dbseo_robots_nofollow', true),
            'cornerstone'          => (bool) get_post_meta($post->ID, '_dbseo_cornerstone', true),
            'schema_type'          => get_post_meta($post->ID, '_dbseo_schema_type', true),
            'og_title'             => get_post_meta($post->ID, '_dbseo_og_title', true),
            'og_description'       => get_post_meta($post->ID, '_dbseo_og_description', true),
            'og_image_id'          => (int) get_post_meta($post->ID, '_dbseo_og_image_id', true),
            'twitter_title'        => get_post_meta($post->ID, '_dbseo_twitter_title', true),
            'twitter_description'  => get_post_meta($post->ID, '_dbseo_twitter_description', true),
            'twitter_image_id'     => (int) get_post_meta($post->ID, '_dbseo_twitter_image_id', true),
            'score'                => (int) get_post_meta($post->ID, '_dbseo_score', true),
        );

        $tabs = array(
            'general'  => __('Generale',  'db-seo-manager'),
            'advanced' => __('Avanzate',  'db-seo-manager'),
            'social'   => __('Social',    'db-seo-manager'),
            'analysis' => __('Analisi',   'db-seo-manager'),
        );
        ?>

        <div class="dbseo-metabox" id="dbseo-metabox" data-post-id="<?php echo esc_attr($post->ID); ?>">

            <ul class="dbseo-tabs" role="tablist">
                <?php foreach ($tabs as $key => $label) : ?>
                    <li>
                        <button type="button"
                                class="dbseo-tab<?php echo $key === 'general' ? ' is-active' : ''; ?>"
                                data-tab="<?php echo esc_attr($key); ?>"
                                role="tab"
                                aria-selected="<?php echo $key === 'general' ? 'true' : 'false'; ?>">
                            <?php echo esc_html($label); ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>

            <!-- Tab: Generale -->
            <div class="dbseo-tab-panel is-active" data-panel="general" role="tabpanel">
                <div class="dbseo-serp-preview" aria-live="polite">
                    <div class="dbseo-serp-url" id="dbseo-serp-url"></div>
                    <div class="dbseo-serp-title" id="dbseo-serp-title"></div>
                    <div class="dbseo-serp-desc" id="dbseo-serp-desc"></div>
                </div>

                <p>
                    <label for="dbseo_title"><strong><?php esc_html_e('Title SEO', 'db-seo-manager'); ?></strong></label>
                    <input type="text"
                           name="dbseo[title]"
                           id="dbseo_title"
                           value="<?php echo esc_attr($values['title']); ?>"
                           class="widefat dbseo-input"
                           data-counter="dbseo-counter-title"
                           maxlength="200">
                    <span class="dbseo-counter" id="dbseo-counter-title"></span>
                    <small class="description">
                        <?php esc_html_e('Lascia vuoto per usare il titolo di default. Variabili: %title%, %sitename%, %sep%, %tagline%.', 'db-seo-manager'); ?>
                    </small>
                </p>

                <p>
                    <label for="dbseo_description"><strong><?php esc_html_e('Meta description', 'db-seo-manager'); ?></strong></label>
                    <textarea name="dbseo[description]"
                              id="dbseo_description"
                              class="widefat dbseo-input"
                              rows="3"
                              data-counter="dbseo-counter-desc"
                              maxlength="320"><?php echo esc_textarea($values['description']); ?></textarea>
                    <span class="dbseo-counter" id="dbseo-counter-desc"></span>
                </p>

                <p>
                    <label for="dbseo_focus_keyword"><strong><?php esc_html_e('Focus keyword', 'db-seo-manager'); ?></strong></label>
                    <input type="text"
                           name="dbseo[focus_keyword]"
                           id="dbseo_focus_keyword"
                           value="<?php echo esc_attr($values['focus_keyword']); ?>"
                           class="widefat">
                    <small class="description">
                        <?php esc_html_e('Parola chiave principale per cui vuoi posizionare questo contenuto.', 'db-seo-manager'); ?>
                    </small>
                </p>
            </div>

            <!-- Tab: Avanzate -->
            <div class="dbseo-tab-panel" data-panel="advanced" role="tabpanel">
                <p>
                    <label for="dbseo_canonical"><strong><?php esc_html_e('URL canonico', 'db-seo-manager'); ?></strong></label>
                    <input type="url"
                           name="dbseo[canonical]"
                           id="dbseo_canonical"
                           value="<?php echo esc_attr($values['canonical']); ?>"
                           class="widefat"
                           placeholder="<?php echo esc_attr(get_permalink($post->ID)); ?>">
                    <small class="description">
                        <?php esc_html_e('Lascia vuoto per usare il permalink standard. Compila solo se questo contenuto è duplicato di un altro URL.', 'db-seo-manager'); ?>
                    </small>
                </p>

                <fieldset>
                    <legend><strong><?php esc_html_e('Direttive robots', 'db-seo-manager'); ?></strong></legend>
                    <label>
                        <input type="checkbox" name="dbseo[noindex]" value="1" <?php checked($values['noindex']); ?>>
                        <?php esc_html_e('Noindex (escludi dai motori di ricerca)', 'db-seo-manager'); ?>
                    </label><br>
                    <label>
                        <input type="checkbox" name="dbseo[nofollow]" value="1" <?php checked($values['nofollow']); ?>>
                        <?php esc_html_e('Nofollow (non seguire i link in questa pagina)', 'db-seo-manager'); ?>
                    </label>
                </fieldset>

                <p>
                    <label for="dbseo_schema_type"><strong><?php esc_html_e('Tipo Schema.org', 'db-seo-manager'); ?></strong></label>
                    <select name="dbseo[schema_type]" id="dbseo_schema_type">
                        <option value=""             <?php selected($values['schema_type'], '');             ?>><?php esc_html_e('Auto (default)', 'db-seo-manager'); ?></option>
                        <option value="Article"      <?php selected($values['schema_type'], 'Article');      ?>>Article</option>
                        <option value="NewsArticle"  <?php selected($values['schema_type'], 'NewsArticle');  ?>>NewsArticle</option>
                        <option value="BlogPosting"  <?php selected($values['schema_type'], 'BlogPosting');  ?>>BlogPosting</option>
                        <option value="FAQPage"      <?php selected($values['schema_type'], 'FAQPage');      ?>>FAQPage</option>
                        <option value="HowTo"        <?php selected($values['schema_type'], 'HowTo');        ?>>HowTo</option>
                        <option value="Product"      <?php selected($values['schema_type'], 'Product');      ?>>Product</option>
                        <option value="Event"        <?php selected($values['schema_type'], 'Event');        ?>>Event</option>
                        <option value="LocalBusiness"<?php selected($values['schema_type'], 'LocalBusiness');?>>LocalBusiness</option>
                        <option value="None"         <?php selected($values['schema_type'], 'None');         ?>><?php esc_html_e('Nessuno', 'db-seo-manager'); ?></option>
                    </select>
                    <small class="description">
                        <?php esc_html_e('FAQPage, HowTo, Product, Event richiedono dati strutturati custom. Aggiungili via filter dbseo_schema_data.', 'db-seo-manager'); ?>
                    </small>
                </p>

                <p>
                    <label>
                        <input type="checkbox" name="dbseo[cornerstone]" value="1" <?php checked($values['cornerstone']); ?>>
                        <strong><?php esc_html_e('Cornerstone content', 'db-seo-manager'); ?></strong>
                    </label>
                    <small class="description">
                        <?php esc_html_e('Marca questo contenuto come centrale per la tua strategia SEO.', 'db-seo-manager'); ?>
                    </small>
                </p>
            </div>

            <!-- Tab: Social -->
            <div class="dbseo-tab-panel" data-panel="social" role="tabpanel">

                <h4><?php esc_html_e('OpenGraph (Facebook, LinkedIn)', 'db-seo-manager'); ?></h4>
                <p>
                    <label for="dbseo_og_title"><?php esc_html_e('Titolo OG', 'db-seo-manager'); ?></label>
                    <input type="text" name="dbseo[og_title]" id="dbseo_og_title"
                           value="<?php echo esc_attr($values['og_title']); ?>" class="widefat">
                </p>
                <p>
                    <label for="dbseo_og_description"><?php esc_html_e('Descrizione OG', 'db-seo-manager'); ?></label>
                    <textarea name="dbseo[og_description]" id="dbseo_og_description" class="widefat" rows="2"><?php echo esc_textarea($values['og_description']); ?></textarea>
                </p>
                <?php $this->render_image_picker('og_image_id', $values['og_image_id'], __('Immagine OG', 'db-seo-manager')); ?>

                <hr>

                <h4><?php esc_html_e('Twitter / X', 'db-seo-manager'); ?></h4>
                <p>
                    <label for="dbseo_twitter_title"><?php esc_html_e('Titolo Twitter', 'db-seo-manager'); ?></label>
                    <input type="text" name="dbseo[twitter_title]" id="dbseo_twitter_title"
                           value="<?php echo esc_attr($values['twitter_title']); ?>" class="widefat">
                </p>
                <p>
                    <label for="dbseo_twitter_description"><?php esc_html_e('Descrizione Twitter', 'db-seo-manager'); ?></label>
                    <textarea name="dbseo[twitter_description]" id="dbseo_twitter_description" class="widefat" rows="2"><?php echo esc_textarea($values['twitter_description']); ?></textarea>
                </p>
                <?php $this->render_image_picker('twitter_image_id', $values['twitter_image_id'], __('Immagine Twitter', 'db-seo-manager')); ?>
            </div>

            <!-- Tab: Analisi -->
            <div class="dbseo-tab-panel" data-panel="analysis" role="tabpanel">
                <button type="button" class="button button-primary" id="dbseo-run-analysis">
                    <?php esc_html_e('Esegui analisi', 'db-seo-manager'); ?>
                </button>
                <div id="dbseo-analysis-results" class="dbseo-analysis-results" aria-live="polite"></div>
                <p class="description">
                    <?php esc_html_e('L\'analisi viene eseguita interamente nel browser: nessun dato lascia il sito.', 'db-seo-manager'); ?>
                </p>
            </div>

        </div>
        <?php
    }

    private function render_image_picker($field, $value, $label) {
        $url = $value ? wp_get_attachment_image_url((int) $value, 'medium') : '';
        ?>
        <div class="dbseo-image-picker" data-field="<?php echo esc_attr($field); ?>">
            <label><strong><?php echo esc_html($label); ?></strong></label>
            <div class="dbseo-image-preview">
                <?php if ($url) : ?>
                    <img src="<?php echo esc_url($url); ?>" alt="">
                <?php endif; ?>
            </div>
            <input type="hidden" name="dbseo[<?php echo esc_attr($field); ?>]"
                   value="<?php echo esc_attr($value); ?>"
                   class="dbseo-image-id">
            <button type="button" class="button dbseo-image-select">
                <?php echo $value ? esc_html__('Cambia immagine', 'db-seo-manager') : esc_html__('Seleziona immagine', 'db-seo-manager'); ?>
            </button>
            <?php if ($value) : ?>
                <button type="button" class="button-link dbseo-image-remove"><?php esc_html_e('Rimuovi', 'db-seo-manager'); ?></button>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =====================================================================
     * Save handler
     * ================================================================== */

    public function save($post_id, $post) {
        // 1. Nonce.
        if (!isset($_POST[self::NONCE_FIELD])) return;
        if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) return;

        // 2. Autosave / revisioni.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;

        // 3. Capability.
        if (!current_user_can('edit_post', $post_id)) return;

        // 4. Post type supportato.
        if (!in_array($post->post_type, $this->get_supported_post_types(), true)) return;

        // 5. Sanitizzazione e salvataggio.
        $input = isset($_POST['dbseo']) && is_array($_POST['dbseo']) ? wp_unslash($_POST['dbseo']) : array();

        $map = array(
            'title'               => array('text',     '_dbseo_title'),
            'description'         => array('textarea', '_dbseo_description'),
            'focus_keyword'       => array('text',     '_dbseo_focus_keyword'),
            'canonical'           => array('url',      '_dbseo_canonical'),
            'noindex'             => array('bool',     '_dbseo_robots_noindex'),
            'nofollow'            => array('bool',     '_dbseo_robots_nofollow'),
            'cornerstone'         => array('bool',     '_dbseo_cornerstone'),
            'schema_type'         => array('key',      '_dbseo_schema_type'),
            'og_title'            => array('text',     '_dbseo_og_title'),
            'og_description'      => array('textarea', '_dbseo_og_description'),
            'og_image_id'         => array('int',      '_dbseo_og_image_id'),
            'twitter_title'       => array('text',     '_dbseo_twitter_title'),
            'twitter_description' => array('textarea', '_dbseo_twitter_description'),
            'twitter_image_id'    => array('int',      '_dbseo_twitter_image_id'),
        );

        foreach ($map as $field => $cfg) {
            list($type, $meta_key) = $cfg;
            $raw = isset($input[$field]) ? $input[$field] : null;

            switch ($type) {
                case 'text':
                    $val = sanitize_text_field((string) $raw);
                    break;
                case 'textarea':
                    $val = sanitize_textarea_field((string) $raw);
                    break;
                case 'url':
                    $val = $raw === null || $raw === '' ? '' : esc_url_raw((string) $raw);
                    break;
                case 'key':
                    $val = sanitize_key((string) $raw);
                    break;
                case 'int':
                    $val = (int) $raw;
                    break;
                case 'bool':
                    $val = !empty($raw) ? 1 : 0;
                    break;
                default:
                    continue 2;
            }

            // Per i bool, salviamo solo se truthy (mantiene il DB pulito).
            if ($type === 'bool') {
                if ($val) {
                    update_post_meta($post_id, $meta_key, 1);
                } else {
                    delete_post_meta($post_id, $meta_key);
                }
            } elseif ($val === '' || $val === 0) {
                delete_post_meta($post_id, $meta_key);
            } else {
                update_post_meta($post_id, $meta_key, $val);
            }
        }

        do_action('dbseo_meta_saved', $post_id, $input);
    }
}
