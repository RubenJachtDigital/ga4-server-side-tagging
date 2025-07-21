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
- **🔒 PHP Session-based Duplicate Prevention** - Server-side order tracking with session management
- **🔐 Secure Configuration Delivery** - JWT-encrypted API keys and settings with rotating keys
- **📊 Complete Payload Logging** - Debug mode logs complete GA4 payloads for troubleshooting
- **Automatic data migration** from legacy storage systems
- **Comprehensive debugging** and logging system

### Performance & Reliability
- **🚀 Event Batching System** - Queue events and send as optimized batches every 5 minutes
- **📊 Cronjob Management** - WordPress cron-based processing with admin monitoring interface
- **⚡ Batch Processing** - Up to 1000 events per batch for improved Cloudflare Worker performance
- **🔄 Retry Logic** - Failed events automatically retried with comprehensive error logging
- **📈 Queue Analytics** - Real-time statistics on event processing, success/failure rates
- **🧹 Automatic Cleanup** - Configurable retention of processed events (1-365 days)
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

## 🔄 Data Flow & Transmission Methods

### 📡 Multi-Transmission Method Support

The plugin supports **4 different transmission methods** to accommodate various security and performance requirements:

| Method | Security Level | Performance | Use Case |
|--------|---------------|-------------|----------|
| **🔄 Direct to CF** | Basic | Fastest | Development/Testing |
| **🔒 WordPress Endpoint** | Standard | Fast | Production Sites |
| **🔐 Encrypted WordPress** | Maximum | Moderate | High-Security Sites |
| **🔑 Legacy API Key** | Full | Moderate | Existing Implementations |

### 🔄 **Method 1: Direct to Cloudflare** (`direct_to_cf`)
**Direct client-to-worker communication with minimal security overhead**

```javascript
// Client Request
fetch('https://your-worker.workers.dev/', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Simple-request': 'true'  // Identifies simple transmission
  },
  body: JSON.stringify({
    name: 'page_view',
    params: { client_id: 'xxx', session_id: 'yyy' }
  })
})
```

**Security Features:**
- ✅ **Basic Rate Limiting**: IP-based request throttling
- ✅ **CORS Protection**: Origin validation
- ✅ **Payload Validation**: Basic structure checks
- ❌ **No API Key Required**: Minimal authentication
- ❌ **No Encryption**: Plain JSON transmission

**Best For:** Development, testing, low-security environments

### 🔒 **Method 2: WordPress Endpoint** (`wp_endpoint_to_cf`)
**Balanced security through WordPress validation with standard transmission**

```javascript
// Client Request
fetch('/wp-json/ga4-server-side-tagging/v1/send-event', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': 'wordpress-nonce-value'  // WordPress security nonce
  },
  body: JSON.stringify({
    name: 'purchase',
    params: { transaction_id: '12345', value: 99.99 }
  })
})
```

**Security Features:**
- ✅ **WordPress Validation**: Nonce-based request authentication
- ✅ **Rate Limiting**: 100 requests/minute per IP
- ✅ **Bot Detection**: 70+ user agent patterns
- ✅ **Origin Validation**: Same-domain CORS checking
- ✅ **Field Validation**: Required GA4 fields verification
- ❌ **No Payload Encryption**: Plain JSON transmission

**Best For:** Standard production sites, balanced security/performance

### 🔐 **Method 3: Encrypted WordPress** (`secure_wp_to_cf`)
**Maximum security with JWT encryption and WordPress validation**

```javascript
// Client Request (with JWT encryption)
fetch('/wp-json/ga4-server-side-tagging/v1/send-event', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': 'wordpress-nonce-value',
    'X-Encrypted': 'true'  // Indicates JWT encrypted payload
  },
  body: JSON.stringify({
    time_jwt: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...'  // Encrypted event data
  })
})
```

**Security Features:**
- ✅ **WordPress Validation**: Nonce-based authentication
- ✅ **JWT Encryption**: Time-based payload encryption
- ✅ **Rate Limiting**: 100 requests/minute per IP
- ✅ **Bot Detection**: Multi-layered analysis
- ✅ **Origin Validation**: Strict same-domain checking
- ✅ **Payload Encryption**: Complete event data encrypted
- ✅ **Key Rotation**: Automatic 5-minute key rotation

