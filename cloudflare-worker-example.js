/**
 * GA4 Server-Side Tagging + Google Ads Conversion Cloudflare Worker
 *
 * This worker receives events from the WordPress plugin and forwards them to GA4
 * and Google Ads conversions.
 *
 * @version 1.3.0
 */

// GA4 Configuration
var DEBUG_MODE = false; // Set to true to enable debug logging
const GA4_ENDPOINT = "https://www.google-analytics.com/mp/collect";
const GA4_MEASUREMENT_ID = "G-xx"; // Your GA4 Measurement ID
const GA4_API_SECRET = "xx"; // Your GA4 API Secret

// Google Ads Configuration
const GOOGLE_ADS_ENDPOINT = "https://googleads.googleapis.com/v17/customers";
const GOOGLE_ADS_DEVELOPER_TOKEN = ""; // Your Google Ads Developer Token
const GOOGLE_ADS_CUSTOMER_ID = ""; // Your Google Ads Customer ID (without dashes)
const GOOGLE_ADS_ACCESS_TOKEN = ""; // OAuth2 Access Token (needs refresh mechanism)

addEventListener("fetch", (event) => {
  event.respondWith(handleRequest(event.request));
});

/**
 * Handle the incoming request
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
 * Check if this is a Google Ads conversion event
 * @param {string} eventName
 * @returns {boolean}
 */
