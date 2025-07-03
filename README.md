# GA4 Server-Side Tagging for WordPress and WooCommerce

A comprehensive WordPress plugin that provides advanced server-side tagging for Google Analytics 4 (GA4) with **enterprise-grade security**, GDPR compliance, bot detection, A/B testing, click tracking, and **JWT Auth encryption**. Fully compatible with WordPress, WooCommerce, and optimized for Cloudflare hosting with **AES-256-GCM encryption** for secure data transmission.

## 🌟 Features

### Core Analytics
- **Server-side tracking** for GA4 events with Cloudflare Worker integration
- **Full WooCommerce integration** for comprehensive e-commerce tracking
- **Advanced attribution tracking** with UTM parameters and Google Ads integration
- **Session management** with 30-minute timeout and automatic cleanup
- **Real-time engagement tracking** with precise timing calculations
- **API key authentication** for secure server-to-server communication

### Privacy & Compliance
- **GDPR/CCPA compliant** with Google Consent Mode v2 integration
- **Automatic data anonymization** when consent is denied
- **Configurable data retention** (default: 24 hours, consent: 1 year)
- **Consent withdrawal cleanup** with complete data removal
- **IP-based location services** with privacy controls
- **CORS security** with domain whitelisting

### Advanced Features
- **Multi-layered bot detection** with behavioral analysis and scoring
- **Complete A/B testing framework** with click tracking and GA4 integration
- **Custom click tracking** with configurable CSS selectors and event naming
- **Duplicate purchase prevention** for accurate e-commerce reporting
- **Automatic data migration** from legacy storage systems
- **Comprehensive debugging** and logging system

### Performance & Reliability
- **Centralized storage management** with automatic expiration
- **Multiple location API fallbacks** for reliable geolocation
- **Event queuing system** for consent-pending events
- **Rate limiting** and payload size validation
- **Graceful degradation** when services are unavailable

### Security Features
- **🔐 JWT Auth Encryption** with AES-256-GCM encryption for secure data transmission
- **🔑 Encryption Key Management** with one-click key generation and rotation
- **🛡️ API key authentication** with X-API-Key header support
- **🌐 Domain origin validation** with referrer checking
- **⚡ Rate limiting** (configurable requests per IP per minute)
- **📏 Payload size validation** (default: 50KB max)
- **🤖 Bot detection and filtering** with multiple detection layers
- **🔒 CORS protection** with explicit header allowlisting
- **🔗 Cross-platform encryption** compatible across PHP, JavaScript, and Cloudflare Worker

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

### Cloudflare Worker Setup (Recommended)

For optimal server-side tagging with enhanced bot protection and security:

1. **Create Cloudflare Worker**: Set up a new worker in your Cloudflare dashboard
2. **Deploy Script**: Use the provided `cloudflare-worker-example.js`
3. **Configure Credentials**: Replace placeholder values with your GA4 credentials
4. **Set Worker URL**: Enter the worker URL in plugin settings
5. **Generate API Key**: Click "Generate New Key" in WordPress admin and update worker
6. **Enable Server-Side Tracking**: Toggle server-side option in admin

**Worker Configuration Variables:**
```javascript
// In Cloudflare Worker - Update these values
const GA4_MEASUREMENT_ID = 'G-XXXXXXXXXX'; // Your GA4 Measurement ID
const GA4_API_SECRET = 'your-api-secret-here'; // Your GA4 API Secret
const API_KEY = "api-key-from-wordpress-admin"; // Copy from WordPress admin
const ALLOWED_DOMAINS = ["yourdomain.com", "www.yourdomain.com"]; // Your domains

// Security Settings
const RATE_LIMIT_REQUESTS = 100; // Max requests per IP per minute
const RATE_LIMIT_WINDOW = 60; // Rate limit window in seconds
const MAX_PAYLOAD_SIZE = 50000; // Max payload size in bytes (50KB)
const REQUIRE_API_KEY = true; // Enable API key authentication
const BOT_DETECTION_ENABLED = true; // Enable bot filtering

// 🔐 JWT Encryption Configuration (NEW)
const JWT_ENCRYPTION_ENABLED = false; // Set to true to enable JWT encryption
const ENCRYPTION_KEY = "your-256-bit-encryption-key-here"; // 64-character hex key from WordPress admin
```

