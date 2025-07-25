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
            final_payload longtext NULL,
            final_headers longtext NULL,
            original_headers longtext NULL,
            transmission_method varchar(50) DEFAULT 'cloudflare',
            was_originally_encrypted tinyint(1) DEFAULT 0,
            final_payload_encrypted tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            status varchar(20) DEFAULT 'pending',
            retry_count int(11) DEFAULT 0,
            error_message text NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at),
            KEY transmission_method (transmission_method)
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
     * @param    array     $event_data              The event data to queue.
     * @param    boolean   $is_encrypted            Whether the event data is encrypted.
     * @param    array     $original_headers        Original request headers.
     * @param    boolean   $was_originally_encrypted Whether the original request was encrypted.
     * @return   boolean   True if queued successfully, false otherwise.
     */
    public function queue_event($event_data, $is_encrypted = false, $original_headers = array(), $was_originally_encrypted = false)
    {
        global $wpdb;

        // Determine the intended transmission method when queuing
        $disable_cf_proxy = get_option('ga4_disable_cf_proxy', false);
        $intended_transmission_method = $disable_cf_proxy ? 'ga4_direct' : 'cloudflare';

        // Encrypt original_headers if encryption is enabled
        $encrypted_headers = $this->encrypt_headers_for_storage($original_headers);

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'event_data' => is_array($event_data) ? wp_json_encode($event_data) : $event_data,
                'is_encrypted' => $is_encrypted ? 1 : 0,
                'original_headers' => $encrypted_headers,
                'was_originally_encrypted' => $was_originally_encrypted ? 1 : 0,
                'transmission_method' => $intended_transmission_method,
                'status' => 'pending'
            ),
            array('%s', '%d', '%s', '%d', '%s', '%s')
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
        $batch_size = 1000;
        
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
            // Mark all events as processed
            $ids_placeholder = implode(',', array_fill(0, count($event_ids), '%d'));
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $this->table_name SET status = 'completed', processed_at = NOW() WHERE id IN ($ids_placeholder)",
                    ...$event_ids
                )
            );

        } else {
            // Mark events as failed with specific error message for chosen transmission method
            foreach ($event_ids as $event_id) {
                $this->mark_event_failed($event_id, $transmission_error_message);
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
                $original_headers = $this->decrypt_headers_from_storage($original_event->original_headers);
                
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
                $this->update_event_final_data($event->id, $payload, $headers, 'cloudflare', $event->was_originally_encrypted, $payload_encrypted);
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
            $final_payload = $this->transform_event_for_ga4($event_data, $events[$index], $batch_consent);
            
            // GA4 payload should NEVER be encrypted when sending directly to Google Analytics
            $payload_encrypted = false;
            
            // Get original headers from the queued event
            $original_headers = $this->decrypt_headers_from_storage($original_event->original_headers);
            if (empty($original_headers) && $this->logger) {
                $this->logger->debug("No original headers found for event {$original_event->id}");
            }
            
            $result = $this->send_single_event_to_ga4($final_payload, $measurement_id, $api_secret, $original_headers);
            
            // Store final payload and headers for display purposes
            $this->update_event_final_data($original_event->id, $final_payload, $result['headers'], 'ga4_direct', $original_event->was_originally_encrypted, $payload_encrypted);
            
            if ($result['success']) {
                $success_count++;
            } else {
                // Mark individual event as failed with specific GA4 error message
                $error_message = "Direct GA4 transmission failed";
                if (isset($result['error_details'])) {
                    $error_message .= ": " . $result['error_details'];
                }
                $this->mark_event_failed($original_event->id, $error_message);
            }
        }
        
        // Return true if all events were sent successfully
        return $success_count === $total_events;
    }
    
    /**
     * Transform event data to Google Analytics expected format
     *
     * @since    3.0.0
     * @param    array    $event_data       The processed event data.
     * @param    object   $original_event   The original queued event object.
     * @param    array    $batch_consent    Batch-level consent data.
     * @return   array    Transformed GA4 payload.
     */
    private function transform_event_for_ga4($event_data, $original_event, $batch_consent = null)
    {
        // Start with basic GA4 payload structure
        $ga4_payload = array(
            'client_id' => $event_data['client_id'] ?? $this->generate_client_id(),
            'events' => array(
                array(
                    'name' => $event_data['name'],
                    'params' => $event_data['params'] ?? array()
                )
            )
        );
        
        // Extract and move client_id from params to top level
        if (isset($event_data['params']['client_id'])) {
            $ga4_payload['client_id'] = $event_data['params']['client_id'];
            unset($ga4_payload['events'][0]['params']['client_id']);
        }
        
        // Add user_id at top level if present (and move from params if found there)
        if (isset($event_data['user_id'])) {
            $ga4_payload['user_id'] = $event_data['user_id'];
        } elseif (isset($event_data['params']['user_id'])) {
            $ga4_payload['user_id'] = $event_data['params']['user_id'];
            unset($ga4_payload['events'][0]['params']['user_id']);
        }
        
        // Add timestamp_micros at top level if present  
        if (isset($event_data['timestamp_micros'])) {
            $ga4_payload['timestamp_micros'] = $event_data['timestamp_micros'];
        }
        
        // Extract and add consent data at top level with privacy compliance
        $consent_data = null;
        
        // First priority: use batch-level consent data (for batch events)
        if ($batch_consent) {
            $consent_data = $batch_consent;
        } elseif (isset($event_data['consent'])) {
            // Second priority: individual event consent
            $consent_data = $event_data['consent'];
        } else {
            // Fallback: try to get consent from original event data
            $original_event_data = json_decode($original_event->event_data, true);
            if (isset($original_event_data['consent'])) {
                $consent_data = $original_event_data['consent'];
            }
        }
        
        // Always include consent data at top level since it's available and important for GA4
        if ($consent_data) {
            // Include ONLY the 2 allowed consent fields (ad_user_data and ad_personalization)
            // Filter out any other fields like consent_reason which GA4 doesn't accept
            $ga4_payload['consent'] = array();
            
            if (isset($consent_data['ad_user_data'])) {
                $ga4_payload['consent']['ad_user_data'] = $consent_data['ad_user_data'];
            }
            
            if (isset($consent_data['ad_personalization'])) {
                $ga4_payload['consent']['ad_personalization'] = $consent_data['ad_personalization'];
            }
            
            // Note: consent_reason and other consent fields are intentionally excluded
            // as GA4 only accepts ad_user_data and ad_personalization in the consent object
            
            // Apply consent-based processing (similar to Cloudflare worker)
            $ad_user_data_denied = (isset($consent_data['ad_user_data']) && $consent_data['ad_user_data'] === 'DENIED');
            $ad_personalization_denied = (isset($consent_data['ad_personalization']) && $consent_data['ad_personalization'] === 'DENIED');
            
            // Apply analytics consent denied rules (when ad_user_data is DENIED)
            if ($ad_user_data_denied) {
                $ga4_payload = $this->apply_analytics_consent_denied($ga4_payload);
            }
            
            // Apply advertising consent denied rules (when ad_personalization is DENIED)
            if ($ad_personalization_denied) {
                $ga4_payload = $this->apply_advertising_consent_denied($ga4_payload);
            }
        } else {
            // Default consent values if not available (conservative approach)
            $ga4_payload['consent'] = array(
                'ad_user_data' => 'DENIED',
                'ad_personalization' => 'DENIED'
            );
            $consent_denied = true;
        }
        
        // Extract location data from params and add at top level (respecting consent)
        $user_location = $this->extract_location_data($ga4_payload['events'][0]['params'], $consent_denied ?? false);
        if (!empty($user_location)) {
            $ga4_payload['user_location'] = $user_location;
        }
        
        // Get original headers for device extraction
        $original_headers = $this->decrypt_headers_from_storage($original_event->original_headers);
        
        // Extract device info from params and headers (consent-aware)
        $device_info = $this->extract_device_info($ga4_payload['events'][0]['params'], $consent_denied ?? false, $original_headers);
        if (!empty($device_info)) {
            $ga4_payload['device'] = $device_info;
        }
        
        // Extract user agent and add at top level (with privacy handling)
        if (isset($ga4_payload['events'][0]['params']['user_agent'])) {
            $user_agent = $ga4_payload['events'][0]['params']['user_agent'];
            
            // Option 1: Always use full user_agent (your preference)
            // User-Agent is generally considered less sensitive than precise location/user_id
            $ga4_payload['user_agent'] = $user_agent;
            
            // Option 2: Anonymize user_agent when consent denied (like Cloudflare worker)
            // Uncomment the lines below if you want to match Cloudflare worker behavior:
            /*
            if ($consent_denied ?? false) {
                $ga4_payload['user_agent'] = $this->anonymize_user_agent($user_agent);
            } else {
                $ga4_payload['user_agent'] = $user_agent;
            }
            */
            
            unset($ga4_payload['events'][0]['params']['user_agent']);
        }
        
        // Add IP override when consent is granted (analytics consent)
        $ip_override = $this->extract_client_ip_from_headers($original_headers);
        if (!empty($ip_override) && !($consent_denied ?? false)) {
            // Only add IP when analytics consent is granted
            $ga4_payload['ip_override'] = $ip_override;
        }
        
        // Add consent parameter to event params (similar to Cloudflare worker)
        if ($consent_data) {
            // Try multiple possible field names for consent reason
            $consent_reason = 'button_click'; // default
            if (isset($consent_data['consent_reason'])) {
                $consent_reason = $consent_data['consent_reason'];
            } elseif (isset($consent_data['reason'])) {
                $consent_reason = $consent_data['reason'];
            }
            
            $ad_personalization = isset($consent_data['ad_personalization']) ? $consent_data['ad_personalization'] : 'DENIED';
            $ad_user_data = isset($consent_data['ad_user_data']) ? $consent_data['ad_user_data'] : 'DENIED';
            
            $ga4_payload['events'][0]['params']['consent'] = "ad_personalization: {$ad_personalization}. ad_user_data: {$ad_user_data}. reason: {$consent_reason}";
        } else {
            // Try to get consent from the original event data one more time (different path)
            $original_event_data = json_decode($original_event->event_data, true);
            if (isset($original_event_data['consent'])) {
                $consent_data_fallback = $original_event_data['consent'];
                
                $consent_reason = 'button_click'; // default
                if (isset($consent_data_fallback['consent_reason'])) {
                    $consent_reason = $consent_data_fallback['consent_reason'];
                } elseif (isset($consent_data_fallback['reason'])) {
                    $consent_reason = $consent_data_fallback['reason'];
                }
                
                $ad_personalization = isset($consent_data_fallback['ad_personalization']) ? $consent_data_fallback['ad_personalization'] : 'DENIED';
                $ad_user_data = isset($consent_data_fallback['ad_user_data']) ? $consent_data_fallback['ad_user_data'] : 'DENIED';
                
                $ga4_payload['events'][0]['params']['consent'] = "ad_personalization: {$ad_personalization}. ad_user_data: {$ad_user_data}. reason: {$consent_reason}";
            } else {
                // Absolute fallback when no consent data available
                $ga4_payload['events'][0]['params']['consent'] = "ad_personalization: DENIED. ad_user_data: DENIED. reason: unknown";
            }
        }
        
        // Clean up params by removing fields that have been moved to top level
        $fields_to_remove = array(
            // Geographic data (moved to user_location)
            'geo_city', 'geo_country', 'geo_region', 'geo_continent', 
            'geo_city_tz', 'geo_country_tz', 'geo_latitude', 'geo_longitude',
            // Device data (moved to device object)
            'device_type', 'is_mobile', 'is_tablet', 'is_desktop', 
            'browser_name', 'browser_version', 'screen_resolution', 'screen_width', 'screen_height',
            'os_name', 'os_version', 'device_model', 'device_brand', 
            'mobile_model_name', 'mobile_brand_name',
            'viewport_width', 'viewport_height', 'language', 'accept_language',
            // User identification (moved to top level)
            'user_id'
        );
        
        foreach ($fields_to_remove as $field) {
            unset($ga4_payload['events'][0]['params'][$field]);
        }
        
        return $ga4_payload;
    }
    
    /**
     * Extract location data from event params
     *
     * @since    3.0.0
     * @param    array    $params         Event parameters.
     * @param    boolean  $consent_denied Whether consent was denied.
     * @return   array    Location data for GA4.
     */
    private function extract_location_data(&$params, $consent_denied = false)
    {
        $user_location = array();
        
        // If consent denied, use timezone-based location data (less precise, privacy compliant)
        if ($consent_denied) {
            // Use timezone-based location data for privacy compliance
            if (isset($params['timezone'])) {
                $timezone = $params['timezone'];
                $timezone_location = $this->get_location_from_timezone($timezone);
                if ($timezone_location) {
                    $user_location = array_merge($user_location, $timezone_location);
                }
            }
            
            // Fallback: use country from timezone data if available
            if (empty($user_location) && isset($params['geo_country_tz'])) {
                $country_name = $params['geo_country_tz'];
                $user_location['country_id'] = $this->convert_country_name_to_iso($country_name);
            } elseif (isset($params['geo_country'])) {
                // Also check geo_country for consent-denied cases
                $country_name = $params['geo_country'];
                $user_location['country_id'] = $this->convert_country_name_to_iso($country_name);
            }
            
            // Include general continent data if available
            if (isset($params['geo_continent'])) {
                $continent = $params['geo_continent'];
                if ($continent === 'Europe') {
                    $user_location['continent_id'] = '150'; // Europe
                    $user_location['subcontinent_id'] = '155'; // Western Europe
                }
            }
        } else {
            // Full location data when consent is granted
            
            // Extract city (precise location)
            if (isset($params['geo_city'])) {
                $user_location['city'] = $params['geo_city'];
            } elseif (isset($params['geo_city_tz'])) {
                $user_location['city'] = $params['geo_city_tz'];
            }
            
            // Extract country - GA4 expects country_id (ISO country code)
            if (isset($params['geo_country'])) {
                $country_name = $params['geo_country'];
                $user_location['country_id'] = $this->convert_country_name_to_iso($country_name);
            } elseif (isset($params['geo_country_tz'])) {
                // Fallback to timezone-based country
                $country_name = $params['geo_country_tz'];
                $user_location['country_id'] = $this->convert_country_name_to_iso($country_name);
            }
            
            // Extract continent and subcontinent IDs (GA4 uses numeric IDs)
            if (isset($params['geo_continent'])) {
                $continent = $params['geo_continent'];
                if ($continent === 'Europe') {
                    $user_location['continent_id'] = '150'; // Europe
                    $user_location['subcontinent_id'] = '155'; // Western Europe
                }
            }
        }
        
        return $user_location;
    }
    
    /**
     * Get location data from timezone (privacy-compliant, less precise)
     *
     * @since    3.0.0
     * @param    string   $timezone    Timezone string (e.g., "Europe/Amsterdam").
     * @return   array    Location data derived from timezone.
     */
    private function get_location_from_timezone($timezone)
    {
        $location = array();
        
        // Common European timezone mappings (privacy-compliant general locations)
        $timezone_map = array(
            // Netherlands
            'Europe/Amsterdam' => array(
                'country_id' => 'NL',
                'continent_id' => '150',
                'subcontinent_id' => '155'
            ),
            // Belgium  
            'Europe/Brussels' => array(
                'country_id' => 'BE',
                'continent_id' => '150', 
                'subcontinent_id' => '155'
            ),
            // Germany
            'Europe/Berlin' => array(
                'country_id' => 'DE',
                'continent_id' => '150',
                'subcontinent_id' => '155'
            ),
            // France
            'Europe/Paris' => array(
                'country_id' => 'FR',
                'continent_id' => '150',
                'subcontinent_id' => '155'
            ),
            // United Kingdom
            'Europe/London' => array(
                'country_id' => 'GB',
                'continent_id' => '150',
                'subcontinent_id' => '154' // Northern Europe
            ),
            // Spain
            'Europe/Madrid' => array(
                'country_id' => 'ES',
                'continent_id' => '150',
                'subcontinent_id' => '039' // Southern Europe
            ),
            // Italy
            'Europe/Rome' => array(
                'country_id' => 'IT',
                'continent_id' => '150',
                'subcontinent_id' => '039' // Southern Europe
            ),
            // United States
            'America/New_York' => array(
                'country_id' => 'US',
                'continent_id' => '003', // North America
                'subcontinent_id' => '021' // Northern America
            ),
            'America/Los_Angeles' => array(
                'country_id' => 'US',
                'continent_id' => '003',
                'subcontinent_id' => '021'
            ),
            'America/Chicago' => array(
                'country_id' => 'US',
                'continent_id' => '003',
                'subcontinent_id' => '021'
            ),
            // Canada
            'America/Toronto' => array(
                'country_id' => 'CA',
                'continent_id' => '003',
                'subcontinent_id' => '021'
            )
        );
        
        // Direct timezone match
        if (isset($timezone_map[$timezone])) {
            return $timezone_map[$timezone];
        }
        
        // Fallback: extract from timezone format (e.g., "Europe/Amsterdam" -> Europe, NL)
        if (strpos($timezone, '/') !== false) {
            $parts = explode('/', $timezone);
            $continent_name = $parts[0];
            $city_name = $parts[1] ?? '';
            
            // Map continent names to IDs
            $continent_mapping = array(
                'Europe' => array('continent_id' => '150', 'subcontinent_id' => '155'),
                'America' => array('continent_id' => '003', 'subcontinent_id' => '021'),
                'Asia' => array('continent_id' => '142', 'subcontinent_id' => '030'),
                'Africa' => array('continent_id' => '002', 'subcontinent_id' => '015'),
                'Australia' => array('continent_id' => '009', 'subcontinent_id' => '053')
            );
            
            if (isset($continent_mapping[$continent_name])) {
                $location = $continent_mapping[$continent_name];
                
                // Try to infer country from major cities (privacy-compliant general mapping)
                $city_country_map = array(
                    'Amsterdam' => 'NL', 'Brussels' => 'BE', 'Berlin' => 'DE',
                    'Paris' => 'FR', 'London' => 'GB', 'Madrid' => 'ES',
                    'Rome' => 'IT', 'New_York' => 'US', 'Los_Angeles' => 'US',
                    'Chicago' => 'US', 'Toronto' => 'CA'
                );
                
                if (isset($city_country_map[$city_name])) {
                    $location['country_id'] = $city_country_map[$city_name];
                }
            }
        }
        
        return $location;
    }
    
    /**
     * Anonymize User-Agent string for privacy compliance (optional)
     *
     * @since    3.0.0
     * @param    string   $user_agent    Original User-Agent string.
     * @return   string   Anonymized User-Agent string.
     */
    private function anonymize_user_agent($user_agent)
    {
        if (empty($user_agent)) {
            return '';
        }
        
        // Replace version numbers with x.x (removes specific software versions)
        $anonymized = preg_replace('/\d+\.\d+[\.\d]*/', 'x.x', $user_agent);
        
        // Replace system info in parentheses with (anonymous)
        $anonymized = preg_replace('/\([^)]*\)/', '(anonymous)', $anonymized);
        
        // Truncate to 100 characters to prevent potential fingerprinting
        $anonymized = substr($anonymized, 0, 100);
        
        return $anonymized;
    }
    
    /**
     * Extract device information from event params and headers with consent-aware filtering
     *
     * @since    3.0.0
     * @param    array    $params           Event parameters.
     * @param    boolean  $consent_denied   Whether analytics consent was denied.
     * @param    array    $headers          Original request headers.
     * @return   array    Device data for GA4.
     */
    private function extract_device_info(&$params, $consent_denied = false, $headers = array())
    {
        $device = array();
        
        // Parse User-Agent from headers if available
        $user_agent_data = array();
        if (!empty($headers['user_agent'])) {
            $user_agent_data = $this->parse_user_agent($headers['user_agent']);
        } elseif (!empty($params['user_agent'])) {
            $user_agent_data = $this->parse_user_agent($params['user_agent']);
        }
        
        // Always allowed device data (basic functionality, not personally identifiable)
        
        // Extract device category (most important field - always allowed)
        if (isset($params['device_type'])) {
            $device['category'] = $this->normalize_device_category($params['device_type']);
        } elseif (isset($params['is_mobile']) && $params['is_mobile']) {
            $device['category'] = 'mobile';
        } elseif (isset($params['is_tablet']) && $params['is_tablet']) {
            $device['category'] = 'tablet';
        } elseif (isset($params['is_desktop']) && $params['is_desktop']) {
            $device['category'] = 'desktop';
        } elseif (!empty($user_agent_data['device_type'])) {
            // Fallback to User-Agent parsed device type
            $device['category'] = $user_agent_data['device_type'];
        }
        
        // Extract language (important for localization - always allowed)
        if (isset($params['language'])) {
            $device['language'] = $this->normalize_language_code($params['language']);
        } elseif (isset($params['accept_language'])) {
            // Fallback to accept-language header
            $device['language'] = $this->extract_primary_language($params['accept_language']);
        } elseif (!empty($headers['accept_language'])) {
            // Fallback to headers accept-language
            $device['language'] = $this->extract_primary_language($headers['accept_language']);
        }
        
        // Consent-aware device data extraction
        if (!$consent_denied) {
            // Full device data when consent is granted
            
            // Screen resolution (useful for responsive design insights)
            if (isset($params['screen_resolution'])) {
                $device['screen_resolution'] = $this->normalize_screen_resolution($params['screen_resolution']);
            } elseif (isset($params['screen_width']) && isset($params['screen_height'])) {
                $device['screen_resolution'] = $params['screen_width'] . 'x' . $params['screen_height'];
            }
            
            // Operating system information
            if (isset($params['os_name'])) {
                $device['operating_system'] = $this->normalize_os_name($params['os_name']);
            } elseif (!empty($user_agent_data['os_name'])) {
                $device['operating_system'] = $this->normalize_os_name($user_agent_data['os_name']);
            }
            
            if (isset($params['os_version'])) {
                $device['operating_system_version'] = $this->normalize_version($params['os_version']);
            } elseif (!empty($user_agent_data['os_version'])) {
                $device['operating_system_version'] = $this->normalize_version($user_agent_data['os_version']);
            }
            
            // Browser information with version
            if (isset($params['browser_name'])) {
                $device['browser'] = $this->normalize_browser_name($params['browser_name']);
            } elseif (!empty($user_agent_data['browser_name'])) {
                $device['browser'] = $this->normalize_browser_name($user_agent_data['browser_name']);
            }
            
            if (isset($params['browser_version'])) {
                $device['browser_version'] = $this->normalize_version($params['browser_version']);
            } elseif (!empty($user_agent_data['browser_version'])) {
                $device['browser_version'] = $this->normalize_version($user_agent_data['browser_version']);
            }
            
            // Device model and brand (mainly for mobile devices)
            if (isset($params['device_model'])) {
                $device['model'] = $this->normalize_device_model($params['device_model']);
            }
            
            if (isset($params['device_brand'])) {
                $device['brand'] = $this->normalize_device_brand($params['device_brand']);
            }
            
            // Mobile-specific fields (legacy support)
            if (isset($params['mobile_model_name'])) {
                $device['model'] = $this->normalize_device_model($params['mobile_model_name']);
            }
            
            if (isset($params['mobile_brand_name'])) {
                $device['brand'] = $this->normalize_device_brand($params['mobile_brand_name']);
            }
            
        } else {
            // Consent denied - use generalized device data only
            
            // Generalized screen resolution categories instead of exact values
            if (isset($params['screen_resolution'])) {
                $device['screen_resolution'] = $this->generalize_screen_resolution($params['screen_resolution']);
            } elseif (isset($params['screen_width']) && isset($params['screen_height'])) {
                $resolution = $params['screen_width'] . 'x' . $params['screen_height'];
                $device['screen_resolution'] = $this->generalize_screen_resolution($resolution);
            }
            
            // Generalized OS information (major versions only)
            if (isset($params['os_name'])) {
                $device['operating_system'] = $this->generalize_os_name($params['os_name']);
            } elseif (!empty($user_agent_data['os_name'])) {
                $device['operating_system'] = $this->generalize_os_name($user_agent_data['os_name']);
            }
            
            if (isset($params['os_version'])) {
                $device['operating_system_version'] = $this->generalize_os_version($params['os_version']);
            } elseif (!empty($user_agent_data['os_version'])) {
                $device['operating_system_version'] = $this->generalize_os_version($user_agent_data['os_version']);
            }
            
            // Generalized browser information (major versions only)
            if (isset($params['browser_name'])) {
                $device['browser'] = $this->generalize_browser_name($params['browser_name']);
            } elseif (!empty($user_agent_data['browser_name'])) {
                $device['browser'] = $this->generalize_browser_name($user_agent_data['browser_name']);
            }
            
            if (isset($params['browser_version'])) {
                $device['browser_version'] = $this->generalize_browser_version($params['browser_version']);
            } elseif (!empty($user_agent_data['browser_version'])) {
                $device['browser_version'] = $this->generalize_browser_version($user_agent_data['browser_version']);
            }
            
            // No specific device model/brand when consent is denied
            // These could be used for fingerprinting
        }
        
        return $device;
    }
    
    /**
     * Normalize device category to GA4 standard values
     *
     * @since    3.0.0
     * @param    string   $category   Raw device category.
     * @return   string   Normalized category.
     */
    private function normalize_device_category($category)
    {
        $category = strtolower(trim($category));
        
        // Map common variations to GA4 standard values
        $category_map = array(
            'phone' => 'mobile',
            'smartphone' => 'mobile',
            'mobile phone' => 'mobile',
            'tablet' => 'tablet',
            'desktop' => 'desktop',
            'computer' => 'desktop',
            'pc' => 'desktop',
            'laptop' => 'desktop',
            'smart tv' => 'smart tv',
            'tv' => 'smart tv',
            'smarttv' => 'smart tv',
            'wearable' => 'wearable',
            'watch' => 'wearable',
            'smart watch' => 'wearable'
        );
        
        return isset($category_map[$category]) ? $category_map[$category] : $category;
    }
    
    /**
     * Normalize language code to ISO 639-1 format
     *
     * @since    3.0.0
     * @param    string   $language   Raw language string.
     * @return   string   Normalized language code.
     */
    private function normalize_language_code($language)
    {
        if (empty($language)) {
            return '';
        }
        
        // Extract primary language from locale (e.g., "en-US" -> "en")
        $language = strtolower(trim($language));
        if (strpos($language, '-') !== false) {
            return substr($language, 0, strpos($language, '-'));
        }
        
        return $language;
    }
    
    /**
     * Extract primary language from Accept-Language header
     *
     * @since    3.0.0
     * @param    string   $accept_language   Accept-Language header value.
     * @return   string   Primary language code.
     */
    private function extract_primary_language($accept_language)
    {
        if (empty($accept_language)) {
            return '';
        }
        
        // Parse Accept-Language header (e.g., "en-US,en;q=0.9,es;q=0.8")
        $languages = explode(',', $accept_language);
        $primary_lang = trim($languages[0]);
        
        // Remove quality factor if present
        if (strpos($primary_lang, ';') !== false) {
            $primary_lang = substr($primary_lang, 0, strpos($primary_lang, ';'));
        }
        
        return $this->normalize_language_code($primary_lang);
    }
    
    /**
     * Normalize screen resolution
     *
     * @since    3.0.0
     * @param    string   $resolution   Raw resolution string.
     * @return   string   Normalized resolution.
     */
    private function normalize_screen_resolution($resolution)
    {
        if (empty($resolution)) {
            return '';
        }
        
        // Ensure format is WIDTHxHEIGHT
        $resolution = preg_replace('/[^\d\x]/', '', $resolution);
        if (preg_match('/^(\d+)[x\*](\d+)$/', $resolution, $matches)) {
            return $matches[1] . 'x' . $matches[2];
        }
        
        return $resolution;
    }
    
    /**
     * Normalize OS name
     *
     * @since    3.0.0
     * @param    string   $os_name   Raw OS name.
     * @return   string   Normalized OS name.
     */
    private function normalize_os_name($os_name)
    {
        $os_name = trim($os_name);
        
        // Common OS name mappings
        $os_map = array(
            'Windows NT' => 'Windows',
            'Win32' => 'Windows',
            'Win64' => 'Windows',
            'Mac OS' => 'macOS',
            'Mac OS X' => 'macOS',
            'MacOS' => 'macOS',
            'iPhone OS' => 'iOS',
            'iPad OS' => 'iPadOS',
            'iPadOS' => 'iPadOS'
        );
        
        foreach ($os_map as $pattern => $normalized) {
            if (stripos($os_name, $pattern) !== false) {
                return $normalized;
            }
        }
        
        return $os_name;
    }
    
    /**
     * Normalize browser name
     *
     * @since    3.0.0
     * @param    string   $browser_name   Raw browser name.
     * @return   string   Normalized browser name.
     */
    private function normalize_browser_name($browser_name)
    {
        $browser_name = trim($browser_name);
        
        // Common browser name mappings
        $browser_map = array(
            'Google Chrome' => 'Chrome',
            'Mozilla Firefox' => 'Firefox',
            'Internet Explorer' => 'Internet Explorer',
            'Microsoft Edge' => 'Edge',
            'Safari' => 'Safari',
            'Opera' => 'Opera',
            'Samsung Internet' => 'Samsung Internet'
        );
        
        foreach ($browser_map as $pattern => $normalized) {
            if (stripos($browser_name, $pattern) !== false) {
                return $normalized;
            }
        }
        
        return $browser_name;
    }
    
    /**
     * Normalize version strings
     *
     * @since    3.0.0
     * @param    string   $version   Raw version string.
     * @return   string   Normalized version.
     */
    private function normalize_version($version)
    {
        if (empty($version)) {
            return '';
        }
        
        // Extract version numbers (e.g., "13.5.1" from "Version 13.5.1")
        if (preg_match('/(\d+(?:\.\d+)*(?:\.\d+)?)/', $version, $matches)) {
            return $matches[1];
        }
        
        return $version;
    }
    
    /**
     * Normalize device model
     *
     * @since    3.0.0
     * @param    string   $model   Raw device model.
     * @return   string   Normalized model.
     */
    private function normalize_device_model($model)
    {
        return trim($model);
    }
    
    /**
     * Normalize device brand
     *
     * @since    3.0.0
     * @param    string   $brand   Raw device brand.
     * @return   string   Normalized brand.
     */
    private function normalize_device_brand($brand)
    {
        return trim($brand);
    }
    
    /**
     * Generalize screen resolution for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $resolution   Exact resolution.
     * @return   string   Generalized resolution category.
     */
    private function generalize_screen_resolution($resolution)
    {
        if (empty($resolution)) {
            return '';
        }
        
        // Extract width and height
        if (preg_match('/^(\d+)[x\*](\d+)$/', $resolution, $matches)) {
            $width = intval($matches[1]);
            $height = intval($matches[2]);
            
            // Categorize by common resolution ranges
            if ($width <= 768) {
                return 'mobile'; // Mobile/small tablet
            } elseif ($width <= 1024) {
                return 'tablet'; // Tablet
            } elseif ($width <= 1366) {
                return 'laptop'; // Small laptop
            } elseif ($width <= 1920) {
                return 'desktop'; // Desktop/large laptop
            } else {
                return 'large'; // Large displays
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Generalize OS name for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $os_name   Specific OS name.
     * @return   string   Generalized OS category.
     */
    private function generalize_os_name($os_name)
    {
        $os_name = strtolower($os_name);
        
        if (strpos($os_name, 'windows') !== false) {
            return 'Windows';
        } elseif (strpos($os_name, 'mac') !== false || strpos($os_name, 'darwin') !== false) {
            return 'macOS';
        } elseif (strpos($os_name, 'ios') !== false) {
            return 'iOS';
        } elseif (strpos($os_name, 'android') !== false) {
            return 'Android';
        } elseif (strpos($os_name, 'linux') !== false) {
            return 'Linux';
        }
        
        return 'Other';
    }
    
    /**
     * Generalize OS version for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $os_version   Specific OS version.
     * @return   string   Generalized version.
     */
    private function generalize_os_version($os_version)
    {
        if (empty($os_version)) {
            return '';
        }
        
        // Extract major version only (e.g., "13.5.1" -> "13")
        if (preg_match('/^(\d+)/', $os_version, $matches)) {
            return $matches[1];
        }
        
        return '';
    }
    
    /**
     * Generalize browser name for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $browser_name   Specific browser name.
     * @return   string   Generalized browser category.
     */
    private function generalize_browser_name($browser_name)
    {
        $browser_name = strtolower($browser_name);
        
        if (strpos($browser_name, 'chrome') !== false) {
            return 'Chrome';
        } elseif (strpos($browser_name, 'firefox') !== false) {
            return 'Firefox';
        } elseif (strpos($browser_name, 'safari') !== false) {
            return 'Safari';
        } elseif (strpos($browser_name, 'edge') !== false) {
            return 'Edge';
        } elseif (strpos($browser_name, 'opera') !== false) {
            return 'Opera';
        }
        
        return 'Other';
    }
    
    /**
     * Generalize browser version for privacy (consent denied)
     *
     * @since    3.0.0
     * @param    string   $browser_version   Specific browser version.
     * @return   string   Generalized version.
     */
    private function generalize_browser_version($browser_version)
    {
        if (empty($browser_version)) {
            return '';
        }
        
        // Extract major version only (e.g., "136.0.7103.60" -> "136")
        if (preg_match('/^(\d+)/', $browser_version, $matches)) {
            return $matches[1];
        }
        
        return '';
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
     * Generate a client ID for GA4
     *
     * @since    3.0.0
     * @return   string   The generated client ID.
     */
    private function generate_client_id()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Update event's final payload and headers
     *
     * @since    3.0.0
     * @param    int      $event_id                The event ID.
     * @param    array    $final_payload           The final payload that was sent.
     * @param    array    $final_headers           The final headers that were sent.
     * @param    string   $transmission_method     The transmission method used.
     * @param    boolean  $was_originally_encrypted Whether the original request was encrypted.
     * @param    boolean  $final_payload_encrypted Whether the final payload was encrypted.
     */
    private function update_event_final_data($event_id, $final_payload, $final_headers = array(), $transmission_method = 'cloudflare', $was_originally_encrypted = false, $final_payload_encrypted = false)
    {
        global $wpdb;
        
        // Prepare headers for storage - encrypt if encryption is enabled
        $stored_headers = $final_headers;
        $jwt_encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        
        if ($jwt_encryption_enabled && !empty($final_headers)) {
            $encryption_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            if ($encryption_key) {
                try {
                    // Encrypt headers like payload
                    $encrypted_headers = GA4_Encryption_Util::create_permanent_jwt_token(wp_json_encode($final_headers), $encryption_key);
                    $stored_headers = array('encrypted' => true, 'jwt' => $encrypted_headers);
                } catch (\Exception $e) {
                    if ($this->logger) {
                        $this->logger->error("Failed to encrypt headers: " . $e->getMessage());
                    }
                    // Continue with unencrypted headers
                }
            }
        }
        
        $wpdb->update(
            $this->table_name,
            array(
                'final_payload' => wp_json_encode($final_payload),
                'final_headers' => wp_json_encode($stored_headers),
                'transmission_method' => $transmission_method,
                'was_originally_encrypted' => $was_originally_encrypted ? 1 : 0,
                'final_payload_encrypted' => $final_payload_encrypted ? 1 : 0
            ),
            array('id' => $event_id),
            array('%s', '%s', '%s', '%d', '%d'),
            array('%d')
        );
    }

    /**
     * Update event's final payload (backward compatibility)
     *
     * @since    3.0.0
     * @param    int      $event_id      The event ID.
     * @param    array    $final_payload The final payload that was sent.
     */
    private function update_event_final_payload($event_id, $final_payload)
    {
        $this->update_event_final_data($event_id, $final_payload, array());
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

    /**
     * Get events with filtering and pagination
     *
     * @since    2.0.0
     * @param    array    $args    Arguments for filtering and pagination.
     * @return   array    Events and pagination info.
     */
    public function get_events_for_table($args = array())
    {
        global $wpdb;

        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'status' => '',
            'search' => '',
            'orderby' => 'created_at',
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);

        // Build WHERE clause
        $where_conditions = array();
        $query_args = array();

        if (!empty($args['status'])) {
            $where_conditions[] = "status = %s";
            $query_args[] = $args['status'];
        }

        if (!empty($args['search'])) {
            $where_conditions[] = "(error_message LIKE %s OR id LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $query_args[] = $search_term;
            $query_args[] = $search_term;
        }

        $where_sql = '';
        if (!empty($where_conditions)) {
            $where_sql = ' WHERE ' . implode(' AND ', $where_conditions);
        }

        // Get total count
        $count_query = "SELECT COUNT(*) FROM $this->table_name" . $where_sql;
        if (!empty($query_args)) {
            $total_events = $wpdb->get_var($wpdb->prepare($count_query, $query_args));
        } else {
            $total_events = $wpdb->get_var($count_query);
        }

        // Get events
        $orderby = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        if (!$orderby) {
            $orderby = 'created_at DESC';
        }

        $query = "SELECT id, status, created_at, processed_at, retry_count, error_message, event_data, final_payload, final_headers, original_headers, transmission_method, was_originally_encrypted, final_payload_encrypted, is_encrypted
                  FROM $this->table_name 
                  $where_sql 
                  ORDER BY $orderby 
                  LIMIT %d OFFSET %d";

        $final_args = array_merge($query_args, array($args['limit'], $args['offset']));
        $events = $wpdb->get_results($wpdb->prepare($query, $final_args));

        return array(
            'events' => $events,
            'total' => intval($total_events)
        );
    }
    
    /**
     * Apply analytics consent denied rules (when ad_user_data is DENIED)
     * Similar to applyAnalyticsConsentDenied in Cloudflare worker
     *
     * @since    3.0.0
     * @param    array    $ga4_payload    The GA4 payload.
     * @return   array    Modified payload.
     */
    private function apply_analytics_consent_denied($ga4_payload)
    {
        // Remove or anonymize personal identifiers
        if (isset($ga4_payload['user_id'])) {
            unset($ga4_payload['user_id']);
        }
        
        // Use session-based client ID if available
        if (isset($ga4_payload['client_id']) && strpos($ga4_payload['client_id'], 'session_') !== 0) {
            // Keep session-based client IDs, anonymize persistent ones
            $timestamp = time();
            $ga4_payload['client_id'] = "session_{$timestamp}";
        }
        
        // Remove precise location data from params
        if (isset($ga4_payload['events'][0]['params'])) {
            $location_params = array('geo_latitude', 'geo_longitude', 'geo_city', 'geo_region', 'geo_country');
            foreach ($location_params as $param) {
                unset($ga4_payload['events'][0]['params'][$param]);
            }
            // Keep only continent-level location (geo_continent should already be set if available)
        }
        
        // Anonymize user agent if present
        if (isset($ga4_payload['events'][0]['params']['user_agent'])) {
            $ga4_payload['events'][0]['params']['user_agent'] = $this->anonymize_user_agent($ga4_payload['events'][0]['params']['user_agent']);
        }
        
        return $ga4_payload;
    }
    
    /**
     * Apply advertising consent denied rules (when ad_personalization is DENIED)
     * Similar to applyAdvertisingConsentDenied in Cloudflare worker
     *
     * @since    3.0.0
     * @param    array    $ga4_payload    The GA4 payload.
     * @return   array    Modified payload.
     */
    private function apply_advertising_consent_denied($ga4_payload)
    {
        if (!isset($ga4_payload['events'][0]['params'])) {
            return $ga4_payload;
        }
        
        $params = &$ga4_payload['events'][0]['params'];
        
        // Remove advertising attribution data
        $ad_params = array('gclid', 'content', 'term', 'originalGclid', 'originalContent', 'originalTerm');
        foreach ($ad_params as $param) {
            unset($params[$param]);
        }
        
        // Anonymize campaign data for paid traffic
        if (isset($params['campaign']) && 
            !in_array($params['campaign'], array('(organic)', '(direct)', '(not set)', '(referral)'))) {
            $params['campaign'] = '(denied consent)';
        }
        
        // Anonymize original campaign data for paid traffic
        if (isset($params['originalCampaign']) && 
            !in_array($params['originalCampaign'], array('(organic)', '(direct)', '(not set)', '(referral)'))) {
            $params['originalCampaign'] = '(denied consent)';
        }
        
        // Anonymize source/medium for paid traffic
        $paid_mediums = array('cpc', 'ppc', 'paidsearch', 'display', 'banner', 'cpm');
        if (isset($params['medium']) && in_array($params['medium'], $paid_mediums)) {
            $params['source'] = '(denied consent)';
            $params['medium'] = '(denied consent)';
        }
        
        // Anonymize original source/medium for paid traffic
        if (isset($params['originalMedium']) && in_array($params['originalMedium'], $paid_mediums)) {
            $params['originalSource'] = '(denied consent)';
            $params['originalMedium'] = '(denied consent)';
        }
        
        // Anonymize traffic type if it reveals paid advertising
        $paid_traffic_types = array('paid_search', 'paid_social', 'display', 'cpc');
        if (isset($params['traffic_type']) && in_array($params['traffic_type'], $paid_traffic_types)) {
            $params['traffic_type'] = '(denied consent)';
        }
        
        // Anonymize original traffic type if it reveals paid advertising
        if (isset($params['originalTrafficType']) && in_array($params['originalTrafficType'], $paid_traffic_types)) {
            $params['originalTrafficType'] = '(denied consent)';
        }
        
        return $ga4_payload;
    }
    
    /**
     * Extract client IP from stored headers
     *
     * @since    3.0.0
     * @param    array    $headers    The headers array.
     * @return   string             The client IP address or empty string.
     */
    private function extract_client_ip_from_headers($headers)
    {
        if (empty($headers) || !is_array($headers)) {
            return '';
        }
        
        // Check for IP from various headers (in order of preference)
        $ip_header_mapping = array(
            'cf_connecting_ip' => 'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'x_real_ip' => 'HTTP_X_REAL_IP',                  // Nginx proxy
            'x_forwarded_for' => 'HTTP_X_FORWARDED_FOR',      // Load balancer
            'x_forwarded' => 'HTTP_X_FORWARDED',              // Proxy
            'x_cluster_client_ip' => 'HTTP_X_CLUSTER_CLIENT_IP', // Cluster
            'forwarded_for' => 'HTTP_FORWARDED_FOR',          // Proxy
            'forwarded' => 'HTTP_FORWARDED',                  // Proxy
            'remote_addr' => 'REMOTE_ADDR'                    // Standard
        );
        
        foreach ($ip_header_mapping as $stored_key => $header_name) {
            if (isset($headers[$stored_key]) && !empty($headers[$stored_key])) {
                $ip = $headers[$stored_key];
                
                // Handle comma-separated IPs (take first one)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return '';
    }
    
    /**
     * Convert country name to ISO country code for GA4
     *
     * @since    3.0.0
     * @param    string   $country_name    Country name.
     * @return   string   ISO country code.
     */
    private function convert_country_name_to_iso($country_name)
    {
        // Common country name to ISO code mappings
        $country_mappings = array(
            'The Netherlands' => 'NL',
            'Netherlands' => 'NL',
            'Belgium' => 'BE',
            'Germany' => 'DE',
            'France' => 'FR',
            'United Kingdom' => 'GB',
            'United States' => 'US',
            'Canada' => 'CA',
            'Australia' => 'AU',
            'Japan' => 'JP',
            'China' => 'CN',
            'India' => 'IN',
            'Brazil' => 'BR',
            'Mexico' => 'MX',
            'Italy' => 'IT',
            'Spain' => 'ES',
            'Poland' => 'PL',
            'Sweden' => 'SE',
            'Norway' => 'NO',
            'Denmark' => 'DK',
            'Finland' => 'FI',
            'Switzerland' => 'CH',
            'Austria' => 'AT',
            'Czech Republic' => 'CZ',
            'Hungary' => 'HU',
            'Portugal' => 'PT',
            'Ireland' => 'IE',
            'Russia' => 'RU',
            'Turkey' => 'TR',
            'South Africa' => 'ZA',
            'Argentina' => 'AR',
            'Chile' => 'CL',
            'Colombia' => 'CO',
            'Peru' => 'PE',
            'Venezuela' => 'VE',
            'Thailand' => 'TH',
            'Malaysia' => 'MY',
            'Singapore' => 'SG',
            'Philippines' => 'PH',
            'Indonesia' => 'ID',
            'Vietnam' => 'VN',
            'South Korea' => 'KR',
            'Taiwan' => 'TW',
            'Hong Kong' => 'HK',
            'New Zealand' => 'NZ',
            'Israel' => 'IL',
            'Egypt' => 'EG',
            'Saudi Arabia' => 'SA',
            'United Arab Emirates' => 'AE',
            'Kuwait' => 'KW',
            'Qatar' => 'QA',
            'Bahrain' => 'BH',
            'Oman' => 'OM',
            'Jordan' => 'JO',
            'Lebanon' => 'LB',
            'Pakistan' => 'PK',
            'Bangladesh' => 'BD',
            'Sri Lanka' => 'LK',
            'Nepal' => 'NP',
            'Myanmar' => 'MM',
            'Cambodia' => 'KH',
            'Laos' => 'LA'
        );
        
        // Check if we have a direct mapping
        if (isset($country_mappings[$country_name])) {
            return $country_mappings[$country_name];
        }
        
        // If country name is already 2 characters (likely ISO code), return as-is
        if (strlen($country_name) === 2 && ctype_alpha($country_name)) {
            return strtoupper($country_name);
        }
        
        // Fallback: return the original name (not ideal but better than nothing)
        return $country_name;
    }

    /**
     * Encrypt headers for database storage using permanent JWT encryption
     *
     * @since    3.0.0
     * @param    array    $headers    The headers array to encrypt.
     * @return   string              The encrypted headers JSON or plain JSON if encryption disabled/fails.
     */
    private function encrypt_headers_for_storage($headers)
    {
        // If headers are empty, return empty JSON
        if (empty($headers)) {
            return wp_json_encode(array());
        }

        // Check if encryption is enabled
        $jwt_encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        if (!$jwt_encryption_enabled) {
            // Return unencrypted JSON when encryption is disabled
            return wp_json_encode($headers);
        }

        // Check if the encryption util class exists
        if (!class_exists('\GA4ServerSideTagging\Utilities\GA4_Encryption_Util')) {
            return wp_json_encode($headers); // Return plain JSON if encryption class not available
        }

        try {
            $encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            if (!$encryption_key) {
                return wp_json_encode($headers); // No encryption key available
            }

            // Encrypt headers with permanent key for database storage
            $headers_json = wp_json_encode($headers);
            $encrypted = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::create_permanent_jwt_token($headers_json, $encryption_key);
            
            if ($encrypted !== false) {
                // Return the encrypted JWT token directly as string
                return $encrypted;
            }

            // If encryption failed, return unencrypted JSON
            return wp_json_encode($headers);

        } catch (\Exception $e) {
            // Log encryption failure
            if ($this->logger) {
                $this->logger->error('Failed to encrypt headers for storage: ' . $e->getMessage());
            }
            // Return unencrypted JSON if encryption fails
            return wp_json_encode($headers);
        }
    }

    /**
     * Decrypt headers from database storage
     *
     * @since    3.0.0
     * @param    string    $stored_headers    The stored headers data (may be encrypted).
     * @return   array                       The decrypted headers array.
     */
    private function decrypt_headers_from_storage($stored_headers)
    {
        // If headers are empty, return empty array
        if (empty($stored_headers)) {
            return array();
        }

        // Try to decode as JSON first
        $headers_data = json_decode($stored_headers, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Not valid JSON, might be a direct JWT token
            $headers_data = $stored_headers;
        }

        // Check if encryption is enabled
        $jwt_encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        if (!$jwt_encryption_enabled) {
            // Return decoded JSON when encryption is disabled
            return is_array($headers_data) ? $headers_data : array();
        }

        // Check if the encryption util class exists
        if (!class_exists('\GA4ServerSideTagging\Utilities\GA4_Encryption_Util')) {
            return is_array($headers_data) ? $headers_data : array();
        }

        try {
            $encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            if (!$encryption_key) {
                return is_array($headers_data) ? $headers_data : array();
            }

            // Try to decrypt if it looks like encrypted data
            if (is_string($headers_data)) {
                // Try permanent JWT decryption
                $decrypted = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt_permanent_jwt_token($headers_data, $encryption_key);
                if ($decrypted !== false) {
                    $decoded = json_decode($decrypted, true);
                    return is_array($decoded) ? $decoded : array();
                }
            }

            // Return original data if decryption fails or data is not encrypted
            return is_array($headers_data) ? $headers_data : array();

        } catch (\Exception $e) {
            // Log decryption failure
            if ($this->logger) {
                $this->logger->error('Failed to decrypt headers from storage: ' . $e->getMessage());
            }
            // Return original data if decryption fails
            return is_array($headers_data) ? $headers_data : array();
        }
    }
    
    /**
     * Parse User-Agent string to extract device information
     *
     * @since    3.0.0
     * @param    string   $user_agent   User-Agent string.
     * @return   array    Parsed device information.
     */
    private function parse_user_agent($user_agent)
    {
        if (empty($user_agent)) {
            return array();
        }
        
        $parsed = array();
        
        // Device type detection
        $mobile_patterns = array(
            '/Mobile|iPhone|iPod|Android|BlackBerry|Opera Mini|IEMobile|Windows Phone/i'
        );
        $tablet_patterns = array(
            '/iPad|Tablet|Kindle|Silk|PlayBook/i'
        );
        
        if (preg_match('/iPad/i', $user_agent)) {
            $parsed['device_type'] = 'tablet';
        } elseif (preg_match($tablet_patterns[0], $user_agent)) {
            $parsed['device_type'] = 'tablet';
        } elseif (preg_match($mobile_patterns[0], $user_agent)) {
            $parsed['device_type'] = 'mobile';
        } else {
            $parsed['device_type'] = 'desktop';
        }
        
        // Operating System detection
        if (preg_match('/Windows NT (\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['os_name'] = 'Windows';
            $version_map = array(
                '10.0' => '10',
                '6.3' => '8.1',
                '6.2' => '8',
                '6.1' => '7',
                '6.0' => 'Vista',
                '5.1' => 'XP'
            );
            $parsed['os_version'] = isset($version_map[$matches[1]]) ? $version_map[$matches[1]] : $matches[1];
        } elseif (preg_match('/Mac OS X (\d+[._]\d+)/i', $user_agent, $matches)) {
            $parsed['os_name'] = 'macOS';
            $parsed['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/iPhone OS (\d+[._]\d+)/i', $user_agent, $matches)) {
            $parsed['os_name'] = 'iOS';
            $parsed['os_version'] = str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/Android (\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['os_name'] = 'Android';
            $parsed['os_version'] = $matches[1];
        } elseif (preg_match('/Linux/i', $user_agent)) {
            $parsed['os_name'] = 'Linux';
        }
        
        // Browser detection
        if (preg_match('/Chrome\/(\d+\.\d+)/i', $user_agent, $matches)) {
            // Check if it's actually Edge using Chrome engine
            if (preg_match('/Edg\/(\d+\.\d+)/i', $user_agent, $edge_matches)) {
                $parsed['browser_name'] = 'Edge';
                $parsed['browser_version'] = $edge_matches[1];
            } else {
                $parsed['browser_name'] = 'Chrome';
                $parsed['browser_version'] = $matches[1];
            }
        } elseif (preg_match('/Firefox\/(\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['browser_name'] = 'Firefox';
            $parsed['browser_version'] = $matches[1];
        } elseif (preg_match('/Safari\/[\d.]+/i', $user_agent) && !preg_match('/Chrome/i', $user_agent)) {
            $parsed['browser_name'] = 'Safari';
            if (preg_match('/Version\/(\d+\.\d+)/i', $user_agent, $matches)) {
                $parsed['browser_version'] = $matches[1];
            }
        } elseif (preg_match('/Opera[\/\s](\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['browser_name'] = 'Opera';
            $parsed['browser_version'] = $matches[1];
        } elseif (preg_match('/MSIE (\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['browser_name'] = 'Internet Explorer';
            $parsed['browser_version'] = $matches[1];
        } elseif (preg_match('/Trident.*rv:(\d+\.\d+)/i', $user_agent, $matches)) {
            $parsed['browser_name'] = 'Internet Explorer';
            $parsed['browser_version'] = $matches[1];
        }
        
        // Mobile device model detection (basic patterns)
        if ($parsed['device_type'] === 'mobile' || $parsed['device_type'] === 'tablet') {
            // iPhone detection
            if (preg_match('/iPhone/i', $user_agent)) {
                $parsed['device_brand'] = 'Apple';
                if (preg_match('/iPhone(\d+,\d+)/i', $user_agent, $matches)) {
                    $parsed['device_model'] = 'iPhone ' . $matches[1];
                } else {
                    $parsed['device_model'] = 'iPhone';
                }
            }
            // iPad detection
            elseif (preg_match('/iPad/i', $user_agent)) {
                $parsed['device_brand'] = 'Apple';
                $parsed['device_model'] = 'iPad';
            }
            // Samsung detection
            elseif (preg_match('/Samsung/i', $user_agent)) {
                $parsed['device_brand'] = 'Samsung';
                if (preg_match('/SM-([A-Z0-9]+)/i', $user_agent, $matches)) {
                    $parsed['device_model'] = 'SM-' . $matches[1];
                }
            }
            // Google Pixel detection
            elseif (preg_match('/Pixel (\d+)/i', $user_agent, $matches)) {
                $parsed['device_brand'] = 'Google';
                $parsed['device_model'] = 'Pixel ' . $matches[1];
            }
        }
        
        return $parsed;
    }
}