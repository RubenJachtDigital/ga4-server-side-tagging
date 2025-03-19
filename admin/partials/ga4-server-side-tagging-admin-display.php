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
                
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Cloudflare Integration</h3>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="ga4_cloudflare_worker_url">Cloudflare Worker URL</label>
                            </th>
                            <td>
                                <input type="url" id="ga4_cloudflare_worker_url" name="ga4_cloudflare_worker_url" value="<?php echo esc_url( $cloudflare_worker_url ); ?>" class="regular-text" />
                                <p class="description">URL to your Cloudflare Worker for server-side tagging (optional)</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
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
                                <p class="description">Anonymize user IP addresses for GDPR compliance</p>
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
                    <input type="submit" name="ga4_test_connection" class="button-secondary" value="Test Connection" />
                </p>
            </form>
            
            <?php if ( $test_result ) : ?>
                <div class="ga4-server-side-tagging-admin-section">
                    <h3>Connection Test Results</h3>
                    
                    <div class="ga4-server-side-tagging-test-result <?php echo $test_result['success'] ? 'success' : 'error'; ?>">
                        <p><strong>GA4 API:</strong> <?php echo esc_html( $test_result['message'] ); ?></p>
                        
                        <?php if ( isset( $test_result['cloudflare'] ) && $test_result['cloudflare']['tested'] ) : ?>
                            <p><strong>Cloudflare Worker:</strong> <?php echo esc_html( $test_result['cloudflare']['message'] ); ?></p>
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
                    <li>Configure your Cloudflare Worker (optional)</li>
                    <li>Adjust tracking options as needed</li>
                    <li>Save settings and test the connection</li>
                </ol>
            </div>
            
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Cloudflare Worker Setup</h3>
                <p>To use server-side tagging with Cloudflare:</p>
                <ol>
                    <li>Create a Cloudflare Worker</li>
                    <li>Deploy the GA4 proxy code to your worker</li>
                    <li>Enter the worker URL in the settings</li>
                </ol>
                <p><a href="https://developers.cloudflare.com/workers/" target="_blank">Learn more about Cloudflare Workers</a></p>
            </div>
        </div>
    </div>
</div> 