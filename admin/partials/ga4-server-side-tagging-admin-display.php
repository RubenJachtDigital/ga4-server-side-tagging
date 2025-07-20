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
                    
                    <!-- Transmission Method Cache Warning -->
                    <div class="notice notice-warning inline" style="margin-bottom: 20px;">
                        <p><strong>⚠️ Cache Clear Required:</strong> Changing the transmission method requires clearing all website and server caches. See the detailed instructions below the Save button.</p>
                    </div>

                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_transmission_method">Event Transmission Method</label>
                            </th>
                            <td>
                                <select id="ga4_transmission_method" name="ga4_transmission_method" class="regular-text">
                                    <option value="secure_wp_to_cf" <?php selected($transmission_method, 'secure_wp_to_cf'); ?>>
                                        🔒 Secure WordPress to Cloudflare (Most Secure)
                                    </option>
                                    <option value="wp_endpoint_to_cf" <?php selected($transmission_method, 'wp_endpoint_to_cf'); ?>>
                                        🛡️ WP Bot Check before sending to CF (Balanced)
                                    </option>
                                    <option value="direct_to_cf" <?php selected($transmission_method, 'direct_to_cf'); ?>>
                                        ⚡ Direct to Cloudflare (Fastest)
                                    </option>
                                </select>
                                
                                <div id="transmission_method_explanation" style="margin-top: 15px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background-color: #f9f9f9;">
                                    <!-- Content will be populated by JavaScript -->
                                </div>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- Worker URL field - required for all methods -->
                    <table class="form-table" id="worker_url_field">
                        <tr>
                            <th scope="row">
                                <label for="ga4_cloudflare_worker_url">GA4 Cloudflare Worker URL</label>
                            </th>
                            <td>
                                <input type="url" id="ga4_cloudflare_worker_url" name="ga4_cloudflare_worker_url"
                                    value="<?php echo esc_url($cloudflare_worker_url); ?>" class="regular-text" />
                                <p class="description">URL to your Cloudflare Worker for GA4 server-side tagging (required for all methods)</p>
                            </td>
                        </tr>
                    </table>
                    
                    <!-- API Key and Encryption fields - shown only for secure method -->
                    <div id="secure_config_fields" style="<?php echo ($transmission_method === 'secure_wp_to_cf') ? '' : 'display: none;'; ?>">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ga4_worker_api_key">Worker API Key</label>
                                </th>
                                <td>
                                    <input type="text" id="ga4_worker_api_key" name="ga4_worker_api_key"
                                        value="<?php echo esc_attr($worker_api_key); ?>" class="regular-text" 
                                        placeholder="Enter your API key or generate a new one" />
                                    <button type="button" id="generate_api_key" class="button button-secondary" style="margin-left: 10px;">Generate New Key</button>
                                    <p class="description">API key for secure communication with your Cloudflare Worker. You can manually enter your own key or click "Generate New Key" to create a random secure key.</p>
                                    <p class="description"><strong>Important:</strong> Copy this API key and paste it into your Cloudflare Worker configuration.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="ga4_jwt_encryption_enabled">JWT Encryption</label>
                                </th>
                                <td>
                                    <label for="ga4_jwt_encryption_enabled">
                                        <input type="checkbox" id="ga4_jwt_encryption_enabled" name="ga4_jwt_encryption_enabled" <?php checked($jwt_encryption_enabled ?? false); ?> />
                                        Enable JWT encryption for secure requests
                                    </label>
                                    <p class="description">Encrypt JWT tokens and API calls for enhanced security</p>
                                    <!-- Hidden input for secure transmission method -->
                                    <input type="hidden" id="ga4_jwt_encryption_forced" name="ga4_jwt_encryption_enabled" value="1" style="display: none;" />
                                </td>
                            </tr>
                            <tr id="encryption_key_row" style="<?php echo ($jwt_encryption_enabled ?? false) ? '' : 'display: none;'; ?>">
                                <th scope="row">
                                    <label for="ga4_jwt_encryption_key">JWT Encryption Key</label>
                                </th>
                                <td>
                                    <input type="text" id="ga4_jwt_encryption_key" name="ga4_jwt_encryption_key"
                                        value="<?php echo esc_attr($jwt_encryption_key ?? ''); ?>" class="regular-text" 
                                        placeholder="Enter your encryption key or generate a new one" />
                                    <button type="button" id="generate_encryption_key" class="button button-secondary" style="margin-left: 10px;">Generate New Encryption Key</button>
                                    <p class="description">256-bit encryption key for JWT token encryption. You can manually enter your own key or click "Generate New Encryption Key" to create a secure key.</p>
                                    <p class="description"><strong>Important:</strong> Copy this encryption key and paste it into your Cloudflare Worker configuration as the ENCRYPTION_KEY constant.</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                    
                    <!-- Legacy Simple Requests fields - hidden when using new transmission method selector -->
                    <div id="legacy_simple_requests_fields" style="display: none;">
                        <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_simple_requests_enabled">Simple Requests</label>
                            </th>
                            <td>
                                <label for="ga4_simple_requests_enabled">
                                    <input type="checkbox" id="ga4_simple_requests_enabled" name="ga4_simple_requests_enabled" <?php checked($simple_requests_enabled ?? false); ?> />
                                    Enable Simple requests for maximum performance
                                </label>
                                <p class="description">
                                    <strong>⚡ Performance Mode:</strong> Bypasses WordPress REST API and sends events directly from JavaScript to Cloudflare Worker.<br>
                                    <strong>Benefits:</strong> Dramatically reduces server response time (~80% faster), reduces server load, eliminates encryption overhead.<br>
                                    <strong>Trade-offs:</strong> Disables encryption, API key validation, and origin validation. Bot detection and basic rate limiting remain active.<br>
                                    <strong>Recommended for:</strong> High-traffic sites prioritizing performance while maintaining data quality through bot filtering.
                                </p>
                            </td>
                        </tr>
                            <tr id="simple_requests_bot_detection_row" style="<?php echo ($simple_requests_enabled ?? false) ? '' : 'display: none;'; ?>">
                                <th scope="row">
                                    <label for="ga4_simple_requests_bot_detection">Simple Requests Bot Detection</label>
                                </th>
                                <td>
                                    <label for="ga4_simple_requests_bot_detection">
                                        <input type="checkbox" id="ga4_simple_requests_bot_detection" name="ga4_simple_requests_bot_detection" <?php checked($simple_requests_bot_detection ?? false); ?> />
                                        Enable WordPress bot detection for Simple requests
                                    </label>
                                    <p class="description">
                                        <strong>🤖 Bot Detection:</strong> Validates requests via WordPress REST endpoint before sending to Cloudflare Worker.<br>
                                        <strong>Benefits:</strong> Filters bot traffic at WordPress level, uses same bot detection as regular requests, session caching for performance.<br>
                                        <strong>Trade-offs:</strong> Adds WordPress server response time (~200-300ms), but ensures higher data quality.<br>
                                        <strong>Recommended for:</strong> Sites that need maximum bot filtering accuracy and can accept slight performance reduction.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
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

          

                <!-- Cache Clear Notice -->
                <div class="notice notice-info inline" style="margin: 20px 0;">
                    <h3 style="margin-top: 10px;">🔄 Important: Clear Cache After Settings Changes</h3>
                    <p><strong>After saving any changes to these settings, you must clear all caches:</strong></p>
                    <div style="display: flex; gap: 30px; margin: 15px 0;">
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 8px 0; color: #2271b1;">🌐 Website Cache</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.6;">
                                <li>Clear WordPress caching plugins (WP Rocket, W3 Total Cache, etc.)</li>
                                <li>Clear browser cache (Ctrl+F5 or Cmd+Shift+R)</li>
                                <li>Clear any object cache (Redis, Memcached)</li>
                                <li>Purge CDN cache if using one</li>
                            </ul>
                        </div>
                        <div style="flex: 1;">
                            <h4 style="margin: 0 0 8px 0; color: #dc3545;">☁️ Server Cache</h4>
                            <ul style="margin: 0; padding-left: 20px; line-height: 1.6;">
                                <li>Clear Cloudflare cache (if using Cloudflare)</li>
                                <li>Restart Cloudflare Worker (redeploy)</li>
                                <li>Clear server-side PHP opcache</li>
                                <li>Clear any reverse proxy cache (Nginx, Varnish)</li>
                            </ul>
                        </div>
                    </div>
                    <p style="background: #fff3cd; padding: 10px; border-left: 4px solid #856404; margin-top: 15px;">
                        <strong>⚠️ Why This Matters:</strong> Changes to transmission methods, encryption settings, and API configurations 
                        are cached in multiple layers. Without clearing cache, your changes may not take effect immediately, 
                        leading to tracking failures or mixed behavior between old and new settings.
                    </p>
                </div>

                <p class="submit">
                    <input type="submit" name="ga4_server_side_tagging_settings_submit" class="button-primary"
                        value="Save Settings" />
                    <input type="submit" name="ga4_test_connection" class="button-secondary"
                        value="Test Connection" />
                </p>
            </form>

            <?php if ($test_result): ?>
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Connection Test Results</h3>

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
                <h3>🔧 Cloudflare Worker Setup</h3>
                <p>To use server-side tagging with Cloudflare Workers:</p>
                
                <h4>Step 1: Create Cloudflare Worker</h4>
                <ol>
                    <li>Create a new Cloudflare Worker in your dashboard</li>
                    <li>Deploy the provided <code>cloudflare-worker-example.js</code> script</li>
                </ol>
                
                <h4>Step 2: Configure Variables and Secrets</h4>
                <p><strong>📋 Required for All Methods:</strong> GA4 credentials are always needed.</p>
                <p><strong>🔒 Additional for Secure Method:</strong> API key and encryption key only needed for "Secure WordPress to Cloudflare" transmission.</p>
                <ol>
                    <li>Go to your Worker → <strong>Settings → Variables and Secrets</strong></li>
                    <li>Add the required variables with type <strong>"secret"</strong></li>
                    <li><button type="button" id="show-cloudflare-vars" class="button button-secondary">📋 Show Variables to Copy</button></li>
                </ol>
                
                <h4>Step 3: Deploy and Test</h4>
                <ol>
                    <li>Deploy your worker with the updated configuration</li>
                    <li>Enter the worker URL in the settings above</li>
                    <li>Test the configuration using the debug mode</li>
                </ol>
                
                <!-- Cloudflare Variables Modal -->
                <div id="cloudflare-vars-modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
                    <div style="background-color: #fff; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 600px; max-height: 80%; overflow-y: auto; position: relative;">
                        <span id="close-cloudflare-modal" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; line-height: 1;">&times;</span>
                        <h3 style="margin-top: 0;">🔧 Cloudflare Worker Variables and Secrets</h3>
                        <p>Copy and paste these exact values into your Cloudflare Worker Variables and Secrets:</p>
                        
                        <div style="background: #e7f3ff; padding: 10px; border-left: 4px solid #0073aa; margin-bottom: 15px; font-size: 12px;">
                            <p style="margin: 0;"><strong>📋 Required for All Methods:</strong> GA4_MEASUREMENT_ID, GA4_API_SECRET, ALLOWED_DOMAINS</p>
                            <p style="margin: 5px 0 0 0;"><strong>🔒 Secure Method Only:</strong> API_KEY, ENCRYPTION_KEY</p>
                        </div>
                        
                        <div style="background: #f9f9f9; padding: 15px; border-left: 4px solid #0073aa; font-family: monospace; font-size: 13px; margin: 15px 0;">
                            <div style="margin-bottom: 15px;">
                                <strong>Variable Name:</strong> <code>GA4_MEASUREMENT_ID</code><br>
                                <strong>Value:</strong> <input type="text" readonly value="<?php echo esc_attr($measurement_id ?: 'Configure GA4 Measurement ID first'); ?>" style="width: 100%; padding: 5px; margin-top: 5px; font-family: monospace;" onclick="this.select();">
                            </div>
                            
                            <div style="margin-bottom: 15px;">
                                <strong>Variable Name:</strong> <code>GA4_API_SECRET</code><br>
                                <strong>Value:</strong> <input type="text" readonly value="<?php echo esc_attr($api_secret ?: 'Configure GA4 API Secret first'); ?>" style="width: 100%; padding: 5px; margin-top: 5px; font-family: monospace;" onclick="this.select();">
                            </div>
                            
                            <div id="secure-method-api-key" style="margin-bottom: 15px; background: #f8f8f8; padding: 10px; border-radius: 4px; border-left: 4px solid #dc3545;">
                                <strong>🔒 Secure Method Only:</strong> <code>API_KEY</code><br>
                                <strong>Value:</strong> <input type="text" readonly value="<?php echo esc_attr($worker_api_key ?: 'Generate API key first'); ?>" style="width: 100%; padding: 5px; margin-top: 5px; font-family: monospace;" onclick="this.select();">
                                <small style="color: #666;">Only required for "Secure WordPress to Cloudflare" transmission</small>
                            </div>
                            
                            <div id="secure-method-encryption-key" style="margin-bottom: 15px; background: #f8f8f8; padding: 10px; border-radius: 4px; border-left: 4px solid #dc3545;">
                                <strong>🔒 Secure Method Only:</strong> <code>ENCRYPTION_KEY</code><br>
                                <strong>Value:</strong> <input type="text" readonly value="<?php echo esc_attr($jwt_encryption_key ?: 'Generate encryption key if using JWT encryption'); ?>" style="width: 100%; padding: 5px; margin-top: 5px; font-family: monospace;" onclick="this.select();">
                                <small style="color: #666;">Only required for "Secure WordPress to Cloudflare" transmission</small>
                            </div>
                            
                            <div style="margin-bottom: 15px;">
                                <strong>Variable Name:</strong> <code>ALLOWED_DOMAINS</code><br>
                                <strong>Value:</strong> <input type="text" readonly value="<?php 
                                    $site_url = parse_url(get_site_url(), PHP_URL_HOST);
                                    // Handle www vs non-www
                                    if (strpos($site_url, 'www.') === 0) {
                                        // Current has www, show both www and non-www
                                        $domains = $site_url . ',' . substr($site_url, 4);
                                    } else {
                                        // Current doesn't have www, show both non-www and www
                                        $domains = $site_url . ',www.' . $site_url;
                                    }
                                    echo esc_attr($domains); 
                                ?>" style="width: 100%; padding: 5px; margin-top: 5px; font-family: monospace;" onclick="this.select();">
                            </div>
                        </div>
                        
                        <div style="background: #e7f3ff; padding: 10px; border-left: 4px solid #0073aa; margin: 15px 0; font-size: 12px;">
                            <p style="margin: 0;"><strong>💡 Instructions:</strong></p>
                            <ol style="margin: 5px 0 0 20px; padding: 0;">
                                <li>Click on each input field to select the value</li>
                                <li>Copy (Ctrl+C / Cmd+C) and paste into Cloudflare Variables and Secrets</li>
                                <li>Set each variable type as <strong>"secret"</strong></li>
                                <li>Save and deploy your worker</li>
                            </ol>
                        </div>
                        
                        <div style="text-align: center; margin-top: 20px;">
                            <button type="button" id="close-cloudflare-modal-btn" class="button button-primary">Close</button>
                        </div>
                    </div>
                </div>
                
                <p><strong>💡 Benefits of Variables and Secrets:</strong></p>
                <ul style="margin-left: 20px;">
                    <li>✅ <strong>Secure storage</strong> - Values encrypted by Cloudflare</li>
                    <li>✅ <strong>No hardcoding</strong> - Sensitive data not visible in worker script</li>
                    <li>✅ <strong>Easy updates</strong> - Change values without redeploying</li>
                    <li>✅ <strong>Version control safe</strong> - No secrets in your code repository</li>
                </ul>
                
                <p><a href="https://developers.cloudflare.com/workers/runtime-apis/handlers/fetch/" target="_blank">📖 Learn more about Cloudflare Worker environment variables</a></p>
            </div>

            <!-- Event Queue Testing Section -->
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Event Queue Testing & Monitoring</h3>
                <p>Monitor and manually process queued events for testing purposes.</p>
                
                <div id="queue-status" style="margin-bottom: 20px;">
                    <p><strong>Loading queue status...</strong></p>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <button type="button" id="refresh-queue-status" class="button">🔄 Refresh Status</button>
                    <button type="button" id="process-queue-now" class="button button-primary">⚡ Process Queue Now</button>
                </div>
                
                <div id="queue-test-result" style="display: none; padding: 10px; margin-top: 10px; border-radius: 4px;">
                </div>
                
                <div style="margin-top: 20px; font-size: 12px; color: #666;">
                    <p><strong>Note:</strong> Events are automatically processed every 5 minutes via cron job. Manual processing is for testing purposes only.</p>
                </div>
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
    
    // Toggle encryption key visibility based on JWT encryption checkbox
    $('#ga4_jwt_encryption_enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#encryption_key_row').show();
        } else {
            $('#encryption_key_row').hide();
        }
    });
    
    // Toggle Simple requests bot detection visibility based on Simple requests checkbox
    $('#ga4_simple_requests_enabled').on('change', function() {
        if ($(this).is(':checked')) {
            $('#simple_requests_bot_detection_row').show();
        } else {
            $('#simple_requests_bot_detection_row').hide();
        }
    });
    
    // Cloudflare Variables Modal functionality
    $('#show-cloudflare-vars').on('click', function() {
        // Show/hide secure method fields based on current transmission method
        updateModalFieldsVisibility();
        $('#cloudflare-vars-modal').show();
    });
    
    // Function to update modal fields visibility based on transmission method
    function updateModalFieldsVisibility() {
        const selectedMethod = $('#ga4_transmission_method').val();
        
        if (selectedMethod === 'secure_wp_to_cf') {
            $('#secure-method-api-key').show();
            $('#secure-method-encryption-key').show();
        } else {
            $('#secure-method-api-key').hide();
            $('#secure-method-encryption-key').hide();
        }
    }
    
    $('#close-cloudflare-modal, #close-cloudflare-modal-btn').on('click', function() {
        $('#cloudflare-vars-modal').hide();
    });
    
    // Close modal when clicking outside of it
    $('#cloudflare-vars-modal').on('click', function(e) {
        if (e.target === this) {
            $(this).hide();
        }
    });
    
    // Prevent modal from closing when clicking inside the modal content
    $('#cloudflare-vars-modal > div').on('click', function(e) {
        e.stopPropagation();
    });
    
    // Transmission method explanations
    const transmissionExplanations = {
        'secure_wp_to_cf': {
            title: '🔒 Secure WordPress to Cloudflare (Most Secure)',
            description: 'Maximum security with JWT encryption and WordPress endpoint validation.',
            pros: [
                '✅ <strong>Maximum Security:</strong> JWT token encryption for all data transmission',
                '✅ <strong>API Key Protection:</strong> Secure API key validation at WordPress level',
                '✅ <strong>Bot Detection:</strong> Multi-layered bot detection with WordPress security checks',
                '✅ <strong>Origin Validation:</strong> Strict same-domain validation for all requests',
                '✅ <strong>Data Integrity:</strong> Encrypted payloads prevent tampering',
                '✅ <strong>Audit Trail:</strong> Complete logging of all security validations'
            ],
            cons: [
                '❌ <strong>Performance Impact:</strong> Additional encryption/decryption impacts performance',
                '❌ <strong>Complex Setup:</strong> Requires encryption key configuration in Cloudflare Worker',
                '❌ <strong>Resource Usage:</strong> Higher CPU usage for encryption operations'
            ],
            when: 'Use for high-security environments, financial sites, or when handling sensitive data. Best for sites prioritizing security over performance.',
            fields: ['worker_api_key', 'jwt_encryption']
        },
        'wp_endpoint_to_cf': {
            title: '🛡️ WP Bot Check before sending to CF (Balanced)',
            description: 'WordPress validates and forwards events to Cloudflare Worker with optimized performance.',
            pros: [
                '✅ <strong>WordPress Bot Detection:</strong> Comprehensive server-side bot validation via /send-events endpoint',
                '✅ <strong>Async CF Forwarding:</strong> Fire-and-forget transmission to Cloudflare Worker for maximum performance',
                '✅ <strong>Session Caching:</strong> Bot validation results cached for performance',
                '✅ <strong>Single Request:</strong> Only one request needed (to WordPress /send-events endpoint)',
                '✅ <strong>Simple Setup:</strong> No API keys or encryption configuration needed',
                '✅ <strong>Batch Processing:</strong> Multiple events handled efficiently in single request'
            ],
            cons: [
                '❌ <strong>WordPress Processing:</strong> Events processed through WordPress before forwarding',
                '❌ <strong>No Encryption:</strong> Data sent as plain JSON (but still over HTTPS)',
                '❌ <strong>WordPress Dependency:</strong> Relies on WordPress for all event processing',
                '❌ <strong>Moderate Performance:</strong> Slower than direct method due to WordPress processing'
            ],
            when: 'Use for sites needing WordPress-level bot detection with optimized performance. Perfect for most business sites.',
            fields: []
        },
        'direct_to_cf': {
            title: '⚡ Direct to Cloudflare (Fastest)',
            description: 'Maximum performance with direct JavaScript to Cloudflare Worker transmission.',
            pros: [
                '✅ <strong>Maximum Performance:</strong> Direct transmission, ~80% faster than other methods',
                '✅ <strong>Reduced Server Load:</strong> No WordPress processing required',
                '✅ <strong>Minimal Latency:</strong> Bypasses WordPress entirely for event transmission',
                '✅ <strong>Simple Setup:</strong> No API keys or encryption to configure',
                '✅ <strong>CDN Benefits:</strong> Leverages Cloudflare\'s global network optimally',
                '✅ <strong>Scalability:</strong> Handles high traffic without WordPress bottlenecks'
            ],
            cons: [
                '❌ <strong>Basic Security:</strong> No WordPress-level API key validation',
                '❌ <strong>Limited Bot Detection:</strong> Relies only on Cloudflare Worker bot detection',
                '❌ <strong>No Origin Validation:</strong> Cannot verify requests come from your WordPress site',
                '❌ <strong>Exposed Worker URL:</strong> Worker URL is visible in browser network requests'
            ],
            when: 'Use for high-traffic sites prioritizing performance over security. Perfect for content sites, blogs, and when WordPress server load is a concern.',
            fields: []
        }
    };
    
    // Function to update transmission method explanation
    function updateTransmissionExplanation() {
        const selectedMethod = $('#ga4_transmission_method').val();
        const explanation = transmissionExplanations[selectedMethod];
        
        if (explanation) {
            let html = '<div style="margin-bottom: 15px;">';
            html += '<h4 style="margin: 0 0 10px 0; color: #2271b1;">' + explanation.title + '</h4>';
            html += '<p style="margin: 0 0 15px 0; font-style: italic;">' + explanation.description + '</p>';
            html += '</div>';
            
            // Pros section
            html += '<div style="margin-bottom: 15px;">';
            html += '<h5 style="margin: 0 0 8px 0; color: #198754;">✅ Advantages:</h5>';
            html += '<ul style="margin: 0; padding-left: 20px; line-height: 1.6;">';
            explanation.pros.forEach(pro => {
                html += '<li style="margin-bottom: 5px;">' + pro + '</li>';
            });
            html += '</ul>';
            html += '</div>';
            
            // Cons section
            html += '<div style="margin-bottom: 15px;">';
            html += '<h5 style="margin: 0 0 8px 0; color: #dc3545;">❌ Trade-offs:</h5>';
            html += '<ul style="margin: 0; padding-left: 20px; line-height: 1.6;">';
            explanation.cons.forEach(con => {
                html += '<li style="margin-bottom: 5px;">' + con + '</li>';
            });
            html += '</ul>';
            html += '</div>';
            
            // When to use section
            html += '<div style="padding: 12px; background-color: #e7f3ff; border-left: 4px solid #2271b1; border-radius: 4px;">';
            html += '<h5 style="margin: 0 0 8px 0; color: #2271b1;">🎯 When to Use:</h5>';
            html += '<p style="margin: 0; line-height: 1.6;">' + explanation.when + '</p>';
            html += '</div>';
            
            $('#transmission_method_explanation').html(html);
        }
    }
    
    // Function to toggle field visibility based on transmission method
    function toggleTransmissionFields() {
        const selectedMethod = $('#ga4_transmission_method').val();
        
        // Show/hide worker URL field - needed for all methods except none
        $('#worker_url_field').show(); // All methods need Worker URL to forward events
        
        // Show/hide secure config fields
        if (selectedMethod === 'secure_wp_to_cf') {
            $('#secure_config_fields').show();
            // For most secure method, enable encryption by default and hide the checkbox
            $('#ga4_jwt_encryption_enabled').prop('checked', true).closest('tr').hide();
            $('#ga4_jwt_encryption_forced').show();
            $('#encryption_key_row').show();
        } else {
            $('#secure_config_fields').hide();
            // For other methods (balanced and direct), show encryption checkbox and let user choose
            $('#ga4_jwt_encryption_enabled').closest('tr').show();
            $('#ga4_jwt_encryption_forced').hide();
        }
        
        // Hide legacy simple requests fields since we're using the new method selector
        $('#legacy_simple_requests_fields').hide();
    }
    
    // Initialize on page load
    updateTransmissionExplanation();
    toggleTransmissionFields();
    
    // Handle transmission method changes
    $('#ga4_transmission_method').on('change', function() {
        updateTransmissionExplanation();
        toggleTransmissionFields();
    });

    // Event Queue Testing Functions
    function loadQueueStatus() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ga4_get_queue_status',
                nonce: '<?php echo wp_create_nonce('ga4_generate_api_key'); ?>'
            },
            success: function(response) {
                if (response.success) {
                    var data = response.data;
                    var html = '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 15px;">';
                    html += '<div style="background: #f0f8ff; padding: 10px; border-radius: 4px; text-align: center;"><strong>' + data.pending_events + '</strong><br><small>Pending</small></div>';
                    html += '<div style="background: #fff3cd; padding: 10px; border-radius: 4px; text-align: center;"><strong>' + data.processing_events + '</strong><br><small>Processing</small></div>';
                    html += '<div style="background: #d4edda; padding: 10px; border-radius: 4px; text-align: center;"><strong>' + data.completed_events + '</strong><br><small>Completed</small></div>';
                    html += '<div style="background: #f8d7da; padding: 10px; border-radius: 4px; text-align: center;"><strong>' + data.failed_events + '</strong><br><small>Failed</small></div>';
                    html += '</div>';
                    
                    if (data.oldest_pending) {
                        html += '<p><strong>Oldest pending event:</strong> ' + data.oldest_pending + '</p>';
                    }
                    
                    if (data.recent_batches && data.recent_batches.length > 0) {
                        html += '<details style="margin-top: 10px;"><summary><strong>Recent Batches</strong></summary><ul>';
                        data.recent_batches.forEach(function(batch) {
                            html += '<li>' + batch.batch_id + ' (' + batch.event_count + ' events) - ' + batch.processed_at + '</li>';
                        });
                        html += '</ul></details>';
                    }
                    
                    $('#queue-status').html(html);
                } else {
                    $('#queue-status').html('<p style="color: red;">Error loading queue status: ' + response.data.message + '</p>');
                }
            },
            error: function() {
                $('#queue-status').html('<p style="color: red;">Failed to load queue status</p>');
            }
        });
    }

    function processQueueNow() {
        $('#process-queue-now').prop('disabled', true).text('⏳ Processing...');
        $('#queue-test-result').hide();
        
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'ga4_process_event_queue',
                nonce: '<?php echo wp_create_nonce('ga4_generate_api_key'); ?>'
            },
            success: function(response) {
                var resultDiv = $('#queue-test-result');
                if (response.success) {
                    resultDiv.removeClass('error').addClass('success')
                        .css('background-color', '#d4edda')
                        .css('color', '#155724')
                        .css('border', '1px solid #c3e6cb')
                        .html('<strong>✅ Success:</strong> ' + response.data.message);
                    
                    if (response.data.processing_time_ms) {
                        resultDiv.append('<br><small>Processing time: ' + response.data.processing_time_ms + 'ms</small>');
                    }
                } else {
                    resultDiv.removeClass('success').addClass('error')
                        .css('background-color', '#f8d7da')
                        .css('color', '#721c24')
                        .css('border', '1px solid #f5c6cb')
                        .html('<strong>❌ Error:</strong> ' + response.data.message);
                }
                resultDiv.show();
                
                // Refresh status after processing
                setTimeout(loadQueueStatus, 1000);
            },
            error: function() {
                $('#queue-test-result').removeClass('success').addClass('error')
                    .css('background-color', '#f8d7da')
                    .css('color', '#721c24')
                    .css('border', '1px solid #f5c6cb')
                    .html('<strong>❌ Error:</strong> Failed to process queue')
                    .show();
            },
            complete: function() {
                $('#process-queue-now').prop('disabled', false).text('⚡ Process Queue Now');
            }
        });
    }

    // Event handlers for queue testing
    $('#refresh-queue-status').on('click', loadQueueStatus);
    $('#process-queue-now').on('click', processQueueNow);
    
    // Load initial queue status
    loadQueueStatus();
});
</script>