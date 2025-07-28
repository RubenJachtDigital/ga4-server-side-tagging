<?php

namespace GA4ServerSideTagging\Core;

use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;
use GA4ServerSideTagging\API\GA4_Server_Side_Tagging_Endpoint;

/**
 * Cron job management for GA4 Server-Side Tagging.
 *
 * Handles scheduled event queue processing and cleanup tasks.
 *
 * @since      1.0.0
 * @package    GA4_Server_Side_Tagging
 */

if (!defined('WPINC')) {
    die;
}

/**
 * Cron job management class.
 *
 * @since      1.0.0
 */
class GA4_Server_Side_Tagging_Cron
{
    /**
     * The logger instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    private $logger;

    /**
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    public function __construct(?GA4_Server_Side_Tagging_Logger $logger = null)
    {
        $this->logger = $logger ?: new GA4_Server_Side_Tagging_Logger();
    }

    /**
     * Register cron jobs and schedules.
     *
     * @since    1.0.0
     */
    public function register_cron_jobs()
    {
        // Add custom cron interval
        add_filter('cron_schedules', array($this, 'add_cron_intervals'));
        
        // Register cron job handlers
        add_action('ga4_process_event_queue', array($this, 'handle_event_queue_processing'));
        add_action('ga4_cleanup_old_events', array($this, 'handle_event_cleanup'));
    }

    /**
     * Schedule cron jobs on plugin activation.
     *
     * @since    1.0.0
     */
    public function schedule_cron_jobs()
    {
        // Schedule event queue processing
        if (!wp_next_scheduled('ga4_process_event_queue')) {
            wp_schedule_event(time(), 'ga4_five_minutes', 'ga4_process_event_queue');
        }
        
        // Schedule daily cleanup of old completed events
        if (!wp_next_scheduled('ga4_cleanup_old_events')) {
            wp_schedule_event(time(), 'daily', 'ga4_cleanup_old_events');
        }
    }

    /**
     * Clear all scheduled cron jobs.
     *
     * @since    1.0.0
     */
    public function clear_scheduled_jobs()
    {
        wp_clear_scheduled_hook('ga4_process_event_queue');
        wp_clear_scheduled_hook('ga4_cleanup_old_events');
    }

    /**
     * Ensure cron jobs are scheduled (called on init to catch missed activations).
     *
     * @since    2.0.0
     */
    public function maybe_schedule_crons()
    {
        $this->schedule_cron_jobs();
    }

    /**
     * Add custom cron intervals.
     *
     * @since    1.0.0
     * @param    array    $schedules    Existing cron schedules.
     * @return   array                  Modified cron schedules.
     */
    public function add_cron_intervals($schedules)
    {
        $schedules['ga4_five_minutes'] = array(
            'interval' => 300, // 5 minutes in seconds
            'display'  => esc_html__('Every 5 Minutes', 'ga4-server-side-tagging'),
        );
        
        $schedules['ga4_two_minutes'] = array(
            'interval' => 120, // 2 minutes in seconds (for high-traffic sites)
            'display'  => esc_html__('Every 2 Minutes', 'ga4-server-side-tagging'),
        );
        
        return $schedules;
    }

    /**
     * Handle the cron job for processing event queue.
     *
     * @since    1.0.0
     */
    public function handle_event_queue_processing()
    {
        $start_time = microtime(true);
        
        // Prevent multiple cron jobs from running simultaneously
        if (get_transient('ga4_queue_processing')) {
            $this->logger->info('Event queue processing skipped - another process is already running');
            return;
        }
        
        // Set a lock to prevent concurrent processing (expires after 5 minutes)
        set_transient('ga4_queue_processing', true, 300);
        
        try {
            // Include necessary files
            require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-cronjob-manager.php';
            require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-encryption-util.php';
            
            // Initialize the cronjob manager
            $cronjob_manager = new \GA4ServerSideTagging\Core\GA4_Cronjob_Manager($this->logger);
            
            // Process the queue
            $cronjob_manager->process_event_queue();
            
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            
            $this->logger->info("Event queue processing completed in {$processing_time}ms");
        } catch (\Exception $e) {
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            $this->logger->error(sprintf(
                'Cron job exception after %.2fms: %s',
                $processing_time,
                $e->getMessage()
            ));
        } finally {
            // Always release the lock
            delete_transient('ga4_queue_processing');
        }
    }

