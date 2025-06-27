# GA4 Server-Side Tagging for WordPress and WooCommerce

A comprehensive WordPress plugin that provides advanced server-side tagging for Google Analytics 4 (GA4) with GDPR compliance, bot detection, A/B testing, and enterprise-level privacy features. Fully compatible with WordPress, WooCommerce, and optimized for Cloudflare hosting.

## 🌟 Features

### Core Analytics
- **Server-side tracking** for GA4 events with Cloudflare Worker integration
- **Full WooCommerce integration** for comprehensive e-commerce tracking
- **Advanced attribution tracking** with UTM parameters and Google Ads integration
- **Session management** with 30-minute timeout and automatic cleanup
- **Real-time engagement tracking** with precise timing calculations

### Privacy & Compliance
- **GDPR/CCPA compliant** with Google Consent Mode v2 integration
- **Automatic data anonymization** when consent is denied
- **Configurable data retention** (default: 24 hours, consent: 1 year)
- **Consent withdrawal cleanup** with complete data removal
- **IP-based location services** with privacy controls

### Advanced Features
- **Multi-layered bot detection** with behavioral analysis and scoring
- **Complete A/B testing framework** with click tracking and GA4 integration
- **Duplicate purchase prevention** for accurate e-commerce reporting
- **Automatic data migration** from legacy storage systems
- **Comprehensive debugging** and logging system

### Performance & Reliability
- **Centralized storage management** with automatic expiration
- **Multiple location API fallbacks** for reliable geolocation
- **Event queuing system** for consent-pending events
- **Graceful degradation** when services are unavailable

## 📋 Requirements

- WordPress 5.2 or higher
- PHP 7.2 or higher
- WooCommerce 4.0 or higher (for e-commerce tracking)
- Google Analytics 4 property with Measurement ID and API Secret
- Cloudflare account (optional, for server-side tagging)

## 🚀 Installation

1. Upload the `ga4-server-side-tagging` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the 'GA4 Tagging' menu in your WordPress admin
4. Enter your GA4 Measurement ID and API Secret
5. Configure additional settings as needed

## ⚙️ Configuration

### Basic Setup

1. **Create GA4 Property**: Set up a GA4 property in your Google Analytics account
2. **Get Measurement ID**: Copy your Measurement ID (starts with G-)
3. **Create API Secret**: Generate an API Secret in GA4 property settings → Data Streams → Web → Measurement Protocol API secrets
4. **Configure Plugin**: Enter these values in the plugin settings

### Advanced Backend Configuration

#### 🕐 **Data Retention Settings**
Configure how long user data is stored:

```php
// In wp-config.php or theme functions.php
add_filter('ga4_storage_expiration_hours', function($hours) {
    return 48; // Store user data for 48 hours (default: 24)
});
```

#### 🧪 **A/B Testing Configuration**
Set up A/B tests through the admin interface:

1. Navigate to **GA4 Tagging → A/B Tests**
2. Enable A/B testing
3. Add tests with configuration:
   - **Test Name**: Descriptive name for the test
   - **Variant A CSS Class**: CSS selector for control group
   - **Variant B CSS Class**: CSS selector for test group
   - **Enabled**: Toggle test active/inactive

**Example A/B Test Setup:**
```json
{
  "name": "Button Color Test",
  "class_a": ".button-red",
  "class_b": ".button-blue", 
  "enabled": true
}
```

#### 🛡️ **GDPR Consent Management**
Configure consent management integration:

**Iubenda Integration:**
1. Enable consent mode in plugin settings
2. Set "Use Iubenda" to true
3. Configure timeout settings (optional auto-accept)

**Custom Consent Platform:**
1. Enable consent mode
2. Set custom CSS selectors:
   - **Accept Button Selector**: `.accept-cookies`
   - **Deny Button Selector**: `.deny-cookies`
3. Configure fallback timeout

**Advanced Consent Settings:**
```php
// Disable IP-based location tracking
add_filter('ga4_disable_ip_location', '__return_true');

// Custom storage expiration for GDPR compliance
add_filter('ga4_consent_storage_days', function($days) {
    return 365; // Store consent for 1 year (default)
});
```

#### 🤖 **Bot Detection Configuration**
Customize bot detection sensitivity:

```php
// Adjust bot detection threshold
add_filter('ga4_bot_detection_threshold', function($threshold) {
    return 0.7; // 70% confidence threshold (default: 0.8)
});

// Custom bot patterns
add_filter('ga4_custom_bot_patterns', function($patterns) {
    $patterns[] = '/custombot/i';
    return $patterns;
});
```

#### 🌍 **Location Services Configuration**
Configure IP-based location detection:

