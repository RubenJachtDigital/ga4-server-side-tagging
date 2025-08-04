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
$disable_cf_proxy = get_option('ga4_disable_cf_proxy', false);
$cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');
$jwt_encryption_enabled = get_option('ga4_jwt_encryption_enabled', false);
$jwt_encryption_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
$worker_api_key = \GA4ServerSideTagging\Utilities\GA4_Encryption_Util::retrieve_encrypted_key('ga4_worker_api_key');
$measurement_id = get_option('ga4_measurement_id', '');
$api_secret = get_option('ga4_api_secret', '');
$debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);
$extensive_error_logging = get_option('ga4_extensive_error_logging', false);
$ecommerce_tracking = get_option('ga4_ecommerce_tracking', true);
$track_logged_in_users = get_option('ga4_track_logged_in_users', true);
$yith_raq_form_id = get_option('ga4_yith_raq_form_id', '');
$conversion_form_ids = get_option('ga4_conversion_form_ids', '');
$disable_all_ip = get_option('ga4_disable_all_ip', false);
$batch_size = get_option('ga4_event_batch_size', 1000);
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
                <?php wp_nonce_field('ga4_settings_form'); ?>
                <input type="hidden" name="form_type" value="settings" />

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
                        <tr>
                            <th scope="row">Disable All IP Geolocation</th>
                            <td>
                                <label for="ga4_disable_all_ip">
                                    <input type="checkbox" id="ga4_disable_all_ip" name="ga4_disable_all_ip" <?php checked($disable_all_ip); ?> />
                                    Disable all IP-based location tracking
                                </label>
                                <p class="description">When enabled, only timezone-based location fallback will be used (no external IP geolocation APIs)</p>
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
                            <th scope="row">Track Logged-in Users</th>
                            <td>
                                <label for="ga4_track_logged_in_users">
                                    <input type="checkbox" id="ga4_track_logged_in_users" name="ga4_track_logged_in_users" <?php checked($track_logged_in_users); ?> />
                                    Track logged-in users (including administrators)
                                </label>
                                <p class="description">Whether to track logged-in users including administrators. Disable to exclude internal traffic from analytics.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_yith_raq_form_id">YITH Request a Quote Form ID</label>
                            </th>
                            <td>
                                <input type="number" id="ga4_yith_raq_form_id" name="ga4_yith_raq_form_id" 
                                    value="<?php echo esc_attr($yith_raq_form_id); ?>" min="0" class="small-text" 
                                    placeholder="3" />
                                <p class="description">Your YITH Request a Quote Form ID (found in Gravity Forms). Only works with Gravity Forms.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_conversion_form_ids">Conversion Form ID(s)</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_conversion_form_ids" name="ga4_conversion_form_ids" 
                                    value="<?php echo esc_attr($conversion_form_ids); ?>" class="regular-text" 
                                    placeholder="1, 8, 9" />
                                <p class="description">Your important conversion form IDs in comma-separated format (e.g., "1,2,3,4"). Only works with Gravity Forms.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Debug Mode</th>
                            <td>
                                <label for="ga4_server_side_tagging_debug_mode">
                                    <input type="checkbox" id="ga4_server_side_tagging_debug_mode" name="ga4_server_side_tagging_debug_mode" <?php checked($debug_mode); ?> />
                                    Enable debug logging
                                </label>
                                <p class="description">Enable detailed logging for troubleshooting. Logs can be viewed in the Tagging Logs page.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Extensive Error Logging</th>
                            <td>
                                <label for="ga4_extensive_error_logging">
                                    <input type="checkbox" id="ga4_extensive_error_logging" name="ga4_extensive_error_logging" <?php checked($extensive_error_logging); ?> />
                                    Log all errors to database (including bot detection and rate limiting)
                                </label>
                                <p class="description">When enabled, all errors including bot detection, rate limiting, and other filtered events will be stored in the Event Monitor database for analysis. <strong>Warning:</strong> This can generate large amounts of data with high bot traffic.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_event_batch_size">Event Batch Size</label>
                            </th>
                            <td>
                                <input type="number" id="ga4_event_batch_size" name="ga4_event_batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="5000" step="1" style="width: 100px;" />
                                <span> events per batch</span>
                                <p class="description">Number of events to process in each batch when sending to GA4/Cloudflare. Higher values improve performance but may cause timeouts. Recommended: 1000-2000.</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Tracking configuration -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Tracking configuration</h3>
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
                        <tr id="disable_cf_proxy_row" style="<?php echo $transmission_method === 'wp_rest_endpoint' ? '' : 'display: none;'; ?>">
                            <th scope="row">Disable Cloudflare Proxy</th>
                            <td>
                                <label for="ga4_disable_cf_proxy">
                                    <input type="checkbox" id="ga4_disable_cf_proxy" name="ga4_disable_cf_proxy" <?php checked($disable_cf_proxy); ?> />
                                    Send directly to Google Analytics (bypass Cloudflare Worker)
                                </label>
                                <p class="description">When enabled, events will be sent directly to Google Analytics instead of through your Cloudflare Worker. Only encryption settings will be shown below.</p>
                            </td>
                        </tr>
                        <tr id="cloudflare_worker_url_row" style="<?php echo ($transmission_method === 'wp_rest_endpoint' && $disable_cf_proxy) ? 'display: none;' : ''; ?>">
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

                    <!-- Worker API Key Settings -->
                    <div id="worker_api_settings" style="<?php echo ($transmission_method === 'wp_rest_endpoint' && !$disable_cf_proxy) ? '' : 'display: none;'; ?>">
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="ga4_worker_api_key">Worker API Key</label>
                                </th>
                                <td>
                                    <input type="text" id="ga4_worker_api_key" name="ga4_worker_api_key" 
                                        value="<?php echo esc_attr($worker_api_key); ?>" class="regular-text" 
                                        placeholder="Generate a 32-character API key" />
                                    <button type="button" id="generate_worker_api_key" class="button button-secondary" style="margin-left: 10px;">
                                        Generate API Key
                                    </button>
                                    <button type="button" id="copy_worker_api_key" class="button button-secondary" style="margin-left: 5px;" <?php echo empty($worker_api_key) ? 'disabled' : ''; ?>>
                                        Copy Key
                                    </button>
                                    <p class="description">
                                        <strong>32-character API key for Cloudflare Worker authentication.</strong> 
                                        This key must be configured in your Cloudflare Worker as the <code>API_KEY</code> environment variable.
                                        <br><strong>Important:</strong> Save settings after generating a new key, then update your Worker configuration.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>

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
                            <tr id="encryption_key_row" style="<?php echo $jwt_encryption_enabled ? '' : 'display: none;'; ?>">
                                <th scope="row">
                                    <label for="ga4_jwt_encryption_key">JWT Encryption Key</label>
                                </th>
                                <td>
                                    <input type="text" id="ga4_jwt_encryption_key" name="ga4_jwt_encryption_key" 
                                        value="<?php echo esc_attr($jwt_encryption_key); ?>" class="regular-text" 
                                        placeholder="Generate a 64-character encryption key" />
                                    <button type="button" id="generate_encryption_key" class="button button-secondary" style="margin-left: 10px;">
                                        Generate New Encryption Key
                                    </button>
                                    <button type="button" id="copy_encryption_key" class="button button-secondary" style="margin-left: 5px;" <?php echo empty($jwt_encryption_key) ? 'disabled' : ''; ?>>
                                        Copy Key
                                    </button>
                                    <p class="description">
                                        <strong>256-bit encryption key (64 hexadecimal characters).</strong> 
                                        This same key must be configured in your Cloudflare Worker as the <code>JWT_ENCRYPTION_KEY</code> environment variable.
                                        <br><strong>Important:</strong> Save settings after generating a new key, then update your Worker configuration.
                                    </p>
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
                        <tr>
                            <th scope="row">
                                <label for="ga4_api_secret">GA4 API Secret</label>
                            </th>
                            <td>
                                <input type="password" id="ga4_api_secret" name="ga4_api_secret" 
                                    value="<?php echo esc_attr($api_secret); ?>" class="regular-text" 
                                    placeholder="Enter your GA4 Measurement Protocol API secret" />
                                <p class="description">
                                    Generate this in GA4: Admin → Data Streams → Your Stream → Measurement Protocol API secrets → Create
                                    <br><strong>Important:</strong> This will be used as a Secret variable in your Cloudflare Worker.
                                </p>
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

                <?php if ($test_result) : ?>
                    <div class="ga4-server-side-tagging-admin-section">
                        <h3>Connection Test Results</h3>

                        <div class="ga4-server-side-tagging-test-result <?php echo $test_result['success'] ? 'success' : 'error'; ?>">
                            <p><strong>GA4 API:</strong> <?php echo esc_html($test_result['message']); ?></p>

                            <?php if (isset($test_result['cloudflare']) && $test_result['cloudflare']['tested']) : ?>
                                <p><strong>Cloudflare Worker:</strong>
                                    <?php echo esc_html($test_result['cloudflare']['message']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php submit_button('Save Settings', 'primary', 'save_settings'); ?>

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
                <h3>🔐 Cloudflare Worker Secrets</h3>
                <p>Configure your Cloudflare Worker with secure environment variables:</p>
                <button type="button" id="open_secrets_popup" class="button button-primary" style="width: 100%; margin-bottom: 10px;">
                    📋 Copy Worker Secrets Configuration
                </button>
                <p class="description" style="font-size: 11px; margin: 0;">
                    <strong>Important:</strong> These values must be set as <strong>Secret variables</strong> in Cloudflare Workers for security.
                </p>
            </div>

            <div class="ga4-server-side-tagging-admin-box">
                <h3>Quick Navigation</h3>
                <ul>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging'); ?>">🧪 A/B Testing & Click Tracking</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-cronjobs'); ?>">📊 Event Queue & Cronjobs</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-logs'); ?>">📄 Tagging Logs</a></li>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-events'); ?>">📄 Event Monitor</a></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Cloudflare Worker Secrets Popup -->
