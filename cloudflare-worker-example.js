/**
 * GA4 Server-Side Tagging Cloudflare Worker
 *
 * This worker receives events from the WordPress plugin and forwards them to GA4.
 *
 * @version 1.2.0
 */

// Configuration
var DEBUG_MODE = false; // Set to true to enable debug logging
const GA4_ENDPOINT = "https://www.google-analytics.com/mp/collect";
const GA4_MEASUREMENT_ID = "G-xx"; // Your GA4 Measurement ID
const GA4_API_SECRET = "xx"; // Your GA4 API Secret

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

    // Process the event data
    const processedData = processEventData(payload, request);

    if (processedData.params.debug_mode) {
      DEBUG_MODE = true;
    }

    // Log the incoming event data
    if (DEBUG_MODE) {
      console.log("Received event:", JSON.stringify(payload));
    }

    // Log the incoming event data
    if (DEBUG_MODE) {
      console.log("Received event:", JSON.stringify(payload));
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

    if (!processedData.params || !processedData.params.client_id) {
      return new Response(
        JSON.stringify({ error: "Missing client_id parameter" }),
        {
          status: 400,
          headers: {
            "Content-Type": "application/json",
            ...getCORSHeaders(request),
          },
        }
      );
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
 * Extract location data from params and convert to GA4 format
 * @param {Object} params - Event parameters
 * @returns {Object} Location data in GA4 format
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
    const countryCode = userLocation.country_id || 'NL'; // Default to NL if no country
    userLocation.region_id = convertRegionToISO(regionName, countryCode);
    delete params.geo_region;
    delete params.region;
  }

  // Add continent and subcontinent based on country
  if (userLocation.country_id) {
    const continentInfo = getContinentInfo(userLocation.country_id);
    if (continentInfo.continent_id) {
      userLocation.continent_id = continentInfo.continent_id;
    }
    if (continentInfo.subcontinent_id) {
      userLocation.subcontinent_id = continentInfo.subcontinent_id;
    }
  }

  // Remove lat/lng from params as they're not standard GA4 parameters
  if (params.geo_latitude) {
    delete params.geo_latitude;
  }
  if (params.geo_longitude) {
    delete params.geo_longitude;
  }

  return userLocation;
}

/**
 * Get continent and subcontinent info based on country code
 * @param {string} countryCode - ISO country code
 * @returns {Object} Continent and subcontinent information
 */
function getContinentInfo(countryCode) {
  // Continental and subcontinental mapping based on UN geoscheme
  const continentMap = {
    // Europe (continent: 150)
    'NL': { continent_id: '150', subcontinent_id: '155' }, // Western Europe
    'DE': { continent_id: '150', subcontinent_id: '155' }, // Western Europe
    'FR': { continent_id: '150', subcontinent_id: '155' }, // Western Europe
    'ES': { continent_id: '150', subcontinent_id: '039' }, // Southern Europe
    'IT': { continent_id: '150', subcontinent_id: '039' }, // Southern Europe
    'BE': { continent_id: '150', subcontinent_id: '155' }, // Western Europe
    'GB': { continent_id: '150', subcontinent_id: '154' }, // Northern Europe
    'IE': { continent_id: '150', subcontinent_id: '154' }, // Northern Europe
    'SE': { continent_id: '150', subcontinent_id: '154' }, // Northern Europe
    'NO': { continent_id: '150', subcontinent_id: '154' }, // Northern Europe
    'DK': { continent_id: '150', subcontinent_id: '154' }, // Northern Europe
    'FI': { continent_id: '150', subcontinent_id: '154' }, // Northern Europe
    'PL': { continent_id: '150', subcontinent_id: '151' }, // Eastern Europe
    'RU': { continent_id: '150', subcontinent_id: '151' }, // Eastern Europe
    'AT': { continent_id: '150', subcontinent_id: '155' }, // Western Europe
    'CH': { continent_id: '150', subcontinent_id: '155' }, // Western Europe
    'PT': { continent_id: '150', subcontinent_id: '039' }, // Southern Europe
    'GR': { continent_id: '150', subcontinent_id: '039' }, // Southern Europe
    'TR': { continent_id: '142', subcontinent_id: '145' }, // Western Asia
    'LU': { continent_id: '150', subcontinent_id: '155' }, // Western Europe

    // Americas (continent: 019)
    'US': { continent_id: '019', subcontinent_id: '021' }, // Northern America
    'CA': { continent_id: '019', subcontinent_id: '021' }, // Northern America
    'MX': { continent_id: '019', subcontinent_id: '013' }, // Central America
    'BR': { continent_id: '019', subcontinent_id: '005' }, // South America
    'AR': { continent_id: '019', subcontinent_id: '005' }, // South America

    // Asia (continent: 142)
    'CN': { continent_id: '142', subcontinent_id: '030' }, // Eastern Asia
    'JP': { continent_id: '142', subcontinent_id: '030' }, // Eastern Asia
    'IN': { continent_id: '142', subcontinent_id: '034' }, // Southern Asia

    // Africa (continent: 002)
    'ZA': { continent_id: '002', subcontinent_id: '018' }, // Southern Africa

    // Oceania (continent: 009)
    'AU': { continent_id: '009', subcontinent_id: '053' }, // Australia and New Zealand
  };

  return continentMap[countryCode] || { continent_id: '', subcontinent_id: '' };
}

