<?php
/**
 * Provide a admin area view for the plugin logs
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
            <h2>GA4 Server-Side Tagging Logs</h2>
            <p>View and manage logs for debugging purposes.</p>
        </div>
        
        <div class="ga4-server-side-tagging-admin-content">
            <div class="ga4-server-side-tagging-admin-section">
                <div class="ga4-server-side-tagging-admin-actions">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <div>
                            <strong>Current Date/Time:</strong> 
                            <span id="current-datetime"><?php echo esc_html( current_time( 'Y-m-d H:i:s T' ) ); ?></span>
                            <small style="color: #666; margin-left: 10px;">(Updates every second)</small>
                        </div>
                        <form method="post" action="" style="margin: 0;">
                            <?php wp_nonce_field( 'ga4_server_side_tagging_logs' ); ?>
                            <input type="submit" name="ga4_clear_logs" class="button-secondary" value="Clear Logs" onclick="return confirm('Are you sure you want to clear all logs?');" />
                        </form>
                    </div>
                </div>
                
                <div class="ga4-server-side-tagging-logs">
                    <?php if ( empty( $log_content ) ) : ?>
                        <p>No logs available. Enable debug mode in the settings to start logging events.</p>
                    <?php else : ?>
                        <div class="ga4-log-info">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; padding: 10px; background: #fff; border: 1px solid #ccd0d4; border-radius: 4px;">
                                <div>
                                    <strong>📋 Showing:</strong> Last 500 entries (newest first) 
                                    <span style="color: #666;">• Auto-cleanup: Entries older than 14 days are automatically removed</span>
                                </div>
                                <div>
                                    <strong>🕐 Reference Time:</strong> 
                                    <span id="reference-time" style="font-family: 'Courier New', monospace; background: #f0f0f1; padding: 2px 6px; border-radius: 3px; border: 1px solid #c3c4c7;">
                                        <?php echo esc_html( current_time( 'Y-m-d H:i:s T' ) ); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="ga4-server-side-tagging-log-viewer">
                            <pre><?php echo esc_html( $log_content ); ?></pre>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="ga4-server-side-tagging-admin-sidebar">
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Debug Information</h3>
                <ul>
                    <li><strong>Debug Mode:</strong> <?php echo get_option( 'ga4_server_side_tagging_debug_mode', false ) ? 'Enabled' : 'Disabled'; ?></li>
                    <li><strong>Plugin Version:</strong> <?php echo esc_html( GA4_SERVER_SIDE_TAGGING_VERSION ); ?></li>
                    <li><strong>WordPress Version:</strong> <?php echo esc_html( get_bloginfo( 'version' ) ); ?></li>
                    <li><strong>PHP Version:</strong> <?php echo esc_html( phpversion() ); ?></li>
                    <?php if ( class_exists( 'WooCommerce' ) ) : ?>
                        <li><strong>WooCommerce Version:</strong> <?php echo esc_html( WC()->version ); ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            
            <div class="ga4-server-side-tagging-admin-box">
                <h3>Log Management</h3>
                <p>Logs are formatted as follows:</p>
                <code>[TIMESTAMP] [LEVEL] Message</code>
                
                <h4 style="margin-top: 15px;">Display Settings:</h4>
                <ul style="margin: 5px 0;">
                    <li><strong>Order:</strong> Newest entries first for easier debugging</li>
                    <li><strong>Limit:</strong> Shows last 500 entries</li>
                    <li><strong>Auto-cleanup:</strong> Entries older than 14 days are automatically removed</li>
                </ul>
                
                <h4 style="margin-top: 15px;">Log Levels:</h4>
                <ul style="margin: 5px 0;">
                    <li><strong>INFO:</strong> General information</li>
                    <li><strong>WARNING:</strong> Potential issues</li>
                    <li><strong>ERROR:</strong> Critical errors</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
function updateCurrentTime() {
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
    
    const element = document.getElementById('current-datetime');
    if (element) {
        element.textContent = timeString;
    }
    
    const referenceElement = document.getElementById('reference-time');
    if (referenceElement) {
        referenceElement.textContent = timeString;
    }
}

// Update time immediately and then every second
updateCurrentTime();
setInterval(updateCurrentTime, 1000);
</script> 