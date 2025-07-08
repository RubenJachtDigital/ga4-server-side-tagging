<?php

namespace GA4ServerSideTagging\Admin;

use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;

/**
 * The admin-specific functionality of the plugin.
 *
 * @since      1.0.0
 * @package    GA4_Server_Side_Tagging
 */

if (!defined('WPINC')) {
    die;
}

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two hooks for
 * enqueuing the admin-specific stylesheet and JavaScript.
 *
 * @since      1.0.0
 */
class GA4_Server_Side_Tagging_Admin
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
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    public function __construct(GA4_Server_Side_Tagging_Logger $logger)
    {
        $this->logger = $logger;
        
        // Initialize encryption salts if they don't exist
        $this->ensure_encryption_salts_exist();
    }

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_styles()
    {
        wp_enqueue_style(
            'ga4-server-side-tagging-admin',
            GA4_SERVER_SIDE_TAGGING_PLUGIN_URL . 'admin/css/ga4-server-side-tagging-admin.css',
            array(),
            GA4_SERVER_SIDE_TAGGING_VERSION,
            'all'
        );
    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            'ga4-server-side-tagging-admin',
            GA4_SERVER_SIDE_TAGGING_PLUGIN_URL . 'admin/js/ga4-server-side-tagging-admin.js',
            array('jquery'),
            GA4_SERVER_SIDE_TAGGING_VERSION,
            false
        );
        
        // Localize script for AJAX
        wp_localize_script(
            'ga4-server-side-tagging-admin',
            'ga4AdminAjax',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ga4_generate_api_key')
            )
        );
    }

    /**
     * Register the admin menu.
     *
     * @since    1.0.0
     */
    public function add_admin_menu()
    {
        add_menu_page(
            'GA4 Server-Side Tagging',
            'GA4 Tagging',
            'manage_options',
            'ga4-server-side-tagging',
            array($this, 'display_admin_page'),
            'dashicons-chart-line',
            100
        );

        // Add submenu for logs
        add_submenu_page(
            'ga4-server-side-tagging',
            'Debug Logs',
            'Debug Logs',
            'manage_options',
            'ga4-server-side-tagging-logs',
            array($this, 'display_logs_page')
        );
    }

    /**
     * Register plugin settings.
     *
     * @since    1.0.0
     */
    public function register_settings()
    {
        // GA4 Settings
        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_measurement_id',
            array(
                'type' => 'string',
                'description' => 'GA4 Measurement ID',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest' => false,
                'default' => '',
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_api_secret',
            array(
                'type' => 'string',
                'description' => 'GA4 API Secret',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest' => false,
                'default' => '',
            )
        );


        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_server_side_tagging_debug_mode',
            array(
                'type' => 'boolean',
                'description' => 'Enable debug mode',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => false,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_track_logged_in_users',
            array(
                'type' => 'boolean',
                'description' => 'Track logged-in users',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => true,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_ecommerce_tracking',
            array(
                'type' => 'boolean',
                'description' => 'Enable e-commerce tracking',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => true,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_cloudflare_worker_url',
            array(
                'type' => 'string',
                'description' => 'Cloudflare Worker URL',
                'sanitize_callback' => 'esc_url_raw',
                'show_in_rest' => false,
                'default' => '',
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_worker_api_key',
            array(
                'type' => 'string',
                'description' => 'Worker API Key',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest' => false,
                'default' => '',
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_jwt_encryption_enabled',
            array(
                'type' => 'boolean',
                'description' => 'Enable JWT encryption',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => false,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_simple_requests_enabled',
            array(
                'type' => 'boolean',
                'description' => 'Enable Simple requests for maximum performance',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => false,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_jwt_encryption_key',
            array(
                'type' => 'string',
                'description' => 'JWT Encryption Key',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest' => false,
                'default' => '',
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_yith_raq_form_id',
            array(
                'type' => 'string',
                'description' => 'YITH Request a Quote Form id',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest' => false,
                'default' => '',
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_conversion_form_ids',
            array(
                'type' => 'string',
                'description' => 'Conversion form id(s)',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest' => false,
                'default' => '',
            )
        );

        // GDPR Consent Settings
        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_use_iubenda',
            array(
                'type' => 'boolean',
                'description' => 'Use Iubenda for consent management',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => false,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_consent_accept_selector',
            array(
                'type' => 'string',
                'description' => 'CSS selector for accept all consent button',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest' => false,
                'default' => '.accept-all',
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_consent_deny_selector',
            array(
                'type' => 'string',
                'description' => 'CSS selector for deny all consent button',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest' => false,
                'default' => '.deny-all',
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_consent_default_timeout',
            array(
                'type' => 'integer',
                'description' => 'Default consent timeout in seconds',
                'sanitize_callback' => 'absint',
                'show_in_rest' => false,
                'default' => 30,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_consent_mode_enabled',
            array(
                'type' => 'boolean',
                'description' => 'Enable Google Consent Mode v2',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => true,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_consent_timeout_action',
            array(
                'type' => 'string',
                'description' => 'Action to take when consent timeout is reached',
                'sanitize_callback' => 'sanitize_text_field',
                'show_in_rest' => false,
                'default' => 'deny',
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_disable_all_ip',
            array(
                'type' => 'boolean',
                'description' => 'Disable all IP-based location tracking',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => false,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_storage_expiration_hours',
            array(
                'type' => 'integer',
                'description' => 'Storage expiration time in hours',
                'sanitize_callback' => array($this, 'sanitize_storage_expiration_hours'),
                'show_in_rest' => false,
                'default' => 24,
            )
        );

        // A/B Testing Settings
        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_ab_tests_enabled',
            array(
                'type' => 'boolean',
                'description' => 'Enable A/B testing functionality',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => false,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_ab_tests_config',
            array(
                'type' => 'string',
                'description' => 'A/B tests configuration JSON',
                'sanitize_callback' => array($this, 'sanitize_ab_tests_config'),
                'show_in_rest' => false,
                'default' => '[]',
            )
        );

        // Click Tracking Settings
        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_click_tracks_enabled',
            array(
                'type' => 'boolean',
                'description' => 'Enable click tracking functionality',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => false,
            )
        );

        register_setting(
            'ga4_server_side_tagging_settings',
            'ga4_click_tracks_config',
            array(
                'type' => 'string',
                'description' => 'Click tracks configuration JSON',
                'sanitize_callback' => array($this, 'sanitize_click_tracks_config'),
                'show_in_rest' => false,
                'default' => '[]',
            )
        );

    }

    /**
     * Sanitize checkbox value.
     *
     * @since    1.0.0
     * @param    mixed    $input    The input value.
     * @return   bool                The sanitized value.
     */
    public function sanitize_checkbox($input)
    {
        return (bool) $input;
    }

    /**
     * Sanitize storage expiration hours.
     *
     * @since    1.0.0
     * @param    mixed    $input    The input value.
     * @return   int                 The sanitized value (1-8760 hours).
     */
    public function sanitize_storage_expiration_hours($input)
    {
        $hours = absint($input);
        // Ensure value is between 1 hour and 8760 hours (1 year)
        if ($hours < 1) {
            $hours = 1;
        } elseif ($hours > 8760) {
            $hours = 8760;
        }
        return $hours;
    }

    /**
     * Sanitize Click tracks configuration.
     *
     * @since    1.0.0
     * @param    mixed    $input    The input value.
     * @return   string              The sanitized value.
     */
    public function sanitize_click_tracks_config($input)
    {
        if (empty($input)) {
            return '[]';
        }

        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            add_settings_error(
                'ga4_click_tracks_config',
                'invalid_json',
                'Invalid JSON in click tracks configuration.'
            );
            return '[]';
        }

        if (!is_array($decoded)) {
            return '[]';
        }

        $sanitized = array();
        foreach ($decoded as $track) {
            if (is_array($track) && isset($track['name']) && isset($track['selector'])) {
                $sanitized_track = array(
                    'name' => sanitize_text_field($track['name']),
                    'selector' => sanitize_text_field($track['selector']),
                    'enabled' => isset($track['enabled']) ? (bool) $track['enabled'] : true,
                );
                
                // Validate event name
                if (!empty($sanitized_track['name']) && !empty($sanitized_track['selector'])) {
                    // Basic validation for event name (will be further validated in JS)
                    $clean_name = preg_replace('/[^a-zA-Z0-9_]/', '_', $sanitized_track['name']);
                    if (!empty($clean_name)) {
                        $sanitized[] = $sanitized_track;
                    }
                }
            }
        }

        return json_encode($sanitized);
    }

    /**
     * Sanitize A/B tests configuration.
     *
     * @since    1.0.0
     * @param    mixed    $input    The input value.
     * @return   string              The sanitized JSON string.
     */
    public function sanitize_ab_tests_config($input)
    {
        if (empty($input)) {
            return '[]';
        }

        // Decode JSON to validate structure
        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return '[]';
        }

        // Validate each test configuration
        $sanitized_tests = array();
        if (is_array($decoded)) {
            foreach ($decoded as $test) {
                if (isset($test['name']) && isset($test['class_a']) && isset($test['class_b'])) {
                    $sanitized_tests[] = array(
                        'name' => sanitize_text_field($test['name']),
                        'class_a' => sanitize_text_field($test['class_a']),
                        'class_b' => sanitize_text_field($test['class_b']),
                        'enabled' => isset($test['enabled']) ? (bool) $test['enabled'] : true,
                    );
                }
            }
        }

        return json_encode($sanitized_tests);
    }



    /**
     * Display the plugin admin page.
     *
     * @since    1.0.0
     */
    public function display_admin_page()
    {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }

        // Save settings if form is submitted
        if (isset($_POST['ga4_server_side_tagging_settings_submit']) && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ga4_server_side_tagging_settings')) {
            $this->save_settings();
        }

        // Test connection if requested
        $test_result = null;
        if (isset($_POST['ga4_test_connection']) && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ga4_server_side_tagging_settings')) {
            $test_result = $this->test_ga4_connection();
        }

        // Ensure existing plain text keys are encrypted when loading admin page
        $this->ensure_api_key_is_encrypted();
        $this->ensure_encryption_key_is_encrypted();

        // Get current settings
        $measurement_id = get_option('ga4_measurement_id', '');
        $api_secret = get_option('ga4_api_secret', '');
        $debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);
        $track_logged_in_users = get_option('ga4_track_logged_in_users', true);
        $ecommerce_tracking = get_option('ga4_ecommerce_tracking', true);
        $cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');
        
        // Retrieve API key with decryption
        $worker_api_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_worker_api_key');
        
        // Generate API key if not set
        if (empty($worker_api_key)) {
            $worker_api_key = $this->generate_api_key();
            \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::store_encrypted_key($worker_api_key, 'ga4_worker_api_key');
            $this->logger->info('New API key generated and stored');
        }

        // JWT Encryption settings
        $jwt_encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
        $jwt_encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
        
        // Simple requests settings
        $simple_requests_enabled = get_option('ga4_simple_requests_enabled', false);
        
        $yith_raq_form_id = get_option('ga4_yith_raq_form_id', '');
        $conversion_form_ids = get_option('ga4_conversion_form_ids', '');
        
        // GDPR Consent settings
        $use_iubenda = get_option('ga4_use_iubenda', false);
        $consent_accept_selector = get_option('ga4_consent_accept_selector', '.accept-all');
        $consent_deny_selector = get_option('ga4_consent_deny_selector', '.deny-all');
        $consent_default_timeout = get_option('ga4_consent_default_timeout', 30);
        $consent_mode_enabled = get_option('ga4_consent_mode_enabled', true);
        $consent_timeout_action = get_option('ga4_consent_timeout_action', 'deny');
        $disable_all_ip = get_option('ga4_disable_all_ip', false);
        $storage_expiration_hours = get_option('ga4_storage_expiration_hours', 24);
        $consent_expiration_days = get_option('ga4_consent_expiration_days', 30);

        // A/B Testing settings
        $ab_tests_enabled = get_option('ga4_ab_tests_enabled', false);
        $ab_tests_config = get_option('ga4_ab_tests_config', '[]');
        $ab_tests_array = json_decode($ab_tests_config, true);
        if (!is_array($ab_tests_array)) {
            $ab_tests_array = array();
        }

        // Click Tracking settings
        $click_tracks_enabled = get_option('ga4_click_tracks_enabled', false);
        $click_tracks_config = get_option('ga4_click_tracks_config', '[]');
        $click_tracks_array = json_decode($click_tracks_config, true);
        if (!is_array($click_tracks_array)) {
            $click_tracks_array = array();
        }

        // Include the admin view
        include GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'admin/partials/ga4-server-side-tagging-admin-display.php';
    }

    /**
     * Display the plugin logs page.
     *
     * @since    1.0.0
     */
    public function display_plugin_logs_page()
    {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }

        // Clear logs if requested
        if (isset($_POST['ga4_clear_logs']) && isset($_POST['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'])), 'ga4_server_side_tagging_logs')) {
            $this->clear_logs();
        }

        // Get log file content
        $log_file = GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'logs/ga4-server-side-tagging.log';
        $log_content = '';

        if (file_exists($log_file)) {
            // Clean up old log entries (older than 14 days) before displaying
            $this->cleanup_old_logs($log_file, 14);
            
            // Read all lines and reverse order to show newest first
            $log_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($log_lines) {
                $log_lines = array_reverse($log_lines);
                $log_content = implode("\n", $log_lines);
            }
        }

        // Include the logs view
        include GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'admin/partials/ga4-server-side-tagging-admin-logs.php';
    }

    /**
     * Save plugin settings.
     *
     * @since    1.0.0
     */
    private function save_settings()
    {
        // Sanitize and save each GA4 setting
        if (isset($_POST['ga4_measurement_id'])) {
            update_option('ga4_measurement_id', sanitize_text_field(wp_unslash($_POST['ga4_measurement_id'])));
        }

        if (isset($_POST['ga4_api_secret'])) {
            update_option('ga4_api_secret', sanitize_text_field(wp_unslash($_POST['ga4_api_secret'])));
        }

        if (isset($_POST['ga4_cloudflare_worker_url'])) {
            update_option('ga4_cloudflare_worker_url', esc_url_raw(wp_unslash($_POST['ga4_cloudflare_worker_url'])));
        }

        // Handle API key with encryption
        if (isset($_POST['ga4_worker_api_key'])) {
            $api_key = sanitize_text_field(wp_unslash($_POST['ga4_worker_api_key']));
            if (!empty($api_key)) {
                // Store API key using encryption utility
                \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::store_encrypted_key($api_key, 'ga4_worker_api_key');
            } else {
                // Clear the key if empty
                update_option('ga4_worker_api_key', '');
            }
        }

        if (isset($_POST['ga4_jwt_encryption_enabled'])) {
            update_option('ga4_jwt_encryption_enabled', (bool) $_POST['ga4_jwt_encryption_enabled']);
        } else {
            update_option('ga4_jwt_encryption_enabled', false);
        }

        if (isset($_POST['ga4_jwt_encryption_key'])) {
            $encryption_key = sanitize_text_field(wp_unslash($_POST['ga4_jwt_encryption_key']));
            // Basic validation for encryption key format
            if (empty($encryption_key) || (strlen($encryption_key) === 64 && ctype_xdigit($encryption_key))) {
                if (!empty($encryption_key)) {
                    // Store encryption key using encryption utility
                    \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::store_encrypted_key($encryption_key, 'ga4_jwt_encryption_key');
                } else {
                    // Clear the key if empty
                    update_option('ga4_jwt_encryption_key', '');
                }
            } else {
                add_settings_error(
                    'ga4_server_side_tagging_settings',
                    'invalid_encryption_key',
                    'Invalid encryption key format. Must be 64 hexadecimal characters (256-bit key) or empty.',
                    'error'
                );
            }
        }

        if (isset($_POST['ga4_yith_raq_form_id'])) {
            update_option('ga4_yith_raq_form_id', sanitize_text_field(wp_unslash($_POST['ga4_yith_raq_form_id'])));
        }

        if (isset($_POST['ga4_conversion_form_ids'])) {
            update_option('ga4_conversion_form_ids', sanitize_text_field(wp_unslash($_POST['ga4_conversion_form_ids'])));
        }

        // GA4 Checkbox options
        update_option('ga4_server_side_tagging_debug_mode', isset($_POST['ga4_server_side_tagging_debug_mode']));
        update_option('ga4_track_logged_in_users', isset($_POST['ga4_track_logged_in_users']));
        update_option('ga4_ecommerce_tracking', isset($_POST['ga4_ecommerce_tracking']));
        update_option('ga4_simple_requests_enabled', isset($_POST['ga4_simple_requests_enabled']));

        // GDPR Consent settings
        update_option('ga4_use_iubenda', isset($_POST['ga4_use_iubenda']));
        update_option('ga4_consent_mode_enabled', isset($_POST['ga4_consent_mode_enabled']));

        if (isset($_POST['ga4_consent_accept_selector'])) {
            update_option('ga4_consent_accept_selector', sanitize_text_field(wp_unslash($_POST['ga4_consent_accept_selector'])));
        }

        if (isset($_POST['ga4_consent_deny_selector'])) {
            update_option('ga4_consent_deny_selector', sanitize_text_field(wp_unslash($_POST['ga4_consent_deny_selector'])));
        }

        if (isset($_POST['ga4_consent_default_timeout'])) {
            $timeout = absint($_POST['ga4_consent_default_timeout']);
            // Limit timeout to reasonable values (0-300 seconds)
            $timeout = min(300, max(0, $timeout));
            update_option('ga4_consent_default_timeout', $timeout);
        }

        if (isset($_POST['ga4_consent_timeout_action'])) {
            $timeout_action = sanitize_text_field(wp_unslash($_POST['ga4_consent_timeout_action']));
            // Validate the timeout action value
            if (in_array($timeout_action, ['accept', 'deny'])) {
                update_option('ga4_consent_timeout_action', $timeout_action);
            }
        }

        // Process new IP and storage settings
        update_option('ga4_disable_all_ip', isset($_POST['ga4_disable_all_ip']));

        if (isset($_POST['ga4_storage_expiration_hours'])) {
            $hours = absint($_POST['ga4_storage_expiration_hours']);
            // Use the existing sanitize method for validation (1-8760 hours)
            $hours = $this->sanitize_storage_expiration_hours($hours);
            update_option('ga4_storage_expiration_hours', $hours);
        }

        // A/B Testing settings
        update_option('ga4_ab_tests_enabled', isset($_POST['ga4_ab_tests_enabled']));
        
        // Process A/B tests configuration from the hidden field
        if (isset($_POST['ga4_ab_tests_config'])) {
            $ab_tests_config = wp_unslash($_POST['ga4_ab_tests_config']);
            update_option('ga4_ab_tests_config', $this->sanitize_ab_tests_config($ab_tests_config));
        } else {
            // If no config provided, save empty array
            update_option('ga4_ab_tests_config', '[]');
        }

        // Click Tracking settings
        update_option('ga4_click_tracks_enabled', isset($_POST['ga4_click_tracks_enabled']));
        
        // Process Click tracks configuration from the hidden field
        if (isset($_POST['ga4_click_tracks_config'])) {
            $click_tracks_config = wp_unslash($_POST['ga4_click_tracks_config']);
            update_option('ga4_click_tracks_config', $this->sanitize_click_tracks_config($click_tracks_config));
        } else {
            // If no config provided, save empty array
            update_option('ga4_click_tracks_config', '[]');
        }

        // Update logger debug mode
        $this->logger->set_debug_mode(isset($_POST['ga4_server_side_tagging_debug_mode']));

        // Ensure existing plain text keys are encrypted after settings save
        $this->ensure_api_key_is_encrypted();
        $this->ensure_encryption_key_is_encrypted();

        // Log settings update
        $this->logger->info('Plugin settings updated');

        // Add admin notice
        add_settings_error(
            'ga4_server_side_tagging_settings',
            'settings_updated',
            'Settings saved successfully.',
            'updated'
        );
    }

    /**
     * Test GA4 connection.
     *
     * @since    1.0.0
     * @return   array    The test result.
     */
    private function test_ga4_connection()
    {
        $measurement_id = get_option('ga4_measurement_id', '');
        $api_secret = get_option('ga4_api_secret', '');

        if (empty($measurement_id) || empty($api_secret)) {
            return array(
                'success' => false,
                'message' => 'Missing measurement ID or API secret',
            );
        }

        // Prepare test event
        $client_id = sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );

        $payload = array(
            'client_id' => $client_id,
            'events' => array(
                array(
                    'name' => 'test_event',
                    'params' => array(
                        'test_param' => 'test_value',
                    ),
                ),
            ),
        );

        // Send to GA4 Measurement Protocol
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . $measurement_id . '&api_secret=' . $api_secret;

        $response = wp_remote_post($url, array(
            'method' => 'POST',
            'timeout' => 5,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
            'cookies' => array(),
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Error: ' . $response->get_error_message(),
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);

        if ($response_code < 200 || $response_code >= 300) {
            $body = wp_remote_retrieve_body($response);
            return array(
                'success' => false,
                'message' => 'GA4 API error: ' . $response_code . ' ' . $body,
            );
        }

        // Test Cloudflare worker if URL is provided
        $cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');
        $cloudflare_result = array(
            'tested' => false,
            'success' => false,
            'message' => 'Cloudflare Worker URL not configured',
        );

        if (!empty($cloudflare_worker_url)) {
            // Format payload specifically for Cloudflare Worker
            $cloudflare_payload = array(
                'name' => 'test_event',
                'params' => array(
                    'test_param' => 'test_value',
                    'client_id' => $client_id
                )
            );

            // Get API key for worker authentication (with decryption)
            $worker_api_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_worker_api_key');
            $headers = array(
                'Content-Type' => 'application/json',
                'Origin' => site_url(),
                'Referer' => site_url(),
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . ' GA4-Server-Side-Tagging Connection Test'
            );
            
            if (!empty($worker_api_key)) {
                $headers['Authorization'] = 'Bearer ' . $worker_api_key;
            }

            // Handle JWT encryption if enabled
            $jwt_encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
            $jwt_encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
            $request_body = wp_json_encode($cloudflare_payload);
            
            if ($jwt_encryption_enabled && !empty($jwt_encryption_key)) {
                try {
                    $encrypted_data = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::encrypt($request_body, $jwt_encryption_key);
                    if ($encrypted_data !== false) {
                        $request_body = wp_json_encode(array('jwt' => $encrypted_data));
                        $headers['X-Encrypted'] = 'true';
                    }
                } catch (\Exception $e) {
                    // Continue with unencrypted payload if encryption fails
                }
            }

            $cloudflare_response = wp_remote_post($cloudflare_worker_url, array(
                'method' => 'POST',
                'timeout' => 5,
                'redirection' => 5,
                'httpversion' => '1.1',
                'blocking' => true,
                'headers' => $headers,
                'body' => $request_body,
                'cookies' => array(),
            ));

            $cloudflare_result['tested'] = true;

            if (is_wp_error($cloudflare_response)) {
                $cloudflare_result['message'] = 'Error: ' . $cloudflare_response->get_error_message();
            } else {
                $cloudflare_response_code = wp_remote_retrieve_response_code($cloudflare_response);

                if ($cloudflare_response_code < 200 || $cloudflare_response_code >= 300) {
                    $cloudflare_body = wp_remote_retrieve_body($cloudflare_response);
                    $cloudflare_result['message'] = 'Cloudflare Worker error: ' . $cloudflare_response_code . ' ' . $cloudflare_body;
                } else {
                    $cloudflare_result['success'] = true;
                    $cloudflare_result['message'] = 'Cloudflare Worker connection successful';
                }
            }
        }

        return array(
            'success' => true,
            'message' => 'GA4 connection successful',
            'cloudflare' => $cloudflare_result,
        );
    }

    /**
     * Clear logs.
     *
     * @since    1.0.0
     */
    private function clear_logs()
    {
        $log_file = GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'logs/ga4-server-side-tagging.log';

        if (file_exists($log_file)) {
            file_put_contents($log_file, '');
        }

        // Add admin notice
        add_settings_error(
            'ga4_server_side_tagging_logs',
            'logs_cleared',
            'Logs cleared successfully.',
            'updated'
        );
    }

    /**
     * Clean up old log entries older than specified days.
     *
     * @since    1.0.0
     * @param    string    $log_file    Path to the log file.
     * @param    int       $days        Number of days to keep logs.
     */
    private function cleanup_old_logs($log_file, $days = 14)
    {
        if (!file_exists($log_file)) {
            return;
        }

        // Calculate cutoff timestamp (X days ago)
        $cutoff_timestamp = time() - ($days * 24 * 60 * 60);
        
        // Read all log lines
        $log_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$log_lines) {
            return;
        }

        $filtered_lines = array();
        $cleaned_count = 0;

        foreach ($log_lines as $line) {
            // Extract timestamp from log line format: [2025-01-05 13:45:32]
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                $log_timestamp = strtotime($matches[1]);
                
                // Keep lines newer than cutoff
                if ($log_timestamp >= $cutoff_timestamp) {
                    $filtered_lines[] = $line;
                } else {
                    $cleaned_count++;
                }
            } else {
                // Keep lines without valid timestamp (just in case)
                $filtered_lines[] = $line;
            }
        }

        // Only rewrite file if we removed some entries
        if ($cleaned_count > 0) {
            file_put_contents($log_file, implode("\n", $filtered_lines) . "\n");
            
            // Log the cleanup action
            if ($this->logger) {
                $this->logger->info("Cleaned up {$cleaned_count} log entries older than {$days} days");
            }
        }
    }

    /**
     * Display the debug logs page.
     *
     * @since    1.0.0
     */
    public function display_logs_page()
    {
        // Handle log actions
        if (isset($_POST['action']) && isset($_POST['_wpnonce']) && wp_verify_nonce($_POST['_wpnonce'], 'ga4_logs_action')) {
            if ($_POST['action'] === 'clear_logs') {
                $this->logger->clear_log();
                add_settings_error('ga4_logs', 'logs_cleared', 'Debug logs have been cleared.', 'success');
            } elseif ($_POST['action'] === 'toggle_debug_mode') {
                $debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);
                update_option('ga4_server_side_tagging_debug_mode', !$debug_mode);
                $status = !$debug_mode ? 'enabled' : 'disabled';
                add_settings_error('ga4_logs', 'debug_mode_toggled', 'Debug mode has been ' . $status . '.', 'success');
            }
        }

        // Get log file content
        $log_file = $this->logger->get_log_file_path();
        $log_content = '';

        if (file_exists($log_file)) {
            // Clean up old log entries (older than 14 days) before displaying
            $this->cleanup_old_logs($log_file, 14);
            
            // Get the last 500 lines (or fewer if file is smaller) and reverse to show newest first
            $log_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($log_lines) {
                $log_lines = array_slice($log_lines, max(0, count($log_lines) - 500));
                $log_lines = array_reverse($log_lines);
                $log_content = implode("\n", $log_lines);
            }
        }

        // Check if debug mode is enabled
        $debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);

        ?>
        <div class="wrap">
            <h1>GA4 Server-Side Tagging - Debug Logs</h1>

            <?php settings_errors('ga4_logs'); ?>

            <div class="notice notice-info">
                <p>
                    Debug mode is currently <strong><?php echo $debug_mode ? 'enabled' : 'disabled'; ?></strong>.
                    <?php if (!$debug_mode): ?>
                        Enable debug mode to start logging events.
                    <?php endif; ?>
                </p>
            </div>

            <div style="margin-bottom: 20px;">
                <form method="post" style="display: inline-block; margin-right: 10px;">
                    <?php wp_nonce_field('ga4_logs_action'); ?>
                    <input type="hidden" name="action" value="toggle_debug_mode">
                    <button type="submit" class="button button-secondary">
                        <?php echo $debug_mode ? 'Disable Debug Mode' : 'Enable Debug Mode'; ?>
                    </button>
                </form>

                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('ga4_logs_action'); ?>
                    <input type="hidden" name="action" value="clear_logs">
                    <button type="submit" class="button button-secondary"
                        onclick="return confirm('Are you sure you want to clear the logs?');">
                        Clear Logs
                    </button>
                </form>
            </div>

            <div class="card">
                <h2>Debug Log</h2>
                <?php if (empty($log_content)): ?>
                    <p>No log entries found. <?php echo !$debug_mode ? 'Enable debug mode to start logging.' : ''; ?></p>
                <?php else: ?>
                    <div class="ga4-log-info" style="margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                            <div>
                                <strong>📋 Showing:</strong> Last 500 entries (newest first) 
                                <span style="color: #666;">• Auto-cleanup: Entries older than 14 days removed</span>
                            </div>
                            <div>
                                <strong>🕐 Reference Time:</strong> 
                                <span id="dashboard-reference-time" style="font-family: 'Courier New', monospace; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; border: 1px solid #c3c4c7;">
                                    <?php echo esc_html( current_time( 'Y-m-d H:i:s T' ) ); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="ga4-server-side-tagging-log-viewer">
                        <pre style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; border-radius: 4px; font-family: 'Courier New', monospace; font-size: 13px; line-height: 1.4; max-height: 600px; overflow-y: auto; margin: 0; white-space: pre-wrap; word-wrap: break-word;"><?php echo esc_html($log_content); ?></pre>
                    </div>
                <?php endif; ?>
            </div>

            <div class="card" style="margin-top: 20px;">
                <h2>Debugging Tips</h2>
                <ul style="list-style-type: disc; margin-left: 20px;">
                    <li>Enable debug mode to log events.</li>
                    <li>Check for "add_to_cart" events in the logs when adding products to cart.</li>
                    <li>Look for any error messages that might indicate configuration issues.</li>
                    <li>Verify that your Measurement ID and API Secret are correctly configured.</li>
                    <li>If using a Cloudflare Worker, check the Cloudflare logs for additional information.</li>
                </ul>
            </div>

        </div>

        <script>
        function updateDashboardTime() {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            
            // Get timezone abbreviation
            const timezone = Intl.DateTimeFormat('en', {timeZoneName: 'short'}).formatToParts(now)
                .find(part => part.type === 'timeZoneName').value;
            
            const timeString = `${year}-${month}-${day} ${hours}:${minutes}:${seconds} ${timezone}`;
            
            const dashboardElement = document.getElementById('dashboard-reference-time');
            if (dashboardElement) {
                dashboardElement.textContent = timeString;
            }
        }

        // Update dashboard time immediately and then every second
        updateDashboardTime();
        setInterval(updateDashboardTime, 1000);
        </script>
        <?php
    }

    /**
     * Generate a secure random API key
     *
     * @return string Generated API key
     */
    private function generate_api_key()
    {
        // Generate a secure 32-character API key
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(16));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(16));
        } else {
            // Fallback for older PHP versions
            return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 32);
        }
    }

    /**
     * Generate a secure random encryption key (256-bit)
     *
     * @return string Generated encryption key (64 hex characters)
     */
    private function generate_encryption_key()
    {
        // Generate a secure 256-bit encryption key (64 hex characters)
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(32));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            return bin2hex(openssl_random_pseudo_bytes(32));
        } else {
            // Fallback for older PHP versions
            return substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 64);
        }
    }

    /**
     * AJAX handler for generating a new API key
     */
    public function ajax_generate_api_key()
    {
        // Check nonce for security
        if (!check_ajax_referer('ga4_generate_api_key', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        try {
            // Generate new API key
            $new_api_key = $this->generate_api_key();
            
            // Save it to options with encryption
            \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::store_encrypted_key($new_api_key, 'ga4_worker_api_key');
            $this->logger->info('New API key generated and stored successfully via AJAX');
            
            // Return success response
            wp_send_json_success(array(
                'api_key' => $new_api_key,
                'message' => 'New API key generated successfully!'
            ));
        } catch (\Exception $e) {
            $this->logger->error('Error generating API key via AJAX: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error generating API key: ' . $e->getMessage()));
        }
    }

    /**
     * AJAX handler for generating a new encryption key
     */
    public function ajax_generate_encryption_key()
    {
        // Check nonce for security
        if (!check_ajax_referer('ga4_generate_api_key', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        // Check user permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }

        try {
            // Generate new encryption key (256-bit = 64 hex characters)
            $new_encryption_key = $this->generate_encryption_key();
            
            // Save it to options with encryption
            \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::store_encrypted_key($new_encryption_key, 'ga4_jwt_encryption_key');
            
            // Return success response
            wp_send_json_success(array(
                'encryption_key' => $new_encryption_key,
                'message' => 'New encryption key generated successfully!'
            ));
        } catch (\Exception $e) {
            wp_send_json_error(array('message' => 'Error generating encryption key: ' . $e->getMessage()));
        }
    }

    /**
     * Ensure any existing plain text encryption key gets encrypted
     * This runs automatically on settings save to upgrade plain text keys
     */
    private function ensure_encryption_key_is_encrypted()
    {
        try {
            // Get the raw option value (not through the utility which auto-decrypts)
            $raw_key = get_option('ga4_jwt_encryption_key', '');
            
            if (empty($raw_key)) {
                return; // No key to encrypt
            }
            
            // Check if it's already encrypted (encrypted keys are base64 JSON structures)
            if (!ctype_xdigit($raw_key) || strlen($raw_key) !== 64) {
                return; // Not a plain text hex key, likely already encrypted or invalid
            }
            
            // Validate it's a proper 64-character hex key
            if (\GA4ServerSideTagging\Utilities\GA4_Encryption_Util::validate_encryption_key($raw_key)) {
                // This is a plain text key, encrypt it
                $stored = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::store_encrypted_key($raw_key, 'ga4_jwt_encryption_key');
                
                if ($stored) {
                    $this->logger->info('Encryption key automatically upgraded from plain text to encrypted storage');
                } else {
                    $this->logger->warning('Failed to automatically encrypt existing plain text encryption key');
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error during automatic encryption key upgrade: ' . $e->getMessage());
        }
    }

    /**
     * Ensure API key is encrypted in storage
     * 
     * This runs automatically on settings save to upgrade plain text API keys
     * to encrypted storage using WordPress salts for enhanced security.
     * 
     * @since 1.0.0
     * @return void
     */
    private function ensure_api_key_is_encrypted()
    {
        try {
            $raw_key = get_option('ga4_worker_api_key', '');
            
            // Check if key exists and needs encryption
            if (!empty($raw_key)) {
                // Try to decrypt - if this succeeds and returns a different value, it's already encrypted
                $decrypted_test = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_worker_api_key');
                
                // If decrypt fails (returns false), it's likely plain text
                // If decrypt succeeds but returns the same value, it's also plain text
                if ($decrypted_test === false) {
                    // This is a plain text key, encrypt it
                    $stored = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::store_encrypted_key($raw_key, 'ga4_worker_api_key');
                    
                    if ($stored) {
                        $this->logger->info('Worker API key automatically upgraded from plain text to encrypted storage');
                    } else {
                        $this->logger->warning('Failed to automatically encrypt existing plain text worker API key');
                    }
                } elseif ($decrypted_test === $raw_key) {
                    // Key is stored as plain text, but we were able to "decrypt" it (meaning it's actually plain text)
                    $stored = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::store_encrypted_key($raw_key, 'ga4_worker_api_key');
                    
                    if ($stored) {
                        $this->logger->info('Worker API key automatically upgraded from plain text to encrypted storage');
                    } else {
                        $this->logger->warning('Failed to automatically encrypt existing plain text worker API key');
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Error during automatic API key upgrade: ' . $e->getMessage());
        }
    }

    /**
     * Ensure WordPress encryption salts exist for key storage
     * 
     * Initializes the required WordPress options for encryption key storage
     * if they don't already exist.
     * 
     * @since 1.0.0
     * @return void
     */
    private function ensure_encryption_salts_exist()
    {
        // Check if time-based salt exists, if not create it
        if (empty(get_option('ga4_time_based_salt', ''))) {
            $salt = bin2hex(random_bytes(32)); // 64-character hex string
            update_option('ga4_time_based_salt', $salt);
            $this->logger->info('Created WordPress encryption salt for secure key storage');
        }

        // Check if time-based auth key exists, if not create it  
        if (empty(get_option('ga4_time_based_auth_key', ''))) {
            $auth_key = bin2hex(random_bytes(32)); // 64-character hex string
            update_option('ga4_time_based_auth_key', $auth_key);
            $this->logger->info('Created WordPress encryption auth key for secure key storage');
        }
    }
}