    /**
     * Handle cleanup of old completed events.
     *
     * @since    1.0.0
     */
    public function handle_event_cleanup()
    {
        global $wpdb;
        
        try {
            $table_name = $wpdb->prefix . 'ga4_event_queue';
            
            // Get cleanup settings
            $cleanup_days = get_option('ga4_queue_cleanup_days', 7); // Default 7 days
            $max_completed_events = get_option('ga4_queue_max_completed', 10000); // Default 10k events
            
            // Clean up old completed events (older than X days)
            $date_threshold = date('Y-m-d H:i:s', strtotime("-{$cleanup_days} days"));
            
            $deleted_old = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table_name} WHERE status = 'completed' AND processed_at < %s",
                    $date_threshold
                )
            );
            
            // Clean up excess completed events (keep only the most recent X events)
            $excess_events = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) - %d FROM {$table_name} WHERE status = 'completed'",
                    $max_completed_events
                )
            );
            
            $deleted_excess = 0;
            if ($excess_events > 0) {
                $wpdb->query(
                    $wpdb->prepare(
                        "DELETE FROM {$table_name} WHERE status = 'completed' ORDER BY processed_at ASC LIMIT %d",
                        $excess_events
                    )
                );
                $deleted_excess = $excess_events;
            }
            
            // Clean up very old failed events (older than 30 days)
            $failed_date_threshold = date('Y-m-d H:i:s', strtotime('-30 days'));
            $deleted_failed = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$table_name} WHERE status = 'failed' AND created_at < %s",
                    $failed_date_threshold
                )
            );
            
            $total_deleted = $deleted_old + $deleted_excess + $deleted_failed;
            
            if ($total_deleted > 0) {
                $this->logger->info(sprintf(
                    'Event cleanup completed: %d old completed, %d excess completed, %d old failed events removed',
                    $deleted_old,
                    $deleted_excess,
                    $deleted_failed
                ));
            }
            
            // Optimize table if significant cleanup occurred
            if ($total_deleted > 1000) {
                $wpdb->query("OPTIMIZE TABLE {$table_name}");
                $this->logger->info('Event queue table optimized after cleanup');
            }
        } catch (\Exception $e) {
            $this->logger->error('Event cleanup failed: ' . $e->getMessage());
        }
    }

    /**
     * Update performance metrics for monitoring.
     *
     * @since    1.0.0
     * @param    int      $events_processed    Number of events processed.
     * @param    float    $processing_time     Processing time in milliseconds.
     */
    private function update_performance_metrics($events_processed, $processing_time)
    {
        // Store last 10 performance metrics for monitoring
        $metrics = get_option('ga4_queue_performance_metrics', array());
        
        $new_metric = array(
            'timestamp' => current_time('mysql'),
            'events_processed' => $events_processed,
            'processing_time_ms' => $processing_time,
            'events_per_second' => round($events_processed / ($processing_time / 1000), 2)
        );
        
        array_unshift($metrics, $new_metric);
        
        // Keep only last 10 metrics
        $metrics = array_slice($metrics, 0, 10);
        
        update_option('ga4_queue_performance_metrics', $metrics);
    }

    /**
     * Get queue performance statistics.
     *
     * @since    1.0.0
     * @return   array    Performance statistics.
     */
    public function get_performance_stats()
    {
        $metrics = get_option('ga4_queue_performance_metrics', array());
        
        if (empty($metrics)) {
            return array(
                'total_batches' => 0,
                'total_events_processed' => 0,
                'average_processing_time' => 0,
                'average_events_per_batch' => 0,
                'average_events_per_second' => 0
            );
        }
        
        $total_batches = count($metrics);
        $total_events = array_sum(array_column($metrics, 'events_processed'));
        $total_time = array_sum(array_column($metrics, 'processing_time_ms'));
        $total_eps = array_sum(array_column($metrics, 'events_per_second'));
        
        return array(
            'total_batches' => $total_batches,
            'total_events_processed' => $total_events,
            'average_processing_time' => round($total_time / $total_batches, 2),
            'average_events_per_batch' => round($total_events / $total_batches, 1),
            'average_events_per_second' => round($total_eps / $total_batches, 2),
            'last_processed' => $metrics[0]['timestamp'] ?? null
        );
    }

    /**
     * Check if queue processing is currently running.
     *
     * @since    1.0.0
     * @return   bool    True if processing is running.
     */
    public function is_processing()
    {
        return (bool) get_transient('ga4_queue_processing');
    }

    /**
     * Force release the processing lock (for emergency situations).
     *
     * @since    1.0.0
     */
    public function force_release_lock()
    {
        delete_transient('ga4_queue_processing');
        $this->logger->warning('Queue processing lock forcibly released');
    }
}
