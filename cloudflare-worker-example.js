/**
 * Enhanced GA4 Server-Side Tagging with GDPR Compliance
 * WITH COMPREHENSIVE BOT DETECTION AND CONSENT HANDLING
 *
 * This worker receives events from the WordPress plugin and forwards them to GA4
 * with full GDPR compliance and consent management
 *
 * @version 2.2.0 - GDPR Enhanced
 */

// GA4 Configuration
var DEBUG_MODE = true; // Set to true to enable debug logging
const GA4_ENDPOINT = "https://www.google-analytics.com/mp/collect";
const GA4_MEASUREMENT_ID = "G-xx"; // Your GA4 Measurement ID
const GA4_API_SECRET = "xx"; // Your GA4 API Secret

// Bot Detection Configuration
const BOT_DETECTION_ENABLED = true; // Set to false to disable bot filtering
const BOT_LOG_ENABLED = true; // Set to false to disable bot logging

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
  // Extract consent data from payload
  const consent = payload.params?.consent || {};
  
  if (DEBUG_MODE) {
    console.log("Processing GDPR consent:", JSON.stringify(consent));
  }

  // Apply consent-based data filtering
  if (consent.analytics_storage === "DENIED") {
    payload = applyAnalyticsConsentDenied(payload);
  }

  if (consent.ad_storage === "DENIED") {
    payload = applyAdvertisingConsentDenied(payload);
  }

  // Add consent signals to the GA4 payload at the top level
  if (Object.keys(consent).length > 0) {
    payload.consent = {
      analytics_storage: consent.analytics_storage || "DENIED",
      ad_storage: consent.ad_storage || "DENIED",
      ad_user_data: consent.ad_user_data || "DENIED",
      ad_personalization: consent.ad_personalization || "DENIED"
    };
  }

  return payload;
}

/**
 * Apply analytics consent denied rules
 * @param {Object} payload - The event payload
 * @returns {Object} Modified payload
 */