**Best For:** High-security sites, sensitive data, compliance requirements

### 🔑 **Method 4: Legacy API Key** (`regular`)
**Full security validation with direct API key authentication**

```javascript
// Client Request (with API key)
fetch('https://your-worker.workers.dev/', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer your-api-key',
    'X-Encrypted': 'true'  // Optional encryption
  },
  body: JSON.stringify({
    name: 'add_to_cart',
    params: { item_id: 'product_123', value: 29.99 }
  })
})
```

**Security Features:**
- ✅ **API Key Authentication**: Bearer token validation
- ✅ **Full Security Validation**: Complete security checks
- ✅ **Origin Validation**: Header consistency validation
- ✅ **Rate Limiting**: IP-based throttling
- ✅ **Bot Detection**: Advanced pattern analysis
- ✅ **Optional Encryption**: JWT payload encryption available
- ✅ **Domain Whitelisting**: Configurable allowed domains

**Best For:** Existing implementations, API-first architectures

### 🛡️ **Security Comparison Matrix**

| Security Feature | Direct CF | WordPress | Encrypted WP | Legacy API |
|------------------|-----------|-----------|--------------|------------|
| **Authentication** | None | WP Nonce | WP Nonce | API Key |
| **Rate Limiting** | Basic | Standard | Standard | Advanced |
| **Bot Detection** | None | Standard | Advanced | Advanced |
| **Origin Validation** | Basic | Standard | Strict | Advanced |
| **Payload Encryption** | ❌ | ❌ | ✅ | Optional |
| **Key Rotation** | N/A | N/A | Auto | Manual |
| **CORS Protection** | Basic | Standard | Strict | Advanced |
| **Field Validation** | Basic | Standard | Advanced | Advanced |

### Complete Data Flow Pipeline

```
Client Browser → WordPress API → Event Queue → Batch Processor → Cloudflare Worker → Google Analytics 4
     ↓              ↓               ↓             ↓                ↓                ↓
1. Event Generated → 2. Security Validation → 3. Database Storage → 4. Batch Processing → 5. Server Processing → 6. GA4 Delivery
   • Attribution     • Rate Limiting (100/min)  • Event Queuing      • Every 5 minutes    • Bot Detection      • Clean Events
   • Consent Check   • Bot Detection           • Encryption         • Up to 1000 events  • GDPR Processing    • Proper Consent
   • Encryption      • Origin Validation       • Database Storage   • Single HTTP Request • Event Enhancement  • Attribution Data
   • Client Data     • API Key Encryption      • Status Tracking    • Error Handling     • Response Processing
```

## 🚀 **Event Batching System (NEW)**

### **Optimized Performance Architecture**

The plugin now features an advanced **event batching system** that queues events locally and processes them in optimized batches every 5 minutes, dramatically improving performance and reliability:

**Key Benefits:**
- **⚡ 95% Fewer HTTP Requests** - One batch request instead of hundreds of individual requests
- **🚀 Improved CF Worker Performance** - Single batch processing vs multiple individual events
- **🔄 Enhanced Reliability** - Failed events automatically queued for retry
- **📊 Complete Monitoring** - Real-time queue statistics and processing analytics
- **🧹 Smart Cleanup** - Automatic cleanup of old processed events

### **Batch Processing Flow**

```
Individual Events → Database Queue → WordPress Cron → Batch Request → Cloudflare Worker
     ↓                   ↓               ↓              ↓                ↓
1. Event Captured → 2. Queue Storage → 3. Scheduled Processing → 4. Single HTTP Request → 5. Parallel GA4 Forwarding
   • page_view        • Encrypted        • Every 5 minutes      • Up to 1000 events    • Individual event processing
   • purchase         • Status tracking  • WordPress cron       • JWT encrypted        • GDPR compliance per event
   • add_to_cart      • Error logging    • Batch processor      • Single response      • Bot detection per batch
   • form_submit      • Retry counting   • Automatic cleanup    • Success/failure      • Attribution preserved
```

### **Queue Management Interface**

**Admin Dashboard:** `WordPress Admin → GA4 Tagging → Cronjobs`

**Real-time Analytics:**
- **📊 Queue Statistics** - Total, pending, completed, and failed events
- **⏰ Next Processing Time** - Countdown to next batch processing
- **📈 Processing History** - Recent events with status and error details
- **🔄 Manual Triggers** - Test cronjob processing with one-click

