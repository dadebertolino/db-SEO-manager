<?php
/**
 * DBSEO_Duplicates — Report titoli e meta description duplicati.
 *
 * Individua con query aggregate (GROUP BY ... HAVING COUNT > 1):
 *  - Titoli SEO (_dbseo_title) identici su più post pubblicati.
 *  - Meta description (_dbseo_description) identiche su più post pubblicati.
 *  - Titoli di post (post_title) identici tra contenuti pubblicati senza
 *    titolo SEO personalizzato (il caso più comune di duplicazione reale).
 *
 * Nessun dato personale coinvolto, tutto on-demand nella pagina admin.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Duplicates {

    /** @var DBSEO_Duplicates|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'register_submenu'), 20);
    }

    public function register_submenu() {
        add_submenu_page(
            'dbseo',
            __('Duplicati', 'db-seo-manager'),
            __('Duplicati', 'db-seo-manager'),
            'manage_options',
            'dbseo-duplicates',
            array($this, 'render_page')
        );
    }

    /* =====================================================================
     * Query
     * ================================================================== */

    /**
     * Duplicati su un meta key SEO tra post pubblicati.
     *
     * @param string $meta_key
     * @return array value => WP_Post-like rows (id, title)
     */
    private function find_meta_duplicates($meta_key) {
        global $wpdb;

        $dupes = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value AS val, COUNT(*) AS n
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value <> ''
               AND p.post_status = 'publish'
             GROUP BY pm.meta_value
             HAVING COUNT(*) > 1
             ORDER BY n DESC
             LIMIT 50",
            $meta_key
        ));
        if (empty($dupes)) return array();

        $out = array();
        foreach ($dupes as $d) {
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT p.ID, p.post_title
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_status = 'publish'
                 LIMIT 20",
                $meta_key, $d->val
            ));
            $out[$d->val] = $posts;
        }
        return $out;
    }

    /**
     * Titoli post identici tra pubblicati (senza titolo SEO custom).
     *
     * @return array
     */
    private function find_post_title_duplicates() {
        global $wpdb;

        $dupes = $wpdb->get_results(
            "SELECT p.post_title AS val, COUNT(*) AS n
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_dbseo_title'
             WHERE p.post_status = 'publish'
               AND p.post_type IN ('post', 'page')
               AND p.post_title <> ''
               AND (pm.meta_id IS NULL OR pm.meta_value = '')
             GROUP BY p.post_title
             HAVING COUNT(*) > 1
             ORDER BY n DESC
             LIMIT 50"
        );
        if (empty($dupes)) return array();

        $out = array();
        foreach ($dupes as $d) {
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('post', 'page') AND post_title = %s
                 LIMIT 20",
                $d->val
            ));
            $out[$d->val] = $posts;
        }
        return $out;
    }

    /* =====================================================================
     * Render
     * ================================================================== */

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permessi insufficienti.', 'db-seo-manager'));
        }

        echo '<div class="wrap dbseo-admin db-ui">';
        echo '<h1>' . esc_html__('Contenuti duplicati', 'db-seo-manager') . '</h1>';
        echo '<p class="description">' . esc_html__('Titoli e description identici confondono i motori di ricerca (keyword cannibalization). Differenziali o imposta una canonica.', 'db-seo-manager') . '</p>';

        $sections = array(
            __('Titoli SEO duplicati', 'db-seo-manager')       => $this->find_meta_duplicates('_dbseo_title'),
            __('Meta description duplicate', 'db-seo-manager') => $this->find_meta_duplicates('_dbseo_description'),
            __('Titoli di post duplicati (senza titolo SEO)', 'db-seo-manager') => $this->find_post_title_duplicates(),
        );

        foreach ($sections as $label => $groups) {
            echo '<div class="db-ui-card"><div class="db-ui-card-header"><h3>' . esc_html($label) . '</h3></div><div class="db-ui-card-body">';
            if (empty($groups)) {
                echo '<p>✅ ' . esc_html__('Nessun duplicato trovato.', 'db-seo-manager') . '</p>';
            } else {
                echo '<table class="db-ui-table"><thead><tr><th>' . esc_html__('Valore', 'db-seo-manager') . '</th><th>' . esc_html__('Contenuti coinvolti', 'db-seo-manager') . '</th></tr></thead><tbody>';
                foreach ($groups as $value => $posts) {
                    echo '<tr><td><code>' . esc_html(mb_substr((string) $value, 0, 120)) . '</code></td><td>';
                    $links = array();
                    foreach ($posts as $p) {
                        $links[] = '<a href="' . esc_url(get_edit_post_link($p->ID)) . '">' . esc_html($p->post_title ?: '#' . $p->ID) . '</a>';
                    }
                    echo wp_kses_post(implode(' · ', $links));
                    echo '</td></tr>';
                }
                echo '</tbody></table>';
            }
            echo '</div></div>';
        }

        echo '</div>';
    }
}