### 🔐 JWT Encryption Setup (NEW)

For enhanced security, enable **AES-256-GCM encryption** for all data transmission:

1. **Generate Encryption Key**: Go to WordPress admin → GA4 Tagging settings
2. **Click "Generate Encryption Key"**: Creates a secure 256-bit encryption key
3. **Enable Encryption**: Check "Enable JWT Encryption" option
4. **Copy Key to Cloudflare Worker**: Update `ENCRYPTION_KEY` constant with generated key
5. **Enable Worker Encryption**: Set `JWT_ENCRYPTION_ENABLED = true` in worker
6. **Deploy Worker**: Redeploy with new encryption settings

**Encryption Key Management:**
```php
// WordPress Admin - Automatic key generation
$encryption_key = get_option('ga4_jwt_encryption_key'); // 64-character hex key
```

**Security Benefits:**
- **🔒 End-to-end encryption** of all event data and sensitive payloads
- **🛡️ AES-256-GCM encryption** with authentication tags for data integrity
- **🔑 Cross-platform compatibility** between PHP, JavaScript, and Cloudflare Worker
- **⚡ Automatic fallback** to XOR encryption for older browsers
- **🔄 Key rotation support** with one-click regeneration

### A/B Testing Configuration

Set up A/B tests through the admin interface:

1. Navigate to **GA4 Tagging → A/B Tests**
2. Enable A/B testing
3. Add tests with configuration:
   - **Test Name**: Descriptive name for the test
   - **Variant A CSS Selector**: CSS selector for control group (e.g., `.button-red`)
   - **Variant B CSS Selector**: CSS selector for test group (e.g., `.button-blue`)
   - **Enabled**: Toggle test active/inactive

**Example A/B Test Configuration:**
```json
[
  {
    "name": "Button Color Test",
    "class_a": ".button-red",
    "class_b": ".button-blue", 
    "enabled": true
  },
  {
    "name": "CTA Position Test",
    "class_a": ".cta-top",
    "class_b": ".cta-bottom",
    "enabled": true
  }
]
```

### Click Tracking Configuration

Set up custom click tracking through the admin interface:

1. Navigate to **GA4 Tagging → Click Tracking**
2. Enable click tracking
3. Add click tracks with configuration:
   - **Event Name**: GA4 event name (automatically sanitized)
   - **CSS Selector**: Target elements to track (e.g., `.download-btn`, `#contact-form`)
   - **Enabled**: Toggle track active/inactive

**Example Click Track Configuration:**
```json
[
  {
    "name": "PDF Download",
    "selector": ".download-pdf",
    "enabled": true
  },
  {
    "name": "Newsletter Signup",
    "selector": "#newsletter-form button[type='submit']",
    "enabled": true
  },
  {
    "name": "Social Share",
    "selector": ".social-share-btn",
    "enabled": true
  }
]
```

**Event Name Validation Rules:**
- Must be 40 characters or less
- Can only contain letters, numbers, and underscores
- Cannot start with a number
- Automatically converted to lowercase
- Invalid characters replaced with underscores

### GDPR Consent Management

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

## 🎯 Usage & API Reference

### Automatic Tracking

The plugin automatically tracks:
- **Page views** with enhanced attribution and device detection
- **WooCommerce events**: product views, add to cart, checkout steps, purchases
- **User interactions**: outbound links, form submissions, file downloads
- **Engagement metrics**: scroll depth, time on page, video views
- **A/B test interactions** with comprehensive variant tracking
- **Custom click events** based on configured selectors

### A/B Testing Events

When A/B tests are configured, the plugin automatically tracks:
- `ab_test_variant_a_click` - When variant A is clicked
- `ab_test_variant_b_click` - When variant B is clicked

