<?php
/**
 * Uninstall handler for DB SEO Manager.
 *
 * Eseguito SOLO quando l'utente disinstalla esplicitamente il plugin
 * (non durante la disattivazione). Rimuove tutti i dati persistenti
 * per essere coerente con il principio GDPR di minimizzazione e
 * limitazione della conservazione.
 *
 * @package DB_SEO_Manager
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

/* -------------------------------------------------------------------------
 * Tabelle custom
 * ---------------------------------------------------------------------- */
$tables = array(
    $wpdb->prefix . 'dbseo_redirects',
    $wpdb->prefix . 'dbseo_404_log',
    $wpdb->prefix . 'dbseo_indexnow_queue',
);
foreach ($tables as $tbl) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS `{$tbl}`");
}

/* -------------------------------------------------------------------------
 * Opzioni
 * ---------------------------------------------------------------------- */
delete_option('dbseo_settings');
delete_site_option('dbseo_settings');

/* -------------------------------------------------------------------------
 * Meta su post / term
 * ---------------------------------------------------------------------- */
$meta_keys = array(
    '_dbseo_title',
    '_dbseo_description',
    '_dbseo_canonical',
    '_dbseo_robots_noindex',
    '_dbseo_robots_nofollow',
    '_dbseo_og_title',
    '_dbseo_og_description',
    '_dbseo_og_image_id',
    '_dbseo_twitter_title',
    '_dbseo_twitter_description',
    '_dbseo_twitter_image_id',
    '_dbseo_focus_keyword',
    '_dbseo_score',
    '_dbseo_cornerstone',
    '_dbseo_schema_type',
);
foreach ($meta_keys as $key) {
    delete_post_meta_by_key($key);
    // Term meta: WP non offre helper bulk, quindi DELETE diretta.
    $wpdb->delete($wpdb->termmeta, array('meta_key' => $key));
}

/* -------------------------------------------------------------------------
 * Transients
 * ---------------------------------------------------------------------- */
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE '\\_transient\\_dbseo\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_dbseo\\_%'
        OR option_name LIKE '\\_transient\\_dbgu\\_%'
        OR option_name LIKE '\\_transient\\_timeout\\_dbgu\\_%'"
);

/* -------------------------------------------------------------------------
 * User meta (admin notice dismissals)
 * ---------------------------------------------------------------------- */
$wpdb->delete($wpdb->usermeta, array('meta_key' => 'dbseo_privacy_hub_notice_dismissed'));

/* -------------------------------------------------------------------------
 * Cron events
 * ---------------------------------------------------------------------- */
wp_clear_scheduled_hook('dbseo_cron_broken_links');
wp_clear_scheduled_hook('dbseo_cron_indexnow_flush');
wp_clear_scheduled_hook('dbseo_cron_404_cleanup');