**Management Controls:**
- **▶️ Manual Processing** - Trigger immediate batch processing for testing
- **🧹 Cleanup Controls** - Remove old processed events (1-365 days retention)
- **⚙️ Configuration Options** - Batch size (100-10,000 events), cleanup intervals
- **📋 Event Inspection** - View individual event details and processing status

### **Configuration Options**

**Batch Processing Settings:**
```php
// Enable/disable cronjob batching (WordPress Admin → GA4 Settings)
'ga4_cronjob_enabled' => true,          // Enable batch processing
'ga4_cronjob_batch_size' => 1000,       // Events per batch (100-10,000)
'ga4_cronjob_cleanup_days' => 7,        // Days to keep processed events

// Cronjob runs every 5 minutes via WordPress cron
wp_schedule_event(time(), 'ga4_five_minutes', 'ga4_process_event_queue');
```

**Legacy Direct Sending:**
- **Fallback Mode** - Disable cronjob to use original direct sending behavior  
- **Debug Compatibility** - Full backward compatibility for existing implementations
- **Performance Trade-off** - Direct sending for immediate processing vs batching for efficiency

### **Database Storage**

**Events Queue Table:** `wp_ga4_events_queue`
```sql
CREATE TABLE wp_ga4_events_queue (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    event_data longtext NOT NULL,           -- JSON event data
    is_encrypted tinyint(1) DEFAULT 0,      -- Encryption flag
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    processed_at datetime NULL,
    status varchar(20) DEFAULT 'pending',   -- pending/completed/failed
    retry_count int(11) DEFAULT 0,
    error_message text NULL,
    PRIMARY KEY (id),
    KEY status (status),
    KEY created_at (created_at)
);
```

**Storage Benefits:**
- **🔒 Encrypted Storage** - Events encrypted in database when secured transmission enabled
- **📊 Status Tracking** - Complete processing history with error logging
- **🔄 Retry Management** - Failed events automatically queued for retry attempts
- **📈 Analytics Ready** - Rich metadata for processing analytics and reporting

### **Batch Request Structure**

**Cloudflare Worker Payload:**
```javascript
// Single batch request containing multiple events
{
  "events": [
    { "name": "page_view", "params": { "client_id": "...", "session_id": "..." } },
    { "name": "purchase", "params": { "transaction_id": "123", "value": 99.99 } },
    { "name": "add_to_cart", "params": { "item_id": "product_456" } }
    // ... up to 1000 events per batch
  ],
  "batch": true,                    // Indicates batch processing
  "consent": {                      // Batch-level consent (from first event)
    "consent_mode": "GRANTED",
    "analytics_consent": true,
    "advertising_consent": true
  },
  "timestamp": 1642534567           // Batch processing timestamp
}
```

**Worker Processing:**
- **🔄 Individual Processing** - Each event processed individually with full GDPR/bot detection
- **📊 Batch Response** - Single response with success/failure counts for entire batch
- **⚡ Optimized Performance** - Worker processes batch more efficiently than individual requests
- **🛡️ Security Preserved** - All existing security and compliance features maintained

### 🔐 End-to-End Encryption Flow

**1. Client-Side Event Encryption (Optional):**
```javascript
Event Data → Time-based JWT → Encrypted Payload → WordPress API
     ↓
{
  "time_jwt": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",  // Encrypted event data
  "X-Encrypted": "true"                                    // Encryption indicator
}
```

**2. WordPress Processing & API Key Encryption:**
```php
Encrypted Event → Decryption → Validation → API Key Encryption → Cloudflare
     ↓               ↓           ↓              ↓
Time-based JWT  → Event Data → Rate Limit → Bearer JWT Token → Worker
Static JWT      → Validation → Origin Check → (Encrypted API key)
```

**3. Cloudflare Worker Security & Decryption:**
```javascript
Request → API Key Decryption → Bot Detection → Event Processing → GA4
   ↓          ↓                  ↓              ↓               ↓
Headers → JWT Verification → Pattern Analysis → GDPR Rules → Clean Events
Payload → Format Detection → Behavior Scoring → Attribution → GA4 API
```

### 🔑 Encryption Key Types & Usage

