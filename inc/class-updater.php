<?php
/**
 * DB GitHub Updater
 * Controlla aggiornamenti plugin da GitHub Releases.
 * Riusabile: copia questo file in inc/ di qualsiasi plugin.
 *
 * Uso:
 *   require_once PLUGIN_DIR . 'inc/class-updater.php';
 *   new DB_GitHub_Updater(__FILE__, 'dadebertolino', 'nome-repo');
 *
 * @version 1.0.0
 * @author Davide Bertolino
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('DB_GitHub_Updater')) {

class DB_GitHub_Updater {

    private $file;
    private $plugin_slug;
    private $plugin_basename;
    private $github_user;
    private $github_repo;
    private $cache_key;
    private $cache_expiry = 43200; // 12 ore

    /**
     * @param string $plugin_file  __FILE__ del file principale del plugin
     * @param string $github_user  Username/org GitHub (es. 'dadebertolino')
     * @param string $github_repo  Nome repository (es. 'db-event-manager')
     */
    public function __construct($plugin_file, $github_user, $github_repo) {
        $this->file            = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->plugin_slug     = dirname($this->plugin_basename);
        $this->github_user     = $github_user;
        $this->github_repo     = $github_repo;
        $this->cache_key       = 'dbgu_' . md5($this->plugin_basename);

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);
    }

    private function get_release_data() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) return $cached;

        $url = sprintf(
            'https://api.github.com/repos/%s/%s/releases/latest',
            $this->github_user,
            $this->github_repo
        );

        $response = wp_remote_get($url, array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
            ),
            'timeout' => 10,
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            set_transient($this->cache_key, null, 3600);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        if (!$data || empty($data->tag_name)) return null;

        $release = array(
            'version'     => ltrim($data->tag_name, 'vV'),
            'zip_url'     => '',
            'description' => $data->body ?? '',
            'published'   => $data->published_at ?? '',
            'html_url'    => $data->html_url ?? '',
        );

        if (!empty($data->assets)) {
            foreach ($data->assets as $asset) {
                if (substr($asset->name, -4) === '.zip') {
                    $release['zip_url'] = $asset->browser_download_url;
                    break;
                }
            }
        }
        if (empty($release['zip_url'])) {
            $release['zip_url'] = $data->zipball_url;
        }

        set_transient($this->cache_key, $release, $this->cache_expiry);
        return $release;
    }

    public function check_update($transient) {
        if (empty($transient->checked)) return $transient;

        $release = $this->get_release_data();
        if (!$release || empty($release['version']) || empty($release['zip_url'])) return $transient;

        $current_version = $transient->checked[$this->plugin_basename] ?? '';
        if (!$current_version) {
            $plugin_data = get_plugin_data($this->file);
            $current_version = $plugin_data['Version'] ?? '0';
        }

        if (version_compare($release['version'], $current_version, '>')) {
            $transient->response[$this->plugin_basename] = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $release['version'],
                'package'     => $release['zip_url'],
                'url'         => sprintf('https://github.com/%s/%s', $this->github_user, $this->github_repo),
            );
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->plugin_slug) {
            return $result;
        }

        $release = $this->get_release_data();
        if (!$release) return $result;

        $plugin_data = get_plugin_data($this->file);

        return (object) array(
            'name'          => $plugin_data['Name'] ?? $this->github_repo,
            'slug'          => $this->plugin_slug,
            'version'       => $release['version'],
            'author'        => $plugin_data['Author'] ?? '',
            'homepage'      => $plugin_data['PluginURI'] ?? '',
            'download_link' => $release['zip_url'],
            'requires'      => $plugin_data['RequiresWP'] ?? '5.8',
            'requires_php'  => $plugin_data['RequiresPHP'] ?? '7.4',
            'tested'        => get_bloginfo('version'),
            'sections'      => array(
                'description' => $plugin_data['Description'] ?? '',
                'changelog'   => nl2br(esc_html($release['description'])),
            ),
            'last_updated'  => $release['published'],
        );
    }

    public function post_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }

        global $wp_filesystem;
        $install_dir = $result['destination'];
        $proper_dir  = WP_PLUGIN_DIR . '/' . $this->plugin_slug;

        if ($install_dir !== $proper_dir) {
            $wp_filesystem->move($install_dir, $proper_dir);
            $result['destination'] = $proper_dir;
        }

        activate_plugin($this->plugin_basename);
        return $result;
    }
}

} // end if !class_exists
