# GA4 Server-Side Tagging for WordPress and WooCommerce

A WordPress plugin that provides server-side tagging for Google Analytics 4 (GA4), fully compatible with WordPress and WooCommerce, and optimized for Cloudflare hosting.

## Features

- Server-side tracking for GA4 events
- Full WooCommerce integration for e-commerce tracking
- Cloudflare Worker integration for improved performance and privacy
- Comprehensive debugging and logging system
- Admin interface for easy configuration
- Support for both client-side and server-side tracking

## Requirements

- WordPress 5.2 or higher
- PHP 7.2 or higher
- WooCommerce 4.0 or higher (for e-commerce tracking)
- Google Analytics 4 property with Measurement ID and API Secret
- Cloudflare account (optional, for server-side tagging)

## Installation

1. Upload the `ga4-server-side-tagging` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the 'GA4 Tagging' menu in your WordPress admin
4. Enter your GA4 Measurement ID and API Secret
5. Configure additional settings as needed

## Configuration

### Basic Setup

1. Create a GA4 property in your Google Analytics account
2. Get your Measurement ID (starts with G-)
3. Create an API Secret in the GA4 property settings
4. Enter these values in the plugin settings

### Cloudflare Worker Setup (Optional)

For optimal server-side tagging, you can use a Cloudflare Worker:

1. Create a Cloudflare Worker in your Cloudflare dashboard
2. Use the example script provided in `cloudflare-worker-example.js`
3. Replace the placeholder values with your actual GA4 credentials
4. Deploy the worker and copy the worker URL
5. Enter the worker URL in the plugin settings

## Usage

Once configured, the plugin will automatically:

- Track page views
- Track WooCommerce events (product views, add to cart, checkout, purchases)
- Track outbound links
- Track form submissions

All events are sent to GA4 using both client-side tracking (via gtag.js) and server-side tracking (via the Measurement Protocol).

## Debugging

The plugin includes a comprehensive logging system:

1. Enable debug mode in the plugin settings
2. View logs in the 'Logs' section of the plugin admin
3. Clear logs as needed

## Advanced Configuration

### Custom Events

You can track custom events using JavaScript:

```javascript
// Track a custom event
if (typeof GA4ServerSideTagging !== 'undefined') {
    GA4ServerSideTagging.trackEvent('custom_event', {
        custom_parameter: 'value'
    });
}
```

### Server-Side Tracking Only

If you want to use server-side tracking only (no client-side tracking):

1. Keep the GA4 Measurement ID and API Secret in the plugin settings
2. Add the following code to your theme's functions.php file:

```php
add_action('wp_head', function() {
    // Remove default GA4 tracking code
    remove_action('wp_head', array(GA4_Server_Side_Tagging_Public::instance(), 'add_ga4_tracking_code'));
}, 5);
```

## Support

For support, please create an issue in the plugin's GitHub repository or contact the plugin author.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by Jacht Digital Marketing. 