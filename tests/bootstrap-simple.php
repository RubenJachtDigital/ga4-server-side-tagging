<?php
/**
 * Simple PHPUnit bootstrap for GA4 Server-Side Tagging Tests
 * (Minimal setup - no WordPress or Brain Monkey dependencies)
 *
 * @package GA4_Server_Side_Tagging
 */

// Composer autoload for our dependencies
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Simple WordPress function mocks (no Brain Monkey)
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
    }
}

if (!function_exists('wp_json_decode')) {
    function wp_json_decode($json, $assoc = false) {
        return json_decode($json, $assoc);
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        static $options = array(
            'ga4_measurement_id' => 'G-TEST123',
            'ga4_api_secret' => 'test-secret',
            'ga4_jwt_encryption_enabled' => false,
            'ga4_jwt_encryption_key' => '', // Disabled for testing
            'ga4_cloudflare_worker_url' => 'https://test-worker.workers.dev/',
            'ga4_transmission_method' => 'wp_rest_endpoint',
            'ga4_disable_cf_proxy' => false,
            'ga4_disable_bot_detection' => true,
            'ga4_server_side_tagging_debug_mode' => false
        );
        return isset($options[$option]) ? $options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return false;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return time();
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url, $component);
    }
}

if (!function_exists('site_url')) {
    function site_url() {
        return 'https://example.com';
    }
}

if (!function_exists('session_id')) {
    function session_id() {
        return 'test_session_123';
    }
}

if (!function_exists('session_start')) {
    function session_start() {
        return true; // Mock session_start for testing
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array()) {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array('success' => true))
        );
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        return isset($response['response']['code']) ? $response['response']['code'] : 200;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return isset($response['body']) ? $response['body'] : '';
    }
}

// Mock WP_REST_Response class
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private $status;
        
        public function __construct($data = null, $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
        
        public function get_data() {
            return $this->data;
        }
        
        public function get_status() {
            return $this->status;
        }
    }
}

// Mock WP_REST_Request class
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $data;
        private $headers;
        private $method;
        private $route;
        
        public function __construct($method = 'GET', $route = '') {
            $this->method = $method;
            $this->route = $route;
            $this->data = array();
            $this->headers = array();
        }
        
        public function get_json_params() {
            return $this->data;
        }
        
        public function get_body() {
            return json_encode($this->data);
        }
        
        public function get_headers() {
            return $this->headers;
        }
        
        public function get_header($name) {
            $key = strtolower($name);
            return isset($this->headers[$key]) ? $this->headers[$key][0] : null;
        }
        
        public function get_method() {
            return $this->method;
        }
        
        public function get_route() {
            return $this->route;
        }
        
        public function get_query_params() {
            return array();
        }
        
        public function set_body($data) {
            $this->data = json_decode($data, true);
        }
        
        public function set_header($name, $value) {
            $key = strtolower($name);
            $this->headers[$key] = array($value);
        }
    }
}

// Mock wpdb class for database tests
class wpdb {
    public $prefix = 'wp_';
    
    public function prepare($query) {
        return $query;
    }
    
    public function get_results($query) {
        return array();
    }
    
    public function insert($table, $data) {
        return 1;
    }
    
    public function update($table, $data, $where) {
        return 1;
    }
    
    public function query($query) {
        return 1;
    }
    
    public function get_var($query) {
        return '0';
    }
    
    public function get_row($query) {
        return null;
    }
    
    public function get_charset_collate() {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}

// Global wpdb instance
global $wpdb;
$wpdb = new wpdb();

// Define constants
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('WPINC')) {
    define('WPINC', 'wp-includes');
}

if (!defined('GA4_TEST_MODE')) {
    define('GA4_TEST_MODE', true);
}

if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', false);
}

// Initialize global variables for testing
if (!isset($_SERVER)) {
    $_SERVER = array();
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test Browser)';

// Start session early to prevent header issues
@session_start();

if (!isset($_SESSION)) {
    $_SESSION = array();
}

// Mock WordPress dbDelta function
if (!function_exists('dbDelta')) {
    function dbDelta($queries) {
        return array('ga4_event_logs' => 'Created table ga4_event_logs');
    }
}

// Mock WordPress cron functions
if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled($hook, $args = array()) {
        return false; // No cron jobs scheduled for testing
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, $recurrence, $hook, $args = array()) {
        return true;
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook($hook, $args = array()) {
        return true;
    }
}

// Mock WordPress hook functions
if (!function_exists('add_action')) {
    function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('remove_action')) {
    function remove_action($hook_name, $callback, $priority = 10) {
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action($hook_name, ...$args) {
        return null;
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters($hook_name, $value, ...$args) {
        return $value;
    }
}

// Mock WordPress transient functions
if (!function_exists('get_transient')) {
    function get_transient($transient) {
        return false; // No transients in testing
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        return true;
    }
}