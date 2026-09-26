<?php
/**
 * DBSEO_WP_Privacy_Tools — Exporter ed eraser per WP Privacy Tools.
 *
 * Si aggancia ai filter del DB Privacy Hub (dbph_user_data_exporters /
 * dbph_user_data_erasers) per esporre i meta SEO autoriali ai diritti
 * di accesso (art. 15 GDPR) e cancellazione (art. 17 GDPR).
 *
 * Il plugin SEO Manager NON raccoglie dati personali degli utenti finali,
 * ma gli autori del blog possono aver scritto meta SEO custom sui propri
 * post (titolo SEO, descrizione, OpenGraph, ecc.).
 *
 *  - Accesso (art. 15): i meta SEO dei post dell'autore vengono esportati
 *    per trasparenza (sono collegati a un autore identificato).
 *  - Cancellazione (art. 17): i meta SEO dei post NON vengono cancellati.
 *    Sono contenuto editoriale del sito (titoli, descrizioni, canonical,
 *    direttive robots, hreflang…), non dati personali dell'autore: la loro
 *    cancellazione distruggerebbe la SEO delle pagine senza alcun beneficio
 *    per l'interessato. L'eraser li segnala come "conservati" con un
 *    messaggio esplicativo. Viene cancellato solo lo user meta del plugin
 *    collegato all'utente (preferenza UI della notice Privacy Hub).
 *
 * Compatibilità: se il DB Privacy Hub non è installato, gli hook non
 * scattano e questa classe è inerte. Per retrocompatibilità con
 * installazioni standalone (senza Hub) gli stessi exporter/eraser sono
 * registrati anche direttamente sui filter core di WordPress, così
 * funzionano comunque.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_WP_Privacy_Tools {

    /** @var DBSEO_WP_Privacy_Tools|null */
    private static $instance = null;

    /**
     * Lista delle chiavi meta SEO che possono essere personalizzate
     * sui singoli post da chi li scrive. Centralizzata qui per essere
     * unica fonte di verità tra exporter ed eraser.
     */
    const META_KEYS = array(
        '_dbseo_title'                 => 'SEO title',
        '_dbseo_description'           => 'SEO description',
        '_dbseo_canonical'             => 'Canonical URL',
        '_dbseo_focus_keyword'         => 'Focus keyword',
        '_dbseo_og_title'              => 'OpenGraph title',
        '_dbseo_og_description'        => 'OpenGraph description',
        '_dbseo_og_image_id'           => 'OpenGraph image ID',
        '_dbseo_twitter_title'         => 'Twitter title',
        '_dbseo_twitter_description'   => 'Twitter description',
        '_dbseo_twitter_image_id'      => 'Twitter image ID',
        '_dbseo_robots_noindex'        => 'Robots noindex',
        '_dbseo_robots_nofollow'       => 'Robots nofollow',
        '_dbseo_score'                 => 'Score',
        '_dbseo_cornerstone'           => 'Cornerstone',
        '_dbseo_schema_type'           => 'Schema type',
        '_dbseo_hreflang'              => 'Hreflang',
    );

    /** User meta del plugin collegati all'utente (non editoriali). */
    const USER_META_KEYS = array(
        'dbseo_privacy_hub_notice_dismissed',
    );

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Canale primario: filter del Privacy Hub (preferito).
        add_filter('dbph_user_data_exporters', array($this, 'register_exporter_via_hub'));
        add_filter('dbph_user_data_erasers',   array($this, 'register_eraser_via_hub'));

        // Fallback: se il Privacy Hub non è installato, registra direttamente
        // sui filter core di WordPress per non perdere la conformità.
        add_filter('wp_privacy_personal_data_exporters', array($this, 'register_exporter_fallback'));
        add_filter('wp_privacy_personal_data_erasers',   array($this, 'register_eraser_fallback'));
    }

    /* =====================================================================
     * Registrazione via Privacy Hub
     * ================================================================== */

    public function register_exporter_via_hub($exporters) {
        $exporters['db-seo-manager'] = array(
            'label'    => __('DB SEO Manager (meta SEO autoriali)', 'db-seo-manager'),
            'callback' => array($this, 'export_user_data'),
        );
        return $exporters;
    }

    public function register_eraser_via_hub($erasers) {
        $erasers['db-seo-manager'] = array(
            'label'    => __('DB SEO Manager (meta SEO autoriali)', 'db-seo-manager'),
            'callback' => array($this, 'erase_user_data'),
        );
        return $erasers;
    }

    /* =====================================================================
     * Fallback diretto sui filter core (solo se Privacy Hub assente)
     * ================================================================== */

    public function register_exporter_fallback($exporters) {
        // Se l'Hub è installato, è già lui a registrare l'exporter via
        // dbph_user_data_exporters → wp_privacy_personal_data_exporters.
        // Evitiamo il doppio registro.
        if (class_exists('DBPH_DSAR')) {
            return $exporters;
        }
        $exporters['db-seo-manager'] = array(
            'exporter_friendly_name' => __('DB SEO Manager', 'db-seo-manager'),
            'callback'               => array($this, 'export_user_data'),
        );
        return $exporters;
    }

    public function register_eraser_fallback($erasers) {
        if (class_exists('DBPH_DSAR')) {
            return $erasers;
        }
        $erasers['db-seo-manager'] = array(
            'eraser_friendly_name' => __('DB SEO Manager', 'db-seo-manager'),
            'callback'             => array($this, 'erase_user_data'),
        );
        return $erasers;
    }

    /* =====================================================================
     * Exporter — diritto di accesso (art. 15 GDPR)
     * ================================================================== */

    /**
     * Esporta i meta SEO custom sui post di un utente.
     *
     * @param string $email_address
     * @param int    $page
     * @return array
     */
    public function export_user_data($email_address, $page = 1) {
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return array('data' => array(), 'done' => true);
        }

        $per_page = 50;
        $page     = max(1, (int) $page);
        $posts    = get_posts(array(
            'author'         => $user->ID,
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_type'      => 'any',
            'post_status'    => 'any',
        ));

        $data = array();
        foreach ($posts as $post) {
            $items = array();
            foreach (self::META_KEYS as $key => $label) {
                $val = get_post_meta($post->ID, $key, true);
                if ($val !== '' && $val !== null) {
                    $items[] = array('name' => $label, 'value' => is_scalar($val) ? (string) $val : wp_json_encode($val));
                }
            }
            if (!empty($items)) {
                array_unshift($items, array(
                    'name'  => __('Post', 'db-seo-manager'),
                    'value' => get_permalink($post->ID),
                ));
                $data[] = array(
                    'group_id'    => 'db-seo-manager-post-meta',
                    'group_label' => __('Metadati SEO autore', 'db-seo-manager'),
                    'item_id'     => 'post-' . $post->ID,
                    'data'        => $items,
                );
            }
        }

        return array(
            'data' => $data,
            'done' => count($posts) < $per_page,
        );
    }

    /* =====================================================================
     * Eraser — diritto alla cancellazione (art. 17 GDPR)
     * ================================================================== */

    /**
     * Diritto alla cancellazione per un utente.
     *
     * - Meta SEO dei post: CONSERVATI (contenuto editoriale, non dati
     *   personali); segnalati con items_retained e messaggio.
     * - User meta del plugin: cancellati.
     *
     * @param string $email_address
     * @param int    $page
     * @return array
     */
    public function erase_user_data($email_address, $page = 1) {
        $user = get_user_by('email', $email_address);
        if (!$user) {
            return array(
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => array(),
                'done'           => true,
            );
        }

        $removed = false;
        foreach (self::USER_META_KEYS as $key) {
            if (metadata_exists('user', $user->ID, $key)) {
                delete_user_meta($user->ID, $key);
                $removed = true;
            }
        }

        // Esiste almeno un post dell'autore con meta SEO personalizzati?
        $meta_query = array('relation' => 'OR');
        foreach (array_keys(self::META_KEYS) as $key) {
            $meta_query[] = array('key' => $key, 'compare' => 'EXISTS');
        }
        $with_meta = get_posts(array(
            'author'           => $user->ID,
            'posts_per_page'   => 1,
            'post_type'        => 'any',
            'post_status'      => 'any',
            'fields'           => 'ids',
            'no_found_rows'    => true,
            'suppress_filters' => true,
            'meta_query'       => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        ));

        $messages = array();
        $retained = !empty($with_meta);
        if ($retained) {
            $messages[] = __('DB SEO Manager: i metadati SEO dei contenuti pubblicati dall\'utente (titolo SEO, descrizione, canonical, OpenGraph, direttive robots, hreflang…) sono stati conservati: sono contenuto editoriale del sito, non dati personali dell\'autore, e la loro cancellazione comprometterebbe l\'indicizzazione delle pagine. Se contengono dati personali, modificarli manualmente dall\'editor.', 'db-seo-manager');
        }

        return array(
            'items_removed'  => $removed,
            'items_retained' => $retained,
            'messages'       => $messages,
            'done'           => true,
        );
    }
}