<div id="cf_secrets_modal" class="cf-secrets-modal" style="display: none;">
    <div class="cf-secrets-modal-content">
        <div class="cf-secrets-modal-header">
            <h2>🔐 Cloudflare Worker Secrets Configuration</h2>
            <span class="cf-secrets-close">&times;</span>
        </div>
        <div class="cf-secrets-modal-body">
            <div class="cf-secrets-section">
                <h3>🔑 Your Secret Values</h3>
                <div class="cf-secrets-values">
                    <div class="cf-secret-item">
                        <strong>GA4_MEASUREMENT_ID:</strong>
                        <code id="cf_ga4_measurement_id"><?php echo esc_html($measurement_id ?: '[Set your GA4 Measurement ID first]'); ?></code>
                        <button type="button" class="copy-secret-btn" data-target="cf_ga4_measurement_id">Copy</button>
                    </div>
                    <div class="cf-secret-item">
                        <strong>GA4_API_SECRET:</strong>
                        <code id="cf_ga4_api_secret"><?php echo esc_html($api_secret ?: '[Set your GA4 API Secret first - Generate in GA4 Admin]'); ?></code>
                        <button type="button" class="copy-secret-btn" data-target="cf_ga4_api_secret">Copy</button>
                    </div>
                    <?php if ($transmission_method === 'wp_rest_endpoint') : ?>
                    <div class="cf-secret-item">
                        <strong>API_KEY:</strong>
                        <code id="cf_api_key"><?php echo esc_html($worker_api_key ?: '[Generate Worker API key first]'); ?></code>
                        <button type="button" class="copy-secret-btn" data-target="cf_api_key">Copy</button>
                    </div>
                    <?php endif; ?>
                    <?php if ($transmission_method === 'wp_rest_endpoint' && $jwt_encryption_enabled) : ?>
                    <div class="cf-secret-item">
                        <strong>ENCRYPTION_KEY:</strong>
                        <code id="cf_encryption_key"><?php echo esc_html($jwt_encryption_key ?: '[Generate encryption key first]'); ?></code>
                        <button type="button" class="copy-secret-btn" data-target="cf_encryption_key">Copy</button>
                    </div>
                    <?php endif; ?>
                    <div class="cf-secret-item">
                        <strong>ALLOWED_DOMAINS:</strong>
                        <code id="cf_allowed_domains"><?php
                            $domain = parse_url(home_url(), PHP_URL_HOST);
                            $www_domain = strpos($domain, 'www.') === 0 ? substr($domain, 4) : 'www.' . $domain;
                            $domains = $domain . ',' . $www_domain;
                            echo esc_html($domains);
                        ?></code>
                        <button type="button" class="copy-secret-btn" data-target="cf_allowed_domains">Copy</button>
                    </div>
                </div>
            </div>

            <div class="cf-secrets-section">
                <h3>🛠️ How to Set Up Secret Variables in Cloudflare</h3>
                <div class="cf-secrets-instructions">
                    <ol>
                        <li><strong>Go to your Cloudflare Dashboard</strong> → Workers & Pages → Your Worker</li>
                        <li><strong>Click "Settings"</strong> tab</li>
                        <li><strong>Scroll to "Environment Variables"</strong> section</li>
                        <li><strong>For each variable above:</strong>
                            <ul>
                                <li>Click "Add Variable"</li>
                                <li>Enter the variable name (e.g., <code>GA4_MEASUREMENT_ID</code>)</li>
                                <li><strong>⚠️ Important:</strong> Select "Secret" as the type (not "Text")</li>
                                <li>Paste the corresponding value from above</li>
                                <li>Click "Save"</li>
                            </ul>
                        </li>
                        <li><strong>Deploy your Worker</strong> after adding all variables</li>
                    </ol>
                </div>
            </div>

            <div class="cf-secrets-section cf-secrets-warning">
                <h3>⚠️ Security Important</h3>
                <p><strong>Always use "Secret" variables, not "Text" variables!</strong></p>
                <ul>
                    <li><strong>Secret variables</strong> are encrypted and hidden in the dashboard</li>
                    <li><strong>Text variables</strong> are visible in plain text (not secure for sensitive data)</li>
                    <li>Your GA4 credentials and encryption keys should always be secrets</li>
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

    // Toggle encryption settings and worker API settings based on transmission method
    $('#ga4_transmission_method').change(function() {
        if ($(this).val() === 'wp_rest_endpoint') {
            $('#disable_cf_proxy_row').show();
            $('#encryption_settings').show();
            toggleCloudflareFields();
        } else {
            $('#disable_cf_proxy_row').hide();
            $('#worker_api_settings').hide();
            $('#encryption_settings').hide();
            $('#cloudflare_worker_url_row').show();
        }
    });

    // Toggle Cloudflare-related fields based on disable CF proxy checkbox
    $('#ga4_disable_cf_proxy').change(function() {
        toggleCloudflareFields();
    });

    function toggleCloudflareFields() {
        var isWpRestEndpoint = $('#ga4_transmission_method').val() === 'wp_rest_endpoint';
        var disableCfProxy = $('#ga4_disable_cf_proxy').is(':checked');
        
        if (isWpRestEndpoint && disableCfProxy) {
            $('#cloudflare_worker_url_row').hide();
            $('#worker_api_settings').hide();
        } else if (isWpRestEndpoint) {
            $('#cloudflare_worker_url_row').show();
            $('#worker_api_settings').show();
        }
    }

    // Toggle encryption key row based on JWT encryption checkbox
    $('#ga4_jwt_encryption_enabled').change(function() {
        if ($(this).is(':checked')) {
            $('#encryption_key_row').show();
        } else {
            $('#encryption_key_row').hide();
        }
        // Update copy button state
        updateCopyButtonState();
    });

    // Copy encryption key functionality
    $('#copy_encryption_key').click(function(e) {
        e.preventDefault();
        var $input = $('#ga4_jwt_encryption_key');
        var key = $input.val();
        
        if (!key) {
            alert('No encryption key to copy. Please generate a key first.');
            return;
        }

        // Copy to clipboard
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(key).then(function() {
                showCopySuccess($(e.target));
            }).catch(function() {
                fallbackCopyToClipboard(key, $(e.target));
            });
        } else {
            fallbackCopyToClipboard(key, $(e.target));
        }
    });

    // Update copy button state based on input value
    $('#ga4_jwt_encryption_key').on('input', updateCopyButtonState);
    
    function updateCopyButtonState() {
        var $copyBtn = $('#copy_encryption_key');
        var $input = $('#ga4_jwt_encryption_key');
        var hasValue = $input.val().trim().length > 0;
        var encryptionEnabled = $('#ga4_jwt_encryption_enabled').is(':checked');
        
        $copyBtn.prop('disabled', !hasValue || !encryptionEnabled);
    }

    function showCopySuccess($button) {
        var originalText = $button.text();
        $button.text('Copied!').css('color', '#00a32a');
        
        setTimeout(function() {
            $button.text(originalText).css('color', '');
        }, 2000);
    }

    function fallbackCopyToClipboard(text, $button) {
        var textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.top = '0';
        textArea.style.left = '0';
        textArea.style.position = 'fixed';
        
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            showCopySuccess($button);
        } catch (err) {
            alert('Failed to copy to clipboard. Please copy manually: ' + text);
        } finally {
            document.body.removeChild(textArea);
        }
    }

    // Initialize states
    updateCopyButtonState();
    
    // Note: Password toggle button is automatically added by admin.js for #ga4_api_secret

    // Encryption Key generation is handled by admin.js
    
    // Worker API Key generation
    $('#generate_worker_api_key').click(function() {
        // Generate 32-character API key (similar to the example: 688c55fb1612a36774be794c8a385ce1)
        var apiKey = '';
        var chars = '0123456789abcdef';
        for (var i = 0; i < 32; i++) {
            apiKey += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        
        $('#ga4_worker_api_key').val(apiKey);
        $('#copy_worker_api_key').prop('disabled', false);
        updateCopyButtonState();
    });
    
    // Copy Worker API Key
    $('#copy_worker_api_key').click(function() {
        var apiKey = $('#ga4_worker_api_key').val();
        if (apiKey) {
            copyToClipboard(apiKey, $(this), 'Copied!');
        }
    });
    
    // Cloudflare Secrets Modal functionality
    $('#open_secrets_popup').click(function(e) {
        e.preventDefault();
        updateSecretsModal();
        $('#cf_secrets_modal').show();
    });
    
    // Close modal
    $('.cf-secrets-close').click(function() {
        $('#cf_secrets_modal').hide();
    });
    
    // Close modal when clicking outside
    $(window).click(function(e) {
        if (e.target.id === 'cf_secrets_modal') {
            $('#cf_secrets_modal').hide();
        }
    });
    
    // Copy individual secret values
    $('.copy-secret-btn').click(function(e) {
        e.preventDefault();
        var targetId = $(this).data('target');
        var value = $('#' + targetId).text();
        
        if (value.includes('[Set') || value.includes('[Generate')) {
            alert('Please configure this value first before copying.');
            return;
        }
        
        copyToClipboard(value, $(this), 'Copied!');
    });
    
    function updateSecretsModal() {
        // Update values in the modal with current form values
        var measurementId = $('#ga4_measurement_id').val() || '<?php echo esc_js($measurement_id); ?>';
        var apiSecret = $('#ga4_api_secret').val() || '<?php echo esc_js($api_secret); ?>';
        var workerApiKey = $('#ga4_worker_api_key').val() || '<?php echo esc_js($worker_api_key); ?>';
        var encryptionKey = $('#ga4_jwt_encryption_key').val() || '<?php echo esc_js($jwt_encryption_key); ?>';
        var transmissionMethod = $('#ga4_transmission_method').val() || '<?php echo esc_js($transmission_method); ?>';
        var encryptionEnabled = $('#ga4_jwt_encryption_enabled').is(':checked') || <?php echo $jwt_encryption_enabled ? 'true' : 'false'; ?>;
        
        // Update measurement ID
        $('#cf_ga4_measurement_id').text(measurementId || '[Set your GA4 Measurement ID first]');
        
        // Update API secret
        $('#cf_ga4_api_secret').text(apiSecret || '[Set your GA4 API Secret first - Generate in GA4 Admin]');
        
        // Update Worker API key if applicable
        if (transmissionMethod === 'wp_rest_endpoint') {
            $('#cf_api_key').text(workerApiKey || '[Generate Worker API key first]');
            $('.cf-secret-item').has('#cf_api_key').show();
        } else {
            $('.cf-secret-item').has('#cf_api_key').hide();
        }
        
        // Update encryption key if applicable
        if (transmissionMethod === 'wp_rest_endpoint' && encryptionEnabled) {
            $('#cf_encryption_key').text(encryptionKey || '[Generate encryption key first]');
            $('.cf-secret-item').has('#cf_encryption_key').show();
        } else {
            $('.cf-secret-item').has('#cf_encryption_key').hide();
        }
    }
    
    function copyToClipboard(text, $button, successMessage) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function() {
                showCopyFeedback($button, successMessage);
            }).catch(function() {
                fallbackCopy(text, $button, successMessage);
            });
        } else {
            fallbackCopy(text, $button, successMessage);
        }
    }
    
    function fallbackCopy(text, $button, successMessage) {
        var textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.top = '0';
        textArea.style.left = '0';
        textArea.style.position = 'fixed';
        
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();
        
        try {
            document.execCommand('copy');
            showCopyFeedback($button, successMessage);
        } catch (err) {
            alert('Failed to copy. Please copy manually: ' + text);
        } finally {
            document.body.removeChild(textArea);
        }
    }
    
    function showCopyFeedback($button, message) {
        var originalText = $button.text();
        $button.text(message).css('background-color', '#00a32a');
        
        setTimeout(function() {
            $button.text(originalText).css('background-color', '');
        }, 2000);
    }
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

