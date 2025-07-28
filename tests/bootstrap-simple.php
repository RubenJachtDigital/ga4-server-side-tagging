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
            'ga4_jwt_encryption_enabled' => false
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