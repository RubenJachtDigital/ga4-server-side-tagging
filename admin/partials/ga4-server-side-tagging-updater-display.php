<?php

use GA4ServerSideTagging\Updater\Updater_Config;

/**
 * Admin interface for GitHub updater configuration
 *
 * @package GA4ServerSideTagging
 */

// Security check
if (!defined('ABSPATH')) {
    exit;
}

// Ensure user has proper capabilities
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

// Handle form submissions
$message = '';
$message_type = '';

if (isset($_POST['test_config']) && wp_verify_nonce($_POST['updater_nonce'], 'ga4_updater_config')) {
    $test_results = Updater_Config::test_config();
    if ($test_results['valid']) {
        $message = 'Configuration test successful! GitHub repository is accessible.';
        $message_type = 'success';
        if (!empty($test_results['warnings'])) {
            $message .= ' Warnings: ' . implode(', ', $test_results['warnings']);
        }
    } else {
        $message = 'Configuration test failed: ' . implode(', ', $test_results['errors']);
        $message_type = 'error';
    }
}

if (isset($_POST['check_updates']) && wp_verify_nonce($_POST['updater_nonce'], 'ga4_updater_config')) {
    // Force clear WordPress update cache
    delete_site_transient('update_plugins');
    delete_transient('ga4_github_updater_' . md5(plugin_basename(GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'ga4-server-side-tagging.php')) . '_version');
    delete_transient('ga4_github_updater_' . md5(plugin_basename(GA4_SERVER_SIDE_TAGGING_PLUGIN_DIR . 'ga4-server-side-tagging.php')) . '_changelog');

    // Trigger WordPress to check for plugin updates
    wp_update_plugins();

    $message = 'Update check completed! If a new version is available, it will appear in the WordPress Plugins page.';
    $message_type = 'success';
}

// Load current configuration
$config = Updater_Config::load_config() ?: Updater_Config::get_defaults();
$is_configured = Updater_Config::is_configured();
$is_enabled = Updater_Config::is_enabled();

