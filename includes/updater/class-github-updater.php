<?php

namespace GA4ServerSideTagging\Updater;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub-based plugin updater with secure configuration
 *
 * @package GA4ServerSideTagging\Updater
 */
class GitHub_Updater {

    /**
     * Plugin slug
     *
     * @var string
     */
    private $plugin_slug;

    /**
     * Current plugin version
     *
     * @var string
     */
    private $version;

    /**
     * Plugin file path
     *
     * @var string
     */
    private $plugin_file;

    /**
     * Plugin name
     *
     * @var string
     */
    private $plugin_name;

    /**
     * GitHub configuration
     *
     * @var array|false
     */
    private $config;

    /**
     * Transient cache key
     *
     * @var string
     */
    private $cache_key;

    /**
     * Cache duration in seconds
     *
     * @var int
     */
    private $cache_duration = 43200; // 12 hours

    /**
     * Constructor
     *
     * @param string $plugin_file Main plugin file path
     * @param string $version     Current version
     * @param string $plugin_name Plugin display name
     */
    public function __construct($plugin_file, $version, $plugin_name = '') {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->version = $version;
        $this->plugin_name = $plugin_name ?: 'GA4 Server-Side Tagging';
        $this->cache_key = 'ga4_github_updater_' . md5($this->plugin_slug);

        // Load configuration
        $this->config = Updater_Config::load_config();

        // Only initialize if properly configured and enabled
        if ($this->is_updater_active()) {
            $this->init_hooks();
        }
    }

    /**
     * Check if updater should be active
     *
     * @return bool True if updater should run
     */
    private function is_updater_active() {
        return $this->config &&
               !empty($this->config['username']) &&
               !empty($this->config['repo']) &&
               $this->config['enabled'];
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);

        // Add action links
        add_filter('plugin_action_links_' . $this->plugin_slug, array($this, 'plugin_action_links'));

        // Admin notices for configuration issues
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * Check for plugin updates
     *
     * @param object $transient WordPress update transient
     * @return object Modified transient
     */
    public function check_for_update($transient) {
        if (empty($transient->checked) || !$this->is_updater_active()) {
            return $transient;
        }

        $remote_version = $this->get_remote_version();

        if ($remote_version && version_compare($this->version, $remote_version, '<')) {
            $transient->response[$this->plugin_slug] = (object) array(
                'slug' => dirname($this->plugin_slug),
                'plugin' => $this->plugin_slug,
                'new_version' => $remote_version,
                'url' => $this->get_github_repo_url(),
                'package' => $this->get_download_url($remote_version),
                'tested' => '6.4',
                'requires_php' => '7.2',
                'compatibility' => new \stdClass()
            );

            // Log update available
            error_log("GA4 GitHub Updater: Update available - Current: {$this->version}, Remote: {$remote_version}");
        }

        return $transient;
    }

    /**
     * Show plugin information popup
     *
     * @param mixed  $response
     * @param string $action
     * @param object $args
     * @return object|mixed
     */
    public function plugin_popup($response, $action, $args) {
        if ('plugin_information' !== $action ||
            empty($args->slug) ||
            $args->slug !== dirname($this->plugin_slug) ||
            !$this->is_updater_active()) {
            return $response;
        }

        $remote_version = $this->get_remote_version();
        $changelog = $this->get_changelog();

        return (object) array(
            'slug' => $args->slug,
            'plugin_name' => $this->plugin_name,
            'version' => $remote_version,
            'author' => '<a href="https://jacht.digital/">Jacht Digital Marketing</a>',
            'homepage' => $this->get_github_repo_url(),
            'requires' => '5.2',
            'tested' => '6.4',
            'requires_php' => '7.2',
            'sections' => array(
                'Description' => $this->get_plugin_description(),
                'Changelog' => $changelog,
                'Installation' => $this->get_installation_instructions()
            ),
            'download_link' => $this->get_download_url($remote_version),
            'banners' => array(),
            'icons' => array()
        );
    }

