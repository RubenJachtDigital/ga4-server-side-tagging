<?php
/**
 * Standalone PHPUnit bootstrap file for GA4 Server-Side Tagging Tests
 * (No WordPress dependencies required)
 *
 * @package GA4_Server_Side_Tagging
 */

// Composer autoload for our dependencies
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Initialize Brain Monkey for WordPress function mocking (standalone mode)
\Brain\Monkey\setUp();

// Mock essential WordPress functions for standalone testing
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data)
    {
        return json_encode($data);
    }
}

if (!function_exists('wp_json_decode')) {
    function wp_json_decode($json, $assoc = false)
    {
        return json_decode($json, $assoc);
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false)
    {
        return $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value)
    {
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return trim(strip_tags($str));
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value)
    {
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return false;
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0)
    {
        return time();
    }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = array())
    {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array('success' => true))
        );
    }
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array())
    {
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array('success' => true))
        );
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        return isset($response['response']['code']) ? $response['response']['code'] : 200;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        return isset($response['body']) ? $response['body'] : '';
    }
}

// Constants for testing
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

if (!defined('GA4_TEST_MODE')) {
    define('GA4_TEST_MODE', true);
}

// Load test utilities
if (file_exists(__DIR__ . '/test-utilities.php')) {
    require_once __DIR__ . '/test-utilities.php';
}