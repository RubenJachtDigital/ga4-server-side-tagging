<?php
/**
 * GA4 Server-Side Tagging
 *
 * @package           GA4_Server_Side_Tagging
 * @author            Jacht Digital Marketing
 * @copyright         2025 Jacht Digital Marketing
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       GA4 Server-Side Tagging
 * Plugin URI:        https://jacht.digital/
 * Description:       Server-side tagging system for GA4 that is fully compatible with WordPress and WooCommerce, hosted on Cloudflare.
 * Version:           2.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Jacht Digital Marketing
 * Author URI:        https://jacht.digital/
 * Text Domain:       ga4-server-side-tagging
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Update URI:        https://jacht.digital/
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// Define plugin version
define( 'GA4_SERVER_SIDE_TAGGING_VERSION', '2.2.0' );
define( 'GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GA4_SERVER_SIDE_TAGGING_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include required files
require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-server-side-tagging.php';
require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-server-side-tagging-loader.php';
require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-server-side-tagging-logger.php';
require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-server-side-tagging-cron.php';

// Use the namespaced classes
use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging;
use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Cron;

// Activation and deactivation hooks
register_activation_hook( __FILE__, 'ga4_server_side_tagging_activate' );
register_deactivation_hook( __FILE__, 'ga4_server_side_tagging_deactivate' );

/**
 * The code that runs during plugin activation.
 */
function ga4_server_side_tagging_activate() {
    // Create necessary database tables and options
    update_option( 'ga4_server_side_tagging_version', GA4_SERVER_SIDE_TAGGING_VERSION );
    update_option( 'ga4_server_side_tagging_debug_mode', false );
    
    // Create event queue table
    ga4_create_event_queue_table();
    
    // Initialize cron manager and schedule jobs
    $cron = new GA4_Server_Side_Tagging_Cron();
    $cron->schedule_cron_jobs();
}

/**
 * Create the event queue database table
 */
function ga4_create_event_queue_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'ga4_event_queue';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        event_data longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        processed_at datetime NULL,
        retry_count int(11) DEFAULT 0,
        status enum('pending','processing','completed','failed') DEFAULT 'pending',
        batch_id varchar(32) NULL,
        error_message text NULL,
        PRIMARY KEY (id),
        KEY idx_status_created (status, created_at),
        KEY idx_batch_id (batch_id),
        KEY idx_created_at (created_at)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

/**
 * The code that runs during plugin deactivation.
 */
function ga4_server_side_tagging_deactivate() {
    // Clear scheduled cron jobs using cron manager
    $cron = new GA4_Server_Side_Tagging_Cron();
    $cron->clear_scheduled_jobs();
}

/**
 * Initialize cron job management
 */
function ga4_init_cron_management() {
    $cron = new GA4_Server_Side_Tagging_Cron();
    $cron->register_cron_jobs();
}
add_action( 'init', 'ga4_init_cron_management' );

/**
 * Begins execution of the plugin.
 */
function run_ga4_server_side_tagging() {
    $plugin = new GA4_Server_Side_Tagging();
    $plugin->run();
}

run_ga4_server_side_tagging(); 
