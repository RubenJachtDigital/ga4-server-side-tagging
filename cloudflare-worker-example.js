/**
 * Enhanced GA4 Server-Side Tagging + Google Ads Conversion Cloudflare Worker
 * WITH COMPREHENSIVE BOT DETECTION
 *
 * This worker receives events from the WordPress plugin and forwards them to GA4
 * and Google Ads conversions using multiple methods, while filtering out bot traffic.
 *
 * @version 2.1.1 - Fixed
 */

// GA4 Configuration
var DEBUG_MODE = true; // Set to true to enable debug logging
const GA4_ENDPOINT = "https://www.google-analytics.com/mp/collect";
const GA4_MEASUREMENT_ID = "G-xx"; // Your GA4 Measurement ID
const GA4_API_SECRET = "xx"; // Your GA4 API Secret

// Google Ads Configuration
const GOOGLE_ADS_ENDPOINT = "https://googleads.googleapis.com/v17/customers";
const GOOGLE_ADS_DEVELOPER_TOKEN = ""; // Your Google Ads Developer Token
const GOOGLE_ADS_CUSTOMER_ID = ""; // Your Google Ads Customer ID (without dashes)
const GOOGLE_ADS_ACCESS_TOKEN = ""; // OAuth2 Access Token (needs refresh mechanism)

// Enhanced Conversions Endpoint (Alternative method)
const ENHANCED_CONVERSIONS_ENDPOINT = "https://googleads.googleapis.com/v17/customers";

