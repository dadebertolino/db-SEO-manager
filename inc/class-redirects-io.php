<?php
/**
 * DBSEO_Redirects_IO — Import/Export CSV delle regole di redirect.
 *
 * Formato CSV (header obbligatorio): source,target,type,is_regex
 *  - source: path relativo (/vecchia-pagina) o URL assoluta (viene normalizzata)
 *  - target: path relativo o URL assoluta (vuoto solo per type 410)
 *  - type: 301|302|307|410
 *  - is_regex: 0|1
 *
 * L'import è idempotente: le origini già esistenti vengono saltate.
 *
 * @package DB_SEO_Manager
 */

if (!defined('ABSPATH')) exit;

class DBSEO_Redirects_IO {

    /** @var DBSEO_Redirects_IO|null */
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_dbseo_redirects_export', array($this, 'handle_export'));
        add_action('admin_post_dbseo_redirects_import', array($this, 'handle_import'));
    }

    /* =====================================================================
     * Export
     * ================================================================== */

    public function handle_export() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('dbseo_redirects_io');

        $module = DBSEO_Core::instance()->module('redirects');
        $rules  = $module ? $module->get_all(10000) : array();

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="dbseo-redirects-' . gmdate('Ymd-His') . '.csv"');

        $fh = fopen('php://output', 'w');
        fputcsv($fh, array('source', 'target', 'type', 'is_regex'));
        foreach ($rules as $r) {
            fputcsv($fh, array($r->source, $r->target, (int) $r->type, (int) $r->is_regex));
        }
        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions
        exit;
    }

    /* =====================================================================
     * Import
     * ================================================================== */

    public function handle_import() {
        if (!current_user_can('manage_options')) wp_die('forbidden');
        check_admin_referer('dbseo_redirects_io');

        $redirect = wp_get_referer() ?: admin_url('admin.php?page=dbseo-redirects');

        if (empty($_FILES['dbseo_csv']['tmp_name']) || !is_uploaded_file($_FILES['dbseo_csv']['tmp_name'])) {
            wp_safe_redirect(add_query_arg('dbseo_msg', 'import_no_file', $redirect));
            exit;
        }

        $module = DBSEO_Core::instance()->module('redirects');
        if (!$module) {
            wp_safe_redirect(add_query_arg('dbseo_msg', 'redirect_error', $redirect));
            exit;
        }

        $fh = fopen($_FILES['dbseo_csv']['tmp_name'], 'r'); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if (!$fh) {
            wp_safe_redirect(add_query_arg('dbseo_msg', 'redirect_error', $redirect));
            exit;
        }

        $header   = fgetcsv($fh);
        $imported = 0;
        $skipped  = 0;
        $max_rows = 5000;

        while (($row = fgetcsv($fh)) !== false && ($imported + $skipped) < $max_rows) {
            if (count($row) < 2) { $skipped++; continue; }

            $source   = sanitize_text_field((string) $row[0]);
            $target   = sanitize_text_field((string) ($row[1] ?? ''));
            $type     = isset($row[2]) ? (int) $row[2] : 301;
            $is_regex = !empty($row[3]);

            $r = $module->add_rule($source, $target, $type, $is_regex);
            if (is_wp_error($r)) {
                $skipped++;   // Duplicati o righe non valide.
            } else {
                $imported++;
            }
        }
        fclose($fh); // phpcs:ignore WordPress.WP.AlternativeFunctions

        wp_safe_redirect(add_query_arg(array(
            'dbseo_msg'      => 'import_done',
            'dbseo_imported' => $imported,
            'dbseo_skipped'  => $skipped,
        ), $redirect));
        exit;
    }
}
