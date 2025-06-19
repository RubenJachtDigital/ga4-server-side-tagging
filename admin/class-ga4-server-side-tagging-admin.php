<?php
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
    public function __construct($logger)
    {
        $this->logger = $logger;
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
            'ga4_use_server_side',
            array(
                'type' => 'boolean',
                'description' => 'Use server-side tagging',
                'sanitize_callback' => array($this, 'sanitize_checkbox'),
                'show_in_rest' => false,
                'default' => true,
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

        // Get current settings
        $measurement_id = get_option('ga4_measurement_id', '');
        $api_secret = get_option('ga4_api_secret', '');
        $use_server_side = get_option('ga4_use_server_side', true);
        $debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);
        $track_logged_in_users = get_option('ga4_track_logged_in_users', true);
        $ecommerce_tracking = get_option('ga4_ecommerce_tracking', true);
        $cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');
        $yith_raq_form_id = get_option('ga4_yith_raq_form_id', '');
        $conversion_form_ids = get_option('ga4_conversion_form_ids', '');
        
        // GDPR Consent settings
        $use_iubenda = get_option('ga4_use_iubenda', false);
        $consent_accept_selector = get_option('ga4_consent_accept_selector', '.accept-all');
        $consent_deny_selector = get_option('ga4_consent_deny_selector', '.deny-all');
        $consent_default_timeout = get_option('ga4_consent_default_timeout', 30);
        $consent_mode_enabled = get_option('ga4_consent_mode_enabled', true);

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
            $log_content = file_get_contents($log_file);
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

        if (isset($_POST['ga4_yith_raq_form_id'])) {
            update_option('ga4_yith_raq_form_id', sanitize_text_field(wp_unslash($_POST['ga4_yith_raq_form_id'])));
        }

        if (isset($_POST['ga4_conversion_form_ids'])) {
            update_option('ga4_conversion_form_ids', sanitize_text_field(wp_unslash($_POST['ga4_conversion_form_ids'])));
        }

        // GA4 Checkbox options
        update_option('ga4_use_server_side', isset($_POST['ga4_use_server_side']));
        update_option('ga4_server_side_tagging_debug_mode', isset($_POST['ga4_server_side_tagging_debug_mode']));
        update_option('ga4_track_logged_in_users', isset($_POST['ga4_track_logged_in_users']));
        update_option('ga4_ecommerce_tracking', isset($_POST['ga4_ecommerce_tracking']));

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

        // Update logger debug mode
        $this->logger->set_debug_mode(isset($_POST['ga4_server_side_tagging_debug_mode']));

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

            $cloudflare_response = wp_remote_post($cloudflare_worker_url, array(
                'method' => 'POST',
                'timeout' => 5,
                'redirection' => 5,
                'httpversion' => '1.1',
                'blocking' => true,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($cloudflare_payload),
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
            // Get the last 500 lines (or fewer if file is smaller)
            $log_lines = file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($log_lines) {
                $log_lines = array_slice($log_lines, max(0, count($log_lines) - 500));
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
                    <p>Showing the last 500 log entries (newest at the bottom):</p>
                    <div
                        style="background: #f6f6f6; padding: 10px; border: 1px solid #ddd; overflow: auto; max-height: 500px; font-family: monospace; white-space: pre-wrap; font-size: 12px;">
                        <?php echo esc_html($log_content); ?>
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

            <div class="card" style="margin-top: 20px;">
                <h2>Test Event</h2>
                <p>Send a test event to verify your configuration:</p>
                <button id="ga4-test-event" class="button button-primary">Send Test Event</button>
                <div id="ga4-test-result" style="margin-top: 10px; padding: 10px; display: none;"></div>
            </div>

            <script>
                jQuery(document).ready(function ($) {
                    $('#ga4-test-event').on('click', function () {
                        var $button = $(this);
                        var $result = $('#ga4-test-result');

                        $button.prop('disabled', true).text('Sending...');
                        $result.hide();

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'ga4_test_event',
                                nonce: '<?php echo wp_create_nonce('ga4_test_event'); ?>'
                            },
                            success: function (response) {
                                $button.prop('disabled', false).text('Send Test Event');

                                if (response.success) {
                                    $result.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>').show();
                                } else {
                                    $result.html('<div class="notice notice-error inline"><p>Error: ' + response.data.message + '</p></div>').show();
                                }
                            },
                            error: function () {
                                $button.prop('disabled', false).text('Send Test Event');
                                $result.html('<div class="notice notice-error inline"><p>Error: Could not send test event. Check your server logs.</p></div>').show();
                            }
                        });
                    });
                });
            </script>
        </div>
        <?php
    }

    /**
     * Handle AJAX request to send a test event.
     *
     * @since    1.0.0
     */
    public function handle_test_event()
    {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ga4_test_event')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        // Check if user has permission
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'You do not have permission to perform this action.'));
        }

        // Enable debug mode temporarily if not already enabled
        $debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);
        if (!$debug_mode) {
            update_option('ga4_server_side_tagging_debug_mode', true);
        }

        // Log the test event
        $this->logger->info('Manual test event triggered from admin');

        // Create test event data
        $event_data = array(
            'event_category' => 'test',
            'event_label' => 'admin_test',
            'value' => 1,
            'timestamp' => current_time('timestamp'),
            'page_location' => admin_url('admin.php?page=ga4-server-side-tagging-logs'),
            'page_title' => 'GA4 Server-Side Tagging - Debug Logs',
        );

        // Log the event data
        $this->logger->log_data($event_data, 'Test event data');

        // Try to send the event
        $measurement_id = get_option('ga4_measurement_id');
        $api_secret = get_option('ga4_api_secret');
        $cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');

        if (empty($measurement_id) || empty($api_secret)) {
            // Restore debug mode setting
            if (!$debug_mode) {
                update_option('ga4_server_side_tagging_debug_mode', false);
            }

            wp_send_json_error(array('message' => 'Measurement ID or API Secret not configured.'));
            return;
        }

        // Generate a client ID for the test
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

        // Prepare the payload
        if (!empty($cloudflare_worker_url)) {
            // Format for Cloudflare Worker
            $payload = array(
                'name' => 'test_event',
                'params' => array_merge($event_data, array('client_id' => $client_id))
            );

            $endpoint = $cloudflare_worker_url;
            $this->logger->info('Sending test event to Cloudflare Worker: ' . $endpoint);
        } else {
            // Format for direct GA4 API
            $payload = array(
                'client_id' => $client_id,
                'events' => array(
                    array(
                        'name' => 'test_event',
                        'params' => $event_data
                    )
                )
            );

            $endpoint = 'https://www.google-analytics.com/mp/collect?measurement_id=' . $measurement_id . '&api_secret=' . $api_secret;
            $this->logger->info('Sending test event directly to GA4 API: ' . $endpoint);
        }

        // Log the payload
        $this->logger->log_data($payload, 'Test event payload');

        // Send the request
        $response = wp_remote_post($endpoint, array(
            'method' => 'POST',
            'timeout' => 5,
            'redirection' => 5,
            'httpversion' => '1.1',
            'blocking' => true,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode($payload),
            'cookies' => array(),
        ));

        // Restore debug mode setting
        if (!$debug_mode) {
            update_option('ga4_server_side_tagging_debug_mode', false);
        }

        // Check for errors
        if (is_wp_error($response)) {
            $this->logger->error('Test event error: ' . $response->get_error_message());
            wp_send_json_error(array('message' => 'Error: ' . $response->get_error_message()));
            return;
        }

        // Log the response
        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $this->logger->info('Test event response code: ' . $response_code);
        $this->logger->log_data(array('body' => $response_body), 'Test event response body');

        // Check if the request was successful
        if ($response_code >= 200 && $response_code < 300) {
            wp_send_json_success(array(
                'message' => 'Test event sent successfully! Check the logs for details.',
                'response' => $response_body
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Error: Received response code ' . $response_code . '. Check the logs for details.',
                'response' => $response_body
            ));
        }
    }
}