| Encryption Type | Key Source | Usage | Rotation |
|------------------|------------|--------|----------|
| **Time-based JWT** | Self-generating (5-min slots) | Client ↔ WordPress | Automatic |
| **Static JWT** | Admin-generated | WordPress ↔ Cloudflare | Manual |
| **API Key JWT** | Static encryption key | API authentication | Manual |
| **Storage Encryption** | WordPress salts | Database storage | Site-specific |

### 🛡️ Security Validation Layers

**WordPress Endpoint (`/send-event`):**
1. **Rate Limiting**: 100 requests/minute per IP
2. **Origin Validation**: Same-domain CORS check
3. **Bot Detection**: 70+ user agent patterns
4. **HTTPS Enforcement**: TLS 1.2+ required
5. **Header Validation**: Essential headers required
6. **Field Validation**: Required GA4 fields check

**Cloudflare Worker Security:**
1. **API Key Authentication**: JWT encrypted Bearer tokens
2. **Domain Whitelisting**: Configurable allowed domains
3. **Advanced Bot Detection**: Multi-layer analysis
4. **Rate Limiting**: IP-based request throttling
5. **Payload Validation**: Size and structure checks
6. **Origin Verification**: Header consistency validation

### 📊 What Gets Encrypted

**🔐 Always Encrypted:**
- **API Keys**: Cloudflare Worker authentication (WordPress → Cloudflare)
- **Configuration Data**: Secure config endpoint responses with JWT encryption
- **Database Storage**: Encryption keys stored with WordPress salts
- **Temporary Keys**: 5-minute rotating JWT keys for config delivery

**📋 Optionally Encrypted (When JWT Encryption Enabled):**
- **Event Payloads**: Complete event data (Client → WordPress → Cloudflare)
- **Response Data**: Worker responses back to WordPress
- **Attribution Parameters**: Traffic source and campaign data
- **User Identification Data**: Client IDs and session information
- **E-commerce Data**: Order details and product information

**🔓 Never Encrypted:**
- **HTTP Headers**: Required for routing and CORS handling
- **Rate Limiting Data**: IP-based request counting
- **Basic Validation Responses**: Simple success/error messages
- **CORS Preflight Responses**: Browser security validations
- **Error Logs**: Console output and logging data

**🔒 Encryption Methods Used:**
- **JWT with HS256**: Event payload encryption (shared secret)
- **Time-based JWT**: Configuration delivery (rotating 5-min keys)
- **WordPress Salts**: Database encryption key derivation
- **Bearer Token**: API key authentication in HTTP headers

### ⚡ Rate Limiting Implementation

**WordPress (`/send-event`):**
- **Limit**: 100 requests per minute per IP
- **Method**: Sliding window with WordPress transients
- **Response**: HTTP 429 with `retry_after` header
- **Storage**: Temporary memory cache (60-second TTL)

**Cloudflare Worker:**
- **Configurable**: RATE_LIMIT_REQUESTS per IP
- **Method**: Cloudflare KV storage
- **Integration**: Built-in DDoS protection
- **Escalation**: Automatic IP blocking

### 🔒 PHP Session-Based Duplicate Prevention

**Enhanced Purchase Tracking Security:**
```php
// First visit to thank you page
Order ID: 12345 → Session tracking: [] → Send order data → Session: [12345]

// Page refresh/revisit same session
Order ID: 12345 → Session tracking: [12345] → Send orderSent: true → Skip tracking
```

**Implementation Details:**
- **Session Storage**: Server-side PHP sessions (secure, non-manipulable)
- **Duplicate Detection**: Checks `$_SESSION['ga4_tracked_orders']` array
- **Logging**: Warning-level logs when duplicates are prevented
- **Cleanup**: Automatic session maintenance (keeps last 10 orders)
- **Security**: Session-based (more secure than client-side localStorage)

**Benefits:**
- **Prevents revenue inflation** from page refreshes
- **Server-side security** - cannot be bypassed by client manipulation
- **Automatic cleanup** - maintains session efficiency
- **Comprehensive logging** - tracks both successful and prevented duplicates

### 🔐 Secure Configuration System

**JWT-Encrypted Configuration Delivery:**
```javascript
// Configuration request flow
GET /wp-json/ga4-server-side-tagging/v1/secure-config
→ Multi-layered bot detection (6 security checks)
→ Rate limiting (100 requests/hour per IP)
→ JWT encryption with rotating 5-minute keys
→ Encrypted response with API keys and settings
```

