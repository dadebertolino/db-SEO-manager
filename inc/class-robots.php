<?php
/**
 * DBSEO_Robots — robots.txt virtuale.
 *
 * Sostituisce il robots.txt virtuale di WordPress con una versione che:
 *  - Mantiene Disallow: /wp-admin/ + Allow: /wp-admin/admin-ajax.php (sicurezza/funzionalità).
 *  - Aggiunge Disallow per /?s= e /search/ se la search è noindex (default true).
 *  - Aggiunge Sitemap: <home>/sitemap.xml se il modulo sitemap è attivo.
 *  - Concatena le direttive custom dell'admin (settings: robots_txt_custom).
 *
 * Funziona SOLO se non esiste un robots.txt fisico nella root.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Robots {

    /** @var DBSEO_Robots|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter('robots_txt', array($this, 'filter_robots_txt'), 20, 2);
    }

    /**
     * @param string $output  robots.txt generato da WP
     * @param bool   $public  blog_public (Reading → Search engine visibility)
     */
    public function filter_robots_txt($output, $public) {
        $core = DBSEO_Core::instance();

        // Se il sito è impostato come "scoraggia i motori di ricerca", WP genera
        // già un Disallow: / globale. Non interferiamo.
        if (!$public) {
            return $output;
        }

        $home = trailingslashit(home_url('/'));

        // Ricostruiamo da zero per avere pieno controllo dell'output.
        $lines = array();
        $lines[] = 'User-agent: *';
        $lines[] = 'Disallow: /wp-admin/';
        $lines[] = 'Allow: /wp-admin/admin-ajax.php';

        // Search results: tipicamente noindex e disallow è coerente.
        if ($core->get('noindex_search')) {
            $lines[] = 'Disallow: /?s=';
            $lines[] = 'Disallow: /search/';
        }

        // Custom rules dall'admin (linea per linea).
        $custom = (string) $core->get('robots_txt_custom', '');
        if ($custom !== '') {
            $lines[] = '';
            $custom_lines = preg_split('/\r\n|\r|\n/', $custom);
            foreach ($custom_lines as $cl) {
                $cl = trim($cl);
                if ($cl === '') continue;
                $lines[] = $cl;
            }
        }

        // Sitemap reference.
        if ($core->get('sitemap_enabled')) {
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $home . 'sitemap.xml';
        }

        $output = implode("\n", $lines) . "\n";
        return apply_filters('dbseo_robots_txt_output', $output);
    }
}
