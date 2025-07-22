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
            event_name varchar(255) NOT NULL,
            event_status enum('allowed','denied','bot_detected','error') NOT NULL,
            reason varchar(500) NULL,
            payload longtext NULL,
            headers longtext NULL,
            ip_address varchar(45) NULL,
            user_agent text NULL,
            url varchar(2000) NULL,
            referrer varchar(2000) NULL,
            user_id bigint(20) NULL,
            session_id varchar(255) NULL,
            consent_given tinyint(1) DEFAULT NULL,
            bot_detection_rules text NULL,
            cloudflare_response text NULL,
            processing_time_ms float NULL,
            batch_size int(11) DEFAULT 1,
            transmission_method varchar(50) NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_status (event_status),
            KEY event_name (event_name),
            KEY created_at (created_at),
            KEY ip_address (ip_address),
            KEY user_id (user_id),
            KEY session_id (session_id)
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
            'event_status' => 'allowed',
            'reason' => null,
            'payload' => null,
            'headers' => null,
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
            'transmission_method' => null
        );

        $args = wp_parse_args($args, $defaults);

        // Serialize complex data
        if (is_array($args['payload']) || is_object($args['payload'])) {
            $args['payload'] = json_encode($args['payload'], JSON_PRETTY_PRINT);
        }
        
        if (is_array($args['headers']) || is_object($args['headers'])) {
            $args['headers'] = json_encode($args['headers'], JSON_PRETTY_PRINT);
        }

        if (is_array($args['bot_detection_rules']) || is_object($args['bot_detection_rules'])) {
            $args['bot_detection_rules'] = json_encode($args['bot_detection_rules'], JSON_PRETTY_PRINT);
        }

        // Insert the log entry
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'event_name' => sanitize_text_field($args['event_name']),
                'event_status' => sanitize_text_field($args['event_status']),
                'reason' => sanitize_text_field($args['reason']),
                'payload' => $args['payload'],
                'headers' => $args['headers'],
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
                'transmission_method' => sanitize_text_field($args['transmission_method'])
            ),
            array(
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%f', '%d', '%s'
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
            $where_clauses[] = '(event_name LIKE %s OR reason LIKE %s OR payload LIKE %s OR ip_address LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
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

        // Events by status
        $stats['allowed'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE event_status = 'allowed'");
        $stats['denied'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE event_status = 'denied'");
        $stats['bot_detected'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE event_status = 'bot_detected'");
        $stats['error'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->table_name WHERE event_status = 'error'");

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

        // Filter by status
        if (!empty($args['status'])) {
            $where_clauses[] = 'event_status = %s';
            $where_values[] = $args['status'];
        }

        // Filter by event name
        if (!empty($args['event_name'])) {
            $where_clauses[] = 'event_name = %s';
            $where_values[] = $args['event_name'];
        }

        // Search filter
        if (!empty($args['search'])) {
            $where_clauses[] = '(event_name LIKE %s OR reason LIKE %s OR ip_address LIKE %s)';
            $search_term = '%' . $wpdb->esc_like($args['search']) . '%';
            $where_values[] = $search_term;
            $where_values[] = $search_term;
            $where_values[] = $search_term;
        }

        $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

        // Sanitize ordering
        $allowed_orderby = array('id', 'event_name', 'event_status', 'created_at', 'ip_address', 'processing_time_ms');
        $orderby = in_array($args['orderby'], $allowed_orderby) ? $args['orderby'] : 'created_at';
        $order = strtoupper($args['order']) === 'ASC' ? 'ASC' : 'DESC';

        // Build query
        $query = "SELECT * FROM $this->table_name $where_sql ORDER BY $orderby $order LIMIT %d OFFSET %d";
        $final_values = array_merge($where_values, array($args['limit'], $args['offset']));

        if (!empty($where_values)) {
            $query = $wpdb->prepare($query, $final_values);
        } else {
            $query = $wpdb->prepare($query, $args['limit'], $args['offset']);
        }

        return $wpdb->get_results($query) ?: array();
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
}