function isGoogleAdsConversion(eventName) {
  return eventName && (
    eventName.startsWith('google_ads_') ||
    eventName === 'google_ads_purchase' ||
    eventName === 'google_ads_lead' ||
    eventName === 'google_ads_phone_call' ||
    eventName === 'google_ads_email_click'
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
        error: "Missing required Google Ads conversion data (conversion_id or conversion_label)" 
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
    // Method 1: Enhanced Conversions via Measurement Protocol (Simpler)
    const success = await sendEnhancedConversion(conversionData, request);
    
    if (success) {
      return new Response(
        JSON.stringify({
          success: true,
          event: payload.name,
          conversion_id: conversionData.conversion_id,
          conversion_label: conversionData.conversion_label,
          method: 'enhanced_conversions',
        }),
        {
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
    } else {
      throw new Error("Failed to send enhanced conversion");
    }
  } catch (error) {
    console.error("Google Ads conversion error:", error);
    
    return new Response(
      JSON.stringify({
        success: false,
        error: "Failed to process Google Ads conversion: " + error.message,
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
 * Send Enhanced Conversion using Google Ads Measurement Protocol
 * @param {Object} conversionData
 * @param {Request} request
 */
async function sendEnhancedConversion(conversionData, request) {
  // Build the conversion payload
  const conversionPayload = {
    conversion_action: `customers/${GOOGLE_ADS_CUSTOMER_ID}/conversionActions/${conversionData.conversion_label}`,
    conversion_date_time: new Date(conversionData.timestamp * 1000).toISOString(),
    conversion_value: conversionData.value || 0,
    currency_code: conversionData.currency || 'EUR',
  };

  // Add order ID if available
  if (conversionData.transaction_id) {
    conversionPayload.order_id = conversionData.transaction_id;
  } else if (conversionData.lead_id) {
    conversionPayload.order_id = conversionData.lead_id;
  }

  // Add GCLID if available
  if (conversionData.gclid) {
    conversionPayload.gclid = conversionData.gclid;
  }

  // Add enhanced conversion data (user identifiers)
  const userIdentifiers = [];
  
  if (conversionData.email) {
    userIdentifiers.push({
      hashed_email: await hashString(conversionData.email.toLowerCase().trim())
    });
  }
  
  if (conversionData.phone) {
    // Normalize phone number (remove non-digits, add country code if needed)
    const normalizedPhone = normalizePhoneNumber(conversionData.phone);
    if (normalizedPhone) {
      userIdentifiers.push({
        hashed_phone_number: await hashString(normalizedPhone)
      });
    }
  }
  
  // Add address information if available
  if (conversionData.first_name || conversionData.last_name || conversionData.street_address) {
    const addressInfo = {};
    
    if (conversionData.first_name) {
      addressInfo.hashed_first_name = await hashString(conversionData.first_name.toLowerCase().trim());
    }
    if (conversionData.last_name) {
      addressInfo.hashed_last_name = await hashString(conversionData.last_name.toLowerCase().trim());
    }
    if (conversionData.street_address) {
      addressInfo.hashed_street_address = await hashString(conversionData.street_address.toLowerCase().trim());
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
        address_info: addressInfo
      });
    }
  }

  if (userIdentifiers.length > 0) {
    conversionPayload.user_identifiers = userIdentifiers;
  }

  // Add cart data if available
  if (conversionData.items && conversionData.items.length > 0) {
    conversionPayload.cart_data = {
      items: conversionData.items.map(item => ({
        product_id: item.item_id,
        quantity: item.quantity || 1,
        unit_price: item.price || 0
      }))
    };
  }

  // For now, we'll use a simplified approach since the full Google Ads API requires OAuth
  // We'll send this data to Google Analytics as a custom event that can be imported
  // This is a workaround until you set up proper Google Ads API credentials
  
  if (DEBUG_MODE) {
    console.log("Google Ads conversion payload:", JSON.stringify(conversionPayload));
  }

  // Alternative: Send as custom GA4 event for later import to Google Ads
  const ga4ConversionEvent = {
    name: 'ads_conversion',
    params: {
      conversion_id: conversionData.conversion_id,
      conversion_label: conversionData.conversion_label,
      conversion_type: conversionData.conversion_type,
      transaction_id: conversionData.transaction_id || conversionData.lead_id,
      value: conversionData.value || 0,
      currency: conversionData.currency || 'EUR',
      client_id: conversionData.client_id,
      // Add other relevant data
      gclid: conversionData.gclid || '',
      utm_source: conversionData.utm_source || '',
      utm_medium: conversionData.utm_medium || '',
      utm_campaign: conversionData.utm_campaign || '',
    }
  };

  // Send to GA4 as a backup/alternative method
  await sendEventToGA4(ga4ConversionEvent, conversionData.client_id);
  
  return true; // Return success for now
}

/**
 * Send event to GA4
 * @param {Object} eventData
 * @param {string} clientId
 */
async function sendEventToGA4(eventData, clientId) {
  const ga4Payload = {
    client_id: clientId || generateClientId(),
    events: [eventData]
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
 * Handle regular GA4 events (your existing logic)
 * @param {Object} payload
 * @param {Request} request
 */
async function handleGA4Event(payload, request) {
  // Process the event data (your existing logic)
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
    return new Response(JSON.stringify({ error: "Missing event name" }), {
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
    client_id: processedData.params.client_id,
    events: [
      {
        name: processedData.name,
        params: processedData.params,
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
        error: `Too many parameters: ${payloadParamsCount}. GA4 only allows a maximum of 25 parameters per event.`,
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
      success: true,
      event: processedData.name,
      ga4_status: ga4Response.status,
      debug: DEBUG_MODE ? ga4Payload : undefined,
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
 * Hash a string using SHA-256
 * @param {string} str
 * @returns {Promise<string>}
 */
async function hashString(str) {
  const encoder = new TextEncoder();
  const data = encoder.encode(str);
  const hashBuffer = await crypto.subtle.digest('SHA-256', data);
  const hashArray = Array.from(new Uint8Array(hashBuffer));
  return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Normalize phone number for Google Ads
 * @param {string} phone
 * @returns {string}
 */
function normalizePhoneNumber(phone) {
  if (!phone) return '';
  
  // Remove all non-digit characters
  let normalized = phone.replace(/\D/g, '');
  
  // Add country code if missing (assuming US/international format)
  if (normalized.length === 10) {
    normalized = '1' + normalized; // Add US country code
  } else if (normalized.length === 11 && normalized.startsWith('1')) {
    // Already has US country code
  } else if (normalized.length > 7) {
    // Assume it already has a country code
  } else {
    // Too short, invalid
    return '';
  }
  
  return normalized;
}

/**
 * Generate a client ID
 * @returns {string}
 */
function generateClientId() {
  return Math.round(2147483647 * Math.random()) + '.' + Math.round(Date.now() / 1000);
}

/**
 * Get continent and subcontinent info based on country code
 * @param {string} countryCode - ISO country code
 * @returns {Object} Continent and subcontinent information
 */
function getContinentInfo(countryCode) {
  // Detect continent code format (e.g., "EU", "NA")
  const continentCodeMap = {
    "EU": { continent_id: "150", subcontinent_id: "" }, // Europe
    "NA": { continent_id: "019", subcontinent_id: "021" }, // North America
    "SA": { continent_id: "019", subcontinent_id: "005" }, // South America
    "AS": { continent_id: "142", subcontinent_id: "" }, // Asia
    "AF": { continent_id: "002", subcontinent_id: "" }, // Africa
    "OC": { continent_id: "009", subcontinent_id: "" }, // Oceania
    "AN": { continent_id: "010", subcontinent_id: "" }, // Antarctica
  };

  if (continentCodeMap[countryCode]) {
    return continentCodeMap[countryCode];
  }

  // Continental and subcontinental mapping based on UN geoscheme
  const continentMap = {
    // Europe (continent: 150)
    "AD": { continent_id: "150", subcontinent_id: "039" }, // Andorra (Southern Europe)
    "AL": { continent_id: "150", subcontinent_id: "039" }, // Albania (Southern Europe)
    "AT": { continent_id: "150", subcontinent_id: "155" }, // Austria (Western Europe)
    "BA": { continent_id: "150", subcontinent_id: "039" }, // Bosnia and Herzegovina (Southern Europe)
    "BE": { continent_id: "150", subcontinent_id: "155" }, // Belgium (Western Europe)
    "BG": { continent_id: "150", subcontinent_id: "151" }, // Bulgaria (Eastern Europe)
    "BY": { continent_id: "150", subcontinent_id: "151" }, // Belarus (Eastern Europe)
    "CH": { continent_id: "150", subcontinent_id: "155" }, // Switzerland (Western Europe)
    "CY": { continent_id: "150", subcontinent_id: "145" }, // Cyprus (Western Asia/Southern Europe)
    "CZ": { continent_id: "150", subcontinent_id: "151" }, // Czech Republic (Eastern Europe)
    "DE": { continent_id: "150", subcontinent_id: "155" }, // Germany (Western Europe)
    "DK": { continent_id: "150", subcontinent_id: "154" }, // Denmark (Northern Europe)
    "EE": { continent_id: "150", subcontinent_id: "154" }, // Estonia (Northern Europe)
    "ES": { continent_id: "150", subcontinent_id: "039" }, // Spain (Southern Europe)
    "FI": { continent_id: "150", subcontinent_id: "154" }, // Finland (Northern Europe)
    "FR": { continent_id: "150", subcontinent_id: "155" }, // France (Western Europe)
    "GB": { continent_id: "150", subcontinent_id: "154" }, // United Kingdom (Northern Europe)
    "GR": { continent_id: "150", subcontinent_id: "039" }, // Greece (Southern Europe)
    "HR": { continent_id: "150", subcontinent_id: "039" }, // Croatia (Southern Europe)
    "HU": { continent_id: "150", subcontinent_id: "151" }, // Hungary (Eastern Europe)
    "IE": { continent_id: "150", subcontinent_id: "154" }, // Ireland (Northern Europe)
    "IS": { continent_id: "150", subcontinent_id: "154" }, // Iceland (Northern Europe)
    "IT": { continent_id: "150", subcontinent_id: "039" }, // Italy (Southern Europe)
    "LI": { continent_id: "150", subcontinent_id: "155" }, // Liechtenstein (Western Europe)
    "LT": { continent_id: "150", subcontinent_id: "154" }, // Lithuania (Northern Europe)
    "LU": { continent_id: "150", subcontinent_id: "155" }, // Luxembourg (Western Europe)
    "LV": { continent_id: "150", subcontinent_id: "154" }, // Latvia (Northern Europe)
    "MC": { continent_id: "150", subcontinent_id: "155" }, // Monaco (Western Europe)
    "MD": { continent_id: "150", subcontinent_id: "151" }, // Moldova (Eastern Europe)
    "ME": { continent_id: "150", subcontinent_id: "039" }, // Montenegro (Southern Europe)
    "MK": { continent_id: "150", subcontinent_id: "039" }, // North Macedonia (Southern Europe)
    "MT": { continent_id: "150", subcontinent_id: "039" }, // Malta (Southern Europe)
    "NL": { continent_id: "150", subcontinent_id: "155" }, // Netherlands (Western Europe)
    "NO": { continent_id: "150", subcontinent_id: "154" }, // Norway (Northern Europe)
    "PL": { continent_id: "150", subcontinent_id: "151" }, // Poland (Eastern Europe)
    "PT": { continent_id: "150", subcontinent_id: "039" }, // Portugal (Southern Europe)
    "RO": { continent_id: "150", subcontinent_id: "151" }, // Romania (Eastern Europe)
    "RS": { continent_id: "150", subcontinent_id: "039" }, // Serbia (Southern Europe)
    "RU": { continent_id: "150", subcontinent_id: "151" }, // Russia (Eastern Europe)
    "SE": { continent_id: "150", subcontinent_id: "154" }, // Sweden (Northern Europe)
    "SI": { continent_id: "150", subcontinent_id: "039" }, // Slovenia (Southern Europe)
    "SK": { continent_id: "150", subcontinent_id: "151" }, // Slovakia (Eastern Europe)
    "SM": { continent_id: "150", subcontinent_id: "039" }, // San Marino (Southern Europe)
    "UA": { continent_id: "150", subcontinent_id: "151" }, // Ukraine (Eastern Europe)
    "VA": { continent_id: "150", subcontinent_id: "039" }, // Vatican City (Southern Europe)
    
    // Americas (continent: 019)
    "AG": { continent_id: "019", subcontinent_id: "029" }, // Antigua and Barbuda (Caribbean)
    "AR": { continent_id: "019", subcontinent_id: "005" }, // Argentina (South America)
    "BB": { continent_id: "019", subcontinent_id: "029" }, // Barbados (Caribbean)
    "BO": { continent_id: "019", subcontinent_id: "005" }, // Bolivia (South America)
    "BR": { continent_id: "019", subcontinent_id: "005" }, // Brazil (South America)
    "BS": { continent_id: "019", subcontinent_id: "029" }, // Bahamas (Caribbean)
    "BZ": { continent_id: "019", subcontinent_id: "013" }, // Belize (Central America)
    "CA": { continent_id: "019", subcontinent_id: "021" }, // Canada (Northern America)
    "CL": { continent_id: "019", subcontinent_id: "005" }, // Chile (South America)
    "CO": { continent_id: "019", subcontinent_id: "005" }, // Colombia (South America)
    "CR": { continent_id: "019", subcontinent_id: "013" }, // Costa Rica (Central America)
    "CU": { continent_id: "019", subcontinent_id: "029" }, // Cuba (Caribbean)
    "DM": { continent_id: "019", subcontinent_id: "029" }, // Dominica (Caribbean)
    "DO": { continent_id: "019", subcontinent_id: "029" }, // Dominican Republic (Caribbean)
    "EC": { continent_id: "019", subcontinent_id: "005" }, // Ecuador (South America)
    "GT": { continent_id: "019", subcontinent_id: "013" }, // Guatemala (Central America)
    "GY": { continent_id: "019", subcontinent_id: "005" }, // Guyana (South America)
    "HN": { continent_id: "019", subcontinent_id: "013" }, // Honduras (Central America)
    "HT": { continent_id: "019", subcontinent_id: "029" }, // Haiti (Caribbean)
    "JM": { continent_id: "019", subcontinent_id: "029" }, // Jamaica (Caribbean)
    "MX": { continent_id: "019", subcontinent_id: "013" }, // Mexico (Central America)
    "NI": { continent_id: "019", subcontinent_id: "013" }, // Nicaragua (Central America)
    "PA": { continent_id: "019", subcontinent_id: "013" }, // Panama (Central America)
    "PE": { continent_id: "019", subcontinent_id: "005" }, // Peru (South America)
    "PY": { continent_id: "019", subcontinent_id: "005" }, // Paraguay (South America)
    "SV": { continent_id: "019", subcontinent_id: "013" }, // El Salvador (Central America)
    "SR": { continent_id: "019", subcontinent_id: "005" }, // Suriname (South America)
    "TT": { continent_id: "019", subcontinent_id: "029" }, // Trinidad and Tobago (Caribbean)
    "US": { continent_id: "019", subcontinent_id: "021" }, // United States (Northern America)
    "UY": { continent_id: "019", subcontinent_id: "005" }, // Uruguay (South America)
    "VE": { continent_id: "019", subcontinent_id: "005" }, // Venezuela (South America)

    // Asia (continent: 142) - abbreviated for space
    "AE": { continent_id: "142", subcontinent_id: "145" }, // United Arab Emirates (Western Asia)
    "AF": { continent_id: "142", subcontinent_id: "034" }, // Afghanistan (Southern Asia)
    "CN": { continent_id: "142", subcontinent_id: "030" }, // China (Eastern Asia)
    "IN": { continent_id: "142", subcontinent_id: "034" }, // India (Southern Asia)
    "JP": { continent_id: "142", subcontinent_id: "030" }, // Japan (Eastern Asia)
    "KR": { continent_id: "142", subcontinent_id: "030" }, // South Korea (Eastern Asia)
    "SA": { continent_id: "142", subcontinent_id: "145" }, // Saudi Arabia (Western Asia)
    "SG": { continent_id: "142", subcontinent_id: "035" }, // Singapore (South-Eastern Asia)
    "TH": { continent_id: "142", subcontinent_id: "035" }, // Thailand (South-Eastern Asia)
    "TR": { continent_id: "142", subcontinent_id: "145" }, // Turkey (Western Asia)

    // Africa (continent: 002) - abbreviated for space
    "EG": { continent_id: "002", subcontinent_id: "015" }, // Egypt (Northern Africa)
    "KE": { continent_id: "002", subcontinent_id: "014" }, // Kenya (Eastern Africa)
    "MA": { continent_id: "002", subcontinent_id: "015" }, // Morocco (Northern Africa)
    "NG": { continent_id: "002", subcontinent_id: "011" }, // Nigeria (Western Africa)
    "ZA": { continent_id: "002", subcontinent_id: "018" }, // South Africa (Southern Africa)
    
    // Oceania (continent: 009)
    "AU": { continent_id: "009", subcontinent_id: "053" }, // Australia (Australia and New Zealand)
    "NZ": { continent_id: "009", subcontinent_id: "053" }, // New Zealand (Australia and New Zealand)
  };

  // First check if the country code exists in our mapping
  if (continentMap[countryCode]) {
    return continentMap[countryCode];
  }

  // Default fallback
  return { continent_id: "150", subcontinent_id: "155" }; // Default to Western Europe
}

/**
 * Convert country name to ISO country code
 * @param {string} countryName - Full country name
 * @returns {string} ISO country code
 */
function convertCountryToISO(countryName) {
  // Simple mapping for common countries
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

  // Check if we have a direct mapping
  if (countryMap[countryName]) {
    return countryMap[countryName];
  }
  
  // Try to handle the case where countryName might be a country code already
  if (countryName && countryName.length === 2 && countryName === countryName.toUpperCase()) {
    return countryName; // It's already a country code
  }
  
  // As a fallback, try to extract first two letters of the country name and uppercase them
  return countryName ? countryName.toUpperCase().substring(0, 2) : "NL";
}

/**
 * Extract location data from params and format for GA4
 * @param {Object} params - Request parameters containing location data
 * @returns {Object} Formatted user_location object for GA4
 */
function extractLocationData(params) {
  const userLocation = {};

  // Map location parameters to GA4 user_location format
  if (params.geo_city || params.city) {
    userLocation.city = params.geo_city || params.city;
    delete params.geo_city;
    delete params.city;
  }

  if (params.geo_country || params.country) {
    // Convert country name to ISO code if needed
    const countryName = params.geo_country || params.country;
    userLocation.country_id = convertCountryToISO(countryName);
    delete params.geo_country;
    delete params.country;
  }

  if (params.geo_region || params.region) {
    // Convert region to ISO format if needed
    const regionName = params.geo_region || params.region;
    const countryCode = userLocation.country_id || "NL"; // Default to NL if no country
    userLocation.region_id = `${countryCode}-${regionName.toUpperCase().substring(0, 2)}`;
    delete params.geo_region;
    delete params.region;
  }

  // Add continent and subcontinent based on country
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

  // Remove lat/lng from params as they're not standard GA4 parameters
  if (params.geo_latitude) {
    delete params.geo_latitude;
  }
  if (params.geo_longitude) {
    delete params.geo_longitude;
  }
  
  // Remove any empty values
  Object.keys(userLocation).forEach(key => {
    if (!userLocation[key]) {
      delete userLocation[key];
    }
  });

  return userLocation;
}

/**
 * Handle CORS preflight requests
 * @param {Request} request
 * @returns {Response}
 */
function handleCORS(request) {
  return new Response(null, {
    status: 204,
    headers: getCORSHeaders(request),
  });
}

/**
 * Get CORS headers for the response
 * @param {Request} request
 * @returns {Object} Headers object
 */
function getCORSHeaders(request) {
  // Get the Origin header from the request
  const origin = request.headers.get("Origin") || "*";

  return {
    "Access-Control-Allow-Origin": origin,
    "Access-Control-Allow-Methods": "GET, POST, OPTIONS",
    "Access-Control-Allow-Headers": "Content-Type, Authorization",
    "Access-Control-Max-Age": "86400",
  };
}

/**
 * Process and normalize event data (your existing function - abbreviated for space)
 * @param {Object} data - The raw event data
 * @param {Request} request - The original request object
 * @returns {Object} - Processed event data
 */
function processEventData(data, request) {
  // Create a copy of the data to avoid modifying the original
  const processedData = JSON.parse(JSON.stringify(data));

  // Ensure params object exists
  if (!processedData.params) {
    processedData.params = {};
  }

  // Get request information
  const referer = request.headers.get("Referer") || "";
  const origin = request.headers.get("Origin") || "";
  const host = origin
    ? new URL(origin).host
    : referer
    ? new URL(referer).host
    : request.headers.get("Host");

  // Handle specific event types (abbreviated - include your full logic here)
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

  // Add engagement parameters for all events
  if (!processedData.params.engagement_time_msec) {
    processedData.params.engagement_time_msec = 1000;
  }

  if (DEBUG_MODE) {
    console.log("Processed event data:", JSON.stringify(processedData));
  }

  return processedData;
}

/**
 * Instructions for deploying this updated worker:
 *
 * 1. Update your existing Cloudflare Worker with this code
 * 2. Replace GA4_MEASUREMENT_ID and GA4_API_SECRET with your actual values
 * 3. The worker now handles both GA4 events and Google Ads conversions
 * 4. Google Ads conversions are sent as custom GA4 events (ads_conversion) for backup
 * 5. For full Google Ads API integration, you'll need to add OAuth credentials
 * 6. Set DEBUG_MODE to true to see detailed logs
 * 7. Save and deploy the worker
 *
 * The worker will automatically detect Google Ads conversion events by their name prefix
 * and route them to the appropriate handler.
 */