**Event Parameters:**
```javascript
{
  test_name: "Button Color Test",
  variant: "A", // or "B"
  element_selector: ".button-red",
  element_text: "Click Me",
  element_id: "main-cta",
  session_duration_seconds: 45
}
```

### Click Tracking Events

Custom click events are tracked with the configured event name:
- Event names are automatically sanitized and validated
- Rich context data is included with each click

**Event Parameters:**
```javascript
{
  click_selector: ".download-pdf",
  click_element_tag: "a",
  click_element_text: "Download PDF",
  click_element_id: "pdf-download-1",
  click_element_class: "download-btn primary",
  session_duration_seconds: 120
}
```

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

// A/B test custom tracking
GA4ServerSideTagging.trackEvent('ab_test_conversion', {
    test_name: 'Button Color Test',
    variant: 'A',
    conversion_value: 99.99
});
```

### GA4Utils API - Advanced Functions

#### 🔐 **Encryption API (NEW)**
```javascript
// Encrypt/decrypt data using AES-256-GCM or XOR fallback
const encryptionKey = 'your-64-character-hex-key';

// Basic encryption/decryption
const encrypted = await GA4Utils.encryption.encrypt('sensitive data', encryptionKey);
const decrypted = await GA4Utils.encryption.decrypt(encrypted, encryptionKey);

// Request/response encryption for secure API calls
const requestData = { event: 'purchase', amount: 99.99 };
const encryptedRequest = await GA4Utils.encryption.encryptRequest(requestData, encryptionKey);
const decryptedResponse = await GA4Utils.encryption.decryptResponse(response, encryptionKey);

// JWT payload encryption for secure token handling
const jwtPayload = { user_id: 123, permissions: ['read', 'write'] };
const encryptedJWT = await GA4Utils.encryption.encryptJWTPayload(jwtPayload, encryptionKey);
const decryptedPayload = await GA4Utils.encryption.decryptJWTPayload(encryptedJWT, encryptionKey);
```

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

#### 🧪 **A/B Testing**
```javascript
// A/B tests are automatically initialized from admin configuration
// Manual test tracking (advanced usage)
GA4ServerSideTagging.trackABTestEvent({
    name: 'Button Color Test',
    class_a: '.button-red',
    class_b: '.button-blue'
}, 'A', element);

// Check if A/B testing is enabled
console.log('A/B Testing enabled:', GA4ServerSideTagging.config.abTestsEnabled);
console.log('A/B Tests config:', GA4ServerSideTagging.config.abTestsConfig);
```

#### 🎯 **Click Tracking**
```javascript
// Click tracking is automatically set up from admin configuration
// Manual click event tracking (advanced usage)
GA4ServerSideTagging.trackClickEvent('custom_click', '.my-selector', element);

// Check if click tracking is enabled
console.log('Click tracking enabled:', GA4ServerSideTagging.config.clickTracksEnabled);
console.log('Click tracks config:', GA4ServerSideTagging.config.clickTracksConfig);
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

// Modify A/B test configuration
add_filter('ga4_ab_tests_config', function($tests) {
    // Add or modify A/B tests programmatically
    $tests[] = [
        'name' => 'Programmatic Test',
        'class_a' => '.variant-a',
        'class_b' => '.variant-b',
        'enabled' => true
    ];
    return $tests;
});

// Modify click tracking configuration
add_filter('ga4_click_tracks_config', function($tracks) {
    // Add or modify click tracks programmatically
    $tracks[] = [
        'name' => 'footer_link_click',
        'selector' => 'footer a',
        'enabled' => true
    ];
    return $tracks;
});
```

## 🔧 Advanced Configuration Examples

### Custom A/B Test Integration
```php
// Add A/B test configuration programmatically
add_filter('ga4_ab_tests_config', function($tests) {
    if (is_front_page()) {
        $tests[] = [
            'name' => 'Homepage Hero Test',
            'class_a' => '.hero-variant-a',
            'class_b' => '.hero-variant-b',
            'enabled' => true
        ];
    }
    return $tests;
});

