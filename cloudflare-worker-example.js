/**
 * Enhanced GA4 Server-Side Tagging with GDPR Compliance
 * WITH COMPREHENSIVE BOT DETECTION AND CONSENT HANDLING
 *
 * This worker receives events from the WordPress plugin and forwards them to GA4
 * with full GDPR compliance and consent management
 *
 * @version 2.3.0 - Multi-Transmission Method Support
 * 
 * TRANSMISSION METHODS SUPPORTED:
 * ==============================
 * 1. direct_to_cf (Simple): Direct client-to-worker, minimal security, no API key
 *    - Headers: X-Simple-request: true
 *    - Payload: Plain JSON
 *    - Security: Basic rate limiting only
 * 
 * 2. wp_endpoint_to_cf (Standard): WordPress endpoint to worker, balanced security
 *    - Headers: X-WP-Nonce: [nonce]
 *    - Payload: Plain JSON
 *    - Security: WordPress validation + rate limiting
 * 
 * 3. secure_wp_to_cf (Encrypted): WordPress endpoint to worker, maximum security
 *    - Headers: X-WP-Nonce: [nonce], X-Encrypted: true
 *    - Payload: JWT encrypted (time_jwt field)
 *    - Security: WordPress validation + JWT encryption + rate limiting
 * 
 * 4. regular (Legacy): Direct with API key, full security validation
 *    - Headers: Authorization: Bearer [api_key]
 *    - Payload: Plain JSON or JWT encrypted
 *    - Security: API key + origin validation + rate limiting
 * 
 * IMPORTANT: CONFIGURATION REQUIRED
 * ================================
 * This worker requires Cloudflare Variables and Secrets to be configured.
 * 
 * Go to your Worker → Settings → Variables and Secrets and add these secrets:
 * 
 * GA4_MEASUREMENT_ID    = Your GA4 Measurement ID (e.g., G-XXXXXXXXXX)
 * GA4_API_SECRET        = Your GA4 API Secret from Google Analytics
 * API_KEY              = API key from WordPress plugin admin (for legacy method)
 * ENCRYPTION_KEY       = JWT encryption key from WordPress plugin admin
 * ALLOWED_DOMAINS      = Comma-separated domains (e.g., example.com,www.example.com)
 * 
 * DO NOT hardcode these values in this script - always use environment variables!
 */

// GA4 Configuration
var DEBUG_MODE = true; // Set to true to enable debug logging
const GA4_ENDPOINT = "https://www.google-analytics.com/mp/collect";

// Bot Detection Configuration
const BOT_DETECTION_ENABLED = true; // Set to false to disable bot filtering
const BOT_LOG_ENABLED = true; // Set to false to disable bot logging

// Security Configuration
// These are loaded from Cloudflare Variables and Secrets - DO NOT HARDCODE
const RATE_LIMIT_REQUESTS = 100; // Max requests per IP per minute
const RATE_LIMIT_WINDOW = 60; // Rate limit window in seconds
const MAX_PAYLOAD_SIZE = 50000; // Max payload size in bytes (50KB)
const REQUIRE_API_KEY = true; // Set to true to require API key authentication

// JWT Encryption Configuration
const JWT_ENCRYPTION_ENABLED = true; // Set to true to enable JWT encryption

// These are loaded from Cloudflare Variables and Secrets - DO NOT HARDCODE
let GA4_MEASUREMENT_ID; // Loaded from env.GA4_MEASUREMENT_ID
let GA4_API_SECRET; // Loaded from env.GA4_API_SECRET
let ENCRYPTION_KEY; // Loaded from env.ENCRYPTION_KEY
let ALLOWED_DOMAINS; // Loaded from env.ALLOWED_DOMAINS (comma-separated)
let API_KEY; // Loaded from env.API_KEY

/**
 * =============================================================================
 * JWT ENCRYPTION UTILITIES
 * =============================================================================
 */

/**
 * Create JWT token with encrypted payload
 * @param {string} plaintext - Text to encrypt as JWT payload
 * @param {string} keyHex - Backend key as hex string
 * @returns {Promise<string>} - JWT token
 */
async function encrypt(plaintext, keyHex) {
  try {
    if (keyHex.length === 64) {
      return await createJWTToken(plaintext, keyHex);
    } else {
      throw new Error('Invalid key length for JWT encryption');
    }
  } catch (error) {
    console.warn('JWT creation failed:', error);
    throw error;
  }
}

/**
 * Encrypt data using AES-256-GCM
 * @param {string} plaintext - Data to encrypt
 * @param {Uint8Array} key - 32-byte encryption key
 * @returns {Promise<Object>} - Object containing encrypted data, IV, and tag
 */
async function encryptAESGCM(plaintext, key) {
  try {
    // Generate random IV (12 bytes for GCM)
    const iv = crypto.getRandomValues(new Uint8Array(12));
    
    // Import the key for AES-GCM
    const cryptoKey = await crypto.subtle.importKey(
      'raw',
      key,
      { name: 'AES-GCM' },
      false,
      ['encrypt']
    );
    
    // Encrypt the data
    const encoder = new TextEncoder();
    const encryptedData = await crypto.subtle.encrypt(
      {
        name: 'AES-GCM',
        iv: iv
      },
      cryptoKey,
      encoder.encode(plaintext)
    );
    
    // Extract tag and data (Web Crypto API returns them together)
    const encryptedArray = new Uint8Array(encryptedData);
    const dataWithoutTag = encryptedArray.slice(0, -16); // Remove last 16 bytes (tag)
    const authTag = encryptedArray.slice(-16); // Last 16 bytes are the authentication tag
    
    return {
      encrypted: dataWithoutTag,
      iv: iv,
      tag: authTag
    };
  } catch (error) {
    throw new Error('AES-GCM encryption failed: ' + error.message);
  }
}

/**
 * Decrypt data using AES-256-GCM
 * @param {Uint8Array} encryptedData - Encrypted data
 * @param {Uint8Array} iv - Initialization vector  
 * @param {Uint8Array} tag - Authentication tag
 * @param {Uint8Array} key - 32-byte encryption key
 * @returns {Promise<string>} - Decrypted plaintext
 */
async function decryptAESGCM(encryptedData, iv, tag, key) {
  try {
    // Import the key for AES-GCM
    const cryptoKey = await crypto.subtle.importKey(
      'raw',
      key,
      { name: 'AES-GCM' },
      false,
      ['decrypt']
    );
    
    // Combine encrypted data and tag for Web Crypto API
    const encryptedWithTag = new Uint8Array(encryptedData.length + tag.length);
    encryptedWithTag.set(encryptedData);
    encryptedWithTag.set(tag, encryptedData.length);
    
    // Decrypt the data
    const decryptedData = await crypto.subtle.decrypt(
      {
        name: 'AES-GCM',
        iv: iv
      },
      cryptoKey,
      encryptedWithTag
    );
    
    const decoder = new TextDecoder();
    return decoder.decode(decryptedData);
  } catch (error) {
    throw new Error('AES-GCM decryption failed: ' + error.message);
  }
}

/**
 * Verify JWT token and extract payload
 * @param {string} jwtToken - JWT token to verify
 * @param {string} keyHex - Backend key as hex string
 * @returns {Promise<string>} - Decrypted plaintext
 */
async function decrypt(jwtToken, keyHex) {
  try {
    if (keyHex.length === 64) {
      return await verifyJWTToken(jwtToken, keyHex);
    } else {
      throw new Error('Invalid key length for JWT verification');
    }
  } catch (error) {
    console.warn('JWT verification failed:', error);
    throw error;
  }
}

/**
 * Create JWT token using HMACSHA256 with AES-GCM encrypted payload
 */
async function createJWTToken(plaintext, keyHex) {
  const keyBytes = hexToBytes(keyHex);
  
  // JWT Header - indicate that payload is encrypted
  const header = {
    typ: 'JWT',
    alg: 'HS256',
    enc: 'A256GCM' // Indicate AES-256-GCM encryption
  };
  
  // Encrypt the plaintext data using AES-GCM
  const encryptionResult = await encryptAESGCM(plaintext, keyBytes);
  
  // JWT Payload - contains encrypted data, IV, and authentication tag
  const payload = {
    enc_data: base64urlEncode(encryptionResult.encrypted),
    iv: base64urlEncode(encryptionResult.iv),
    tag: base64urlEncode(encryptionResult.tag),
    iat: Math.floor(Date.now() / 1000),
    exp: Math.floor(Date.now() / 1000) + 300 // 5 minutes expiry
  };
  
  // Base64URL encode header and payload
  const headerEncoded = base64urlEncode(JSON.stringify(header));
  const payloadEncoded = base64urlEncode(JSON.stringify(payload));
  
  // Create signature using HMACSHA256
  const signatureInput = headerEncoded + '.' + payloadEncoded;
  const signature = await createHMACSHA256(signatureInput, keyBytes);
  const signatureEncoded = base64urlEncode(signature);
  
  // Return complete JWT token
  return headerEncoded + '.' + payloadEncoded + '.' + signatureEncoded;
}

/**
 * Verify JWT token and extract payload
 */
async function verifyJWTToken(jwtToken, keyHex) {
  const keyBytes = hexToBytes(keyHex);
  
  // Split JWT token into parts
  const parts = jwtToken.split('.');
  if (parts.length !== 3) {
    throw new Error('Invalid JWT token format');
  }
  
  const [headerEncoded, payloadEncoded, signatureEncoded] = parts;
  
  // Verify signature first
  const signatureInput = headerEncoded + '.' + payloadEncoded;
  const expectedSignature = await createHMACSHA256(signatureInput, keyBytes);
  const providedSignature = base64urlDecode(signatureEncoded);
  
  if (!arrayBuffersEqual(expectedSignature, providedSignature)) {
    throw new Error('JWT signature verification failed');
  }
  
  // Decode and validate payload
  const payload = JSON.parse(new TextDecoder().decode(base64urlDecode(payloadEncoded)));
  
  // Check expiration
  if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) {
    throw new Error('JWT token has expired');
  }
  
  // Check header for encryption information
  const header = JSON.parse(new TextDecoder().decode(base64urlDecode(headerEncoded)));
  
  // Handle both encrypted and legacy unencrypted tokens
  if (header.enc === 'A256GCM' && payload.enc_data && payload.iv && payload.tag) {
    // New encrypted format - decrypt the payload
    try {
      const encryptedData = base64urlDecode(payload.enc_data);
      const iv = base64urlDecode(payload.iv);
      const tag = base64urlDecode(payload.tag);
      const decryptedData = await decryptAESGCM(encryptedData, iv, tag, keyBytes);
      return decryptedData;
    } catch (decryptError) {
      throw new Error('JWT payload decryption failed: ' + decryptError.message);
    }
  } else if (payload.data) {
    // Legacy unencrypted format for backwards compatibility
    return payload.data;
  } else {
    throw new Error('JWT payload format not recognized');
  }
}

/**
 * Create HMACSHA256 signature
 */
async function createHMACSHA256(data, key) {
  const encoder = new TextEncoder();
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    key,
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  
  const signature = await crypto.subtle.sign(
    'HMAC',
    cryptoKey,
    encoder.encode(data)
  );
  
  return new Uint8Array(signature);
}

/**
 * Base64URL encode
 */
function base64urlEncode(data) {
  const base64 = btoa(typeof data === 'string' ? data : String.fromCharCode(...new Uint8Array(data)));
  return base64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

/**
 * Base64URL decode
 */
function base64urlDecode(data) {
  const base64 = data.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - data.length % 4) % 4);
  const binary = atob(base64);
  return new Uint8Array(binary.split('').map(char => char.charCodeAt(0)));
}

/**
 * Compare two ArrayBuffers for equality
 */
