<?php
/**
 * Integration tests for GA4 Server-Side Tagging /send-events endpoint
 * Tests both transmission methods: Cloudflare Worker and Direct to GA4
 *
 * @package GA4_Server_Side_Tagging
 * @since 1.0.0
 */

namespace GA4ServerSideTagging\Tests\Integration;

use GA4ServerSideTagging\API\GA4_Server_Side_Tagging_Endpoint;
use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;
use GA4ServerSideTagging\Core\GA4_Cronjob_Manager;
use GA4ServerSideTagging\Core\GA4_Event_Logger;
use GA4ServerSideTagging\Utilities\GA4_Encryption_Util;
use GA4ServerSideTagging\Tests\GA4_Test_HTTP_Mock;
use GA4ServerSideTagging\Tests\GA4_Test_Data_Generator;
use GA4ServerSideTagging\Tests\GA4_Test_DB_Utils;
use GA4ServerSideTagging\Tests\GA4_Test_Assertions;
use WP_UnitTestCase;
use WP_REST_Request;

class EndpointTest extends WP_UnitTestCase
{
    private $endpoint;
    private $logger;
    private $cronjob_manager;
    private $event_logger;

    /**
     * Set up test environment before each test
     */
    public function setUp(): void
    {
        parent::setUp();
        
        // Create mock logger
        $this->logger = $this->createMock(GA4_Server_Side_Tagging_Logger::class);
        
        // Create endpoint instance
        $this->endpoint = new GA4_Server_Side_Tagging_Endpoint($this->logger);
        
        // Create manager instances for testing
        $this->cronjob_manager = new GA4_Cronjob_Manager($this->logger);
        $this->event_logger = new GA4_Event_Logger();
        
        // Set up WordPress options for testing
        update_option('ga4_measurement_id', 'G-TEST123');
        update_option('ga4_api_secret', 'test-api-secret');
        update_option('ga4_cloudflare_worker_url', 'https://test-worker.workers.dev/');
        update_option('ga4_transmission_method', 'wp_rest_endpoint');
        update_option('ga4_disable_cf_proxy', false);
        update_option('ga4_jwt_encryption_enabled', false);
        
        // Register REST routes
        $this->endpoint->register_routes();
        
        // Ensure database tables exist
        $this->event_logger->maybe_create_table();
        
        // Clean up any existing test data
        $this->cleanup_test_data();
    }

    /**
     * Clean up after each test
     */
    public function tearDown(): void
    {
        $this->cleanup_test_data();
        parent::tearDown();
    }

