<?php
/**
 * Provide a admin area view for the plugin - Features
 *
 * This file is used to markup the feature admin-facing aspects of the plugin.
 * Settings have been moved to the Settings page.
 *
 * @since      2.0.0
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
            <h2>GA4 Server-Side Tagging Features</h2>
            <p>Configure A/B testing, click tracking, and test your connections. For basic settings, visit the <a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-settings'); ?>">Settings page</a>.</p>
        </div>

        <div class="ga4-server-side-tagging-admin-content">
            <form method="post" action="">
                <?php wp_nonce_field('ga4_admin_features_form'); ?>
                <input type="hidden" name="form_type" value="features" />

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

                <?php submit_button('Save Features', 'primary', 'save_features'); ?>

            </form>
        </div>

        <div class="ga4-server-side-tagging-admin-sidebar">
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Quick Links</h3>
                <ul>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-settings'); ?>">⚙️ Settings</a> - Configure basic GA4 and consent settings</li>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-cronjobs'); ?>">📊 Cronjobs</a> - Monitor event processing queue</li>
                    <li><a href="<?php echo admin_url('admin.php?page=ga4-server-side-tagging-logs'); ?>">📄 Error Logs</a> - View debug and error logs</li>
                </ul>
            </div>

            <div class="ga4-server-side-tagging-admin-box">
                <h3>A/B Testing Guide</h3>
                <p>A/B Testing allows you to track different variations of elements:</p>
                <ol>
                    <li>Enable A/B testing functionality</li>
                    <li>Create test configurations with CSS classes</li>
                    <li>Add the CSS classes to your theme elements</li>
                    <li>View results in Google Analytics 4</li>
                </ol>
                <p><strong>Example:</strong> Create buttons with classes <code>.button-red</code> and <code>.button-blue</code> to test which color performs better.</p>
            </div>

            <div class="ga4-server-side-tagging-admin-box">
                <h3>Click Tracking Guide</h3>
                <p>Click tracking lets you monitor interactions with specific elements:</p>
                <ol>
                    <li>Enable click tracking functionality</li>
                    <li>Define event names and CSS selectors</li>
                    <li>Events are automatically sent to GA4</li>
                    <li>View click data in GA4 Events reports</li>
                </ol>
                <p><strong>Example:</strong> Track PDF downloads with selector <code>.download-pdf</code> and event name <code>pdf_download</code>.</p>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript functionality is handled by admin/js/ga4-server-side-tagging-admin.js -->

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

.ab-test-item, .click-track-item {
    border: 1px solid #ddd;
    margin: 15px 0;
    padding: 15px;
    background: #fafafa;
}
</style>