**Security Layers:**
1. **Rate Limiting**: 100 requests/hour per IP for config endpoint
2. **Bot Detection**: 6 comprehensive security validation checks
3. **Origin Validation**: Same-domain referrer and origin verification
4. **Browser Fingerprinting**: Validates authentic browser headers
5. **JWT Encryption**: Rotating encryption keys (5-minute rotation)
6. **Request Authentication**: Enhanced security validation

**What Gets Encrypted in Configuration:**
- **API Keys**: Cloudflare Worker authentication tokens
- **Worker URLs**: Secure endpoint locations
- **Encryption Keys**: Event payload encryption secrets
- **Configuration Data**: Sensitive plugin settings

### 📊 Complete Payload Logging

**Cloudflare Worker Error Logging:**
```javascript
// Debug mode logs complete GA4 payloads
if (DEBUG_MODE) {
  console.log("📤 Complete GA4 Payload being sent to Google Analytics:");
  console.log(JSON.stringify(ga4Payload));
}
```

**Logged Data Includes:**
- **Event Names**: page_view, purchase, add_to_cart, etc.
- **Event Parameters**: All processed event data
- **Consent Settings**: GDPR compliance status
- **Attribution Data**: Source, medium, campaign information
- **E-commerce Data**: Transaction details and product information
- **User Identification**: Client ID, session ID, user ID (if present)

**Benefits:**
- **Debug Troubleshooting**: Complete visibility into GA4 data transmission
- **Compliance Verification**: Confirms proper consent handling
- **Data Quality Assurance**: Validates event parameters and formatting
- **Performance Monitoring**: Tracks payload size and structure

## 📋 Requirements

- WordPress 5.2 or higher
- PHP 7.2 or higher with OpenSSL extension
- WooCommerce 4.0 or higher (for e-commerce tracking)
- Google Analytics 4 property with Measurement ID and API Secret
- Cloudflare account (recommended, for server-side tagging)

## 🚀 Installation

1. Upload the `ga4-server-side-tagging` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to the 'GA4 Tagging' menu in your WordPress admin
4. Enter your GA4 Measurement ID and API Secret
5. Configure additional settings as needed

### 🚀 **Automatic Setup (NEW)**
Upon activation, the plugin automatically:
- **📊 Creates Event Queue Table** - Database table for batched event processing
- **⏰ Schedules WordPress Cron** - 5-minute recurring event processing
- **⚙️ Enables Batch Processing** - Default setting for optimized performance
- **🔧 Configures Default Settings** - 1000 events per batch, 7-day cleanup retention

**Post-Installation Steps:**
1. **Monitor Queue**: Visit `GA4 Tagging → Cronjobs` to view real-time queue statistics
2. **Test Processing**: Use "Trigger Cronjob Now" button to test batch processing
3. **Adjust Settings**: Configure batch size and cleanup intervals as needed
4. **Verify Cloudflare**: Ensure CF Worker handles batch requests (automatic compatibility)

## ⚙️ Configuration

### Basic Setup

1. **Create GA4 Property**: Set up a GA4 property in your Google Analytics account
2. **Get Measurement ID**: Copy your Measurement ID (starts with G-)
3. **Create API Secret**: Generate an API Secret in GA4 property settings → Data Streams → Web → Measurement Protocol API secrets
4. **Configure Plugin**: Enter these values in the plugin settings

### Cloudflare Worker Setup (Recommended)

For optimal server-side tagging with enhanced bot protection and multi-transmission method support:

1. **Create Cloudflare Worker**: Set up a new worker in your Cloudflare dashboard
2. **Deploy Script**: Use the provided `cloudflare-worker-example.js`
3. **Configure Variables and Secrets**: Set up environment variables (see below)
4. **Set Worker URL**: Enter the worker URL in plugin settings
5. **Choose Transmission Method**: Select from 4 available methods:
   - **Direct to CF**: Simple development setup (no API key required)
   - **WordPress Endpoint**: Balanced security for production
   - **Encrypted WordPress**: Maximum security with JWT encryption
   - **Legacy API Key**: Full validation with direct authentication
