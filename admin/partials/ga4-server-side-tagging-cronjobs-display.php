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

// Handle manual trigger action
if (isset($_POST['trigger_cronjob']) && wp_verify_nonce($_POST['_wpnonce'], 'ga4_trigger_cronjob')) {
    $this->cronjob_manager->trigger_manual_processing();
    echo '<div class="notice notice-success is-dismissible"><p>Cronjob triggered successfully! Events have been processed.</p></div>';
}

// Handle cleanup action
if (isset($_POST['cleanup_events']) && wp_verify_nonce($_POST['_wpnonce'], 'ga4_cleanup_events')) {
    $days = intval($_POST['cleanup_days']) ?: 7;
    $cleaned = $this->cronjob_manager->cleanup_old_events($days);
    echo '<div class="notice notice-success is-dismissible"><p>Cleaned up ' . $cleaned . ' old events.</p></div>';
}

// Get queue statistics
$stats = $this->cronjob_manager->get_queue_stats();
$next_scheduled = wp_next_scheduled('ga4_process_event_queue');

// Get filter parameters
$filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
$limit = isset($_GET['limit']) ? max(10, min(200, intval($_GET['limit']))) : 50;
$offset = isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0;

// Get events with filtering and pagination
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
    <h1><?php echo esc_html__('Event Queue & Cronjob Management', 'ga4-server-side-tagging'); ?></h1>
    
    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Queue Statistics', 'ga4-server-side-tagging'); ?></h2>
        <table class="widefat">
            <tbody>
                <tr>
                    <td><strong><?php echo esc_html__('Total Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo esc_html($stats['total']); ?></td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('Pending Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo esc_html($stats['pending']); ?></td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('Completed Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo esc_html($stats['completed']); ?></td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('Failed Events:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td><?php echo esc_html($stats['failed']); ?></td>
                </tr>
                <tr>
                    <td><strong><?php echo esc_html__('Next Scheduled Run:', 'ga4-server-side-tagging'); ?></strong></td>
                    <td>
                        <?php 
                        if ($next_scheduled) {
                            echo esc_html(date('Y-m-d H:i:s', $next_scheduled));
                            echo ' (' . esc_html(human_time_diff($next_scheduled, time())) . ')';
                        } else {
                            echo esc_html__('Not scheduled', 'ga4-server-side-tagging');
                        }
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Manual Controls', 'ga4-server-side-tagging'); ?></h2>
        <form method="post" style="display: inline-block; margin-right: 20px;">
            <?php wp_nonce_field('ga4_trigger_cronjob'); ?>
            <input type="submit" name="trigger_cronjob" class="button button-primary" value="<?php echo esc_attr__('Trigger Cronjob Now', 'ga4-server-side-tagging'); ?>" 
                   onclick="return confirm('<?php echo esc_js(__('This will immediately process all pending events. Continue?', 'ga4-server-side-tagging')); ?>');">
            <p class="description"><?php echo esc_html__('Manually trigger the cronjob to process all pending events immediately.', 'ga4-server-side-tagging'); ?></p>
        </form>

        <form method="post" style="display: inline-block;">
            <?php wp_nonce_field('ga4_cleanup_events'); ?>
            <label for="cleanup_days"><?php echo esc_html__('Clean up events older than:', 'ga4-server-side-tagging'); ?></label>
            <input type="number" id="cleanup_days" name="cleanup_days" value="7" min="1" max="365" style="width: 60px;">
            <span><?php echo esc_html__('days', 'ga4-server-side-tagging'); ?></span>
            <input type="submit" name="cleanup_events" class="button" value="<?php echo esc_attr__('Cleanup Old Events', 'ga4-server-side-tagging'); ?>">
            <p class="description"><?php echo esc_html__('Remove processed/failed events older than the specified number of days.', 'ga4-server-side-tagging'); ?></p>
        </form>
    </div>

    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Event Queue', 'ga4-server-side-tagging'); ?></h2>
        
        <!-- Filters -->
        <form method="get" class="ga4-filters">
            <input type="hidden" name="page" value="<?php echo esc_attr($_GET['page']); ?>" />
            
            <div class="filter-group">
                <label for="filter_status"><?php echo esc_html__('Filter by Status:', 'ga4-server-side-tagging'); ?></label>
                <select name="filter_status" id="filter_status">
                    <option value=""><?php echo esc_html__('All Statuses', 'ga4-server-side-tagging'); ?></option>
                    <option value="pending" <?php selected($filter_status, 'pending'); ?>><?php echo esc_html__('Pending', 'ga4-server-side-tagging'); ?></option>
                    <option value="completed" <?php selected($filter_status, 'completed'); ?>><?php echo esc_html__('Completed', 'ga4-server-side-tagging'); ?></option>
                    <option value="failed" <?php selected($filter_status, 'failed'); ?>><?php echo esc_html__('Failed', 'ga4-server-side-tagging'); ?></option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="search"><?php echo esc_html__('Search:', 'ga4-server-side-tagging'); ?></label>
                <input type="text" name="search" id="search" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Search ID or error message...', 'ga4-server-side-tagging'); ?>" />
            </div>
            
            <div class="filter-group">
                <label for="limit"><?php echo esc_html__('Per Page:', 'ga4-server-side-tagging'); ?></label>
                <select name="limit" id="limit">
                    <option value="25" <?php selected($limit, 25); ?>>25</option>
                    <option value="50" <?php selected($limit, 50); ?>>50</option>
                    <option value="100" <?php selected($limit, 100); ?>>100</option>
                    <option value="200" <?php selected($limit, 200); ?>>200</option>
                </select>
            </div>
            
            <div class="filter-group">
                <input type="submit" class="button" value="<?php echo esc_attr__('Filter', 'ga4-server-side-tagging'); ?>" />
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . $_GET['page'])); ?>" class="button"><?php echo esc_html__('Reset', 'ga4-server-side-tagging'); ?></a>
            </div>
        </form>

        <!-- Results Info -->
        <?php if ($total_events > 0): ?>
            <div class="ga4-results-info">
                <?php echo sprintf(
                    esc_html__('Showing %d-%d of %s events', 'ga4-server-side-tagging'),
                    $offset + 1,
                    min($offset + $limit, $total_events),
                    number_format($total_events)
                ); ?>
            </div>
        <?php endif; ?>

        <!-- Events Table -->
        <?php if (empty($events)): ?>
            <p><?php echo esc_html__('No events found matching your criteria.', 'ga4-server-side-tagging'); ?></p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('ID', 'ga4-server-side-tagging'); ?></th>
                        <th><?php echo esc_html__('Status', 'ga4-server-side-tagging'); ?></th>
                        <th><?php echo esc_html__('Created', 'ga4-server-side-tagging'); ?></th>
                        <th><?php echo esc_html__('Processed', 'ga4-server-side-tagging'); ?></th>
                        <th><?php echo esc_html__('Retry Count', 'ga4-server-side-tagging'); ?></th>
                        <th><?php echo esc_html__('Error', 'ga4-server-side-tagging'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $event): ?>
                        <tr>
                            <td><?php echo esc_html($event->id); ?></td>
                            <td>
                                <span class="status-<?php echo esc_attr($event->status); ?>">
                                    <?php echo esc_html(ucfirst($event->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($event->created_at); ?></td>
                            <td><?php echo esc_html($event->processed_at ?: '-'); ?></td>
                            <td><?php echo esc_html($event->retry_count); ?></td>
                            <td>
                                <?php if ($event->error_message): ?>
                                    <span title="<?php echo esc_attr($event->error_message); ?>" style="cursor: help;">
                                        <?php echo esc_html(wp_trim_words($event->error_message, 10)); ?>
                                    </span>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="ga4-pagination">
                <?php
                $base_url = admin_url('admin.php?page=' . $_GET['page']);
                $query_args = array_filter(array(
                    'filter_status' => $filter_status,
                    'search' => $search,
                    'limit' => $limit
                ));

                // First/Previous links
                if ($current_page > 1):
                    echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($query_args, array('offset' => 0)), $base_url)) . '">' . esc_html__('« First', 'ga4-server-side-tagging') . '</a> ';
                    echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($query_args, array('offset' => max(0, $offset - $limit))), $base_url)) . '">' . esc_html__('‹ Previous', 'ga4-server-side-tagging') . '</a> ';
                endif;

                // Page numbers
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);

                for ($i = $start_page; $i <= $end_page; $i++):
                    $page_offset = ($i - 1) * $limit;
                    if ($i == $current_page):
                        echo '<span class="button button-primary disabled">' . $i . '</span> ';
                    else:
                        echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($query_args, array('offset' => $page_offset)), $base_url)) . '">' . $i . '</a> ';
                    endif;
                endfor;

                // Next/Last links
                if ($current_page < $total_pages):
                    echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($query_args, array('offset' => $offset + $limit)), $base_url)) . '">' . esc_html__('Next ›', 'ga4-server-side-tagging') . '</a> ';
                    echo '<a class="button" href="' . esc_url(add_query_arg(array_merge($query_args, array('offset' => ($total_pages - 1) * $limit)), $base_url)) . '">' . esc_html__('Last »', 'ga4-server-side-tagging') . '</a>';
                endif;
                ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="ga4-admin-section">
        <h2><?php echo esc_html__('Cronjob Configuration', 'ga4-server-side-tagging'); ?></h2>
        <p><strong><?php echo esc_html__('Processing Schedule:', 'ga4-server-side-tagging'); ?></strong> <?php echo esc_html__('Every 5 minutes', 'ga4-server-side-tagging'); ?></p>
        <p><strong><?php echo esc_html__('Batch Size:', 'ga4-server-side-tagging'); ?></strong> <?php echo esc_html__('Up to', 'ga4-server-side-tagging'); ?> <?php echo esc_html(get_option('ga4_cronjob_batch_size', 1000)); ?> <?php echo esc_html__('events per batch', 'ga4-server-side-tagging'); ?></p>
        <p><strong><?php echo esc_html__('WordPress Cron:', 'ga4-server-side-tagging'); ?></strong> <?php echo wp_doing_cron() ? esc_html__('Running', 'ga4-server-side-tagging') : esc_html__('Idle', 'ga4-server-side-tagging'); ?></p>
        
        <?php if (!wp_next_scheduled('ga4_process_event_queue')): ?>
            <div class="notice notice-warning">
                <p><strong><?php echo esc_html__('Warning:', 'ga4-server-side-tagging'); ?></strong> <?php echo esc_html__('The cronjob is not scheduled. Events will not be processed automatically.', 'ga4-server-side-tagging'); ?></p>
            </div>
        <?php endif; ?>
        
        <h3><?php echo esc_html__('How it Works', 'ga4-server-side-tagging'); ?></h3>
        <ol>
            <li><?php echo wp_kses(__('Events sent to <code>/wp-json/ga4-server-side-tagging/v1/send-events</code> are queued in the database', 'ga4-server-side-tagging'), array('code' => array())); ?></li>
            <li><?php echo esc_html__('Every 5 minutes, a WordPress cronjob processes all pending events as a single batch', 'ga4-server-side-tagging'); ?></li>
            <li><?php echo esc_html__('If secured transmission is enabled, events are encrypted when stored and decrypted when processed', 'ga4-server-side-tagging'); ?></li>
            <li><?php echo esc_html__('The batch is sent to your Cloudflare Worker with proper authentication', 'ga4-server-side-tagging'); ?></li>
            <li><?php echo esc_html__('Events are marked as completed or failed based on the response', 'ga4-server-side-tagging'); ?></li>
        </ol>
    </div>

    <style>
        .ga4-admin-section {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            margin: 20px 0;
            padding: 20px;
        }
        .ga4-admin-section h2 {
            margin-top: 0;
        }
        .status-pending { color: #0073aa; font-weight: bold; }
        .status-completed { color: #46b450; font-weight: bold; }
        .status-failed { color: #dc3232; font-weight: bold; }
        
        /* Filter styles */
        .ga4-filters {
            background: #f9f9f9;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            align-items: flex-end;
        }
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-height: 50px;
            justify-content: flex-end;
            margin: 0!important;
            width: 24%;
        }
        .filter-group label {
            font-weight: 600;
            font-size: 12px;
            color: #555;
            margin-bottom: 5px;
        }
        .filter-group select,
        .filter-group input[type="text"] {
            min-width: 120px;
            height: 30px;
        }
        .filter-group .button {
            height: 30px;
            line-height: 28px;
            margin-right: 5px;
        }
        
        /* Results info */
        .ga4-results-info {
            background: #f0f0f1;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 15px;
            font-size: 13px;
            color: #646970;
        }
        
        /* Pagination styles */
        .ga4-pagination {
            margin-top: 20px;
            text-align: center;
            padding: 15px 0;
            border-top: 1px solid #ddd;
        }
        .ga4-pagination .button {
            margin: 0 2px;
        }
        .ga4-pagination .button.disabled {
            cursor: default;
            opacity: 0.7;
        }
        
        /* Responsive design */
        @media (max-width: 768px) {
            .ga4-filters {
                flex-direction: column;
                align-items: stretch;
            }
            .filter-group {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
            .filter-group label {
                margin-bottom: 0;
                min-width: 100px;
            }
        }
    </style>
</div>