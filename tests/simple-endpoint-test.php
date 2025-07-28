<?php
/**
 * Simple endpoint test without dependencies
 * Tests the GA4 endpoint with the provided request data
 */

// Mock WordPress functions first
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        $options = array(
            'ga4_measurement_id' => 'G-TEST123',
            'ga4_api_secret' => 'test-api-secret',
            'ga4_cloudflare_worker_url' => 'https://test-worker.workers.dev/',
            'ga4_transmission_method' => 'wp_rest_endpoint',
            'ga4_disable_cf_proxy' => false,
            'ga4_jwt_encryption_enabled' => false,
            'ga4_disable_bot_detection' => true,
            'ga4_server_side_tagging_debug_mode' => false
        );
        return isset($options[$option]) ? $options[$option] : $default;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) {
        return json_encode($data);
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

if (!function_exists('current_time')) {
    function current_time($type) {
        return date($type);
    }
}

if (!function_exists('session_id')) {
    function session_id() {
        return 'test_session_123';
    }
}

if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', false);
}

// Mock global variables
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Test Browser)';
$_SESSION = array();

// Load classes with namespace compatibility
require_once dirname(__DIR__) . '/includes/class-ga4-server-side-tagging-logger.php';

// Simple mock classes
class MockLogger extends GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger {
    public function __construct() {
        // Don't call parent constructor
    }
    
    public function log($level, $message, $context = array()) {
        echo "[LOG {$level}] {$message}\n";
    }
    
    public function info($message, $context = array()) {
        $this->log('INFO', $message, $context);
    }
    
    public function warning($message, $context = array()) {
        $this->log('WARNING', $message, $context);
    }
    
    public function error($message, $context = array()) {
        $this->log('ERROR', $message, $context);
    }
    
    public function bot_detected($message, $context = array()) {
        $this->log('BOT', $message, $context);
    }
    
    public function log_data($data, $label = 'Data') {
        echo "[DATA {$label}] " . json_encode($data) . "\n";
    }
}

class MockEventLogger {
    public function create_event_record($event_data, $monitor_status, $headers, $was_encrypted, $additional_data) {
        echo "[EVENT] Status: {$monitor_status}, Event: " . ($additional_data['event_name'] ?? 'unknown') . "\n";
        echo "[EVENT] Data: " . json_encode($event_data) . "\n";
        return rand(100, 999); // Mock event ID
    }
    
    public function maybe_create_table() {
        return true;
    }
}

class MockWPRestRequest {
    private $data;
    private $headers;
    
    public function __construct($data, $headers = array()) {
        $this->data = $data;
        $this->headers = array_merge(array(
            'content-type' => 'application/json',
            'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
            'accept-language' => 'nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7',
            'accept' => '*/*',
            'referer' => 'https://compuact-staging.jachtdigital.dev/',
            'x-forwarded-for' => '37.17.209.130',
            'x-real-ip' => '37.17.209.130'
        ), $headers);
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
        return isset($this->headers[$key]) ? $this->headers[$key] : null;
    }
    
    public function get_method() {
        return 'POST';
    }
    
    public function get_route() {
        return '/wp-json/ga4-server-side-tagging/v1/send-events';
    }
    
    public function get_query_params() {
        return array();
    }
}

class MockWPRestResponse {
    private $data;
    private $status;
    
