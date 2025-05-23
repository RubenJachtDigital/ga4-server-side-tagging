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
if ( ! defined( 'WPINC' ) ) {
    die;
}
?>

<div class="wrap">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    
    <?php settings_errors(); ?>
    
    <div class="ga4-server-side-tagging-admin">
        <div class="ga4-server-side-tagging-admin-header">
            <h2>GA4 Server-Side Tagging Settings</h2>
            <p>Configure your GA4 server-side tagging settings for WordPress and WooCommerce.</p>
        </div>
        
        <div class="ga4-server-side-tagging-admin-content">
            <form method="post" action="">
                <?php wp_nonce_field( 'ga4_server_side_tagging_settings' ); ?>
                
                <!-- GA4 Analytics Settings -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Google Analytics 4 Configuration</h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_measurement_id">Measurement ID</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_measurement_id" name="ga4_measurement_id" value="<?php echo esc_attr( $measurement_id ); ?>" class="regular-text" />
                                <p class="description">Your GA4 Measurement ID (e.g., G-XXXXXXXXXX)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_api_secret">API Secret</label>
                            </th>
                            <td>
                                <input type="password" id="ga4_api_secret" name="ga4_api_secret" value="<?php echo esc_attr( $api_secret ); ?>" class="regular-text" />
                                <p class="description">Your GA4 API Secret for server-side events</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Google Ads Settings -->
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Google Ads Conversion Tracking</h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_google_ads_conversion_id">Google Ads Conversion ID</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_google_ads_conversion_id" name="ga4_google_ads_conversion_id" 
                                       value="<?php echo esc_attr( $google_ads_conversion_id ); ?>" 
                                       placeholder="AW-123456789" class="regular-text" />
                                <p class="description">Your Google Ads Conversion ID (e.g., AW-123456789)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_google_ads_purchase_label">Purchase Conversion Label</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_google_ads_purchase_label" name="ga4_google_ads_purchase_label" 
                                       value="<?php echo esc_attr( $google_ads_purchase_label ); ?>" 
                                       placeholder="AbCdEfGhIj12345678" class="regular-text" />
                                <p class="description">Conversion label for purchase/order completion events</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_google_ads_lead_label">Lead Conversion Label</label>
                            </th>
                            <td>
                                <input type="text" id="ga4_google_ads_lead_label" name="ga4_google_ads_lead_label" 
                                       value="<?php echo esc_attr( $google_ads_lead_label ); ?>" 
                                       placeholder="XyZaBc123456789" class="regular-text" />
                                <p class="description">Conversion label for lead generation events (quotes, form submissions)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_google_ads_worker_url">Google Ads Worker URL</label>
                            </th>
                            <td>
                                <input type="url" id="ga4_google_ads_worker_url" name="ga4_google_ads_worker_url" 
                                       value="<?php echo esc_url( $google_ads_worker_url ); ?>" 
                                       placeholder="https://your-ads-worker.your-subdomain.workers.dev" class="regular-text" />
                                <p class="description">URL to your Cloudflare Worker for Google Ads conversions</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h4>Conversion Values</h4>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_google_ads_default_lead_value">Default Lead Value</label>
                            </th>
                            <td>
                                <input type="number" id="ga4_google_ads_default_lead_value" name="ga4_google_ads_default_lead_value" 
                                       value="<?php echo esc_attr( $google_ads_default_lead_value ); ?>" 
                                       step="0.01" min="0" class="small-text" />
                                <p class="description">Default value for lead conversions (form submissions)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_google_ads_default_quote_value">Default Quote Value</label>
                            </th>
                            <td>
                                <input type="number" id="ga4_google_ads_default_quote_value" name="ga4_google_ads_default_quote_value" 
                                       value="<?php echo esc_attr( $google_ads_default_quote_value ); ?>" 
                                       step="0.01" min="0" class="small-text" />
                                <p class="description">Default value for quote request conversions</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_google_ads_phone_call_value">Phone Call Value</label>
                            </th>
                            <td>
                                <input type="number" id="ga4_google_ads_phone_call_value" name="ga4_google_ads_phone_call_value" 
                                       value="<?php echo esc_attr( $google_ads_phone_call_value ); ?>" 
                                       step="0.01" min="0" class="small-text" />
                                <p class="description">Value for phone call conversions</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="ga4_google_ads_email_click_value">Email Click Value</label>
                            </th>
                            <td>
                                <input type="number" id="ga4_google_ads_email_click_value" name="ga4_google_ads_email_click_value" 
                                       value="<?php echo esc_attr( $google_ads_email_click_value ); ?>" 
                                       step="0.01" min="0" class="small-text" />
                                <p class="description">Value for email click conversions</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h4>Google Ads Tracking Options</h4>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Additional Conversions</th>
                            <td>
                                <label for="ga4_google_ads_track_phone_calls">
                                    <input type="checkbox" id="ga4_google_ads_track_phone_calls" name="ga4_google_ads_track_phone_calls" <?php checked( $google_ads_track_phone_calls ); ?> />
                                    Track phone calls as conversions
                                </label>
                                <br />
                                <label for="ga4_google_ads_track_email_clicks">
                                    <input type="checkbox" id="ga4_google_ads_track_email_clicks" name="ga4_google_ads_track_email_clicks" <?php checked( $google_ads_track_email_clicks ); ?> />
                                    Track email clicks as conversions
                                </label>
                                <p class="description">Track tel: and mailto: link clicks as conversion events</p>
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
                                <input type="url" id="ga4_cloudflare_worker_url" name="ga4_cloudflare_worker_url" value="<?php echo esc_url( $cloudflare_worker_url ); ?>" class="regular-text" />
                                <p class="description">URL to your Cloudflare Worker for GA4 server-side tagging (optional)</p>
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
                                    <input type="checkbox" id="ga4_use_server_side" name="ga4_use_server_side" <?php checked( $use_server_side ); ?> />
                                    Enable server-side tagging
                                </label>
                                <p class="description">Send events to GA4 from the server instead of the browser</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">E-Commerce Tracking</th>
                            <td>
                                <label for="ga4_ecommerce_tracking">
                                    <input type="checkbox" id="ga4_ecommerce_tracking" name="ga4_ecommerce_tracking" <?php checked( $ecommerce_tracking ); ?> />
                                    Enable e-commerce tracking
                                </label>
                                <p class="description">Track WooCommerce events (product views, add to cart, checkout, purchases)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Logged-in Users</th>
                            <td>
                                <label for="ga4_track_logged_in_users">
                                    <input type="checkbox" id="ga4_track_logged_in_users" name="ga4_track_logged_in_users" <?php checked( $track_logged_in_users ); ?> />
                                    Track logged-in users
                                </label>
                                <p class="description">Whether to track logged-in users (including administrators)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">IP Anonymization</th>
                            <td>
                                <label for="ga4_anonymize_ip">
                                    <input type="checkbox" id="ga4_anonymize_ip" name="ga4_anonymize_ip" <?php checked( $anonymize_ip ); ?> />
                                    Anonymize IP addresses
                                </label>
                                <p class="description">Anonymize user IP addresses for GDPR compliance.(Only tracks continent when enabled)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Debug Mode</th>
                            <td>
                                <label for="ga4_server_side_tagging_debug_mode">
                                    <input type="checkbox" id="ga4_server_side_tagging_debug_mode" name="ga4_server_side_tagging_debug_mode" <?php checked( $debug_mode ); ?> />
                                    Enable debug mode
                                </label>
                                <p class="description">Log events and enable debug mode in GA4</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <p class="submit">
                    <input type="submit" name="ga4_server_side_tagging_settings_submit" class="button-primary" value="Save Settings" />
                    <input type="submit" name="ga4_test_connection" class="button-secondary" value="Test GA4 Connection" />
                    <input type="submit" name="ga4_test_google_ads_connection" class="button-secondary" value="Test Google Ads Connection" />
                </p>
            </form>
            
            <?php if ( $test_result ) : ?>
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>GA4 Connection Test Results</h3>
                    
                    <div class="ga4-server-side-tagging-test-result <?php echo $test_result['success'] ? 'success' : 'error'; ?>">
                        <p><strong>GA4 API:</strong> <?php echo esc_html( $test_result['message'] ); ?></p>
                        
                        <?php if ( isset( $test_result['cloudflare'] ) && $test_result['cloudflare']['tested'] ) : ?>
                            <p><strong>Cloudflare Worker:</strong> <?php echo esc_html( $test_result['cloudflare']['message'] ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ( $google_ads_test_result ) : ?>
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Google Ads Connection Test Results</h3>
                    
                    <div class="ga4-server-side-tagging-test-result <?php echo $google_ads_test_result['success'] ? 'success' : 'error'; ?>">
                        <p><strong>Google Ads Worker:</strong> <?php echo esc_html( $google_ads_test_result['message'] ); ?></p>
                        
                        <?php if ( isset( $google_ads_test_result['response'] ) && $google_ads_test_result['response'] ) : ?>
                            <details>
                                <summary>Response Details</summary>
                                <pre><?php echo esc_html( $google_ads_test_result['response'] ); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="ga4-server-side-tagging-admin-sidebar">
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Getting Started</h3>
                <ol>
                    <li>Enter your GA4 Measurement ID and API Secret</li>
                    <li>Configure your Google Ads Conversion ID and labels</li>
                    <li>Set up your Cloudflare Workers (optional)</li>
                    <li>Adjust tracking options as needed</li>
                    <li>Save settings and test the connections</li>
                </ol>
            </div>
            
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Google Ads Setup</h3>
                <p>To track Google Ads conversions:</p>
                <ol>
                    <li>Find your Conversion ID in Google Ads</li>
                    <li>Create conversion actions and get labels</li>
                    <li>Set up a Cloudflare Worker for conversions</li>
                    <li>Configure conversion values</li>
                </ol>
                <p><a href="https://support.google.com/google-ads/answer/1722054" target="_blank">Learn about Google Ads conversions</a></p>
            </div>
            
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Cloudflare Worker Setup</h3>
                <p>To use server-side tagging with Cloudflare:</p>
                <ol>
                    <li>Create separate Cloudflare Workers for GA4 and Google Ads</li>
                    <li>Deploy the respective proxy code to each worker</li>
                    <li>Enter the worker URLs in the settings</li>
                </ol>
                <p><a href="https://developers.cloudflare.com/workers/" target="_blank">Learn more about Cloudflare Workers</a></p>
            </div>
        </div>
    </div>
</div>