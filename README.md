# GA4 Server-Side Tagging Plugin

A comprehensive WordPress plugin that provides server-side Google Analytics 4 (GA4) tracking with GDPR compliance, advanced bot detection, and Cloudflare Worker integration.

## Overview

This plugin enables server-side tracking for GA4, bypassing client-side ad blockers while maintaining full GDPR compliance. It features advanced bot detection, A/B testing capabilities, click tracking, and seamless integration with WooCommerce for e-commerce tracking.

### Key Features

- **=€ Server-Side Tracking**: Bypass ad blockers with Cloudflare Worker integration
- **=á GDPR Compliant**: Built-in consent management with Iubenda support
- **> Bot Detection**: Advanced bot filtering to ensure data quality
- **>ê A/B Testing**: Built-in A/B testing framework with CSS-based variants
- **=F Click Tracking**: Track custom events based on CSS selectors
- **=Ò WooCommerce Ready**: Complete e-commerce tracking integration
- **¡ High Performance**: Event queuing system with batch processing
- **= Secure**: JWT encryption and API key authentication
- **=Ê Comprehensive Logging**: Detailed event monitoring and debugging

## Installation

1. Download the plugin files
2. Upload to your WordPress `/wp-content/plugins/` directory
3. Activate the plugin through the WordPress admin panel
4. Navigate to **GA4 Server-Side Tagging** in your admin menu
5. Configure your settings (see Configuration section below)

## Configuration

### Basic Setup

1. **Navigate to Settings**: Go to `GA4 Server-Side Tagging > Settings`
2. **Configure GA4**: Enter your GA4 Measurement ID and API Secret
3. **Set up GDPR**: Configure consent management (Iubenda or custom selectors)
4. **Choose Transmission Method**: Direct to Cloudflare or WP REST Endpoint

### Cloudflare Worker Setup (Recommended)

For optimal performance and ad-blocker bypass:

1. Deploy the provided Cloudflare Worker script
2. Configure environment variables in Cloudflare:
   - `GA4_MEASUREMENT_ID`: Your GA4 Measurement ID
   - `GA4_API_SECRET`: Your GA4 API Secret
   - `ALLOWED_DOMAINS`: Your website domains
   - `API_KEY`: Worker API key (if using WP REST Endpoint)
   - `ENCRYPTION_KEY`: JWT encryption key (if encryption enabled)
3. Enter your Worker URL in plugin settings
4. Test the connection

### GDPR Consent Configuration

#### Option 1: Iubenda Integration
- Check "Use Iubenda" in settings
- Plugin automatically detects Iubenda consent events

#### Option 2: Custom Consent Selectors
- Provide CSS selectors for "Accept All" and "Deny All" buttons
- Example: `.accept-cookies`, `#consent-accept`

#### Consent Timeout (Optional)
- Set automatic consent timeout (0 = disabled)
- Choose action: Accept All or Deny All after timeout

### Tracking Options

- **E-commerce Tracking**: Enable for WooCommerce integration
- **Logged-in Users**: Choose whether to track administrators
- **Form Tracking**: Configure Gravity Forms integration
- **Debug Mode**: Enable detailed logging for troubleshooting

## Features

### A/B Testing

Configure A/B tests through the main plugin page:

1. **Enable A/B Testing** in the Features section
2. **Add Test Configurations**:
   - Test Name: Descriptive name for your test
   - Variant A CSS Class: CSS class for first variant (e.g., `.button-red`)
   - Variant B CSS Class: CSS class for second variant (e.g., `.button-blue`)
3. **Add CSS classes to your theme elements**
4. **View results in GA4** under Events

Example: Test button colors by adding `.button-red` and `.button-blue` classes to your buttons.

### Click Tracking

Track custom events based on user interactions:

1. **Enable Click Tracking** in the Features section
2. **Configure Event Tracking**:
   - Event Name: GA4-compliant event name (e.g., `pdf_download`)
   - CSS Selector: Element selector (e.g., `.download-btn`, `#cta-button`)
3. **Events automatically appear in GA4**

Event names are automatically sanitized to meet GA4 requirements:
- Maximum 40 characters
- Letters, numbers, underscores only
- Cannot start with a number
- Converted to lowercase

### Event Queue & Cronjobs

The plugin uses a queuing system for reliable event processing:

- **Automatic Processing**: Events processed every 5 minutes
- **Manual Trigger**: Force immediate processing via admin panel
- **Batch Processing**: Up to 1000 events per batch
- **Retry Logic**: Failed events are retried automatically
- **Cleanup**: Old events automatically removed

Monitor queue status at `GA4 Server-Side Tagging > Cronjobs`.

### Event Monitoring

Comprehensive event logging and monitoring:

- **Real-time Statistics**: Total events, success rates, processing times
- **Event Filtering**: Filter by status, event type, or search terms
- **Detailed Event Data**: View complete payload, headers, and responses
- **Consent Tracking**: Monitor consent status and reasons
- **Bot Detection Results**: See which events were blocked and why

Access monitoring at `GA4 Server-Side Tagging > Event Monitor`.

## API Endpoints

### Main Event Endpoint

```
POST /wp-json/ga4-server-side-tagging/v1/send-events
```

**Headers:**
- `Content-Type: application/json`
- `X-API-Key: your-api-key` (if using WP REST Endpoint method)

**Payload:**
```json
{
  "events": [
    {
      "name": "page_view",
      "params": {
        "page_title": "Homepage",
        "page_location": "https://example.com"
      }
    }
  ],
  "client_id": "unique-client-identifier",
  "consent": {
    "ad_user_data": "GRANTED",
    "ad_personalization": "GRANTED"
  }
}
```

## Security Features

### Bot Detection

Advanced bot filtering includes:
- **User Agent Analysis**: Known bot signatures
- **Behavioral Analysis**: Rapid requests, missing headers
- **IP Reputation**: Known bot networks
- **Honeypot Detection**: Hidden form fields
- **Rate Limiting**: Prevent spam requests

### Data Protection

- **JWT Encryption**: Optional payload encryption in transit
- **API Key Authentication**: Secure Worker communication
- **GDPR Compliance**: Automatic consent handling
- **IP Anonymization**: Optional IP geolocation disabling
- **Secure Storage**: Encrypted sensitive data

## Troubleshooting

### Common Issues

**Events Not Appearing in GA4**
1. Check Debug Logs (`GA4 Server-Side Tagging > Error Logs`)
2. Verify GA4 Measurement ID and API Secret
3. Test connection in Settings page
4. Check Cloudflare Worker configuration

**Consent Not Working**
1. Verify CSS selectors for consent buttons
2. Check browser console for JavaScript errors
3. Test with different consent scenarios
4. Enable debug mode for detailed logging

**High Bot Detection Rate**
1. Review bot detection rules in Event Monitor
2. Adjust detection sensitivity if needed
3. Whitelist legitimate traffic patterns
4. Check for false positives in logs

### Debug Information

Enable debug mode in Settings to get detailed logs:
- Event processing steps
- Consent detection results
- Bot detection analysis
- API response details
- Error messages and stack traces

## Performance Optimization

### Recommended Settings

- **Use Cloudflare Worker**: Direct transmission for fastest performance
- **Enable Event Queuing**: Prevents blocking page loads
- **Optimize Batch Size**: Default 1000 events works for most sites
- **Regular Cleanup**: Remove old events to maintain performance

### Monitoring Performance

- Check processing times in Event Monitor
- Monitor queue size in Cronjobs page
- Review server resources during peak traffic
- Use Cloudflare Analytics for Worker performance

## Support and Documentation

### Getting Help

- **Error Logs**: Check plugin error logs for detailed debugging
- **Event Monitor**: Review event processing and consent status
- **Queue Status**: Monitor background processing health
- **Connection Test**: Verify GA4 and Cloudflare connectivity

### System Requirements

- **WordPress**: 5.2 or higher
- **PHP**: 7.2 or higher
- **Database**: MySQL 5.6 or higher
- **SSL**: Required for secure transmission
- **Cloudflare Account**: Recommended for optimal performance

## Integration Examples

### WooCommerce E-commerce Tracking

Automatically tracks:
- Purchase events with transaction details
- Add to cart actions
- Product views
- Checkout progress
- Revenue and conversion data

### Gravity Forms Integration

Configure form tracking:
1. Set YITH Request a Quote Form ID
2. Add Conversion Form IDs (comma-separated)
3. Forms automatically trigger GA4 events

### Custom Theme Integration

Add tracking to your theme:

```javascript
// Manual event tracking
fetch('/wp-json/ga4-server-side-tagging/v1/send-events', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json'
    },
    body: JSON.stringify({
        events: [{
            name: 'custom_event',
            params: {
                custom_parameter: 'value'
            }
        }],
        client_id: 'your-client-id'
    })
});
```

## Advanced Configuration

### Custom Bot Detection Rules

Extend bot detection by modifying the detection criteria:
- User agent patterns
- IP ranges
- Request frequency limits
- Required headers

### Custom Consent Handling

Implement custom consent logic:
- Multiple consent providers
- Granular consent categories
- Dynamic consent updates
- Consent history tracking

### Performance Tuning

Optimize for high-traffic sites:
- Increase batch processing limits
- Adjust cron frequency
- Implement caching strategies
- Use dedicated Cloudflare Workers

## License

This plugin is licensed under the GPL v2 or later.

## Author

Developed by [Jacht Digital Marketing](https://jacht.digital/)

---

For the latest updates and detailed developer documentation, see the [CLAUDE.md](CLAUDE.md) file.