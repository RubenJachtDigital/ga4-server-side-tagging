<?php

namespace GA4ServerSideTagging\Updater;

use GA4ServerSideTagging\Utilities\GA4_Encryption_Util;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Secure configuration manager for GitHub updater
 *
 * @package GA4ServerSideTagging\Updater
 */
class Updater_Config {

    /**
     * Option names for encrypted storage
     */
    public const GITHUB_USERNAME_OPTION = 'ga4_github_username';
    public const GITHUB_REPO_OPTION = 'ga4_github_repo';
    public const GITHUB_TOKEN_OPTION = 'ga4_github_token';
    public const UPDATER_ENABLED_OPTION = 'ga4_updater_enabled';

    /**
     * Load configuration from .env file and environment variables only
     * Priority: .env file > environment variables
     *
     * @return array|false Configuration array or false if invalid
     */
    public static function load_config() {
        $config = array();

        // 1. Load from .env file first
        $env_config = self::load_from_env_file();
        $config = array_merge($config, $env_config);

        // 2. Fallback to environment variables
        if (empty($config['username'])) {
            $config['username'] = getenv('GITHUB_USERNAME') ?: $_ENV['GITHUB_USERNAME'] ?? '';
        }
        if (empty($config['repo'])) {
            $config['repo'] = getenv('GITHUB_REPO') ?: $_ENV['GITHUB_REPO'] ?? '';
        }
        if (empty($config['token'])) {
            $config['token'] = getenv('GITHUB_TOKEN') ?: $_ENV['GITHUB_TOKEN'] ?? '';
        }

        // Auto-updates are enabled if configuration is complete
        $config['enabled'] = !empty($config['username']) && !empty($config['repo']);

        // Validate required fields
        if (empty($config['username']) || empty($config['repo'])) {
            return false;
        }

        return $config;
    }


    /**
     * Load configuration from .env file
     *
     * @return array Configuration from .env file
     */
    private static function load_from_env_file() {
        $config = array();
        $env_file = GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . '.env';

        if (!file_exists($env_file)) {
            return $config;
        }

        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Parse KEY=VALUE
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value, ' "\'');

                switch ($key) {
                    case 'GITHUB_USERNAME':
                        $config['username'] = $value;
                        break;
                    case 'GITHUB_REPO':
                        $config['repo'] = $value;
                        break;
                    case 'GITHUB_TOKEN':
                        $config['token'] = $value;
                        break;
                }
            }
        }

        return $config;
    }


    /**
     * Test configuration validity
     *
     * @param array $config Configuration to test
     * @return array Test results
     */
    public static function test_config($config = null) {
        if ($config === null) {
            $config = self::load_config();
        }

        $results = array(
            'valid' => false,
            'errors' => array(),
            'warnings' => array()
        );

        // Check required fields
        if (empty($config['username'])) {
            $results['errors'][] = 'GitHub username is required';
        }

        if (empty($config['repo'])) {
            $results['errors'][] = 'GitHub repository name is required';
        }

        if (!empty($results['errors'])) {
            return $results;
        }

        // Test GitHub API connectivity
        $api_url = "https://api.github.com/repos/{$config['username']}/{$config['repo']}";
        $headers = array(
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        );

        if (!empty($config['token'])) {
            $headers['Authorization'] = 'token ' . $config['token'];
        }

        $response = wp_remote_get($api_url, array(
            'timeout' => 15,
            'headers' => $headers
        ));

        if (is_wp_error($response)) {
            $results['errors'][] = 'Failed to connect to GitHub: ' . $response->get_error_message();
            return $results;
        }

        $response_code = wp_remote_retrieve_response_code($response);

        switch ($response_code) {
            case 200:
                $results['valid'] = true;
                break;
            case 404:
                $results['errors'][] = 'Repository not found. Please check username and repository name.';
                break;
            case 403:
                $results['warnings'][] = 'API rate limit reached. Consider adding a GitHub token.';
                break;
            default:
                $results['errors'][] = "GitHub API returned status code: {$response_code}";
        }

        // Check for GitHub token
        if (empty($config['token'])) {
            $results['warnings'][] = 'No GitHub token provided. API rate limits may apply.';
        }

        return $results;
    }

    /**
     * Get default configuration
     *
     * @return array Default configuration
     */
    public static function get_defaults() {
        return array(
            'username' => '',
            'repo' => 'ga4-server-side-tagging',
            'token' => '',
            'enabled' => false
        );
    }

    /**
     * Check if updater is properly configured
     *
     * @return bool True if configured
     */
    public static function is_configured() {
        $config = self::load_config();
        return $config && !empty($config['username']) && !empty($config['repo']);
    }

    /**
     * Check if updater is enabled
     *
     * @return bool True if enabled
     */
    public static function is_enabled() {
        $config = self::load_config();
        return $config && $config['enabled'] && self::is_configured();
    }

}