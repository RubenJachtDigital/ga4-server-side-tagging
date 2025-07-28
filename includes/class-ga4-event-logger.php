<?php

namespace GA4ServerSideTagging\Core;

/**
 * Manages comprehensive event logging for GA4 server-side tagging
 *
 * @since      2.1.0
 * @package    GA4_Server_Side_Tagging
 */

if (!defined('WPINC')) {
    die;
}

class GA4_Event_Logger
{
    /**
     * Database table name for event logs
     *
     * @since    2.1.0
     * @access   private
     * @var      string    $table_name    The name of the event logs table.
     */
    private $table_name;

    /**
     * Initialize the class and set its properties.
     *
     * @since    2.1.0
     */
    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ga4_event_logs';
        
        // Ensure table exists
        $this->ensure_table_exists();
    }

    /**
     * Ensure the event logs table exists
     *
     * @since    2.1.0
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
     * Create the event logs table if it doesn't exist
     *
     * @since    2.1.0
     */
    public function maybe_create_table()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            
            -- Event Identification
            event_name varchar(255) NULL,
            
            -- Dual Status Tracking
            monitor_status enum('allowed','denied','bot_detected','error') NOT NULL DEFAULT 'allowed',
            queue_status enum('pending','processing','completed','failed') NULL,
            
            -- Request Context
            ip_address varchar(45) NULL,
            user_agent text NULL,
            url varchar(2000) NULL,
            referrer varchar(2000) NULL,
            user_id bigint(20) NULL,
            session_id varchar(255) NULL,
            consent_given tinyint(1) DEFAULT NULL,
            
            -- Monitoring Data
            reason varchar(500) NULL,
            bot_detection_rules text NULL,
            
            -- Simplified Payload Columns (only 4)
            original_headers longtext NULL,
            original_payload longtext NULL,
            final_payload longtext NULL,
            final_headers longtext NULL,
            
            -- Processing Data
            transmission_method varchar(50) NULL,
            retry_count int(11) DEFAULT 0,
            error_message text NULL,
            cloudflare_response text NULL,
            processing_time_ms float NULL,
            batch_size int(11) DEFAULT 1,
            
            -- Encryption Tracking
            was_originally_encrypted tinyint(1) DEFAULT 0,
            final_payload_encrypted tinyint(1) DEFAULT 0,
            
            -- Timestamps
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            
            PRIMARY KEY (id),
            KEY monitor_status (monitor_status),
            KEY queue_status (queue_status),
            KEY event_name (event_name),
            KEY created_at (created_at),
            KEY ip_address (ip_address),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY transmission_method (transmission_method)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Log an event with comprehensive details
     *
     * @since    2.1.0
     * @param    array    $args    Event logging arguments.
     * @return   int|false         The inserted log ID or false on failure.
     */
    public function log_event($args)
    {
        global $wpdb;

        $defaults = array(
            'event_name' => '',
            'monitor_status' => 'allowed',
            'queue_status' => null,
            'reason' => null,
            'original_headers' => null,
            'original_payload' => null,
            'final_payload' => null,
            'final_headers' => null,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $this->get_user_agent(),
            'url' => $this->get_current_url(),
            'referrer' => $this->get_referrer(),
            'user_id' => get_current_user_id() ?: null,
            'session_id' => $this->get_session_id(),
            'consent_given' => null,
            'bot_detection_rules' => null,
            'cloudflare_response' => null,
            'processing_time_ms' => null,
            'batch_size' => 1,
            'transmission_method' => null,
            'retry_count' => 0,
            'error_message' => null,
            'was_originally_encrypted' => false,
            'final_payload_encrypted' => false,
            'processed_at' => null
        );

        $args = wp_parse_args($args, $defaults);

        // Handle payload encryption for the 4 payload columns
        foreach (['original_headers', 'original_payload', 'final_payload', 'final_headers'] as $field) {
            if (!empty($args[$field])) {
                if (is_array($args[$field]) || is_object($args[$field])) {
                    $serialized_data = json_encode($args[$field], JSON_PRETTY_PRINT);
                    $args[$field] = $this->encrypt_sensitive_data_for_storage($serialized_data);
                } elseif (is_string($args[$field])) {
                    $processed_data = $this->process_encrypted_payload_for_storage($args[$field]);
                    $args[$field] = $this->ensure_payload_encrypted_for_storage($processed_data);
                }
            }
        }

        if (is_array($args['bot_detection_rules']) || is_object($args['bot_detection_rules'])) {
            $serialized_bot_data = json_encode($args['bot_detection_rules'], JSON_PRETTY_PRINT);
            $args['bot_detection_rules'] = $this->encrypt_sensitive_data_for_storage($serialized_bot_data);
        }

        // Insert the log entry
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'event_name' => sanitize_text_field($args['event_name']),
                'monitor_status' => sanitize_text_field($args['monitor_status']),
                'queue_status' => $args['queue_status'] ? sanitize_text_field($args['queue_status']) : null,
                'reason' => sanitize_text_field($args['reason']),
                'original_headers' => $args['original_headers'],
                'original_payload' => $args['original_payload'],
                'final_payload' => $args['final_payload'],
                'final_headers' => $args['final_headers'],
                'ip_address' => sanitize_text_field($args['ip_address']),
                'user_agent' => sanitize_textarea_field($args['user_agent']),
                'url' => esc_url_raw($args['url']),
                'referrer' => esc_url_raw($args['referrer']),
                'user_id' => intval($args['user_id']),
                'session_id' => sanitize_text_field($args['session_id']),
                'consent_given' => is_null($args['consent_given']) ? null : (bool) $args['consent_given'],
                'bot_detection_rules' => $args['bot_detection_rules'],
                'cloudflare_response' => sanitize_textarea_field($args['cloudflare_response']),
                'processing_time_ms' => is_numeric($args['processing_time_ms']) ? floatval($args['processing_time_ms']) : null,
                'batch_size' => intval($args['batch_size']),
                'transmission_method' => sanitize_text_field($args['transmission_method']),
                'processed_at' => $args['processed_at'],
                'retry_count' => intval($args['retry_count']),
                'error_message' => sanitize_textarea_field($args['error_message']),
                'was_originally_encrypted' => (bool) $args['was_originally_encrypted'],
                'final_payload_encrypted' => (bool) $args['final_payload_encrypted']
            ),
            array(
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%f', '%d', '%s', '%s', '%d', '%s', '%d', '%d'
            )
        );

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get paginated event logs with filtering
     *
     * @since    2.1.0
     * @param    array    $args    Query arguments.
     * @return   array             Array containing results and pagination info.
     */
    public function get_event_logs($args = array())
    {
        global $wpdb;

        $defaults = array(
            'page' => 1,
            'per_page' => 50,
            'event_name' => '',
            'event_status' => '',
            'search' => '',
            'date_from' => '',
            'date_to' => '',
            'orderby' => 'created_at',
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array('1=1');
        $where_values = array();

        // Filter by event name
        if (!empty($args['event_name'])) {
            $where_clauses[] = 'event_name = %s';
            $where_values[] = $args['event_name'];
        }

        // Filter by event status
        if (!empty($args['event_status'])) {
            $where_clauses[] = 'event_status = %s';
            $where_values[] = $args['event_status'];
        }

        // Search functionality
        if (!empty($args['search'])) {
            $where_clauses[] = '(event_name LIKE %s OR reason LIKE %s OR payload LIKE %s OR ip_address LIKE %s OR user_agent LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        // Date range filtering
        if (!empty($args['date_from'])) {
            $where_clauses[] = 'created_at >= %s';
            $where_values[] = $args['date_from'] . ' 00:00:00';
        }

        if (!empty($args['date_to'])) {
            $where_clauses[] = 'created_at <= %s';
            $where_values[] = $args['date_to'] . ' 23:59:59';
        }

        $where_sql = implode(' AND ', $where_clauses);

        // Sanitize ordering
        $allowed_orderby = array('id', 'event_name', 'event_status', 'created_at', 'ip_address');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Get total count
        $count_sql = "SELECT COUNT(*) FROM $this->table_name WHERE $where_sql";
        if (!empty($where_values)) {
            $count_sql = $wpdb->prepare($count_sql, $where_values);
        }
        $total_items = $wpdb->get_var($count_sql);

        // Calculate pagination
        $per_page = max(1, intval($args['per_page']));
        $page = max(1, intval($args['page']));
        $offset = ($page - 1) * $per_page;
        $total_pages = ceil($total_items / $per_page);

        // Get results
        $results_sql = "SELECT * FROM $this->table_name WHERE $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $final_values = array_merge($where_values, array($per_page, $offset));
        $results_sql = $wpdb->prepare($results_sql, $final_values);
        
        $results = $wpdb->get_results($results_sql);

        // Decrypt payloads and headers for display
        foreach ($results as $result) {
            if (!empty($result->payload)) {
                $result->payload = $this->decrypt_payload_for_display($result->payload);
            }
            if (!empty($result->headers)) {
                $result->headers = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt_headers_from_storage($result->headers);
            }
            if (!empty($result->bot_detection_rules)) {
                $result->bot_detection_rules = $this->decrypt_payload_for_display($result->bot_detection_rules);
            }
        }

        return array(
            'results' => $results,
            'total_items' => $total_items,
            'total_pages' => $total_pages,
            'current_page' => $page,
            'per_page' => $per_page
        );
    }

    /**
     * Get event statistics
     *
     * @since    2.1.0
     * @return   array    Event statistics.
     */
    public function get_event_stats()
    {
        global $wpdb;

        $stats = array();

        // Total events by status
        $status_stats = $wpdb->get_results("
            SELECT event_status, COUNT(*) as count 
            FROM $this->table_name 
            GROUP BY event_status
        ", ARRAY_A);

        foreach ($status_stats as $stat) {
            $stats['by_status'][$stat['event_status']] = intval($stat['count']);
        }

        // Events in last 24 hours
        $stats['last_24h'] = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM $this->table_name 
            WHERE created_at >= %s
        ", date('Y-m-d H:i:s', strtotime('-24 hours'))));

        // Most common event names
        $common_events = $wpdb->get_results("
            SELECT event_name, COUNT(*) as count 
            FROM $this->table_name 
            GROUP BY event_name 
            ORDER BY count DESC 
            LIMIT 10
        ", ARRAY_A);

        $stats['common_events'] = $common_events;

        return $stats;
    }

    /**
     * Get recent events for display
     *
     * @since    2.1.0
     * @param    int    $limit    Number of events to retrieve.
     * @return   array           Array of recent events.
     */
    public function get_recent_events($limit = 100)
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM $this->table_name 
            ORDER BY created_at DESC 
            LIMIT %d
        ", $limit));

        // Decrypt payloads and headers for display
        foreach ($results as $result) {
            if (!empty($result->payload)) {
                $result->payload = $this->decrypt_payload_for_display($result->payload);
            }
            if (!empty($result->headers)) {
                $result->headers = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt_headers_from_storage($result->headers);
            }
            if (!empty($result->bot_detection_rules)) {
                $result->bot_detection_rules = $this->decrypt_payload_for_display($result->bot_detection_rules);
            }
        }

        return $results ?: array();
    }

    /**
     * Get table statistics similar to cronjob manager
     *
     * @since    2.1.0
     * @return   array    Table statistics.
     */
    public function get_table_stats()
    {
        global $wpdb;

        $stats = array();

        // Total events
        $stats['total'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name");

        // Events by monitor status
        $stats['allowed'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE monitor_status = 'allowed'");
        $stats['denied'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE monitor_status = 'denied'");
        $stats['bot_detected'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE monitor_status = 'bot_detected'");
        $stats['error'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE monitor_status = 'error'");

        // Events in last 24 hours
        $stats['last_24h'] = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM $this->table_name 
            WHERE created_at >= %s
        ", date('Y-m-d H:i:s', strtotime('-24 hours'))));

        // Events in last hour
        $stats['last_1h'] = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM $this->table_name 
            WHERE created_at >= %s
        ", date('Y-m-d H:i:s', strtotime('-1 hour'))));

        // Top event names
        $top_events = $wpdb->get_results("
            SELECT event_name, COUNT(*) as count 
            FROM $this->table_name 
            WHERE event_name IS NOT NULL AND event_name != ''
            GROUP BY event_name 
            ORDER BY count DESC 
            LIMIT 5
        ", ARRAY_A);

        $stats['top_events'] = $top_events ?: array();

        // Average processing time
        $avg_processing = $wpdb->get_var("
            SELECT AVG(processing_time_ms) 
            FROM $this->table_name 
            WHERE processing_time_ms IS NOT NULL 
            AND processing_time_ms > 0
        ");

        $stats['avg_processing_time'] = $avg_processing ? round($avg_processing, 2) : 0;

        return $stats;
    }

    /**
     * Get events with detailed table display format
     *
     * @since    2.1.0
     * @param    array    $args    Query arguments.
     * @return   array             Events formatted for table display.
     */
    public function get_events_for_table($args = array())
    {
        global $wpdb;

        $defaults = array(
            'limit' => 50,
            'offset' => 0,
            'status' => '',
            'event_name' => '',
            'search' => '',
            'orderby' => 'created_at',
            'order' => 'DESC'
        );

        $args = wp_parse_args($args, $defaults);

        $where_clauses = array();
        $where_values = array();

        // Filter by monitor status (single-row approach)
        if (!empty($args['status'])) {
            $where_clauses[] = 'monitor_status = %s';
            $where_values[] = $args['status'];
        }

        // Filter by event name
        if (!empty($args['event_name'])) {
            $where_clauses[] = 'event_name = %s';
            $where_values[] = $args['event_name'];
        }

        // Search handling: we'll search in both database fields and encrypted payload fields
        $search_term = '';
        $has_search = false;
        if (!empty($args['search'])) {
            $search_term = $args['search'];
            $has_search = true;
            // For encrypted payload search, we need to get more records and filter them after decryption
            // Don't add database search filters here - we'll get all records and filter in PHP
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Get total count first
        $count_query = "SELECT COUNT(*) FROM $this->table_name $where_sql";
        if (!empty($where_values)) {
            $total_events = $wpdb->get_var($wpdb->prepare($count_query, $where_values));
        } else {
            $total_events = $wpdb->get_var($count_query);
        }

        // Sanitize ordering for single-row structure
        $allowed_orderby = array('id', 'event_name', 'monitor_status', 'queue_status', 'created_at', 'ip_address', 'processing_time_ms');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // When searching, get more records to search through encrypted content
        if ($has_search) {
            // Get a larger batch to search through encrypted content
            $search_limit = max($args['limit'] * 3, 100); // Get more records for searching
            $search_offset = 0; // Start from beginning for accurate search
        } else {
            $search_limit = $args['limit'];
            $search_offset = $args['offset'];
        }

        // Build query
        $query = "SELECT * FROM $this->table_name $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $final_values = array_merge($where_values, array($search_limit, $search_offset));

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $final_values);
        } else {
            $query = $wpdb->prepare($query, $search_limit, $search_offset);
        }

        $results = $wpdb->get_results($query) ?: array();

        // Decrypt the 4 payload columns and perform payload search if needed
        $filtered_results = array();
        foreach ($results as $result) {
            // Decrypt payload columns
            $original_payload_decrypted = '';
            $final_payload_decrypted = '';
            $original_headers_decrypted = '';
            $final_headers_decrypted = '';
            
            if (!empty($result->original_payload)) {
                $original_payload_decrypted = $this->decrypt_payload_for_display($result->original_payload);
                $result->original_payload = $original_payload_decrypted;
            }
            if (!empty($result->final_payload)) {
                $final_payload_decrypted = $this->decrypt_payload_for_display($result->final_payload);
                $result->final_payload = $final_payload_decrypted;
            }
            if (!empty($result->original_headers)) {
                $original_headers_decrypted = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt_headers_from_storage($result->original_headers);
                $result->original_headers = $original_headers_decrypted;
            }
            if (!empty($result->final_headers)) {
                $final_headers_decrypted = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt_headers_from_storage($result->final_headers);
                $result->final_headers = $final_headers_decrypted;
            }
            if (!empty($result->bot_detection_rules)) {
                $result->bot_detection_rules = $this->decrypt_payload_for_display($result->bot_detection_rules);
            }
            
            // Add backward compatibility fields for admin display
            $result->event_status = $result->monitor_status; // For legacy display code
            $result->payload = $result->original_payload; // For legacy display code
            $result->headers = $result->original_headers; // For legacy display code
            
            // If searching, check all fields
            if ($has_search) {
                $search_found = false;
                
                // Search in database fields (non-encrypted)
                $db_fields = array(
                    $result->id ?? '',
                    $result->event_name ?? '',
                    $result->reason ?? '',
                    $result->ip_address ?? '',
                    $result->user_agent ?? ''
                );
                
                foreach ($db_fields as $field_content) {
                    if (stripos((string)$field_content, $search_term) !== false) {
                        $search_found = true;
                        break;
                    }
                }
                
                // Search in decrypted payload fields
                if (!$search_found) {
                    $payload_fields = array(
                        $original_payload_decrypted,
                        $final_payload_decrypted,
                        $original_headers_decrypted,
                        $final_headers_decrypted
                    );
                    
                    foreach ($payload_fields as $field_content) {
                        // Convert arrays to JSON strings for searching
                        $search_content = is_array($field_content) ? wp_json_encode($field_content) : (string)$field_content;
                        if (stripos($search_content, $search_term) !== false) {
                            $search_found = true;
                            break;
                        }
                    }
                }
                
                if ($search_found) {
                    $filtered_results[] = $result;
                }
            } else {
                $filtered_results[] = $result;
            }
        }
        
        // Apply pagination to filtered results if we performed search
        if ($has_search) {
            $total_filtered = count($filtered_results);
            $filtered_results = array_slice($filtered_results, $args['offset'], $args['limit']);
            // Update total count for pagination
            $total_events = $total_filtered;
        }
        
        $results = $filtered_results;

        return array(
            'results' => $results,
            'total' => intval($total_events)
        );
    }

    /**
     * Get client IP address
     *
     * @since    2.1.0
     * @return   string    Client IP address.
     */
    private function get_client_ip()
    {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        );

        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated list (take first IP)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Basic IP validation
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    }

    /**
     * Get user agent
     *
     * @since    2.1.0
     * @return   string    User agent string.
     */
    private function get_user_agent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    }

    /**
     * Get current URL
     *
     * @since    2.1.0
     * @return   string    Current URL.
     */
    private function get_current_url()
    {
        if (!empty($_SERVER['HTTP_REFERER'])) {
            return $_SERVER['HTTP_REFERER'];
        }
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        
        return $protocol . $host . $uri;
    }

    /**
     * Get referrer URL
     *
     * @since    2.1.0
     * @return   string    Referrer URL.
     */
    private function get_referrer()
    {
        return isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
    }

    /**
     * Get session ID
     *
     * @since    2.1.0
     * @return   string    Session ID.
     */
    private function get_session_id()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return session_id();
        }

        // Try to get from cookies if available
        if (isset($_COOKIE['ga4_session_id'])) {
            return $_COOKIE['ga4_session_id'];
        }

        return '';
    }

    /**
     * Clean old logs (keep last 30 days by default)
     *
     * @since    2.1.0
     * @param    int    $days    Number of days to keep.
     * @return   int|false       Number of deleted rows or false on error.
     */
    public function cleanup_old_logs($days = 30)
    {
        global $wpdb;

        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM $this->table_name WHERE created_at < %s",
            $cutoff_date
        ));
    }

    /**
     * Process encrypted payload for database storage
     * - Decrypt time_jwt if present
     * - Re-encrypt with permanent key if encryption is enabled
     * - Store encrypted or plain data based on settings
     *
     * @since    2.1.0
     * @param    string    $payload    The payload data (may be encrypted).
     * @return   string               The processed payload for database storage.
     */
    private function process_encrypted_payload_for_storage($payload)
    {
        // Check if the encryption util class exists
        if (!class_exists('\GA4ServerSideTagging\Utilities\GA4_Encryption_Util')) {
            return $payload; // Return original if encryption class not available
        }

        try {
            $encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
            $encryption_key = null;
            
            if ($encryption_enabled) {
                $encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            }
            
            // Try to parse as JSON first to see if it might be encrypted
            $payload_data = json_decode($payload, true);
            $decrypted_data = null;
            
            // Check for time-based JWT encryption first
            if (is_array($payload_data) && isset($payload_data['time_jwt']) && !empty($payload_data['time_jwt'])) {
                $decrypted_data = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::verify_time_based_jwt($payload_data['time_jwt']);
                if ($decrypted_data !== false) {
                    // Successfully decrypted time_jwt
                    if ($encryption_enabled && $encryption_key) {
                        // Re-encrypt with permanent key for database storage
                        $payload_to_encrypt = is_array($decrypted_data) ? json_encode($decrypted_data) : $decrypted_data;
                        $re_encrypted = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::encrypt($payload_to_encrypt, $encryption_key);
                        if ($re_encrypted !== false) {
                            return $re_encrypted; // Store re-encrypted data
                        }
                    }
                    // If re-encryption fails or is disabled, store decrypted data
                    return is_array($decrypted_data) ? json_encode($decrypted_data, JSON_PRETTY_PRINT) : $decrypted_data;
                }
            }

            // Try regular JWT with stored encryption key (already encrypted with permanent key)
            if ($encryption_enabled && $encryption_key) {
                // Check if this looks like an encrypted JWT format
                if (is_array($payload_data) && (isset($payload_data['encrypted']) || isset($payload_data['jwt']))) {
                    // This is already encrypted with permanent key, keep as-is for database
                    return $payload;
                }
                
                // Try decrypting as permanent key encrypted data to verify it's encrypted
                $test_decrypt = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt($payload, $encryption_key);
                if ($test_decrypt !== false) {
                    // This is already encrypted with permanent key, keep as-is
                    return $payload;
                }
            }

            // If no encryption or not encrypted, return original payload
            return $payload;
        } catch (\Exception $e) {
            // Log processing failure but don't break the logging process
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GA4 Event Logger: Failed to process encrypted payload - ' . $e->getMessage());
            }
            return $payload; // Return original payload if processing fails
        }
    }

    /**
     * Encrypt sensitive data for database storage
     *
     * @since    2.1.0
     * @param    string    $data    The data to encrypt (already serialized).
     * @return   string            The encrypted data for storage or original if encryption fails.
     */
    private function encrypt_sensitive_data_for_storage($data)
    {
        // Check if the encryption util class exists
        if (!class_exists('\GA4ServerSideTagging\Utilities\GA4_Encryption_Util')) {
            return $data; // Return original if encryption class not available
        }

        try {
            $encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
            
            if (!$encryption_enabled) {
                return $data; // No encryption enabled, return as-is
            }
            
            $encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            if (!$encryption_key) {
                return $data; // No encryption key available
            }

            // Encrypt the data with permanent key (no expiry for database storage)
            $encrypted = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::create_permanent_jwt_token($data, $encryption_key);
            if ($encrypted !== false) {
                return $encrypted; // Successfully encrypted
            }

            // If encryption failed, return original data
            return $data;
        } catch (\Exception $e) {
            // Log encryption failure but don't break the logging process
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GA4 Event Logger: Failed to encrypt sensitive data for storage - ' . $e->getMessage());
            }
            return $data; // Return original data if encryption fails
        }
    }

    /**
     * Ensure payload is encrypted for database storage
     * This method checks if data is already encrypted and encrypts if needed
     *
     * @since    2.1.0
     * @param    string    $payload    The payload data to potentially encrypt.
     * @return   string               The encrypted payload for storage.
     */
    private function ensure_payload_encrypted_for_storage($payload)
    {
        // Check if the encryption util class exists
        if (!class_exists('\GA4ServerSideTagging\Utilities\GA4_Encryption_Util')) {
            return $payload; // Return original if encryption class not available
        }

        try {
            $encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
            
            if (!$encryption_enabled) {
                return $payload; // No encryption enabled, return as-is
            }
            
            $encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            if (!$encryption_key) {
                return $payload; // No encryption key available
            }

            // Check if already encrypted by trying to decrypt
            $test_decrypt = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt_permanent_jwt_token($payload, $encryption_key);
            if ($test_decrypt !== false) {
                // Already encrypted, return as-is
                return $payload;
            }

            // Not encrypted, encrypt it with permanent key
            $encrypted = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::create_permanent_jwt_token($payload, $encryption_key);
            if ($encrypted !== false) {
                return $encrypted; // Successfully encrypted
            }

            // If encryption failed, return original data
            return $payload;
        } catch (\Exception $e) {
            // Log encryption failure but don't break the logging process
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GA4 Event Logger: Failed to ensure payload encryption for storage - ' . $e->getMessage());
            }
            return $payload; // Return original data if encryption fails
        }
    }

    /**
     * Decrypt payload when retrieving from database for display
     *
     * @since    2.1.0
     * @param    string    $payload    The payload data from database (may be encrypted).
     * @return   string               The decrypted payload for display.
     */
    private function decrypt_payload_for_display($payload)
    {
        // Check if the encryption util class exists
        if (!class_exists('\GA4ServerSideTagging\Utilities\GA4_Encryption_Util')) {
            return $payload; // Return original if encryption class not available
        }

        try {
            $encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
            
            if (!$encryption_enabled) {
                return $payload; // No encryption enabled, return as-is
            }
            
            $encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            if (!$encryption_key) {
                return $payload; // No encryption key available
            }

            // Try to decrypt with permanent key
            $decrypted = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt($payload, $encryption_key);
            if ($decrypted !== false) {
                return $decrypted; // Successfully decrypted
            }

            // If decryption failed, return original (might not be encrypted)
            return $payload;
        } catch (\Exception $e) {
            // Log decryption failure but don't break the display process
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GA4 Event Logger: Failed to decrypt payload for display - ' . $e->getMessage());
            }
            return $payload; // Return original payload if decryption fails
        }
    }

    /**
     * Create initial event record with queue status (single-row approach)
     *
     * @since    2.1.0
     * @param    array|string $event_data            The event data to queue (array) or raw request body (string).
     * @param    string    $monitor_status          Monitor status (allowed/denied/bot_detected/error).
     * @param    array     $original_headers        Original request headers.
     * @param    boolean   $was_originally_encrypted Whether the original request was encrypted.
     * @param    array     $additional_data         Additional event context data.
     * @return   int|false Event ID if successful, false otherwise.
     */
    public function create_event_record($event_data, $monitor_status = 'allowed', $original_headers = array(), $was_originally_encrypted = false, $additional_data = array())
    {
        // Determine the intended transmission method
        $disable_cf_proxy = get_option('ga4_disable_cf_proxy', false);
        $intended_transmission_method = $disable_cf_proxy ? 'ga4_direct' : 'cloudflare';

        // Set queue status based on monitor status
        $queue_status = ($monitor_status === 'allowed') ? 'pending' : null;

        // Start with base required fields
        $base_args = array(
            'monitor_status' => $monitor_status,
            'queue_status' => $queue_status,
            'original_headers' => $this->prepare_headers_for_storage($original_headers),
            'was_originally_encrypted' => $was_originally_encrypted,
            'transmission_method' => $intended_transmission_method
        );
        
        // Only set original_payload if not already provided in additional_data
        if (!isset($additional_data['original_payload'])) {
            $base_args['original_payload'] = is_array($event_data) ? wp_json_encode($event_data) : $event_data;
        }
        
        // Merge additional data with base args (additional_data takes priority)
        $event_args = array_merge($base_args, $additional_data);

        return $this->log_event($event_args);
    }

    /**
     * Queue an event for processing (single-row approach)
     *
     * @since    2.1.0
     * @param    array     $event_data              The event data to queue.
     * @param    boolean   $is_encrypted            Whether the event data is encrypted.
     * @param    array     $original_headers        Original request headers.
     * @param    boolean   $was_originally_encrypted Whether the original request was encrypted.
     * @return   int|false Event ID if successful, false otherwise.
     */
    public function queue_event($event_data, $is_encrypted = false, $original_headers = array(), $was_originally_encrypted = false)
    {
        // Encrypt the event data for storage if needed
        $encrypted_data = $event_data;
        if (is_array($event_data)) {
            $encrypted_data = wp_json_encode($event_data);
        }
        
        // Apply encryption if enabled
        if ($is_encrypted || get_option('ga4_jwt_encryption_enabled', false)) {
            $encrypted_data = $this->encrypt_sensitive_data_for_storage($encrypted_data);
        }

        return $this->create_event_record(
            $encrypted_data,
            'allowed', // Monitor status for queued events is always 'allowed'
            $original_headers,
            $was_originally_encrypted,
            array(
                'is_encrypted' => $is_encrypted || get_option('ga4_jwt_encryption_enabled', false),
                'original_payload' => $encrypted_data  // Pass the encrypted data as original_payload
            )
        );
    }

    /**
     * Get queue statistics (unified table approach)
     *
     * @since    2.1.0
     * @return   array    Queue statistics.
     */
    public function get_queue_stats()
    {
        global $wpdb;

        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN queue_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN queue_status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN queue_status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN queue_status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM $this->table_name WHERE queue_status IS NOT NULL",
            ARRAY_A
        );

        return $stats ?: array('total' => 0, 'pending' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0);
    }

    /**
     * Get pending events for processing (unified table approach)
     *
     * @since    2.1.0
     * @param    int    $limit    Number of events to retrieve.
     * @return   array           Array of pending events.
     */
    public function get_pending_events($limit = 1000)
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->table_name 
             WHERE queue_status = 'pending' 
             ORDER BY created_at ASC 
             LIMIT %d",
            $limit
        ));
    }

    /**
     * Update event status (unified table approach)
     *
     * @since    2.1.0
     * @param    array    $event_ids    Array of event IDs to update.
     * @param    string   $status       New status.
     * @param    array    $extra_data   Additional data to update.
     * @return   boolean  True if successful.
     */
    public function update_event_status($event_ids, $queue_status, $extra_data = array())
    {
        global $wpdb;

        $update_data = array('queue_status' => $queue_status);
        $update_format = array('%s');

        if ($queue_status == 'completed') {
            $update_data['processed_at'] = current_time('mysql');
            $update_format[] = '%s';
        }

        // Add any extra data
        foreach ($extra_data as $key => $value) {
            $update_data[$key] = $value;
            $update_format[] = is_numeric($value) ? '%d' : '%s';
        }

        $ids_placeholder = implode(',', array_fill(0, count($event_ids), '%d'));
        
        // Build the SET clause dynamically
        $set_clauses = array();
        $values = array();
        
        foreach ($update_data as $column => $value) {
            $set_clauses[] = "$column = %s";
            $values[] = $value;
        }
        
        // Add the IDs to the values array
        $values = array_merge($values, $event_ids);
        
        $sql = "UPDATE $this->table_name SET " . implode(', ', $set_clauses) . " WHERE id IN ($ids_placeholder)";
        
        return $wpdb->query($wpdb->prepare($sql, $values));
    }

    /**
     * Mark event as failed (unified table approach)
     *
     * @since    2.1.0
     * @param    int       $event_id       The event ID.
     * @param    string    $error_message  The error message.
     */
    public function mark_event_failed($event_id, $error_message)
    {
        global $wpdb;

        $wpdb->update(
            $this->table_name,
            array(
                'queue_status' => 'failed',
                'error_message' => $error_message
            ),
            array('id' => $event_id),
            array('%s', '%s'),
            array('%d')
        );

        // Increment retry_count
        $wpdb->query($wpdb->prepare(
            "UPDATE $this->table_name SET retry_count = retry_count + 1 WHERE id = %d",
            $event_id
        ));
    }

    /**
     * Update event's final payload and headers (unified table approach)
     *
     * @since    2.1.0
     * @param    int      $event_id                The event ID.
     * @param    array    $final_payload           The final payload that was sent.
     * @param    array    $final_headers           The final headers that were sent.
     * @param    string   $transmission_method     The transmission method used.
     * @param    boolean  $was_originally_encrypted Whether the original request was encrypted.
     * @param    boolean  $final_payload_encrypted Whether the final payload was encrypted.
     */
    public function update_event_final_data($event_id, $final_payload, $final_headers = array(), $transmission_method = 'cloudflare', $was_originally_encrypted = false, $final_payload_encrypted = false)
    {
        global $wpdb;
        
        // Prepare headers for storage - encrypt if encryption is enabled
        $stored_headers = $final_headers;
        $jwt_encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        
        if ($jwt_encryption_enabled && !empty($final_headers)) {
            try {
                $encrypted_headers = $this->encrypt_sensitive_data_for_storage(wp_json_encode($final_headers));
                $stored_headers = array('encrypted' => true, 'jwt' => $encrypted_headers);
            } catch (\Exception $e) {
                // Continue with unencrypted headers
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('GA4 Event Logger: Failed to encrypt headers - ' . $e->getMessage());
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
     * Get events for cronjob table display (unified table approach)
     *
     * @since    2.1.0
     * @param    array    $args    Query arguments.
     * @return   array             Events and pagination info.
     */
    public function get_queue_events_for_table($args = array())
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

        // Build WHERE clause for single-row approach (filter by queue_status not null)
        $where_conditions = array('queue_status IS NOT NULL');
        $query_args = array();

        if (!empty($args['status'])) {
            $where_conditions[] = "queue_status = %s";
            $query_args[] = $args['status'];
        }

        if (!empty($args['search'])) {
            $where_conditions[] = "(error_message LIKE %s OR id LIKE %s OR event_name LIKE %s OR original_payload LIKE %s OR final_payload LIKE %s OR user_agent LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $query_args[] = $search_term;
            $query_args[] = $search_term;
            $query_args[] = $search_term; // event_name
            $query_args[] = $search_term; // original_payload
            $query_args[] = $search_term; // final_payload
            $query_args[] = $search_term; // user_agent
        }

        $where_sql = ' WHERE ' . implode(' AND ', $where_conditions);

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

        $query = "SELECT * FROM $this->table_name 
                  $where_sql 
                  ORDER BY $orderby 
                  LIMIT %d OFFSET %d";

        $final_args = array_merge($query_args, array($args['limit'], $args['offset']));
        $events = $wpdb->get_results($wpdb->prepare($query, $final_args));

        // Decrypt the 4 payload columns for display (single-row approach)
        foreach ($events as $event) {
            if (!empty($event->original_payload)) {
                $event->original_payload = $this->decrypt_payload_for_display($event->original_payload);
            }
            if (!empty($event->final_payload)) {
                $event->final_payload = $this->decrypt_payload_for_display($event->final_payload);
            }
            if (!empty($event->original_headers)) {
                $event->original_headers = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt_headers_from_storage($event->original_headers);
            }
            if (!empty($event->final_headers)) {
                $event->final_headers = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt_headers_from_storage($event->final_headers);
            }
            
            // Add backward compatibility fields for cronjob admin display
            $event->event_status = $event->queue_status; // For legacy display code
            $event->event_data = $event->original_payload; // For legacy display code
            $event->status = $event->queue_status; // For legacy display code
        }

        return array(
            'events' => $events,
            'total' => intval($total_events)
        );
    }

    /**
     * Clean old processed events (unified table approach)
     *
     * @since    2.1.0
     * @param    int    $days_old    Number of days to keep events.
     * @return   int    Number of deleted rows.
     */
    public function cleanup_old_queue_events($days_old = 7)
    {
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "DELETE FROM $this->table_name 
             WHERE queue_status IS NOT NULL 
             AND queue_status IN ('completed', 'failed') 
             AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days_old
        ));
    }



    /**
     * Remove old queue table after successful migration
     *
     * @since    2.1.0
     * @return   boolean    True if successful.
     */
    public function drop_old_queue_table()
    {
        global $wpdb;
        
        $old_queue_table = $wpdb->prefix . 'ga4_events_queue';
        
        return $wpdb->query("DROP TABLE IF EXISTS $old_queue_table");
    }

    /**
     * Prepare headers for database storage with encryption if enabled
     *
     * @since    3.0.0
     * @param    array    $headers    Headers array to prepare.
     * @return   string              Prepared headers for storage (encrypted or plain JSON).
     */
    private function prepare_headers_for_storage($headers)
    {
        if (empty($headers)) {
            return wp_json_encode(array());
        }
        
        $jwt_encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        
        if ($jwt_encryption_enabled) {
            try {
                $headers_json = wp_json_encode($headers);
                $encrypted_headers = $this->encrypt_sensitive_data_for_storage($headers_json);
                return wp_json_encode(array('encrypted' => true, 'jwt' => $encrypted_headers));
            } catch (\Exception $e) {
                // Continue with unencrypted headers if encryption fails
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('GA4 Event Logger: Failed to encrypt original headers - ' . $e->getMessage());
                }
            }
        }
        
        // Return plain JSON if encryption is disabled or failed
        return wp_json_encode($headers);
    }
}
