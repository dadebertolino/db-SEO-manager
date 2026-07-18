<?php
/**
 * DBSEO_AI_Robots — Controllo crawler AI + llms.txt.
 *
 * Due funzioni, entrambe opt-in:
 *  1. Blocco selettivo dei crawler AI noti via robots.txt (Disallow per-bot).
 *     Nessun bot è bloccato di default: è una scelta editoriale dell'admin.
 *  2. llms.txt virtuale (https://llmstxt.org): file informativo per gli LLM
 *     con nome sito, descrizione, pagine chiave e testo custom.
 *
 * Privacy: nessun dato utente coinvolto, tutto server-side statico.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_AI_Robots {

    /** @var DBSEO_AI_Robots|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('dbseo_robots_txt_output', array($this, 'append_ai_rules'), 10);
        add_action('parse_request', array($this, 'maybe_serve_llms_txt'), 1);
    }

    /**
     * Catalogo crawler AI noti (slug => label + user-agent).
     * Filtrabile per aggiungerne di nuovi senza aspettare un update.
     *
     * @return array
     */
    public static function bots() {
        return apply_filters('dbseo_ai_bots', array(
            'gptbot'          => array('label' => 'GPTBot (OpenAI — training)',           'ua' => 'GPTBot'),
            'oai-searchbot'   => array('label' => 'OAI-SearchBot (OpenAI — search)',      'ua' => 'OAI-SearchBot'),
            'chatgpt-user'    => array('label' => 'ChatGPT-User (OpenAI — browsing)',     'ua' => 'ChatGPT-User'),
            'claudebot'       => array('label' => 'ClaudeBot (Anthropic — training)',     'ua' => 'ClaudeBot'),
            'claude-user'     => array('label' => 'Claude-User (Anthropic — browsing)',   'ua' => 'Claude-User'),
            'google-extended' => array('label' => 'Google-Extended (Gemini — training)',  'ua' => 'Google-Extended'),
            'applebot-ext'    => array('label' => 'Applebot-Extended (Apple AI)',         'ua' => 'Applebot-Extended'),
            'perplexitybot'   => array('label' => 'PerplexityBot (Perplexity)',           'ua' => 'PerplexityBot'),
            'ccbot'           => array('label' => 'CCBot (Common Crawl)',                 'ua' => 'CCBot'),
            'bytespider'      => array('label' => 'Bytespider (ByteDance)',               'ua' => 'Bytespider'),
            'meta-external'   => array('label' => 'Meta-ExternalAgent (Meta AI)',         'ua' => 'Meta-ExternalAgent'),
            'amazonbot'       => array('label' => 'Amazonbot (Amazon)',                   'ua' => 'Amazonbot'),
        ));
    }

    /**
     * Appende i blocchi Disallow per i bot selezionati al robots.txt virtuale.
     *
     * @param string $output
     * @return string
     */
    public function append_ai_rules($output) {
        $blocked = (array) DBSEO_Core::instance()->get('ai_bots_block', array());
        if (empty($blocked)) return $output;

        $bots  = self::bots();
        $lines = array('', '# DB SEO Manager — crawler AI bloccati');
        foreach ($blocked as $slug) {
            if (!isset($bots[$slug])) continue;
            $lines[] = 'User-agent: ' . $bots[$slug]['ua'];
            $lines[] = 'Disallow: /';
        }

        if (count($lines) <= 2) return $output;
        return rtrim($output, "\n") . "\n" . implode("\n", $lines) . "\n";
    }

    /* =====================================================================
     * llms.txt virtuale
     * ================================================================== */

    /**
     * Serve /llms.txt se abilitato e se non esiste un file fisico.
     *
     * @param WP $wp
     */
    public function maybe_serve_llms_txt($wp) {
        if (!DBSEO_Core::instance()->get('llms_txt_enabled')) return;

        $path = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
        if ($path !== '/llms.txt') return;
        if (file_exists(ABSPATH . 'llms.txt')) return; // Il file fisico vince.

        header('Content-Type: text/plain; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo $this->build_llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput -- plain text costruito internamente.
        exit;
    }

    /**
     * Costruisce il contenuto llms.txt in formato Markdown (spec llmstxt.org).
     *
     * @return string
     */
    private function build_llms_txt() {
        $name = get_bloginfo('name');
        $desc = get_bloginfo('description');

        $out  = '# ' . $name . "\n\n";
        if ($desc !== '') {
            $out .= '> ' . $desc . "\n\n";
        }

        $custom = trim((string) DBSEO_Core::instance()->get('llms_txt_custom', ''));
        if ($custom !== '') {
            $out .= $custom . "\n\n";
        }

        // Pagine principali (escluse le noindex).
        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 20,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
        ));
        if ($pages) {
            $out .= "## Pagine\n\n";
            foreach ($pages as $p) {
                if (get_post_meta($p->ID, '_dbseo_robots_noindex', true)) continue;
                $out .= '- [' . $p->post_title . '](' . get_permalink($p) . ")\n";
            }
            $out .= "\n";
        }

        // Contenuti recenti.
        $posts = get_posts(array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
        ));
        if ($posts) {
            $out .= "## Articoli recenti\n\n";
            foreach ($posts as $p) {
                if (get_post_meta($p->ID, '_dbseo_robots_noindex', true)) continue;
                $out .= '- [' . $p->post_title . '](' . get_permalink($p) . ")\n";
            }
            $out .= "\n";
        }

        return apply_filters('dbseo_llms_txt_output', $out);
    }
}