    public function __construct($data, $status = 200) {
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

// Mock WP_REST_Response class in global namespace
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

// Load and modify the endpoint class
require_once dirname(__DIR__) . '/includes/class-ga4-server-side-tagging-endpoint.php';

// Create a test endpoint class that uses our mocks
class TestEndpoint extends GA4ServerSideTagging\API\GA4_Server_Side_Tagging_Endpoint {
    private $mock_event_logger;
    
    public function __construct($logger) {
        $this->logger = $logger;
        $this->mock_event_logger = new MockEventLogger();
        
        // Use reflection to set the mocked dependencies
        $reflection = new ReflectionClass($this);
        
        $event_logger_property = $reflection->getProperty('event_logger');
        $event_logger_property->setAccessible(true);
        $event_logger_property->setValue($this, $this->mock_event_logger);
        
        // Skip session start in constructor
    }
}

// Test data from the provided request
function get_test_data() {
    return array(
        'event' => array(
            'name' => 'scroll',
            'params' => array(
                'percent_scrolled' => 50,
                'event_timestamp' => 1753706905,
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
                'timezone' => 'Europe/Amsterdam',
                'geo_continent' => 'Europe',
                'geo_country_tz' => 'Netherlands',
                'geo_city_tz' => 'Amsterdam',
                'geo_latitude' => 51.5542,
                'geo_longitude' => 5.0661,
                'geo_city' => 'Tilburg',
                'geo_country' => 'The Netherlands',
                'geo_region' => 'North Brabant',
                'originalSource' => '(direct)',
                'originalMedium' => '(none)',
                'originalCampaign' => '(not set)',
                'originalTrafficType' => 'direct',
                'user_id' => '3',
                'session_id' => '1753706886858',
                'session_count' => 1,
                'engagement_time_msec' => 18931,
                'client_id' => '1884216334.1753706887'
            ),
            'isCompleteData' => true,
            'timestamp' => 1753706905789
        ),
        'consent' => array(
            'ad_user_data' => 'GRANTED',
            'ad_personalization' => 'GRANTED',
            'consent_reason' => 'button_click_immediate'
        ),
        'batch' => false,
        'timestamp' => 1753706907
    );
}

function get_test_headers() {
    return array(
        'Content-Type' => 'application/json',
        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
        'Accept-Language' => 'nl-NL,nl;q=0.9,en-US;q=0.8,en;q=0.7',
        'Accept' => '*/*',
        'Referer' => 'https://compuact-staging.jachtdigital.dev/',
        'X-Forwarded-For' => '37.17.209.130',
        'X-Real-IP' => '37.17.209.130'
    );
}

// Run the test
echo "=== GA4 Endpoint Test ===\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Working Directory: " . getcwd() . "\n";

try {
    $logger = new MockLogger();
    $endpoint = new TestEndpoint($logger);
    
    $test_data = get_test_data();
    $test_headers = get_test_headers();
    
    echo "\n1. Testing with provided request data:\n";
    echo json_encode($test_data, JSON_PRETTY_PRINT) . "\n";
    
    $request = new MockWPRestRequest($test_data, $test_headers);
    
    echo "\n2. Processing request...\n";
    $response = $endpoint->send_events($request);
    
    echo "\n3. Response:\n";
    echo "Status: " . $response->get_status() . "\n";
    echo "Data: " . json_encode($response->get_data(), JSON_PRETTY_PRINT) . "\n";
    
    // Test with unified batch format
    echo "\n=== Testing Unified Batch Format ===\n";
    
    $batch_data = array(
        'events' => array($test_data['event']),
        'consent' => $test_data['consent'],
        'batch' => false,
        'timestamp' => $test_data['timestamp']
    );
    
    echo "\nBatch data:\n";
    echo json_encode($batch_data, JSON_PRETTY_PRINT) . "\n";
    
    $batch_request = new MockWPRestRequest($batch_data, $test_headers);
    
    echo "\nProcessing batch request...\n";
    $batch_response = $endpoint->send_events($batch_request);
    
    echo "\nBatch Response:\n";
    echo "Status: " . $batch_response->get_status() . "\n";
    echo "Data: " . json_encode($batch_response->get_data(), JSON_PRETTY_PRINT) . "\n";
    
    // Test error handling
    echo "\n=== Testing Error Handling ===\n";
    
    $empty_request = new MockWPRestRequest(array(), $test_headers);
    $error_response = $endpoint->send_events($empty_request);
    
    echo "\nEmpty request response:\n";
    echo "Status: " . $error_response->get_status() . "\n";
    echo "Data: " . json_encode($error_response->get_data(), JSON_PRETTY_PRINT) . "\n";
    
    echo "\n=== Test Completed Successfully ===\n";
    
} catch (Exception $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
} catch (Error $e) {
    echo "\nFATAL ERROR: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}