    /**
     * Post-install cleanup
     *
     * @param array $response   Install response
     * @param array $hook_extra Hook extra data
     * @param array $result     Install result
     * @return array Modified result
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        if (!isset($hook_extra['plugin']) || $this->plugin_slug != $hook_extra['plugin']) {
            return $result;
        }

        $install_directory = plugin_dir_path($this->plugin_file);

        // Move files to correct directory
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;

        // Clean up
        if (isset($result['destination_name'])) {
            $wp_filesystem->delete($result['destination_name']);
        }

        // Reactivate plugin if it was active
        $active_plugins = get_option('active_plugins', array());
        if (in_array($this->plugin_slug, $active_plugins)) {
            activate_plugin($this->plugin_slug);
        }

        // Clear update caches
        $this->clear_cache();

        // Log successful update
        error_log("GA4 GitHub Updater: Successfully updated plugin to version {$this->get_remote_version()}");

        return $result;
    }

    /**
     * Add plugin action links
     *
     * @param array $links Existing action links
     * @return array Modified action links
     */
    public function plugin_action_links($links) {
        if (!$this->is_updater_active()) {
            return $links;
        }

        $check_update_link = sprintf(
            '<a href="#" onclick="wp.updates.checkPluginUpdates(); return false;">%s</a>',
            __('Check for Updates', 'ga4-server-side-tagging')
        );

        array_unshift($links, $check_update_link);
        return $links;
    }