/**
 * Convert country name to ISO country code
 * @param {string} countryName - Full country name
 * @returns {string} ISO country code
 */
function convertCountryToISO(countryName) {
  // Simple mapping for common countries
  const countryMap = {
    'The Netherlands': 'NL',
    'Netherlands': 'NL',
    'United States': 'US',
    'United Kingdom': 'GB',
    'Germany': 'DE',
    'France': 'FR',
    'Spain': 'ES',
    'Italy': 'IT',
    'Belgium': 'BE',
    'Canada': 'CA',
    'Australia': 'AU',
    'Japan': 'JP',
    'China': 'CN',
    'India': 'IN',
    'Brazil': 'BR',
    'Mexico': 'MX',
    'Argentina': 'AR',
    'South Africa': 'ZA',
    'Russia': 'RU',
    'Poland': 'PL',
    'Sweden': 'SE',
    'Norway': 'NO',
    'Denmark': 'DK',
    'Finland': 'FI',
    'Ireland': 'IE',
    'Portugal': 'PT',
    'Greece': 'GR',
    'Turkey': 'TR',
    'Austria': 'AT',
    'Switzerland': 'CH',
    'Luxembourg': 'LU'
  };

  return countryMap[countryName] || countryName.toUpperCase().substring(0, 2);
}

/**
 * Convert region name to ISO region code
 * @param {string} regionName - Full region name
 * @param {string} countryCode - ISO country code
 * @returns {string} ISO region code
 */