function arrayBuffersEqual(a, b) {
  if (a.length !== b.length) return false;
  for (let i = 0; i < a.length; i++) {
    if (a[i] !== b[i]) return false;
  }
  return true;
}

/**
 * Convert hex string to bytes
 */
function hexToBytes(hex) {
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < hex.length; i += 2) {
    bytes[i / 2] = parseInt(hex.substr(i, 2), 16);
  }
  return bytes;
}

/**
 * =============================================================================
 * API KEY JWT UTILITIES
 * =============================================================================
 */

/**
 * Verify and decrypt JWT encrypted API key using static encryption key
 * @param {string} jwtToken - JWT token to verify
 * @return {Promise<string|null>} Decrypted API key or null on failure
 */
async function verifyJWTApiKey(jwtToken) {
  if (!ENCRYPTION_KEY) {
    throw new Error('ENCRYPTION_KEY not configured for JWT decryption');
  }
  
  try {
    // Use the static encryption key to decrypt the JWT token
    const decryptedData = await verifyJWTToken(jwtToken, ENCRYPTION_KEY);
    
    // The decrypted data should be the plain API key string
    return decryptedData;
  } catch (error) {
    if (DEBUG_MODE) {
      console.log('JWT API key verification failed with static encryption key:', error.message);
    }
    throw new Error('JWT API key verification failed: ' + error.message);
  }
}


/**
 * =============================================================================
 * GDPR CONSENT HANDLING
 * =============================================================================
 */

/**
 * Process consent data and apply GDPR compliance rules
 * @param {Object} payload - The event payload
 * @returns {Object} Processed payload with consent applied
 */
function processGDPRConsent(payload) {
  // Extract consent data from payload, default to DENIED if no consent data
  const consent = payload.params?.consent || {};
  
  // If no consent data is provided, default to DENIED values (only official GA4 fields)
  const processedConsent = {
    ad_user_data: consent.ad_user_data || "DENIED",
    ad_personalization: consent.ad_personalization || "DENIED"
  };

  // Apply consent-based data filtering based on processed consent
  // Use ad_user_data for analytics consent (user data collection)
  if (processedConsent.ad_user_data === "DENIED") {
    payload = applyAnalyticsConsentDenied(payload);
  }

  // Use ad_personalization for advertising consent
  if (processedConsent.ad_personalization === "DENIED") {
    payload = applyAdvertisingConsentDenied(payload);
  }

  // Always add consent signals to the GA4 payload at the top level
  payload.consent = processedConsent;

  return payload;
}

/**
 * Apply analytics consent denied rules
 * @param {Object} payload - The event payload
 * @returns {Object} Modified payload
 */
function applyAnalyticsConsentDenied(payload) {


  // Remove or anonymize personal identifiers
  if (payload.user_id) {
    delete payload.user_id;
  }

  // Use session-based client ID if available
  if (payload.client_id && !payload.client_id.startsWith("session_")) {
    // Keep session-based client IDs, anonymize persistent ones
    const timestamp = Math.floor(Date.now() / 1000);
    payload.client_id = `session_${timestamp}`;
  }

  // Remove precise location data
  if (payload.params) {
    delete payload.params.geo_latitude;
    delete payload.params.geo_longitude;
    delete payload.params.geo_city;
    delete payload.params.geo_region;
    delete payload.params.geo_country;
    
    // Keep only continent-level location
    // (geo_continent should already be set by client if available)
  }

  // Anonymize user agent in bot data
  if (payload.params?.botData?.user_agent_full) {
    payload.params.botData.user_agent_full = anonymizeUserAgent(payload.params.botData.user_agent_full);
  }

  if (payload.params?.user_agent) {
    payload.params.user_agent = anonymizeUserAgent(payload.params.user_agent);
  }

  return payload;
}

/**
 * Apply advertising consent denied rules
 * @param {Object} payload - The event payload
 * @returns {Object} Modified payload
 */
function applyAdvertisingConsentDenied(payload) {
  if (DEBUG_MODE) {
    console.log("Applying advertising consent denied rules");
  }

  if (payload.params) {
    // Remove advertising attribution data
    delete payload.params.gclid;
    delete payload.params.content;
    delete payload.params.term;

    // Anonymize campaign data for paid traffic
    if (payload.params.campaign && 
        !["(organic)", "(direct)", "(not set)", "(referral)"].includes(payload.params.campaign)) {
      payload.params.campaign = "(denied consent)";
    }

    // Anonymize source/medium for paid traffic
    if (payload.params.medium && 
        ["cpc", "ppc", "paidsearch", "display", "banner", "cpm"].includes(payload.params.medium)) {
      payload.params.source = "(denied consent)";
      payload.params.medium = "(denied consent)";
    }
  }

  return payload;
}

/**
 * Anonymize user agent string
 * @param {string} userAgent - Original user agent
 * @returns {string} Anonymized user agent
 */
function anonymizeUserAgent(userAgent) {
  if (!userAgent) return "";
  
  return userAgent
    .replace(/\d+\.\d+[\.\d]*/g, "x.x") // Replace version numbers
    .replace(/\([^)]*\)/g, "(anonymous)") // Replace system info in parentheses
    .substring(0, 100); // Truncate to 100 characters
}

/**
 * =============================================================================
 * BOT DETECTION SYSTEM
 * =============================================================================
 */

/**
 * Comprehensive bot detection for server-side filtering
 * @param {Request} request - The incoming request
 * @param {Object} payload - The event payload
 * @returns {Object} Detection result with isBot flag and details
 */
function detectBot(request, payload) {
  if (!BOT_DETECTION_ENABLED) {
    return { isBot: false, reason: "detection_disabled" };
  }

  // Get user agent from the right place - prioritize botData, fallback to params, then request headers
  const userAgent = getUserAgent(payload, request);
  const cfData = request.cf || {};
  const country = (cfData.country) || '';
  const city = (String(cfData.city) || '').toLowerCase();
  const region = (cfData.region) || '';
  const params = payload.params || {};

  const checks = [
    checkUserAgentPatterns(userAgent),
    checkSuspiciousGeography(country, city, region),
    checkCloudflareData(cfData),
    checkRequestHeaders(request),
    checkEventData(payload),
    checkIPReputation(cfData),
    checkThreatScore(cfData)
  ];

  // Check WordPress bot data if available
  if (params.botData) {
    checks.push(checkWordPressBotData(params.botData));
  }

  // Check behavior patterns if available
  if (params.botData || params.event_timestamp) {
    checks.push(checkBehaviorPatterns(params.botData || {}, params));
  }

  // Check request patterns
  checks.push(checkRequestPatterns(request));

  const positiveChecks = checks.filter(check => check.isBot);
  const isBot = positiveChecks.length >= 2; // Require at least 2 positive checks

  return {
    isBot: isBot,
    score: positiveChecks.length,
    reasons: positiveChecks.map(check => check.reason),
    details: {
      userAgent: userAgent,
      country: country,
      city: city,
      region: region,
      asn: cfData.asn,
      threatScore: cfData.threatScore
    }
  };
}

/**
 * Get the actual user agent from the best available source
 * @param {Object} payload - The event payload
 * @param {Request} request - The incoming request
 * @returns {string} The user agent string
 */
function getUserAgent(payload, request) {
  const params = payload.params || {};
  
  // Priority 1: botData.user_agent_full (most reliable from WordPress)
  if (params.botData && params.botData.user_agent_full) {
    return params.botData.user_agent_full;
  }
  
  // Priority 2: user_agent in params
  if (params.user_agent) {
    return params.user_agent;
  }
  
  // Priority 3: botData.user_agent (fallback)
  if (params.botData && params.botData.user_agent) {
    return params.botData.user_agent;
  }
  
  // Priority 4: Request header (least reliable for WordPress requests)
  return request ? (request.headers.get('User-Agent') || '') : '';
}

/**
 * Check user agent patterns for bot indicators - ENHANCED
 */
function checkUserAgentPatterns(userAgent) {
  if (!userAgent || userAgent.length < 10) {
    return { isBot: true, reason: "missing_or_short_user_agent" };
  }

  const botPatterns = [
    // Common bots
    /bot/i, /crawl/i, /spider/i, /scraper/i,
    
    // Search engine bots - ENHANCED
    /googlebot/i, /bingbot/i, /yahoo/i, /duckduckbot/i,
    /baiduspider/i, /yandexbot/i, /sogou/i,
    /applebot/i, // Added - Apple's web crawler
    
    // Social media bots
    /facebookexternalhit/i, /twitterbot/i, /linkedinbot/i,
    /whatsapp/i, /telegrambot/i, /discordbot/i,
    
    // SEO/monitoring tools
    /semrushbot/i, /ahrefsbot/i, /mj12bot/i, /dotbot/i,
    /screaming frog/i, /seobility/i, /serpstatbot/i,
    /ubersuggest/i, /sistrix/i,
    
    // Headless browsers and automation
    /headlesschrome/i, /phantomjs/i, /slimerjs/i,
    /htmlunit/i, /selenium/i, /webdriver/i,
    /puppeteer/i, /playwright/i, /cypress/i,
    
    // Monitoring services
    /pingdom/i, /uptimerobot/i, /statuscake/i,
    /site24x7/i, /newrelic/i, /gtmetrix/i,
    /pagespeed/i, /lighthouse/i,
    
    // Generic automation and tools
    /python/i, /requests/i, /curl/i, /wget/i,
    /apache-httpclient/i, /java/i, /okhttp/i,
    /node\.js/i, /go-http-client/i, /http_request/i,
    
    // AI/ML crawlers
    /gptbot/i, /chatgpt/i, /claudebot/i, /anthropic/i,
    /openai/i, /perplexity/i, /cohere/i,
    
    // Academic and research bots
    /researchbot/i, /academicbot/i, /university/i,
    
    // Suspicious patterns
    /^mozilla\/5\.0$/i, // Just "Mozilla/5.0"
    /compatible;?\s*$/i, // Ends with just "compatible"
    /^\s*$/i, // Empty or whitespace only
    
    // Specific problem bots found in logs
    /chrome-lighthouse/i, /pagespeed/i, /lighthouse/i,
    /wp-super-cache/i, /wp-rocket/i, // WordPress caching plugins
  ];

  const matchedPattern = botPatterns.find(pattern => pattern.test(userAgent));
  if (matchedPattern) {
    return { isBot: true, reason: `user_agent_pattern: ${matchedPattern.source.substring(0, 50)}` };
  }

  // Additional checks for suspicious user agent characteristics
  if (userAgent.split(' ').length < 3) {
    return { isBot: true, reason: "suspiciously_simple_user_agent" };
  }

  // Check for missing common browser indicators
  if (!/mozilla|chrome|safari|firefox|edge|opera/i.test(userAgent)) {
    return { isBot: true, reason: "missing_browser_indicators" };
  }

  return { isBot: false, reason: "user_agent_ok" };
}

/**
 * Check suspicious geography patterns
 */
function checkSuspiciousGeography(country, city, region) {
  const suspiciousPatterns = [];

  // Check for suspicious countries (commonly used by bots)
  const suspiciousCountries = ['XX', 'T1', 'ZZ']; // Add more as needed
  if (suspiciousCountries.includes(country)) {
    suspiciousPatterns.push('suspicious_country');
  }

  // Check for generic city names often used by bots
  const suspiciousCities = ['unknown', 'localhost', 'test', '', 'null', 'undefined'];
  if (suspiciousCities.includes(city.toLowerCase())) {
    suspiciousPatterns.push('suspicious_city');
  }

  if (suspiciousPatterns.length >= 1) {
    return { isBot: true, reason: `suspicious_geography: ${suspiciousPatterns.join(', ')}` };
  }

  return { isBot: false, reason: "geography_ok" };
}

/**
 * Check WordPress bot data for comprehensive analysis - ENHANCED
 */