    /**
     * Clean up test data from database
     */
    private function cleanup_test_data()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $wpdb->query("DELETE FROM {$table_name} WHERE event_name LIKE 'test_%'");
    }

    /**
     * Create a test WP_REST_Request object
     */
    private function create_test_request($payload, $headers = array())
    {
        $request = new WP_REST_Request('POST', '/wp-json/ga4-server-side-tagging/v1/send-events');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Safari/605.1.15');
        $request->set_header('Accept', 'application/json');
        $request->set_header('Accept-Language', 'en-US,en;q=0.9');
        $request->set_header('Referer', site_url());
        $request->set_header('Origin', site_url());
        
        // Add custom headers
        foreach ($headers as $key => $value) {
            $request->set_header($key, $value);
        }
        
        $request->set_body(wp_json_encode($payload));
        return $request;
    }

    /**
     * Get sample single event payload (as received from JavaScript)
     */
    private function get_sample_single_event_payload()
    {
        return array(
            'event' => array(
                'name' => 'test_custom_user_engagement',
                'params' => array(
                    'engagement_time_msec' => 60000,
                    'event_timestamp' => 1753702507,
                    'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Safari/605.1.15',
                    'timezone' => 'Europe/Brussels',
                    'geo_continent' => 'Europe',
                    'geo_country_tz' => 'Belgium',
                    'geo_city_tz' => 'Brussels',
                    'geo_latitude' => 50.8267,
                    'geo_longitude' => 4.5224,
                    'geo_city' => 'Tervuren',
                    'geo_country' => 'Belgium',
                    'geo_region' => 'Flanders',
                    'originalSource' => 'google',
                    'originalMedium' => 'cpc',
                    'originalCampaign' => '21619415177',
                    'originalGclid' => 'EAIaIQobChMIk4CukrrfjgMV7an9BR0wNyJQEAAYAiAAEgLV9fD_BwE',
                    'originalTrafficType' => 'paid_search',
                    'session_id' => '1753702447180',
                    'session_count' => 1,
                    'client_id' => '2046349794.1753702447'
                ),
                'isCompleteData' => true,
                'timestamp' => 1753702507184
            ),
            'consent' => array(
                'ad_user_data' => 'GRANTED',
                'ad_personalization' => 'GRANTED',
                'consent_reason' => 'button_click_immediate'
            ),
            'batch' => false,
            'timestamp' => 1753702510
        );
    }

    /**
     * Get sample batch event payload (as sent to Cloudflare Worker)
     */
    private function get_sample_batch_payload()
    {
        return array(
            'events' => array(
                array(
                    'name' => 'test_custom_session_start',
                    'params' => array(
                        'session_id' => '1753702434028',
                        'client_id' => '317098942.1753702434',
                        'engagement_time_msec' => 1000,
                        'session_start' => 1,
                        'browser_name' => 'Not)A;Brand',
                        'device_type' => 'mobile',
                        'screen_resolution' => '384x832',
                        'is_mobile' => true,
                        'language' => 'nl-BE',
                        'source' => 'google',
                        'medium' => 'cpc',
                        'campaign' => '21619415177',
                        'traffic_type' => 'paid_search',
                        'gclid' => 'CjwKCAjwv5zEBhBwEiwAOg2YKMayEzup2uXB2NNa1bOp2FDVaLbad_yg-h-4feQdbUEnEE8bR37ucBoCSj0QAvD_BwE',
                        'page_title' => 'Test Page Title',
                        'page_location' => 'https://example.com/test/',
                        'page_referrer' => 'https://google.com/',
                        'timezone' => 'Europe/Brussels',
                        'user_agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36',
                        'event_timestamp' => 1753702434,
                        'geo_continent' => 'Europe',
                        'geo_country_tz' => 'Belgium',
                        'geo_city_tz' => 'Brussels',
                        'geo_latitude' => 51.1981,
                        'geo_longitude' => 5.1181,
                        'geo_city' => 'Mol',
                        'geo_country' => 'Belgium',
                        'geo_region' => 'Flanders'
                    ),
                    'isCompleteData' => true,
                    'timestamp' => 1753702434371,
                    'headers' => array(
                        'x_forwarded_for' => '2a02:1810:1530:5300:5361:4f32:2c55:f092',
                        'user_agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36',
                        'referer' => 'https://example.com/test/',
                        'accept' => '*/*',
                        'accept_language' => 'nl-BE,nl-NL;q=0.9,nl;q=0.8,en-US;q=0.7,en;q=0.6',
                        'x_real_ip' => '2a02:1810:1530:5300:5361:4f32:2c55:f092'
                    )
                ),
                array(
                    'name' => 'test_custom_user_engagement',
                    'params' => array(
                        'engagement_time_msec' => 15000,
                        'event_timestamp' => 1753702449,
                        'user_agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36',
                        'timezone' => 'Europe/Brussels',
                        'geo_continent' => 'Europe',
                        'geo_country_tz' => 'Belgium',
                        'geo_city_tz' => 'Brussels',
                        'geo_latitude' => 51.1981,
                        'geo_longitude' => 5.1181,
                        'geo_city' => 'Mol',
                        'geo_country' => 'Belgium',
                        'geo_region' => 'Flanders',
                        'originalSource' => 'google',
                        'originalMedium' => 'cpc',
                        'originalCampaign' => '21619415177',
                        'originalGclid' => 'CjwKCAjwv5zEBhBwEiwAOg2YKMayEzup2uXB2NNa1bOp2FDVaLbad_yg-h-4feQdbUEnEE8bR37ucBoCSj0QAvD_BwE',
                        'originalTrafficType' => 'paid_search',
                        'session_id' => '1753702434028',
                        'session_count' => 1,
                        'client_id' => '317098942.1753702434'
                    ),
                    'isCompleteData' => true,
                    'timestamp' => 1753702449031,
                    'headers' => array(
                        'x_forwarded_for' => '2a02:1810:1530:5300:5361:4f32:2c55:f092',
                        'user_agent' => 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36',
                        'referer' => 'https://example.com/test/',
                        'accept' => '*/*',
                        'accept_language' => 'nl-BE,nl-NL;q=0.9,nl;q=0.8,en-US;q=0.7,en;q=0.6',
                        'x_real_ip' => '2a02:1810:1530:5300:5361:4f32:2c55:f092'
                    )
                )
            ),
            'batch' => true,
            'timestamp' => 1753702569
        );
    }

    /**
     * Get sample GA4 direct payload structure
     */
    private function get_sample_ga4_direct_payload()
    {
        return array(
            'client_id' => '1406931247.1753690598',
            'events' => array(
                array(
                    'name' => 'test_page_view',
                    'params' => array(
                        'session_id' => '1753690598129',
                        'engagement_time_msec' => 801014,
                        'source' => '(internal)',
                        'medium' => 'internal',
                        'campaign' => '(not set)',
                        'traffic_type' => 'internal',
                        'page_title' => 'Test Page Title',
                        'page_location' => 'https://example.com/',
                        'page_referrer' => '',
                        'timezone' => 'Europe/Amsterdam',
                        'event_timestamp' => 1753691399,
                        'session_count' => 1,
                        'consent' => 'ad_personalization: GRANTED. ad_user_data: GRANTED. reason: button_click_immediate'
                    )
                )
            ),
            'user_id' => '3',
            'consent' => array(
                'ad_user_data' => 'GRANTED',
                'ad_personalization' => 'GRANTED'
            ),
            'user_location' => array(
                'city' => 'Tilburg',
                'country_id' => 'NL',
                'continent_id' => '150',
                'subcontinent_id' => '155'
            ),
            'device' => array(
                'category' => 'desktop',
                'language' => 'nl',
                'screen_resolution' => '1440x900',
                'operating_system' => 'macOS',
                'operating_system_version' => '10.15',
                'browser' => 'Not)A;Brand',
                'browser_version' => '138.0'
            ),
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36',
            'ip_override' => '37.17.209.130'
        );
    }

    /**
     * Test single event processing with Cloudflare Worker transmission method
     */
    public function test_single_event_cloudflare_worker_transmission()
    {
        // Configure for Cloudflare Worker transmission
        update_option('ga4_transmission_method', 'wp_rest_endpoint');
        update_option('ga4_disable_cf_proxy', false);
        
        $payload = $this->get_sample_single_event_payload();
        $request = $this->create_test_request($payload);
        
        // Mock successful Cloudflare response
        add_filter('pre_http_request', function ($response, $args, $url) {
            if (strpos($url, 'workers.dev') !== false) {
                return array(
                    'response' => array('code' => 200),
                    'body' => wp_json_encode(array('success' => true, 'events_processed' => 1))
                );
            }
            return $response;
        }, 10, 3);
        
        // Execute the request
        $response = $this->endpoint->send_events($request);
        
        // Assertions
        $this->assertInstanceOf('WP_REST_Response', $response);
        $this->assertEquals(200, $response->get_status());
        
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertGreaterThan(0, $data['events_queued']);
        $this->assertStringContainsString('queued for batch processing', $data['message']);
        
        // Verify event was stored in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $stored_events = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE event_name = %s", 'test_custom_user_engagement')
        );
        
        $this->assertCount(1, $stored_events);
        $this->assertEquals('allowed', $stored_events[0]->monitor_status);
        $this->assertEquals('pending', $stored_events[0]->queue_status);
    }

    /**
     * Test batch event processing with Cloudflare Worker transmission method
     */
    public function test_batch_events_cloudflare_worker_transmission()
    {
        // Configure for Cloudflare Worker transmission
        update_option('ga4_transmission_method', 'wp_rest_endpoint');
        update_option('ga4_disable_cf_proxy', false);
        
        $payload = $this->get_sample_batch_payload();
        $request = $this->create_test_request($payload);
        
        // Mock successful Cloudflare response
        add_filter('pre_http_request', function ($response, $args, $url) {
            if (strpos($url, 'workers.dev') !== false) {
                return array(
                    'response' => array('code' => 200),
                    'body' => wp_json_encode(array('success' => true, 'events_processed' => 2))
                );
            }
            return $response;
        }, 10, 3);
        
        // Execute the request
        $response = $this->endpoint->send_events($request);
        
        // Assertions
        $this->assertInstanceOf('WP_REST_Response', $response);
        $this->assertEquals(200, $response->get_status());
        
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertEquals(2, $data['events_queued']);
        
        // Verify events were stored in database
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $stored_events = $wpdb->get_results(
            "SELECT * FROM {$table_name} WHERE event_name LIKE 'test_%' ORDER BY created_at"
        );
        
        $this->assertCount(2, $stored_events);
        foreach ($stored_events as $event) {
            $this->assertEquals('allowed', $event->monitor_status);
            $this->assertEquals('pending', $event->queue_status);
        }
    }

    /**
     * Test direct GA4 transmission method (bypass Cloudflare)
     */
    public function test_direct_ga4_transmission()
    {
        // Configure for direct GA4 transmission
        update_option('ga4_transmission_method', 'wp_rest_endpoint');
        update_option('ga4_disable_cf_proxy', true); // This enables direct GA4 transmission
        
        $payload = $this->get_sample_single_event_payload();
        $request = $this->create_test_request($payload);
        
        // Mock successful GA4 response
        add_filter('pre_http_request', function ($response, $args, $url) {
            if (strpos($url, 'google-analytics.com') !== false) {
                return array(
                    'response' => array('code' => 204), // GA4 returns 204 No Content on success
                    'body' => ''
                );
            }
            return $response;
        }, 10, 3);
        
        // Execute the request
        $response = $this->endpoint->send_events($request);
        
        // Assertions
        $this->assertInstanceOf('WP_REST_Response', $response);
        $this->assertEquals(200, $response->get_status());
        
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertGreaterThan(0, $data['events_queued']);
        
        // Verify event was stored with correct transmission method
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $stored_event = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE event_name = %s", 'test_custom_user_engagement')
        );
        
        $this->assertNotNull($stored_event);
        $this->assertEquals('allowed', $stored_event->monitor_status);
        $this->assertEquals('pending', $stored_event->queue_status);
    }

    /**
     * Test event payload transformation for different transmission methods
     */
    public function test_event_payload_transformation()
    {
        $single_event = $this->get_sample_single_event_payload();
        
        // Test conversion from single event to unified batch structure
        $request = $this->create_test_request($single_event);
        $response = $this->endpoint->send_events($request);
        
        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        
        // Verify the event was processed correctly
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $stored_event = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE event_name = %s", 'test_custom_user_engagement')
        );
        
        $this->assertNotNull($stored_event);
        
        // Verify the original payload structure is preserved
        $original_payload = json_decode($stored_event->original_payload, true);
        $this->assertArrayHasKey('event', $original_payload);
        $this->assertArrayHasKey('consent', $original_payload);
        $this->assertEquals('test_custom_user_engagement', $original_payload['event']['name']);
    }

    /**
     * Test bot detection functionality
     */
    public function test_bot_detection()
    {
        $payload = $this->get_sample_single_event_payload();
        
        // Create request with bot-like headers
        $request = $this->create_test_request($payload, array(
            'User-Agent' => 'Googlebot/2.1',
            'Accept' => '*/*'
        ));
        
        $response = $this->endpoint->send_events($request);
        
        // Should be blocked with 403 status
        $this->assertEquals(403, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('blocked', $data['error']);
        
        // Verify bot detection was logged
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $bot_event = $wpdb->get_row(
            "SELECT * FROM {$table_name} WHERE monitor_status = 'bot_detected' ORDER BY created_at DESC LIMIT 1"
        );
        
        $this->assertNotNull($bot_event);
        $this->assertEquals('bot_detected', $bot_event->monitor_status);
        $this->assertNull($bot_event->queue_status); // Bots don't get queued
    }

    /**
     * Test rate limiting functionality
     */
    public function test_rate_limiting()
    {
        $payload = $this->get_sample_single_event_payload();
        
        // Send 101 requests rapidly (over the 100/minute limit)
        for ($i = 0; $i < 101; $i++) {
            $request = $this->create_test_request($payload);
            $response = $this->endpoint->send_events($request);
            
            if ($i < 100) {
                // First 100 should succeed
                $this->assertEquals(200, $response->get_status());
            } else {
                // 101st should be rate limited
                $this->assertEquals(429, $response->get_status());
                $data = $response->get_data();
                $this->assertArrayHasKey('error', $data);
                $this->assertStringContainsString('Rate limit exceeded', $data['error']);
                break;
            }
        }
    }

    /**
     * Test consent handling
     */
    public function test_consent_handling()
    {
        // Test with DENIED consent
        $payload = $this->get_sample_single_event_payload();
        $payload['consent'] = array(
            'ad_user_data' => 'DENIED',
            'ad_personalization' => 'DENIED',
            'consent_reason' => 'user_declined'
        );
        
        $request = $this->create_test_request($payload);
        $response = $this->endpoint->send_events($request);
        
        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        
        // Verify event was stored with consent info
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $stored_event = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE event_name = %s ORDER BY created_at DESC LIMIT 1", 'test_custom_user_engagement')
        );
        
        $this->assertNotNull($stored_event);
        $this->assertEquals(0, $stored_event->consent_given); // Should be false for DENIED
    }

    /**
     * Test encrypted payload handling
     */
    public function test_encrypted_payload_handling()
    {
        // Enable encryption
        update_option('ga4_jwt_encryption_enabled', true);
        update_option('ga4_jwt_encryption_key', base64_encode('test-encryption-key-32-chars-long!'));
        
        $payload = $this->get_sample_single_event_payload();
        
        // Create request with encryption header
        $request = $this->create_test_request($payload, array(
            'X-Encrypted' => 'true'
        ));
        
        $response = $this->endpoint->send_events($request);
        
        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        
        // Verify encrypted event was processed
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $stored_event = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE event_name = %s ORDER BY created_at DESC LIMIT 1", 'test_custom_user_engagement')
        );
        
        $this->assertNotNull($stored_event);
        $this->assertEquals(1, $stored_event->was_originally_encrypted);
    }

    /**
     * Test error handling for invalid payloads
     */
    public function test_invalid_payload_handling()
    {
        // Test with empty payload
        $request = $this->create_test_request(array());
        $response = $this->endpoint->send_events($request);
        
        $this->assertEquals(400, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('error', $data);
        
        // Test with malformed payload
        $malformed_payload = array(
            'events' => array(
                array('name' => '') // Empty event name
            )
        );
        
        $request = $this->create_test_request($malformed_payload);
        $response = $this->endpoint->send_events($request);
        
        $this->assertEquals(400, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Missing event name', $data['error']);
    }

    /**
     * Test queue processing functionality
     */
    public function test_queue_processing()
    {
        // First, queue some events
        $payload = $this->get_sample_batch_payload();
        $request = $this->create_test_request($payload);
        
        // Mock Cloudflare response for queue processing
        add_filter('pre_http_request', function ($response, $args, $url) {
            if (strpos($url, 'workers.dev') !== false) {
                return array(
                    'response' => array('code' => 200),
                    'body' => wp_json_encode(array('success' => true, 'events_processed' => 2))
                );
            }
            return $response;
        }, 10, 3);
        
        $response = $this->endpoint->send_events($request);
        $this->assertEquals(200, $response->get_status());
        
        // Verify events are pending
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $pending_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE queue_status = 'pending' AND event_name LIKE 'test_%'"
        );
        $this->assertGreaterThan(0, $pending_count);
        
        // Process the queue
        $this->cronjob_manager->process_event_queue();
        
        // Verify events were processed
        $completed_count = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE queue_status = 'completed' AND event_name LIKE 'test_%'"
        );
        $this->assertGreaterThan(0, $completed_count);
    }

    /**
     * Test header filtering and storage
     */
    public function test_header_filtering()
    {
        $payload = $this->get_sample_single_event_payload();
        
        // Create request with various headers
        $request = $this->create_test_request($payload, array(
            'X-Custom-Header' => 'should-be-filtered-out',
            'Accept-Language' => 'en-US,en;q=0.9',
            'X-Forwarded-For' => '192.168.1.1',
            'Authorization' => 'Bearer token-should-be-filtered'
        ));
        
        $response = $this->endpoint->send_events($request);
        $this->assertEquals(200, $response->get_status());
        
        // Verify only essential headers were stored
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $stored_event = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE event_name = %s", 'test_custom_user_engagement')
        );
        
        $this->assertNotNull($stored_event);
        $headers = json_decode($stored_event->original_headers, true);
        
        // Should contain essential headers
        $this->assertArrayHasKey('accept_language', $headers);
        $this->assertArrayHasKey('x_forwarded_for', $headers);
        
        // Should NOT contain filtered headers
        $this->assertArrayNotHasKey('x_custom_header', $headers);
        $this->assertArrayNotHasKey('authorization', $headers);
    }
}
