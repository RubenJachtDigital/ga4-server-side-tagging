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
                    <form method="post" action="">
                        <?php wp_nonce_field( 'ga4_server_side_tagging_logs' ); ?>
                        <input type="submit" name="ga4_clear_logs" class="button-secondary" value="Clear Logs" onclick="return confirm('Are you sure you want to clear all logs?');" />
                    </form>
                </div>
                
                <div class="ga4-server-side-tagging-logs">
                    <?php if ( empty( $log_content ) ) : ?>
                        <p>No logs available. Enable debug mode in the settings to start logging events.</p>
                    <?php else : ?>
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
                <h3>Log Format</h3>
                <p>Logs are formatted as follows:</p>
                <code>[TIMESTAMP] [LEVEL] Message</code>
                <p>Log levels include:</p>
                <ul>
                    <li><strong>INFO:</strong> General information</li>
                    <li><strong>WARNING:</strong> Potential issues</li>
                    <li><strong>ERROR:</strong> Critical errors</li>
                </ul>
            </div>
        </div>
    </div>
</div> 