function checkWordPressBotData(botData) {
  var suspiciousPatterns = [];

  // Check bot score passed from WordPress - LOWERED THRESHOLD
  if (botData.bot_score && parseInt(botData.bot_score) > 35) {
    return { isBot: true, reason: 'high_bot_score: ' + botData.bot_score };
  }

  // Check for automation indicators
  if (botData.webdriver_detected === true || botData.has_automation_indicators === true) {
    suspiciousPatterns.push('automation_detected');
  }

  // Check JavaScript availability - be more lenient
  if (botData.has_javascript === false) {
    suspiciousPatterns.push('no_javascript');
  }

  // Check engagement time - ADJUSTED
  if (botData.engagement_calculated && parseInt(botData.engagement_calculated) < 500) {
    suspiciousPatterns.push('very_short_engagement');
  }

  // Enhanced screen dimension checks
  var screenWidth = parseInt(botData.screen_available_width);
  var screenHeight = parseInt(botData.screen_available_height);
  if (screenWidth && screenHeight) {
    // Check for impossible dimensions
    if (screenWidth < 320 || screenHeight < 240 || screenWidth > 7680 || screenHeight > 4320) {
      suspiciousPatterns.push('impossible_screen_dimensions');
    }
  }

  // Enhanced hardware checks
  if (botData.hardware_concurrency === 0) {
    suspiciousPatterns.push('no_hardware_concurrency');
  }
  
  if (botData.max_touch_points === undefined || botData.max_touch_points < 0) {
    suspiciousPatterns.push('suspicious_touch_points');
  }

  // Check for missing or suspicious browser features
  if (botData.cookie_enabled === false) {
    suspiciousPatterns.push('cookies_disabled');
  }

  if (botData.color_depth && (botData.color_depth < 16 || botData.color_depth > 32)) {
    suspiciousPatterns.push('unusual_color_depth');
  }

  // Check timezone consistency
  if (botData.timezone === 'UTC' || botData.timezone === 'GMT') {
    suspiciousPatterns.push('suspicious_timezone');
  }

  // Require fewer patterns for bot detection
  if (suspiciousPatterns.length >= 2) {
    return { isBot: true, reason: 'wordpress_bot_data: ' + suspiciousPatterns.join(', ') };
  }

  return { isBot: false, reason: "wordpress_bot_data_ok" };
}

/**
 * Check behavior patterns using WordPress-passed data - ENHANCED
 */
function checkBehaviorPatterns(botData, params) {
  var suspiciousPatterns = [];

  // Timing analysis
  if (botData.event_creation_time && botData.session_start_time) {
    var sessionDuration = botData.event_creation_time - botData.session_start_time;
    if (sessionDuration < 1000) { // Less than 1 second - more aggressive
      suspiciousPatterns.push('very_short_session');
    }
  }

  // Perfect timing patterns (bots often have regular intervals)
  if (params.event_timestamp) {
    var timestamp = parseInt(params.event_timestamp);
    if (timestamp % 10 === 0 || timestamp % 60 === 0) {
      suspiciousPatterns.push('round_timestamp');
    }
  }

  // Enhanced engagement time analysis
  if (params.engagement_time_msec) {
    var engagementTime = parseInt(params.engagement_time_msec);
    
    // Check for impossible fast engagement
    if (engagementTime < 100) {
      suspiciousPatterns.push('impossible_fast_engagement');
    }
  }

  // Check scroll percentage patterns (for scroll events)
  if (params.percent_scrolled) {
    var scrollPercent = parseInt(params.percent_scrolled);
    // Bots often have perfect scroll percentages
    if ([25, 50, 75, 90, 100].includes(scrollPercent)) {
      suspiciousPatterns.push('perfect_scroll_percentage');
    }
  }

  // Check timezone consistency
  if (botData.timezone) {
    // Bots often use UTC or common default timezones
    var suspiciousTimezones = ['UTC', 'GMT', 'America/New_York', 'Europe/London'];
    if (suspiciousTimezones.indexOf(botData.timezone) !== -1) {
      suspiciousPatterns.push('suspicious_timezone');
    }
  }

  if (suspiciousPatterns.length >= 2) {
    return { isBot: true, reason: 'behavior_patterns: ' + suspiciousPatterns.join(', ') };
  }

  return { isBot: false, reason: "behavior_patterns_ok" };
}

/**
 * Check request patterns (focused on server-to-server communication)
 */
