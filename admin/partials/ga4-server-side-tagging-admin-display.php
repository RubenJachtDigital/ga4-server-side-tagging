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
                                <p class="description">Choose what happens when the timeout is reached. "Deny All" is more privacy-friendly and GDPR compliant.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_disable_all_ip">Disable All IP Geolocation</label>
                            </th>
                            <td>
                                <label for="ga4_disable_all_ip">
                                    <input type="checkbox" id="ga4_disable_all_ip" name="ga4_disable_all_ip" <?php checked($disable_all_ip); ?> />
                                    Disable all IP-based location tracking
                                </label>
                                <p class="description">When enabled, only timezone-based location fallback will be used (no external IP geolocation APIs)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_storage_expiration_hours">Storage Expiration Time</label>
                            </th>
                            <td>
                                <input type="number" id="ga4_storage_expiration_hours" name="ga4_storage_expiration_hours"
                                    value="<?php echo esc_attr($storage_expiration_hours); ?>" min="1" max="8760" />
                                <span>hours</span>
                                <p class="description">How long to store cached location data (1-8760 hours, default: 24 hours)</p>
                            </td>
                        </tr>
                        <!-- Consent Mode hidden - always enabled -->
                        <tr style="display: none;">
                            <th scope="row">Consent Mode</th>
                            <td>
                                <label for="ga4_consent_mode_enabled">
                                    <input type="checkbox" id="ga4_consent_mode_enabled" name="ga4_consent_mode_enabled" checked style="display: none;" />
                                    Enable Google Consent Mode v2
                                </label>
                                <p class="description">Send consent signals to Google Analytics for better data modeling when users deny consent</p>
                            </td>
                        </tr>
                        <!-- Hidden input to ensure consent mode is always enabled -->
                        <input type="hidden" name="ga4_consent_mode_enabled" value="1" />
                    </table>
                </div>
      <!-- A/B Testing Settings -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>A/B Testing Configuration</h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable A/B Testing</th>
                            <td>
                                <label for="ga4_ab_tests_enabled">
                                    <input type="checkbox" id="ga4_ab_tests_enabled" name="ga4_ab_tests_enabled" <?php checked($ab_tests_enabled); ?> />
                                    Enable A/B testing functionality
                                </label>
                                <p class="description">Track user interactions with different variations of elements</p>
                            </td>
                        </tr>
                    </table>

                    <div id="ab_testing_config" style="<?php echo $ab_tests_enabled ? '' : 'display: none;'; ?>">
                        <h4>A/B Test Configurations</h4>
                        <p class="description">Configure button click tracking for A/B tests. When users click elements with these CSS classes, events will be sent with the variant as the event name (e.g., "button_test_a" or "button_test_b"). Add the CSS classes to your buttons in your theme.</p>
                        
                        <div id="ab_tests_container">
                            <?php if (!empty($ab_tests_array)): ?>
                                <?php foreach ($ab_tests_array as $index => $test): ?>
                                    <div class="ab-test-item" data-index="<?php echo $index; ?>">
                                        <table class="form-table">
                                            <tr>
                                                <th scope="row">
                                                    <label>Test Name</label>
                                                </th>
                                                <td>
                                                    <input type="text" name="ab_test_name[]" value="<?php echo esc_attr($test['name']); ?>" 
                                                           placeholder="e.g., Button Color Test" class="regular-text" />
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row">
                                                    <label>Variant A CSS Class</label>
                                                </th>
                                                <td>
                                                    <input type="text" name="ab_test_class_a[]" value="<?php echo esc_attr($test['class_a']); ?>" 
                                                           placeholder="e.g., .button-red" class="regular-text" />
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row">
                                                    <label>Variant B CSS Class</label>
                                                </th>
                                                <td>
                                                    <input type="text" name="ab_test_class_b[]" value="<?php echo esc_attr($test['class_b']); ?>" 
                                                           placeholder="e.g., .button-blue" class="regular-text" />
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row">
                                                    <label>Enabled</label>
                                                </th>
                                                <td>
                                                    <input type="checkbox" name="ab_test_enabled[]" value="1" <?php checked($test['enabled']); ?> />
                                                    <span>Test is active</span>
                                                </td>
                                            </tr>
                                        </table>
                                        <button type="button" class="button remove-ab-test">Remove Test</button>
                                        <hr>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <button type="button" id="add_ab_test" class="button">Add New A/B Test</button>
                        
                        <input type="hidden" id="ga4_ab_tests_config" name="ga4_ab_tests_config" value="" />
                    </div>
                </div>
                
                <!-- Click Tracking Settings -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Click Tracking Configuration</h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">Enable Click Tracking</th>
                            <td>
                                <label for="ga4_click_tracks_enabled">
                                    <input type="checkbox" id="ga4_click_tracks_enabled" name="ga4_click_tracks_enabled" <?php checked($click_tracks_enabled); ?> />
                                    Enable click tracking functionality
                                </label>
                                <p class="description">Track user clicks on specific elements using CSS selectors</p>
                            </td>
                        </tr>
                    </table>

                    <div id="click_tracking_config" style="<?php echo $click_tracks_enabled ? '' : 'display: none;'; ?>">
                        <h4>Click Track Configurations</h4>
                        <p class="description">Configure element click tracking. When users click elements matching these CSS selectors, custom events will be sent with the specified event name (e.g., "button_click", "download_pdf"). Event names will be automatically sanitized to be GA4-compliant.</p>
                        
                        <div id="click_tracks_container">
                            <?php if (!empty($click_tracks_array)): ?>
                                <?php foreach ($click_tracks_array as $index => $track): ?>
                                    <div class="click-track-item" data-index="<?php echo $index; ?>">
                                        <table class="form-table">
                                            <tr>
                                                <th scope="row">
                                                    <label>Event Name</label>
                                                </th>
                                                <td>
                                                    <input type="text" name="click_track_name[]" value="<?php echo esc_attr($track['name']); ?>" 
                                                           placeholder="e.g., download_pdf, cta_click" class="regular-text" />
                                                    <p class="description">This becomes the GA4 event name (automatically sanitized)</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row">
                                                    <label>CSS Selector</label>
                                                </th>
                                                <td>
                                                    <input type="text" name="click_track_selector[]" value="<?php echo esc_attr($track['selector']); ?>" 
                                                           placeholder="e.g., .download-btn, #cta-button, .track-click" class="regular-text" />
                                                    <p class="description">CSS selector for elements to track (class, ID, or any valid CSS selector)</p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <th scope="row">
                                                    <label>Enabled</label>
                                                </th>
                                                <td>
                                                    <input type="checkbox" name="click_track_enabled[]" value="1" <?php checked($track['enabled']); ?> />
                                                    <span>Track is active</span>
                                                </td>
                                            </tr>
                                        </table>
                                        <button type="button" class="button remove-click-track">Remove Track</button>
                                        <hr>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                        <button type="button" id="add_click_track" class="button">Add New Click Track</button>
                        
                        <input type="hidden" id="ga4_click_tracks_config" name="ga4_click_tracks_config" value="" />
                        
                        <div class="notice notice-info inline" style="margin-top: 15px;">
                            <p><strong>Event Name Validation Rules:</strong></p>
                            <ul style="margin-left: 20px;">
                                <li>• Must be 40 characters or less</li>
                                <li>• Can only contain letters, numbers, and underscores</li>
                                <li>• Cannot start with a number</li>
                                <li>• Automatically converted to lowercase</li>
                                <li>• Invalid characters replaced with underscores</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <!-- Tracking Options -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Tracking Options</h3>

                    <table class="form-table">
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
                        <tr>
                            <th scope="row">
                                <label for="ga4_worker_api_key">Worker API Key</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_worker_api_key" name="ga4_worker_api_key"
                                    value="<?php echo esc_attr($worker_api_key); ?>" class="regular-text" />
                                <button type="button" id="generate_api_key" class="button button-secondary" style="margin-left: 10px;">Generate New Key</button>
                                <p class="description">API key for secure communication with your Cloudflare Worker. Click "Generate New Key" to create a random secure key.</p>
                                <p class="description"><strong>Important:</strong> Copy this API key and paste it into your Cloudflare Worker configuration.</p>
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
                    <li>Force your consent manager plugin to refresh all sessions. This can be done by changing the settings.</li>
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
    
    // Function to toggle timeout action visibility based on timeout value
    function toggleTimeoutActionVisibility() {
        var timeoutValue = parseInt($('#ga4_consent_default_timeout').val()) || 0;
        if (timeoutValue > 0) {
            $('#timeout_action_row').show();
        } else {
            $('#timeout_action_row').hide();
        }
    }
    
    // Initial check on page load
    toggleTimeoutActionVisibility();
    
    // Listen for changes to the timeout input
    $('#ga4_consent_default_timeout').on('input change keyup', function() {
        toggleTimeoutActionVisibility();
    });
});
</script>