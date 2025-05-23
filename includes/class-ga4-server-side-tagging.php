<?php

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
     * Define the core functionality of the plugin.
     *
     * @since    1.0.0
     */
    public function __construct()
    {
        $this->load_dependencies();
        $this->define_admin_hooks();
        $this->define_public_hooks();
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

        // Add AJAX handler for test event
        $this->loader->add_action('wp_ajax_ga4_test_event', $plugin_admin, 'handle_test_event');
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
        $use_server_side = get_option('ga4_use_server_side', true);

        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_styles');
        $this->loader->add_action('wp_enqueue_scripts', $plugin_public, 'enqueue_scripts');
        $this->loader->add_action('wp_head', $plugin_public, 'add_ga4_tracking_code');

        // Register REST API endpoint
        $plugin_endpoint = new GA4_Server_Side_Tagging_Endpoint($this->logger);
        $this->loader->add_action('rest_api_init', $plugin_endpoint, 'register_routes');
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
}