function checkRequestPatterns(request) {
  var headers = {};
  for (var entry of request.headers.entries()) {
    headers[entry[0].toLowerCase()] = entry[1];
  }

  var suspiciousPatterns = [];

  // Check content-type (should be application/json for WordPress requests)
  var contentType = headers['content-type'] || '';
  if (contentType.indexOf('application/json') === -1) {
    suspiciousPatterns.push('unexpected_content_type');
  }

  // Check for automation tools making direct requests
  var userAgent = headers['user-agent'] || '';
  if (/curl|wget|python|node|automation|postman|insomnia/i.test(userAgent)) {
    suspiciousPatterns.push('automation_user_agent');
  }

  // Check for missing expected headers from legitimate WordPress requests
  if (!headers['content-length']) {
    suspiciousPatterns.push('missing_content_length');
  }

  // Check for suspicious origin patterns
  var origin = headers['origin'] || '';
  if (origin && !/^https?:\/\/[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/.test(origin)) {
    suspiciousPatterns.push('suspicious_origin');
  }

  // Check for rate limiting indicators
  var referer = headers['referer'] || '';
  if (!referer && !origin) {
    suspiciousPatterns.push('no_referer_or_origin');
  }

  if (suspiciousPatterns.length >= 2) {
    return { isBot: true, reason: 'request_patterns: ' + suspiciousPatterns.join(', ') };
  }

  return { isBot: false, reason: "request_patterns_ok" };
}

/**
 * Check Cloudflare data for bot indicators
 */
function checkCloudflareData(cfData) {
  // Check for known bot ASNs
  const botASNs = [
    13335, // Cloudflare (sometimes used by bots)
    15169, // Google (Googlebot, etc.)
    16509, // Amazon AWS
    8075,  // Microsoft Azure
    32934, // Facebook
    // Add more as needed
  ];

  if (cfData.asn && botASNs.indexOf(cfData.asn) !== -1) {
    return { isBot: true, reason: 'bot_asn: ' + cfData.asn };
  }

  // Check threat score (0-100, higher = more suspicious)
  if (cfData.threatScore && cfData.threatScore > 50) {
    return { isBot: true, reason: 'high_threat_score: ' + cfData.threatScore };
  }

  return { isBot: false, reason: "cf_data_ok" };
}

/**
 * Check request headers for bot indicators
 */
function checkRequestHeaders(request) {
  var headers = {};
  for (var entry of request.headers.entries()) {
    headers[entry[0].toLowerCase()] = entry[1];
  }

  // Bots often have suspicious header patterns
  var suspiciousHeaders = [
    // Missing common browser headers
    !headers['accept-language'] && 'missing_accept_language',
    !headers['accept-encoding'] && 'missing_accept_encoding',
    !headers['accept'] && 'missing_accept',
    
    // Suspicious header values
    headers['accept'] === '*/*' && 'generic_accept',
    headers['connection'] === 'close' && 'connection_close',
    
    // Known bot headers
    headers['x-forwarded-for'] && headers['x-forwarded-for'].includes('crawler'),
    headers['from'] && 'has_from_header',
  ].filter(Boolean);

  if (suspiciousHeaders.length >= 2) {
    return { isBot: true, reason: `suspicious_headers: ${suspiciousHeaders.join(', ')}` };
  }

  return { isBot: false, reason: "headers_ok" };
}

/**
 * Check event data for bot patterns - ENHANCED
 */
function checkEventData(payload) {
  const params = payload.params || {};
  
  // Check for suspicious event patterns
  const suspiciousPatterns = [];


  // Missing JavaScript indicators
  if (params.has_javascript === false) {
    suspiciousPatterns.push('no_javascript');
  }

  // Check for user agent in params and validate it
  var userAgent = getUserAgent(payload, null);
  if (/webdriver|automation|applebot|bot|crawl|spider/i.test(userAgent)) {
    suspiciousPatterns.push('bot_user_agent_in_params');
  }

  if (suspiciousPatterns.length >= 1) { // Lowered threshold
    return { isBot: true, reason: `event_patterns: ${suspiciousPatterns.join(', ')}` };
  }

  return { isBot: false, reason: "event_data_ok" };
}

/**
 * Check IP reputation using Cloudflare data
 */
function checkIPReputation(cfData) {
  // Cloudflare provides threat scores and other reputation data
  if (cfData.threatScore !== undefined) {
    if (parseInt(cfData.threatScore) > 30) {
      return { isBot: true, reason: `threat_score: ${cfData.threatScore}` };
    }
  }

  return { isBot: false, reason: "ip_reputation_ok" };
}

/**
 * Check Cloudflare threat score
 */
function checkThreatScore(cfData) {
  // Cloudflare Enterprise customers get more detailed threat scores
  if (cfData.threatScore !== undefined && parseInt(cfData.threatScore) > 25) {
    return { isBot: true, reason: `cf_threat_score: ${cfData.threatScore}` };
  }

  return { isBot: false, reason: "threat_score_ok" };
}

/**
 * Log bot detection for analysis
 */
function logBotDetection(detection, request, payload) {
  if (!BOT_LOG_ENABLED) return;

  console.log('Bot Detection Result:', JSON.stringify({
    isBot: detection.isBot,
    score: detection.score,
    reasons: detection.reasons,
    details: detection.details,
    eventName: payload.name,
    timestamp: new Date().toISOString(),
    url: request.url,
    userAgent: getUserAgent(payload, request), // Log the actual UA being checked
    consent_mode: payload.params?.consent?.analytics_storage || 'unknown'
  }));
}

/**
 * =============================================================================
 * SECURITY FUNCTIONS
 * =============================================================================
 */

/**
 * Validate the request origin against allowed domains
 * @param {Request} request - The incoming request
 * @returns {Object} Validation result
 */
function validateOrigin(request) {
  const origin = request.headers.get("Origin");
  const referer = request.headers.get("Referer");

  // Check Origin header first
  if (origin) {
    try {
      const originDomain = new URL(origin).hostname.toLowerCase();
      const isAllowed = ALLOWED_DOMAINS.some(domain => 
        originDomain === domain || originDomain.endsWith('.' + domain)
      );
      
      if (isAllowed) {
        return { valid: true, source: "origin", domain: originDomain };
      }
    } catch (e) {
      return { valid: false, reason: "invalid_origin_format", value: origin };
    }
  }
  
  // Fallback to Referer header
  if (referer) {
    try {
      const refererDomain = new URL(referer).hostname.toLowerCase();
      const isAllowed = ALLOWED_DOMAINS.some(domain => 
        refererDomain === domain || refererDomain.endsWith('.' + domain)
      );
      
      if (isAllowed) {
        return { valid: true, source: "referer", domain: refererDomain };
      }
    } catch (e) {
      return { valid: false, reason: "invalid_referer_format", value: referer };
    }
  }
  
  return { valid: false, reason: "no_valid_origin_or_referer" };
}

/**
 * Rate limiting using memory-based storage
 * @param {Request} request - The incoming request
 * @returns {Promise<Object>} Rate limit result
 */
async function checkRateLimit(request) {
  const clientIP = request.headers.get('CF-Connecting-IP') || 
                   request.headers.get('X-Forwarded-For') || 
                   'unknown';
  
  const now = Math.floor(Date.now() / 1000);
  const windowStart = now - RATE_LIMIT_WINDOW;
  const key = `rate_limit:${clientIP}:${Math.floor(now / RATE_LIMIT_WINDOW)}`;
  
  // Memory-based rate limiting (resets on worker restart)
  if (typeof globalThis.rateLimitMemory === 'undefined') {
    globalThis.rateLimitMemory = new Map();
  }
  
  // Clean old entries
  for (const [mapKey, data] of globalThis.rateLimitMemory.entries()) {
    if (data.timestamp < windowStart) {
      globalThis.rateLimitMemory.delete(mapKey);
    }
  }
  
  const currentData = globalThis.rateLimitMemory.get(key) || { count: 0, timestamp: now };
  currentData.count += 1;
  currentData.timestamp = now;
  
  if (currentData.count > RATE_LIMIT_REQUESTS) {
    return { allowed: false, count: currentData.count, limit: RATE_LIMIT_REQUESTS };
  }
  
  globalThis.rateLimitMemory.set(key, currentData);
  return { allowed: true, count: currentData.count, limit: RATE_LIMIT_REQUESTS };
}

/**
 * Detect API key format and decode accordingly
 * @param {string} apiKey - The raw API key from headers
 * @returns {Promise<{key: string, format: string}>} Decoded key and detected format
 */
async function detectAndDecodeApiKey(apiKey) {
  const originalKey = apiKey;
  
  // 1. Check if it's a JWT token (3 parts separated by dots)
  if (apiKey.includes('.') && apiKey.split('.').length === 3) {
    // 1a. First try JWT API key decryption using static encryption key
    try {
      const jwtDecryptedApiKey = await verifyJWTApiKey(apiKey);
      if (jwtDecryptedApiKey) {
        return { key: jwtDecryptedApiKey, format: 'JWT (Static Key)' };
      }
    } catch (jwtError) {
      if (DEBUG_MODE) {
        console.log("JWT API key decryption failed:", jwtError.message);
      }
    }
    
    // 1b. Fall back to regular JWT decryption
    if (JWT_ENCRYPTION_ENABLED && ENCRYPTION_KEY) {
      try {
        const decryptedKey = await decrypt(apiKey, ENCRYPTION_KEY);
        return { key: decryptedKey, format: 'JWT' };
      } catch (jwtError) {
        if (DEBUG_MODE) {
          console.log("JWT decryption failed:", jwtError.message);
        }
      }
    }
  }
  
  // 2. Check if it's base64 encoded (common pattern: alphanumeric + / + = padding)
  if (/^[A-Za-z0-9+/]*={0,2}$/.test(apiKey) && apiKey.length > 8) {
    try {
      const decodedKey = atob(apiKey);
      // Verify the decoded result looks like a reasonable API key
      if (decodedKey.length >= 8 && /^[A-Za-z0-9_-]+$/.test(decodedKey)) {
        return { key: decodedKey, format: 'Base64' };
      }
    } catch (base64Error) {
      if (DEBUG_MODE) {
        console.log("Base64 decoding failed:", base64Error.message);
      }
    }
  }
  
  // 3. Check if it matches the expected API key directly (plain text)
  if (apiKey === API_KEY) {
    return { key: apiKey, format: 'Plain Text' };
  }
  
  // 4. Last attempt: try base64 decode anyway (some keys might not match the pattern)
  if (apiKey.length > 8) {
    try {
      const decodedKey = atob(apiKey);
      if (decodedKey === API_KEY) {
        return { key: decodedKey, format: 'Base64 (Fallback)' };
      }
    } catch (e) {
      // Ignore error and continue
    }
  }
  
  // 5. Return original key as last resort
  return { key: originalKey, format: 'Unknown/Plain Text' };
}

/**
 * Validate API key if required
 * @param {Request} request - The incoming request
 * @returns {Promise<boolean>} Whether API key is valid
 */
async function validateApiKey(request) {
  let receivedApiKey = request.headers.get('X-API-Key') || 
  request.headers.get('Authorization')?.replace('Bearer ', '');
  
  if (!REQUIRE_API_KEY) return true;
  
  if (!receivedApiKey) {
    if (DEBUG_MODE) {
      console.log("❌ No API key was sent in the request");
    }
    return false;
  }
  
  try {
    // Detect format and decode the API key
    const { key: decodedKey, format } = await detectAndDecodeApiKey(receivedApiKey);
    
    // Compare with expected API key
    const isValid = decodedKey === API_KEY;
    
    if (DEBUG_MODE) {
      if (isValid) {
      } else {
        console.log(`❌ API key validation failed (${format})`);
        console.log(`Expected: ${API_KEY?.substring(0, 8)}...`);
        console.log(`Received: ${decodedKey?.substring(0, 8)}...`);
      }
    }
    
    return isValid;
    
  } catch (error) {
    if (DEBUG_MODE) {
      console.log("⚠️ Error during API key validation:", error.message);
    }
    return false;
  }
}

/**
 * Validate request payload size
 * @param {Request} request - The incoming request
 * @returns {boolean} Whether payload size is acceptable
 */
function validatePayloadSize(request) {
  const contentLength = request.headers.get('Content-Length');
  if (contentLength && parseInt(contentLength) > MAX_PAYLOAD_SIZE) {
    return false;
  }
  return true;
}

/**
 * Security middleware - runs all security checks
 * @param {Request} request - The incoming request
 * @returns {Promise<Object>} Security check result
 */
async function runSecurityChecks(request) {
  // 1. Validate payload size
  if (!validatePayloadSize(request)) {
    return { 
      passed: false, 
      reason: "payload_too_large", 
      details: `Payload exceeds ${MAX_PAYLOAD_SIZE} bytes` 
    };
  }
  
  // 2. Validate API key if required
  if (!(await validateApiKey(request))) {
    return { 
      passed: false, 
      reason: "invalid_api_key", 
      details: "Missing or invalid API key" 
    };
  }
  
  // 3. Validate origin domain
  const originCheck = validateOrigin(request);
  if (!originCheck.valid) {
    return { 
      passed: false, 
      reason: "invalid_origin", 
      details: `${originCheck.reason}: ${originCheck.value || 'missing'}` 
    };
  }
  
  // 4. Check rate limiting
  const rateLimitCheck = await checkRateLimit(request);
  if (!rateLimitCheck.allowed) {
    return { 
      passed: false, 
      reason: "rate_limited", 
      details: `${rateLimitCheck.count}/${rateLimitCheck.limit} requests in window` 
    };
  }
  
  return { 
    passed: true, 
    origin: originCheck,
    rateLimit: rateLimitCheck
  };
}

/**
 * =============================================================================
 * MAIN REQUEST HANDLER
 * =============================================================================
 */

export default {
  async fetch(request, env) {
    return await handleRequest(request, env);
  }
};

/**
 * Handle the incoming request (ENHANCED WITH GDPR COMPLIANCE)
 * @param {Request} request
 */

async function handleRequest(request, env) {
  // Initialize environment variables
  if (env) {
    GA4_MEASUREMENT_ID = env.GA4_MEASUREMENT_ID || GA4_MEASUREMENT_ID;
    GA4_API_SECRET = env.GA4_API_SECRET || GA4_API_SECRET;
    API_KEY = env.API_KEY || API_KEY;
    ENCRYPTION_KEY = env.ENCRYPTION_KEY || ENCRYPTION_KEY;
    
    // Parse ALLOWED_DOMAINS from environment (comma-separated string)
    if (env.ALLOWED_DOMAINS) {
      ALLOWED_DOMAINS = env.ALLOWED_DOMAINS.split(',').map(domain => domain.trim());
    }
  }
  // Handle CORS preflight requests
  if (request.method === "OPTIONS") {
    return handleCORS(request);
  }

  // Only allow POST requests
  if (request.method !== "POST") {
    return new Response("Method not allowed", {
      status: 405,
      headers: getCORSHeaders(request),
    });
  }

  // Detect request type for proper security handling
  const isSimpleRequest = request.headers.get("X-Simple-request") === "true";
  const isWordPressRequest = request.headers.get("X-WP-Nonce") !== null;
  const isEncryptedRequest = request.headers.get("X-Encrypted") === "true";
  
  let requestType = "regular";
  if (isWordPressRequest) {
    requestType = isEncryptedRequest ? "wp_encrypted" : "wp_standard";
  } else if (isSimpleRequest) {
    requestType = "simple";
  }
  
  if (DEBUG_MODE) {
    console.log(`🔍 Processing ${requestType} request`);
  }

  // SECURITY CHECKS - Run validation based on request type
  if (requestType === "regular") {
    // Regular requests: Full security validation (payload, API key, origin, rate limiting)
    const securityCheck = await runSecurityChecks(request);
    if (!securityCheck.passed) {
      if (DEBUG_MODE) {
        console.log("Security check failed:", JSON.stringify(securityCheck));
      }
      
      return new Response(
        JSON.stringify({
          success: false,
          error: "Security validation failed",
          reason: securityCheck.reason,
          details: securityCheck.details
        }),
        {
          status: securityCheck.reason === "rate_limited" ? 429 : 403,
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
    }
    
    // Log security check success for regular requests
    if (DEBUG_MODE) {
      console.log("Security checks passed:", JSON.stringify({
        origin: securityCheck.origin?.domain,
        rateLimit: `${securityCheck.rateLimit?.count}/${securityCheck.rateLimit?.limit}`
      }));
    }
  } else if (requestType === "simple") {
    // Simple requests: Only essential checks (payload size, basic rate limiting)
    // Skip API key validation and origin validation for performance
    
    if (DEBUG_MODE) {
      console.log("⚡ Simple request - bypassing API key and origin validation");
    }
    
    // Still validate payload size for Simple requests
    if (!validatePayloadSize(request)) {
      return new Response(
        JSON.stringify({
          success: false,
          error: "Payload too large",
          details: `Payload exceeds ${MAX_PAYLOAD_SIZE} bytes`
        }),
        {
          status: 413,
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
    }
    
    // Basic rate limiting for Simple requests (more lenient than regular requests)
    const rateLimitCheck = await checkRateLimit(request);
    if (!rateLimitCheck.allowed) {
      return new Response(
        JSON.stringify({
          success: false,
          error: "Rate limit exceeded",
          details: `${rateLimitCheck.count}/${rateLimitCheck.limit} requests in window`
        }),
        {
          status: 429,
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
    }
  } else if (requestType === "wp_standard" || requestType === "wp_encrypted") {
    // WordPress requests: Skip API key validation but run other security checks
    // These come from WordPress endpoint and don't need API key validation
    
    if (DEBUG_MODE) {
      console.log(`🔒 WordPress ${requestType === "wp_encrypted" ? "encrypted" : "standard"} request - skipping API key validation`);
    }
    
    // Still validate payload size for WordPress requests
    if (!validatePayloadSize(request)) {
      return new Response(
        JSON.stringify({
          success: false,
          error: "Payload too large",
          details: `Payload exceeds ${MAX_PAYLOAD_SIZE} bytes`
        }),
        {
          status: 413,
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
    }
    
    // Apply rate limiting for WordPress requests
    const rateLimitCheck = await checkRateLimit(request);
    if (!rateLimitCheck.allowed) {
      return new Response(
        JSON.stringify({
          success: false,
          error: "Rate limit exceeded",
          details: `${rateLimitCheck.count}/${rateLimitCheck.limit} requests in window`
        }),
        {
          status: 429,
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
    }
    
    // Optional: Validate origin for WordPress requests (less strict than regular)
    const originCheck = validateOrigin(request);
    if (!originCheck.valid) {
      if (DEBUG_MODE) {
        console.log("WordPress request origin validation failed:", JSON.stringify(originCheck));
      }
      // Could return error here if strict origin validation is required
      // For now, just log the warning but continue processing
    }
  }

  try {
    // Parse the request body
    let payload = await request.json();

    // Check if the request uses JWT encryption (skip for Simple requests)
    const isJWTEncrypted = requestType === "wp_encrypted" || 
                          (requestType === "regular" && request.headers.get('X-Encrypted') === 'true');
    
    if (isJWTEncrypted) {
      if (!JWT_ENCRYPTION_ENABLED) {
        console.warn("❌ X-Encrypted header present but JWT_ENCRYPTION_ENABLED is false");
      } else if (!ENCRYPTION_KEY) {
        console.warn("❌ X-Encrypted header present but ENCRYPTION_KEY is not set");
      } else {
        try {
          // Handle different JWT formats based on request type
          if (requestType === "wp_encrypted") {
            // WordPress encrypted requests use time_jwt format
            if (payload.time_jwt) {
              const decryptedData = await decrypt(payload.time_jwt, ENCRYPTION_KEY);
              payload = JSON.parse(decryptedData);
              
              if (DEBUG_MODE) {
                console.log("Decrypted WordPress payload:", JSON.stringify(payload));
              }
            } else {
              console.warn("❌ WordPress encrypted request but no time_jwt token found");
              if (DEBUG_MODE) {
                console.log("Available payload fields:", Object.keys(payload));
                console.log("Full payload:", JSON.stringify(payload));
              }
            }
          } else {
            // Regular encrypted requests use legacy jwt format
            if (payload.jwt) {
              const decryptedData = await decrypt(payload.jwt, ENCRYPTION_KEY);
              payload = JSON.parse(decryptedData);
              
              if (DEBUG_MODE) {
                console.log("Decrypted regular payload:", JSON.stringify(payload));
              }
            } else {
              console.warn("❌ Request marked as JWT encrypted but no JWT token found");
              if (DEBUG_MODE) {
                console.log("Available payload fields:", Object.keys(payload));
                console.log("Full payload:", JSON.stringify(payload));
              }
            }
          }
        } catch (decryptError) {
          console.error("❌ Failed to verify JWT token:", decryptError);
          return new Response(
            JSON.stringify({
              success: false,
              error: "JWT verification failed",
              details: decryptError.message
            }),
            {
              status: 400,
              headers: {
                "Content-Type": "application/json",
                ...getCORSHeaders(request),
              },
            }
          );
        }
      }
    }

    // Check if this is a batch request
    if (payload.events && Array.isArray(payload.events) && payload.events.length > 0) {
      return await handleBatchEvents(payload, request);
    }

    // GDPR CONSENT PROCESSING - Apply before bot detection (single event)
    const consentProcessedPayload = processGDPRConsent(payload);

    // BOT DETECTION CHECK
    const botDetection = detectBot(request, consentProcessedPayload);
    
    if (botDetection.isBot) {
      logBotDetection(botDetection, request, consentProcessedPayload);
      
      // Return success response but don't process the event
      const botResponseData = {
        success: true,
        filtered: true,
        reason: "bot_detected",
        bot_score: botDetection.score,
        bot_details: botDetection,
        gdpr_processed: true
      };
      
      return await createResponse(botResponseData, request);
    }

    return await handleGA4Event(consentProcessedPayload, request);
    
  } catch (error) {
    // Log the error
    console.error("Error processing request:", error);

    // Return error response (encrypted if supported)
    const errorResponseData = {
      success: false,
      error: error.message,
      gdpr_processed: false
    };
    
    try {
      const errorResponse = await createResponse(errorResponseData, request);
      return new Response(errorResponse.body, {
        status: 500,
        headers: errorResponse.headers
      });
    } catch (encryptError) {
      // Fallback to unencrypted response if encryption fails
      return new Response(
        JSON.stringify(errorResponseData),
        {
          status: 500,
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
    }
  }
}

/**
 * Handle batch events by processing each event individually using existing functions
 * @param {Object} batchPayload
 * @param {Request} request
 */
async function handleBatchEvents(batchPayload, request) {
  try {
    if (DEBUG_MODE) {
      console.log("📦 Processing batch of events:", {
        eventCount: batchPayload.events.length,
        hasConsent: !!batchPayload.consent,
        consentData: batchPayload.consent,
        timestamp: batchPayload.timestamp
      });
    }

    // Find the first complete event to use as a reference for enriching incomplete events
    let firstCompleteEvent = null;
    for (let i = 0; i < batchPayload.events.length; i++) {
      if (batchPayload.events[i].isCompleteData) {
        firstCompleteEvent = batchPayload.events[i];
        break;
      }
    }

    // Process each event in the batch using existing functions
    const results = [];
    const errors = [];
    
    for (let i = 0; i < batchPayload.events.length; i++) {
      const event = batchPayload.events[i];
      
      try {
        // Convert batch event back to single event format for existing functions
        const singleEventPayload = {
          event_name: event.name,
          name: event.name,
          params: event.params || {},
          consent: batchPayload.consent,
          timestamp: event.timestamp || batchPayload.timestamp
        };

        // Add page context and enrich incomplete events with session data
        if (!event.isCompleteData) {
          // Add page context if available
          if (event.pageUrl) {
            singleEventPayload.params.page_location = event.pageUrl;
            singleEventPayload.params.page_title = event.pageTitle;
          }
          
          // Enrich incomplete events with session data from the first complete event
          if (firstCompleteEvent) {
            // Add missing session data
            if (!singleEventPayload.params.session_id && firstCompleteEvent.params.session_id) {
              singleEventPayload.params.session_id = firstCompleteEvent.params.session_id;
            }
            if (!singleEventPayload.params.client_id && firstCompleteEvent.params.client_id) {
              singleEventPayload.params.client_id = firstCompleteEvent.params.client_id;
            }
            if (!singleEventPayload.params.session_start && firstCompleteEvent.params.session_start) {
              singleEventPayload.params.session_start = firstCompleteEvent.params.session_start;
            }
            if (!singleEventPayload.params.browser_name && firstCompleteEvent.params.browser_name) {
              singleEventPayload.params.browser_name = firstCompleteEvent.params.browser_name;
            }
            if (!singleEventPayload.params.device_type && firstCompleteEvent.params.device_type) {
              singleEventPayload.params.device_type = firstCompleteEvent.params.device_type;
            }
            if (!singleEventPayload.params.screen_resolution && firstCompleteEvent.params.screen_resolution) {
              singleEventPayload.params.screen_resolution = firstCompleteEvent.params.screen_resolution;
            }
            if (!singleEventPayload.params.is_desktop && firstCompleteEvent.params.is_desktop) {
              singleEventPayload.params.is_desktop = firstCompleteEvent.params.is_desktop;
            }
            if (!singleEventPayload.params.language && firstCompleteEvent.params.language) {
              singleEventPayload.params.language = firstCompleteEvent.params.language;
            }
            if (!singleEventPayload.params.user_agent && firstCompleteEvent.params.user_agent) {
              singleEventPayload.params.user_agent = firstCompleteEvent.params.user_agent;
            }
            // Add STORED attribution data for incomplete events (not current page attribution)
            // For all events, we want to use the original traffic source that brought the user to the site
            if (!singleEventPayload.params.source) {
              // Try to get stored attribution first, then fall back to current attribution
              if (firstCompleteEvent.params.originalSource) {
                singleEventPayload.params.source = firstCompleteEvent.params.originalSource;
              } else if (firstCompleteEvent.params.source) {
                singleEventPayload.params.source = firstCompleteEvent.params.source;
              }
            }
            if (!singleEventPayload.params.medium) {
              // Try to get stored attribution first, then fall back to current attribution
              if (firstCompleteEvent.params.originalMedium) {
                singleEventPayload.params.medium = firstCompleteEvent.params.originalMedium;
              } else if (firstCompleteEvent.params.medium) {
                singleEventPayload.params.medium = firstCompleteEvent.params.medium;
              }
            }
            if (!singleEventPayload.params.campaign) {
              // Try to get stored attribution first, then fall back to current attribution
              if (firstCompleteEvent.params.originalCampaign) {
                singleEventPayload.params.campaign = firstCompleteEvent.params.originalCampaign;
              } else if (firstCompleteEvent.params.campaign) {
                singleEventPayload.params.campaign = firstCompleteEvent.params.campaign;
              }
            }
            if (!singleEventPayload.params.content) {
              // Try to get stored attribution first, then fall back to current attribution
              if (firstCompleteEvent.params.originalContent) {
                singleEventPayload.params.content = firstCompleteEvent.params.originalContent;
              } else if (firstCompleteEvent.params.content) {
                singleEventPayload.params.content = firstCompleteEvent.params.content;
              }
            }
            if (!singleEventPayload.params.term) {
              // Try to get stored attribution first, then fall back to current attribution
              if (firstCompleteEvent.params.originalTerm) {
                singleEventPayload.params.term = firstCompleteEvent.params.originalTerm;
              } else if (firstCompleteEvent.params.term) {
                singleEventPayload.params.term = firstCompleteEvent.params.term;
              }
            }
            if (!singleEventPayload.params.gclid) {
              // Try to get stored attribution first, then fall back to current attribution
              if (firstCompleteEvent.params.originalGclid) {
                singleEventPayload.params.gclid = firstCompleteEvent.params.originalGclid;
              } else if (firstCompleteEvent.params.gclid) {
                singleEventPayload.params.gclid = firstCompleteEvent.params.gclid;
              }
            }
            
            // Use stored traffic_type if available, otherwise calculate from the attribution we just set
            if (!singleEventPayload.params.traffic_type) {
              // Use stored traffic type first
              if (firstCompleteEvent.params.originalTrafficType) {
                singleEventPayload.params.traffic_type = firstCompleteEvent.params.originalTrafficType;
              } else {
                // Calculate traffic type based on the attribution we just set
                const source = singleEventPayload.params.source || '';
                const medium = singleEventPayload.params.medium || '';
                
                if (source === '(direct)' && medium === '(none)') {
                  singleEventPayload.params.traffic_type = 'direct';
                } else if (source === '(internal)' && medium === 'internal') {
                  singleEventPayload.params.traffic_type = 'internal';
                } else if (medium === 'organic') {
                  singleEventPayload.params.traffic_type = 'organic';
                } else if (medium === 'cpc' || medium === 'ppc' || medium === 'paidsearch') {
                  singleEventPayload.params.traffic_type = 'paid_search';
                } else if (medium === 'social') {
                  singleEventPayload.params.traffic_type = 'social';
                } else if (medium === 'email') {
                  singleEventPayload.params.traffic_type = 'email';
                } else if (medium === 'referral') {
                  singleEventPayload.params.traffic_type = 'referral';
                } else {
                  singleEventPayload.params.traffic_type = 'other';
                }
              }
            }
            
            if (!singleEventPayload.params.page_referrer && firstCompleteEvent.params.page_referrer) {
              singleEventPayload.params.page_referrer = firstCompleteEvent.params.page_referrer;
            }
            // Add engagement time if missing
            if (!singleEventPayload.params.engagement_time_msec && firstCompleteEvent.params.engagement_time_msec) {
              singleEventPayload.params.engagement_time_msec = firstCompleteEvent.params.engagement_time_msec;
            }
            
            if (DEBUG_MODE) {
              console.log("📝 Enriched incomplete event with session data and original attribution:", {
                eventName: event.name,
                addedSessionId: !!firstCompleteEvent.params.session_id,
                addedClientId: !!firstCompleteEvent.params.client_id,
                usedOriginalSource: !!firstCompleteEvent.params.originalSource,
                usedOriginalMedium: !!firstCompleteEvent.params.originalMedium,
                usedOriginalTrafficType: !!firstCompleteEvent.params.originalTrafficType,
                finalSource: singleEventPayload.params.source,
                finalMedium: singleEventPayload.params.medium,
                finalTrafficType: singleEventPayload.params.traffic_type
              });
            }
          }
        }

        // Add consent data to the individual event params for GDPR processing
        // In batch events, consent is at the batch level, not individual event level
        singleEventPayload.params.consent = batchPayload.consent;
        
        if (DEBUG_MODE) {
          console.log("📋 Added batch consent to individual event:", {
            eventName: event.name,
            consentApplied: !!batchPayload.consent,
            analyticsConsent: batchPayload.consent?.analytics_storage || 'unknown',
            adConsent: batchPayload.consent?.ad_storage || 'unknown'
          });
        }
        
        // Use existing GDPR processing
        const consentProcessedPayload = processGDPRConsent(singleEventPayload);

        // Bot detection for batch (use first event's data as representative)
        if (i === 0) {
          const botDetection = detectBot(request, consentProcessedPayload);
          
          if (botDetection.isBot) {
            logBotDetection(botDetection, request, consentProcessedPayload);
            
            // Return early if bot detected - don't process any events in batch
            const botResponseData = {
              success: true,
              filtered: true,
              reason: "bot_detected",
              bot_score: botDetection.score,
              events_filtered: batchPayload.events.length,
              gdpr_processed: true
            };
            
            return await createResponse(botResponseData, request);
          }
        }

        // Use existing handleGA4Event function to maintain payload structure
        const ga4Response = await handleGA4Event(consentProcessedPayload, request);
        
        // Parse the response to get success status
        const responseData = await ga4Response.json().catch(() => ({}));
        
        results.push({
          event: event.name,
          success: ga4Response.ok,
          status: ga4Response.status,
          response: DEBUG_MODE ? responseData : undefined
        });

        if (DEBUG_MODE) {
          console.log(`✅ Processed event ${i + 1}/${batchPayload.events.length}: ${event.name}`);
        }

      } catch (eventError) {
        console.error(`❌ Error processing event ${i + 1}:`, eventError);
        errors.push({
          event: event.name || 'unknown',
          error: eventError.message
        });
      }
    }

    // Prepare batch response
    const responseData = {
      success: true,
      events_processed: results.length,
      events_failed: errors.length,
      total_events: batchPayload.events.length,
      results: DEBUG_MODE ? results : undefined,
      errors: errors.length > 0 ? errors : undefined,
      consent_applied: !!batchPayload.consent,
      consent_mode: batchPayload.consent?.analytics_storage || 'unknown',
      request_type: request.headers.get("X-Simple-request") === "true" ? "simple" : "regular"
    };

    if (DEBUG_MODE) {
      console.log("📦 Batch processing complete:", responseData);
    }

    return await createResponse(responseData, request);

  } catch (error) {
    console.error("❌ Error processing batch events:", error);
    
    const errorResponseData = {
      success: false,
      error: "Batch processing failed",
      details: error.message,
      events_processed: 0,
      events_failed: batchPayload.events?.length || 0
    };
    
    return await createResponse(errorResponseData, request);
  }
}

/**
 * Handle GA4 events (Enhanced with consent handling)
 * @param {Object} payload
 * @param {Request} request
 */
async function handleGA4Event(payload, request) {
  // Process the event data
  const processedData = processEventData(payload, request);
  let payloadDebug = false;

  // Validate required parameters - check for both 'name' and 'event_name' fields
  if (!processedData.name && !processedData.event_name) {
    return new Response(JSON.stringify({ "error": "Missing event name" }), {
      status: 400,
      headers: {
        "Content-Type": "application/json",
        ...getCORSHeaders(request),
      },
    });
  }
  
  // Normalize event name field - use 'name' field consistently
  if (!processedData.name && processedData.event_name) {
    processedData.name = processedData.event_name;
  }

  // Extract location data from params before preparing GA4 payload
  const userLocation = extractLocationData(processedData.params);

  // Prepare the GA4 payload
  const ga4Payload = {
    "client_id": processedData.params.client_id,
    "events": [
      {
        "name": processedData.name,
        "params": processedData.params,
      },
    ],
  };

  // Add consent signals at the request level if available
  if (processedData.consent) {
    ga4Payload.consent = processedData.consent;
  }

  // Add user_location at request level if available
  if (userLocation && Object.keys(userLocation).length > 0) {
    ga4Payload.user_location = userLocation;
  }

  // Extract and add device information following GA4 specification
  const deviceInfo = extractDeviceInfo(processedData.params);
  if (deviceInfo && Object.keys(deviceInfo).length > 0) {
    ga4Payload.device = deviceInfo;
  }

  // Add user_agent at top level (always include when available)
  const userAgent = getUserAgent(processedData, request);
  if (userAgent) {
    ga4Payload.user_agent = userAgent;
  }


  // Keep timestamp in event parameters only (not allowed at top level in GA4)
  // event_timestamp should remain in the event parameters where it belongs

  // Add IP override for geographic information derivation
  const clientIP = request.headers.get('CF-Connecting-IP') || 
                   request.headers.get('X-Forwarded-For') || 
                   request.headers.get('X-Real-IP');
  if (clientIP && processedData.consent && processedData.consent.ad_user_data === 'GRANTED') {
    // Only include IP when analytics consent is granted
    ga4Payload.ip_override = clientIP.split(',')[0].trim(); // Take first IP if multiple
  }



  // Keep session_id in event params only (not allowed at top level in GA4)
  // session_id should remain in the event parameters where it belongs



  // Add validation_code for enhanced measurement validation (if available)
  if (processedData.params.validation_code) {
    delete processedData.params.validation_code;
  }

  // Add app information if this is from a mobile app context
  if (processedData.params.app_name) {
    delete processedData.params.app_name;
  }
  if (processedData.params.app_version) {
    delete processedData.params.app_version;
  }
  if (processedData.params.app_id) {
    delete processedData.params.app_id;
  }

  // Remove client_id from params to avoid duplication, only if it exists
  if (ga4Payload.events[0].params.hasOwnProperty("client_id")) {
    delete ga4Payload.events[0].params.client_id;
  }
  
  // Remove botData from params
  if (ga4Payload.events[0].params.hasOwnProperty("botData")) {
    delete ga4Payload.events[0].params.botData;
  }
  
  // Remove device-related data from params since it's now at top level
  const deviceParamsToRemove = [
    'device_type', 'is_mobile', 'is_tablet', 'is_desktop', 
    'browser_name', 'screen_resolution', 'user_agent'
  ];
  deviceParamsToRemove.forEach(param => {
    if (ga4Payload.events[0].params.hasOwnProperty(param)) {
      delete ga4Payload.events[0].params[param];
    }
  });
  // Handle consent parameter in event params
  if (ga4Payload.events[0].params.consent) {
    // If consent is an object (from client), remove it - we'll create the proper string format
    if (typeof ga4Payload.events[0].params.consent === 'object') {
      delete ga4Payload.events[0].params.consent;
    }
  }
  
  // Create the combined consent string parameter if not already present
  if (!ga4Payload.events[0].params.consent) {
    var consentReason = (ga4Payload.consent && ga4Payload.consent.consent_reason) ? ga4Payload.consent.consent_reason : 'button_click';
    ga4Payload.events[0].params.consent = "ad_personalization: " + ga4Payload.consent.ad_personalization + ". ad_user_data: " + ga4Payload.consent.ad_user_data + ". reason: " + consentReason;
  }

  // Note: We keep the 'consent' parameter in event params (it contains the combined consent info)

  // Add user_id if available and consent allows
  if (processedData.params.user_id) {
    ga4Payload.user_id = processedData.params.user_id;
    // Remove from params to avoid duplication
    delete processedData.params.user_id;
  }
  // Clean up consent object - only keep official GA4 fields
  if (ga4Payload.consent) {
    // Keep only the two official GA4 consent fields
    const cleanConsent = {
      ad_user_data: ga4Payload.consent.ad_user_data || "DENIED",
      ad_personalization: ga4Payload.consent.ad_personalization || "DENIED"
    };
    ga4Payload.consent = cleanConsent;
  }


  // Check if params exceeds 25
  const payloadParamsCount = Object.keys(processedData.params).length;
  if (payloadParamsCount > 25) {
    return new Response(
      JSON.stringify({
        "error": `Too many parameters: ${payloadParamsCount}. GA4 only allows a maximum of 25 parameters per event.`,
      }),
      {
        status: 400,
        headers: {
          "Content-Type": "application/json",
          ...getCORSHeaders(request),
        },
      }
    );
  }

  if (DEBUG_MODE) {
    if (processedData.name == 'page_view' || processedData.name == 'custom_session_start' || processedData.name == 'custom_first_visit') {
      console.log("Traffic Type:" + processedData.params.traffic_type);
    }
  }

  // Log the complete payload being sent to Google Analytics
  if (DEBUG_MODE) {
    console.log("📤 Complete GA4 Payload being sent to Google Analytics:");
    console.log(JSON.stringify({ payload: ga4Payload }, null, 2));
  }
  
  if(processedData.params.debug_mode == true){
      payloadDebug = true;
  }
  // Send the event to GA4
  const ga4Response = await fetch(
    `${GA4_ENDPOINT}?measurement_id=${GA4_MEASUREMENT_ID}&api_secret=${GA4_API_SECRET}`,
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(ga4Payload),
    }
  );

  // Check if the request was successful
  if (!ga4Response.ok) {
    const errorText = await ga4Response.text();
    throw new Error(`GA4 API error: ${ga4Response.status} ${errorText}`);
  }

  // Get the response body from GA4 API
  const ga4ResponseBody = await ga4Response.text();

  // Log the GA4 API response
  if (DEBUG_MODE) {
    console.log("GA4 API Response Status:", ga4Response.status);
    console.log("GA4 API Response Body:", ga4ResponseBody);
  }

  // Prepare response data
  const responseData = {
    "success": true,
    "event": processedData.name,
    "ga4_status": ga4Response.status,
    "ga4_response": DEBUG_MODE ? ga4ResponseBody : undefined,
    "debug": payloadDebug ? ga4Payload : undefined,
    "consent_applied": ga4Payload.consent ? true : false,
    "consent_mode": ga4Payload.consent?.ad_user_data || 'unknown',
    "request_type": request.headers.get("X-Simple-request") === "true" ? "simple" : "regular"
  };

  // Return encrypted response if requested
  return await createResponse(responseData, request);
}

/**
 * Create response with optional encryption
 * @param {Object} responseData - Data to send in response
 * @param {Request} request - Original request to check encryption headers
 * @returns {Promise<Response>} - Response object
 */
async function createResponse(responseData, request) {
  let responseBody = JSON.stringify(responseData);
  const headers = {
    "Content-Type": "application/json",
    ...getCORSHeaders(request),
  };

  // Always send responses as plain text (no encryption)
  if (DEBUG_MODE) {
    console.log("📤 Response sent as plain text (encryption disabled for responses)");
  }

  return new Response(responseBody, { headers });
}

/**
 * =============================================================================
 * UTILITY FUNCTIONS
 * =============================================================================
 */

/**
 * Extract device information following GA4 specification
 * @param {Object} params - Event parameters
 * @returns {Object} Device information object
 */
function extractDeviceInfo(params) {
  const device = {};
  const botData = params.botData || {};
  
  // Extract device category (most important field according to docs)
  if (params.device_type) {
    device.category = params.device_type; // mobile, desktop, tablet
  } else if (params.is_mobile) {
    device.category = "mobile";
  } else if (params.is_tablet) {
    device.category = "tablet";
  } else if (params.is_desktop) {
    device.category = "desktop";
  }
  
  // Extract language
  if (params.language) {
    device.language = params.language;
  }
  
  // Extract screen resolution
  if (params.screen_resolution) {
    device.screen_resolution = params.screen_resolution;
  } else if (botData.screen_available_width && botData.screen_available_height) {
    device.screen_resolution = `${botData.screen_available_width}x${botData.screen_available_height}`;
  }
  
  // Extract operating system from user agent or bot data
  if (params.browser_name || botData.browser_name) {
    device.browser = params.browser_name || botData.browser_name;
  }
  
  // Extract platform/OS information
  if (botData.platform) {
    // Map common platform values to GA4 format
    const platformMap = {
      'Win32': 'Windows',
      'MacIntel': 'Macintosh', 
      'Linux x86_64': 'Linux',
      'iPhone': 'iOS',
      'iPad': 'iOS',
      'Android': 'Android'
    };
    device.operating_system = platformMap[botData.platform] || botData.platform;
  }
  
  // Try to extract browser and OS from user_agent if available
  if (params.user_agent || botData.user_agent_full) {
    const userAgent = params.user_agent || botData.user_agent_full;
    const browserInfo = parseUserAgentForDevice(userAgent);
    
    if (browserInfo.browser && !device.browser) {
      device.browser = browserInfo.browser;
    }
    if (browserInfo.browser_version) {
      device.browser_version = browserInfo.browser_version;
    }
    if (browserInfo.operating_system && !device.operating_system) {
      device.operating_system = browserInfo.operating_system;
    }
    if (browserInfo.operating_system_version) {
      device.operating_system_version = browserInfo.operating_system_version;
    }
    if (browserInfo.model) {
      device.model = browserInfo.model;
    }
    if (browserInfo.brand) {
      device.brand = browserInfo.brand;
    }
  }
  
  return device;
}

/**
 * Parse user agent to extract device information
 * @param {string} userAgent - User agent string
 * @returns {Object} Parsed device information
 */
function parseUserAgentForDevice(userAgent) {
  const info = {};
  
  if (!userAgent) return info;
  
  // Extract browser information
  if (/Chrome\/([0-9\.]+)/.test(userAgent)) {
    info.browser = "Chrome";
    info.browser_version = userAgent.match(/Chrome\/([0-9\.]+)/)[1];
  } else if (/Firefox\/([0-9\.]+)/.test(userAgent)) {
    info.browser = "Firefox";
    info.browser_version = userAgent.match(/Firefox\/([0-9\.]+)/)[1];
  } else if (/Safari\/([0-9\.]+)/.test(userAgent) && !/Chrome/.test(userAgent)) {
    info.browser = "Safari";
    info.browser_version = userAgent.match(/Version\/([0-9\.]+)/)?.[1] || "";
  } else if (/Edge\/([0-9\.]+)/.test(userAgent)) {
    info.browser = "Edge";
    info.browser_version = userAgent.match(/Edge\/([0-9\.]+)/)[1];
  }
  
  // Extract operating system
  if (/Windows NT ([0-9\.]+)/.test(userAgent)) {
    info.operating_system = "Windows";
    info.operating_system_version = userAgent.match(/Windows NT ([0-9\.]+)/)[1];
  } else if (/Mac OS X ([0-9_\.]+)/.test(userAgent)) {
    info.operating_system = "Macintosh";
    info.operating_system_version = userAgent.match(/Mac OS X ([0-9_\.]+)/)[1].replace(/_/g, '.');
  } else if (/Android ([0-9\.]+)/.test(userAgent)) {
    info.operating_system = "Android";
    info.operating_system_version = userAgent.match(/Android ([0-9\.]+)/)[1];
  } else if (/iPhone OS ([0-9_\.]+)/.test(userAgent)) {
    info.operating_system = "iOS";
    info.operating_system_version = userAgent.match(/iPhone OS ([0-9_\.]+)/)[1].replace(/_/g, '.');
  } else if (/Linux/.test(userAgent)) {
    info.operating_system = "Linux";
  }
  
  // Extract device model and brand (mainly for mobile)
  if (/iPhone/.test(userAgent)) {
    info.brand = "Apple";
    info.model = "iPhone";
  } else if (/iPad/.test(userAgent)) {
    info.brand = "Apple";
    info.model = "iPad";
  } else if (/Pixel ([0-9a-zA-Z\s]+)/.test(userAgent)) {
    info.brand = "Google";
    info.model = userAgent.match(/Pixel ([0-9a-zA-Z\s]+)/)[1].trim();
  } else if (/Samsung/.test(userAgent)) {
    info.brand = "Samsung";
    const modelMatch = userAgent.match(/SM-([A-Z0-9]+)/);
    if (modelMatch) {
      info.model = modelMatch[1];
    }
  }
  
  return info;
}


/**
 * Get continent and subcontinent info based on country code
 */
function getContinentInfo(countryCode) {
  const continentCodeMap = {
    "EU": { "continent_id": "150", "subcontinent_id": "" },
    "NA": { "continent_id": "019", "subcontinent_id": "021" },
    "SA": { "continent_id": "019", "subcontinent_id": "005" },
    "AS": { "continent_id": "142", "subcontinent_id": "" },
    "AF": { "continent_id": "002", "subcontinent_id": "" },
    "OC": { "continent_id": "009", "subcontinent_id": "" },
    "AN": { "continent_id": "010", "subcontinent_id": "" },
  };

  if (continentCodeMap[countryCode]) {
    return continentCodeMap[countryCode];
  }

  const continentMap = {
    // Europe (continent: 150)
    "AD": { "continent_id": "150", "subcontinent_id": "039" },
    "AL": { "continent_id": "150", "subcontinent_id": "039" },
    "AT": { "continent_id": "150", "subcontinent_id": "155" },
    "BA": { "continent_id": "150", "subcontinent_id": "039" },
    "BE": { "continent_id": "150", "subcontinent_id": "155" },
    "BG": { "continent_id": "150", "subcontinent_id": "151" },
    "BY": { "continent_id": "150", "subcontinent_id": "151" },
    "CH": { "continent_id": "150", "subcontinent_id": "155" },
    "CY": { "continent_id": "150", "subcontinent_id": "145" },
    "CZ": { "continent_id": "150", "subcontinent_id": "151" },
    "DE": { "continent_id": "150", "subcontinent_id": "155" },
    "DK": { "continent_id": "150", "subcontinent_id": "154" },
    "EE": { "continent_id": "150", "subcontinent_id": "154" },
    "ES": { "continent_id": "150", "subcontinent_id": "039" },
    "FI": { "continent_id": "150", "subcontinent_id": "154" },
    "FR": { "continent_id": "150", "subcontinent_id": "155" },
    "GB": { "continent_id": "150", "subcontinent_id": "154" },
    "GR": { "continent_id": "150", "subcontinent_id": "039" },
    "HR": { "continent_id": "150", "subcontinent_id": "039" },
    "HU": { "continent_id": "150", "subcontinent_id": "151" },
    "IE": { "continent_id": "150", "subcontinent_id": "154" },
    "IS": { "continent_id": "150", "subcontinent_id": "154" },
    "IT": { "continent_id": "150", "subcontinent_id": "039" },
    "LI": { "continent_id": "150", "subcontinent_id": "155" },
    "LT": { "continent_id": "150", "subcontinent_id": "154" },
    "LU": { "continent_id": "150", "subcontinent_id": "155" },
    "LV": { "continent_id": "150", "subcontinent_id": "154" },
    "MC": { "continent_id": "150", "subcontinent_id": "155" },
    "MD": { "continent_id": "150", "subcontinent_id": "151" },
    "ME": { "continent_id": "150", "subcontinent_id": "039" },
    "MK": { "continent_id": "150", "subcontinent_id": "039" },
    "MT": { "continent_id": "150", "subcontinent_id": "039" },
    "NL": { "continent_id": "150", "subcontinent_id": "155" },
    "NO": { "continent_id": "150", "subcontinent_id": "154" },
    "PL": { "continent_id": "150", "subcontinent_id": "151" },
    "PT": { "continent_id": "150", "subcontinent_id": "039" },
    "RO": { "continent_id": "150", "subcontinent_id": "151" },
    "RS": { "continent_id": "150", "subcontinent_id": "039" },
    "RU": { "continent_id": "150", "subcontinent_id": "151" },
    "SE": { "continent_id": "150", "subcontinent_id": "154" },
    "SI": { "continent_id": "150", "subcontinent_id": "039" },
    "SK": { "continent_id": "150", "subcontinent_id": "151" },
    "SM": { "continent_id": "150", "subcontinent_id": "039" },
    "UA": { "continent_id": "150", "subcontinent_id": "151" },
    "VA": { "continent_id": "150", "subcontinent_id": "039" },

    // Americas (continent: 019)
    "AG": { "continent_id": "019", "subcontinent_id": "029" },
    "AR": { "continent_id": "019", "subcontinent_id": "005" },
    "BB": { "continent_id": "019", "subcontinent_id": "029" },
    "BO": { "continent_id": "019", "subcontinent_id": "005" },
    "BR": { "continent_id": "019", "subcontinent_id": "005" },
    "BS": { "continent_id": "019", "subcontinent_id": "029" },
    "BZ": { "continent_id": "019", "subcontinent_id": "013" },
    "CA": { "continent_id": "019", "subcontinent_id": "021" },
    "CL": { "continent_id": "019", "subcontinent_id": "005" },
    "CO": { "continent_id": "019", "subcontinent_id": "005" },
    "CR": { "continent_id": "019", "subcontinent_id": "013" },
    "CU": { "continent_id": "019", "subcontinent_id": "029" },
    "DM": { "continent_id": "019", "subcontinent_id": "029" },
    "DO": { "continent_id": "019", "subcontinent_id": "029" },
    "EC": { "continent_id": "019", "subcontinent_id": "005" },
    "GT": { "continent_id": "019", "subcontinent_id": "013" },
    "GY": { "continent_id": "019", "subcontinent_id": "005" },
    "HN": { "continent_id": "019", "subcontinent_id": "013" },
    "HT": { "continent_id": "019", "subcontinent_id": "029" },
    "JM": { "continent_id": "019", "subcontinent_id": "029" },
    "MX": { "continent_id": "019", "subcontinent_id": "013" },
    "NI": { "continent_id": "019", "subcontinent_id": "013" },
    "PA": { "continent_id": "019", "subcontinent_id": "013" },
    "PE": { "continent_id": "019", "subcontinent_id": "005" },
    "PY": { "continent_id": "019", "subcontinent_id": "005" },
    "SV": { "continent_id": "019", "subcontinent_id": "013" },
    "SR": { "continent_id": "019", "subcontinent_id": "005" },
    "TT": { "continent_id": "019", "subcontinent_id": "029" },
    "US": { "continent_id": "019", "subcontinent_id": "021" },
    "UY": { "continent_id": "019", "subcontinent_id": "005" },
    "VE": { "continent_id": "019", "subcontinent_id": "005" },

    // Asia (continent: 142)
    "AE": { "continent_id": "142", "subcontinent_id": "145" },
    "AF": { "continent_id": "142", "subcontinent_id": "034" },
    "CN": { "continent_id": "142", "subcontinent_id": "030" },
    "IN": { "continent_id": "142", "subcontinent_id": "034" },
    "JP": { "continent_id": "142", "subcontinent_id": "030" },
    "KR": { "continent_id": "142", "subcontinent_id": "030" },
    "SA": { "continent_id": "142", "subcontinent_id": "145" },
    "SG": { "continent_id": "142", "subcontinent_id": "035" },
    "TH": { "continent_id": "142", "subcontinent_id": "035" },
    "TR": { "continent_id": "142", "subcontinent_id": "145" },

    // Africa (continent: 002)
    "EG": { "continent_id": "002", "subcontinent_id": "015" },
    "KE": { "continent_id": "002", "subcontinent_id": "014" },
    "MA": { "continent_id": "002", "subcontinent_id": "015" },
    "NG": { "continent_id": "002", "subcontinent_id": "011" },
    "ZA": { "continent_id": "002", "subcontinent_id": "018" },

    // Oceania (continent: 009)
    "AU": { "continent_id": "009", "subcontinent_id": "053" },
    "NZ": { "continent_id": "009", "subcontinent_id": "053" },
  };

  if (continentMap[countryCode]) {
    return continentMap[countryCode];
  }

  return { "continent_id": "150", "subcontinent_id": "155" };
}

/**
 * Convert country name to ISO country code
 */
function convertCountryToISO(countryName) {
  const countryMap = {
    // Europe
    "The Netherlands": "NL",
    "Netherlands": "NL",
    "United Kingdom": "GB",
    "UK": "GB",
    "Germany": "DE",
    "France": "FR",
    "Spain": "ES",
    "Italy": "IT",
    "Belgium": "BE",
    "Austria": "AT",
    "Switzerland": "CH",
    "Sweden": "SE",
    "Norway": "NO",
    "Denmark": "DK",
    "Finland": "FI",
    "Poland": "PL",
    "Czech Republic": "CZ",
    "Hungary": "HU",
    "Greece": "GR",
    "Portugal": "PT",
    "Ireland": "IE",
    "Russia": "RU",
    "Ukraine": "UA",
    
    // Americas
    "United States": "US",
    "USA": "US",
    "Canada": "CA",
    "Mexico": "MX",
    "Brazil": "BR",
    "Argentina": "AR",
    "Chile": "CL",
    "Peru": "PE",
    "Colombia": "CO",
    "Venezuela": "VE",
    
    // Asia
    "Japan": "JP",
    "China": "CN",
    "India": "IN",
    "South Korea": "KR",
    "Thailand": "TH",
    "Indonesia": "ID",
    "Philippines": "PH",
    "Malaysia": "MY",
    "Singapore": "SG",
    "Hong Kong": "HK",
    "United Arab Emirates": "AE",
    "Saudi Arabia": "SA",
    "Israel": "IL",
    "Turkey": "TR",
    
    // Oceania
    "Australia": "AU",
    "New Zealand": "NZ",
    "Fiji": "FJ",
    
    // Africa
    "Egypt": "EG",
    "Nigeria": "NG",
    "South Africa": "ZA",
    "Kenya": "KE",
    "Morocco": "MA",
    "Tunisia": "TN",
  };

  if (countryMap[countryName]) {
    return countryMap[countryName];
  }

  if (
    countryName &&
    countryName.length === 2 &&
    countryName === countryName.toUpperCase()
  ) {
    return countryName;
  }

  return countryName ? countryName.toUpperCase().substring(0, 2) : "NL";
}

/**
 * Extract location data from params and format for GA4
 */
function extractLocationData(params) {
  const userLocation = {};
  let locationSource = "continent mapping"; // Default fallback

  // Priority 1: Precise location data (from IP geolocation)
  if (params.geo_city || params.city) {
    const cityName = params.geo_city || params.city;
    userLocation.city = cleanLocationString(cityName);
    locationSource = "IP geolocation";
    delete params.geo_city;
    delete params.city;
  }
  // Fallback: Timezone-based city data
  else if (params.geo_city_tz) {
    userLocation.city = cleanLocationString(params.geo_city_tz);
    locationSource = "timezone fallback";
    delete params.geo_city_tz;
  }

  // Priority 1: Precise country data (from IP geolocation)
  if (params.geo_country || params.country) {
    const countryName = params.geo_country || params.country;
    userLocation.country_id = convertCountryToISO(countryName);
    if (locationSource === "continent mapping") locationSource = "IP geolocation";
    delete params.geo_country;
    delete params.country;
  }
  // Fallback: Timezone-based country data
  else if (params.geo_country_tz) {
    userLocation.country_id = convertCountryToISO(params.geo_country_tz);
    if (locationSource === "continent mapping") locationSource = "timezone fallback";
    delete params.geo_country_tz;
  }

  if (params.geo_region || params.region) {
    const regionName = params.geo_region || params.region;
    const countryCode = userLocation.country_id || "NL";

    if (regionName) {
      if (countryCode === "NL") {
        userLocation.region_id = formatDutchRegion(regionName, countryCode);
      } else {
        userLocation.region_id = formatRegionId(regionName, countryCode);
      }
    }
    delete params.geo_region;
    delete params.region;
  }

  // Handle continent-only location (for GDPR compliance)
  if (params.geo_continent) {
    // Map continent to a general country for GA4 compatibility
    const continentMap = {
      "Europe": "NL",
      "America": "US", 
      "North America": "US",
      "South America": "BR",
      "Asia": "JP",
      "Africa": "ZA",
      "Oceania": "AU"
    };
    
    if (!userLocation.country_id && continentMap[params.geo_continent]) {
      userLocation.country_id = continentMap[params.geo_continent];
    }
    
    // Always remove geo_continent - it's not a valid GA4 parameter
    delete params.geo_continent;
  }

  // Clean up timezone parameter - remove it from event params
  // (timezone is not a standard GA4 event parameter)
  if (params.timezone) {
    delete params.timezone;
  }

  if (userLocation.country_id && !userLocation.continent_id) {
    const continentInfo = getContinentInfo(userLocation.country_id);

    if (continentInfo.continent_id) {
      userLocation.continent_id = continentInfo.continent_id;
    }

    if (continentInfo.subcontinent_id) {
      userLocation.subcontinent_id = continentInfo.subcontinent_id;
    }
  }

  if (!userLocation.country_id) {
    userLocation.country_id = "NL";
  }

  if (params.geo_latitude) {
    delete params.geo_latitude;
  }
  if (params.geo_longitude) {
    delete params.geo_longitude;
  }

  Object.keys(userLocation).forEach((key) => {
    if (!userLocation[key] || userLocation[key] === "") {
      delete userLocation[key];
    }
  });

  // Clean up any remaining timezone-based parameters that shouldn't be in GA4 events
  const nonStandardParams = ['geo_continent', 'geo_country_tz', 'geo_city_tz', 'timezone'];
  nonStandardParams.forEach(param => {
    if (params[param]) {
      delete params[param];
    }
  });

  if (DEBUG_MODE && Object.keys(userLocation).length > 0) {
    console.log("Final user_location object:", JSON.stringify(userLocation));
    console.log("Location source:", locationSource);
  }

  return userLocation;
}

/**
 * Clean and format location strings
 */
function cleanLocationString(locationString) {
  if (!locationString) return "";

  return locationString
    .trim()
    .replace(/\s+/g, " ")
    .split(" ")
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
    .join(" ");
}

/**
 * Format Dutch region/province names to proper codes
 */
function formatDutchRegion(regionName, countryCode) {
  const dutchProvinces = {
    "Noord-Holland": "NL-NH",
    "Noord Holland": "NL-NH",
    "North Holland": "NL-NH",
    "NH": "NL-NH",
    "Zuid-Holland": "NL-ZH",
    "Zuid Holland": "NL-ZH",
    "South Holland": "NL-ZH",
    "ZH": "NL-ZH",
    "Utrecht": "NL-UT",
    "UT": "NL-UT",
    "Gelderland": "NL-GE",
    "GE": "NL-GE",
    "Overijssel": "NL-OV",
    "OV": "NL-OV",
    "Drenthe": "NL-DR",
    "DR": "NL-DR",
    "Friesland": "NL-FR",
    "Fryslân": "NL-FR",
    "FR": "NL-FR",
    "Groningen": "NL-GR",
    "GR": "NL-GR",
    "Limburg": "NL-LI",
    "LI": "NL-LI",
    "Noord-Brabant": "NL-NB",
    "Noord Brabant": "NL-NB",
    "North Brabant": "NL-NB",
    "NB": "NL-NB",
    "Zeeland": "NL-ZE",
    "ZE": "NL-ZE",
    "Flevoland": "NL-FL",
    "FL": "NL-FL",
  };

  if (dutchProvinces[regionName]) {
    return dutchProvinces[regionName];
  }

  const lowerRegion = regionName.toLowerCase();
  for (const [key, value] of Object.entries(dutchProvinces)) {
    if (key.toLowerCase() === lowerRegion) {
      return value;
    }
  }

  return formatRegionId(regionName, countryCode);
}

/**
 * Format region ID for Belgium and other countries
 */
function formatRegionId(regionName, countryCode) {
  if (!regionName) return "";

  if (regionName.includes("-") && regionName.startsWith(countryCode)) {
    return regionName;
  }

  const belgiumRegions = {
    "Flanders": "BE-VLG",
    "Vlaanderen": "BE-VLG", 
    "FL": "BE-VLG",
    "VLG": "BE-VLG",
    "Wallonia": "BE-WAL",
    "Wallonie": "BE-WAL", 
    "WL": "BE-WAL",
    "WAL": "BE-WAL",
    "Brussels": "BE-BRU",
    "Brussel": "BE-BRU",
    "Brussels-Capital": "BE-BRU",
    "BR": "BE-BRU",
    "BRU": "BE-BRU"
  };

  const usStates = {
    "California": "US-CA",
    "CA": "US-CA",
    "New York": "US-NY",
    "NY": "US-NY",
    "Texas": "US-TX",
    "TX": "US-TX",
    "Florida": "US-FL",
    "FL": "US-FL",
    "Illinois": "US-IL",
    "IL": "US-IL",
    "Pennsylvania": "US-PA",
    "PA": "US-PA",
    "Ohio": "US-OH",
    "OH": "US-OH",
    "Georgia": "US-GA",
    "GA": "US-GA",
    "North Carolina": "US-NC",
    "NC": "US-NC",
    "Michigan": "US-MI",
    "MI": "US-MI",
  };

  if (countryCode === "BE") {
    if (belgiumRegions[regionName]) {
      return belgiumRegions[regionName];
    }
    
    const lowerRegion = regionName.toLowerCase();
    for (const [key, value] of Object.entries(belgiumRegions)) {
      if (key.toLowerCase() === lowerRegion) {
        return value;
      }
    }
  }

  if (countryCode === "US" && usStates[regionName]) {
    return usStates[regionName];
  }

  let regionCode =
    regionName.length <= 3
      ? regionName.toUpperCase()
      : regionName.substring(0, 2).toUpperCase();
  return `${countryCode}-${regionCode}`;
}

/**
 * Process and normalize event data (Enhanced with consent handling)
 */
function processEventData(data, request) {
  const processedData = JSON.parse(JSON.stringify(data));

  if (!processedData.params) {
    processedData.params = {};
  }

  // Extract consent data if present
  if (processedData.params.consent) {
    processedData.consent = processedData.params.consent;
    // Keep consent in params for now, will be removed later
  }

  const referer = request.headers.get("Referer") || "";
  const origin = request.headers.get("Origin") || "";
  const host = origin
    ? new URL(origin).host
    : referer
    ? new URL(referer).host
    : request.headers.get("Host");

  switch (processedData.name) {
    case "page_view":
      if (!processedData.params.page_title) {
        processedData.params.page_title = "Unknown Page";
      }
      if (!processedData.params.page_location) {
        if (processedData.params.page_path) {
          processedData.params.page_location = `https://${host}${processedData.params.page_path}`;
        } else if (referer) {
          processedData.params.page_location = referer;
        }
      }
      break;

    case "add_to_cart":
    case "purchase":
      if (!processedData.params.currency) {
        processedData.params.currency = "EUR";
      }
      break;
  }

  if (!processedData.params.engagement_time_msec) {
    processedData.params.engagement_time_msec = 1000;
  }

  if (DEBUG_MODE) {
    console.log("Processed event data:", JSON.stringify(processedData));
  }

  return processedData;
}

/**
 * =============================================================================
 * CORS AND REQUEST HANDLING
 * =============================================================================
 */

/**
 * Handle CORS preflight requests
 */
function handleCORS(request) {
  return new Response(null, {
    status: 204,
    headers: getCORSHeaders(request),
  });
}

/**
 * Get CORS headers for the response
 */
function getCORSHeaders(request) {
  const origin = request.headers.get("Origin") || "*";

  return {
    "Access-Control-Allow-Origin": origin,
    "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, Authorization, X-API-Key, X-Encrypted, X-Simple-request, X-WP-Nonce",
    "Access-Control-Max-Age": "86400",
  };
}