// Track A/B test conversions
add_action('woocommerce_thankyou', function($order_id) {
    if ($order_id) {
        // Track conversion for A/B test
        ?>
        <script>
        if (typeof GA4ServerSideTagging !== 'undefined') {
            GA4ServerSideTagging.trackEvent('ab_test_conversion', {
                test_name: 'Checkout Button Test',
                conversion_value: <?php echo wc_get_order($order_id)->get_total(); ?>
            });
        }
        </script>
        <?php
    }
});
```

### Custom Click Tracking
```php
// Add click tracking for specific pages
add_filter('ga4_click_tracks_config', function($tracks) {
    if (is_product()) {
        $tracks[] = [
            'name' => 'product_image_click',
            'selector' => '.woocommerce-product-gallery img',
            'enabled' => true
        ];
    }
    
    if (is_checkout()) {
        $tracks[] = [
            'name' => 'checkout_payment_method_change',
            'selector' => 'input[name="payment_method"]',
            'enabled' => true
        ];
    }
    
    return $tracks;
});
```

### Enhanced E-commerce Integration
```php
// Custom product data enhancement
add_filter('ga4_product_data', function($product_data, $product) {
    $product_data['custom_attribute'] = get_post_meta($product->get_id(), 'custom_field', true);
    $product_data['stock_level'] = $product->get_stock_quantity();
    return $product_data;
}, 10, 2);

// Custom purchase event data
add_filter('ga4_purchase_data', function($purchase_data, $order) {
    $purchase_data['shipping_method'] = $order->get_shipping_method();
    $purchase_data['payment_method'] = $order->get_payment_method();
    $purchase_data['customer_type'] = $order->get_user_id() ? 'returning' : 'new';
    return $purchase_data;
}, 10, 2);
```

## 🔒 Security & Privacy Features

### 🔐 **Enterprise-Grade Encryption (NEW)**
- **🛡️ AES-256-GCM Encryption**: Military-grade encryption for all sensitive data transmission
- **🔑 Automatic Key Management**: One-click encryption key generation and rotation
- **🌐 Cross-Platform Compatibility**: Identical encryption format across PHP, JavaScript, and Cloudflare Worker
- **⚡ Intelligent Fallback**: XOR encryption fallback for older browsers without Web Crypto API
- **🔒 Payload Encryption**: Request/response data encrypted end-to-end
- **🎯 Selective Encryption**: Headers remain in plaintext for HTTP routing, payload encrypted for security
- **🔄 Key Rotation**: Easy encryption key rotation without service interruption

### 🛡️ **Server-Side Security (Cloudflare Worker)**
- **🔐 JWT Token Authentication**: Time-limited JWT tokens with 5-minute expiration
- **🔑 API Key Authentication**: Secure X-API-Key header validation with configurable rotation
- **🌍 Domain Whitelisting**: Only allowed domains can send events with origin validation
- **⚡ Advanced Rate Limiting**: Configurable requests per IP per time window with automatic blocking
- **📏 Payload Size Validation**: Prevents oversized requests (default: 50KB max)
- **🔒 CORS Protection**: Explicit header allowlisting with secure defaults
- **🤖 Multi-Layer Bot Detection**: Comprehensive filtering with behavioral analysis and scoring
- **🚫 IP Reputation Filtering**: Cloudflare threat score integration
- **🔍 Request Pattern Analysis**: Suspicious header and behavior detection

### 🔒 **Client-Side Privacy**
- **📋 Consent Mode v2**: Google's latest consent framework implementation
- **📊 Data Minimization**: Only collect necessary data based on consent status
- **⏰ Automatic Expiration**: Configurable data retention periods with automatic cleanup
- **🌐 IP Anonymization**: Optional IP-based location disabling for enhanced privacy
- **🔄 Session-only Tracking**: Privacy mode with session-based IDs (no persistent tracking)
- **🔐 Encrypted Storage**: Sensitive data encrypted in browser localStorage
- **🚫 Ad Blocker Bypass**: Server-side processing bypasses client-side ad blockers

### 📜 **GDPR Compliance Features**
- **✅ Automatic Consent Detection**: Integration with major consent management platforms (Iubenda, OneTrust, etc.)
- **📅 Data Retention Policies**: Configurable retention periods with automatic data expiration
- **🗑️ Right to Erasure**: Complete data cleanup functions with verification
- **🎯 Consent Granularity**: Separate controls for analytics vs advertising data
- **📊 Transparent Data Collection**: Storage summary functions for user transparency
- **🔄 Consent Withdrawal**: Immediate data anonymization when consent is withdrawn
- **🛡️ Privacy by Design**: Default-deny approach with minimal data collection

### 🔒 **Transport Security**
- **🌐 HTTPS Enforcement**: All requests require TLS 1.2+ encryption
- **🔐 TLS Certificate Validation**: Strict certificate validation for all connections
- **🛡️ Header Security**: Security headers implemented (HSTS, CSP, etc.)
- **🚫 Mixed Content Prevention**: Ensures all resources loaded over HTTPS
- **🔒 Secure Cookie Handling**: HttpOnly, Secure, and SameSite cookie attributes

### 🎯 **Attack Prevention**
- **🚨 DDoS Protection**: Cloudflare's built-in DDoS mitigation
- **🔍 SQL Injection Prevention**: Input sanitization and parameterized queries
- **🛡️ XSS Protection**: Content Security Policy and input validation
- **🚫 CSRF Protection**: WordPress nonce validation and origin checking
- **🔒 Directory Traversal Prevention**: Path validation and file access restrictions
- **⚡ Brute Force Protection**: Rate limiting and temporary IP blocking

## 🐛 Debugging & Troubleshooting

### Debug Mode
1. **Enable Debug Mode**: Check the debug option in plugin settings
2. **View Console Logs**: Open browser developer tools → Console tab
3. **Check Admin Logs**: Navigate to GA4 Tagging → Logs section
4. **Test Configuration**: Use debug functions in console

### Common Issues

**A/B Testing Not Working:**
```javascript
// Check if A/B testing is enabled
console.log('A/B Testing Config:', {
    enabled: window.ga4ServerSideTagging.abTestsEnabled,
    config: window.ga4ServerSideTagging.abTestsConfig
});