/* Cloudflare Secrets Modal Styles */
.cf-secrets-modal {
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    overflow: auto;
    background-color: rgba(0,0,0,0.5);
}

.cf-secrets-modal-content {
    background-color: #fff;
    margin: 5% auto;
    padding: 0;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    box-shadow: 0 3px 6px rgba(0,0,0,0.3);
    width: 90%;
    max-width: 800px;
    max-height: 85vh;
    overflow-y: auto;
}

.cf-secrets-modal-header {
    padding: 20px;
    background-color: #f1f1f1;
    border-bottom: 1px solid #ddd;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.cf-secrets-modal-header h2 {
    margin: 0;
    font-size: 18px;
}

.cf-secrets-close {
    color: #666;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    line-height: 1;
}

.cf-secrets-close:hover {
    color: #000;
}

.cf-secrets-modal-body {
    padding: 20px;
}

.cf-secrets-section {
    margin-bottom: 25px;
}

.cf-secrets-section h3 {
    margin-top: 0;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 1px solid #eee;
    font-size: 16px;
}

.cf-secrets-code-block {
    background-color: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 4px;
    padding: 15px;
    margin-bottom: 10px;
}

.cf-secrets-code-block pre {
    margin: 0;
    font-family: 'Courier New', Consolas, Monaco, monospace;
    font-size: 13px;
    line-height: 1.4;
    color: #333;
}

