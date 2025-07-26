<?php
/**
 * Provide an event monitoring admin area view for the plugin
 *
 * This file displays comprehensive event logs in a table format similar to cronjobs.
 *
 * @since      2.1.0
 * @package    GA4_Server_Side_Tagging
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

use GA4ServerSideTagging\Core\GA4_Event_Logger;

// Initialize event logger
$event_logger = new GA4_Event_Logger();

// Handle cleanup action
if (isset($_POST['cleanup_logs']) && wp_verify_nonce($_POST['_wpnonce'], 'ga4_cleanup_logs')) {
    $days = intval($_POST['cleanup_days']) ?: 30;
    
    // Save the cleanup days setting for Event Monitor
    update_option('ga4_event_logs_cleanup_days', $days);
    
    $cleaned = $event_logger->cleanup_old_logs($days);
    echo '<div class="notice notice-success is-dismissible"><p>Cleaned up ' . $cleaned . ' event logs older than ' . $days . ' days.</p></div>';
}

// Get the saved cleanup days setting for Event Monitor (default: 30 days)
$event_logs_cleanup_days = get_option('ga4_event_logs_cleanup_days', 30);

// Get filter parameters
$filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
$filter_event = isset($_GET['filter_event']) ? sanitize_text_field($_GET['filter_event']) : '';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$limit = isset($_GET['limit']) ? max(10, min(200, intval($_GET['limit']))) : 50;
$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;

// Get statistics and events using unified table approach
$stats = $event_logger->get_table_stats();
$events_data = $event_logger->get_events_for_table(array(
    'status' => $filter_status,
    'event_name' => $filter_event,
    'search' => $search,
    'limit' => $limit,
    'offset' => $offset
));

$events = $events_data['results'] ?? $events_data; // Handle both old and new return formats
$total_events = $events_data['total'] ?? count($events); // Get total from the method

// Get unique event names for filter using unified table
global $wpdb;
$table_name = $wpdb->prefix . 'ga4_event_logs';
$unique_events = $wpdb->get_col("SELECT DISTINCT event_name FROM $table_name WHERE event_name != '' AND event_name IS NOT NULL ORDER BY event_name LIMIT 50");

// Calculate pagination info
$total_pages = ceil($total_events / $limit);
$current_page = floor($offset / $limit) + 1;

?>

<div class="wrap">
    <h1><?php echo esc_html__('Event Monitor & Queue Management', 'ga4-server-side-tagging'); ?></h1>
    
    <!-- Tab Navigation -->
    <div class="nav-tab-wrapper" style="margin-bottom: 20px;">
        <a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-events'); ?>" class="nav-tab nav-tab-active">
            <?php echo esc_html__('📊 Event Monitor', 'ga4-server-side-tagging'); ?>
        </a>
        <a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-cronjobs'); ?>" class="nav-tab">
            <?php echo esc_html__('⚙️ Queue Management', 'ga4-server-side-tagging'); ?>
        </a>
    </div>
    
    <!-- Statistics Section -->
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Event Statistics', 'ga4-server-side-tagging'); ?></h2>
        <table class="widefat">
            <tbody>
                <tr>
                    <td><strong><?php echo esc_html__('Total Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['total']); ?></td>
                    <td><strong><?php echo esc_html__('Last 24h:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['last_24h']); ?></td>
                </tr>
                <tr>
                    <td><strong style="color: #28a745;"><?php echo esc_html__('Allowed Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['allowed']); ?></td>
                    <td><strong><?php echo esc_html__('Last 1h:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['last_1h']); ?></td>
                </tr>
                <tr>
                    <td><strong style="color: #dc3545;"><?php echo esc_html__('Denied Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['denied']); ?></td>
                    <td></td>
                    <td></td>
                </tr>
                <tr>
                    <td><strong style="color: #ffc107;"><?php echo esc_html__('Bot Detected:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['bot_detected']); ?></td>
                    <td><strong style="color: #6c757d;"><?php echo esc_html__('Errors:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo number_format($stats['error']); ?></td>
                </tr>
            </tbody>
        </table>
        
        <?php if (!empty($stats['top_events'])): ?>
        <div style="margin-top: 15px;">
            <strong><?php echo esc_html__('Top Event Types:', 'ga4-server-side-tagging'); ?></strong>
            <div style="margin-top: 5px;">
                <?php foreach (array_slice($stats['top_events'], 0, 5) as $event): ?>
                    <span style="display: inline-block; background: #f1f1f1; padding: 2px 8px; margin: 2px; border-radius: 3px; font-size: 12px;">
                        <?php echo esc_html($event['event_name']); ?> (<?php echo number_format($event['count']); ?>)
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filters Section -->
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Filters & Search', 'ga4-server-side-tagging'); ?></h2>
        <form method="get" action="">
            <input type="hidden" name="page" value="ga4-server-side-tagging-events">
            
            <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: end; margin-bottom: 15px;">
                <!-- Search -->
                <div>
                    <label for="search"><?php echo esc_html__('Search:', 'ga4-server-side-tagging'); ?></label><br>
                    <input type="text" id="search" name="search" value="<?php echo esc_attr($search); ?>" 
                           placeholder="Event name, IP, reason, user agent, payload..." style="width: 250px;">
                </div>

                <!-- Status Filter -->
                <div>
                    <label for="filter_status"><?php echo esc_html__('Status:', 'ga4-server-side-tagging'); ?></label><br>
                    <select id="filter_status" name="filter_status">
                        <option value=""><?php echo esc_html__('All Statuses', 'ga4-server-side-tagging'); ?></option>
                        <option value="allowed" <?php selected($filter_status, 'allowed'); ?>><?php echo esc_html__('✅ Allowed', 'ga4-server-side-tagging'); ?></option>
                        <option value="denied" <?php selected($filter_status, 'denied'); ?>><?php echo esc_html__('🚫 Denied', 'ga4-server-side-tagging'); ?></option>
                        <option value="bot_detected" <?php selected($filter_status, 'bot_detected'); ?>><?php echo esc_html__('🤖 Bot Detected', 'ga4-server-side-tagging'); ?></option>
                        <option value="error" <?php selected($filter_status, 'error'); ?>><?php echo esc_html__('⚠️ Error', 'ga4-server-side-tagging'); ?></option>
                    </select>
                </div>

                <!-- Event Name Filter -->
                <div>
                    <label for="filter_event"><?php echo esc_html__('Event Type:', 'ga4-server-side-tagging'); ?></label><br>
                    <select id="filter_event" name="filter_event">
                        <option value=""><?php echo esc_html__('All Events', 'ga4-server-side-tagging'); ?></option>
                        <?php foreach ($unique_events as $event_name): ?>
                            <option value="<?php echo esc_attr($event_name); ?>" <?php selected($filter_event, $event_name); ?>>
                                <?php echo esc_html($event_name); ?>
                            </option>
                        <?php endforeach; ?>
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
                    <a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-events'); ?>" class="button"><?php echo esc_html__('Clear', 'ga4-server-side-tagging'); ?></a>
                </div>
            </div>
        </form>
    </div>

    <!-- Management Section -->
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Management', 'ga4-server-side-tagging'); ?></h2>
        <form method="post" style="display: inline-block;">
            <?php wp_nonce_field('ga4_cleanup_logs'); ?>
            <label for="cleanup_days"><?php echo esc_html__('Clean up logs older than:', 'ga4-server-side-tagging'); ?></label>
            <input type="number" id="cleanup_days" name="cleanup_days" value="<?php echo esc_attr($event_logs_cleanup_days); ?>" min="1" max="365" style="width: 60px;">
            <span><?php echo esc_html__('days', 'ga4-server-side-tagging'); ?></span>
            <input type="submit" name="cleanup_logs" class="button" value="<?php echo esc_attr__('Cleanup Old Logs', 'ga4-server-side-tagging'); ?>"
                   onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete old event logs?', 'ga4-server-side-tagging')); ?>');">
            <p class="description"><?php echo esc_html__('Remove event logs older than the specified number of days to keep the database clean.', 'ga4-server-side-tagging'); ?></p>
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
                <?php if ($filter_status || $filter_event || $search): ?>
                    <span style="color: #666;"><?php echo esc_html__('(filtered)', 'ga4-server-side-tagging'); ?></span>
                <?php endif; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="tablenav-pages">
                <?php 
                $base_url = admin_url('admin.php?page=ga4-server-side-tagging-events');
                $query_args = array_filter(array(
                    'filter_status' => $filter_status,
                    'filter_event' => $filter_event,
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

    <!-- Events Table -->
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Event Logs', 'ga4-server-side-tagging'); ?></h2>
        
        <?php if (empty($events)): ?>
            <p><?php echo esc_html__('No events found matching your criteria.', 'ga4-server-side-tagging'); ?></p>
        <?php else: ?>
            <div style="max-height: 600px; overflow-y: auto; border: 1px solid #ddd; border-radius: 3px;">
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th style="width: 60px;"><?php echo esc_html__('ID', 'ga4-server-side-tagging'); ?></th>
                            <th><?php echo esc_html__('Event Name', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 100px;"><?php echo esc_html__('Status', 'ga4-server-side-tagging'); ?></th>
                            <th><?php echo esc_html__('Reason', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 120px;"><?php echo esc_html__('IP Address', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 140px;"><?php echo esc_html__('Created At', 'ga4-server-side-tagging'); ?></th>
                            <th style="width: 80px;"><?php echo esc_html__('Details', 'ga4-server-side-tagging'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?php echo intval($event->id); ?></td>
                                <td>
                                    <strong><?php echo esc_html($event->event_name); ?></strong>
                                    <?php if ($event->batch_size > 1): ?>
                                        <br><small style="color: #666;">Batch: <?php echo intval($event->batch_size); ?> events</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $status_colors = array(
                                        'allowed' => '#28a745',
                                        'denied' => '#dc3545', 
                                        'bot_detected' => '#ffc107',
                                        'error' => '#6c757d'
                                    );
                                    $status_icons = array(
                                        'allowed' => '✅',
                                        'denied' => '🚫',
                                        'bot_detected' => '🤖',
                                        'error' => '⚠️'
                                    );
                                    $color = $status_colors[$event->event_status] ?? '#6c757d';
                                    $icon = $status_icons[$event->event_status] ?? '❓';
                                    ?>
                                    <span style="color: <?php echo esc_attr($color); ?>; font-weight: bold; font-size: 11px;">
                                        <?php echo $icon; ?> <?php echo esc_html(ucfirst(str_replace('_', ' ', $event->event_status))); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($event->reason): ?>
                                        <span title="<?php echo esc_attr($event->reason); ?>" style="font-size: 11px;">
                                            <?php echo esc_html(wp_trim_words($event->reason, 8, '...')); ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <code style="font-size: 11px;"><?php echo esc_html($event->ip_address ?: 'N/A'); ?></code>
                                    <?php if ($event->consent_given !== null): ?>
                                        <br><small style="color: <?php echo $event->consent_given ? '#28a745' : '#dc3545'; ?>;">
                                            <?php echo $event->consent_given ? 'Consent ✓' : 'Consent ✗'; ?>
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 11px;">
                                    <?php 
                                    $date = new DateTime($event->created_at);
                                    echo esc_html($date->format('M j, Y'));
                                    echo '<br>' . esc_html($date->format('H:i:s')); 
                                    ?>
                                </td>
                                <td>
                                    <button class="button button-small view-details-btn" 
                                            data-event-id="<?php echo intval($event->id); ?>"
                                            data-event-name="<?php echo esc_attr($event->event_name); ?>"
                                            data-event-status="<?php echo esc_attr($event->event_status); ?>"
                                            data-reason="<?php echo esc_attr($event->reason ?? ''); ?>"
                                            data-payload="<?php echo esc_attr($event->payload ?? ''); ?>"
                                            data-headers="<?php echo esc_attr($event->headers ?? ''); ?>"
                                            data-ip="<?php echo esc_attr($event->ip_address ?? ''); ?>"
                                            data-user-agent="<?php echo esc_attr($event->user_agent ?? ''); ?>"
                                            data-url="<?php echo esc_attr($event->url ?? ''); ?>"
                                            data-referrer="<?php echo esc_attr($event->referrer ?? ''); ?>"
                                            data-user-id="<?php echo esc_attr($event->user_id ?? ''); ?>"
                                            data-session-id="<?php echo esc_attr($event->session_id ?? ''); ?>"
                                            data-consent-given="<?php 
                                                // Handle both boolean and integer values from database
                                                if (is_null($event->consent_given)) {
                                                    echo esc_attr('');
                                                } else {
                                                    // Convert to boolean first, then to string
                                                    $consent_bool = (bool) $event->consent_given;
                                                    echo esc_attr($consent_bool ? 'true' : 'false');
                                                }
                                            ?>"
                                            data-consent-debug="<?php echo esc_attr('Raw: ' . var_export($event->consent_given, true) . ' | Type: ' . gettype($event->consent_given)); ?>"
                                            data-bot-rules="<?php echo esc_attr($event->bot_detection_rules ?? ''); ?>"
                                            data-cf-response="<?php echo esc_attr($event->cloudflare_response ?? ''); ?>"
                                            data-batch-size="<?php echo esc_attr($event->batch_size ?? ''); ?>"
                                            data-transmission="<?php echo esc_attr($event->transmission_method ?? ''); ?>"
                                            data-created-at="<?php echo esc_attr($event->created_at); ?>"
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
</div>

<!-- Event Details Modal -->
<div id="event-details-modal" class="event-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.5);">
    <div class="event-modal-content" style="background-color: #fff; margin: 2% auto; padding: 0; border: 1px solid #ccd0d4; border-radius: 6px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); width: 95%; max-width: 900px; max-height: 85vh; overflow-y: auto;">
        <div class="event-modal-header" style="padding: 15px 20px; background-color: #f8f9fa; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; border-radius: 6px 6px 0 0;">
            <h2 style="margin: 0; font-size: 16px; color: #495057;">🔍 Event Details</h2>
            <span class="event-modal-close" style="color: #6c757d; font-size: 24px; font-weight: bold; cursor: pointer; line-height: 1;">&times;</span>
        </div>
        <div class="event-modal-body" style="padding: 20px;">
            <div id="event-details-content">
                <!-- Content will be populated by JavaScript -->
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Event details modal
    $('.view-details-btn').click(function() {
        var data = $(this).data();
        var content = '';
        
        // Basic Information Section
        content += '<div style="background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
        content += '<h3 style="margin: 0 0 10px 0; color: #495057; font-size: 14px;">📊 Basic Information</h3>';
        content += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 13px;">';
        content += '<div><strong>ID:</strong> ' + data.eventId + '</div>';
        content += '<div><strong>Event:</strong> <code>' + htmlEscape(data.eventName) + '</code></div>';
        content += '<div><strong>Status:</strong> ' + getStatusDisplay(data.eventStatus) + '</div>';
        content += '<div><strong>Date:</strong> ' + htmlEscape(data.createdAt) + '</div>';
        if (data.batchSize > 1) {
            content += '<div><strong>Batch Size:</strong> ' + data.batchSize + ' events</div>';
        }
        if (data.transmission) {
            content += '<div><strong>Method:</strong> ' + htmlEscape(data.transmission) + '</div>';
        }
        content += '</div>';
        if (data.reason) {
            content += '<div style="margin-top: 10px;"><strong>Reason:</strong> <span style="background: #fff; padding: 4px 8px; border-radius: 3px; border: 1px solid #ddd;">' + htmlEscape(data.reason) + '</span></div>';
        }
        content += '</div>';

        // Network & User Info
        content += '<div style="background: #e3f2fd; border: 1px solid #90caf9; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
        content += '<h3 style="margin: 0 0 10px 0; color: #1976d2; font-size: 14px;">🌐 Network & User Information</h3>';
        content += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; font-size: 13px;">';
        content += '<div><strong>IP Address:</strong> <code>' + htmlEscape(data.ip || 'N/A') + '</code></div>';
        if (data.userId) {
            content += '<div><strong>User ID:</strong> ' + htmlEscape(data.userId) + '</div>';
        }
        if (data.sessionId) {
            content += '<div><strong>Session:</strong> <code style="word-break: break-all;">' + htmlEscape(data.sessionId.substring(0, 20)) + '...</code></div>';
        }
        // Try to get detailed consent info from payload first
        var consentDetails = extractConsentFromPayload(data.payload);
        var consentStatus = null;
        
        // If we have consent details from payload, determine status from that
        if (consentDetails) {
            if (consentDetails.consent_mode) {
                // Legacy format
                consentStatus = consentDetails.consent_mode === 'GRANTED';
            } else if (consentDetails.ad_user_data && consentDetails.ad_personalization) {
                // Modern format - both must be granted
                consentStatus = (consentDetails.ad_user_data === 'GRANTED' && consentDetails.ad_personalization === 'GRANTED');
            } else if (consentDetails.ad_user_data) {
                // Only ad_user_data present
                consentStatus = consentDetails.ad_user_data === 'GRANTED';
            } else if (consentDetails.ad_personalization) {
                // Only ad_personalization present  
                consentStatus = consentDetails.ad_personalization === 'GRANTED';
            }
        }
        
        // Fallback to database field if payload parsing didn't work
        if (consentStatus === null) {
            // Handle various formats from database
            if (data.consentGiven === 'true' || data.consentGiven === '1' || data.consentGiven === 1 || data.consentGiven === true) {
                consentStatus = true;
            } else if (data.consentGiven === 'false' || data.consentGiven === '0' || data.consentGiven === 0 || data.consentGiven === false) {
                consentStatus = false;
            }
        }
        
        // Debug logging
        console.log('Consent Debug:', {
            'data.consentGiven': data.consentGiven,
            'data.consentDebug': data.consentDebug,
            'consentDetails': consentDetails,
            'consentStatus from payload': consentStatus,
            'final consentStatus': consentStatus
        });
        
        // Generate display based on determined status
        var consentDisplay = consentStatus === true ? '<span style="color: #28a745;">✅ Granted</span>' : 
                           consentStatus === false ? '<span style="color: #dc3545;">❌ Denied</span>' : 
                           '<span style="color: #6c757d;">❓ Unknown</span>';
        
        // Add detailed consent summary if available
        if (consentDetails) {
            var consentSummary = '';
            if (consentDetails.ad_user_data) {
                consentSummary += 'Ad Data: ' + consentDetails.ad_user_data + ' ';
            }
            if (consentDetails.ad_personalization) {
                consentSummary += 'Personalization: ' + consentDetails.ad_personalization + ' ';
            }
            if (consentDetails.consent_reason) {
                consentSummary += '(' + consentDetails.consent_reason + ')';
            }
            
            if (consentSummary) {
                consentDisplay += '<br><small style="color: #666; font-size: 10px;">' + htmlEscape(consentSummary) + '</small>';
            }
        }
        
        content += '<div><strong>Consent:</strong> ' + consentDisplay + '</div>';
        content += '</div>';
        if (data.url) {
            content += '<div style="margin-top: 10px;"><strong>URL:</strong><br><code style="word-break: break-all; font-size: 11px;">' + htmlEscape(data.url) + '</code></div>';
        }
        if (data.referrer) {
            content += '<div style="margin-top: 10px;"><strong>Referrer:</strong><br><code style="word-break: break-all; font-size: 11px;">' + htmlEscape(data.referrer) + '</code></div>';
        }
        content += '</div>';

        // User Agent
        if (data.userAgent) {
            content += '<div style="background: #fff3e0; border: 1px solid #ffcc02; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
            content += '<h3 style="margin: 0 0 10px 0; color: #f57c00; font-size: 14px;">🔍 User Agent</h3>';
            content += '<code style="word-break: break-all; font-size: 11px; line-height: 1.4;">' + htmlEscape(data.userAgent) + '</code>';
            content += '</div>';
        }

        // Consent Information (extract from payload)
        var consentData = extractConsentFromPayload(data.payload);
        if (consentData) {
            content += '<div style="background: #e8f5e8; border: 1px solid #4caf50; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
            content += '<h3 style="margin: 0 0 10px 0; color: #2e7d2e; font-size: 14px;">🍪 Consent Information</h3>';
            content += '<pre style="background: #fff; padding: 10px; border: 1px solid #dee2e6; border-radius: 3px; white-space: pre-wrap; word-wrap: break-word; max-height: 150px; overflow-y: auto; font-size: 11px; margin: 0; line-height: 1.4;">' + formatJson(JSON.stringify(consentData)) + '</pre>';
            content += '</div>';
        }

        // Payload
        if (data.payload) {
            content += '<div style="background: #f7fafc; border: 1px solid #a0aec0; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
            content += '<h3 style="margin: 0 0 10px 0; color: #4a5568; font-size: 14px;">📦 Complete Request Payload</h3>';
            content += '<pre style="background: #fff; padding: 10px; border: 1px solid #dee2e6; border-radius: 3px; white-space: pre-wrap; word-wrap: break-word; max-height: 250px; overflow-y: auto; font-size: 11px; margin: 0; line-height: 1.4;">' + formatJson(data.payload) + '</pre>';
            content += '</div>';
        }

        // Bot Detection Rules (if applicable)
        if (data.botRules) {
            content += '<div style="background: #ffeaa7; border: 1px solid #fdcb6e; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
            content += '<h3 style="margin: 0 0 10px 0; color: #e17055; font-size: 14px;">🤖 Bot Detection Analysis</h3>';
            content += '<pre style="background: #fff; padding: 10px; border: 1px solid #dee2e6; border-radius: 3px; white-space: pre-wrap; word-wrap: break-word; max-height: 200px; overflow-y: auto; font-size: 11px; margin: 0;">' + formatJson(data.botRules) + '</pre>';
            content += '</div>';
        }

        // Headers
        if (data.headers) {
            content += '<div style="background: #f0f4f8; border: 1px solid #90cdf4; border-radius: 4px; padding: 15px; margin-bottom: 15px;">';
            content += '<h3 style="margin: 0 0 10px 0; color: #2b6cb0; font-size: 14px;">📋 Request Headers</h3>';
            content += '<pre style="background: #fff; padding: 10px; border: 1px solid #dee2e6; border-radius: 3px; white-space: pre-wrap; word-wrap: break-word; max-height: 200px; overflow-y: auto; font-size: 11px; margin: 0; line-height: 1.4;">' + formatJson(data.headers) + '</pre>';
            content += '</div>';
        }

        // Cloudflare Response
        if (data.cfResponse) {
            content += '<div style="background: #fff5f5; border: 1px solid #fed7d7; border-radius: 4px; padding: 15px;">';
            content += '<h3 style="margin: 0 0 10px 0; color: #c53030; font-size: 14px;">☁️ Cloudflare Response</h3>';
            content += '<pre style="background: #fff; padding: 10px; border: 1px solid #dee2e6; border-radius: 3px; white-space: pre-wrap; word-wrap: break-word; max-height: 150px; overflow-y: auto; font-size: 11px; margin: 0;">' + htmlEscape(data.cfResponse) + '</pre>';
            content += '</div>';
        }

        $('#event-details-content').html(content);
        $('#event-details-modal').show();
    });

    // Close modal
    $('.event-modal-close').click(function() {
        $('#event-details-modal').hide();
    });

    // Close modal when clicking outside
    $('#event-details-modal').click(function(e) {
        if (e.target.id === 'event-details-modal') {
            $('#event-details-modal').hide();
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

    function getStatusDisplay(status) {
        var statusMap = {
            'allowed': '<span style="color: #28a745;">✅ Allowed</span>',
            'denied': '<span style="color: #dc3545;">🚫 Denied</span>',
            'bot_detected': '<span style="color: #ffc107;">🤖 Bot Detected</span>',
            'error': '<span style="color: #6c757d;">⚠️ Error</span>'
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
            // If it's not valid JSON, try to display it nicely anyway
            var cleaned = htmlEscape(jsonString);
            
            // If it looks like query params or form data, format it
            if (cleaned.includes('&') && cleaned.includes('=')) {
                return cleaned.replace(/&/g, '\n&').replace(/=/g, ' = ');
            }
            
            return cleaned;
        }
    }

    function extractConsentFromPayload(payloadString) {
        if (!payloadString) return null;
        
        try {
            var payload = JSON.parse(payloadString);
            if (payload && payload.consent) {
                return payload.consent;
            }
        } catch (e) {
            // If parsing fails, return null
        }
        
        return null;
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

.view-details-btn:hover {
    background: #0073aa !important;
    color: white;
    border-color: #0073aa !important;
}

@media (max-width: 768px) {
    .event-modal-content {
        width: 98%;
        margin: 1% auto;
    }
    
    #event-details-content > div {
        margin-bottom: 10px !important;
    }
    
    #event-details-content > div > div[style*="grid"] {
        grid-template-columns: 1fr !important;
    }
}
</style>