// Check if test elements exist
console.log('Test Elements:', {
    variantA: document.querySelectorAll('.button-red').length,
    variantB: document.querySelectorAll('.button-blue').length
});
```

**Click Tracking Not Working:**
```javascript
// Check click tracking configuration
console.log('Click Tracking Config:', {
    enabled: window.ga4ServerSideTagging.clickTracksEnabled,
    config: window.ga4ServerSideTagging.clickTracksConfig
});

// Test click tracking manually
GA4ServerSideTagging.trackClickEvent('test_click', '.my-button', document.querySelector('.my-button'));
```

**Events Not Sending:**
```javascript
// Check consent status
console.log('Consent Status:', GA4Utils.consent.getForServerSide());

// Verify client ID generation
console.log('Client ID:', GA4Utils.clientId.get());

// Check for bot detection
console.log('Bot Detection:', GA4Utils.botDetection.isBot(userAgent, session, behavior));
```

**Server-Side Issues:**
- Check Cloudflare Worker logs for API key validation errors
- Verify CORS headers include `X-API-Key`
- Ensure worker API key matches WordPress generated key
- Check rate limiting and domain whitelisting

**🔐 Encryption Issues:**
```javascript
// Test encryption functionality
const testKey = 'your-64-character-hex-encryption-key';
const testData = 'test data';

// Test basic encryption
GA4Utils.encryption.encrypt(testData, testKey)
    .then(encrypted => {
        console.log('Encryption successful:', encrypted);
        return GA4Utils.encryption.decrypt(encrypted, testKey);
    })
    .then(decrypted => {
        console.log('Decryption successful:', decrypted);
        console.log('Round-trip match:', decrypted === testData);
    })
    .catch(error => {
        console.error('Encryption test failed:', error);
    });