```php
// Disable location services entirely
add_filter('ga4_enable_location_services', '__return_false');

// Custom location API timeout
add_filter('ga4_location_api_timeout', function($timeout) {
    return 5000; // 5 seconds (default: 3000ms)
});
```

### Cloudflare Worker Setup (Recommended)

For optimal server-side tagging with enhanced bot protection:

1. **Create Cloudflare Worker**: Set up a new worker in your Cloudflare dashboard
2. **Deploy Script**: Use the provided `cloudflare-worker-example.js`
3. **Configure Credentials**: Replace placeholder values with your GA4 credentials
4. **Set Worker URL**: Enter the worker URL in plugin settings
5. **Enable Server-Side Tracking**: Toggle server-side option in admin

**Worker Environment Variables:**
```javascript
// In Cloudflare Worker
const GA4_MEASUREMENT_ID = 'G-XXXXXXXXXX';
const GA4_API_SECRET = 'your-api-secret';
const GA4_DEBUG_MODE = false; // Set to true for testing
```

## 🎯 Usage & API Reference

### Automatic Tracking

The plugin automatically tracks:
- **Page views** with enhanced attribution and device detection
- **WooCommerce events**: product views, add to cart, checkout steps, purchases
- **User interactions**: outbound links, form submissions, file downloads
- **Engagement metrics**: scroll depth, time on page, video views
- **A/B test interactions** with comprehensive variant tracking

### Custom Event Tracking

Track custom events using the JavaScript API:

```javascript
// Basic custom event
if (typeof GA4ServerSideTagging !== 'undefined') {
    GA4ServerSideTagging.trackEvent('custom_event', {
        custom_parameter: 'value',
        user_engagement: true
    });
}

// E-commerce event with items
GA4ServerSideTagging.trackEvent('add_to_cart', {
    currency: 'USD',
    value: 29.99,
    items: [{
        item_id: 'product_123',
        item_name: 'Example Product',
        category: 'Electronics',
        quantity: 1,
        price: 29.99
    }]
});
```

### GA4Utils API - Advanced Functions

#### 📦 **Storage Management**
```javascript
// Get user data with automatic expiration checking
const userData = GA4Utils.storage.getUserData();

// Check consent status
const hasConsent = GA4Utils.storage.hasValidConsent();

// Manual data cleanup
GA4Utils.storage.clearUserData();

// Test storage system (development)
GA4Utils.storage.testNewStorage();
```

#### 🆔 **User Identification**
```javascript
// Get persistent client ID
const clientId = GA4Utils.clientId.get();

// Get session information
const session = GA4Utils.session.get();
// Returns: { id, start, isNew, isFirstVisit, sessionCount, duration }

// Session-based ID for privacy mode
const sessionId = GA4Utils.clientId.getSessionBased();
```

#### 📊 **Attribution & Campaign Tracking**
```javascript
// Get all UTM parameters
const utmData = GA4Utils.utm.getAll();
// Returns: { utm_source, utm_medium, utm_campaign, utm_content, utm_term }

// Get Google Click ID
const gclid = GA4Utils.gclid.get();

// Complete user attribution info
const userInfo = GA4Utils.user.getInfo(configData);
```

#### 🌍 **Location Services**
```javascript
// Get user location (cached or fresh)
GA4Utils.location.get().then(location => {
    console.log(location); // { latitude, longitude, city, region, country }
});

// Force fresh location fetch
GA4Utils.location.refresh().then(location => {
    // Fresh location data
});

// Check cached location status
const hasCached = GA4Utils.location.hasCachedData();
const cacheAge = GA4Utils.location.getCacheAge(); // milliseconds
```

#### 🤖 **Bot Detection**
```javascript
// Check if current visitor is a bot
const userAgent = GA4Utils.device.parseUserAgent();
const behavior = GA4Utils.botDetection.getClientBehaviorData();
const sessionParams = GA4Utils.session.getInfo();

const isBot = GA4Utils.botDetection.isBot(userAgent, sessionParams, behavior);

// Get bot confidence score
const botScore = GA4Utils.botDetection.calculateBotScore(userAgent, behavior);
```

#### 🛡️ **GDPR Consent Management**
```javascript
// Check consent status
const hasAnalytics = GA4Utils.consent.hasAnalyticsConsent();
const hasAdvertising = GA4Utils.consent.hasAdvertisingConsent();

// Get consent for server-side processing
const consentData = GA4Utils.consent.getForServerSide();

// Check if user data collection is allowed
const canTrackUser = GA4Utils.consent.shouldTrackUserData();
const canTrackAds = GA4Utils.consent.shouldTrackAdvertisingData();
```

