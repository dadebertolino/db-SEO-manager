<?php
/**
 * DBSEO_Schema — Generatore JSON-LD (Schema.org).
 *
 * Filosofia:
 *  - Output minimale e valido. Niente campi inventati, solo dati reali del sito.
 *  - Un singolo <script type="application/ld+json"> con grafo unico (@graph)
 *    per evitare duplicazioni e migliorare la coerenza tra entità.
 *  - Tutti gli oggetti sono filtrabili via dbseo_schema_data.
 *
 * Tipi gestiti automaticamente:
 *  - WebSite (sempre, su ogni pagina)
 *  - Organization | Person (sempre, da settings)
 *  - WebPage (sempre, ancora del grafo)
 *  - Article (su singolari di tipo post o CPT marcato come Article via meta)
 *  - BreadcrumbList (se breadcrumbs abilitate)
 *
 * Tipi opt-in via post meta `_dbseo_schema_type`:
 *  - FAQPage, HowTo, Product, Event, LocalBusiness — lasciati al filter pubblico
 *    perché richiedono dati strutturati custom (campi che il plugin non ha).
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Schema {

    /** @var DBSEO_Schema|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** @var array Item FAQ raccolti dagli shortcode nella pagina corrente. */
    private $faq_items = array();

    /** @var array Step HowTo raccolti dagli shortcode nella pagina corrente. */
    private $howto = array();

    private function __construct() {
        // Gli shortcode vanno registrati anche in admin (preview editor).
        add_shortcode('dbseo_faq',       array($this, 'shortcode_faq'));
        add_shortcode('dbseo_faq_item',  array($this, 'shortcode_faq_item'));
        add_shortcode('dbseo_howto',     array($this, 'shortcode_howto'));
        add_shortcode('dbseo_howto_step', array($this, 'shortcode_howto_step'));

        if (is_admin()) return;
        if (!DBSEO_Core::instance()->get('schema_enabled')) return;

        // Priorità 5 per stare prima di altri plugin schema.
        add_action('wp_head', array($this, 'output'), 5);

        // FAQ/HowTo: gli shortcode vengono processati DOPO wp_head, quindi
        // il loro JSON-LD viene emesso nel footer (posizione valida per Google).
        add_action('wp_footer', array($this, 'output_collected'), 20);
    }

    /* =====================================================================
     * Shortcode FAQ — [dbseo_faq][dbseo_faq_item q="..."]risposta[/dbseo_faq_item][/dbseo_faq]
     * ================================================================== */

    public function shortcode_faq($atts, $content = '') {
        $this->faq_items = array();
        $inner = do_shortcode($content);
        // Markup accessibile: details/summary nativi.
        return '<div class="dbseo-faq" itemscope>' . $inner . '</div>';
    }

    public function shortcode_faq_item($atts, $content = '') {
        $atts = shortcode_atts(array('q' => ''), $atts, 'dbseo_faq_item');
        $q = sanitize_text_field($atts['q']);
        $a = wp_kses_post($content);
        if ($q === '' || trim($a) === '') return '';

        $this->faq_items[] = array('q' => $q, 'a' => wp_strip_all_tags($a));

        return '<details class="dbseo-faq-item"><summary>' . esc_html($q) . '</summary><div class="dbseo-faq-answer">' . $a . '</div></details>';
    }

    /* =====================================================================
     * Shortcode HowTo — [dbseo_howto name="..."][dbseo_howto_step name="..."]testo[/dbseo_howto_step][/dbseo_howto]
     * ================================================================== */

    public function shortcode_howto($atts, $content = '') {
        $atts = shortcode_atts(array('name' => '', 'total_time' => ''), $atts, 'dbseo_howto');
        $this->howto = array(
            'name'       => sanitize_text_field($atts['name']),
            'total_time' => sanitize_text_field($atts['total_time']), // ISO 8601, es. PT30M
            'steps'      => array(),
        );
        $inner = do_shortcode($content);
        return '<div class="dbseo-howto"><ol class="dbseo-howto-steps">' . $inner . '</ol></div>';
    }

    public function shortcode_howto_step($atts, $content = '') {
        $atts = shortcode_atts(array('name' => ''), $atts, 'dbseo_howto_step');
        $name = sanitize_text_field($atts['name']);
        $text = wp_kses_post($content);
        if (trim($text) === '') return '';

        $this->howto['steps'][] = array('name' => $name, 'text' => wp_strip_all_tags($text));

        $label = $name !== '' ? '<strong>' . esc_html($name) . '</strong> — ' : '';
        return '<li class="dbseo-howto-step">' . $label . $text . '</li>';
    }

    /**
     * Emette il JSON-LD di FAQ/HowTo raccolti dagli shortcode (footer).
     */
    public function output_collected() {
        $graph = array();

        if (!empty($this->faq_items)) {
            $entities = array();
            foreach ($this->faq_items as $item) {
                $entities[] = array(
                    '@type'          => 'Question',
                    'name'           => $item['q'],
                    'acceptedAnswer' => array('@type' => 'Answer', 'text' => $item['a']),
                );
            }
            $graph[] = array('@type' => 'FAQPage', 'mainEntity' => $entities);
        }

        if (!empty($this->howto['steps'])) {
            $steps = array();
            $pos = 1;
            foreach ($this->howto['steps'] as $st) {
                $step = array('@type' => 'HowToStep', 'position' => $pos++, 'text' => $st['text']);
                if ($st['name'] !== '') $step['name'] = $st['name'];
                $steps[] = $step;
            }
            $node = array('@type' => 'HowTo', 'step' => $steps);
            if ($this->howto['name'] !== '')       $node['name']      = $this->howto['name'];
            if ($this->howto['total_time'] !== '') $node['totalTime'] = $this->howto['total_time'];
            $graph[] = $node;
        }

        $graph = (array) apply_filters('dbseo_schema_footer_graph', $graph);
        if (empty($graph)) return;

        $json = wp_json_encode(array('@context' => 'https://schema.org', '@graph' => array_values($graph)), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return;
        echo '<script type="application/ld+json">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
    }

    public function output() {
        $graph = array();

        // 1. WebSite (sempre)
        $graph[] = $this->build_website();

        // 2. Publisher: Organization o Person
        $publisher = $this->build_publisher();
        if ($publisher) $graph[] = $publisher;

        // 3. WebPage (sempre, ancora del grafo)
        $graph[] = $this->build_webpage();

        // 4. Article (se singolare post o CPT mappato)
        if (is_singular()) {
            $post_id     = get_queried_object_id();
            $schema_type = get_post_meta($post_id, '_dbseo_schema_type', true);

            if ($schema_type === 'Article' || (is_singular('post') && empty($schema_type))) {
                $article = $this->build_article($post_id);
                if ($article) $graph[] = $article;
            }
        }

        // 5. BreadcrumbList (se modulo attivo)
        $breadcrumbs = $this->build_breadcrumb_list();
        if ($breadcrumbs) $graph[] = $breadcrumbs;

        // Filter pubblico: terze parti possono aggiungere/modificare nodi.
        $graph = (array) apply_filters('dbseo_schema_data', $graph, $this->context());

        // Rimuove eventuali nodi vuoti / non-array.
        $graph = array_values(array_filter($graph, function($n) { return is_array($n) && !empty($n); }));

        if (empty($graph)) return;

        $output = array(
            '@context' => 'https://schema.org',
            '@graph'   => $graph,
        );

        $json = wp_json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return;

        echo '<script type="application/ld+json">' . $json . "</script>\n";
    }

    /* =====================================================================
     * Builders
     * ================================================================== */

    private function build_website() {
        $core = DBSEO_Core::instance();
        $name = $core->get('site_name');
        if ($name === '') $name = get_bloginfo('name');

        $site = array(
            '@type'       => 'WebSite',
            '@id'         => home_url('/#website'),
            'url'         => home_url('/'),
            'name'        => $name,
            'description' => get_bloginfo('description'),
            'inLanguage'  => str_replace('_', '-', determine_locale()),
            'publisher'   => array('@id' => $this->publisher_id()),
        );

        // SearchAction (sitelinks search box). Filtrabile.
        if ((bool) apply_filters('dbseo_schema_search_action', true)) {
            $site['potentialAction'] = array(
                '@type'       => 'SearchAction',
                'target'      => array(
                    '@type'       => 'EntryPoint',
                    'urlTemplate' => home_url('/?s={search_term_string}'),
                ),
                'query-input' => 'required name=search_term_string',
            );
        }

        return $site;
    }

    private function build_publisher() {
        $core = DBSEO_Core::instance();
        $type = $core->get('schema_org_type', 'Organization');
        $name = $core->get('schema_org_name');
        if ($name === '') $name = get_bloginfo('name');

        $entity = array(
            '@type' => $type,
            '@id'   => $this->publisher_id(),
            'name'  => $name,
            'url'   => home_url('/'),
        );

        $logo_id = (int) $core->get('schema_org_logo_id');
        if ($logo_id) {
            $logo = wp_get_attachment_image_src($logo_id, 'full');
            if ($logo && !empty($logo[0])) {
                $entity['logo'] = array(
                    '@type'  => 'ImageObject',
                    '@id'    => home_url('/#logo'),
                    'url'    => $logo[0],
                    'width'  => isset($logo[1]) ? (int) $logo[1] : null,
                    'height' => isset($logo[2]) ? (int) $logo[2] : null,
                );
                if ($type === 'Organization') {
                    // Per Organization, il logo è anche l'image principale.
                    $entity['image'] = array('@id' => home_url('/#logo'));
                }
                $entity['logo'] = array_filter($entity['logo'], function($v) { return $v !== null; });
            }
        }

        $tel   = trim((string) $core->get('schema_org_telephone'));
        $email = trim((string) $core->get('schema_org_email'));

        if ($tel !== '' && $type === 'Organization') {
            $entity['contactPoint'] = array(
                '@type'       => 'ContactPoint',
                'contactType' => 'customer support',
                'telephone'   => $tel,
                'email'       => $email !== '' ? $email : null,
            );
            $entity['contactPoint'] = array_filter($entity['contactPoint'], function($v) { return $v !== null; });
        }

        // sameAs: profili social, popolabili via filter (non li mettiamo in settings
        // per non gonfiare lo schema; è una scelta deliberata).
        $sameAs = (array) apply_filters('dbseo_schema_publisher_sameas', array());
        $sameAs = array_values(array_filter(array_map('esc_url_raw', $sameAs)));
        if (!empty($sameAs)) {
            $entity['sameAs'] = $sameAs;
        }

        return $entity;
    }

    private function publisher_id() {
        $type = DBSEO_Core::instance()->get('schema_org_type', 'Organization');
        return home_url('/#') . strtolower($type);
    }

    private function build_webpage() {
        $url = $this->current_url();

        $page = array(
            '@type'      => is_singular() ? 'WebPage' : (is_search() ? 'SearchResultsPage' : 'CollectionPage'),
            '@id'        => $url . '#webpage',
            'url'        => $url,
            'name'       => wp_get_document_title(),
            'isPartOf'   => array('@id' => home_url('/#website')),
            'inLanguage' => str_replace('_', '-', determine_locale()),
        );

        // Description: usa quella già calcolata dal Frontend se disponibile.
        $desc = (string) apply_filters('dbseo_meta_description', '', array());
        if ($desc !== '') {
            $page['description'] = $desc;
        }

        // Date per singolari.
        if (is_singular()) {
            $post_id  = get_queried_object_id();
            $page['datePublished'] = get_the_date('c', $post_id);
            $page['dateModified']  = get_the_modified_date('c', $post_id);

            // Featured image.
            $thumb_id = (int) get_post_thumbnail_id($post_id);
            if ($thumb_id) {
                $img = wp_get_attachment_image_src($thumb_id, 'full');
                if ($img && !empty($img[0])) {
                    $page['primaryImageOfPage'] = array(
                        '@type'  => 'ImageObject',
                        'url'    => $img[0],
                        'width'  => isset($img[1]) ? (int) $img[1] : null,
                        'height' => isset($img[2]) ? (int) $img[2] : null,
                    );
                    $page['primaryImageOfPage'] = array_filter($page['primaryImageOfPage'], function($v) { return $v !== null; });
                }
            }
        }

        return $page;
    }

    private function build_article($post_id) {
        $post = get_post($post_id);
        if (!$post) return null;

        $url      = get_permalink($post_id);
        $thumb_id = (int) get_post_thumbnail_id($post_id);

        $article = array(
            '@type'            => 'Article',
            '@id'              => $url . '#article',
            'isPartOf'         => array('@id' => $url . '#webpage'),
            'mainEntityOfPage' => array('@id' => $url . '#webpage'),
            'headline'         => get_the_title($post_id),
            'datePublished'    => get_the_date('c', $post_id),
            'dateModified'     => get_the_modified_date('c', $post_id),
            'inLanguage'       => str_replace('_', '-', determine_locale()),
            'publisher'        => array('@id' => $this->publisher_id()),
        );

        // Author.
        $author_id = (int) $post->post_author;
        if ($author_id) {
            $article['author'] = array(
                '@type' => 'Person',
                'name'  => get_the_author_meta('display_name', $author_id),
                'url'   => get_author_posts_url($author_id),
            );
        }

        // Image.
        if ($thumb_id) {
            $img = wp_get_attachment_image_src($thumb_id, 'full');
            if ($img && !empty($img[0])) {
                $article['image'] = array(
                    '@type'  => 'ImageObject',
                    'url'    => $img[0],
                    'width'  => isset($img[1]) ? (int) $img[1] : null,
                    'height' => isset($img[2]) ? (int) $img[2] : null,
                );
                $article['image'] = array_filter($article['image'], function($v) { return $v !== null; });
            }
        }

        // wordCount + articleSection.
        $content = wp_strip_all_tags($post->post_content);
        if ($content !== '') {
            $article['wordCount'] = str_word_count($content);
        }
        $cats = get_the_category($post_id);
        if (!empty($cats) && isset($cats[0])) {
            $article['articleSection'] = $cats[0]->name;
        }

        return $article;
    }

    private function build_breadcrumb_list() {
        if (!DBSEO_Core::instance()->get('breadcrumb_enabled')) {
            return null;
        }

        $bc = DBSEO_Core::instance()->module('breadcrumbs');
        if (!$bc || !method_exists($bc, 'get_items')) {
            return null;
        }

        $items = $bc->get_items();
        if (empty($items) || count($items) < 2) {
            return null; // Niente breadcrumb sulla home.
        }

        $list = array();
        $pos  = 1;
        foreach ($items as $item) {
            if (empty($item['label'])) continue;
            $entry = array(
                '@type'    => 'ListItem',
                'position' => $pos++,
                'name'     => $item['label'],
            );
            if (!empty($item['url'])) {
                $entry['item'] = $item['url'];
            }
            $list[] = $entry;
        }

        if (empty($list)) return null;

        return array(
            '@type'           => 'BreadcrumbList',
            '@id'             => $this->current_url() . '#breadcrumb',
            'itemListElement' => $list,
        );
    }

    /* =====================================================================
     * Helpers
     * ================================================================== */

    private function current_url() {
        if (is_singular()) {
            return (string) get_permalink(get_queried_object_id());
        }
        if (is_category() || is_tag() || is_tax()) {
            $url = get_term_link(get_queried_object());
            return is_wp_error($url) ? home_url('/') : $url;
        }
        if (is_author()) {
            return get_author_posts_url(get_queried_object_id());
        }
        return home_url('/');
    }

    private function context() {
        return array(
            'is_singular' => is_singular(),
            'is_archive'  => is_archive(),
            'is_home'     => is_home() || is_front_page(),
            'object_id'   => get_queried_object_id(),
        );
    }
}
