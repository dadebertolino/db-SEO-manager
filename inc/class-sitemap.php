<?php
/**
 * DBSEO_Sitemap — Sitemap XML estesa.
 *
 * Sostituisce la sitemap core di WordPress con una versione che include:
 *  - Indice principale: /sitemap.xml
 *  - Sub-sitemap per post type: /sitemap-{type}-{paged}.xml
 *  - Sub-sitemap per tassonomia: /sitemap-tax-{taxonomy}.xml
 *  - Immagini in linea (estensione image/1.1) per ogni URL.
 *  - Esclusione automatica: post con _dbseo_robots_noindex=true,
 *    post nella lista sitemap_exclude_ids, post type non pubblici.
 *
 * Performance:
 *  - Cache transient per ogni sub-sitemap (12h, invalidate on save_post/edited_term).
 *  - Paginazione a 1000 URL per file (limite raccomandato da Google).
 *
 * Privacy:
 *  - Nessun dato utente esposto. Solo URL pubblici.
 *  - L'autore è escluso (no /author/ archives in sitemap).
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Sitemap {

    const URLS_PER_FILE  = 1000;
    const CACHE_GROUP    = 'dbseo_sitemap_';
    const CACHE_DURATION = 12 * HOUR_IN_SECONDS;

    /** @var DBSEO_Sitemap|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!DBSEO_Core::instance()->get('sitemap_enabled')) return;

        // Rewrite rules per gli URL della sitemap.
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('parse_request', array($this, 'serve_sitemap'));

        // Invalidazione cache quando i contenuti cambiano.
        add_action('save_post',     array($this, 'invalidate_for_post'));
        add_action('deleted_post',  array($this, 'invalidate_for_post'));
        add_action('edited_term',   array($this, 'invalidate_cache'));
        add_action('created_term',  array($this, 'invalidate_cache'));
        add_action('delete_term',   array($this, 'invalidate_cache'));
    }

    /* =====================================================================
     * Routing
     * ================================================================== */

    public function add_rewrite_rules() {
        add_rewrite_rule('^sitemap\.xml$',                                  'index.php?dbseo_sitemap=index',                                                              'top');
        add_rewrite_rule('^sitemap-([a-z0-9_-]+)-([0-9]+)\.xml$',           'index.php?dbseo_sitemap=type&dbseo_st_type=$matches[1]&dbseo_st_paged=$matches[2]',          'top');
        add_rewrite_rule('^sitemap-tax-([a-z0-9_-]+)\.xml$',                'index.php?dbseo_sitemap=tax&dbseo_st_tax=$matches[1]',                                       'top');
    }

    public function add_query_vars($vars) {
        $vars[] = 'dbseo_sitemap';
        $vars[] = 'dbseo_st_type';
        $vars[] = 'dbseo_st_paged';
        $vars[] = 'dbseo_st_tax';
        return $vars;
    }

    public function serve_sitemap($wp) {
        if (empty($wp->query_vars['dbseo_sitemap'])) return;

        // Headers comuni.
        nocache_headers();
        header('Content-Type: application/xml; charset=' . get_bloginfo('charset'));
        header('X-Robots-Tag: noindex, follow');

        $kind = $wp->query_vars['dbseo_sitemap'];

        switch ($kind) {
            case 'index':
                echo $this->render_index();
                break;
            case 'type':
                $type  = sanitize_key($wp->query_vars['dbseo_st_type']);
                $paged = max(1, (int) $wp->query_vars['dbseo_st_paged']);
                echo $this->render_type($type, $paged);
                break;
            case 'tax':
                $tax = sanitize_key($wp->query_vars['dbseo_st_tax']);
                echo $this->render_taxonomy($tax);
                break;
            default:
                status_header(404);
                exit;
        }
        exit;
    }

    /* =====================================================================
     * Rendering: indice
     * ================================================================== */

    private function render_index() {
        $core = DBSEO_Core::instance();
        $items = array();

        // Sub-sitemap per post type.
        $post_types = (array) $core->get('sitemap_post_types', array('post', 'page'));
        $post_types = array_filter($post_types, function($t) {
            return post_type_exists($t) && get_post_type_object($t)->public;
        });

        foreach ($post_types as $type) {
            $count = $this->count_posts($type);
            if ($count < 1) continue;

            $pages = max(1, (int) ceil($count / self::URLS_PER_FILE));
            for ($i = 1; $i <= $pages; $i++) {
                $items[] = array(
                    'loc'     => home_url("/sitemap-{$type}-{$i}.xml"),
                    'lastmod' => $this->get_last_post_date($type),
                );
            }
        }

        // Sub-sitemap per tassonomia.
        $taxonomies = (array) $core->get('sitemap_taxonomies', array('category'));
        foreach ($taxonomies as $tax) {
            if (!taxonomy_exists($tax)) continue;
            $tax_obj = get_taxonomy($tax);
            if (!$tax_obj || !$tax_obj->public) continue;

            $term_count = (int) wp_count_terms(array(
                'taxonomy'   => $tax,
                'hide_empty' => true,
            ));
            if ($term_count < 1) continue;

            $items[] = array(
                'loc'     => home_url("/sitemap-tax-{$tax}.xml"),
                'lastmod' => current_time('c'),
            );
        }

        $items = apply_filters('dbseo_sitemap_index_items', $items);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($items as $item) {
            $xml .= "  <sitemap>\n";
            $xml .= '    <loc>' . esc_url($item['loc']) . "</loc>\n";
            if (!empty($item['lastmod'])) {
                $xml .= '    <lastmod>' . esc_html($item['lastmod']) . "</lastmod>\n";
            }
            $xml .= "  </sitemap>\n";
        }
        $xml .= '</sitemapindex>';
        return $xml;
    }

    /* =====================================================================
     * Rendering: post type sub-sitemap
     * ================================================================== */

    private function render_type($post_type, $paged) {
        if (!post_type_exists($post_type)) {
            status_header(404);
            return '';
        }

        $allowed = (array) DBSEO_Core::instance()->get('sitemap_post_types', array('post', 'page'));
        if (!in_array($post_type, $allowed, true)) {
            status_header(404);
            return '';
        }

        $cache_key = self::CACHE_GROUP . "type_{$post_type}_p{$paged}";
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $excluded = (array) DBSEO_Core::instance()->get('sitemap_exclude_ids', array());
        $excluded = (array) apply_filters('dbseo_sitemap_exclude_ids', $excluded, $post_type);

        // Performance: niente meta_query NOT EXISTS (LEFT JOIN pesante su
        // postmeta nei siti grandi). Gli ID noindex sono pochi: li leggiamo
        // con una query diretta cachata e li uniamo a post__not_in.
        $excluded = array_values(array_unique(array_merge(
            array_map('intval', $excluded),
            $this->get_meta_flag_ids('_dbseo_robots_noindex')
        )));

        $args = array(
            'post_type'      => $post_type,
            'post_status'    => 'publish',
            'posts_per_page' => self::URLS_PER_FILE,
            'paged'          => $paged,
            'orderby'        => 'modified',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'post__not_in'   => $excluded,
        );

        $q = new WP_Query($args);

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";

        // Homepage solo nella prima pagina di "page".
        if ($paged === 1 && $post_type === 'page') {
            $xml .= $this->url_node(home_url('/'), current_time('c'), 'daily', '1.0');
        }

        $cornerstone_ids = $this->get_meta_flag_ids('_dbseo_cornerstone');

        if ($q->have_posts()) {
            foreach ($q->posts as $post) {
                $loc     = get_permalink($post);
                $lastmod = mysql2date('c', $post->post_modified_gmt, false);
                $images  = $this->get_post_images($post);

                $xml .= "  <url>\n";
                $xml .= '    <loc>' . esc_url($loc) . "</loc>\n";
                $xml .= '    <lastmod>' . esc_html($lastmod) . "</lastmod>\n";
                if (in_array((int) $post->ID, $cornerstone_ids, true)) {
                    $xml .= "    <priority>1.0</priority>\n";
                }
                foreach ($images as $img_url) {
                    $xml .= "    <image:image>\n";
                    $xml .= '      <image:loc>' . esc_url($img_url) . "</image:loc>\n";
                    $xml .= "    </image:image>\n";
                }
                $xml .= "  </url>\n";
            }
        }

        $xml .= '</urlset>';

        set_transient($cache_key, $xml, self::CACHE_DURATION);
        return $xml;
    }

    /* =====================================================================
     * Rendering: taxonomy sub-sitemap
     * ================================================================== */

    private function render_taxonomy($taxonomy) {
        if (!taxonomy_exists($taxonomy)) {
            status_header(404);
            return '';
        }

        $allowed = (array) DBSEO_Core::instance()->get('sitemap_taxonomies', array('category'));
        if (!in_array($taxonomy, $allowed, true)) {
            status_header(404);
            return '';
        }

        $cache_key = self::CACHE_GROUP . "tax_{$taxonomy}";
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $terms = get_terms(array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => true,
            'number'     => self::URLS_PER_FILE,
        ));

        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                if ((int) get_term_meta($term->term_id, '_dbseo_robots_noindex', true)) continue;

                $url = get_term_link($term);
                if (is_wp_error($url)) continue;

                $xml .= $this->url_node($url, current_time('c'), 'weekly', '0.5');
            }
        }

        $xml .= '</urlset>';

        set_transient($cache_key, $xml, self::CACHE_DURATION);
        return $xml;
    }

    /* =====================================================================
     * Helpers
     * ================================================================== */

    private function url_node($loc, $lastmod = '', $changefreq = '', $priority = '') {
        $out  = "  <url>\n";
        $out .= '    <loc>' . esc_url($loc) . "</loc>\n";
        if ($lastmod)    $out .= '    <lastmod>' . esc_html($lastmod) . "</lastmod>\n";
        if ($changefreq) $out .= '    <changefreq>' . esc_html($changefreq) . "</changefreq>\n";
        if ($priority)   $out .= '    <priority>' . esc_html($priority) . "</priority>\n";
        $out .= "  </url>\n";
        return $out;
    }

    private function count_posts($type) {
        $counts = wp_count_posts($type);
        return isset($counts->publish) ? (int) $counts->publish : 0;
    }

    private function get_last_post_date($type) {
        global $wpdb;
        $date = $wpdb->get_var($wpdb->prepare(
            "SELECT post_modified_gmt FROM {$wpdb->posts}
             WHERE post_type = %s AND post_status = 'publish'
             ORDER BY post_modified_gmt DESC LIMIT 1",
            $type
        ));
        return $date ? mysql2date('c', $date, false) : current_time('c');
    }

    /**
     * Estrae URL immagini rilevanti per un post: featured + immagini nel contenuto.
     * Limitato a 1000 immagini per URL (limite hard di Google).
     */
    private function get_post_images($post) {
        $urls = array();

        $thumb_id = get_post_thumbnail_id($post);
        if ($thumb_id) {
            $u = wp_get_attachment_image_url($thumb_id, 'full');
            if ($u) $urls[] = $u;
        }

        if (preg_match_all('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $post->post_content, $m)) {
            foreach ($m[1] as $src) {
                $urls[] = $src;
            }
        }

        $urls = array_unique($urls);
        return array_slice($urls, 0, 1000);
    }

    /**
     * ID dei post pubblicati con un flag meta truthy (noindex, cornerstone).
     * Query diretta preparata, cache transient invalidata con la sitemap.
     *
     * @param string $meta_key
     * @return int[]
     */
    private function get_meta_flag_ids($meta_key) {
        $cache_key = self::CACHE_GROUP . 'flag_' . md5($meta_key);
        $cached = get_transient($cache_key);
        if (is_array($cached)) return $cached;

        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = %s AND pm.meta_value = '1'
               AND p.post_status = 'publish'",
            $meta_key
        ));
        $ids = array_map('intval', (array) $ids);

        set_transient($cache_key, $ids, self::CACHE_DURATION);
        return $ids;
    }

    /**
     * Invalidazione mirata: cancella solo i transient del post type toccato
     * (+ index + cache flag), invece dell'intera cache sitemap.
     *
     * @param int $post_id
     */
    public function invalidate_for_post($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            $this->invalidate_cache();
            return;
        }

        // I revisioni/autosave non toccano la sitemap.
        if (wp_is_post_revision($post_id) || $post->post_status === 'auto-draft') return;

        $allowed = (array) DBSEO_Core::instance()->get('sitemap_post_types', array('post', 'page'));
        if (!in_array($post->post_type, $allowed, true)) return;

        global $wpdb;
        $like = $wpdb->esc_like('_transient_' . self::CACHE_GROUP . 'type_' . $post->post_type . '_');
        $like_to = $wpdb->esc_like('_transient_timeout_' . self::CACHE_GROUP . 'type_' . $post->post_type . '_');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $like . '%', $like_to . '%'
        ));
        delete_transient(self::CACHE_GROUP . 'index');
        delete_transient(self::CACHE_GROUP . 'flag_' . md5('_dbseo_robots_noindex'));
        delete_transient(self::CACHE_GROUP . 'flag_' . md5('_dbseo_cornerstone'));
    }

    public function invalidate_cache() {
        global $wpdb;
        // Cancellazione bulk dei transient sitemap (ottimizzata vs delete_transient in loop).
        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '\\_transient\\_dbseo\\_sitemap\\_%'
                OR option_name LIKE '\\_transient\\_timeout\\_dbseo\\_sitemap\\_%'"
        );
    }
}