#### 🧪 **A/B Testing**
```javascript
// Initialize A/B tests (called automatically)
GA4Utils.abTesting.init(config);

// Manual test setup (advanced usage)
GA4Utils.abTesting.setupTest({
    name: 'Button Color Test',
    class_a: '.button-red',
    class_b: '.button-blue',
    enabled: true
});

// Track custom A/B test interaction
GA4Utils.abTesting.track(testConfig, 'variant_a', element);
```

#### 📱 **Device & Browser Detection**
```javascript
// Get comprehensive device information
const deviceInfo = GA4Utils.device.parseUserAgent();
// Returns: { browser_name, device_type, user_agent, is_mobile, is_tablet, is_desktop }

// Get screen resolution
const resolution = GA4Utils.device.getScreenResolution(); // "1920x1080"

// Anonymize user agent for privacy
const anonymized = GA4Utils.device.anonymizeUserAgent(userAgent);
```

#### 🛠️ **Utility Functions**
```javascript
// Generate unique IDs
const uniqueId = GA4Utils.helpers.generateUniqueId();

// Debug logging (only when debug mode enabled)
GA4Utils.helpers.log('Debug message', data, config, '[Custom Prefix]');

// Social platform detection
const platform = GA4Utils.helpers.getSocialPlatform(url); // 'facebook', 'twitter', etc.

// Performance helpers
const debouncedFn = GA4Utils.helpers.debounce(myFunction, 300);
const throttledFn = GA4Utils.helpers.throttle(myFunction, 100);

// Timezone and location helpers
const timezone = GA4Utils.helpers.getTimezone();
const locationFromTz = GA4Utils.helpers.getLocationFromTimezone(timezone);
```

### WordPress Hooks & Filters

#### Action Hooks
```php
// Before GA4 event is sent
do_action('ga4_before_send_event', $event_name, $event_data);

// After GA4 event is sent
do_action('ga4_after_send_event', $event_name, $event_data, $response);

// On consent status change
do_action('ga4_consent_updated', $consent_status);

// Before data cleanup
do_action('ga4_before_data_cleanup', $cleanup_type);
```

#### Filter Hooks
```php
// Modify event data before sending
add_filter('ga4_event_data', function($data, $event_name) {
    // Modify $data array
    return $data;
}, 10, 2);

// Custom bot detection rules
add_filter('ga4_is_bot', function($is_bot, $user_agent, $ip) {
    // Custom bot detection logic
    return $is_bot;
}, 10, 3);

// Modify storage expiration
add_filter('ga4_storage_expiration_hours', function($hours) {
    return 48; // Custom expiration time
});

// Custom consent timeout
add_filter('ga4_consent_timeout_seconds', function($seconds) {
    return 30; // 30 seconds auto-accept timeout
});
```

## 🔧 Advanced Configuration Examples

### Custom E-commerce Integration
```php
// Custom product data enhancement
add_filter('ga4_product_data', function($product_data, $product) {
    $product_data['custom_attribute'] = get_post_meta($product->get_id(), 'custom_field', true);
    return $product_data;
}, 10, 2);

// Custom purchase event data
add_filter('ga4_purchase_data', function($purchase_data, $order) {
    $purchase_data['shipping_method'] = $order->get_shipping_method();
    $purchase_data['payment_method'] = $order->get_payment_method();
    return $purchase_data;
}, 10, 2);
```

### Advanced Bot Detection
```php
// Custom bot detection rules
add_filter('ga4_bot_detection_rules', function($rules) {
    $rules['custom_bots'] = [
        'patterns' => ['/custombot/i', '/scraperbot/i'],
        'ips' => ['192.168.1.100', '10.0.0.50'],
        'score_weight' => 0.9
    ];
    return $rules;
});
```

### Performance Optimization
```php
// Disable location services for faster loading
add_filter('ga4_enable_location_services', '__return_false');

// Reduce bot detection intensity
add_filter('ga4_bot_detection_level', function() {
    return 'basic'; // 'basic', 'standard', 'aggressive'
});

// Custom cache durations
add_filter('ga4_location_cache_duration', function() {
    return HOUR_IN_SECONDS * 6; // 6 hours
});
```

## 🐛 Debugging & Troubleshooting

### Debug Mode
1. **Enable Debug Mode**: Check the debug option in plugin settings
2. **View Console Logs**: Open browser developer tools → Console tab
3. **Check Admin Logs**: Navigate to GA4 Tagging → Logs section
4. **Test Configuration**: Use `GA4Utils.storage.testNewStorage()` in console

### Common Issues

**Events Not Sending:**
```javascript
// Check consent status
console.log('Consent Status:', GA4Utils.consent.getForServerSide());

// Verify client ID generation
console.log('Client ID:', GA4Utils.clientId.get());

// Check for bot detection
console.log('Bot Detection:', GA4Utils.botDetection.isBot(userAgent, session, behavior));
```

