<?php
/**
 * DBSEO_Hreflang — Alternative linguistiche per post/pagine.
 *
 * Fonte dati manuale: meta _dbseo_hreflang, una riga per alternativa nel
 * formato "codice-lingua|URL" (es. "en|https://example.com/en/page/").
 * L'output avviene tramite il filtro dbseo_hreflang_alternatives già
 * presente in class-frontend.php — plugin multilingua (Polylang, WPML)
 * possono agganciarsi allo stesso filtro.
 *
 * Best practice implementata: se esistono alternative, viene aggiunto
 * automaticamente il self-reference con la lingua del sito e x-default
 * (se non specificato manualmente).
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Hreflang {

    const META_KEY     = '_dbseo_hreflang';
    const NONCE_ACTION = 'dbseo_hreflang_save';
    const NONCE_FIELD  = 'dbseo_hreflang_nonce';

    /** @var DBSEO_Hreflang|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('add_meta_boxes', array($this, 'register_metabox'));
        add_action('save_post',      array($this, 'save'), 10, 2);
        add_filter('dbseo_hreflang_alternatives', array($this, 'inject_alternatives'), 10, 2);
    }

    /* =====================================================================
     * Metabox
     * ================================================================== */

    public function register_metabox() {
        $types = apply_filters('dbseo_hreflang_post_types', array('post', 'page'));
        foreach ($types as $type) {
            add_meta_box(
                'dbseo-hreflang',
                __('Hreflang — DB SEO Manager', 'db-seo-manager'),
                array($this, 'render_metabox'),
                $type,
                'normal',
                'low'
            );
        }
    }

    public function render_metabox($post) {
        $value = (string) get_post_meta($post->ID, self::META_KEY, true);
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        ?>
        <p class="description">
            <?php esc_html_e('Una alternativa per riga, formato: codice-lingua|URL. Esempi: en|https://example.com/en/page/ — x-default|https://example.com/. Il self-reference viene aggiunto automaticamente.', 'db-seo-manager'); ?>
        </p>
        <textarea name="dbseo_hreflang" rows="4" style="width:100%;font-family:monospace"
                  placeholder="en|https://example.com/en/pagina/"><?php echo esc_textarea($value); ?></textarea>
        <?php
    }

    public function save($post_id, $post) {
        if (!isset($_POST[self::NONCE_FIELD])) return;
        if (!wp_verify_nonce(sanitize_key(wp_unslash($_POST[self::NONCE_FIELD])), self::NONCE_ACTION)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $raw = isset($_POST['dbseo_hreflang']) ? sanitize_textarea_field(wp_unslash($_POST['dbseo_hreflang'])) : '';

        // Validazione riga per riga: conserva solo le righe ben formate.
        $clean = array();
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) !== 2) continue;
            list($lang, $url) = $parts;
            if (!preg_match('/^(x-default|[a-z]{2,3}(-[a-zA-Z]{2,4})?)$/', $lang)) continue;
            $url = esc_url_raw($url);
            if ($url === '') continue;
            $clean[] = $lang . '|' . $url;
        }

        if (empty($clean)) {
            delete_post_meta($post_id, self::META_KEY);
        } else {
            update_post_meta($post_id, self::META_KEY, implode("\n", $clean));
        }
    }

    /* =====================================================================
     * Output (via filtro esistente in class-frontend.php)
     * ================================================================== */

    /**
     * @param array $alternatives lang => url
     * @param array $meta
     * @return array
     */
    public function inject_alternatives($alternatives, $meta) {
        if (!is_singular()) return $alternatives;

        $post_id = get_queried_object_id();
        $raw = (string) get_post_meta($post_id, self::META_KEY, true);
        if ($raw === '') return $alternatives;

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $parts = array_map('trim', explode('|', $line, 2));
            if (count($parts) !== 2) continue;
            $alternatives[$parts[0]] = $parts[1];
        }

        if (!empty($alternatives)) {
            // Self-reference con la lingua del sito, se non già dichiarato.
            $site_lang = strtolower(str_replace('_', '-', get_locale()));
            if (!isset($alternatives[$site_lang])) {
                $alternatives[$site_lang] = get_permalink($post_id);
            }
        }

        return $alternatives;
    }
}
