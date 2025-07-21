<?php
/**
 * Provide a settings admin area view for the plugin
 *
 * This file is used to markup the settings admin-facing aspects of the plugin.
 *
 * @since      2.0.0
 * @package    GA4_Server_Side_Tagging
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Get all the options - this will need to be populated with the actual settings retrieval
$use_iubenda = get_option('ga4_use_iubenda', false);
$consent_accept_selector = get_option('ga4_consent_accept_selector', '.iubenda-cs-accept-btn');
$consent_deny_selector = get_option('ga4_consent_deny_selector', '.iubenda-cs-reject-btn');
$consent_default_timeout = get_option('ga4_consent_default_timeout', 0);
$consent_timeout_action = get_option('ga4_consent_timeout_action', 'deny');
$transmission_method = get_option('ga4_transmission_method', 'direct_to_cf');
$cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');
$jwt_encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
$measurement_id = get_option('ga4_measurement_id', '');
$debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);
$ecommerce_tracking = get_option('ga4_ecommerce_tracking', true);
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors(); ?>

    <div class="ga4-server-side-tagging-admin">
        <div class="ga4-server-side-tagging-admin-header">
            <h2>GA4 Server-Side Tagging Settings</h2>
            <p>Configure your GA4 server-side tagging settings for WordPress and WooCommerce.</p>
        </div>

        <div class="ga4-server-side-tagging-admin-content">
            <form method="post" action="">
                <?php wp_nonce_field('ga4_server_side_tagging_settings'); ?>

                <!-- GDPR Consent Settings -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>GDPR Consent Settings</h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Use Iubenda</th>
                            <td>
                                <label for="ga4_use_iubenda">
                                    <input type="checkbox" id="ga4_use_iubenda" name="ga4_use_iubenda" <?php checked($use_iubenda); ?> />
                                    I use Iubenda for consent management
                                </label>
                                <p class="description">Check this if you're using Iubenda. If unchecked, we'll use custom consent selectors.</p>
                            </td>
                        </tr>
                    </table>

                    <div id="custom_consent_settings" style="<?php echo $use_iubenda ? 'display: none;' : ''; ?>">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ga4_consent_accept_selector">Accept All CSS Selector</label>
                                </th>
                                <td>
                                    <input type="text" id="ga4_consent_accept_selector" name="ga4_consent_accept_selector"
                                        value="<?php echo esc_attr($consent_accept_selector); ?>" class="regular-text" 
                                        placeholder=".accept-all, #accept-cookies" />
                                    <p class="description">CSS selector for the "Accept All" button/link (e.g., ".accept-all", "#accept-cookies")</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ga4_consent_deny_selector">Deny All CSS Selector</label>
                                </th>
                                <td>
                                    <input type="text" id="ga4_consent_deny_selector" name="ga4_consent_deny_selector"
                                        value="<?php echo esc_attr($consent_deny_selector); ?>" class="regular-text" 
                                        placeholder=".deny-all, #reject-cookies" />
                                    <p class="description">CSS selector for the "Deny All" button/link (e.g., ".deny-all", "#reject-cookies")</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_consent_default_timeout">Default Consent Timeout</label>
                            </th>
                            <td>
                                <input type="number" id="ga4_consent_default_timeout" name="ga4_consent_default_timeout"
                                    value="<?php echo esc_attr($consent_default_timeout); ?>" min="0" max="300" />
                                <span>seconds</span>
                                <p class="description">Time in seconds before automatically taking action (0 = disabled). User can still accept/deny manually during this time and after.</p>
                            </td>
                        </tr>
                        <tr id="timeout_action_row">
                            <th scope="row">
                                <label>Timeout Action</label>
                            </th>
                            <td>
                                <fieldset>
                                    <label for="ga4_consent_timeout_action_deny">
                                        <input type="radio" id="ga4_consent_timeout_action_deny" name="ga4_consent_timeout_action" 
                                            value="deny" <?php checked($consent_timeout_action, 'deny'); ?> />
                                        Deny All - Automatically deny consent after timeout
                                    </label>
                                    <br><br>
                                    <label for="ga4_consent_timeout_action_accept">
                                        <input type="radio" id="ga4_consent_timeout_action_accept" name="ga4_consent_timeout_action" 
                                            value="accept" <?php checked($consent_timeout_action, 'accept'); ?> />
                                        Accept All - Automatically accept consent after timeout
                                    </label>
                                </fieldset>
                                <p class="description">What action to take when the timeout expires. Users can still manually accept/deny after timeout.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Tracking Options -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Tracking Options</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable E-commerce Tracking</th>
                            <td>
                                <label for="ga4_ecommerce_tracking">
                                    <input type="checkbox" id="ga4_ecommerce_tracking" name="ga4_ecommerce_tracking" <?php checked($ecommerce_tracking); ?> />
                                    Track WooCommerce e-commerce events
                                </label>
                                <p class="description">Automatically track purchase events, add to cart, and other e-commerce interactions.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Debug Mode</th>
                            <td>
                                <label for="ga4_server_side_tagging_debug_mode">
                                    <input type="checkbox" id="ga4_server_side_tagging_debug_mode" name="ga4_server_side_tagging_debug_mode" <?php checked($debug_mode); ?> />
                                    Enable debug logging
                                </label>
                                <p class="description">Enable detailed logging for troubleshooting. Logs can be viewed in the Error Logs page.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Cloudflare Integration -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Cloudflare Integration</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Transmission Method</th>
                            <td>
                                <select id="ga4_transmission_method" name="ga4_transmission_method" class="regular-text">
                                    <option value="direct_to_cf" <?php selected($transmission_method, 'direct_to_cf'); ?>>⚡ Direct to Cloudflare (Fastest)</option>
                                    <option value="wp_rest_endpoint" <?php selected($transmission_method, 'wp_rest_endpoint'); ?>>🛡️ WP REST Endpoint (Secure)</option>
                                </select>
                                <p class="description">
                                    <strong>Direct to Cloudflare:</strong> Sends events directly to your Cloudflare Worker for fastest processing.<br>
                                    <strong>WP REST Endpoint:</strong> Routes through WordPress REST API first for additional security and encryption options.
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_cloudflare_worker_url">Cloudflare Worker URL</label>
                            </th>
                            <td>
                                <input type="url" id="ga4_cloudflare_worker_url" name="ga4_cloudflare_worker_url" 
                                    value="<?php echo esc_attr($cloudflare_worker_url); ?>" class="regular-text" 
                                    placeholder="https://your-worker.your-subdomain.workers.dev" />
                                <p class="description">Your Cloudflare Worker endpoint URL.</p>
                            </td>
                        </tr>
                    </table>

                    <!-- Encryption Settings (shown only for WP REST Endpoint method) -->
                    <div id="encryption_settings" style="<?php echo $transmission_method === 'wp_rest_endpoint' ? '' : 'display: none;'; ?>">
                        <table class="form-table">
                            <tr>
                                <th scope="row">JWT Encryption</th>
                                <td>
                                    <label for="ga4_jwt_encryption_enabled">
                                        <input type="checkbox" id="ga4_jwt_encryption_enabled" name="ga4_jwt_encryption_enabled" <?php checked($jwt_encryption_enabled); ?> />
                                        Enable JWT encryption for event data
                                    </label>
                                    <p class="description">Encrypts event data before sending to your Cloudflare Worker. Only available with WP REST Endpoint method.</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- Google Analytics 4 Configuration -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Google Analytics 4 Configuration</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_measurement_id">GA4 Measurement ID</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_measurement_id" name="ga4_measurement_id" 
                                    value="<?php echo esc_attr($measurement_id); ?>" class="regular-text" 
                                    placeholder="G-XXXXXXXXXX" />
                                <p class="description">Your Google Analytics 4 Measurement ID (starts with G-).</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Connection Test Section -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Test Your Configuration</h3>
                    <p class="description">Test your Cloudflare Worker and GA4 connections to ensure everything is working correctly.</p>
                    
                    <input type="submit" name="test_ga4_connection" class="button button-secondary" value="Test Connection" />
                    <p class="description">
                        This will send a test event to verify that your Cloudflare Worker can reach Google Analytics 4.
                    </p>
                </div>

                <?php if ($test_result): ?>
                    <div class="ga4-server-side-tagging-admin-section">
                        <h3>Connection Test Results</h3>

                        <div class="ga4-server-side-tagging-test-result <?php echo $test_result['success'] ? 'success' : 'error'; ?>">
                            <p><strong>GA4 API:</strong> <?php echo esc_html($test_result['message']); ?></p>

                            <?php if (isset($test_result['cloudflare']) && $test_result['cloudflare']['tested']): ?>
                                <p><strong>Cloudflare Worker:</strong>
                                    <?php echo esc_html($test_result['cloudflare']['message']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php submit_button(); ?>

            </form>
        </div>

        <div class="ga4-server-side-tagging-admin-sidebar">
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Getting Started</h3>
                <ol>
                    <li>Configure GDPR consent settings</li>
                    <li>Enter your GA4 Measurement ID</li>
                    <li>Set up your Cloudflare Worker (optional but recommended)</li>
                    <li>Adjust tracking options as needed</li>
                    <li>Test the connections to verify setup</li>
                    <li>Save settings and visit the main page for A/B testing and click tracking</li>
                </ol>
            </div>

            <div class="ga4-server-side-tagging-admin-box">
                <h3>GDPR Consent Guide</h3>
                <p>For GDPR compliance:</p>
                <ol>
                    <li>Check "Use Iubenda" if you use their consent system</li>
                    <li>Or provide CSS selectors for your consent buttons</li>
                    <li>Set a timeout for automatic consent (optional)</li>
                    <li>Enable Consent Mode v2 for better data modeling</li>
                </ol>
                <p><strong>Note:</strong> When consent is denied, only timezone-based location (continent level) will be tracked.</p>
            </div>

            <div class="ga4-server-side-tagging-admin-box">
                <h3>🔧 Cloudflare Worker Setup</h3>
                <p>For enhanced performance and privacy, set up a Cloudflare Worker:</p>
                <ol>
                    <li>Deploy the provided worker script to Cloudflare</li>
                    <li>Configure environment variables (GA4_MEASUREMENT_ID, etc.)</li>
                    <li>Enter the Worker URL above</li>
                    <li>Choose transmission method (Direct vs WP REST Endpoint)</li>
                    <li>Test the connection</li>
                </ol>
                <p><strong>Benefits:</strong> Bypasses ad blockers, improves performance, enhances privacy compliance, enables advanced bot detection.</p>
            </div>

            <div class="ga4-server-side-tagging-admin-box">
                <h3>Transmission Methods</h3>
                <p><strong>Direct to Cloudflare:</strong></p>
                <ul>
                    <li>• Fastest performance</li>
                    <li>• Events sent directly to your Worker</li>
                    <li>• Bypasses WordPress processing</li>
                    <li>• Recommended for most setups</li>
                </ul>
                
                <p><strong>WP REST Endpoint:</strong></p>
                <ul>
                    <li>• Additional security layer</li>
                    <li>• Optional JWT encryption</li>
                    <li>• WordPress processes events first</li>
                    <li>• Better for high-security environments</li>
                </ul>
            </div>

            <div class="ga4-server-side-tagging-admin-box">
                <h3>Quick Navigation</h3>
                <ul>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging'); ?>">🧪 A/B Testing & Click Tracking</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-cronjobs'); ?>">📊 Event Queue & Cronjobs</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-logs'); ?>">📄 Error Logs</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Toggle custom consent settings based on Iubenda checkbox
    $('#ga4_use_iubenda').change(function() {
        if ($(this).is(':checked')) {
            $('#custom_consent_settings').hide();
        } else {
            $('#custom_consent_settings').show();
        }
    });

    // Toggle timeout action row based on timeout value
    function toggleTimeoutAction() {
        var timeoutValue = parseInt($('#ga4_consent_default_timeout').val()) || 0;
        if (timeoutValue > 0) {
            $('#timeout_action_row').show();
        } else {
            $('#timeout_action_row').hide();
        }
    }

    $('#ga4_consent_default_timeout').change(toggleTimeoutAction);
    toggleTimeoutAction(); // Initial state

    // Toggle encryption settings based on transmission method
    $('#ga4_transmission_method').change(function() {
        if ($(this).val() === 'wp_rest_endpoint') {
            $('#encryption_settings').show();
        } else {
            $('#encryption_settings').hide();
        }
    });
});
</script>

<style>
.ga4-server-side-tagging-admin {
    display: flex;
    gap: 30px;
}

.ga4-server-side-tagging-admin-content {
    flex: 1;
}

.ga4-server-side-tagging-admin-sidebar {
    width: 300px;
}

.ga4-server-side-tagging-admin-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin: 20px 0;
    padding: 20px;
}

.ga4-server-side-tagging-admin-section h3 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.ga4-server-side-tagging-admin-header {
    margin-bottom: 20px;
}

.ga4-server-side-tagging-admin-header h2 {
    margin-bottom: 5px;
}

.ga4-server-side-tagging-admin-header p {
    color: #666;
    font-size: 14px;
}

.ga4-server-side-tagging-admin-box {
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-bottom: 20px;
    padding: 20px;
}

.ga4-server-side-tagging-admin-box h3 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 1px solid #eee;
}

.ga4-server-side-tagging-test-result {
    padding: 15px;
    border-radius: 4px;
    margin: 10px 0;
}

.ga4-server-side-tagging-test-result.success {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.ga4-server-side-tagging-test-result.error {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}
</style>