// Bot Detection Configuration
const BOT_DETECTION_ENABLED = true; // Set to false to disable bot filtering
const BOT_LOG_ENABLED = true; // Set to false to disable bot logging

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

  const userAgent = request.headers.get('User-Agent') || '';
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
  if (params.bot_data) {
    checks.push(checkWordPressBotData(params.bot_data));
  }

  // Check behavior patterns if available
  if (params.bot_data || params.event_timestamp) {
    checks.push(checkBehaviorPatterns(params.bot_data || {}, params));
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
 * Check user agent patterns for bot indicators
 */
function checkUserAgentPatterns(userAgent) {
  if (!userAgent || userAgent.length < 10) {
    return { isBot: true, reason: "missing_or_short_user_agent" };
  }

  const botPatterns = [
    // Common bots
    /bot/i, /crawl/i, /spider/i, /scraper/i,
    
    // Search engine bots
    /googlebot/i, /bingbot/i, /yahoo/i, /duckduckbot/i,
    /baiduspider/i, /yandexbot/i, /sogou/i,
    
    // Social media bots
    /facebookexternalhit/i, /twitterbot/i, /linkedinbot/i,
    /whatsapp/i, /telegrambot/i,
    
    // SEO/monitoring tools
    /semrushbot/i, /ahrefsbot/i, /mj12bot/i, /dotbot/i,
    /screaming frog/i, /seobility/i,
    
    // Headless browsers
    /headlesschrome/i, /phantomjs/i, /slimerjs/i,
    /htmlunit/i, /selenium/i, /webdriver/i,
    
    // Monitoring services
    /pingdom/i, /uptimerobot/i, /statuscake/i,
    /site24x7/i, /newrelic/i, /gtmetrix/i,
    
    // Generic automation
    /python/i, /requests/i, /curl/i, /wget/i,
    /apache-httpclient/i, /java/i, /okhttp/i,
    /node\.js/i, /go-http-client/i,
    
    // Suspicious patterns
    /^mozilla\/5\.0$/i, // Just "Mozilla/5.0"
    /compatible;?\s*$/i, // Ends with just "compatible"
    
    // Known problematic
    /chrome-lighthouse/i, /pagespeed/i, /lighthouse/i
  ];

  const matchedPattern = botPatterns.find(pattern => pattern.test(userAgent));
  if (matchedPattern) {
    return { isBot: true, reason: `user_agent_pattern: ${matchedPattern.source.substring(0, 50)}` };
  }

  return { isBot: false, reason: "user_agent_ok" };
}

/**
 * Check suspicious geography patterns
 */
function checkSuspiciousGeography(country, city, region) {
  const suspiciousPatterns = [];

  // Check for suspicious countries (commonly used by bots)
  const suspiciousCountries = ['XX', 'T1']; // Add more as needed
  if (suspiciousCountries.includes(country)) {
    suspiciousPatterns.push('suspicious_country');
  }

  // Check for generic city names often used by bots
  const suspiciousCities = ['unknown', 'localhost', 'test', ''];
  if (suspiciousCities.includes(city.toLowerCase())) {
    suspiciousPatterns.push('suspicious_city');
  }

  if (suspiciousPatterns.length >= 1) {
    return { isBot: true, reason: `suspicious_geography: ${suspiciousPatterns.join(', ')}` };
  }

  return { isBot: false, reason: "geography_ok" };
}

/**
 * Check WordPress bot data for comprehensive analysis
 */
function checkWordPressBotData(botData) {
  var suspiciousPatterns = [];

  // Check bot score passed from WordPress
  if (botData.bot_score && parseInt(botData.bot_score) > 70) {
    return { isBot: true, reason: 'high_bot_score: ' + botData.bot_score };
  }

  // Check for automation indicators
  if (botData.webdriver_detected === true || botData.has_automation_indicators === true) {
    suspiciousPatterns.push('automation_detected');
  }

  // Check JavaScript availability
  if (botData.has_javascript === false) {
    suspiciousPatterns.push('no_javascript');
  }

  // Check user interaction
  if (botData.user_interaction_detected === false) {
    suspiciousPatterns.push('no_user_interaction');
  }

  // Check suspicious timing patterns
  if (botData.page_load_time && parseInt(botData.page_load_time) < 100) {
    suspiciousPatterns.push('suspiciously_fast_load');
  }

  // Check engagement time
  if (botData.engagement_calculated && parseInt(botData.engagement_calculated) < 1000) {
    suspiciousPatterns.push('very_short_engagement');
  }

  // Check screen dimensions (common bot patterns)
  var screenWidth = botData.screen_available_width;
  var screenHeight = botData.screen_available_height;
  if (screenWidth && screenHeight) {
    var commonBotResolutions = [
      { w: 1024, h: 768 }, { w: 1366, h: 768 }, { w: 1920, h: 1080 },
      { w: 800, h: 600 }, { w: 1280, h: 720 }, { w: 1440, h: 900 }
    ];
    
    var isCommonBotRes = commonBotResolutions.some(function(res) {
      return res.w === screenWidth && res.h === screenHeight;
    });
    
    if (isCommonBotRes) {
      suspiciousPatterns.push('common_bot_resolution');
    }
  }

  // Check hardware indicators
  if (botData.hardware_concurrency === 0 || botData.max_touch_points === 0) {
    suspiciousPatterns.push('suspicious_hardware');
  }

  // Check for missing or suspicious browser features
  if (botData.cookie_enabled === false) {
    suspiciousPatterns.push('cookies_disabled');
  }

  if (botData.color_depth && (botData.color_depth < 16 || botData.color_depth > 32)) {
    suspiciousPatterns.push('unusual_color_depth');
  }

  if (suspiciousPatterns.length >= 2) {
    return { isBot: true, reason: 'wordpress_bot_data: ' + suspiciousPatterns.join(', ') };
  }

  return { isBot: false, reason: "wordpress_bot_data_ok" };
}

/**
 * Check behavior patterns using WordPress-passed data
 */
function checkBehaviorPatterns(botData, params) {
  var suspiciousPatterns = [];

  // Timing analysis
  if (botData.event_creation_time && botData.session_start_time) {
    var sessionDuration = botData.event_creation_time - botData.session_start_time;
    if (sessionDuration < 2000) { // Less than 2 seconds
      suspiciousPatterns.push('very_short_session');
    }
  }

  // Perfect timing patterns (bots often have regular intervals)
  if (params.event_timestamp && params.event_timestamp % 10 === 0) {
    suspiciousPatterns.push('round_timestamp');
  }

  // Check timezone consistency
  if (botData.timezone) {
    // Basic timezone validation - bots often have inconsistent or default timezones
    var suspiciousTimezones = ['UTC', 'GMT', 'America/New_York'];
    if (suspiciousTimezones.indexOf(botData.timezone) !== -1) {
      suspiciousPatterns.push('suspicious_timezone');
    }
  }

  // Platform inconsistencies
  if (botData.platform && botData.device_type) {
    // Check for platform/device mismatches
    if (botData.platform.toLowerCase().indexOf('win') !== -1 && botData.device_type === 'mobile') {
      suspiciousPatterns.push('platform_device_mismatch');
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
  if (/curl|wget|python|node|automation|postman/i.test(userAgent)) {
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
 * Check event data for bot patterns
 */
function checkEventData(payload) {
  const params = payload.params || {};
  
  // Check for suspicious event patterns
  const suspiciousPatterns = [];

  // Extremely short engagement time
  if (params.engagement_time_msec && params.engagement_time_msec < 500) {
    suspiciousPatterns.push('very_short_engagement');
  }

  // Perfect timestamp patterns (bots often have regular timing)
  if (params.event_timestamp && params.event_timestamp % 10 === 0) {
    suspiciousPatterns.push('round_timestamp');
  }

  // Suspicious screen resolutions common to headless browsers
  const botResolutions = ['1024x768', '1366x768', '1920x1080', '800x600', '1280x720'];
  if (params.screen_resolution && botResolutions.includes(params.screen_resolution)) {
    suspiciousPatterns.push('bot_resolution');
  }

  // Missing JavaScript indicators
  if (params.has_javascript === false) {
    suspiciousPatterns.push('no_javascript');
  }

  // WebDriver indicators
  if (params.user_agent && /webdriver|automation/i.test(params.user_agent)) {
    suspiciousPatterns.push('webdriver_detected');
  }

  if (suspiciousPatterns.length >= 2) {
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
    url: request.url
  }));
}

/**
 * =============================================================================
 * MAIN REQUEST HANDLER
 * =============================================================================
 */

addEventListener("fetch", (event) => {
  event.respondWith(handleRequest(event.request));
});

/**
 * Handle the incoming request (ENHANCED WITH BOT DETECTION)
 * @param {Request} request
 */
async function handleRequest(request) {
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

  try {
    // Parse the request body
    const payload = await request.json();

    if (DEBUG_MODE) {
      console.log("Received payload:", JSON.stringify(payload));
    }

    // BOT DETECTION CHECK
    const botDetection = detectBot(request, payload);
    
    if (botDetection.isBot) {
      logBotDetection(botDetection, request, payload);
      
      // Return success response but don't process the event
      return new Response(
        JSON.stringify({
          success: true,
          filtered: true,
          reason: "bot_detected",
          bot_score: botDetection.score,
          bot_details: DEBUG_MODE ? botDetection : undefined
        }),
        {
          status: 200,
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
    }

    // Log legitimate traffic (optional, for monitoring)
    if (DEBUG_MODE) {
      console.log("Legitimate traffic detected:", JSON.stringify({
        eventName: payload.name,
        country: String(request.cf && request.cf.country || 'unknown'),
        city: String(request.cf && request.cf.city || 'unknown'),
        userAgent: (request.headers.get('User-Agent') || ''),
        bot_score: botDetection.score,

      }));
    }

    // Continue with normal processing for legitimate traffic
    // Check if this is a Google Ads conversion event
    if (isGoogleAdsConversion(payload.name)) {
      return await handleGoogleAdsConversion(payload, request);
    } else {
      return await handleGA4Event(payload, request);
    }
  } catch (error) {
    // Log the error
    console.error("Error processing request:", error);

    // Return error response
    return new Response(
      JSON.stringify({
        success: false,
        error: error.message,
      }),
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

/**
 * =============================================================================
 * GOOGLE ADS & GA4 EVENT HANDLING
 * =============================================================================
 */

/**
 * Check if this is a Google Ads conversion event
 * @param {string} eventName
 * @returns {boolean}
 */
function isGoogleAdsConversion(eventName) {
  return (
    eventName &&
    (eventName.startsWith("google_ads_") ||
      eventName === "google_ads_purchase" ||
      eventName === "google_ads_lead" ||
      eventName === "google_ads_phone_call" ||
      eventName === "google_ads_email_click")
  );
}

/**
 * Handle Google Ads conversion tracking
 * @param {Object} payload
 * @param {Request} request
 */
async function handleGoogleAdsConversion(payload, request) {
  if (DEBUG_MODE) {
    console.log("Processing Google Ads conversion:", JSON.stringify(payload));
  }

  // Extract conversion data from params
  const conversionData = payload.params;

  // Validate required conversion data
  if (!conversionData.conversion_id || !conversionData.conversion_label) {
    return new Response(
      JSON.stringify({
        "error":
          "Missing required Google Ads conversion data (conversion_id or conversion_label)",
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

  try {
    // Try multiple methods for Google Ads conversion tracking
    const results = await Promise.allSettled([
      // Method 1: Enhanced Conversions via Google Click ID
      sendEnhancedConversionViaGCLID(conversionData, request),

      // Method 2: Enhanced Conversions via User Data
      sendEnhancedConversionViaUserData(conversionData, request),

      // Method 3: Backup via GA4 (for importing to Google Ads)
      sendToGA4AsCustomEvent(conversionData, request),
    ]);

    // Check if at least one method succeeded
    const successfulMethods = results.filter(
      (result) => result.status === "fulfilled" && result.value
    );

    if (successfulMethods.length > 0) {
      return new Response(
        JSON.stringify({
          "success": true,
          "event": payload.name,
          "conversion_id": conversionData.conversion_id,
          "conversion_label": conversionData.conversion_label,
          "methods_used": results.map((result, index) => ({
            "method": ["gclid", "user_data", "ga4_backup"][index],
            "success": result.status === "fulfilled" && result.value,
            "error": result.status === "rejected" ? result.reason?.message : null,
          })),
          "debug": DEBUG_MODE ? conversionData : undefined,
        }),
        {
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
    } else {
      throw new Error("All Google Ads conversion methods failed");
    }
  } catch (error) {
    console.error("Google Ads conversion error:", error);

    return new Response(
      JSON.stringify({
        "success": false,
        "error": "Failed to process Google Ads conversion: " + error.message,
      }),
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

/**
 * Handle regular GA4 events
 * @param {Object} payload
 * @param {Request} request
 */
async function handleGA4Event(payload, request) {
  // Process the event data
  const processedData = processEventData(payload, request);

  if (processedData.params.debug_mode) {
    DEBUG_MODE = true;
  }

  // Log the incoming event data
  if (DEBUG_MODE) {
    console.log("Received GA4 event:", JSON.stringify(payload));
  }

  // Validate required parameters
  if (!processedData.name) {
    return new Response(JSON.stringify({ "error": "Missing event name" }), {
      status: 400,
      headers: {
        "Content-Type": "application/json",
        ...getCORSHeaders(request),
      },
    });
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

  // Add user_location at request level if available
  if (userLocation && Object.keys(userLocation).length > 0) {
    ga4Payload.user_location = userLocation;
  }

  // Remove client_id from params to avoid duplication, only if it exists
  if (ga4Payload.events[0].params.hasOwnProperty("client_id")) {
    delete ga4Payload.events[0].params.client_id;
  }
  // Remove botData from params
  if (ga4Payload.events[0].params.hasOwnProperty("botData")) {
    delete ga4Payload.events[0].params.botData;
  }

  // Add user_id if available
  if (processedData.params.user_id) {
    ga4Payload.user_id = processedData.params.user_id;
    // Remove from params to avoid duplication
    delete processedData.params.user_id;
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
    console.log("Finished payload:" + JSON.stringify(ga4Payload));
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

  // Return success response
  return new Response(
    JSON.stringify({
      "success": true,
      "event": processedData.name,
      "ga4_status": ga4Response.status,
      "debug": DEBUG_MODE ? ga4Payload : undefined,
    }),
    {
      headers: {
        "Content-Type": "application/json",
        ...getCORSHeaders(request),
      },
    }
  );
}

/**
 * =============================================================================
 * GOOGLE ADS CONVERSION METHODS
 * =============================================================================
 */

/**
 * Send Enhanced Conversion via GCLID (Method 1)
 */
async function sendEnhancedConversionViaGCLID(conversionData, request) {
  if (!conversionData.gclid) {
    if (DEBUG_MODE) {
      console.log("No GCLID available for enhanced conversion");
    }
    return false;
  }

  const conversionPayload = {
    "conversions": [
      {
        "conversion_action": `customers/${GOOGLE_ADS_CUSTOMER_ID}/conversionActions/${conversionData.conversion_label}`,
        "conversion_date_time": new Date(
          conversionData.timestamp * 1000
        ).toISOString(),
        "conversion_value": parseFloat(conversionData.value) || 0,
        "currency_code": conversionData.currency || "EUR",
        "gclid": conversionData.gclid,
        "order_id": conversionData.transaction_id || conversionData.lead_id,
        "user_identifiers": await buildUserIdentifiers(conversionData),
        "cart_data": conversionData.items
          ? {
              "items": conversionData.items.map((item) => ({
                "product_id": item.item_id,
                "quantity": parseInt(item.quantity) || 1,
                "unit_price": parseFloat(item.price) || 0,
              })),
            }
          : undefined,
      },
    ],
    "validate_only": false,
  };

  if (DEBUG_MODE) {
    console.log("GCLID conversion payload:", JSON.stringify(conversionPayload));
  }

  if (GOOGLE_ADS_ACCESS_TOKEN && GOOGLE_ADS_DEVELOPER_TOKEN) {
    try {
      const response = await fetch(
        `${GOOGLE_ADS_ENDPOINT}/${GOOGLE_ADS_CUSTOMER_ID}/conversionUploads:uploadConversions`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${GOOGLE_ADS_ACCESS_TOKEN}`,
            "developer-token": GOOGLE_ADS_DEVELOPER_TOKEN,
          },
          body: JSON.stringify(conversionPayload),
        }
      );

      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(
          `Google Ads API error: ${response.status} ${errorText}`
        );
      }

      const result = await response.json();
      if (DEBUG_MODE) {
        console.log("Google Ads API response:",json.stringify(result));
      }

      return true;
    } catch (error) {
      console.error("Google Ads API GCLID method failed:", error);
      return false;
    }
  }

  return false;
}

/**
 * Send Enhanced Conversion via User Data (Method 2)
 */
async function sendEnhancedConversionViaUserData(conversionData, request) {
  const userIdentifiers = await buildUserIdentifiers(conversionData);

  if (userIdentifiers.length === 0) {
    if (DEBUG_MODE) {
      console.log("No user identifiers available for enhanced conversion");
    }
    return false;
  }

  const conversionPayload = {
    "conversions": [
      {
        "conversion_action": `customers/${GOOGLE_ADS_CUSTOMER_ID}/conversionActions/${conversionData.conversion_label}`,
        "conversion_date_time": new Date(
          conversionData.timestamp * 1000
        ).toISOString(),
        "conversion_value": parseFloat(conversionData.value) || 0,
        "currency_code": conversionData.currency || "EUR",
        "order_id": conversionData.transaction_id || conversionData.lead_id,
        "user_identifiers": userIdentifiers,
        "cart_data": conversionData.items
          ? {
              "items": conversionData.items.map((item) => ({
                "product_id": item.item_id,
                "quantity": parseInt(item.quantity) || 1,
                "unit_price": parseFloat(item.price) || 0,
              })),
            }
          : undefined,
      },
    ],
    "validate_only": false,
  };

  if (DEBUG_MODE) {
    console.log(
      "User data conversion payload:",
      JSON.stringify(conversionPayload)
    );
  }

  if (GOOGLE_ADS_ACCESS_TOKEN && GOOGLE_ADS_DEVELOPER_TOKEN) {
    try {
      const response = await fetch(
        `${GOOGLE_ADS_ENDPOINT}/${GOOGLE_ADS_CUSTOMER_ID}/conversionUploads:uploadConversions`,
        {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Authorization": `Bearer ${GOOGLE_ADS_ACCESS_TOKEN}`,
            "developer-token": GOOGLE_ADS_DEVELOPER_TOKEN,
          },
          body: JSON.stringify(conversionPayload),
        }
      );

      if (!response.ok) {
        const errorText = await response.text();
        throw new Error(
          `Google Ads API error: ${response.status} ${errorText}`
        );
      }

      const result = await response.json();
      if (DEBUG_MODE) {
        console.log("Google Ads API user data response:", json.stringify(result));
      }

      return true;
    } catch (error) {
      console.error("Google Ads API user data method failed:", json.stringify(error));
      return false;
    }
  }

  return false;
}

/**
 * Send to GA4 as custom event for importing to Google Ads (Method 3)
 */
async function sendToGA4AsCustomEvent(conversionData, request) {
  try {
    const ga4ConversionEvent = {
      "name": "ads_conversion",
      "params": {
        "conversion_id": conversionData.conversion_id,
        "conversion_label": conversionData.conversion_label,
        "conversion_type": conversionData.conversion_type,
        "transaction_id": conversionData.transaction_id || conversionData.lead_id,
        "value": parseFloat(conversionData.value) || 0,
        "currency": conversionData.currency || "EUR",
        "gclid": conversionData.gclid || "",
        "utm_source": conversionData.utm_source || "",
        "utm_medium": conversionData.utm_medium || "",
        "utm_campaign": conversionData.utm_campaign || "",
        "utm_content": conversionData.utm_content || "",
        "utm_term": conversionData.utm_term || "",
        "client_id": conversionData.client_id,
        "session_id": conversionData.session_id,
        "conversion_timestamp": conversionData.timestamp,
        "page_location": conversionData.page_location,
        "page_referrer": conversionData.page_referrer,
        "user_agent": conversionData.user_agent,
        "items": conversionData.items || [],
      },
    };

    await sendEventToGA4(ga4ConversionEvent, conversionData.client_id);

    if (DEBUG_MODE) {
      console.log("GA4 conversion event sent successfully");
    }

    return true;
  } catch (error) {
    console.error("GA4 backup method failed:", error);
    return false;
  }
}

/**
 * =============================================================================
 * UTILITY FUNCTIONS
 * =============================================================================
 */

/**
 * Build user identifiers for enhanced conversions
 */
async function buildUserIdentifiers(conversionData) {
  const userIdentifiers = [];

  // Email identifier
  if (conversionData.email) {
    userIdentifiers.push({
      "hashed_email": await hashString(conversionData.email.toLowerCase().trim()),
    });
  }

  // Phone identifier
  if (conversionData.phone) {
    const normalizedPhone = normalizePhoneNumber(conversionData.phone);
    if (normalizedPhone) {
      userIdentifiers.push({
        "hashed_phone_number": await hashString(normalizedPhone),
      });
    }
  }

  // Address information
  if (
    conversionData.first_name ||
    conversionData.last_name ||
    conversionData.street_address
  ) {
    const addressInfo = {};

    if (conversionData.first_name) {
      addressInfo.hashed_first_name = await hashString(
        conversionData.first_name.toLowerCase().trim()
      );
    }
    if (conversionData.last_name) {
      addressInfo.hashed_last_name = await hashString(
        conversionData.last_name.toLowerCase().trim()
      );
    }
    if (conversionData.street_address) {
      addressInfo.hashed_street_address = await hashString(
        conversionData.street_address.toLowerCase().trim()
      );
    }
    if (conversionData.city) {
      addressInfo.city = conversionData.city;
    }
    if (conversionData.region) {
      addressInfo.state = conversionData.region;
    }
    if (conversionData.postal_code) {
      addressInfo.postal_code = conversionData.postal_code;
    }
    if (conversionData.country) {
      addressInfo.country_code = conversionData.country;
    }

    if (Object.keys(addressInfo).length > 0) {
      userIdentifiers.push({
        "address_info": addressInfo,
      });
    }
  }

  return userIdentifiers;
}

/**
 * Send event to GA4
 */
async function sendEventToGA4(eventData, clientId) {
  const ga4Payload = {
    "client_id": clientId || generateClientId(),
    "events": [eventData],
  };

  const response = await fetch(
    `${GA4_ENDPOINT}?measurement_id=${GA4_MEASUREMENT_ID}&api_secret=${GA4_API_SECRET}`,
    {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify(ga4Payload),
    }
  );

  if (!response.ok) {
    throw new Error(`GA4 API error: ${response.status}`);
  }

  return true;
}

/**
 * Hash a string using SHA-256
 */
async function hashString(str) {
  if (!str) return "";

  const encoder = new TextEncoder();
  const data = encoder.encode(str);
  const hashBuffer = await crypto.subtle.digest("SHA-256", data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map((b) => b.toString(16).padStart(2, "0")).join("");
}

/**
 * Normalize phone number for Google Ads
 */
function normalizePhoneNumber(phone) {
  if (!phone) return "";

  let normalized = phone.replace(/\D/g, "");

  if (normalized.length === 10) {
    normalized = "1" + normalized;
  } else if (normalized.length === 11 && normalized.startsWith("1")) {
    // Already has US country code
  } else if (normalized.length > 7) {
    // Assume it already has a country code
  } else {
    return "";
  }

  return normalized;
}

/**
 * Generate a client ID
 */
function generateClientId() {
  return (
    Math.round(2147483647 * Math.random()) + "." + Math.round(Date.now() / 1000)
  );
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
    "The Netherlands": "NL",
    "Netherlands": "NL",
    "United States": "US",
    "USA": "US",
    "United Kingdom": "GB",
    "UK": "GB",
    "Germany": "DE",
    "France": "FR",
    "Spain": "ES",
    "Italy": "IT",
    "Belgium": "BE",
    "Canada": "CA",
    "Australia": "AU",
    "Japan": "JP",
    "China": "CN",
    "India": "IN",
    "Brazil": "BR",
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

  if (params.geo_city || params.city) {
    const cityName = params.geo_city || params.city;
    userLocation.city = cleanLocationString(cityName);
    delete params.geo_city;
    delete params.city;
  }

  if (params.geo_country || params.country) {
    const countryName = params.geo_country || params.country;
    userLocation.country_id = convertCountryToISO(countryName);
    delete params.geo_country;
    delete params.country;
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

  if (DEBUG_MODE && Object.keys(userLocation).length > 0) {
    console.log("Final user_location object:", JSON.stringify(userLocation));
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
    "Access-Control-Allow-Headers": "Content-Type, Authorization",
    "Access-Control-Max-Age": "86400",
  };
}

/**
 * Process and normalize event data
 */
function processEventData(data, request) {
  const processedData = JSON.parse(JSON.stringify(data));

  if (!processedData.params) {
    processedData.params = {};
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
      if (!processedData.params.currency) {
        processedData.params.currency = "EUR";
      }
      break;
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