function applyAnalyticsConsentDenied(payload) {
  if (DEBUG_MODE) {
    console.log("Applying analytics consent denied rules");
  }

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
      payload.params.campaign = "(not provided)";
    }

    // Anonymize source/medium for paid traffic
    if (payload.params.medium && 
        ["cpc", "ppc", "paidsearch", "display", "banner", "cpm"].includes(payload.params.medium)) {
      payload.params.source = "(not provided)";
      payload.params.medium = "(not provided)";
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
  return request.headers.get('User-Agent') || '';
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

  // Extremely short engagement time
  if (params.engagement_time_msec && params.engagement_time_msec < 200) {
    suspiciousPatterns.push('very_short_engagement');
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

  // Check for user agent in params and validate it
  var userAgent = getUserAgent(payload, { headers: { get: () => '' } });
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
 * MAIN REQUEST HANDLER
 * =============================================================================
 */

addEventListener("fetch", (event) => {
  event.respondWith(handleRequest(event.request));
});

/**
 * Handle the incoming request (ENHANCED WITH GDPR COMPLIANCE)
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

    // CONSENT VALIDATION - Check if consent data exists
    const consentData = payload.params?.consent;
    
    if (!consentData || (!consentData.analytics_storage && !consentData.ad_storage)) {
      if (DEBUG_MODE) {
        console.log("No consent data found - rejecting event");
      }
      
      return new Response(
        JSON.stringify({
          success: false,
          error: "No consent data provided",
          gdpr_processed: false
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
      console.log("Consent validation passed:", JSON.stringify(consentData));
    }

    // GDPR CONSENT PROCESSING - Apply before bot detection
    const consentProcessedPayload = processGDPRConsent(payload);

    // BOT DETECTION CHECK
    const botDetection = detectBot(request, consentProcessedPayload);
    
    if (botDetection.isBot) {
      logBotDetection(botDetection, request, consentProcessedPayload);
      
      // Return success response but don't process the event
      return new Response(
        JSON.stringify({
          success: true,
          filtered: true,
          reason: "bot_detected",
          bot_score: botDetection.score,
          bot_details: DEBUG_MODE ? botDetection : undefined,
          gdpr_processed: true
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
      const consentInfo = consentProcessedPayload.params?.consent || {};
      console.log("Legitimate traffic detected:", JSON.stringify({
        eventName: consentProcessedPayload.name,
        country: String(request.cf && request.cf.country || 'unknown'),
        city: String(request.cf && request.cf.city || 'unknown'),
        userAgent: (request.headers.get('User-Agent') || ''),
        bot_score: botDetection.score,
        consent_mode: consentInfo.analytics_storage || 'unknown',
        gdpr_processed: true
      }));
    }

    return await handleGA4Event(consentProcessedPayload, request);
    
  } catch (error) {
    // Log the error
    console.error("Error processing request:", error);

    // Return error response
    return new Response(
      JSON.stringify({
        success: false,
        error: error.message,
        gdpr_processed: false
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
 * Handle GA4 events (Enhanced with consent handling)
 * @param {Object} payload
 * @param {Request} request
 */
async function handleGA4Event(payload, request) {
  // Process the event data
  const processedData = processEventData(payload, request);
  var analytics_storage_temp;
  // Log the incoming event data
  if (DEBUG_MODE) {
    console.log("Received GA4 event:", JSON.stringify(payload));
    
    // Log consent status
    const consentInfo = processedData.params?.consent || {};
    console.log("Consent status:", JSON.stringify(consentInfo));
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

  // Add consent signals at the request level if available
  if (processedData.consent) {
    ga4Payload.consent = processedData.consent;
  }

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

  // Remove consent from params (it's at request level now)
  if (ga4Payload.events[0].params.hasOwnProperty("consent")) {
    delete ga4Payload.events[0].params.consent;
  }

  // Add user_id if available and consent allows
  if (processedData.params.user_id) {
    ga4Payload.user_id = processedData.params.user_id;
    // Remove from params to avoid duplication
    delete processedData.params.user_id;
  }
  if(ga4Payload.consent.ad_storage){
    delete ga4Payload.consent.ad_storage;
  }
  if(ga4Payload.consent.functionality_storage){
    delete ga4Payload.consent.functionality_storage;
  }
  if(ga4Payload.consent.personalization_storage){
    delete ga4Payload.consent.personalization_storage;
  }
  if(ga4Payload.consent.security_storage){
    delete ga4Payload.consent.security_storage;
  }
    if(ga4Payload.consent.consent_mode){
    delete ga4Payload.consent.consent_mode;
  }
  if(ga4Payload.consent.consent_timestamp){
    delete ga4Payload.consent.consent_timestamp;
  }
  if(ga4Payload.consent.analytics_storage){
    analytics_storage_temp = ga4Payload.consent.analytics_storage;
    delete ga4Payload.consent.analytics_storage;
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
    
    // Log consent mode for tracking
    const consentMode = ga4Payload.consent?.analytics_storage_temp || 'unknown';
    console.log("Sending event with consent mode:", consentMode);
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

  // Get the response body from GA4 API
  const ga4ResponseBody = await ga4Response.text();

  // Log the GA4 API response
  if (DEBUG_MODE) {
    console.log("GA4 API Response Status:", ga4Response.status);
    console.log("GA4 API Response Body:", ga4ResponseBody);
  }

  // Return success response with consent information
  return new Response(
    JSON.stringify({
      "success": true,
      "event": processedData.name,
      "ga4_status": ga4Response.status,
      "ga4_response": DEBUG_MODE ? ga4ResponseBody : undefined,
      "debug": DEBUG_MODE ? ga4Payload : undefined,
      "consent_applied": ga4Payload.consent ? true : false,
      "consent_mode": ga4Payload.consent?.analytics_storage_temp || 'unknown'
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
 * UTILITY FUNCTIONS
 * =============================================================================
 */

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
    
    delete params.geo_continent;
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
    "Access-Control-Allow-Headers": "Content-Type, Authorization",
    "Access-Control-Max-Age": "86400",
  };
}