**Storage Issues:**
```javascript
// Test storage system
GA4Utils.storage.testNewStorage();

// Check data expiration
console.log('User Data:', GA4Utils.storage.getUserData());
console.log('Consent Data:', GA4Utils.storage.getConsentData());
```

**A/B Testing Not Working:**
```javascript
// Verify A/B test configuration
console.log('A/B Tests Config:', window.ga4ServerSideTagging.abTestsConfig);

// Check if elements exist
console.log('Test Elements:', document.querySelectorAll('.button-red, .button-blue'));
```

### Performance Monitoring
```javascript
// Monitor storage usage
const storageUsage = GA4Utils.helpers.getStoredDataSummary();
console.log('Storage Summary:', storageUsage);

// Check cache performance
console.log('Location Cache Age:', GA4Utils.location.getCacheAge());
console.log('Session Duration:', GA4Utils.session.get().duration);
```

## 📊 Data Privacy & Compliance

### GDPR Compliance Features
- **Automatic consent detection** with major consent management platforms
- **Data minimization** with configurable retention periods  
- **Right to erasure** with complete data cleanup functions
- **Consent granularity** for analytics vs advertising data
- **Transparent data collection** with storage summary functions

### Data Categories Tracked
1. **Essential Data** (always collected):
   - Session identifiers (session-based, no personal data)
   - Page views and basic navigation
   - Technical information (browser type, device type)

2. **Analytics Data** (requires consent):
   - Persistent user identifiers
   - Detailed device information
   - Precise location data
   - Attribution and campaign data

3. **Advertising Data** (requires separate consent):
   - Google Click IDs (gclid)
   - UTM campaign parameters
   - Cross-site tracking identifiers

### Data Retention Policies
- **User Data**: Configurable (default: 24 hours)
- **Session Data**: 30 minutes after last activity
- **Consent Data**: 1 year (with manual override)
- **Purchase Tracking**: 30 minutes (prevents duplicates)

## 🚀 Performance Optimization

### Caching Strategy
- **Location Data**: Cached with configurable expiration
- **User Agent Parsing**: Cached per session
- **Attribution Data**: Persistent until session expires
- **Bot Detection**: Results cached to avoid repeated calculations

### Async Loading
- All external API calls are asynchronous
- Event queuing prevents blocking during consent flows
- Graceful degradation when services are unavailable

### Resource Management
- Automatic cleanup of expired data
- Efficient storage with minimal localStorage usage
- Debounced and throttled event handlers
- Lazy loading of non-essential features

## 📈 Analytics Enhancement

### Enhanced Attribution
- **First-party data** prioritized over third-party cookies
- **Session continuity** across page loads
- **Cross-domain tracking** support
- **Offline conversion** import capability

### Custom Dimensions
The plugin automatically sets up custom dimensions for:
- Bot detection score
- Consent status (granted/denied)
- A/B test variants
- Session type (new/returning)
- Traffic quality score

### Enhanced E-commerce
- **Product interaction tracking** (views, clicks, add to cart)
- **Checkout funnel analysis** with step-by-step tracking
- **Purchase attribution** with proper source assignment
- **Refund tracking** and inventory management integration

## 🔄 Migration & Updates

### Automatic Data Migration
The plugin automatically migrates data from:
- Legacy GA tracking plugins
- Old storage formats within the same plugin
- Standard Google Analytics (UA) configurations

### Update Process
1. **Backup**: Plugin automatically backs up settings before updates
2. **Migration**: Data structures are automatically updated
3. **Verification**: Built-in tests verify data integrity after updates
4. **Rollback**: Previous configurations can be restored if needed

## 🆘 Support & Resources

### Documentation
- **API Reference**: Complete function documentation in code comments
- **WordPress Hooks**: All available actions and filters documented
- **Configuration Examples**: Real-world implementation samples

### Troubleshooting Tools
- **Debug Console**: `GA4Utils.storage.testNewStorage()`
- **Data Inspector**: `GA4Utils.helpers.getStoredDataSummary()`
- **Performance Monitor**: Built-in timing and performance metrics

### Community & Support
- **GitHub Repository**: [Link to repository for issues and contributions]
- **Documentation Site**: [Link to comprehensive documentation]
- **Community Forum**: [Link to support forum]

## 📄 License

This plugin is licensed under the GPL v2 or later.

## 👥 Credits

**Developed by**: Jacht Digital Marketing  
**Contributors**: [List of contributors]  
**Special Thanks**: WordPress community, GA4 developer community

---

*For detailed API documentation, advanced configuration examples, and troubleshooting guides, visit our comprehensive documentation site.*