function convertRegionToISO(regionName, countryCode) {
  // Netherlands regions mapping
  if (countryCode === 'NL') {
    const nlRegions = {
      'North Holland': 'NL-NH',
      'South Holland': 'NL-ZH',
      'North Brabant': 'NL-NB',
      'South Brabant': 'NL-NB',
      'Utrecht': 'NL-UT',
      'Gelderland': 'NL-GE',
      'Overijssel': 'NL-OV',
      'Limburg': 'NL-LI',
      'Groningen': 'NL-GR',
      'Friesland': 'NL-FR',
      'Drenthe': 'NL-DR',
      'Flevoland': 'NL-FL',
      'Zeeland': 'NL-ZE'
    };
    return nlRegions[regionName] || `${countryCode}-${regionName.toUpperCase().substring(0, 2)}`;
  }

  // For other countries, create a simple format
  return `${countryCode}-${regionName.toUpperCase().substring(0, 2)}`;
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
 * Process and normalize event data
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

  // Handle specific event types
  switch (processedData.name) {
    case "page_view":
      // Ensure required page_view parameters
      if (!processedData.params.page_title) {
        processedData.params.page_title = "Unknown Page";
      }

      // Ensure page_location exists
      if (!processedData.params.page_location) {
        if (processedData.params.page_path) {
          // Try to construct page_location from page_path
          processedData.params.page_location = `https://${host}${processedData.params.page_path}`;
        } else if (referer) {
          // Use referer as fallback
          processedData.params.page_location = referer;
        }
      }

      // Ensure page_path exists
      if (
        !processedData.params.page_path &&
        processedData.params.page_location
      ) {
        try {
          const url = new URL(processedData.params.page_location);
          processedData.params.page_path = url.pathname;
        } catch (e) {
          processedData.params.page_path = "/";
        }
      }

      // Log detailed page_view processing for debugging
      if (DEBUG_MODE) {
        console.log("Processing page_view event:", {
          original: data,
          referer: referer,
          origin: origin,
          host: host,
          processed: processedData,
        });
      }
      break;

    case "add_to_cart":
      // Ensure required add_to_cart parameters
      if (!processedData.params.currency) {
        processedData.params.currency = "EUR"; // Default currency
      }

      // Ensure items array exists and has required fields
      if (
        processedData.params.items &&
        Array.isArray(processedData.params.items)
      ) {
        processedData.params.items = processedData.params.items.map((item) => {
          // Ensure required item fields
          if (!item.item_id) {
            item.item_id = "unknown_id";
          }
          if (!item.item_name) {
            item.item_name = "Unknown Product";
          }
          if (!item.price) {
            item.price = 0;
          }
          if (!item.quantity) {
            item.quantity = 1;
          }
          return item;
        });

        // Calculate value if not provided
        if (
          !processedData.params.value &&
          processedData.params.items.length > 0
        ) {
          processedData.params.value = processedData.params.items.reduce(
            (total, item) => total + item.price * (item.quantity || 1),
            0
          );
        }
      } else {
        // Create empty items array if missing
        processedData.params.items = [];
      }
      break;

    case "view_item":
      // Ensure required view_item parameters
      if (!processedData.params.currency) {
        processedData.params.currency = "EUR"; // Default currency
      }

      // Ensure items array exists and has required fields
      if (
        processedData.params.items &&
        Array.isArray(processedData.params.items)
      ) {
        processedData.params.items = processedData.params.items.map((item) => {
          // Ensure required item fields
          if (!item.item_id) {
            item.item_id = "unknown_id";
          }
          if (!item.item_name) {
            item.item_name = "Unknown Product";
          }
          if (!item.price) {
            item.price = 0;
          }
          return item;
        });

        // Calculate value if not provided
        if (
          !processedData.params.value &&
          processedData.params.items.length > 0
        ) {
          processedData.params.value = processedData.params.items.reduce(
            (total, item) => total + (parseFloat(item.price) || 0),
            0
          );
        }

        // Log detailed view_item processing for debugging
        if (DEBUG_MODE) {
          console.log("Processing view_item event:", {
            items: processedData.params.items,
            value: processedData.params.value,
          });
        }
      } else {
        // Create empty items array if missing
        processedData.params.items = [];
      }
      break;

    case "view_item_list":
      // Ensure required view_item_list parameters
      if (!processedData.params.item_list_id) {
        processedData.params.item_list_id = "default_list";
      }

      if (!processedData.params.item_list_name) {
        processedData.params.item_list_name = "Default List";
      }

      // Ensure items array exists and has required fields
      if (
        processedData.params.items &&
        Array.isArray(processedData.params.items)
      ) {
        processedData.params.items = processedData.params.items.map(
          (item, index) => {
            // Ensure required item fields
            if (!item.item_id) {
              item.item_id = "unknown_id_" + index;
            }
            if (!item.item_name) {
              item.item_name = "Unknown Product " + index;
            }
            if (!item.index) {
              item.index = index;
            }
            return item;
          }
        );

        // Log detailed view_item_list processing for debugging
        if (DEBUG_MODE) {
          console.log("Processing view_item_list event:", {
            item_list_id: processedData.params.item_list_id,
            item_list_name: processedData.params.item_list_name,
            items: processedData.params.items,
          });
        }
      } else {
        // Create empty items array if missing
        processedData.params.items = [];
      }
      break;

    case "select_item":
      // Ensure required select_item parameters
      if (!processedData.params.item_list_id) {
        processedData.params.item_list_id = "default_list";
      }

      if (!processedData.params.item_list_name) {
        processedData.params.item_list_name = "Default List";
      }

      // Ensure items array exists and has required fields
      if (
        processedData.params.items &&
        Array.isArray(processedData.params.items)
      ) {
        processedData.params.items = processedData.params.items.map(
          (item, index) => {
            // Ensure required item fields
            if (!item.item_id) {
              item.item_id = "unknown_id";
            }
            if (!item.item_name) {
              item.item_name = "Unknown Product";
            }
            if (!item.index) {
              item.index = index;
            }
            return item;
          }
        );
      } else {
        // Create empty items array if missing
        processedData.params.items = [];
      }
      break;

    case "remove_from_cart":
      // Ensure required remove_from_cart parameters
      if (!processedData.params.currency) {
        processedData.params.currency = "EUR"; // Default currency
      }

      // Ensure items array exists and has required fields
      if (
        processedData.params.items &&
        Array.isArray(processedData.params.items)
      ) {
        processedData.params.items = processedData.params.items.map((item) => {
          // Ensure required item fields
          if (!item.item_id) {
            item.item_id = "unknown_id";
          }
          if (!item.item_name) {
            item.item_name = "Unknown Product";
          }
          if (!item.price) {
            item.price = 0;
          }
          if (!item.quantity) {
            item.quantity = 1;
          }
          return item;
        });

        // Calculate value if not provided
        if (
          !processedData.params.value &&
          processedData.params.items.length > 0
        ) {
          processedData.params.value = processedData.params.items.reduce(
            (total, item) => total + item.price * (item.quantity || 1),
            0
          );
        }
      } else {
        // Create empty items array if missing
        processedData.params.items = [];
      }
      break;

    case "view_cart":
      // Ensure required view_cart parameters
      if (!processedData.params.currency) {
        processedData.params.currency = "EUR"; // Default currency
      }

      // Ensure items array exists and has required fields
      if (
        processedData.params.items &&
        Array.isArray(processedData.params.items)
      ) {
        processedData.params.items = processedData.params.items.map((item) => {
          // Ensure required item fields
          if (!item.item_id) {
            item.item_id = "unknown_id";
          }
          if (!item.item_name) {
            item.item_name = "Unknown Product";
          }
          if (!item.price) {
            item.price = 0;
          }
          if (!item.quantity) {
            item.quantity = 1;
          }
          return item;
        });

        // Calculate value if not provided
        if (
          !processedData.params.value &&
          processedData.params.items.length > 0
        ) {
          processedData.params.value = processedData.params.items.reduce(
            (total, item) => total + item.price * (item.quantity || 1),
            0
          );
        }
      } else {
        // Create empty items array if missing
        processedData.params.items = [];
      }
      break;

    case "begin_checkout":
      // Ensure required begin_checkout parameters
      if (!processedData.params.currency) {
        processedData.params.currency = "EUR"; // Default currency
      }

      // Ensure items array exists and has required fields
      if (
        processedData.params.items &&
        Array.isArray(processedData.params.items)
      ) {
        processedData.params.items = processedData.params.items.map((item) => {
          // Ensure required item fields
          if (!item.item_id) {
            item.item_id = "unknown_id";
          }
          if (!item.item_name) {
            item.item_name = "Unknown Product";
          }
          if (!item.price) {
            item.price = 0;
          }
          if (!item.quantity) {
            item.quantity = 1;
          }
          return item;
        });

        // Calculate value if not provided
        if (
          !processedData.params.value &&
          processedData.params.items.length > 0
        ) {
          processedData.params.value = processedData.params.items.reduce(
            (total, item) => total + item.price * (item.quantity || 1),
            0
          );
        }
      } else {
        // Create empty items array if missing
        processedData.params.items = [];
        processedData.params.value = 0; // Default value
      }
      break;

    case "add_shipping_info":
      // Ensure required add_shipping_info parameters
      if (!processedData.params.currency) {
        processedData.params.currency = "EUR"; // Default currency
      }

      if (!processedData.params.shipping_tier) {
        processedData.params.shipping_tier = "Unknown";
      }

      // Ensure items array exists and has required fields
      if (
        processedData.params.items &&
        Array.isArray(processedData.params.items)
      ) {
        processedData.params.items = processedData.params.items.map((item) => {
          // Ensure required item fields
          if (!item.item_id) {
            item.item_id = "unknown_id";
          }
          if (!item.item_name) {
            item.item_name = "Unknown Product";
          }
          if (!item.price) {
            item.price = 0;
          }
          if (!item.quantity) {
            item.quantity = 1;
          }
          return item;
        });

        // Calculate value if not provided
        if (
          !processedData.params.value &&
          processedData.params.items.length > 0
        ) {
          processedData.params.value = processedData.params.items.reduce(
            (total, item) => total + item.price * (item.quantity || 1),
            0
          );
        }
      } else {
        // Create empty items array if missing
        processedData.params.items = [];
        processedData.params.value = 0; // Default value
      }
      break;

    case "add_payment_info":
      // Ensure required add_payment_info parameters
      if (!processedData.params.currency) {
        processedData.params.currency = "EUR"; // Default currency
      }

      if (!processedData.params.payment_type) {
        processedData.params.payment_type = "Unknown";
      }

      // Ensure items array exists and has required fields
      if (
        processedData.params.items &&
        Array.isArray(processedData.params.items)
      ) {
        processedData.params.items = processedData.params.items.map((item) => {
          // Ensure required item fields
          if (!item.item_id) {
            item.item_id = "unknown_id";
          }
          if (!item.item_name) {
            item.item_name = "Unknown Product";
          }
          if (!item.price) {
            item.price = 0;
          }
          if (!item.quantity) {
            item.quantity = 1;
          }
          return item;
        });

        // Calculate value if not provided
        if (
          !processedData.params.value &&
          processedData.params.items.length > 0
        ) {
          processedData.params.value = processedData.params.items.reduce(
            (total, item) => total + item.price * (item.quantity || 1),
            0
          );
        }
      } else {
        // Create empty items array if missing
        processedData.params.items = [];
        processedData.params.value = 0; // Default value
      }
      break;

    case "purchase":
      // Ensure transaction_id exists
      if (!processedData.params.transaction_id) {
        processedData.params.transaction_id = "unknown_" + Date.now();
      }
      break;
  }

  // Add engagement parameters for all events only when not already assigned
  if (!processedData.params.engagement_time_msec) {
    processedData.params.engagement_time_msec = 1000;
  }
  // Log the processed event data
  if (DEBUG_MODE) {
    console.log("Processed event data:", JSON.stringify(processedData));
  }

  return processedData;
}

/**
 * Instructions for deploying this worker:
 *
 * 1. Create a Cloudflare Worker in your Cloudflare dashboard
 * 2. Replace the GA4_MEASUREMENT_ID and GA4_API_SECRET with your actual values
 * 3. Copy and paste this code into the worker editor
 * 4. Set DEBUG_MODE to true if you want to see detailed logs in the Cloudflare dashboard
 * 5. Save and deploy the worker
 * 6. Copy the worker URL and paste it in the plugin settings
 *
 * Example of a page_view event payload:
 * {
 *   "name": "page_view",
 *   "params": {
 *     "page_title": "Home",
 *     "page_location": "https://example.com/",
 *     "page_path": "/",
 *     "client_id": "123456789.987654321"
 *   }
 * }
 */