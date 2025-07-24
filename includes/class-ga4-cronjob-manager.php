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

        $result = $wpdb->insert(
            $this->table_name,
            array(
                'event_data' => is_array($event_data) ? wp_json_encode($event_data) : $event_data,
                'is_encrypted' => $is_encrypted ? 1 : 0,
                'original_headers' => wp_json_encode($original_headers),
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
            if ($this->logger) {
                $this->logger->debug("No valid events to send after processing");
            }
            return;
        }

        // Simple check: if disable CF proxy is enabled, use direct GA4
        $disable_cf_proxy = get_option('ga4_disable_cf_proxy', false);
        
        if ($disable_cf_proxy) {
            // Send events individually to GA4 (bypass Cloudflare) - NO FALLBACK
            if ($this->logger) {
                $this->logger->info("Using direct GA4 transmission (bypassing Cloudflare)");
            }
            $success = $this->send_events_to_ga4($events, $batch_events);
            $transmission_error_message = "Failed to send events directly to Google Analytics";
        } else {
            // Send batch to Cloudflare - NO FALLBACK
            if ($this->logger) {
                $this->logger->info("Using Cloudflare transmission");
            }
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
     * @return   bool     True if all events sent successfully, false otherwise.
     */
    private function send_events_to_ga4($events, $batch_events)
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
            $final_payload = $this->transform_event_for_ga4($event_data, $events[$index]);
            
            // GA4 payload should NEVER be encrypted when sending directly to Google Analytics
            $payload_encrypted = false;
            
            // Get original headers from the queued event
            $original_headers = array();
            if (!empty($original_event->original_headers)) {
                $original_headers = json_decode($original_event->original_headers, true) ?: array();
                if ($this->logger) {
                    $this->logger->debug("Retrieved original headers for event {$original_event->id}: " . wp_json_encode(array_keys($original_headers)));
                }
            } else {
                if ($this->logger) {
                    $this->logger->debug("No original headers found for event {$original_event->id}");
                }
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
        
        if ($this->logger) {
            $this->logger->info("Direct GA4 transmission completed: Sent $success_count/$total_events events individually to Google Analytics (bypassing Cloudflare)");
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
     * @return   array    Transformed GA4 payload.
     */
    private function transform_event_for_ga4($event_data, $original_event)
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
        if (isset($event_data['consent'])) {
            $consent_data = $event_data['consent'];
        } else {
            // Try to get consent from original event data
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
            
            // If consent is denied, strip sensitive data for privacy compliance
            $consent_denied = (
                (isset($consent_data['ad_user_data']) && $consent_data['ad_user_data'] === 'DENIED') ||
                (isset($consent_data['ad_personalization']) && $consent_data['ad_personalization'] === 'DENIED')
            );
            
            if ($consent_denied) {
                // Remove user_id for privacy compliance when consent denied
                unset($ga4_payload['user_id']);
                
                // Remove sensitive identifying parameters
                $sensitive_params = array('user_id', 'customer_id', 'login_status');
                foreach ($sensitive_params as $param) {
                    unset($ga4_payload['events'][0]['params'][$param]);
                }
                
                // Anonymize IP address by not including precise location data
                // Keep only general location (country/continent level)
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
        
        // Extract device info from params and add at top level
        $device_info = $this->extract_device_info($ga4_payload['events'][0]['params']);
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
        
        // Clean up params by removing fields that have been moved to top level
        $fields_to_remove = array(
            // Geographic data (moved to user_location)
            'geo_city', 'geo_country', 'geo_region', 'geo_continent', 
            'geo_city_tz', 'geo_country_tz', 'geo_latitude', 'geo_longitude',
            // Device data (moved to device)
            'device_type', 'is_mobile', 'is_tablet', 'is_desktop', 
            'browser_name', 'screen_resolution', 'os_name', 'device_model', 
            'device_brand', 'viewport_width', 'viewport_height',
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
                if ($country_name === 'Netherlands') {
                    $user_location['country_id'] = 'NL';
                } elseif ($country_name === 'Belgium') {
                    $user_location['country_id'] = 'BE';
                } else {
                    $user_location['country_id'] = $country_name;
                }
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
                if ($country_name === 'The Netherlands') {
                    $user_location['country_id'] = 'NL';
                } elseif ($country_name === 'Belgium') {
                    $user_location['country_id'] = 'BE';
                } else {
                    $user_location['country_id'] = $country_name; // Assume it's already ISO code
                }
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
     * Extract device information from event params
     *
     * @since    3.0.0
     * @param    array    $params    Event parameters.
     * @return   array    Device data for GA4.
     */
    private function extract_device_info(&$params)
    {
        $device = array();
        
        // Extract device category (most important field according to GA4 docs)
        if (isset($params['device_type'])) {
            $device['category'] = $params['device_type']; // mobile, desktop, tablet
        } elseif (isset($params['is_mobile']) && $params['is_mobile']) {
            $device['category'] = 'mobile';
        } elseif (isset($params['is_tablet']) && $params['is_tablet']) {
            $device['category'] = 'tablet';
        } elseif (isset($params['is_desktop']) && $params['is_desktop']) {
            $device['category'] = 'desktop';
        }
        
        // Extract language (important for localization)
        if (isset($params['language'])) {
            $device['language'] = $params['language'];
        }
        
        // Extract screen resolution (useful for responsive design insights)
        if (isset($params['screen_resolution'])) {
            $device['screen_resolution'] = $params['screen_resolution'];
        }
        
        // Extract browser information
        if (isset($params['browser_name'])) {
            $device['browser'] = $params['browser_name'];
        }
        
        // Extract operating system information if available
        if (isset($params['os_name'])) {
            $device['operating_system'] = $params['os_name'];
        }
        
        // Extract device model/brand if available (mainly for mobile)
        if (isset($params['device_model'])) {
            $device['mobile_model_name'] = $params['device_model'];
        }
        
        if (isset($params['device_brand'])) {
            $device['mobile_brand_name'] = $params['device_brand'];
        }
        
        // Extract viewport dimensions if available
        if (isset($params['viewport_width']) && isset($params['viewport_height'])) {
            $device['viewport_size'] = $params['viewport_width'] . 'x' . $params['viewport_height'];
        }
        
        return $device;
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
        
        // Log headers being used for debugging
        if ($this->logger) {
            $this->logger->debug("Direct GA4 transmission using headers: " . wp_json_encode($headers));
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
}