6. **Generate API Key**: Click "Generate New Key" in WordPress admin (for Legacy method)
7. **Enable Server-Side Tracking**: Toggle server-side option in admin

### 🔧 **Cloudflare Worker Variables and Secrets Setup**

Instead of hardcoding values in your worker script, use Cloudflare's Variables and Secrets feature for secure configuration:

**Step 1: Create Variables and Secrets**
1. Go to your Cloudflare Worker dashboard
2. Navigate to **Settings → Variables and Secrets**
3. Add the following variables with type "secret":

| Variable Name | Type | Value | Description | Required For |
|---------------|------|-------|-------------|--------------|
| `GA4_MEASUREMENT_ID` | Secret | `G-XXXXXXXXXX` | Your GA4 Measurement ID | All methods |
| `GA4_API_SECRET` | Secret | `your-api-secret-here` | Your GA4 API Secret | All methods 
| `ENCRYPTION_KEY` | Secret | `64-char-hex-key` | Encryption key from WordPress admin | Encrypted WordPress method |
| `ALLOWED_DOMAINS` | Secret | `yourdomain.com,www.yourdomain.com` | Comma-separated list of allowed domains | All methods (recommended) |

**Step 2: Variable Formats**

```bash
# GA4_MEASUREMENT_ID
G-XXXXXXXXXX

# GA4_API_SECRET  
your-ga4-api-secret-from-google-analytics

# ENCRYPTION_KEY (copy from WordPress admin)
0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef

# ALLOWED_DOMAINS (comma-separated, no spaces around commas)
yourdomain.com,www.yourdomain.com,subdomain.yourdomain.com
```

**Step 3: Deploy Worker**
The worker script will automatically load these values from the environment at runtime.

**Step 4: Configure Transmission Method**
Choose the appropriate transmission method for your security requirements:

**Benefits of Using Variables and Secrets:**
- ✅ **Secure Storage**: Sensitive data encrypted by Cloudflare
- ✅ **No Hardcoding**: Values not visible in your worker script
- ✅ **Easy Updates**: Change values without redeploying worker
- ✅ **Version Control Safe**: No secrets in your code repository
- ✅ **Multiple Environments**: Different values for staging/production

**Legacy Configuration (Not Recommended):**
If you prefer to hardcode values in the worker script, update these constants:
```javascript
// In Cloudflare Worker - Update these values (NOT RECOMMENDED)
let GA4_MEASUREMENT_ID = 'G-XXXXXXXXXX'; // Your GA4 Measurement ID
let GA4_API_SECRET = 'your-api-secret-here'; // Your GA4 API Secret
let ALLOWED_DOMAINS = ["yourdomain.com", "www.yourdomain.com"]; // Your domains
let ENCRYPTION_KEY = "your-256-bit-encryption-key-here"; // 64-character hex key from WordPress admin
```

### 🚀 **Cronjob Batch Processing Setup (NEW)**

Configure the advanced event batching system for optimal performance:

**1. Basic Configuration:**
1. Go to WordPress admin → GA4 Tagging settings
2. **Event Batching**: Enable/disable cronjob batch processing
3. **Batch Size**: Configure events per batch (100-10,000, default: 1000)
4. **Cleanup Days**: Set retention period for processed events (1-365 days, default: 7)

**2. Monitor Queue:**
1. Navigate to **GA4 Tagging → Cronjobs** 
2. View real-time statistics: pending, completed, failed events
3. Check next scheduled processing time
4. Review recent processing history and error details

**3. Manual Testing:**
1. Click **"Trigger Cronjob Now"** to process events immediately
2. Monitor processing results in real-time
3. Use **"Cleanup Old Events"** to remove processed events manually
4. Verify batch requests in Cloudflare Worker logs

**Performance Recommendations:**
```php
// High-traffic sites (>10,000 events/day)
'ga4_cronjob_batch_size' => 2000        // Process larger batches
'ga4_cronjob_cleanup_days' => 3         // Cleanup more frequently

// Standard sites (<10,000 events/day)  
'ga4_cronjob_batch_size' => 1000        // Default batch size
'ga4_cronjob_cleanup_days' => 7         // Standard cleanup

// Low-traffic sites (<1,000 events/day)
'ga4_cronjob_batch_size' => 500         // Smaller batches
'ga4_cronjob_cleanup_days' => 14        // Keep events longer
```

