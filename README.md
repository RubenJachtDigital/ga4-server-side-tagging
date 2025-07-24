# GA4 Server-Side Tagging Plugin

A comprehensive WordPress plugin that provides server-side Google Analytics 4 (GA4) tracking with multiple transmission methods, GDPR compliance, advanced bot detection, and optional Cloudflare Worker integration.

## Overview

This plugin enables flexible server-side tracking for GA4 with three transmission methods: direct Cloudflare Worker integration (bypassing ad blockers), WordPress REST API with queuing system, or direct Google Analytics transmission. All methods maintain full GDPR compliance with advanced bot detection, A/B testing capabilities, click tracking, and seamless WooCommerce integration.

### Key Features

- **🚀 Flexible Server-Side Tracking**: Three transmission methods including optional Cloudflare Worker integration
- **=� GDPR Compliant**: Built-in consent management with Iubenda support
- **> Bot Detection**: Advanced bot filtering to ensure data quality
- **>� A/B Testing**: Built-in A/B testing framework with CSS-based variants
- **=F Click Tracking**: Track custom events based on CSS selectors
- **=� WooCommerce Ready**: Complete e-commerce tracking integration
- **� High Performance**: Event queuing system with batch processing
- **= Secure**: JWT encryption and API key authentication
- **=� Comprehensive Logging**: Detailed event monitoring and debugging

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
4. **Choose Transmission Method**: Direct to Cloudflare, WP REST Endpoint, or Direct to GA4

## Transmission Methods & Data Flow

### Overview

The plugin offers three transmission methods for sending analytics data, each with different security, performance, and privacy characteristics:

1. **Direct to Cloudflare** (Recommended) - Bypass WordPress entirely
2. **WP REST Endpoint** - Queue through WordPress then send to Cloudflare  
3. **Direct to GA4** - Send directly to Google Analytics (bypass Cloudflare)

### Method 1: Direct to Cloudflare (direct_to_cf)

**Best for**: Maximum performance, ad-blocker bypass, minimal server load

**Data Flow**:
```
Browser JavaScript → Cloudflare Worker → Google Analytics
```

**Encryption**: 
- Time-based JWT tokens (short-lived, 5-10 minute expiry)
- Encrypted with rotating keys for maximum security
- Headers are never encrypted (stored as plain text for efficiency)

**Headers**: 
- Essential headers (User-Agent, Accept-Language, Referer, IP) filtered and sent
- Fallback to Cloudflare request headers if payload headers unavailable
- No sensitive data (cookies, auth headers) transmitted

**GDPR Compliance**:
- Full consent processing in Cloudflare Worker
- Advertising consent denied: Anonymizes paid traffic sources to "(denied consent)"
- Analytics consent denied: Removes personal identifiers, precise location data

### Method 2: WP REST Endpoint (wp_rest_endpoint)

**Best for**: Full logging, debugging, complex processing, hybrid approach

**Data Flow**:
```
Browser JavaScript → WordPress REST API → Event Queue → Cloudflare Worker → Google Analytics
```

**Encryption**:
- Permanent JWT tokens (no expiry) for database storage
- Event data encrypted with permanent keys
- Headers stored separately (not encrypted for performance)
- Final payload can be encrypted before sending to Cloudflare

**Headers**:
- Full header capture and filtering in WordPress
- Essential headers (User-Agent, Accept-Language, Referer, X-Forwarded-For, X-Real-IP) preserved
- Cookie headers and sensitive data filtered out before storage

**Processing**:
- Events queued in WordPress database
- Background cron processing every 5 minutes  
- Batch processing (up to 1000 events per batch)
- Comprehensive logging and monitoring
- Retry logic for failed events

**GDPR Compliance**:
- WordPress-side consent validation before queuing
- Cloudflare Worker applies additional consent processing
- Complete audit trail in WordPress event logs

### Method 3: Direct to GA4 (Enable "Disable Cloudflare Proxy")

**Best for**: Simple setup, no Cloudflare Worker needed, direct Google integration

**Data Flow**:
```
Browser JavaScript → WordPress REST API → Event Queue → Google Analytics
```

**Encryption**:
- Data never encrypted when sending to GA4 (Google doesn't accept encrypted payloads)
- Events stored encrypted in WordPress database
- Headers stored as plain text for GA4 compatibility

**Headers**:
- WordPress captures and filters essential headers
- Headers mapped to proper HTTP format for GA4 requests
- Original website headers preserved and forwarded to Google

**GA4 Payload Structure**:
- Follows Google Analytics Measurement Protocol specification
- Top-level fields: `client_id`, `user_id`, `consent`, `user_location`, `device`, `user_agent`
- Event-level consent parameter: `"consent": "ad_personalization: GRANTED. ad_user_data: GRANTED. reason: button_click_immediate"`
- Privacy-compliant location data with proper ISO country codes

**GDPR Compliance**:
- Same consent processing as Cloudflare Worker method
- Direct application of consent rules in WordPress
- Advertising consent denied: Traffic sources anonymized to "(denied consent)"
- Analytics consent denied: Personal data stripped, location anonymized

### Security & Privacy

**JWT Encryption**:
- Time-based JWTs: Short-lived tokens for direct transmission (Method 1)
- Permanent JWTs: Long-term storage encryption for database (Methods 2 & 3)
- Rotation-based encryption keys prevent replay attacks
- Headers never encrypted (performance optimization)

**Data Privacy**:
- Essential headers only: User-Agent, Accept-Language, Referer, IP addresses
- Cookie filtering: No session cookies, auth tokens, or tracking IDs transmitted
- IP privacy: Uses Cloudflare's CF-Connecting-IP for accurate geolocation
- Consent enforcement: Real-time consent validation before any processing

**Bot Detection**:
- User-Agent analysis for bot patterns
- Request rate limiting per IP address
- Header validation (missing Accept, Accept-Language indicates bots)
- Behavioral analysis for suspicious patterns
- All methods include bot filtering before data transmission

### Performance Characteristics

| Method | Page Load Impact | Server Load | Reliability | Ad-blocker Bypass |
|--------|------------------|-------------|-------------|-------------------|
| Direct to Cloudflare | Minimal | Very Low | Highest | Yes |
| WP REST Endpoint | Low | Medium | High | Yes |
| Direct to GA4 | Low | Medium | High | No |

### Choosing the Right Method

**Use Direct to Cloudflare when**:
- Maximum performance is critical
- High traffic websites  
- Ad-blocker bypass is important
- Minimal WordPress server load desired

**Use WP REST Endpoint when**:
- Detailed logging and monitoring needed
- Complex event processing required
- Debugging capabilities important
- Hybrid approach with fallback options

**Use Direct to GA4 when**:
- Simple setup without Cloudflare Worker
- Direct Google Analytics integration preferred
- No additional infrastructure dependencies
- Testing and development environments

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