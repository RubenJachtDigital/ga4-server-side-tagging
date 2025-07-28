<?php
/**
 * Native PHPUnit bootstrap for GA4 Server-Side Tagging Tests
 * (No WordPress mocking libraries - pure PHP testing)
 *
 * @package GA4_Server_Side_Tagging
 */

// Composer autoload for our dependencies
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Define constants first
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('GA4_TEST_MODE')) {
    define('GA4_TEST_MODE', true);
}

// Pure PHP WordPress function mocks (no Brain Monkey or Patchwork)
function wp_json_encode($data) {
    return json_encode($data);
}

function wp_json_decode($json, $assoc = false) {
    return json_decode($json, $assoc);
}

function get_option($option, $default = false) {
    static $options = array(
        'ga4_measurement_id' => 'G-TEST123',
        'ga4_api_secret' => 'test-secret',
        'ga4_jwt_encryption_enabled' => false,
        'ga4_jwt_encryption_key' => 'test-key-123456789'
    );
    return isset($options[$option]) ? $options[$option] : $default;
}

function update_option($option, $value) {
    return true;
}

function sanitize_text_field($str) {
    return trim(strip_tags($str));
}

function wp_unslash($value) {
    return is_string($value) ? stripslashes($value) : $value;
}

function is_wp_error($thing) {
    return false;
}

function current_time($type, $gmt = 0) {
    return time();
}

function wp_remote_post($url, $args = array()) {
    return array(
        'response' => array('code' => 200),
        'body' => json_encode(array('success' => true))
    );
}

function wp_remote_get($url, $args = array()) {
    return array(
        'response' => array('code' => 200),
        'body' => json_encode(array('success' => true))
    );
}

function wp_remote_retrieve_response_code($response) {
    return isset($response['response']['code']) ? $response['response']['code'] : 200;
}

function wp_remote_retrieve_body($response) {
    return isset($response['body']) ? $response['body'] : '';
}

// Simple wpdb mock class
class wpdb {
    public $prefix = 'wp_';
    public $last_insert_id = 1;
    
    public function prepare($query, ...$args) {
        return $query;
    }
    
    public function get_results($query, $output = OBJECT) {
        return array(
            (object) array(
                'id' => 1,
                'event_name' => 'test_event',
                'monitor_status' => 'allowed',
                'queue_status' => 'completed',
                'created_at' => date('Y-m-d H:i:s')
            )
        );
    }
    
    public function insert($table, $data, $format = null) {
        $this->last_insert_id++;
        return 1;
    }
    
    public function update($table, $data, $where, $format = null, $where_format = null) {
        return 1;
    }
    
    public function query($query) {
        return 1;
    }
    
    public function get_var($query, $col_offset = 0, $row_offset = 0) {
        return '0';
    }
    
    public function get_row($query, $output = OBJECT, $y = 0) {
        return (object) array('count' => 10);
    }
}

// Global wpdb instance
global $wpdb;
$wpdb = new wpdb();

// Define OBJECT constant for wpdb
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}