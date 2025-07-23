<?php

namespace GA4ServerSideTagging\Core;

use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;
use GA4ServerSideTagging\Utilities\GA4_Encryption_Util;

/**
 * Manages cronjob system for batching events
 *
 * @since      2.0.0
 * @package    GA4_Server_Side_Tagging
 */

if (!defined('WPINC')) {
    die;
}

class GA4_Cronjob_Manager
{
    /**
     * The logger instance for debugging.
     *
     * @since    2.0.0
     * @access   private
     * @var      GA4_Server_Side_Tagging_Logger    $logger    Handles logging for the plugin.
     */
    private $logger;

    /**
     * Database table name for queued events
     *
     * @since    2.0.0
     * @access   private
     * @var      string    $table_name    The name of the events queue table.
     */
    private $table_name;

    /**
     * Initialize the class and set its properties.
     *
     * @since    2.0.0
     * @param    GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    public function __construct($logger = null)
    {
        $this->logger = $logger;
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ga4_events_queue';
        
        // Only create table if it doesn't exist (avoid running on every page load)
        $this->ensure_table_exists();
        
        // Schedule cronjob if not already scheduled
        if (!wp_next_scheduled('ga4_process_event_queue')) {
            wp_schedule_event(time(), 'ga4_five_minutes', 'ga4_process_event_queue');
        }
        
        // Hook the cronjob processor
        add_action('ga4_process_event_queue', array($this, 'process_event_queue'));
    }

    /**
     * Ensure the events queue table exists (only check once per request)
     *
     * @since    2.0.0
     */
    private function ensure_table_exists()
    {
        static $table_checked = false;
        
        // Only check once per request
        if ($table_checked) {
            return;
        }
        
        global $wpdb;
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $this->table_name
        ));
        
        if (!$table_exists) {
            $this->maybe_create_table();
        }
        
        $table_checked = true;
    }

    /**
     * Create the events queue table if it doesn't exist
     *
     * @since    2.0.0
     */
    public function maybe_create_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            event_data longtext NOT NULL,
            is_encrypted tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            status varchar(20) DEFAULT 'pending',
            retry_count int(11) DEFAULT 0,
            error_message text NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($this->logger) {
            $this->logger->debug("GA4 Events Queue table created/updated");
        }
    }

    /**
     * Add custom cron schedule for 5 minutes
     *
     * @since    2.0.0
     * @param    array    $schedules    Existing cron schedules.
     * @return   array    Modified schedules array.
     */
    public function add_cron_schedule($schedules)
    {
        $schedules['ga4_five_minutes'] = array(
            'interval' => 300, // 5 minutes in seconds
            'display'  => __('Every 5 Minutes', 'ga4-server-side-tagging')
        );
        return $schedules;
    }

    /**
     * Queue an event for batch processing
     *
     * @since    2.0.0
     * @param    array     $event_data    The event data to queue.
     * @param    boolean   $is_encrypted  Whether the event data is encrypted.
     * @return   boolean   True if queued successfully, false otherwise.
     */
    public function queue_event($event_data, $is_encrypted = false)
    {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'event_data' => is_array($event_data) ? wp_json_encode($event_data) : $event_data,
                'is_encrypted' => $is_encrypted ? 1 : 0,
                'status' => 'pending'
            ),
            array('%s', '%d', '%s')
        );

        if ($result === false) {
            if ($this->logger) {
                $this->logger->error("Failed to queue event: " . $wpdb->last_error);
            }
            return false;
        }


        return true;
    }

    /**
     * Process the event queue - called by cronjob
     *
     * @since    2.0.0
     */
    public function process_event_queue()
    {
        global $wpdb;

        // Get batch size setting
        $batch_size = get_option('ga4_cronjob_batch_size', 1000);
        
        // Get pending events
        $events = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_name WHERE status = 'pending' ORDER BY created_at ASC LIMIT %d",
            $batch_size
        ));

        if (empty($events)) {
            return;
        }

        // Group events for batch processing
        $batch_events = array();
        $event_ids = array();
        $batch_consent = null;
        $first_event_processed = false;

        foreach ($events as $event) {
            $event_data = $event->event_data;
            
            // Decrypt if encrypted
            if ($event->is_encrypted) {
                $event_data = $this->decrypt_event_data($event_data);
                if (!$event_data) {
                    $this->mark_event_failed($event->id, "Failed to decrypt event data");
                    continue;
                }
            }

            // Parse JSON if needed
            if (is_string($event_data)) {
                $parsed_data = json_decode($event_data, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $event_data = $parsed_data;
                }
            }

            // Extract the actual event data (the event key contains the GA4 event)
            if (isset($event_data['event'])) {
                $batch_events[] = $event_data['event'];
                
                // Use consent from first event for the entire batch
                if (!$first_event_processed && isset($event_data['consent'])) {
                    $batch_consent = $event_data['consent'];
                    $first_event_processed = true;
                }
            } else {
                // Fallback for direct event data
                $batch_events[] = $event_data;
            }
            
            $event_ids[] = $event->id;
        }

        if (empty($batch_events)) {
            if ($this->logger) {
                $this->logger->debug("No valid events to send after processing");
            }
            return;
        }

        // Send batch to Cloudflare
        $success = $this->send_batch_to_cloudflare($batch_events, $batch_consent);

        if ($success) {
            // Mark all events as processed
            $ids_placeholder = implode(',', array_fill(0, count($event_ids), '%d'));
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $this->table_name SET status = 'completed', processed_at = NOW() WHERE id IN ($ids_placeholder)",
                    ...$event_ids
                )
            );

        } else {
            // Mark events as failed or retry
            foreach ($event_ids as $event_id) {
                $this->mark_event_failed($event_id, "Failed to send batch to Cloudflare");
            }
        }
    }

    /**
     * Decrypt event data using permanent, time-based, or regular JWT
     * Uses permanent JWT decryption for queued events, with fallbacks for compatibility
     *
     * @since    2.0.0
     * @param    string    $encrypted_data    The encrypted event data.
     * @return   mixed     Decrypted data or false on failure.
     */
    private function decrypt_event_data($encrypted_data)
    {
        try {
            // Try permanent JWT first (for events stored with permanent encryption)
            $permanent_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            if ($permanent_key) {
                $decrypted = GA4_Encryption_Util::decrypt_permanent_jwt_token($encrypted_data, $permanent_key);
                if ($decrypted !== false) {
                    return $decrypted;
                }
            }
            
            // Try time-based JWT (for recent events that may still use time-based encryption)
            $decrypted = GA4_Encryption_Util::verify_time_based_jwt($encrypted_data);
            if ($decrypted !== false) {
                return $decrypted;
            }
            
            // Fallback to regular JWT with stored encryption key (legacy compatibility)
            if ($permanent_key) {
                $decrypted = GA4_Encryption_Util::decrypt($encrypted_data, $permanent_key);
                if ($decrypted !== false) {
                    return $decrypted;
                }
            }

            return false;
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error("Event decryption failed: " . $e->getMessage());
            }
            return false;
        }
    }

    /**
     * Send batch of events to Cloudflare Worker
     *
     * @since    2.0.0
     * @param    array    $batch_events    Array of GA4 events to send.
     * @param    array    $batch_consent   Consent data for the batch.
     * @return   boolean  True if successful, false otherwise.
     */
    private function send_batch_to_cloudflare($batch_events, $batch_consent = null)
    {
        $worker_url = get_option('ga4_cloudflare_worker_url');

        if (empty($worker_url)) {
            if ($this->logger) {
                $this->logger->error("Missing Cloudflare Worker URL");
            }
            return false;
        }

        // Prepare batch payload in the format CF Worker expects
        $payload = array(
            'events' => $batch_events,
            'batch' => true,
            'timestamp' => time()
        );
        
        // Add consent data if available
        if ($batch_consent) {
            $payload['consent'] = $batch_consent;
        }

        // Check if secured transmission is enabled
        $secured_transmission = get_option('ga4_jwt_encryption_enabled', false);
        if ($secured_transmission) {
            $encryption_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            if ($encryption_key) {
                try {
                    // Use permanent JWT encryption for batch payloads (no expiry for async processing)
                    $encrypted_payload = GA4_Encryption_Util::create_permanent_jwt_token(wp_json_encode($payload), $encryption_key);
                    $payload = array(
                        'encrypted' => true,
                        'jwt' => $encrypted_payload
                    );
                } catch (Exception $e) {
                    if ($this->logger) {
                        $this->logger->error("Failed to encrypt batch payload with permanent key: " . $e->getMessage());
                    }
                    // Continue with unencrypted payload
                }
            }
        }

        // Prepare headers with Worker API key authentication
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'GA4-Server-Side-Tagging-Batch/2.0.0'
        );

        // Add Worker API key authentication header
        $worker_api_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_worker_api_key');
        if (!empty($worker_api_key)) {
            $headers['Authorization'] = 'Bearer ' . $worker_api_key;
        }

        // Send request to Cloudflare Worker
        $response = wp_remote_post($worker_url, array(
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 30,
            'sslverify' => true
        ));

        if (is_wp_error($response)) {
            if ($this->logger) {
                $this->logger->error("Failed to send batch to Cloudflare: " . $response->get_error_message());
            }
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code >= 200 && $response_code < 300) {
            return true;
        } else {
            if ($this->logger) {
                $this->logger->error("Cloudflare returned error code $response_code: " . $response_body);
            }
            return false;
        }
    }

    /**
     * Mark an event as failed
     *
     * @since    2.0.0
     * @param    int       $event_id       The event ID.
     * @param    string    $error_message  The error message.
     */
    private function mark_event_failed($event_id, $error_message)
    {
        global $wpdb;

        $wpdb->update(
            $this->table_name,
            array(
                'status' => 'failed',
                'error_message' => $error_message,
                'retry_count' => new \stdClass() // This will be converted to retry_count + 1
            ),
            array('id' => $event_id),
            array('%s', '%s', '%d'),
            array('%d')
        );

        // Properly increment retry_count
        $wpdb->query($wpdb->prepare(
            "UPDATE $this->table_name SET retry_count = retry_count + 1 WHERE id = %d",
            $event_id
        ));
    }

    /**
     * Get queue statistics
     *
     * @since    2.0.0
     * @return   array    Queue statistics.
     */
    public function get_queue_stats()
    {
        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM $this->table_name",
            ARRAY_A
        );

        return $stats ?: array('total' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0);
    }

    /**
     * Clear old processed events
     *
     * @since    2.0.0
     * @param    int    $days_old    Number of days to keep events.
     */
    public function cleanup_old_events($days_old = 7)
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $this->table_name WHERE status IN ('completed', 'failed') AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));

        if ($this->logger) {
            $this->logger->debug("Cleaned up $result old events");
        }

        return $result;
    }

    /**
     * Manually trigger queue processing
     *
     * @since    2.0.0
     * @return   boolean  True if successful.
     */
    public function trigger_manual_processing()
    {
        if ($this->logger) {
            $this->logger->debug("Manual queue processing triggered");
        }

        $this->process_event_queue();
        return true;
    }

    /**
     * Get recent events for display
     *
     * @since    2.0.0
     * @param    int    $limit    Number of events to retrieve.
     * @return   array  Recent events.
     */
    public function get_recent_events($limit = 50)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, status, created_at, processed_at, retry_count, error_message 
             FROM $this->table_name 
             ORDER BY created_at DESC 
             LIMIT %d",
            $limit
        ));
    }
}