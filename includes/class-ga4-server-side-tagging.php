<?php

namespace GA4ServerSideTagging\Core;

use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Loader;
use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;
use GA4ServerSideTagging\Admin\GA4_Server_Side_Tagging_Admin;
use GA4ServerSideTagging\Frontend\GA4_Server_Side_Tagging_Public;
use GA4ServerSideTagging\API\GA4_Server_Side_Tagging_Endpoint;
use GA4ServerSideTagging\Core\GA4_Cronjob_Manager;
use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Cron;

/**
 * The core plugin class.
 *
 * @since      1.0.0
 * @package    GA4_Server_Side_Tagging
 */

if (!defined('WPINC')) {
    die;
}

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * @since      1.0.0
 */
class GA4_Server_Side_Tagging
{

    /**
     * The loader that's responsible for maintaining and registering all hooks that power
     * the plugin.
     *
     * @since    1.0.0
     * @access   protected
     * @var      GA4_Server_Side_Tagging_Loader    $loader    Maintains and registers all hooks for the plugin.
     */
    protected $loader;

    /**
     * The logger instance for debugging.
     *
     * @since    1.0.0
     * @access   protected
     * @var      GA4_Server_Side_Tagging_Logger    $logger    Handles logging for the plugin.
     */
    protected $logger;

    /**
     * The cronjob manager instance.
     *
     * @since    2.0.0
     * @access   protected
     * @var      GA4_Cronjob_Manager    $cronjob_manager    Handles event queue and cronjob processing.
     */
    protected $cronjob_manager;

    /**
     * The cron handler instance.
     *
     * @since    2.0.0
     * @access   protected
     * @var      GA4_Server_Side_Tagging_Cron    $cron_handler    Handles cron scheduling and execution.
     */
    protected $cron_handler;

    /**
     * Define the core functionality of the plugin.
     *
     * @since    1.0.0
     */
    public function __construct()
    {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
        $this->define_cronjob_hooks();
    }


    /**
     * Load the required dependencies for this plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function load_dependencies()
    {
        $this->loader = new GA4_Server_Side_Tagging_Loader();
        $this->logger = new GA4_Server_Side_Tagging_Logger();

        // Admin classes
        require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'admin/class-ga4-server-side-tagging-admin.php';

        // Public classes
        require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'public/class-ga4-server-side-tagging-public.php';

        // API endpoint for server-side tagging
        require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-server-side-tagging-endpoint.php';
        
        // Encryption utilities
        require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-encryption-util.php';
        
        // Cronjob manager
        require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-cronjob-manager.php';
        
        // Cron handler
        require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-server-side-tagging-cron.php';
        
        // Event logger
        require_once GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'includes/class-ga4-event-logger.php';
    }

    /**
     * Register all of the hooks related to the admin area functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_admin_hooks()
    {
        $plugin_admin = new GA4_Server_Side_Tagging_Admin($this->logger);

        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_styles');
        $this->loader->add_action('admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts');
        $this->loader->add_action('admin_menu', $plugin_admin, 'add_admin_menu');
        $this->loader->add_action('admin_init', $plugin_admin, 'register_settings');

        // Add AJAX handler for generating encryption key
        $this->loader->add_action('wp_ajax_ga4_generate_encryption_key', $plugin_admin, 'ajax_generate_encryption_key');

        // Add admin notice for WP-Cron warning
        $this->loader->add_action('admin_notices', $plugin_admin, 'display_wp_cron_warning_notice');
    }

    /**
     * Register all of the hooks related to the public-facing functionality
     * of the plugin.
     *
     * @since    1.0.0
     * @access   private
     */
    private function define_public_hooks()
    {
        $plugin_public = new GA4_Server_Side_Tagging_Public($this->logger);

        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');

        // Register REST API endpoint
        $plugin_endpoint = new GA4_Server_Side_Tagging_Endpoint($this->logger);
        $this->loader->add_action('rest_api_init', $plugin_endpoint, 'register_routes');

        // Add AJAX handlers for nonce refresh (both logged in and non-logged in users)
        $this->loader->add_action('wp_ajax_ga4_refresh_nonce', $plugin_public, 'ajax_refresh_nonce');
        $this->loader->add_action('wp_ajax_nopriv_ga4_refresh_nonce', $plugin_public, 'ajax_refresh_nonce');
    }

    /**
     * Register all of the hooks related to the cronjob functionality.
     *
     * @since    2.0.0
     * @access   private
     */
    private function define_cronjob_hooks()
    {
        $this->cronjob_manager = new GA4_Cronjob_Manager($this->logger);
        $this->cron_handler = new GA4_Server_Side_Tagging_Cron($this->logger);

        // Register cron jobs and schedules
        $this->cron_handler->register_cron_jobs();
        
        // Add custom cron schedule
        $this->loader->add_filter('cron_schedules', $this->cron_handler, 'add_cron_intervals');
        
        // Ensure cron jobs are scheduled (in case activation was missed)
        add_action('init', array($this->cron_handler, 'maybe_schedule_crons'), 10);
    }


    /**
     * Run the loader to execute all of the hooks with WordPress.
     *
     * @since    1.0.0
     */
    public function run()
    {
        $this->loader->run();
    }

    /**
     * Get the cronjob manager instance.
     *
     * @since    2.0.0
     * @return   GA4_Cronjob_Manager    The cronjob manager instance.
     */
    public function get_cronjob_manager()
    {
        return $this->cronjob_manager;
    }
}
