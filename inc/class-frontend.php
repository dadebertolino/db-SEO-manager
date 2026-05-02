<?php
/**
 * DBSEO_Frontend — Output SEO nell'<head>.
 *
 * Responsabilità:
 *  - Disabilita l'output di WordPress core che andremmo a duplicare
 *    (rel-canonical, generator) e pilota wp_robots.
 *  - Genera <title> via filter pre_get_document_title.
 *  - Stampa meta description, robots (via filter wp_robots), canonical, OG, Twitter Card.
 *  - Stampa meta tag di verifica per Search Console / Bing / Yandex / Pinterest.
 *  - Stampa hreflang manuale se configurato.
 *
 * Tutti gli output sono filtrabili (vedi filter dbseo_meta_title, dbseo_meta_description, ecc.).
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Frontend {

    /** @var DBSEO_Frontend|null */
    private static $instance = null;

    /** @var array|null Cache della meta dell'oggetto corrente per evitare query multiple. */
    private $current_meta = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if (is_admin()) {
            return; // Frontend-only.
        }

        // Disabilita l'output core di WP che andremmo a duplicare.
        add_action('init', array($this, 'disable_core_output'), 99);

        // Reset cache su ogni nuovo loop.
        add_action('wp', array($this, 'reset_meta_cache'));

        // Title tag.
        add_filter('pre_get_document_title', array($this, 'filter_title'), 15);

        // Meta nell'<head>: priorità 1 per stare in alto, prima di altri plugin.
        add_action('wp_head', array($this, 'output_head'), 1);

        // Theme support per <title>.
        add_action('after_setup_theme', array($this, 'add_title_support'));
    }

    public function add_title_support() {
        add_theme_support('title-tag');
    }

    /**
     * Disabilita gli output WP core che gestiamo noi.
     */
    public function disable_core_output() {
        // Rimuoviamo il rel=canonical core; lo emettiamo noi con la nostra logica.
        remove_action('wp_head', 'rel_canonical');

        // Rimuoviamo il "generator" per ridurre fingerprinting.
        remove_action('wp_head', 'wp_generator');

        // wp_robots: filtro dedicato per noindex/nofollow.
        add_filter('wp_robots', array($this, 'filter_wp_robots'), 99);

        // Disabilita la sitemap core di WP (la sostituiamo con la nostra estesa).
        if (DBSEO_Core::instance()->get('sitemap_enabled')) {
            add_filter('wp_sitemaps_enabled', '__return_false');
        }
    }

    public function reset_meta_cache() {
        $this->current_meta = null;
    }

    /* =====================================================================
     * Helper contesto
     * ================================================================== */

    /**
     * Restituisce la meta SEO dell'oggetto corrente (post/term/archivio).
     *
     * @return array
     */
    private function get_current_meta() {
        if ($this->current_meta !== null) {
            return $this->current_meta;
        }

        $core = DBSEO_Core::instance();
        $meta = array(
            'title'               => '',
            'description'         => '',
            'canonical'           => '',
            'noindex'             => false,
            'nofollow'            => false,
            'og_title'            => '',
            'og_description'      => '',
            'og_image_id'         => 0,
            'twitter_title'       => '',
            'twitter_description' => '',
            'twitter_image_id'    => 0,
        );

        if (is_singular()) {
            $post_id = get_queried_object_id();
            $meta['title']               = (string) get_post_meta($post_id, '_dbseo_title', true);
            $meta['description']         = (string) get_post_meta($post_id, '_dbseo_description', true);
            $meta['canonical']           = (string) get_post_meta($post_id, '_dbseo_canonical', true);
            $meta['noindex']             = (bool) get_post_meta($post_id, '_dbseo_robots_noindex', true);
            $meta['nofollow']            = (bool) get_post_meta($post_id, '_dbseo_robots_nofollow', true);
            $meta['og_title']            = (string) get_post_meta($post_id, '_dbseo_og_title', true);
            $meta['og_description']      = (string) get_post_meta($post_id, '_dbseo_og_description', true);
            $meta['og_image_id']         = (int) get_post_meta($post_id, '_dbseo_og_image_id', true);
            $meta['twitter_title']       = (string) get_post_meta($post_id, '_dbseo_twitter_title', true);
            $meta['twitter_description'] = (string) get_post_meta($post_id, '_dbseo_twitter_description', true);
            $meta['twitter_image_id']    = (int) get_post_meta($post_id, '_dbseo_twitter_image_id', true);

            // Fallback: title vuoto → costruito da template.
            if ($meta['title'] === '') {
                $tpl = (get_post_type($post_id) === 'page')
                    ? $core->get('title_page')
                    : $core->get('title_post');
                $meta['title'] = $this->resolve_template($tpl, array('object_id' => $post_id, 'object_type' => 'post'));
            }
            if ($meta['description'] === '') {
                $tpl = (get_post_type($post_id) === 'page')
                    ? $core->get('desc_page')
                    : $core->get('desc_post');
                $meta['description'] = $this->resolve_template($tpl, array('object_id' => $post_id, 'object_type' => 'post'));
            }

        } elseif (is_tax() || is_category() || is_tag()) {
            $term = get_queried_object();
            if ($term instanceof WP_Term) {
                $meta['title']       = (string) get_term_meta($term->term_id, '_dbseo_title', true);
                $meta['description'] = (string) get_term_meta($term->term_id, '_dbseo_description', true);
                $meta['canonical']   = (string) get_term_meta($term->term_id, '_dbseo_canonical', true);
                $meta['noindex']     = (bool) get_term_meta($term->term_id, '_dbseo_robots_noindex', true);
                $meta['og_image_id'] = (int) get_term_meta($term->term_id, '_dbseo_og_image_id', true);

                if ($meta['title'] === '') {
                    $meta['title'] = $this->resolve_template(
                        $core->get('title_archive'),
                        array('archive_title' => single_term_title('', false))
                    );
                }
                if ($meta['description'] === '') {
                    $desc = term_description($term);
                    $meta['description'] = wp_strip_all_tags($desc);
                }
            }

        } elseif (is_home() || is_front_page()) {
            $meta['title']       = $this->resolve_template($core->get('title_home'));
            $meta['description'] = $this->resolve_template($core->get('desc_home'));
            if ($meta['description'] === '') {
                $meta['description'] = get_bloginfo('description');
            }

        } elseif (is_search()) {
            $meta['title']   = $this->resolve_template($core->get('title_search'), array(
                'search_query' => get_search_query(),
            ));
            $meta['noindex'] = (bool) $core->get('noindex_search');

        } elseif (is_404()) {
            $meta['title']   = $this->resolve_template($core->get('title_404'));
            $meta['noindex'] = (bool) $core->get('noindex_404');

        } elseif (is_author()) {
            $meta['noindex'] = (bool) $core->get('noindex_author');

        } elseif (is_date()) {
            $meta['noindex'] = (bool) $core->get('noindex_date');

        } elseif (is_attachment()) {
            $meta['noindex'] = (bool) $core->get('noindex_attachment');
        }

        // Canonical: se vuota, costruisce da URL corrente.
        if ($meta['canonical'] === '') {
            $meta['canonical'] = $this->build_default_canonical();
        }

        // Limiti sensati.
        $meta['title']       = $this->clamp($meta['title'], 70);
        $meta['description'] = $this->clamp($meta['description'], 160);

        // Filtri pubblici.
        $meta['title']       = (string) apply_filters('dbseo_meta_title', $meta['title'], $meta);
        $meta['description'] = (string) apply_filters('dbseo_meta_description', $meta['description'], $meta);
        $meta['canonical']   = (string) apply_filters('dbseo_canonical_url', $meta['canonical'], $meta);

        $this->current_meta = $meta;
        return $meta;
    }

    /**
     * Risolve un template con placeholder %title%, %sitename%, %sep%, %tagline%, ecc.
     *
     * @param string $tpl
     * @param array  $context
     * @return string
     */
    private function resolve_template($tpl, $context = array()) {
        if (!is_string($tpl) || $tpl === '') return '';

        $core      = DBSEO_Core::instance();
        $sitename  = $core->get('site_name');
        if ($sitename === '') $sitename = get_bloginfo('name');

        $object_title = '';
        $excerpt      = '';
        if (!empty($context['object_id']) && ($context['object_type'] ?? '') === 'post') {
            $post = get_post((int) $context['object_id']);
            if ($post) {
                $object_title = $post->post_title;
                $excerpt      = has_excerpt($post)
                    ? get_the_excerpt($post)
                    : wp_trim_words(wp_strip_all_tags($post->post_content), 30, '');
            }
        }

        $replacements = array(
            '%sitename%'      => $sitename,
            '%sep%'           => $core->get('separator'),
            '%tagline%'       => get_bloginfo('description'),
            '%title%'         => $object_title,
            '%excerpt%'       => $excerpt,
            '%search_query%'  => get_search_query(),
            '%archive_title%' => $context['archive_title'] ?? '',
            '%category%'      => $this->primary_category_name((int) ($context['object_id'] ?? 0)),
        );

        $out = strtr($tpl, $replacements);

        // Pulizia: rimuove separatori orfani lasciati da placeholder vuoti.
        $sep = preg_quote($core->get('separator'), '/');
        $out = preg_replace('/(\s*' . $sep . '\s*)+/', ' ' . $core->get('separator') . ' ', $out);
        $out = trim($out, " \t\n\r\0\x0B" . $core->get('separator'));
        return $out;
    }

    private function primary_category_name($post_id) {
        if (!$post_id) return '';
        $cats = get_the_category($post_id);
        return ($cats && isset($cats[0])) ? $cats[0]->name : '';
    }

    /**
     * Tronca a $max caratteri rispettando i word-boundary, senza tagliare a metà parola.
     */
    private function clamp($str, $max) {
        $str = wp_strip_all_tags((string) $str);
        $str = preg_replace('/\s+/', ' ', trim($str));
        if (mb_strlen($str) <= $max) return $str;
        $cut = mb_substr($str, 0, $max);
        $sp  = mb_strrpos($cut, ' ');
        if ($sp !== false && $sp > $max * 0.6) {
            $cut = mb_substr($cut, 0, $sp);
        }
        return rtrim($cut, " ,.;:-") . '…';
    }

    private function build_default_canonical() {
        if (is_singular()) {
            return (string) get_permalink(get_queried_object_id());
        }
        if (is_category() || is_tag() || is_tax()) {
            $url = get_term_link(get_queried_object());
            return is_wp_error($url) ? '' : $url;
        }
        if (is_home() || is_front_page()) {
            return home_url('/');
        }
        if (is_author()) {
            return get_author_posts_url(get_queried_object_id());
        }
        // Fallback: URL corrente normalizzato.
        return home_url(add_query_arg(array(), $GLOBALS['wp']->request));
    }

    /* =====================================================================
     * Output: <title>
     * ================================================================== */

    public function filter_title($title) {
        $meta = $this->get_current_meta();
        return $meta['title'] !== '' ? $meta['title'] : $title;
    }

    /* =====================================================================
     * Output: wp_robots filter (5.7+)
     * ================================================================== */

    public function filter_wp_robots($robots) {
        $meta = $this->get_current_meta();

        if ($meta['noindex']) {
            $robots['noindex'] = true;
            unset($robots['index']);
        }
        if ($meta['nofollow']) {
            $robots['nofollow'] = true;
            unset($robots['follow']);
        }

        // Direttive raccomandate per controllo snippet (filtrabili).
        $robots = apply_filters('dbseo_robots_directives', $robots, $meta);
        return $robots;
    }

    /* =====================================================================
     * Output: <head> markup principale
     * ================================================================== */

    public function output_head() {
        $core = DBSEO_Core::instance();
        $meta = $this->get_current_meta();

        echo "\n<!-- DB SEO Manager v" . esc_attr(DBSEO_VERSION) . " -->\n";

        // Description.
        if ($meta['description'] !== '') {
            echo '<meta name="description" content="' . esc_attr($meta['description']) . "\">\n";
        }

        // Canonical.
        if ($meta['canonical'] !== '') {
            echo '<link rel="canonical" href="' . esc_url($meta['canonical']) . "\">\n";
        }

        // Verifiche search engine (solo meta tag, no fetch dati).
        $verifications = array(
            'google-site-verification' => $core->get('verify_google'),
            'msvalidate.01'            => $core->get('verify_bing'),
            'yandex-verification'      => $core->get('verify_yandex'),
            'p:domain_verify'          => $core->get('verify_pinterest'),
        );
        foreach ($verifications as $name => $value) {
            if (!empty($value)) {
                echo '<meta name="' . esc_attr($name) . '" content="' . esc_attr($value) . "\">\n";
            }
        }

        // OpenGraph.
        if ($core->get('og_enabled')) {
            $this->output_opengraph($meta);
            $this->output_twitter($meta);
        }

        // Hreflang (filter pubblico).
        $hreflangs = (array) apply_filters('dbseo_hreflang_alternatives', array(), $meta);
        foreach ($hreflangs as $lang => $url) {
            echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($url) . "\">\n";
        }

        echo "<!-- /DB SEO Manager -->\n";

        do_action('dbseo_after_head', $meta);
    }

    /* =====================================================================
     * Output: OpenGraph
     * ================================================================== */

    private function output_opengraph($meta) {
        $og_title = $meta['og_title']       !== '' ? $meta['og_title']       : $meta['title'];
        $og_desc  = $meta['og_description'] !== '' ? $meta['og_description'] : $meta['description'];

        $core      = DBSEO_Core::instance();
        $sitename  = $core->get('site_name');
        if ($sitename === '') $sitename = get_bloginfo('name');

        $og_type   = is_singular(array('post')) ? 'article' : 'website';
        $og_locale = determine_locale();

        // Image: meta specifica → featured → default OG.
        $og_image_id = $meta['og_image_id'];
        if (!$og_image_id && is_singular()) {
            $og_image_id = (int) get_post_thumbnail_id(get_queried_object_id());
        }
        if (!$og_image_id) {
            $og_image_id = (int) $core->get('og_default_image_id');
        }

        echo '<meta property="og:locale" content="' . esc_attr($og_locale) . "\">\n";
        echo '<meta property="og:type" content="' . esc_attr($og_type) . "\">\n";
        echo '<meta property="og:site_name" content="' . esc_attr($sitename) . "\">\n";

        if ($og_title !== '') {
            echo '<meta property="og:title" content="' . esc_attr($og_title) . "\">\n";
        }
        if ($og_desc !== '') {
            echo '<meta property="og:description" content="' . esc_attr($og_desc) . "\">\n";
        }
        if ($meta['canonical'] !== '') {
            echo '<meta property="og:url" content="' . esc_url($meta['canonical']) . "\">\n";
        }

        if ($og_image_id) {
            $img = wp_get_attachment_image_src($og_image_id, 'full');
            if ($img && !empty($img[0])) {
                echo '<meta property="og:image" content="' . esc_url($img[0]) . "\">\n";
                if (!empty($img[1])) echo '<meta property="og:image:width" content="' . esc_attr((string) $img[1]) . "\">\n";
                if (!empty($img[2])) echo '<meta property="og:image:height" content="' . esc_attr((string) $img[2]) . "\">\n";

                $alt = (string) get_post_meta($og_image_id, '_wp_attachment_image_alt', true);
                if ($alt !== '') {
                    echo '<meta property="og:image:alt" content="' . esc_attr($alt) . "\">\n";
                }
            }
        }

        // Article-specific.
        if (is_singular(array('post'))) {
            $post_id   = get_queried_object_id();
            $published = get_the_date('c', $post_id);
            $modified  = get_the_modified_date('c', $post_id);
            if ($published) echo '<meta property="article:published_time" content="' . esc_attr($published) . "\">\n";
            if ($modified)  echo '<meta property="article:modified_time" content="' . esc_attr($modified) . "\">\n";
        }
    }

    /* =====================================================================
     * Output: Twitter Card
     * ================================================================== */

    private function output_twitter($meta) {
        $core = DBSEO_Core::instance();

        $tw_title = $meta['twitter_title']       !== '' ? $meta['twitter_title']       : ($meta['og_title']       !== '' ? $meta['og_title']       : $meta['title']);
        $tw_desc  = $meta['twitter_description'] !== '' ? $meta['twitter_description'] : ($meta['og_description'] !== '' ? $meta['og_description'] : $meta['description']);

        $card = $core->get('twitter_card', 'summary_large_image');
        echo '<meta name="twitter:card" content="' . esc_attr($card) . "\">\n";

        $handle = trim((string) $core->get('twitter_site'));
        if ($handle !== '') {
            if ($handle[0] !== '@') $handle = '@' . $handle;
            echo '<meta name="twitter:site" content="' . esc_attr($handle) . "\">\n";
        }

        if ($tw_title !== '') echo '<meta name="twitter:title" content="' . esc_attr($tw_title) . "\">\n";
        if ($tw_desc !== '')  echo '<meta name="twitter:description" content="' . esc_attr($tw_desc) . "\">\n";

        $tw_image_id = $meta['twitter_image_id'] ?: $meta['og_image_id'];
        if (!$tw_image_id && is_singular()) {
            $tw_image_id = (int) get_post_thumbnail_id(get_queried_object_id());
        }
        if (!$tw_image_id) {
            $tw_image_id = (int) $core->get('og_default_image_id');
        }
        if ($tw_image_id) {
            $url = wp_get_attachment_image_url($tw_image_id, 'full');
            if ($url) {
                echo '<meta name="twitter:image" content="' . esc_url($url) . "\">\n";
            }
        }
    }
}