**WordPress Cron Configuration:**
```php
// Custom cron schedule (automatically added)
wp_schedule_event(time(), 'ga4_five_minutes', 'ga4_process_event_queue');

// Verify cron is working
wp_next_scheduled('ga4_process_event_queue');  // Returns timestamp if scheduled

// Manual cron trigger (for testing)
do_action('ga4_process_event_queue');
```

**Fallback to Direct Sending:**
- **Disable Batching**: Set `ga4_cronjob_enabled` to `false` for immediate processing
- **Legacy Mode**: Maintains full backward compatibility with existing setup
- **Debug Mode**: Direct sending recommended for development/debugging
- **Performance Trade-off**: Immediate vs optimized batch processing

### 🔐 JWT Encryption Setup (Enhanced)

For enhanced security, enable **AES-256-GCM encryption** for all data transmission:

**1. Generate Encryption Key:**
1. Go to WordPress admin → GA4 Tagging settings
2. Click "Generate Encryption Key" - Creates a secure 256-bit encryption key
3. Key is automatically encrypted and stored in database using WordPress salts

**2. Enable Encryption Features:**
1. Check "Enable JWT Encryption" option
2. Copy the generated key to Cloudflare Worker `ENCRYPTION_KEY` variable
3. Set `JWT_ENCRYPTION_ENABLED = true` in worker
4. Deploy worker with new encryption settings

**3. Automatic API Key Encryption:**
- API keys are automatically encrypted when sending to Cloudflare Worker
- Uses static encryption key (not time-based) for API authentication
- Cloudflare Worker automatically detects and decrypts JWT encrypted API keys
- Fallback to plain text API keys if encryption fails

**Encryption Key Management:**
```php
// WordPress Admin - Automatic key generation and storage
$encryption_key = GA4_Encryption_Util::retrieve_encrypted_key('ga4_jwt_encryption_key');
// Returns decrypted 64-character hex key for use

// Automatic key upgrade - existing plain text keys are encrypted on save
$stored_key = GA4_Encryption_Util::store_encrypted_key($key, 'ga4_jwt_encryption_key');
```

**Multi-Layer Encryption Benefits:**
- **🔒 Event Data Encryption**: Client ↔ WordPress (optional, time-based JWT)
- **🔑 API Key Encryption**: WordPress ↔ Cloudflare (automatic, static JWT)  
- **🛡️ Response Encryption**: Cloudflare ↔ WordPress (optional, static JWT)
- **💾 Storage Encryption**: Database keys encrypted with WordPress salts
- **🔄 Key Rotation**: One-click regeneration with automatic re-encryption
- **⚡ Format Detection**: Automatic encryption format detection and handling

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

### 🛡️ **Server-Side Security (Enhanced)**

**WordPress Endpoint Security:**
- **⚡ Rate Limiting**: 100 requests/minute per IP with sliding window algorithm
- **🔐 API Key Encryption**: Automatic JWT encryption of API keys to Cloudflare Worker
- **🛡️ Multi-Layer Bot Detection**: 70+ user agent patterns with behavioral analysis
- **🌍 Origin Validation**: Strict same-domain CORS validation
- **🔒 HTTPS Enforcement**: TLS 1.2+ required for all secure endpoints
- **📋 Header Validation**: Essential browser headers required for authenticity
- **🔍 Field Validation**: Complete GA4 required fields verification
- **🚫 Query Parameter Filtering**: Blocks suspicious parameters (cmd, exec, eval, etc.)

**Cloudflare Worker Security:**
- **🔑 Multi-Method Authentication**: Supports 4 transmission methods with varying security levels
- **📡 Automatic Method Detection**: Headers-based transmission method identification
- **🔐 JWT API Key Decryption**: Automatic detection and decryption of encrypted API keys
- **🌍 Domain Whitelisting**: Configurable allowed domains with origin validation
- **⚡ Adaptive Rate Limiting**: Method-specific rate limiting (basic to advanced)
- **📏 Payload Size Validation**: Prevents oversized requests (default: 50KB max)
- **🔒 CORS Protection**: Explicit header allowlisting with secure defaults
- **🤖 Multi-Layer Bot Detection**: Comprehensive filtering with behavioral analysis and scoring
- **🚫 IP Reputation Filtering**: Cloudflare threat score integration
- **🔍 Request Pattern Analysis**: Suspicious header and behavior detection
- **🎯 Method-Specific Validation**: Security checks tailored to transmission method

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
- Verify CORS headers include `Authorization` header
- Ensure worker API key matches WordPress generated key
- Check rate limiting and domain whitelisting

