<?php
/**
 * Provide a admin area view for the plugin
 *
 * This file is used to markup the admin-facing aspects of the plugin.
 *
 * @since      1.0.0
 * @package    GA4_Server_Side_Tagging
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}
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
                                <p class="description">Time in seconds before automatically accepting consent (0 = disabled). User can still deny during this time.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Consent Mode</th>
                            <td>
                                <label for="ga4_consent_mode_enabled">
                                    <input type="checkbox" id="ga4_consent_mode_enabled" name="ga4_consent_mode_enabled" <?php checked($consent_mode_enabled); ?> />
                                    Enable Google Consent Mode v2
                                </label>
                                <p class="description">Send consent signals to Google Analytics for better data modeling when users deny consent</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Tracking Options -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Tracking Options</h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row">Server-Side Tagging</th>
                            <td>
                                <label for="ga4_use_server_side">
                                    <input type="checkbox" id="ga4_use_server_side" name="ga4_use_server_side" <?php checked($use_server_side); ?> />
                                    Enable server-side tagging
                                </label>
                                <p class="description">Send events to GA4 from the server instead of the browser</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">E-Commerce Tracking</th>
                            <td>
                                <label for="ga4_ecommerce_tracking">
                                    <input type="checkbox" id="ga4_ecommerce_tracking" name="ga4_ecommerce_tracking"
                                        <?php checked($ecommerce_tracking); ?> />
                                    Enable e-commerce tracking
                                </label>
                                <p class="description">Track WooCommerce events (product views, add to cart, checkout,
                                    purchases)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Logged-in Users</th>
                            <td>
                                <label for="ga4_track_logged_in_users">
                                    <input type="checkbox" id="ga4_track_logged_in_users"
                                        name="ga4_track_logged_in_users" <?php checked($track_logged_in_users); ?> />
                                    Track logged-in users
                                </label>
                                <p class="description">Whether to track logged-in users (including administrators)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Debug Mode</th>
                            <td>
                                <label for="ga4_server_side_tagging_debug_mode">
                                    <input type="checkbox" id="ga4_server_side_tagging_debug_mode"
                                        name="ga4_server_side_tagging_debug_mode" <?php checked($debug_mode); ?> />
                                    Enable debug mode
                                </label>
                                <p class="description">Log events and enable debug mode in GA4</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Cloudflare Integration -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Cloudflare Integration</h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_cloudflare_worker_url">GA4 Cloudflare Worker URL</label>
                            </th>
                            <td>
                                <input type="url" id="ga4_cloudflare_worker_url" name="ga4_cloudflare_worker_url"
                                    value="<?php echo esc_url($cloudflare_worker_url); ?>" class="regular-text" />
                                <p class="description">URL to your Cloudflare Worker for GA4 server-side tagging
                                    (optional)</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- GA4 Analytics Settings -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Google Analytics 4 Configuration</h3>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_measurement_id">Measurement ID</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_measurement_id" name="ga4_measurement_id"
                                    value="<?php echo esc_attr($measurement_id); ?>" class="regular-text" />
                                <p class="description">Your GA4 Measurement ID (e.g., G-XXXXXXXXXX)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_api_secret">API Secret</label>
                            </th>
                            <td>
                                <input type="password" id="ga4_api_secret" name="ga4_api_secret"
                                    value="<?php echo esc_attr($api_secret); ?>" class="regular-text" />
                                <p class="description">Your GA4 API Secret for server-side events</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_yith_raq_form_id">YITH Request a Quote Form id</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_yith_raq_form_id" name="ga4_yith_raq_form_id"
                                    value="<?php echo esc_attr($yith_raq_form_id); ?>" class="regular-text" />
                                <p class="description">Your YITH Request a Quote Form id(found in Gravity forms)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_conversion_form_ids">Conversion form id(s)</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_conversion_form_ids" name="ga4_conversion_form_ids"
                                    value="<?php echo esc_attr($conversion_form_ids); ?>" class="regular-text" />
                                <p class="description">Your important conversion form id's(They should be in the following format "1,2,3,4")<br>Only works with gravity forms</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <p class="submit">
                    <input type="submit" name="ga4_server_side_tagging_settings_submit" class="button-primary"
                        value="Save Settings" />
                    <input type="submit" name="ga4_test_connection" class="button-secondary"
                        value="Test GA4 Connection" />
                </p>
            </form>

            <?php if ($test_result): ?>
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>GA4 Connection Test Results</h3>

                    <div
                        class="ga4-server-side-tagging-test-result <?php echo $test_result['success'] ? 'success' : 'error'; ?>">
                        <p><strong>GA4 API:</strong> <?php echo esc_html($test_result['message']); ?></p>

                        <?php if (isset($test_result['cloudflare']) && $test_result['cloudflare']['tested']): ?>
                            <p><strong>Cloudflare Worker:</strong>
                                <?php echo esc_html($test_result['cloudflare']['message']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="ga4-server-side-tagging-admin-sidebar">
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Getting Started</h3>
                <ol>
                    <li>Configure GDPR consent settings</li>
                    <li>Enter your GA4 Measurement ID and API Secret</li>
                    <li>Set up your Cloudflare Workers (optional)</li>
                    <li>Adjust tracking options as needed</li>
                    <li>Save settings and test the connections</li>
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
                <h3>Cloudflare Worker Setup</h3>
                <p>To use server-side tagging with Cloudflare:</p>
                <ol>
                    <li>Create a Cloudflare worker</li>
                    <li>Deploy the respective proxy code to each worker</li>
                    <li>Enter the worker URLs in the settings</li>
                </ol>
                <p><a href="https://developers.cloudflare.com/workers/" target="_blank">Learn more about Cloudflare
                        Workers</a></p>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Toggle custom consent settings based on Iubenda checkbox
    $('#ga4_use_iubenda').on('change', function() {
        if ($(this).is(':checked')) {
            $('#custom_consent_settings').hide();
        } else {
            $('#custom_consent_settings').show();
        }
    });
});
</script>