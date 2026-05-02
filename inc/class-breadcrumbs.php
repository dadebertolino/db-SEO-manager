<?php
/**
 * DBSEO_Breadcrumbs — Briciole di pane.
 *
 * Espone:
 *  - get_items()   → array di voci (label, url, position) usato anche da DBSEO_Schema
 *  - render()      → markup HTML accessibile (nav > ol)
 *  - shortcode     [dbseo_breadcrumbs]
 *  - template tag  dbseo_breadcrumbs() per i temi
 *  - block         dbseo/breadcrumbs (server-side render)
 *
 * Logica:
 *  - Home → ancestori (categoria genitore, post type archive) → corrente.
 *  - Funziona per: pagine (con parent), post (categoria primaria), CPT (archivio),
 *    tassonomie (con term parent), search, 404, author, date.
 *
 * Accessibilità:
 *  - <nav aria-label="Breadcrumb">
 *  - <ol> con itemtype microdata BreadcrumbList opzionale
 *  - aria-current="page" sull'ultima voce
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Breadcrumbs {

    /** @var DBSEO_Breadcrumbs|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (!DBSEO_Core::instance()->get('breadcrumb_enabled')) return;

        add_shortcode('dbseo_breadcrumbs', array($this, 'shortcode'));
        add_action('init', array($this, 'register_block'));
    }

    /* =====================================================================
     * Costruzione voci
     * ================================================================== */

    /**
     * Ritorna l'array di voci breadcrumb per il contesto corrente.
     * Ogni voce: array{label:string, url:string, current:bool}
     */
    public function get_items() {
        $core  = DBSEO_Core::instance();
        $items = array();

        // Voce Home (sempre, salvo si stia ON home).
        if (!is_front_page()) {
            $items[] = array(
                'label'   => $core->get('breadcrumb_home_label', 'Home'),
                'url'     => home_url('/'),
                'current' => false,
            );
        }

        if (is_front_page()) {
            $items[] = array(
                'label'   => $core->get('breadcrumb_home_label', 'Home'),
                'url'     => '',
                'current' => true,
            );

        } elseif (is_singular('page')) {
            $items = array_merge($items, $this->ancestors_for_page(get_queried_object()));

        } elseif (is_singular()) {
            $post = get_queried_object();
            $items = array_merge($items, $this->ancestors_for_post($post));
            $items[] = array(
                'label'   => get_the_title($post),
                'url'     => '',
                'current' => true,
            );

        } elseif (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                // Ancestor della tassonomia (parent term).
                $ancestors = array_reverse(get_ancestors($term->term_id, $term->taxonomy, 'taxonomy'));
                foreach ($ancestors as $aid) {
                    $a = get_term($aid, $term->taxonomy);
                    if ($a && !is_wp_error($a)) {
                        $url = get_term_link($a);
                        $items[] = array(
                            'label'   => $a->name,
                            'url'     => is_wp_error($url) ? '' : $url,
                            'current' => false,
                        );
                    }
                }
                $items[] = array(
                    'label'   => $term->name,
                    'url'     => '',
                    'current' => true,
                );
            }

        } elseif (is_post_type_archive()) {
            $pt = get_queried_object();
            if ($pt) {
                $items[] = array(
                    'label'   => $pt->labels->name,
                    'url'     => '',
                    'current' => true,
                );
            }

        } elseif (is_search()) {
            $items[] = array(
                'label'   => sprintf(
                    /* translators: %s: search query */
                    __('Risultati per "%s"', 'db-seo-manager'),
                    get_search_query()
                ),
                'url'     => '',
                'current' => true,
            );

        } elseif (is_author()) {
            $items[] = array(
                'label'   => get_the_author_meta('display_name', get_queried_object_id()),
                'url'     => '',
                'current' => true,
            );

        } elseif (is_year()) {
            $items[] = array('label' => get_the_date('Y'), 'url' => '', 'current' => true);

        } elseif (is_month()) {
            $items[] = array('label' => get_the_date('F Y'), 'url' => '', 'current' => true);

        } elseif (is_day()) {
            $items[] = array('label' => get_the_date(), 'url' => '', 'current' => true);

        } elseif (is_404()) {
            $items[] = array(
                'label'   => __('Pagina non trovata', 'db-seo-manager'),
                'url'     => '',
                'current' => true,
            );
        }

        return apply_filters('dbseo_breadcrumb_items', $items);
    }

    private function ancestors_for_page($page) {
        $out = array();
        if (!$page) return $out;
        $ancestors = array_reverse(get_post_ancestors($page->ID));
        foreach ($ancestors as $aid) {
            $out[] = array(
                'label'   => get_the_title($aid),
                'url'     => get_permalink($aid),
                'current' => false,
            );
        }
        $out[] = array(
            'label'   => get_the_title($page),
            'url'     => '',
            'current' => true,
        );
        return $out;
    }

    private function ancestors_for_post($post) {
        $out = array();
        if (!$post) return $out;

        $pt = $post->post_type;

        // Per CPT: archivio del post type come ancestor.
        if ($pt !== 'post') {
            $pt_obj = get_post_type_object($pt);
            if ($pt_obj && $pt_obj->has_archive) {
                $out[] = array(
                    'label'   => $pt_obj->labels->name,
                    'url'     => get_post_type_archive_link($pt),
                    'current' => false,
                );
            }
            return $out;
        }

        // Per post: categoria primaria.
        $cats = get_the_category($post->ID);
        if (!empty($cats) && isset($cats[0])) {
            $cat = $cats[0];
            // Eventuali ancestor della categoria.
            $ancestors = array_reverse(get_ancestors($cat->term_id, 'category', 'taxonomy'));
            foreach ($ancestors as $aid) {
                $a = get_term($aid, 'category');
                if ($a && !is_wp_error($a)) {
                    $out[] = array(
                        'label'   => $a->name,
                        'url'     => get_term_link($a),
                        'current' => false,
                    );
                }
            }
            $out[] = array(
                'label'   => $cat->name,
                'url'     => get_term_link($cat),
                'current' => false,
            );
        }
        return $out;
    }

    /* =====================================================================
     * Rendering
     * ================================================================== */

    public function render($args = array()) {
        $defaults = array(
            'separator'   => DBSEO_Core::instance()->get('breadcrumb_separator', '/'),
            'aria_label'  => __('Briciole di pane', 'db-seo-manager'),
            'class'       => 'dbseo-breadcrumbs',
        );
        $args = wp_parse_args($args, $defaults);

        $items = $this->get_items();
        if (empty($items)) return '';

        $out  = sprintf(
            '<nav aria-label="%s" class="%s">',
            esc_attr($args['aria_label']),
            esc_attr($args['class'])
        );
        $out .= '<ol>';

        $count = count($items);
        $i = 0;
        foreach ($items as $item) {
            $i++;
            $is_last = ($i === $count);
            $out .= '<li>';
            if (!empty($item['url']) && !$is_last) {
                $out .= sprintf(
                    '<a href="%s">%s</a>',
                    esc_url($item['url']),
                    esc_html($item['label'])
                );
            } else {
                $out .= sprintf(
                    '<span aria-current="page">%s</span>',
                    esc_html($item['label'])
                );
            }
            if (!$is_last) {
                $out .= sprintf(
                    '<span class="dbseo-breadcrumbs__sep" aria-hidden="true"> %s </span>',
                    esc_html($args['separator'])
                );
            }
            $out .= '</li>';
        }

        $out .= '</ol></nav>';
        return $out;
    }

    public function shortcode($atts = array()) {
        $atts = shortcode_atts(array(
            'separator' => DBSEO_Core::instance()->get('breadcrumb_separator', '/'),
            'class'     => 'dbseo-breadcrumbs',
        ), $atts, 'dbseo_breadcrumbs');

        return $this->render(array(
            'separator' => $atts['separator'],
            'class'     => $atts['class'],
        ));
    }

    /* =====================================================================
     * Gutenberg block (server-side render)
     * ================================================================== */

    public function register_block() {
        if (!function_exists('register_block_type')) return;

        register_block_type('dbseo/breadcrumbs', array(
            'render_callback' => array($this, 'render_block'),
            'attributes'      => array(
                'separator' => array('type' => 'string', 'default' => '/'),
            ),
        ));
    }

    public function render_block($attrs) {
        return $this->render(array(
            'separator' => isset($attrs['separator']) ? sanitize_text_field($attrs['separator']) : '/',
        ));
    }
}

/* ---------------------------------------------------------------------------
 * Template tag globale.
 * ------------------------------------------------------------------------ */
if (!function_exists('dbseo_breadcrumbs')) {
    function dbseo_breadcrumbs($args = array()) {
        $bc = DBSEO_Core::instance()->module('breadcrumbs');
        if ($bc instanceof DBSEO_Breadcrumbs) {
            echo $bc->render($args); // phpcs:ignore WordPress.Security.EscapeOutput
        }
    }
}