**Rate Limiting Issues:**
```javascript
// Check rate limit status in browser console
fetch('/wp-json/ga4-server-side-tagging/v1/send-event', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name: 'test_event', params: { client_id: 'test', session_id: 'test' } })
})
.then(response => {
    if (response.status === 429) {
        console.log('Rate limited:', response.headers.get('retry-after'), 'seconds');
    }
    return response.json();
})
.then(data => console.log('Response:', data));
```

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

### 🚀 **Cronjob Batching Issues (NEW)**

**Events Not Processing:**
```php
// Check if cronjob is scheduled (WordPress admin or debug)
$next_run = wp_next_scheduled('ga4_process_event_queue');
echo "Next processing: " . ($next_run ? date('Y-m-d H:i:s', $next_run) : 'Not scheduled');

// Check queue statistics
global $wpdb;
$table_name = $wpdb->prefix . 'ga4_events_queue';
$stats = $wpdb->get_row("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
FROM $table_name", ARRAY_A);
print_r($stats);
```

**Manual Cronjob Testing:**
```php
// Trigger cronjob manually (WordPress admin → Cronjobs → Trigger Cronjob Now)
// OR via PHP:
do_action('ga4_process_event_queue');

// Check for recent events
$recent = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY created_at DESC LIMIT 10");
foreach ($recent as $event) {
    echo "Event {$event->id}: {$event->status} - {$event->created_at}\n";
    if ($event->error_message) {
        echo "Error: {$event->error_message}\n";
    }
}
```

**WordPress Cron Issues:**
```php
// Check if WordPress cron is working
$crons = _get_cron_array();
$ga4_crons = array_filter($crons, function($time_crons) {
    return isset($time_crons['ga4_process_event_queue']);
});

echo "GA4 cron jobs scheduled: " . count($ga4_crons) . "\n";

// Check if cron is disabled
if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
    echo "WARNING: WP_CRON is disabled. Set up system cron job.\n";
    echo "Add to system crontab: */5 * * * * curl -s https://yoursite.com/wp-cron.php\n";
}
```

**Batch Processing Errors:**
```javascript
// Check Cloudflare Worker logs for batch processing errors
// Look for these error patterns:

// 1. Batch size issues
"Error: Batch too large" // Reduce ga4_cronjob_batch_size

// 2. Timeout issues  
"Error: Request timeout" // Reduce batch size or check CF Worker limits

// 3. Malformed batch data
"Error: Invalid batch structure" // Check event data structure in queue

```

**Database Table Issues:**
```sql
-- Check if queue table exists
SHOW TABLES LIKE 'wp_ga4_events_queue';

-- Check table structure
DESCRIBE wp_ga4_events_queue;

-- Check for corrupt events
SELECT id, status, error_message, created_at 
FROM wp_ga4_events_queue 
WHERE status = 'failed' 
ORDER BY created_at DESC 
LIMIT 10;

-- Clean up stuck events (if needed)
UPDATE wp_ga4_events_queue 
SET status = 'pending', retry_count = 0 
WHERE status = 'failed' AND retry_count < 3;
```

**Performance Optimization:**
```php
// For high-traffic sites, optimize batch processing:

// 1. Increase batch size
update_option('ga4_cronjob_batch_size', 2000);

// 2. Decrease cleanup retention  
update_option('ga4_cronjob_cleanup_days', 3);

// 3. Monitor database size
$table_size = $wpdb->get_var("
    SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'MB' 
    FROM information_schema.TABLES 
    WHERE table_schema = DATABASE() 
    AND table_name = '{$wpdb->prefix}ga4_events_queue'
");
echo "Queue table size: {$table_size} MB\n";
```

**Fallback to Direct Sending:**
```php
// If cronjob issues persist, disable batching temporarily
update_option('ga4_cronjob_enabled', false);

// This will revert to direct sending behavior (legacy mode)
// Events will be sent immediately instead of queued
// Useful for debugging and emergency situations
```

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