// Check encryption compatibility
console.log('Web Crypto API available:', window.crypto && window.crypto.subtle ? 'Yes' : 'No (XOR fallback)');
console.log('Encryption key length:', testKey.length, '(should be 64)');
console.log('Encryption key format:', /^[0-9a-fA-F]+$/.test(testKey) ? 'Valid hex' : 'Invalid format');
```

**Cloudflare Worker Encryption Setup:**
- Ensure `JWT_ENCRYPTION_ENABLED = true` in worker
- Verify `ENCRYPTION_KEY` matches WordPress generated key exactly
- Check worker logs for encryption/decryption errors
- Ensure `X-Encrypted: true` header is being sent by client

## 📊 Data Categories & Retention

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
   - A/B test variant assignments
   - Click tracking data

3. **Advertising Data** (requires separate consent):
   - Google Click IDs (gclid)
   - UTM campaign parameters
   - Cross-site tracking identifiers

### Data Retention Policies
- **User Data**: Configurable (default: 24 hours)
- **Session Data**: 30 minutes after last activity
- **Consent Data**: 1 year (with manual override)
- **Purchase Tracking**: 30 minutes (prevents duplicates)
- **A/B Test Data**: Same as user data retention
- **Click Tracking Data**: Same as user data retention

## 📈 Performance & Analytics Enhancement

### A/B Testing Analytics
- Automatic GA4 event tracking for variant interactions
- Session-based variant assignment consistency
- Conversion tracking with test attribution
- Statistical significance calculations (external tools recommended)

### Click Tracking Analytics
- Comprehensive click context data
- Element identification and text content
- Session duration at time of click
- Integration with GA4 conversion funnels

### Enhanced Attribution
- **First-party data** prioritized over third-party cookies
- **Session continuity** across page loads
- **Cross-domain tracking** support
- **A/B test attribution** for conversion analysis

## 🆘 Support & Resources

### Documentation
- **API Reference**: Complete function documentation in code comments
- **WordPress Hooks**: All available actions and filters documented
- **Configuration Examples**: Real-world implementation samples
- **A/B Testing Guide**: Best practices for test setup and analysis
- **Click Tracking Guide**: Event naming and selector optimization

### Troubleshooting Tools
- **Debug Console**: Built-in debug mode with comprehensive logging
- **Data Inspector**: `GA4Utils.helpers.getStoredDataSummary()`
- **Performance Monitor**: Built-in timing and performance metrics
- **Test Validation**: Automatic event name sanitization and validation

### 🔐 **Encryption Testing Framework (NEW)**
Comprehensive tools for testing encryption compatibility across all platforms:

**Test Files:**
- `test-encryption-compatibility.php` - PHP backend encryption testing
- `test-encryption-vectors.js` - JavaScript browser compatibility testing
- `ENCRYPTION-COMPATIBILITY-TEST.md` - Complete testing guide

**Cross-Platform Testing:**
```bash
# Run PHP encryption tests (requires WordPress environment)
php test-encryption-compatibility.php

# Load JavaScript tests in browser
<script src="test-encryption-vectors.js"></script>
<script>runCompatibilityTests();</script>

# Test Cloudflare Worker encryption
# (Copy test code from generated output)
```

**Automated Compatibility Verification:**
- ✅ PHP ↔ JavaScript encryption compatibility
- ✅ JavaScript ↔ Cloudflare Worker compatibility  
- ✅ PHP ↔ Cloudflare Worker compatibility
- ✅ Request/response encryption end-to-end
- ✅ AES-256-GCM vs XOR fallback testing
- ✅ Key format and length validation

## 📄 License

This plugin is licensed under the GPL v2 or later.

## 👥 Credits

**Developed by**: Jacht Digital Marketing  
**Contributors**: [List of contributors]  
**Special Thanks**: WordPress community, GA4 developer community

---

*For detailed API documentation, advanced configuration examples, and troubleshooting guides, visit our comprehensive documentation site.*