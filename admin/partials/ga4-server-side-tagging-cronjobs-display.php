<?php
/**
 * Provide a admin area view for the cronjobs management page.
 *
 * This file is used to markup the admin-facing aspects of the cronjobs.
 *
 * @since      2.0.0
 * @package    GA4_Server_Side_Tagging
 * @subpackage GA4_Server_Side_Tagging/admin/partials
 */

// Helper function to decrypt encrypted data
function decrypt_event_data($encrypted_data) {
    if (empty($encrypted_data)) {
        return array('data' => '', 'was_encrypted' => false);
    }
    
    // Check if the data is a JWT token (starts with eyJ which is base64 for {"typ":"JWT"})
    if (strpos($encrypted_data, 'eyJ') === 0 && strpos($encrypted_data, '.') !== false) {
        // This looks like a direct JWT token
        return decrypt_jwt_token($encrypted_data);
    }
    
    $parsed_data = json_decode($encrypted_data, true);
    
    // Check if it's a JSON object with jwt field
    if (is_array($parsed_data) && isset($parsed_data['jwt'])) {
        return decrypt_jwt_token($parsed_data['jwt']);
    }
    
    // Not encrypted, return as formatted JSON if it's valid JSON
    if (is_array($parsed_data)) {
        return array(
            'data' => wp_json_encode($parsed_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'was_encrypted' => false
        );
    }
    
    // Return as-is if not valid JSON
    return array(
        'data' => $encrypted_data,
        'was_encrypted' => false
    );
}

// Helper function to extract event name from payload
function extract_event_name_from_payload($event_data) {
    if (empty($event_data)) {
        return '';
    }
    
    // Try to parse as JSON first
    $parsed_data = json_decode($event_data, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return '';
    }
    
    // Check if there's an event array with events
    if (isset($parsed_data['events']) && is_array($parsed_data['events']) && !empty($parsed_data['events'])) {
        $first_event = $parsed_data['events'][0];
        if (isset($first_event['name'])) {
            return $first_event['name'];
        }
    }
    
    // Check if there's a direct event with name
    if (isset($parsed_data['event']) && is_array($parsed_data['event'])) {
        if (isset($parsed_data['event']['name'])) {
            return $parsed_data['event']['name'];
        }
    }
    
    // Check if the payload itself is an event with name
    if (isset($parsed_data['name'])) {
        return $parsed_data['name'];
    }
    
    return '';
}

// Helper function to decrypt JWT tokens (same method as event monitor)
function decrypt_jwt_token($jwt_token) {
    if (empty($jwt_token)) {
        return array('data' => '', 'was_encrypted' => false);
    }
    
    // Check if the encryption util class exists
    if (!class_exists('\GA4ServerSideTagging\Utilities\GA4_Encryption_Util')) {
        return array('data' => $jwt_token, 'was_encrypted' => false);
    }

    try {
        $encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        
        if (!$encryption_enabled) {
            return array('data' => $jwt_token, 'was_encrypted' => false);
        }
        
        $encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
        if (!$encryption_key) {
            return array('data' => $jwt_token, 'was_encrypted' => false);
        }

        // First check if this looks like a JWT token (has 3 parts separated by dots)
        if (substr_count($jwt_token, '.') !== 2) {
            // Not a JWT format, might be regular JSON or other data
            return array('data' => $jwt_token, 'was_encrypted' => false);
        }
        
        // Try to decrypt with permanent key (same as event monitor)
        $decrypted = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::decrypt($jwt_token, $encryption_key);
        if ($decrypted !== false) {
            // Successfully decrypted - format as JSON if it's valid JSON
            $formatted_data = $decrypted;
            $json_data = json_decode($decrypted, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $formatted_data = wp_json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            
            return array(
                'data' => $formatted_data,
                'was_encrypted' => true
            );
        }

        // If decryption failed, return original (might not be encrypted)
        return array('data' => $jwt_token, 'was_encrypted' => false);

    } catch (\Exception $e) {
        // Silently handle decryption failures to avoid log spam
        return array('data' => $jwt_token, 'was_encrypted' => false);
    }
}

// Handle manual trigger action
if (isset($_POST['trigger_cronjob']) && wp_verify_nonce($_POST['_wpnonce'], 'ga4_trigger_cronjob')) {
    $this->cronjob_manager->trigger_manual_processing();
    echo '<div class="notice notice-success is-dismissible"><p>Cronjob triggered successfully! Events have been processed.</p></div>';
}

// Handle cleanup action
if (isset($_POST['cleanup_events']) && wp_verify_nonce($_POST['_wpnonce'], 'ga4_cleanup_events')) {
    $days = intval($_POST['cleanup_days']) ?: 7;
    
    // Save the unified cleanup days setting
    update_option('ga4_event_cleanup_days', $days);
    
    $cleaned = $this->cronjob_manager->cleanup_old_events($days);
    echo '<div class="notice notice-success is-dismissible"><p>Cleaned up ' . $cleaned . ' old events.</p></div>';
}

// Get the unified cleanup days setting (default: 7 days)
// Migrate from old separate settings if they exist
$event_cleanup_days = get_option('ga4_event_cleanup_days');
if ($event_cleanup_days === false) {
    // Check for old separate settings and use them as migration
    $old_event_logs_days = get_option('ga4_event_logs_cleanup_days');
    $old_event_queue_days = get_option('ga4_event_queue_cleanup_days');
    
    if ($old_event_logs_days !== false || $old_event_queue_days !== false) {
        // Use the lower value for safety (more conservative cleanup)
        $event_cleanup_days = min(
            $old_event_logs_days !== false ? $old_event_logs_days : 7,
            $old_event_queue_days !== false ? $old_event_queue_days : 7
        );
        // Save the migrated value
        update_option('ga4_event_cleanup_days', $event_cleanup_days);
    } else {
        $event_cleanup_days = 7; // Default
    }
}

// Get queue statistics
$stats = $this->cronjob_manager->get_queue_stats();
$next_scheduled = wp_next_scheduled('ga4_process_event_queue');

// Get filter parameters
$filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$limit = isset($_GET['limit']) ? max(10, min(200, intval($_GET['limit']))) : 50;
$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;

// Get events with filtering and pagination (unified table approach)
$events_data = $this->cronjob_manager->get_events_for_table(array(
    'limit' => $limit,
    'offset' => $offset,
    'status' => $filter_status,
    'search' => $search,
    'orderby' => 'created_at',
    'order' => 'DESC'
));

$events = $events_data['events'];
$total_events = $events_data['total'];

// Calculate pagination info
$total_pages = ceil($total_events / $limit);
$current_page = floor($offset / $limit) + 1;
?>

<div class="wrap">
    <h1><?php echo esc_html__('Event Monitor & Queue Management', 'ga4-server-side-tagging'); ?></h1>
    
    <!-- Tab Navigation -->
    <div class="nav-tab-wrapper" style="margin-bottom: 20px;">
        <a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-events'); ?>" class="nav-tab">
            <?php echo esc_html__('📊 Event Monitor', 'ga4-server-side-tagging'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-cronjobs'); ?>" class="nav-tab nav-tab-active">
            <?php echo esc_html__('⚙️ Queue Management', 'ga4-server-side-tagging'); ?>
        </a>
    </div>
    
    <!-- Statistics Section -->
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Queue Statistics', 'ga4-server-side-tagging'); ?></h2>
        <table class="widefat">
            <tbody>
                <tr>
                    <td><strong><?php echo esc_html__('Total Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['total']); ?></td>
                    <td><strong style="color: #0073aa;"><?php echo esc_html__('Pending Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['pending']); ?></td>
                </tr>
                <tr>
                    <td><strong style="color: #28a745;"><?php echo esc_html__('Completed Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['completed']); ?></td>
                    <td><strong style="color: #dc3545;"><?php echo esc_html__('Failed Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['failed']); ?></td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('Next Scheduled Run:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td colspan="3">
                        <?php 
                        if ($next_scheduled) {
                            echo esc_html(date('Y-m-d H:i:s', $next_scheduled));
                            echo ' <small style="color: #666;">(' . esc_html(human_time_diff($next_scheduled, time())) . ')</small>';
                        } else {
                            echo '<span style="color: #dc3545;">' . esc_html__('Not scheduled', 'ga4-server-side-tagging') . '</span>';
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Management Section -->
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Management', 'ga4-server-side-tagging'); ?></h2>
        <form method="post" style="display: inline-block; margin-right: 20px;">
            <?php wp_nonce_field('ga4_trigger_cronjob'); ?>
            <input type="submit" name="trigger_cronjob" class="button button-primary" value="<?php echo esc_attr__('Trigger Cronjob Now', 'ga4-server-side-tagging'); ?>" 
                   onclick="return confirm('<?php echo esc_js(__('This will immediately process all pending events. Continue?', 'ga4-server-side-tagging')); ?>');">
            <p class="description"><?php echo esc_html__('Manually trigger the cronjob to process all pending events immediately.', 'ga4-server-side-tagging'); ?></p>
        </form>

        <form method="post" style="display: inline-block;">
            <?php wp_nonce_field('ga4_cleanup_events'); ?>
            <label for="cleanup_days"><?php echo esc_html__('Clean up events older than:', 'ga4-server-side-tagging'); ?></label>
            <input type="number" id="cleanup_days" name="cleanup_days" value="<?php echo esc_attr($event_cleanup_days); ?>" min="1" max="365" style="width: 60px;">
            <span><?php echo esc_html__('days', 'ga4-server-side-tagging'); ?></span>
            <input type="submit" name="cleanup_events" class="button" value="<?php echo esc_attr__('Cleanup Old Events', 'ga4-server-side-tagging'); ?>"
                   onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete old events?', 'ga4-server-side-tagging')); ?>');">
            <p class="description"><?php echo esc_html__('Remove processed/failed events older than the specified number of days to keep the database clean.', 'ga4-server-side-tagging'); ?></p>
        </form>
    </div>

    <!-- Filters Section -->
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Filters & Search', 'ga4-server-side-tagging'); ?></h2>
        <form method="get" action="">
            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>">
            
            <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end; margin-bottom: 15px;">
                <!-- Search -->
                <div>
                    <label for="search"><?php echo esc_html__('Search:', 'ga4-server-side-tagging'); ?></label><br>
                    <input type="text" id="search" name="search" value="<?php echo esc_attr($search); ?>" 
                           placeholder="ID, event name, error message, payload..." style="width: 250px;">
                </div>

                <!-- Status Filter -->
                <div>
                    <label for="filter_status"><?php echo esc_html__('Status:', 'ga4-server-side-tagging'); ?></label><br>
                    <select id="filter_status" name="filter_status">
                        <option value=""><?php echo esc_html__('All Statuses', 'ga4-server-side-tagging'); ?></option>
                        <option value="pending" <?php selected($filter_status, 'pending'); ?>><?php echo esc_html__('⏳ Pending', 'ga4-server-side-tagging'); ?></option>
                        <option value="completed" <?php selected($filter_status, 'completed'); ?>><?php echo esc_html__('✅ Completed', 'ga4-server-side-tagging'); ?></option>
                        <option value="failed" <?php selected($filter_status, 'failed'); ?>><?php echo esc_html__('❌ Failed', 'ga4-server-side-tagging'); ?></option>
                    </select>
                </div>

                <!-- Per Page -->
                <div>
                    <label for="limit"><?php echo esc_html__('Per Page:', 'ga4-server-side-tagging'); ?></label><br>
                    <select id="limit" name="limit">
                        <option value="25" <?php selected($limit, 25); ?>>25</option>
                        <option value="50" <?php selected($limit, 50); ?>>50</option>
                        <option value="100" <?php selected($limit, 100); ?>>100</option>
                        <option value="200" <?php selected($limit, 200); ?>>200</option>
                    </select>
                </div>

                <!-- Submit -->
                <div>
                    <input type="submit" class="button button-primary" value="<?php echo esc_attr__('Filter', 'ga4-server-side-tagging'); ?>">
                    <a href="<?php echo admin_url('admin.php?page=' . $_GET['page']); ?>" class="button"><?php echo esc_html__('Clear', 'ga4-server-side-tagging'); ?></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Results Info -->
    <div class="ga4-admin-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <div>
                <strong><?php echo esc_html__('Results:', 'ga4-server-side-tagging'); ?></strong>
                <?php echo sprintf(
                    esc_html__('Showing %d-%d of %s events', 'ga4-server-side-tagging'),
                    $offset + 1,
                    min($offset + $limit, $total_events),
                    number_format($total_events)
                ); ?>
                <?php if ($filter_status || $search): ?>
                    <span style="color: #666;"><?php echo esc_html__('(filtered)', 'ga4-server-side-tagging'); ?></span>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="tablenav-pages">
                <?php 
                $base_url = admin_url('admin.php?page=' . $_GET['page']);
                $query_args = array_filter(array(
                    'filter_status' => $filter_status,
                    'search' => $search,
                    'limit' => $limit
                ));
                
                if ($current_page > 1): ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_args, array('offset' => 0)), $base_url)); ?>">
                        <?php echo esc_html__('« First', 'ga4-server-side-tagging'); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_args, array('offset' => ($current_page - 2) * $limit)), $base_url)); ?>">
                        <?php echo esc_html__('‹ Previous', 'ga4-server-side-tagging'); ?>
                    </a>
                <?php endif; ?>
                
                <span style="margin: 0 10px;"><?php echo sprintf(esc_html__('Page %d of %d', 'ga4-server-side-tagging'), $current_page, $total_pages); ?></span>
                
                <?php if ($current_page < $total_pages): ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_args, array('offset' => $current_page * $limit)), $base_url)); ?>">
                        <?php echo esc_html__('Next ›', 'ga4-server-side-tagging'); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($query_args, array('offset' => ($total_pages - 1) * $limit)), $base_url)); ?>">
                        <?php echo esc_html__('Last »', 'ga4-server-side-tagging'); ?>
                    </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Event Queue -->
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Event Queue', 'ga4-server-side-tagging'); ?></h2>

        
        <?php if (empty($events)): ?>
            <p><?php echo esc_html__('No events found matching your criteria.', 'ga4-server-side-tagging'); ?></p>
        <?php else: ?>
            <div style="max-height: 600px; overflow-y: auto; border: 1px solid #ddd; border-radius: 3px;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 60px;"><?php echo esc_html__('ID', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 120px;"><?php echo esc_html__('Event Name', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 100px;"><?php echo esc_html__('Status', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 120px;"><?php echo esc_html__('Transmission', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 100px;"><?php echo esc_html__('Encryption', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 140px;"><?php echo esc_html__('Created', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 140px;"><?php echo esc_html__('Processed', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 80px;"><?php echo esc_html__('Retries', 'ga4-server-side-tagging'); ?></th>
                            <th><?php echo esc_html__('Error Message', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 80px;"><?php echo esc_html__('Details', 'ga4-server-side-tagging'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?php echo intval($event->id); ?></td>
                                <td>
                                    <?php
                                    // Extract event name from the original payload
                                    $event_name = '';
                                    if (!empty($event->event_data)) {
                                        $event_name = extract_event_name_from_payload($event->event_data);
                                    }
                                    
                                    if (!empty($event_name)) {
                                        echo '<strong>' . esc_html($event_name) . '</strong>';
                                    } else {
                                        echo '<span style="color: #999; font-style: italic;">Unknown</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <?php
                                    $status_colors = array(
                                        'pending' => '#0073aa',
                                        'completed' => '#28a745', 
                                        'failed' => '#dc3545'
                                    );
                                    $status_icons = array(
                                        'pending' => '⏳',
                                        'completed' => '✅',
                                        'failed' => '❌'
                                    );
                                    $color = $status_colors[$event->event_status] ?? '#6c757d';
                                    $icon = $status_icons[$event->event_status] ?? '❓';
                                    ?>
                                    <span style="color: <?php echo esc_attr($color); ?>; font-weight: bold; font-size: 11px;">
                                        <?php echo $icon; ?> <?php echo esc_html(ucfirst($event->event_status)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $transmission_method = $event->transmission_method ?? 'cloudflare';
                                    $method_colors = array(
                                        'cloudflare' => '#f48120',
                                        'ga4_direct' => '#4285f4'
                                    );
                                    $method_labels = array(
                                        'cloudflare' => 'Cloudflare',
                                        'ga4_direct' => 'GA4 Direct'
                                    );
                                    $method_color = $method_colors[$transmission_method] ?? '#6c757d';
                                    $method_label = $method_labels[$transmission_method] ?? ucfirst($transmission_method);
                                    ?>
                                    <span style="color: <?php echo esc_attr($method_color); ?>; font-weight: bold; font-size: 11px;">
                                        <?php echo esc_html($method_label); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $originally_encrypted = $event->was_originally_encrypted ?? false;
                                    $payload_encrypted = $event->final_payload_encrypted ?? false;
                                    
                                    if ($originally_encrypted) {
                                        echo '<span style="color: #28a745; font-size: 10px;">🔒 In</span>';
                                    } else {
                                        echo '<span style="color: #6c757d; font-size: 10px;">🔓 In</span>';
                                    }
                                    
                                    echo '<br>';
                                    
                                    if ($payload_encrypted) {
                                        echo '<span style="color: #28a745; font-size: 10px;">🔒 Out</span>';
                                    } else {
                                        echo '<span style="color: #6c757d; font-size: 10px;">🔓 Out</span>';
                                    }
                                    ?>
                                </td>
                                <td style="font-size: 11px;">
                                    <?php 
                                    $date = new DateTime($event->created_at);
                                    echo esc_html($date->format('M j, Y'));
                                    echo '<br>' . esc_html($date->format('H:i:s')); 
                                    ?>
                                </td>
                                <td style="font-size: 11px;">
                                    <?php 
                                    if ($event->processed_at) {
                                        $processed_date = new DateTime($event->processed_at);
                                        echo esc_html($processed_date->format('M j, Y'));
                                        echo '<br>' . esc_html($processed_date->format('H:i:s'));
                                    } else {
                                        echo '<span style="color: #999;">-</span>';
                                    }
                                    ?>
                                </td>
                                <td style="text-align: center;">
                                    <?php if ($event->retry_count > 0): ?>
                                        <span style="color: #ffc107; font-weight: bold;"><?php echo intval($event->retry_count); ?></span>
                                    <?php else: ?>
                                        <span style="color: #999;">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($event->error_message): ?>
                                        <span title="<?php echo esc_attr($event->error_message); ?>" style="font-size: 11px; cursor: help;">
                                            <?php echo esc_html(wp_trim_words($event->error_message, 8, '...')); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="button button-small view-queue-details-btn" 
                                            data-event-id="<?php echo intval($event->id); ?>"
                                            data-status="<?php echo esc_attr($event->event_status); ?>"
                                            data-created="<?php echo esc_attr($event->created_at); ?>"
                                            data-processed="<?php echo esc_attr($event->processed_at ?: ''); ?>"
                                            data-retry-count="<?php echo esc_attr($event->retry_count); ?>"
                                            data-error="<?php echo esc_attr($event->error_message ?: ''); ?>"
                                            data-event-data="<?php 
                                                $event_data_result = decrypt_event_data($event->event_data ?: '');
                                                echo esc_attr($event_data_result['data']);
                                            ?>"
                                            data-event-data-encrypted="<?php 
                                                $event_data_result = decrypt_event_data($event->event_data ?: '');
                                                echo esc_attr($event_data_result['was_encrypted'] ? '1' : '0');
                                            ?>"
                                            data-final-payload="<?php 
                                                $payload_result = decrypt_event_data($event->final_payload ?: '');
                                                echo esc_attr($payload_result['data']);
                                            ?>"
                                            data-final-headers="<?php 
                                                $headers_result = decrypt_event_data($event->final_headers ?: '');
                                                echo esc_attr($headers_result['data']);
                                            ?>"
                                            data-payload-encrypted="<?php 
                                                $payload_result = decrypt_event_data($event->final_payload ?: '');
                                                echo esc_attr($payload_result['was_encrypted'] ? '1' : '0');
                                            ?>"
                                            data-headers-encrypted="<?php 
                                                $headers_result = decrypt_event_data($event->final_headers ?: '');
                                                echo esc_attr($headers_result['was_encrypted'] ? '1' : '0');
                                            ?>"
                                            data-is-encrypted="<?php echo esc_attr($event->is_encrypted ? '1' : '0'); ?>"
                                            style="font-size: 10px; padding: 3px 8px;">
                                        <?php echo esc_html__('View', 'ga4-server-side-tagging'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>

    <!-- Configuration Section -->
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Cronjob Configuration', 'ga4-server-side-tagging'); ?></h2>
        <table class="widefat">
            <tbody>
                <tr>
                    <td><strong><?php echo esc_html__('Processing Schedule:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo esc_html__('Every 5 minutes', 'ga4-server-side-tagging'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('Batch Size:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td>
                        <?php 
                        $batch_size = get_option('ga4_event_batch_size', 1000);
                        echo sprintf(
                            esc_html__('Up to %s events per batch', 'ga4-server-side-tagging'), 
                            '<strong>' . number_format($batch_size) . '</strong>'
                        ); 
                        ?>
                        <br><small style="color: #666;">
                            <a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-settings'); ?>" style="text-decoration: none;">
                                ⚙️ <?php echo esc_html__('Change in Settings', 'ga4-server-side-tagging'); ?>
                            </a>
                        </small>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('WordPress Cron:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td>
                        <?php 
                        // Check if cron is disabled
                        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                            echo '<span style="color: #dc3545;">' . esc_html__('❌ Disabled (DISABLE_WP_CRON)', 'ga4-server-side-tagging') . '</span>';
                        } elseif (wp_next_scheduled('ga4_process_event_queue')) {
                            // If our specific cron job is scheduled, show as active
                            echo '<span style="color: #28a745;">' . esc_html__('✅ Active', 'ga4-server-side-tagging') . '</span>';
                        } else {
                            // No scheduled job found
                            echo '<span style="color: #dc3545;">' . esc_html__('❌ Not Scheduled', 'ga4-server-side-tagging') . '</span>';
                        }
                        
                        // Show if currently executing (rare but possible)
                        if (wp_doing_cron()) {
                            echo '<br><small style="color: #0073aa;">' . esc_html__('🔄 Currently executing', 'ga4-server-side-tagging') . '</small>';
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <?php if (!wp_next_scheduled('ga4_process_event_queue')): ?>
            <div class="notice notice-warning" style="margin-top: 15px;">
                <p><strong><?php echo esc_html__('Warning:', 'ga4-server-side-tagging'); ?></strong> <?php echo esc_html__('The cronjob is not scheduled. Events will not be processed automatically.', 'ga4-server-side-tagging'); ?></p>
            </div>
        <?php endif; ?>
        
        <div style="margin-top: 15px;">
            <strong><?php echo esc_html__('How it Works:', 'ga4-server-side-tagging'); ?></strong>
            <ol style="margin-top: 10px;">
                <li><?php echo wp_kses(__('Events sent to <code>/wp-json/ga4-server-side-tagging/v1/send-events</code> are queued in the database', 'ga4-server-side-tagging'), array('code' => array())); ?></li>
                <li><?php echo esc_html__('Every 5 minutes, a WordPress cronjob processes all pending events as a single batch', 'ga4-server-side-tagging'); ?></li>
                <li><?php echo esc_html__('If secured transmission is enabled, events are encrypted when stored and decrypted when processed', 'ga4-server-side-tagging'); ?></li>
                <li><?php echo esc_html__('The batch is sent to your Cloudflare Worker with proper authentication', 'ga4-server-side-tagging'); ?></li>
                <li><?php echo esc_html__('Events are marked as completed or failed based on the response', 'ga4-server-side-tagging'); ?></li>
            </ol>
        </div>
    </div>

<!-- Queue Details Modal -->
<div id="queue-details-modal" class="event-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div class="event-modal-content" style="background-color: #fff; margin: 2% auto; padding: 0; border: 1px solid #ccd0d4; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); width: 95%; max-width: 900px; max-height: 85vh; overflow-y: auto;">
        <div class="event-modal-header" style="padding: 15px 20px; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; border-radius: 6px 6px 0 0;">
            <h2 style="margin: 0; font-size: 16px; color: #495057;">🔍 Queue Event Details</h2>
            <span class="queue-modal-close" style="color: #6c757d; font-size: 24px; font-weight: bold; cursor: pointer; line-height: 1;">&times;</span>
        </div>
        <div class="event-modal-body" style="padding: 20px;">
            <div id="queue-details-content">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Queue details modal
    $('.view-queue-details-btn').click(function() {
        var data = $(this).data();
        var content = '';
        
        // Basic Information Section
        content += '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
        content += '<h3 style="margin: 0 0 10px 0; color: #495057; font-size: 14px;">📊 Basic Information</h3>';
        content += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 13px;">';
        content += '<div><strong>ID:</strong> ' + data.eventId + '</div>';
        content += '<div><strong>Status:</strong> ' + getQueueStatusDisplay(data.status) + '</div>';
        content += '<div><strong>Created:</strong> ' + htmlEscape(data.created) + '</div>';
        if (data.processed) {
            content += '<div><strong>Processed:</strong> ' + htmlEscape(data.processed) + '</div>';
        }
        if (data.retryCount > 0) {
            content += '<div><strong>Retry Count:</strong> <span style="color: #ffc107; font-weight: bold;">' + data.retryCount + '</span></div>';
        }
        content += '</div>';
        if (data.error) {
            content += '<div style="margin-top: 10px;"><strong>Error:</strong><br><div style="background: #fff; padding: 8px; border: 1px solid #ddd; border-radius: 3px; color: #dc3545; font-family: monospace; font-size: 11px;">' + htmlEscape(data.error) + '</div></div>';
        }
        content += '</div>';

        // Event Data Section
        if (data.eventData) {
            content += '<div style="background: #f7fafc; border: 1px solid #a0aec0; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
            content += '<h3 style="margin: 0 0 10px 0; color: #4a5568; font-size: 14px;">📦 Event Data</h3>';
            
            // Show encryption status if event data was encrypted
            if (data.eventDataEncrypted === '1') {
                content += '<div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 3px; padding: 8px; margin-bottom: 10px; font-size: 12px; color: #155724;">';
                content += '<strong>🔓 Decrypted Event Data:</strong> This event data was encrypted with JWT and has been decrypted for display.';
                content += '</div>';
            }
            
            content += '<pre style="background: #fff; padding: 10px; border: 1px solid #dee2e6; border-radius: 3px; white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow-y: auto; font-size: 11px; margin: 0; line-height: 1.4;">' + formatJson(data.eventData) + '</pre>';
            content += '</div>';
        }

        // Show Final Headers earlier if available (move it up to show with event data)
        if (data.finalHeaders) {
            content += '<div style="background: #f0f8f0; border: 1px solid #28a745; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
            content += '<h3 style="margin: 0 0 10px 0; color: #28a745; font-size: 14px;">📡 Request Headers (Final)</h3>';
            
            // Show encryption status if headers were encrypted
            if (data.headersEncrypted === '1') {
                content += '<div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 3px; padding: 8px; margin-bottom: 10px; font-size: 12px; color: #155724;">';
                content += '<strong>🔓 Decrypted Headers:</strong> These headers were encrypted with JWT and have been decrypted for display.';
                content += '</div>';
            }
            
            content += '<p style="color: #666; font-size: 12px; margin: 0 0 10px 0;">These are the HTTP headers that were sent to external services (GA4/Cloudflare).</p>';
            content += '<pre style="background: #fff; padding: 10px; border: 1px solid #28a745; border-radius: 3px; white-space: pre-wrap; word-wrap: break-word; max-height: 200px; overflow-y: auto; font-size: 11px; margin: 0; line-height: 1.4;">' + formatJson(data.finalHeaders) + '</pre>';
            content += '</div>';
        }

        // Final Payload Section
        if (data.finalPayload) {
            content += '<div style="background: #f1f8ff; border: 1px solid #0073aa; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
            content += '<h3 style="margin: 0 0 10px 0; color: #0073aa; font-size: 14px;">🚀 Final Payload Sent</h3>';
            
            // Show encryption status if payload was encrypted
            if (data.payloadEncrypted === '1') {
                content += '<div style="background: #d4edda; border: 1px solid #c3e6cb; border-radius: 3px; padding: 8px; margin-bottom: 10px; font-size: 12px; color: #155724;">';
                content += '<strong>🔓 Decrypted Payload:</strong> This payload was encrypted with JWT and has been decrypted for display.';
                content += '</div>';
            }
            
            content += '<pre style="background: #fff; padding: 10px; border: 1px solid #0073aa; border-radius: 3px; white-space: pre-wrap; word-wrap: break-word; max-height: 300px; overflow-y: auto; font-size: 11px; margin: 0; line-height: 1.4;">' + formatJson(data.finalPayload) + '</pre>';
            content += '</div>';
        }


        $('#queue-details-content').html(content);
        $('#queue-details-modal').show();
    });

    // Close modal
    $('.queue-modal-close').click(function() {
        $('#queue-details-modal').hide();
    });

    // Close modal when clicking outside
    $('#queue-details-modal').click(function(e) {
        if (e.target.id === 'queue-details-modal') {
            $('#queue-details-modal').hide();
        }
    });

    // Helper functions
    function htmlEscape(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function getQueueStatusDisplay(status) {
        var statusMap = {
            'pending': '<span style="color: #0073aa;">⏳ Pending</span>',
            'completed': '<span style="color: #28a745;">✅ Completed</span>',
            'failed': '<span style="color: #dc3545;">❌ Failed</span>'
        };
        return statusMap[status] || '<span style="color: #6c757d;">❓ Unknown</span>';
    }

    function formatJson(jsonString) {
        if (!jsonString) return '';
        
        // If it's already an object, stringify it
        if (typeof jsonString === 'object') {
            try {
                return JSON.stringify(jsonString, null, 2);
            } catch (e) {
                return htmlEscape(String(jsonString));
            }
        }
        
        // If it's a JSON string, parse and format it
        try {
            var obj = JSON.parse(jsonString);
            return JSON.stringify(obj, null, 2);
        } catch (e) {
            // If it's not valid JSON, display as-is
            return htmlEscape(jsonString);
        }
    }
});
</script>

<style>
.ga4-admin-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin: 20px 0;
    padding: 15px 20px;
    border-radius: 3px;
}

.ga4-admin-section h2 {
    margin-top: 0;
    margin-bottom: 15px;
    font-size: 16px;
    border-bottom: 1px solid #eee;
    padding-bottom: 8px;
}

.event-modal {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.event-modal-content {
    animation: slideDown 0.2s ease-out;
}

@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-30px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.view-queue-details-btn:hover {
    background: #0073aa !important;
    color: white;
    border-color: #0073aa !important;
}

@media (max-width: 768px) {
    .event-modal-content {
        width: 98%;
        margin: 1% auto;
    }
    
    #queue-details-content > div {
        margin-bottom: 10px !important;
    }
    
    #queue-details-content > div > div[style*="grid"] {
        grid-template-columns: 1fr !important;
    }
}
</style>
</div>