    /**
     * Show admin notices for configuration issues
     */
    public function admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Check if configuration exists but is invalid
        if ($this->config === false) {
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>GA4 Server-Side Tagging:</strong> ';
            echo 'Auto-updates are not configured. Please configure GitHub repository settings in the plugin settings.';
            echo '</p></div>';
        }
    }

    /**
     * Get remote version from GitHub API
     *
     * @param bool $force_check Force API check, bypass cache
     * @return string|false Remote version or false on failure
     */
    private function get_remote_version($force_check = false) {
        if (!$this->is_updater_active()) {
            return false;
        }

        if (!$force_check) {
            $cached_version = get_transient($this->cache_key . '_version');
            if ($cached_version !== false) {
                return $cached_version;
            }
        }

        $remote_data = $this->get_remote_release_data();

        if ($remote_data && isset($remote_data['tag_name'])) {
            $version = $this->normalize_version($remote_data['tag_name']);
            set_transient($this->cache_key . '_version', $version, $this->cache_duration);
            return $version;
        }

        return false;
    }

    /**
     * Get remote release data from GitHub API
     *
     * @return array|false Release data or false on failure
     */
    private function get_remote_release_data() {
        if (!$this->is_updater_active()) {
            return false;
        }

        $api_url = $this->get_api_url();
        $headers = array(
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        );

        // Add authorization header if token is available
        if (!empty($this->config['token'])) {
            // Support both classic and fine-grained tokens
            if (strpos($this->config['token'], 'ghp_') === 0) {
                // Classic personal access token
                $headers['Authorization'] = 'token ' . $this->config['token'];
            } elseif (strpos($this->config['token'], 'github_pat_') === 0) {
                // Fine-grained personal access token
                $headers['Authorization'] = 'token ' . $this->config['token'];
            } else {
                // Fallback for older tokens
                $headers['Authorization'] = 'token ' . $this->config['token'];
            }
        }

        $request = wp_remote_get($api_url, array(
            'timeout' => 15,
            'headers' => $headers
        ));

        if (is_wp_error($request)) {
            error_log('GA4 GitHub Updater Error: ' . $request->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($request);

        if ($response_code !== 200) {
            error_log("GA4 GitHub Updater Error: HTTP {$response_code} from GitHub API");

            // Check for rate limiting
            if ($response_code === 403) {
                $rate_limit_remaining = wp_remote_retrieve_header($request, 'x-ratelimit-remaining');
                if ($rate_limit_remaining === '0') {
                    error_log('GA4 GitHub Updater: API rate limit exceeded. Consider adding a GitHub token.');
                }
            }

            return false;
        }

        $body = wp_remote_retrieve_body($request);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('GA4 GitHub Updater Error: Invalid JSON response from GitHub API');
            return false;
        }

        return $data;
    }

    /**
     * Get changelog from GitHub releases
     *
     * @return string Formatted changelog HTML
     */
    private function get_changelog() {
        if (!$this->is_updater_active()) {
            return '<p>Auto-updates not configured.</p>';
        }

        $cached_changelog = get_transient($this->cache_key . '_changelog');
        if ($cached_changelog !== false) {
            return $cached_changelog;
        }

        $api_url = "https://api.github.com/repos/{$this->config['username']}/{$this->config['repo']}/releases";
        $headers = array(
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        );

        if (!empty($this->config['token'])) {
            $headers['Authorization'] = 'token ' . $this->config['token'];
        }

        $request = wp_remote_get($api_url, array(
            'timeout' => 15,
            'headers' => $headers
        ));

        if (is_wp_error($request) || wp_remote_retrieve_response_code($request) !== 200) {
            return '<p>Changelog not available.</p>';
        }

        $body = wp_remote_retrieve_body($request);
        $releases = json_decode($body, true);

        if (!is_array($releases)) {
            return '<p>Changelog not available.</p>';
        }

        $changelog = '';
        $release_count = 0;

        foreach ($releases as $release) {
            if ($release_count >= 5) break; // Limit to 5 releases

            $version = $this->normalize_version($release['tag_name']);
            $date = date('F j, Y', strtotime($release['published_at']));
            $body = !empty($release['body']) ? $release['body'] : 'No release notes available.';

            $changelog .= "<h4>Version {$version} - {$date}</h4>";
            $changelog .= wpautop(wp_kses_post($body));

            $release_count++;
        }

        if (empty($changelog)) {
            $changelog = '<p>No changelog available.</p>';
        }

        set_transient($this->cache_key . '_changelog', $changelog, $this->cache_duration);
        return $changelog;
    }

    /**
     * Normalize version string
     *
     * @param string $version Raw version string
     * @return string Normalized version
     */
    private function normalize_version($version) {
        return ltrim($version, 'v'); // Remove 'v' prefix if present
    }

    /**
     * Get GitHub API URL for latest release
     *
     * @return string API URL
     */
    private function get_api_url() {
        return "https://api.github.com/repos/{$this->config['username']}/{$this->config['repo']}/releases/latest";
    }

    /**
     * Get GitHub repository URL
     *
     * @return string Repository URL
     */
    private function get_github_repo_url() {
        return "https://github.com/{$this->config['username']}/{$this->config['repo']}";
    }

    /**
     * Get download URL for specific version
     *
     * @param string $version Version to download
     * @return string Download URL
     */
    private function get_download_url($version) {
        return "https://github.com/{$this->config['username']}/{$this->config['repo']}/archive/refs/tags/v{$version}.zip";
    }

    /**
     * Get plugin description for popup
     *
     * @return string Plugin description
     */
    private function get_plugin_description() {
        return 'Server-side tagging system for GA4 with multiple transmission methods, fully compatible with WordPress and WooCommerce. Features include GDPR compliance, bot detection, event queuing, and comprehensive monitoring.';
    }

    /**
     * Get installation instructions
     *
     * @return string Installation instructions
     */
    private function get_installation_instructions() {
        return '<ol>
            <li>Backup your website before updating.</li>
            <li>Click "Update Now" to download and install the latest version.</li>
            <li>The plugin will be automatically activated after update.</li>
            <li>Verify your settings after the update is complete.</li>
        </ol>';
    }

    /**
     * Clear update cache
     */
    public function clear_cache() {
        delete_transient($this->cache_key . '_version');
        delete_transient($this->cache_key . '_changelog');
        delete_site_transient('update_plugins');
    }

    /**
     * Force update check
     *
     * @return string|false Latest version or false
     */
    public function force_update_check() {
        if (!$this->is_updater_active()) {
            return false;
        }

        $this->clear_cache();
        return $this->get_remote_version(true);
    }

    /**
     * Get current configuration status
     *
     * @return array Status information
     */
    public function get_status() {
        return array(
            'configured' => $this->config !== false,
            'enabled' => $this->is_updater_active(),
            'current_version' => $this->version,
            'remote_version' => $this->get_remote_version(),
            'repo_url' => $this->is_updater_active() ? $this->get_github_repo_url() : null
        );
    }
}