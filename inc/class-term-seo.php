<?php
/**
 * DBSEO_Term_SEO — Campi SEO per termini di tassonomia.
 *
 * Il frontend (class-frontend.php) e la sitemap leggono già i term meta
 * _dbseo_title / _dbseo_description / _dbseo_canonical / _dbseo_robots_noindex:
 * questo modulo fornisce la UI per compilarli nella schermata di modifica
 * di categorie, tag e tassonomie custom incluse in sitemap.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Term_SEO {

    const NONCE_ACTION = 'dbseo_term_seo_save';
    const NONCE_FIELD  = 'dbseo_term_seo_nonce';

    /** @var DBSEO_Term_SEO|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'register_hooks'));
    }

    /**
     * Tassonomie supportate: category, post_tag + quelle incluse in sitemap.
     *
     * @return string[]
     */
    private function taxonomies() {
        $taxes = array_merge(
            array('category', 'post_tag'),
            (array) DBSEO_Core::instance()->get('sitemap_taxonomies', array())
        );
        $taxes = array_filter(array_unique($taxes), 'taxonomy_exists');
        return apply_filters('dbseo_term_seo_taxonomies', array_values($taxes));
    }

    public function register_hooks() {
        foreach ($this->taxonomies() as $tax) {
            add_action("{$tax}_edit_form_fields", array($this, 'render_fields'), 20);
            add_action("edited_{$tax}",           array($this, 'save'), 10, 1);
        }
    }

    /**
     * Campi nella schermata di modifica termine.
     *
     * @param WP_Term $term
     */
    public function render_fields($term) {
        $title     = (string) get_term_meta($term->term_id, '_dbseo_title', true);
        $desc      = (string) get_term_meta($term->term_id, '_dbseo_description', true);
        $canonical = (string) get_term_meta($term->term_id, '_dbseo_canonical', true);
        $noindex   = (bool) get_term_meta($term->term_id, '_dbseo_robots_noindex', true);

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <tr class="form-field">
            <th colspan="2" style="padding-bottom:0">
                <h2 style="margin:0"><?php esc_html_e('SEO — DB SEO Manager', 'db-seo-manager'); ?></h2>
            </th>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="dbseo_term_title"><?php esc_html_e('Titolo SEO', 'db-seo-manager'); ?></label></th>
            <td>
                <input type="text" id="dbseo_term_title" name="dbseo_term[title]" value="<?php echo esc_attr($title); ?>" maxlength="120">
                <p class="description"><?php esc_html_e('Vuoto = template titoli archivio.', 'db-seo-manager'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="dbseo_term_desc"><?php esc_html_e('Meta description', 'db-seo-manager'); ?></label></th>
            <td>
                <textarea id="dbseo_term_desc" name="dbseo_term[description]" rows="3" maxlength="320"><?php echo esc_textarea($desc); ?></textarea>
                <p class="description"><?php esc_html_e('Vuota = descrizione del termine.', 'db-seo-manager'); ?></p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="dbseo_term_canonical"><?php esc_html_e('URL canonica', 'db-seo-manager'); ?></label></th>
            <td><input type="url" id="dbseo_term_canonical" name="dbseo_term[canonical]" value="<?php echo esc_attr($canonical); ?>"></td>
        </tr>
        <tr class="form-field">
            <th scope="row"><?php esc_html_e('Indicizzazione', 'db-seo-manager'); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="dbseo_term[noindex]" value="1" <?php checked($noindex); ?>>
                    <?php esc_html_e('Noindex (escludi da motori di ricerca e sitemap)', 'db-seo-manager'); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    /**
     * Salvataggio: nonce + capability + sanitizzazione.
     *
     * @param int $term_id
     */
    public function save($term_id) {
        if (!isset($_POST[self::NONCE_FIELD])) return;
        if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) return;
        if (!current_user_can('manage_categories')) return;

        $input = isset($_POST['dbseo_term']) && is_array($_POST['dbseo_term']) ? wp_unslash($_POST['dbseo_term']) : array();

        $fields = array(
            '_dbseo_title'       => sanitize_text_field((string) ($input['title'] ?? '')),
            '_dbseo_description' => sanitize_textarea_field((string) ($input['description'] ?? '')),
            '_dbseo_canonical'   => esc_url_raw((string) ($input['canonical'] ?? '')),
        );
        foreach ($fields as $key => $val) {
            if ($val === '') {
                delete_term_meta($term_id, $key);
            } else {
                update_term_meta($term_id, $key, $val);
            }
        }

        if (!empty($input['noindex'])) {
            update_term_meta($term_id, '_dbseo_robots_noindex', 1);
        } else {
            delete_term_meta($term_id, '_dbseo_robots_noindex');
        }

        // La sitemap delle tassonomie va invalidata.
        $sm = DBSEO_Core::instance()->module('sitemap');
        if ($sm && method_exists($sm, 'invalidate_cache')) $sm->invalidate_cache();
    }
}
