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

// Use the namespaced classes
use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging;

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
}

/**
 * The code that runs during plugin deactivation.
 */
function ga4_server_side_tagging_deactivate() {
    // Clean up if needed
}

/**
 * Begins execution of the plugin.
 */
function run_ga4_server_side_tagging() {
    $plugin = new GA4_Server_Side_Tagging();
    $plugin->run();
}

run_ga4_server_side_tagging(); 