?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?> - Auto-Update Configuration</h1>

    <?php if ($message): ?>
        <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
            <p><?php echo esc_html($message); ?></p>
        </div>
    <?php endif; ?>

    <div class="ga4-admin-container">
        <div class="ga4-admin-section">
            <h2>GitHub Repository Configuration</h2>
            <p>Configuration is loaded from your <code>.env</code> file or server environment variables. To modify these settings, edit your <code>.env</code> file.</p>

            <div class="ga4-status-cards">
                <div class="ga4-status-card <?php echo $is_configured ? 'status-success' : 'status-warning'; ?>">
                    <h3>Configuration Status</h3>
                    <p><?php echo $is_configured ? 'Configured ✓' : 'Not Configured ⚠'; ?></p>
                </div>
                <div class="ga4-status-card <?php echo $is_enabled ? 'status-success' : 'status-error'; ?>">
                    <h3>Auto-Updates</h3>
                    <p><?php echo $is_enabled ? 'Enabled ✓' : 'Disabled ✗'; ?></p>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('ga4_updater_config', 'updater_nonce'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">GitHub Username/Organization</th>
                        <td>
                            <input type="text"
                                   value="<?php echo esc_attr($config['username'] ?? 'Not configured'); ?>"
                                   class="regular-text"
                                   readonly
                                   style="background-color: #f9f9f9; cursor: not-allowed;" />
                            <p class="description">
                                <strong>Read-only:</strong> Set <code>GITHUB_USERNAME</code> in your .env file
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Repository Name</th>
                        <td>
                            <input type="text"
                                   value="<?php echo esc_attr($config['repo'] ?? 'Not configured'); ?>"
                                   class="regular-text"
                                   readonly
                                   style="background-color: #f9f9f9; cursor: not-allowed;" />
                            <p class="description">
                                <strong>Read-only:</strong> Set <code>GITHUB_REPO</code> in your .env file
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">GitHub Personal Access Token</th>
                        <td>
                            <input type="password"
                                   value="<?php echo !empty($config['token']) ? str_repeat('•', 40) : 'Not configured'; ?>"
                                   class="regular-text"
                                   readonly
                                   style="background-color: #f9f9f9; cursor: not-allowed;" />
                            <p class="description">
                                <strong>Read-only:</strong> Set <code>GITHUB_TOKEN</code> in your .env file<br>
                                <strong>For private repos:</strong> Token with "repo" scope is required<br>
                                <strong>For public repos:</strong> Optional but recommended for higher API limits<br>
                                <a href="https://github.com/settings/tokens" target="_blank">Generate a token here</a>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-Updates Status</th>
                        <td>
                            <span class="ga4-status-badge <?php echo $config['enabled'] ? 'enabled' : 'disabled'; ?>">
                                <?php echo $config['enabled'] ? 'Enabled ✓' : 'Disabled (Missing Configuration)'; ?>
                            </span>
                            <p class="description">
                                Auto-updates are automatically enabled when GitHub username and repository are configured.
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="ga4-form-actions">
                    <?php submit_button('Test Configuration', 'secondary', 'test_config', false); ?>
                    <?php if ($is_configured && $is_enabled): ?>
                        <?php submit_button('Check for Updates Now', 'primary', 'check_updates', false, array(
                            'style' => 'background: #2271b1; border-color: #2271b1; color: #fff;'
                        )); ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <div class="ga4-admin-section">
            <h2>Setup Instructions</h2>

            <div class="ga4-setup-steps">
                <div class="ga4-step">
                    <h3>1. Create .env File</h3>
                    <p>Create a <code>.env</code> file in your plugin root directory:</p>
                    <pre><code>GITHUB_USERNAME=your-username
GITHUB_REPO=ga4-server-side-tagging
GITHUB_TOKEN=optional-access-token</code></pre>
                    <p><strong>Important:</strong> Copy <code>.env.example</code> to <code>.env</code> and never commit the <code>.env</code> file!</p>
                </div>

                <div class="ga4-step">
                    <h3>2. GitHub Repository</h3>
                    <ul>
                        <li>Push your plugin code to GitHub</li>
                        <li>For private repos: Add GitHub token with "repo" scope</li>
                        <li>For public repos: Token is optional but recommended</li>
                        <li>Ensure repository name matches your .env configuration</li>
                    </ul>
                </div>

                <div class="ga4-step">
                    <h3>3. Release Process</h3>
                    <p>Updates are triggered by version number changes:</p>
                    <ol>
                        <li>Update version in <code>ga4-server-side-tagging.php</code></li>
                        <li>Push to master branch</li>
                        <li>GitHub Actions automatically creates release</li>
                        <li>All sites receive update notification within 12 hours</li>
                    </ol>
                </div>

                <div class="ga4-step">
                    <h3>4. Deploy to Client Sites</h3>
                    <ul>
                        <li>Upload plugin files to each client site</li>
                        <li>Create <code>.env</code> file on each site</li>
                        <li>Test configuration using buttons above</li>
                        <li>Verify updates appear in WordPress plugins page</li>
                    </ul>
                </div>
            </div>
        </div>

        <?php if ($is_configured): ?>
        <div class="ga4-admin-section">
            <h2>Current Status</h2>

            <table class="widefat fixed">
                <thead>
                    <tr>
                        <th>Setting</th>
                        <th>Value</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Repository URL</td>
                        <td>
                            <a href="https://github.com/<?php echo esc_attr($config['username'] . '/' . $config['repo']); ?>" target="_blank">
                                <?php echo esc_html($config['username'] . '/' . $config['repo']); ?>
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td>GitHub Token</td>
                        <td><?php echo empty($config['token']) ? 'Not configured' : 'Configured ✓'; ?></td>
                    </tr>
                    <tr>
                        <td>Auto-Updates</td>
                        <td><?php echo $config['enabled'] ? 'Enabled ✓' : 'Disabled'; ?></td>
                    </tr>
                    <tr>
                        <td>Current Version</td>
                        <td><?php echo esc_html(GA4_SERVER_SIDE_TAGGING_VERSION); ?></td>
                    </tr>
                    <tr>
                        <td>Last Update Check</td>
                        <td>
                            <?php
                            $last_checked = get_site_transient('update_plugins');
                            if ($last_checked && isset($last_checked->last_checked)) {
                                echo esc_html(human_time_diff($last_checked->last_checked) . ' ago');
                            } else {
                                echo 'Never';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td>WordPress Updates Page</td>
                        <td>
                            <a href="<?php echo admin_url('plugins.php'); ?>" class="button button-secondary">
                                View Plugin Updates
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>

            <?php if ($is_enabled): ?>
            <div style="margin-top: 20px; padding: 15px; background: #e7f3ff; border: 1px solid #b8daff; border-radius: 4px;">
                <h4 style="margin-top: 0;">Quick Update Check</h4>
                <p>Use the "Check for Updates Now" button above to immediately check for new plugin versions. This bypasses the normal 12-hour WordPress update cycle.</p>
                <p><strong>After checking:</strong> Go to <a href="<?php echo admin_url('plugins.php'); ?>">Plugins page</a> to see if updates are available.</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.ga4-admin-container {
    max-width: 1200px;
}

.ga4-admin-section {
    background: #fff;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
    padding: 20px;
    margin: 20px 0;
}

.ga4-status-cards {
    display: flex;
    gap: 20px;
    margin: 20px 0;
}

.ga4-status-card {
    flex: 1;
    padding: 15px;
    border-radius: 4px;
    text-align: center;
}

.ga4-status-card.status-success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.ga4-status-card.status-warning {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    color: #856404;
}

.ga4-status-card.status-error {
    background: #f8d7da;
    border: 1px solid #f1b0b7;
    color: #721c24;
}

.ga4-status-card h3 {
    margin: 0 0 10px 0;
    font-size: 14px;
    font-weight: 600;
}

.ga4-status-card p {
    margin: 0;
    font-weight: 500;
}

.ga4-form-actions {
    margin-top: 20px;
}

.ga4-form-actions .button {
    margin-right: 10px;
}

.ga4-setup-steps {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.ga4-step {
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    border-radius: 4px;
    padding: 20px;
}

.ga4-step h3 {
    margin-top: 0;
    color: #495057;
}

.ga4-step pre {
    background: #e9ecef;
    padding: 10px;
    border-radius: 4px;
    font-size: 12px;
    overflow-x: auto;
}

.ga4-step code {
    background: #e9ecef;
    padding: 2px 4px;
    border-radius: 3px;
    font-size: 12px;
}

.ga4-status-badge {
    padding: 6px 12px;
    border-radius: 4px;
    font-weight: 500;
    font-size: 14px;
}

.ga4-status-badge.enabled {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.ga4-status-badge.disabled {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f1b0b7;
}

@media (max-width: 768px) {
    .ga4-status-cards {
        flex-direction: column;
    }

    .ga4-setup-steps {
        grid-template-columns: 1fr;
    }
}
</style>