.cf-secret-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
    flex-wrap: wrap;
}

.cf-secret-item:last-child {
    border-bottom: none;
}

.cf-secret-item strong {
    min-width: 160px;
    font-weight: 600;
    color: #333;
}

.cf-secret-item code {
    background-color: #f8f9fa;
    padding: 4px 8px;
    border-radius: 3px;
    border: 1px solid #e9ecef;
    font-family: 'Courier New', Consolas, Monaco, monospace;
    font-size: 12px;
    flex: 1;
    min-width: 200px;
    word-break: break-all;
}

.copy-secret-btn {
    background-color: #0073aa;
    color: white;
    border: 1px solid #0073aa;
    padding: 4px 12px;
    border-radius: 3px;
    font-size: 12px;
    cursor: pointer;
    white-space: nowrap;
}

.copy-secret-btn:hover {
    background-color: #005a87;
}

.cf-secret-note {
    font-size: 11px;
    color: #d63384;
    font-weight: 600;
}

.cf-secrets-instructions {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 4px;
    border-left: 4px solid #0073aa;
}

.cf-secrets-instructions ol {
    margin: 0;
    padding-left: 20px;
}

.cf-secrets-instructions li {
    margin-bottom: 8px;
}

.cf-secrets-instructions ul {
    margin: 5px 0;
    padding-left: 20px;
}

.cf-secrets-instructions code {
    background-color: #e9ecef;
    padding: 2px 4px;
    border-radius: 2px;
    font-family: 'Courier New', Consolas, Monaco, monospace;
    font-size: 12px;
}

.cf-secrets-warning {
    background-color: #fff3cd;
    border: 1px solid #ffecb5;
    border-radius: 4px;
    padding: 15px;
    border-left: 4px solid #ffc107;
}

.cf-secrets-warning h3 {
    color: #856404;
    margin-top: 0;
}

.cf-secrets-warning p {
    color: #856404;
    margin-bottom: 10px;
}

.cf-secrets-warning ul {
    color: #856404;
    margin: 0;
    padding-left: 20px;
}
</style>
