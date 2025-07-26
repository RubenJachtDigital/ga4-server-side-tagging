<?php

namespace GA4ServerSideTagging\Core;

use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;
use GA4ServerSideTagging\Core\GA4_Payload_Transformer;
use GA4ServerSideTagging\Core\GA4_Event_Logger;
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
     * Event logger instance for unified table operations
     *
     * @since    3.0.0
     * @access   private
     * @var      GA4_Event_Logger    $event_logger    The event logger instance for unified table operations.
     */
    private $event_logger;

    /**
     * The payload transformer instance for GA4 formatting.
     *
     * @since    3.0.0
     * @access   private
     * @var      GA4_Payload_Transformer    $transformer    Handles GA4 payload transformation.
     */
    private $transformer;

    /**
     * Initialize the class and set its properties.
     *
     * @since    2.0.0
     * @param    GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    public function __construct($logger = null)
    {
        $this->logger = $logger;
        $this->transformer = new GA4_Payload_Transformer($logger);
        $this->event_logger = new GA4_Event_Logger();
        
        // Schedule cronjob if not already scheduled
        if (!wp_next_scheduled('ga4_process_event_queue')) {
            wp_schedule_event(time(), 'ga4_five_minutes', 'ga4_process_event_queue');
        }
        
        // Hook the cronjob processor
        add_action('ga4_process_event_queue', array($this, 'process_event_queue'));
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
     * @param    array     $event_data              The event data to queue.
     * @param    boolean   $is_encrypted            Whether the event data is encrypted.
     * @param    array     $original_headers        Original request headers.
     * @param    boolean   $was_originally_encrypted Whether the original request was encrypted.
     * @return   boolean   True if queued successfully, false otherwise.
     */
    public function queue_event($event_data, $is_encrypted = false, $original_headers = array(), $was_originally_encrypted = false)
    {
        $result = $this->event_logger->queue_event(
            $event_data, 
            $is_encrypted, 
            $original_headers, 
            $was_originally_encrypted
        );

        if ($result === false) {
            if ($this->logger) {
                $this->logger->error("Failed to queue event in unified table");
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
        // Get batch size setting
        $batch_size = 1000;
        
        // Get pending events from unified table
        $events = $this->event_logger->get_pending_events($batch_size);

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
                    $this->event_logger->mark_event_failed($event->id, "Failed to decrypt event data");
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
            return;
        }

        
        // Simple check: if disable CF proxy is enabled, use direct GA4
        $disable_cf_proxy = get_option('ga4_disable_cf_proxy', false);
        
        if ($disable_cf_proxy) {
            // Send events individually to GA4 (bypass Cloudflare) - NO FALLBACK
            $success = $this->send_events_to_ga4($events, $batch_events, $batch_consent);
            $transmission_error_message = "Failed to send events directly to Google Analytics";
        } else {
        
            $success = $this->send_batch_to_cloudflare($batch_events, $batch_consent, $events);
            $transmission_error_message = "Failed to send events to Cloudflare Worker";
        }

        if ($success) {
            // Mark all events as processed using unified table
            $this->event_logger->update_event_status($event_ids, 'completed');

        } else {
            // Mark events as failed with specific error message for chosen transmission method
            foreach ($event_ids as $event_id) {
                $this->event_logger->mark_event_failed($event_id, $transmission_error_message);
            }
            
            if ($this->logger) {
                $this->logger->error($transmission_error_message . " - " . count($event_ids) . " events marked as failed");
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
        } catch (\Exception $e) {
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
    private function send_batch_to_cloudflare($batch_events, $batch_consent = null, $events = null)
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
            'events' => array(),  // Will be populated with events including headers
            'batch' => true,
            'timestamp' => time()
        );
        
        // Add consent data if available
        if ($batch_consent) {
            $payload['consent'] = $batch_consent;
        }
        
        // Add original headers to each event for Cloudflare worker to use
        if ($events) {
            foreach ($batch_events as $index => $event_data) {
                $original_event = $events[$index];
                
                // Get original headers from the queued event
                $original_headers = GA4_Encryption_Util::decrypt_headers_from_storage($original_event->original_headers);
                
                // Create event payload with headers included
                $event_with_headers = $event_data;
                $event_with_headers['headers'] = $original_headers;
                
                
                $payload['events'][] = $event_with_headers;
            }
        } else {
            // Fallback: use events without headers if events array not available
            $payload['events'] = $batch_events;
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
                } catch (\Exception $e) {
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
        $worker_api_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_worker_api_key');
        if (!empty($worker_api_key)) {
            $headers['Authorization'] = 'Bearer ' . $worker_api_key;
        }

        // Store final payload and headers for each event if events are provided
        if ($events) {
            foreach ($events as $event) {
                // Check if payload was encrypted for Cloudflare
                $payload_encrypted = (isset($payload['encrypted']) && $payload['encrypted'] === true);
                $this->event_logger->update_event_final_data($event->id, $payload, $headers, 'cloudflare', $event->was_originally_encrypted, $payload_encrypted);
            }
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
     * Send events individually to Google Analytics (bypass Cloudflare)
     *
     * @since    3.0.0
     * @param    array    $events         The original event objects from database.
     * @param    array    $batch_events   The processed event data.
     * @param    array    $batch_consent  Batch-level consent data.
     * @return   bool     True if all events sent successfully, false otherwise.
     */
    private function send_events_to_ga4($events, $batch_events, $batch_consent = null)
    {
        $measurement_id = get_option('ga4_measurement_id', '');
        $api_secret = get_option('ga4_api_secret', '');
        
        if (empty($measurement_id) || empty($api_secret)) {
            if ($this->logger) {
                $this->logger->error("Missing GA4 measurement ID or API secret for direct transmission");
            }
            return false;
        }
        
        $success_count = 0;
        $total_events = count($batch_events);
        
        // Send each event individually
        foreach ($batch_events as $index => $event_data) {
            $original_event = $events[$index];
            
            // Transform event data to match Google Analytics expected format
            $final_payload = $this->transformer->transform_event_for_ga4($event_data, $events[$index], $batch_consent);
            
            // GA4 payload should NEVER be encrypted when sending directly to Google Analytics
            $payload_encrypted = false;
            
            // Get original headers from the queued event
            $original_headers = GA4_Encryption_Util::decrypt_headers_from_storage($original_event->original_headers);
            if (empty($original_headers) && $this->logger) {
                $this->logger->debug("No original headers found for event {$original_event->id}");
            }
            
            $result = $this->send_single_event_to_ga4($final_payload, $measurement_id, $api_secret, $original_headers);
            
            // Store final payload and headers for display purposes
            $this->event_logger->update_event_final_data($original_event->id, $final_payload, $result['headers'], 'ga4_direct', $original_event->was_originally_encrypted, $payload_encrypted);
            
            if ($result['success']) {
                $success_count++;
            } else {
                // Mark individual event as failed with specific GA4 error message
                $error_message = "Direct GA4 transmission failed";
                if (isset($result['error_details'])) {
                    $error_message .= ": " . $result['error_details'];
                }
                $this->event_logger->mark_event_failed($original_event->id, $error_message);
            }
        }
        
        // Return true if all events were sent successfully
        return $success_count === $total_events;
    }
    
    /**
     * Send a single event directly to Google Analytics
     *
     * @since    3.0.0
     * @param    array    $payload        The event payload.
     * @param    string   $measurement_id The GA4 measurement ID.
     * @param    string   $api_secret     The GA4 API secret.
     * @param    array    $original_headers Original request headers.
     * @return   array    Array with 'success' boolean and 'headers' array.
     */
    private function send_single_event_to_ga4($payload, $measurement_id, $api_secret, $original_headers = array())
    {
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . $measurement_id . '&api_secret=' . $api_secret;
        
        // Start with default headers
        $headers = array(
            'Content-Type' => 'application/json',
            'User-Agent' => 'GA4-Server-Side-Tagging-Direct/3.0.0'
        );
        
        // Map original headers to proper format (headers are stored with underscores)
        $header_mapping = array(
            'user_agent' => 'User-Agent',
            'accept_language' => 'Accept-Language', 
            'accept' => 'Accept',
            'referer' => 'Referer',
            'accept_encoding' => 'Accept-Encoding',
            'x_forwarded_for' => 'X-Forwarded-For',
            'x_real_ip' => 'X-Real-IP'
        );
        
        foreach ($header_mapping as $stored_key => $header_name) {
            if (isset($original_headers[$stored_key]) && !empty($original_headers[$stored_key])) {
                $headers[$header_name] = $original_headers[$stored_key];
            }
        }
        
        $response = wp_remote_post($url, array(
            'headers' => $headers,
            'body' => wp_json_encode($payload),
            'timeout' => 10,
            'sslverify' => true
        ));
        
        $result = array(
            'success' => false,
            'headers' => $headers,
            'error_details' => null
        );
        
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $result['error_details'] = "WordPress HTTP Error: " . $error_message;
            if ($this->logger) {
                $this->logger->error("Failed to send event to GA4: " . $error_message);
            }
            return $result;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code >= 200 && $response_code < 300) {
            $result['success'] = true;
        } else {
            $result['error_details'] = "HTTP $response_code" . (!empty($response_body) ? ": " . $response_body : "");
            if ($this->logger) {
                $this->logger->error("GA4 returned error code $response_code: " . $response_body);
            }
        }
        
        return $result;
    }


    /**
     * Get queue statistics
     *
     * @since    2.0.0
     * @return   array    Queue statistics.
     */
    public function get_queue_stats()
    {
        return $this->event_logger->get_queue_stats();
    }

    /**
     * Clear old processed events
     *
     * @since    2.0.0
     * @param    int    $days_old    Number of days to keep events.
     */
    public function cleanup_old_events($days_old = 7)
    {
        $result = $this->event_logger->cleanup_old_queue_events($days_old);

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
        // Get the last $limit queue items
        $result = $this->event_logger->get_queue_events_for_table(array(
            'limit' => $limit,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC'
        ));

        return $result['events'];
    }

    /**
     * Get events with filtering and pagination
     *
     * @since    2.0.0
     * @param    array    $args    Arguments for filtering and pagination.
     * @return   array    Events and pagination info.
     */
    public function get_events_for_table($args = array())
    {
        return $this->event_logger->get_queue_events_for_table($args);
    }
}