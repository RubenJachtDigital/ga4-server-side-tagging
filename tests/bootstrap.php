<?php
/**
 * PHPUnit bootstrap file for GA4 Server-Side Tagging plugin tests
 *
 * @package GA4_Server_Side_Tagging
 */

// Load Composer autoloader
$composer_autoload = dirname(dirname(__FILE__)) . '/vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

// Load PHPUnit polyfills for compatibility
if (class_exists('Yoast\PHPUnitPolyfills\Autoload')) {
    // Polyfills are automatically loaded via Composer
}

// Compatibility with PHPUnit 6+
if (class_exists('PHPUnit\Runner\Version')) {
    require_once dirname(dirname(__FILE__)) . '/tests/phpunit6-compat.php';
}

$_tests_dir = getenv('WP_TESTS_DIR');

if (! $_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\') . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv('WP_TESTS_PHPUNIT_POLYFILLS_PATH');
if (false !== $_phpunit_polyfills_path) {
    define('WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path);
}

if (! file_exists("{$_tests_dir}/includes/functions.php")) {
    echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    exit(1);
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin being tested.
 */
function _manually_load_plugin()
{
    require dirname(dirname(__FILE__)) . '/ga4-server-side-tagging.php';
    
    // Set up test environment
    define('GA4_TEST_MODE', true);
    define('GA4_DISABLE_BOT_DETECTION', true);
    
    // Set default test options
    add_option('ga4_measurement_id', 'G-TEST123');
    add_option('ga4_api_secret', 'test-api-secret');
    add_option('ga4_cloudflare_worker_url', 'https://test-worker.workers.dev/');
    add_option('ga4_transmission_method', 'wp_rest_endpoint');
    add_option('ga4_disable_cf_proxy', false);
    add_option('ga4_jwt_encryption_enabled', false);
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

// Include test utilities
require_once dirname(__FILE__) . '/test-utilities.php';
