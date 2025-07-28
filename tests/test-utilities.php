<?php
/**
 * Test utilities for GA4 Server-Side Tagging plugin tests
 *
 * @package GA4_Server_Side_Tagging
 */

namespace GA4ServerSideTagging\Tests;

use WP_Error;

/**
 * Mock HTTP responses for testing
 */
class GA4_Test_HTTP_Mock
{
    
    /**
     * Mock successful Cloudflare Worker response
     */
    public static function mock_cloudflare_success($response, $args, $url)
    {
        if (strpos($url, 'workers.dev') !== false) {
            return array(
                'response' => array('code' => 200),
                'body' => wp_json_encode(array(
                    'success' => true,
                    'events_processed' => count(json_decode($args['body'], true)['events'] ?? [1])
                ))
            );
        }
        return $response;
    }
    
    /**
     * Mock successful GA4 direct response
     */
    public static function mock_ga4_success($response, $args, $url)
    {
        if (strpos($url, 'google-analytics.com') !== false) {
            return array(
                'response' => array('code' => 204), // GA4 returns 204 No Content
                'body' => ''
            );
        }
        return $response;
    }
    
    /**
     * Mock HTTP error response
     */
    public static function mock_http_error($response, $args, $url)
    {
        return new WP_Error('http_request_failed', 'Test HTTP error');
    }
    
    /**
     * Mock timeout response
     */
    public static function mock_timeout($response, $args, $url)
    {
        return new WP_Error('http_request_failed', 'Operation timed out');
    }
}

/**
 * Test data generator
 */
class GA4_Test_Data_Generator
{
    
    /**
     * Generate random client ID
     */
    public static function generate_client_id()
    {
        return mt_rand(1000000000, 9999999999) . '.' . time();
    }
    
    /**
     * Generate random session ID
     */
    public static function generate_session_id()
    {
        return time() . mt_rand(100, 999);
    }
    
    /**
     * Generate test event with random data
     */
    public static function generate_test_event($event_name = 'test_event', $params = array())
    {
        $default_params = array(
            'client_id' => self::generate_client_id(),
            'session_id' => self::generate_session_id(),
            'event_timestamp' => time(),
            'user_agent' => 'Mozilla/5.0 (Test Browser)',
            'page_location' => 'https://example.com/test/',
            'engagement_time_msec' => mt_rand(1000, 60000)
        );
        
        return array(
            'name' => $event_name,
            'params' => array_merge($default_params, $params),
            'isCompleteData' => true,
            'timestamp' => time() * 1000
        );
    }
    
    /**
     * Generate test consent data
     */
    public static function generate_consent($granted = true)
    {
        $status = $granted ? 'GRANTED' : 'DENIED';
        return array(
            'ad_user_data' => $status,
            'ad_personalization' => $status,
            'consent_reason' => $granted ? 'button_click_immediate' : 'user_declined'
        );
    }
}

/**
 * Database test utilities
 */
class GA4_Test_DB_Utils
{
    
    /**
     * Clean test data from database
     */
    public static function cleanup_test_data()
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        $wpdb->query("DELETE FROM {$table_name} WHERE event_name LIKE 'test_%'");
    }
    
    /**
     * Get test events from database
     */
    public static function get_test_events($event_name = null)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        
        if ($event_name) {
            return $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table_name} WHERE event_name = %s ORDER BY created_at DESC", $event_name)
            );
        }
        
        return $wpdb->get_results(
            "SELECT * FROM {$table_name} WHERE event_name LIKE 'test_%' ORDER BY created_at DESC"
        );
    }
    
    /**
     * Count events by status
     */
    public static function count_events_by_status($monitor_status = null, $queue_status = null)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ga4_event_logs';
        
        $where_conditions = array("event_name LIKE 'test_%'");
        $values = array();
        
        if ($monitor_status) {
            $where_conditions[] = "monitor_status = %s";
            $values[] = $monitor_status;
        }
        
        if ($queue_status) {
            $where_conditions[] = "queue_status = %s";
            $values[] = $queue_status;
        }
        
        $where_clause = implode(' AND ', $where_conditions);
        $query = "SELECT COUNT(*) FROM {$table_name} WHERE {$where_clause}";
        
        if (!empty($values)) {
            return $wpdb->get_var($wpdb->prepare($query, $values));
        }
        
        return $wpdb->get_var($query);
    }
}

/**
 * Assertion helpers
 */
class GA4_Test_Assertions
{
    
    /**
     * Assert that an event was stored correctly
     */
    public static function assertEventStored($test_case, $event_name, $monitor_status = 'allowed', $queue_status = 'pending')
    {
        $events = GA4_Test_DB_Utils::get_test_events($event_name);
        $test_case->assertCount(1, $events, "Expected exactly one event with name {$event_name}");
        
        $event = $events[0];
        $test_case->assertEquals($monitor_status, $event->monitor_status);
        
        if ($queue_status !== null) {
            $test_case->assertEquals($queue_status, $event->queue_status);
        }
    }
    
    /**
     * Assert that events were batched correctly
     */
    public static function assertEventsBatched($test_case, $expected_count, $monitor_status = 'allowed', $queue_status = 'pending')
    {
        $count = GA4_Test_DB_Utils::count_events_by_status($monitor_status, $queue_status);
        $test_case->assertEquals($expected_count, $count, "Expected {$expected_count} events with status {$monitor_status}/{$queue_status}");
    }
    
    /**
     * Assert REST response structure
     */
    public static function assertValidRestResponse($test_case, $response, $expected_status = 200)
    {
        $test_case->assertInstanceOf('WP_REST_Response', $response);
        $test_case->assertEquals($expected_status, $response->get_status());
        
        $data = $response->get_data();
        $test_case->assertIsArray($data);
        
        if ($expected_status === 200) {
            $test_case->assertArrayHasKey('success', $data);
            $test_case->assertTrue($data['success']);
        }
    }
}
