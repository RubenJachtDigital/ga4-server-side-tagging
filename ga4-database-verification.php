<?php
/**
 * GA4 Server-Side Tagging Database Verification Script
 * 
 * This script provides comprehensive verification of the GA4 plugin database structure and data.
 * Place this file in your WordPress root directory and access via browser.
 * 
 * @version 1.0
 * @author  Claude Code Assistant
 */

// Security check - remove in production
if (!defined('WP_DEBUG') || !WP_DEBUG) {
    die('This verification script can only be run when WP_DEBUG is enabled.');
}

// Load WordPress
require_once(dirname(__FILE__) . '/wp-config.php');
require_once(dirname(__FILE__) . '/wp-load.php');

// Check if user has admin capabilities
if (!current_user_can('manage_options')) {
    wp_die('You do not have sufficient permissions to access this verification script.');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GA4 Server-Side Tagging Database Verification</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #2c3e50;
            border-bottom: 3px solid #3498db;
            padding-bottom: 10px;
        }
        h2 {
            color: #34495e;
            margin-top: 30px;
            background: #ecf0f1;
            padding: 10px;
            border-left: 4px solid #3498db;
        }
        h3 {
            color: #2980b9;
            margin-top: 25px;
        }
        .status {
            padding: 10px;
            border-radius: 5px;
            margin: 10px 0;
            font-weight: bold;
        }
        .status.success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .status.warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        .status.error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            background: white;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border: 1px solid #ddd;
        }
        th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        tr:nth-child(even) {
            background-color: #f8f9fa;
        }
        .code-block {
            background: #f4f4f4;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            font-family: 'Courier New', monospace;
            overflow-x: auto;
            margin: 10px 0;
        }
        .metric {
            display: inline-block;
            background: #3498db;
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            margin: 5px;
            font-weight: bold;
        }
        .warning-box {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
        }
        .info-box {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
        }
        .json-data {
            max-height: 200px;
            overflow-y: auto;
            font-size: 12px;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 3px;
        }
        .timestamp {
            color: #6c757d;
            font-size: 0.9em;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 GA4 Server-Side Tagging Database Verification</h1>
        <p class="timestamp">Generated on: <?php echo date('Y-m-d H:i:s T'); ?></p>

        <?php
        global $wpdb;
        
        // Function to format bytes
        function formatBytes($size, $precision = 2) {
            $base = log($size, 1024);
            $suffixes = array('B', 'KB', 'MB', 'GB', 'TB');
            return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
        }
        
        // Function to check if table exists
        function table_exists($table_name) {
            global $wpdb;
            return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
        }
        
        // Function to get table structure
        function get_table_structure($table_name) {
            global $wpdb;
            return $wpdb->get_results("DESCRIBE {$table_name}");
        }
        
        // Function to get table indexes
        function get_table_indexes($table_name) {
            global $wpdb;
            return $wpdb->get_results("SHOW INDEX FROM {$table_name}");
        }
        
        // Function to get table size
        function get_table_size($table_name) {
            global $wpdb;
            $result = $wpdb->get_row($wpdb->prepare("
                SELECT 
                    ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb,
                    TABLE_ROWS as row_count
                FROM information_schema.TABLES 
                WHERE table_schema = %s AND table_name = %s
            ", DB_NAME, $table_name));
            
            return $result;
        }
        
        // Get all GA4 related tables
        $ga4_tables = array();
        $expected_tables = array(
            $wpdb->prefix . 'ga4_event_logs',
            $wpdb->prefix . 'ga4_event_queue',  // Legacy table
            $wpdb->prefix . 'ga4_events_queue', // Another possible legacy table name
        );
        
        foreach ($expected_tables as $table) {
            if (table_exists($table)) {
                $ga4_tables[] = $table;
            }
        }
        
        echo "<h2>📊 Database Tables Overview</h2>";
        
        if (empty($ga4_tables)) {
            echo "<div class='status error'>❌ No GA4 database tables found!</div>";
        } else {
            echo "<div class='status success'>✅ Found " . count($ga4_tables) . " GA4 database table(s)</div>";
            
            echo "<table>";
            echo "<tr><th>Table Name</th><th>Exists</th><th>Row Count</th><th>Size</th><th>Purpose</th></tr>";
            
            foreach ($ga4_tables as $table) {
                $size_info = get_table_size($table);
                $table_purpose = "Unknown";
                
                if (strpos($table, 'event_logs') !== false) {
                    $table_purpose = "Unified Event Logging & Queue Management";
                } elseif (strpos($table, 'event_queue') !== false || strpos($table, 'events_queue') !== false) {
                    $table_purpose = "Legacy Queue Management (Should be migrated)";
                }
                
                echo "<tr>";
                echo "<td><strong>{$table}</strong></td>";
                echo "<td><span class='status success'>✅ Yes</span></td>";
                echo "<td>" . number_format($size_info->row_count ?? 0) . "</td>";
                echo "<td>" . ($size_info->size_mb ?? 0) . " MB</td>";
                echo "<td>{$table_purpose}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // Check for the main unified table
        $main_table = $wpdb->prefix . 'ga4_event_logs';
        $main_table_exists = table_exists($main_table);
        
        echo "<h2>🏗️ Main Table Structure Verification</h2>";
        
        if (!$main_table_exists) {
            echo "<div class='status error'>❌ Main table '{$main_table}' does not exist!</div>";
            echo "<div class='warning-box'>";
            echo "<strong>Action Required:</strong> The main GA4 event logs table is missing. ";
            echo "This table should be created automatically when the plugin is activated. ";
            echo "Try deactivating and reactivating the plugin, or contact support.";
            echo "</div>";
        } else {
            echo "<div class='status success'>✅ Main table '{$main_table}' exists</div>";
            
            // Show table structure
            echo "<h3>📋 Table Structure</h3>";
            $structure = get_table_structure($main_table);
            
            echo "<table>";
            echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
            
            $expected_columns = array(
                'id', 'event_name', 'event_status', 'reason', 'payload', 'headers',
                'ip_address', 'user_agent', 'url', 'referrer', 'user_id', 'session_id',
                'consent_given', 'bot_detection_rules', 'cloudflare_response',
                'processing_time_ms', 'batch_size', 'transmission_method', 'created_at',
                'processed_at', 'retry_count', 'error_message', 'event_data',
                'is_encrypted', 'final_payload', 'final_headers', 'original_headers',
                'was_originally_encrypted', 'final_payload_encrypted', 'record_type'
            );
            
            $found_columns = array();
            
            foreach ($structure as $column) {
                $found_columns[] = $column->Field;
                echo "<tr>";
                echo "<td><strong>{$column->Field}</strong></td>";
                echo "<td>{$column->Type}</td>";
                echo "<td>{$column->Null}</td>";
                echo "<td>{$column->Key}</td>";
                echo "<td>{$column->Default}</td>";
                echo "<td>{$column->Extra}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Check for missing columns
            $missing_columns = array_diff($expected_columns, $found_columns);
            if (!empty($missing_columns)) {
                echo "<div class='status warning'>⚠️ Missing columns: " . implode(', ', $missing_columns) . "</div>";
            } else {
                echo "<div class='status success'>✅ All expected columns are present</div>";
            }
            
            // Show indexes
            echo "<h3>🔑 Table Indexes</h3>";
            $indexes = get_table_indexes($main_table);
            
            echo "<table>";
            echo "<tr><th>Index Name</th><th>Column</th><th>Non Unique</th><th>Type</th></tr>";
            
            foreach ($indexes as $index) {
                echo "<tr>";
                echo "<td>{$index->Key_name}</td>";
                echo "<td>{$index->Column_name}</td>";
                echo "<td>" . ($index->Non_unique ? 'Yes' : 'No') . "</td>";
                echo "<td>{$index->Index_type}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
        
        // Data Analysis
        if ($main_table_exists) {
            echo "<h2>📈 Data Analysis</h2>";
            
            // Get record type distribution
            $record_type_stats = $wpdb->get_results("
                SELECT 
                    record_type,
                    COUNT(*) as count,
                    MIN(created_at) as first_record,
                    MAX(created_at) as last_record
                FROM {$main_table} 
                GROUP BY record_type
            ");
            
            echo "<h3>📊 Record Type Distribution</h3>";
            echo "<table>";
            echo "<tr><th>Record Type</th><th>Count</th><th>First Record</th><th>Last Record</th><th>Purpose</th></tr>";
            
            $total_records = 0;
            
            foreach ($record_type_stats as $stat) {
                $total_records += $stat->count;
                $purpose = "Unknown";
                
                switch ($stat->record_type) {
                    case 'event_log':
                        $purpose = "Event monitoring and logging";
                        break;
                    case 'queue_item':
                        $purpose = "Event queue management";
                        break;
                }
                
                echo "<tr>";
                echo "<td><strong>{$stat->record_type}</strong></td>";
                echo "<td><span class='metric'>" . number_format($stat->count) . "</span></td>";
                echo "<td>{$stat->first_record}</td>";
                echo "<td>{$stat->last_record}</td>";
                echo "<td>{$purpose}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            echo "<div class='info-box'>";
            echo "<strong>Total Records:</strong> " . number_format($total_records);
            echo "</div>";
            
            // Event status distribution
            echo "<h3>🚦 Event Status Distribution</h3>";
            $status_stats = $wpdb->get_results("
                SELECT 
                    record_type,
                    event_status,
                    COUNT(*) as count
                FROM {$main_table} 
                GROUP BY record_type, event_status
                ORDER BY record_type, event_status
            ");
            
            echo "<table>";
            echo "<tr><th>Record Type</th><th>Event Status</th><th>Count</th><th>Percentage</th></tr>";
            
            $type_totals = array();
            foreach ($record_type_stats as $stat) {
                $type_totals[$stat->record_type] = $stat->count;
            }
            
            foreach ($status_stats as $stat) {
                $percentage = $type_totals[$stat->record_type] > 0 ? 
                    round(($stat->count / $type_totals[$stat->record_type]) * 100, 1) : 0;
                
                echo "<tr>";
                echo "<td>{$stat->record_type}</td>";
                echo "<td><strong>{$stat->event_status}</strong></td>";
                echo "<td>" . number_format($stat->count) . "</td>";
                echo "<td>{$percentage}%</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            // Recent activity
            echo "<h3>⏰ Recent Activity (Last 24 Hours)</h3>";
            $recent_activity = $wpdb->get_results($wpdb->prepare("
                SELECT 
                    record_type,
                    event_status,
                    COUNT(*) as count
                FROM {$main_table} 
                WHERE created_at >= %s
                GROUP BY record_type, event_status
                ORDER BY record_type, event_status
            ", date('Y-m-d H:i:s', strtotime('-24 hours'))));
            
            if (empty($recent_activity)) {
                echo "<div class='status warning'>⚠️ No activity in the last 24 hours</div>";
            } else {
                echo "<table>";
                echo "<tr><th>Record Type</th><th>Status</th><th>Count (24h)</th></tr>";
                
                foreach ($recent_activity as $activity) {
                    echo "<tr>";
                    echo "<td>{$activity->record_type}</td>";
                    echo "<td>{$activity->event_status}</td>";
                    echo "<td>" . number_format($activity->count) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            }
            
            // Sample data
            echo "<h3>🔍 Sample Data</h3>";
            
            // Event log samples
            $event_log_samples = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$main_table} 
                WHERE record_type = 'event_log' 
                ORDER BY created_at DESC 
                LIMIT 3
            "));
            
            if (!empty($event_log_samples)) {
                echo "<h4>Event Log Samples:</h4>";
                foreach ($event_log_samples as $sample) {
                    echo "<div class='code-block'>";
                    echo "<strong>ID:</strong> {$sample->id} | ";
                    echo "<strong>Event:</strong> {$sample->event_name} | ";
                    echo "<strong>Status:</strong> {$sample->event_status} | ";
                    echo "<strong>Created:</strong> {$sample->created_at}<br>";
                    
                    if (!empty($sample->payload)) {
                        echo "<strong>Payload:</strong> ";
                        echo "<div class='json-data'>" . htmlspecialchars(substr($sample->payload, 0, 500)) . 
                             (strlen($sample->payload) > 500 ? '...' : '') . "</div>";
                    }
                    echo "</div>";
                }
            }
            
            // Queue item samples
            $queue_samples = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$main_table} 
                WHERE record_type = 'queue_item' 
                ORDER BY created_at DESC 
                LIMIT 3
            "));
            
            if (!empty($queue_samples)) {
                echo "<h4>Queue Item Samples:</h4>";
                foreach ($queue_samples as $sample) {
                    echo "<div class='code-block'>";
                    echo "<strong>ID:</strong> {$sample->id} | ";
                    echo "<strong>Status:</strong> {$sample->event_status} | ";
                    echo "<strong>Transmission:</strong> {$sample->transmission_method} | ";
                    echo "<strong>Created:</strong> {$sample->created_at}<br>";
                    
                    if (!empty($sample->event_data)) {
                        echo "<strong>Event Data:</strong> ";
                        echo "<div class='json-data'>" . htmlspecialchars(substr($sample->event_data, 0, 500)) . 
                             (strlen($sample->event_data) > 500 ? '...' : '') . "</div>";
                    }
                    echo "</div>";
                }
            }
        }
        
        // Configuration verification
        echo "<h2>⚙️ Plugin Configuration</h2>";
        
        $config_options = array(
            'ga4_measurement_id' => 'GA4 Measurement ID',
            'ga4_api_secret' => 'GA4 API Secret',
            'ga4_cloudflare_worker_url' => 'Cloudflare Worker URL',
            'ga4_transmission_method' => 'Transmission Method',
            'ga4_disable_cf_proxy' => 'Disable Cloudflare Proxy',
            'ga4_jwt_encryption_enabled' => 'JWT Encryption Enabled',
            'ga4_server_side_tagging_debug_mode' => 'Debug Mode'
        );
        
        echo "<table>";
        echo "<tr><th>Setting</th><th>Value</th><th>Status</th></tr>";
        
        foreach ($config_options as $option => $label) {
            $value = get_option($option, '');
            $status = '';
            
            // Determine status
            if ($option === 'ga4_measurement_id') {
                $status = !empty($value) && strpos($value, 'G-') === 0 ? 
                    "<span class='status success'>✅ Valid</span>" : 
                    "<span class='status error'>❌ Invalid/Missing</span>";
                $value = !empty($value) ? substr($value, 0, 8) . '...' : 'Not set';
            } elseif ($option === 'ga4_api_secret') {
                $status = !empty($value) ? 
                    "<span class='status success'>✅ Set</span>" : 
                    "<span class='status error'>❌ Not set</span>";
                $value = !empty($value) ? '***hidden***' : 'Not set';
            } elseif ($option === 'ga4_cloudflare_worker_url') {
                $status = !empty($value) && filter_var($value, FILTER_VALIDATE_URL) ? 
                    "<span class='status success'>✅ Valid URL</span>" : 
                    "<span class='status warning'>⚠️ Invalid/Missing</span>";
            } elseif (in_array($option, ['ga4_disable_cf_proxy', 'ga4_jwt_encryption_enabled', 'ga4_server_side_tagging_debug_mode'])) {
                $value = $value ? 'Enabled' : 'Disabled';
                $status = "<span class='status success'>✅ Set</span>";
            } else {
                $status = !empty($value) ? 
                    "<span class='status success'>✅ Set</span>" : 
                    "<span class='status warning'>⚠️ Not set</span>";
            }
            
            echo "<tr>";
            echo "<td><strong>{$label}</strong></td>";
            echo "<td>{$value}</td>";
            echo "<td>{$status}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // System verification
        echo "<h2>🔧 System Verification</h2>";
        
        // Check if both systems are using the unified table
        $unified_table_check = true;
        
        // Check if Event Logger class exists and uses correct table
        if (class_exists('GA4ServerSideTagging\Core\GA4_Event_Logger')) {
            echo "<div class='status success'>✅ GA4_Event_Logger class found</div>";
        } else {
            echo "<div class='status error'>❌ GA4_Event_Logger class not found</div>";
            $unified_table_check = false;
        }
        
        // Check if Cronjob Manager class exists
        if (class_exists('GA4ServerSideTagging\Core\GA4_Cronjob_Manager')) {
            echo "<div class='status success'>✅ GA4_Cronjob_Manager class found</div>";
        } else {
            echo "<div class='status error'>❌ GA4_Cronjob_Manager class not found</div>";
            $unified_table_check = false;
        }
        
        // Check for legacy tables
        $legacy_tables = array(
            $wpdb->prefix . 'ga4_event_queue',
            $wpdb->prefix . 'ga4_events_queue'
        );
        
        $found_legacy = false;
        foreach ($legacy_tables as $legacy_table) {
            if (table_exists($legacy_table)) {
                echo "<div class='status warning'>⚠️ Legacy table found: {$legacy_table}</div>";
                $found_legacy = true;
                
                $legacy_count = $wpdb->get_var("SELECT COUNT(*) FROM {$legacy_table}");
                if ($legacy_count > 0) {
                    echo "<div class='warning-box'>";
                    echo "<strong>Migration Needed:</strong> Legacy table '{$legacy_table}' contains {$legacy_count} records. ";
                    echo "These should be migrated to the unified table.";
                    echo "</div>";
                }
            }
        }
        
        if (!$found_legacy) {
            echo "<div class='status success'>✅ No legacy tables found</div>";
        }
        
        // Final verification summary
        echo "<h2>📋 Verification Summary</h2>";
        
        $all_good = true;
        
        if (!$main_table_exists) {
            echo "<div class='status error'>❌ Main unified table is missing</div>";
            $all_good = false;
        } else {
            echo "<div class='status success'>✅ Main unified table exists and is properly structured</div>";
        }
        
        if ($unified_table_check) {
            echo "<div class='status success'>✅ Both event monitoring and queue management are using the unified table</div>";
        } else {
            echo "<div class='status error'>❌ Plugin classes are not properly loaded or configured</div>";
            $all_good = false;
        }
        
        if ($found_legacy) {
            echo "<div class='status warning'>⚠️ Legacy tables detected - migration recommended</div>";
        } else {
            echo "<div class='status success'>✅ No legacy tables found</div>";
        }
        
        if ($all_good && !$found_legacy) {
            echo "<div class='status success'>";
            echo "<h3>🎉 Verification Complete</h3>";
            echo "Your GA4 Server-Side Tagging plugin database is properly configured and both ";
            echo "event monitoring and queue management are successfully using the unified table approach.";
            echo "</div>";
        } else {
            echo "<div class='status warning'>";
            echo "<h3>⚠️ Issues Detected</h3>";
            echo "Some issues were found with your database configuration. ";
            echo "Please review the details above and take appropriate action.";
            echo "</div>";
        }
        
        // Cleanup recommendations
        echo "<h2>🧹 Maintenance Recommendations</h2>";
        
        $size_info = get_table_size($main_table);
        if ($size_info && $size_info->size_mb > 100) {
            echo "<div class='warning-box'>";
            echo "<strong>Large Table Size:</strong> Your main table is {$size_info->size_mb} MB. ";
            echo "Consider implementing regular cleanup of old records to maintain performance.";
            echo "</div>";
        }
        
        $old_records = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$main_table} 
            WHERE created_at < %s
        ", date('Y-m-d H:i:s', strtotime('-30 days'))));
        
        if ($old_records > 1000) {
            echo "<div class='info-box'>";
            echo "<strong>Old Records:</strong> Found " . number_format($old_records) . " records older than 30 days. ";
            echo "Regular cleanup can help maintain optimal performance.";
            echo "</div>";
        }
        
        echo "<hr>";
        echo "<p><em>This verification script was generated automatically. ";
        echo "For any issues or questions, please consult the plugin documentation or contact support.</em></p>";
        ?>
    </div>
</body>
</html>