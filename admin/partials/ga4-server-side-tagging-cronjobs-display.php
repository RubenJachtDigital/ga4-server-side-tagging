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
$recent_events = $this->cronjob_manager->get_recent_events(100);
$next_scheduled = wp_next_scheduled('ga4_process_event_queue');
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
        <h2><?php echo esc_html__('Recent Events (Last 100)', 'ga4-server-side-tagging'); ?></h2>
        <?php if (empty($recent_events)): ?>
            <p><?php echo esc_html__('No events found in the queue.', 'ga4-server-side-tagging'); ?></p>
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
                    <?php foreach ($recent_events as $event): ?>
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
    </style>
</div>