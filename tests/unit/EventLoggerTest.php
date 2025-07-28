<?php
/**
 * Unit tests for GA4_Event_Logger class
 *
 * @package GA4_Server_Side_Tagging
 * @since 1.0.0
 */

namespace GA4ServerSideTagging\Tests\Unit;

use GA4ServerSideTagging\Core\GA4_Event_Logger;
use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;

class EventLoggerTest extends TestCase
{
    private $event_logger;

    protected function setUp(): void
    {
        parent::setUp();
        \Brain\Monkey\setUp();
        
        // Mock WordPress database and functions
        $this->setupWordPressMocks();
        
        $this->event_logger = new GA4_Event_Logger();
    }

    protected function tearDown(): void
    {
        \Brain\Monkey\tearDown();
        parent::tearDown();
    }

    private function setupWordPressMocks()
    {
        // Mock WordPress database
        $wpdb = $this->createMock('wpdb');
        $wpdb->prefix = 'wp_';
        $wpdb->insert = function () {
            return 1;
        };
        $wpdb->get_results = function () {
            return array();
        };
        $wpdb->get_var = function () {
            return 0;
        };
        
        global $wpdb;
        $wpdb = $wpdb;
        
        // Mock WordPress functions
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('current_time')->with('mysql')->justReturn('2023-01-01 12:00:00');
        Functions\when('get_option')->justReturn(false);
    }

    /**
     * Test event record creation
     */
    public function test_create_event_record()
    {
        $event_data = array(
            'event' => array(
                'name' => 'test_event',
                'params' => array('client_id' => '123.456')
            ),
            'consent' => array('ad_user_data' => 'GRANTED')
        );
        
        $headers = array(
            'user_agent' => 'Test Browser',
            'accept_language' => 'en-US'
        );
        
        $additional_data = array(
            'event_name' => 'test_event',
            'reason' => 'Test event creation',
            'ip_address' => '192.168.1.1'
        );
        
        $result = $this->event_logger->create_event_record(
            $event_data,
            'allowed',
            $headers,
            false,
            $additional_data
        );
        
        // Should return a record ID or true
        $this->assertTrue($result !== false);
    }

    /**
     * Test event queuing
     */
    public function test_queue_event()
    {
        $event_data = array(
            'event' => array(
                'name' => 'test_queue_event',
                'params' => array('session_id' => '789')
            )
        );
        
        $result = $this->event_logger->queue_event($event_data, false, array(), false);
        
        $this->assertTrue($result !== false);
    }

    /**
     * Test event status updates
     */
    public function test_update_event_status()
    {
        $event_ids = array(1, 2, 3);
        $new_status = 'completed';
        
        $result = $this->event_logger->update_event_status($event_ids, $new_status);
        
        $this->assertTrue($result);
    }

    /**
     * Test table statistics
     */
    public function test_get_table_stats()
    {
        $stats = $this->event_logger->get_table_stats();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_events', $stats);
        $this->assertArrayHasKey('allowed_events', $stats);
        $this->assertArrayHasKey('denied_events', $stats);
        $this->assertArrayHasKey('bot_events', $stats);
        $this->assertArrayHasKey('pending_queue', $stats);
        $this->assertArrayHasKey('completed_queue', $stats);
        $this->assertArrayHasKey('failed_queue', $stats);
    }

    /**
     * Test event retrieval for admin display
     */
    public function test_get_events_for_table()
    {
        $args = array(
            'limit' => 10,
            'offset' => 0,
            'status_filter' => 'allowed'
        );
        
        $events = $this->event_logger->get_events_for_table($args);
        
        $this->assertIsArray($events);
    }

    /**
     * Test queue event retrieval
     */
    public function test_get_queue_events_for_table()
    {
        $args = array(
            'limit' => 20,
            'status_filter' => 'pending'
        );
        
        $events = $this->event_logger->get_queue_events_for_table($args);
        
        $this->assertIsArray($events);
    }

    /**
     * Test old event cleanup
     */
    public function test_cleanup_old_logs()
    {
        $days = 30;
        
        $result = $this->event_logger->cleanup_old_logs($days);
        
        $this->assertTrue(is_numeric($result) || $result === true);
    }

    /**
     * Test consent extraction
     */
    public function test_extract_consent_status()
    {
        // Test GRANTED consent
        $granted_data = array(
            'consent' => array(
                'ad_user_data' => 'GRANTED',
                'ad_personalization' => 'GRANTED'
            )
        );
        
        if (method_exists($this->event_logger, 'extract_consent_status')) {
            $result = $this->event_logger->extract_consent_status($granted_data);
            $this->assertTrue($result);
        }
        
        // Test DENIED consent
        $denied_data = array(
            'consent' => array(
                'ad_user_data' => 'DENIED',
                'ad_personalization' => 'DENIED'
            )
        );
        
        if (method_exists($this->event_logger, 'extract_consent_status')) {
            $result = $this->event_logger->extract_consent_status($denied_data);
            $this->assertFalse($result);
        }
        
        // Test mixed consent (should be false if either is denied)
        $mixed_data = array(
            'consent' => array(
                'ad_user_data' => 'GRANTED',
                'ad_personalization' => 'DENIED'
            )
        );
        
        if (method_exists($this->event_logger, 'extract_consent_status')) {
            $result = $this->event_logger->extract_consent_status($mixed_data);
            $this->assertFalse($result);
        }
    }

    /**
     * Test database table creation
     */
    public function test_maybe_create_table()
    {
        // Mock dbDelta function
        Functions\when('dbDelta')->justReturn(array());
        Functions\when('get_option')->with('ga4_event_logs_db_version')->justReturn('1.0');
        
        $result = $this->event_logger->maybe_create_table();
        
        $this->assertTrue($result);
    }

    /**
     * Test payload encryption for storage
     */
    public function test_payload_encryption_for_storage()
    {
        $payload = array(
            'event' => array(
                'name' => 'sensitive_event',
                'params' => array(
                    'user_id' => '12345',
                    'email' => 'user@example.com'
                )
            )
        );
        
        // Mock encryption enabled
        Functions\when('get_option')
            ->with('ga4_jwt_encryption_enabled')
            ->justReturn(true);
            
        if (method_exists($this->event_logger, 'encrypt_payload_for_storage')) {
            $encrypted = $this->event_logger->encrypt_payload_for_storage($payload);
            $this->assertIsString($encrypted);
            $this->assertNotEquals(json_encode($payload), $encrypted);
        } else {
            $this->markTestSkipped('Payload encryption method not implemented yet');
        }
    }
}
