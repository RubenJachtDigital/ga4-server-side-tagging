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
          error: `Too many parameters: ${payloadParamsCount}. GA4 only allows a maximum of 25 parameters per event. - ${processedData.params}`,
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
 * Get continent and subcontinent info based on country code
 * @param {string} countryCode - ISO country code
 * @returns {Object} Continent and subcontinent information
 */
/**
 * Get continent and subcontinent information based on country code
 * @param {string} countryCode - ISO 3166-1 alpha-2 country code
 * @returns {Object} Object containing continent_id and subcontinent_id
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

    // Asia (continent: 142)
    "AE": { continent_id: "142", subcontinent_id: "145" }, // United Arab Emirates (Western Asia)
    "AF": { continent_id: "142", subcontinent_id: "034" }, // Afghanistan (Southern Asia)
    "AM": { continent_id: "142", subcontinent_id: "145" }, // Armenia (Western Asia)
    "AZ": { continent_id: "142", subcontinent_id: "145" }, // Azerbaijan (Western Asia)
    "BD": { continent_id: "142", subcontinent_id: "034" }, // Bangladesh (Southern Asia)
    "BH": { continent_id: "142", subcontinent_id: "145" }, // Bahrain (Western Asia)
    "BN": { continent_id: "142", subcontinent_id: "035" }, // Brunei (South-Eastern Asia)
    "BT": { continent_id: "142", subcontinent_id: "034" }, // Bhutan (Southern Asia)
    "CN": { continent_id: "142", subcontinent_id: "030" }, // China (Eastern Asia)
    "GE": { continent_id: "142", subcontinent_id: "145" }, // Georgia (Western Asia)
    "ID": { continent_id: "142", subcontinent_id: "035" }, // Indonesia (South-Eastern Asia)
    "IL": { continent_id: "142", subcontinent_id: "145" }, // Israel (Western Asia)
    "IN": { continent_id: "142", subcontinent_id: "034" }, // India (Southern Asia)
    "IQ": { continent_id: "142", subcontinent_id: "145" }, // Iraq (Western Asia)
    "IR": { continent_id: "142", subcontinent_id: "034" }, // Iran (Southern Asia)
    "JO": { continent_id: "142", subcontinent_id: "145" }, // Jordan (Western Asia)
    "JP": { continent_id: "142", subcontinent_id: "030" }, // Japan (Eastern Asia)
    "KG": { continent_id: "142", subcontinent_id: "143" }, // Kyrgyzstan (Central Asia)
    "KH": { continent_id: "142", subcontinent_id: "035" }, // Cambodia (South-Eastern Asia)
    "KP": { continent_id: "142", subcontinent_id: "030" }, // North Korea (Eastern Asia)
    "KR": { continent_id: "142", subcontinent_id: "030" }, // South Korea (Eastern Asia)
    "KW": { continent_id: "142", subcontinent_id: "145" }, // Kuwait (Western Asia)
    "KZ": { continent_id: "142", subcontinent_id: "143" }, // Kazakhstan (Central Asia)
    "LA": { continent_id: "142", subcontinent_id: "035" }, // Laos (South-Eastern Asia)
    "LB": { continent_id: "142", subcontinent_id: "145" }, // Lebanon (Western Asia)
    "LK": { continent_id: "142", subcontinent_id: "034" }, // Sri Lanka (Southern Asia)
    "MM": { continent_id: "142", subcontinent_id: "035" }, // Myanmar (South-Eastern Asia)
    "MN": { continent_id: "142", subcontinent_id: "030" }, // Mongolia (Eastern Asia)
    "MV": { continent_id: "142", subcontinent_id: "034" }, // Maldives (Southern Asia)
    "MY": { continent_id: "142", subcontinent_id: "035" }, // Malaysia (South-Eastern Asia)
    "NP": { continent_id: "142", subcontinent_id: "034" }, // Nepal (Southern Asia)
    "OM": { continent_id: "142", subcontinent_id: "145" }, // Oman (Western Asia)
    "PH": { continent_id: "142", subcontinent_id: "035" }, // Philippines (South-Eastern Asia)
    "PK": { continent_id: "142", subcontinent_id: "034" }, // Pakistan (Southern Asia)
    "PS": { continent_id: "142", subcontinent_id: "145" }, // Palestine (Western Asia)
    "QA": { continent_id: "142", subcontinent_id: "145" }, // Qatar (Western Asia)
    "SA": { continent_id: "142", subcontinent_id: "145" }, // Saudi Arabia (Western Asia)
    "SG": { continent_id: "142", subcontinent_id: "035" }, // Singapore (South-Eastern Asia)
    "SY": { continent_id: "142", subcontinent_id: "145" }, // Syria (Western Asia)
    "TH": { continent_id: "142", subcontinent_id: "035" }, // Thailand (South-Eastern Asia)
    "TJ": { continent_id: "142", subcontinent_id: "143" }, // Tajikistan (Central Asia)
    "TM": { continent_id: "142", subcontinent_id: "143" }, // Turkmenistan (Central Asia)
    "TR": { continent_id: "142", subcontinent_id: "145" }, // Turkey (Western Asia)
    "TW": { continent_id: "142", subcontinent_id: "030" }, // Taiwan (Eastern Asia)
    "UZ": { continent_id: "142", subcontinent_id: "143" }, // Uzbekistan (Central Asia)
    "VN": { continent_id: "142", subcontinent_id: "035" }, // Vietnam (South-Eastern Asia)
    "YE": { continent_id: "142", subcontinent_id: "145" }, // Yemen (Western Asia)

    // Africa (continent: 002)
    "AO": { continent_id: "002", subcontinent_id: "017" }, // Angola (Middle Africa)
    "BF": { continent_id: "002", subcontinent_id: "011" }, // Burkina Faso (Western Africa)
    "BI": { continent_id: "002", subcontinent_id: "014" }, // Burundi (Eastern Africa)
    "BJ": { continent_id: "002", subcontinent_id: "011" }, // Benin (Western Africa)
    "BW": { continent_id: "002", subcontinent_id: "018" }, // Botswana (Southern Africa)
    "CD": { continent_id: "002", subcontinent_id: "017" }, // DR Congo (Middle Africa)
    "CF": { continent_id: "002", subcontinent_id: "017" }, // Central African Republic (Middle Africa)
    "CG": { continent_id: "002", subcontinent_id: "017" }, // Republic of the Congo (Middle Africa)
    "CI": { continent_id: "002", subcontinent_id: "011" }, // Côte d'Ivoire (Western Africa)
    "CM": { continent_id: "002", subcontinent_id: "017" }, // Cameroon (Middle Africa)
    "CV": { continent_id: "002", subcontinent_id: "011" }, // Cape Verde (Western Africa)
    "DJ": { continent_id: "002", subcontinent_id: "014" }, // Djibouti (Eastern Africa)
    "DZ": { continent_id: "002", subcontinent_id: "015" }, // Algeria (Northern Africa)
    "EG": { continent_id: "002", subcontinent_id: "015" }, // Egypt (Northern Africa)
    "ER": { continent_id: "002", subcontinent_id: "014" }, // Eritrea (Eastern Africa)
    "ET": { continent_id: "002", subcontinent_id: "014" }, // Ethiopia (Eastern Africa)
    "GA": { continent_id: "002", subcontinent_id: "017" }, // Gabon (Middle Africa)
    "GH": { continent_id: "002", subcontinent_id: "011" }, // Ghana (Western Africa)
    "GM": { continent_id: "002", subcontinent_id: "011" }, // Gambia (Western Africa)
    "GN": { continent_id: "002", subcontinent_id: "011" }, // Guinea (Western Africa)
    "GQ": { continent_id: "002", subcontinent_id: "017" }, // Equatorial Guinea (Middle Africa)
    "GW": { continent_id: "002", subcontinent_id: "011" }, // Guinea-Bissau (Western Africa)
    "KE": { continent_id: "002", subcontinent_id: "014" }, // Kenya (Eastern Africa)
    "LR": { continent_id: "002", subcontinent_id: "011" }, // Liberia (Western Africa)
    "LS": { continent_id: "002", subcontinent_id: "018" }, // Lesotho (Southern Africa)
    "LY": { continent_id: "002", subcontinent_id: "015" }, // Libya (Northern Africa)
    "MA": { continent_id: "002", subcontinent_id: "015" }, // Morocco (Northern Africa)
    "MG": { continent_id: "002", subcontinent_id: "014" }, // Madagascar (Eastern Africa)
    "ML": { continent_id: "002", subcontinent_id: "011" }, // Mali (Western Africa)
    "MR": { continent_id: "002", subcontinent_id: "011" }, // Mauritania (Western Africa)
    "MU": { continent_id: "002", subcontinent_id: "014" }, // Mauritius (Eastern Africa)
    "MW": { continent_id: "002", subcontinent_id: "014" }, // Malawi (Eastern Africa)
    "MZ": { continent_id: "002", subcontinent_id: "014" }, // Mozambique (Eastern Africa)
    "NA": { continent_id: "002", subcontinent_id: "018" }, // Namibia (Southern Africa)
    "NE": { continent_id: "002", subcontinent_id: "011" }, // Niger (Western Africa)
    "NG": { continent_id: "002", subcontinent_id: "011" }, // Nigeria (Western Africa)
    "RW": { continent_id: "002", subcontinent_id: "014" }, // Rwanda (Eastern Africa)
    "SD": { continent_id: "002", subcontinent_id: "015" }, // Sudan (Northern Africa)
    "SL": { continent_id: "002", subcontinent_id: "011" }, // Sierra Leone (Western Africa)
    "SN": { continent_id: "002", subcontinent_id: "011" }, // Senegal (Western Africa)
    "SO": { continent_id: "002", subcontinent_id: "014" }, // Somalia (Eastern Africa)
    "SS": { continent_id: "002", subcontinent_id: "014" }, // South Sudan (Eastern Africa)
    "SZ": { continent_id: "002", subcontinent_id: "018" }, // Eswatini (Southern Africa)
    "TD": { continent_id: "002", subcontinent_id: "017" }, // Chad (Middle Africa)
    "TG": { continent_id: "002", subcontinent_id: "011" }, // Togo (Western Africa)
    "TN": { continent_id: "002", subcontinent_id: "015" }, // Tunisia (Northern Africa)
    "TZ": { continent_id: "002", subcontinent_id: "014" }, // Tanzania (Eastern Africa)
    "UG": { continent_id: "002", subcontinent_id: "014" }, // Uganda (Eastern Africa)
    "ZA": { continent_id: "002", subcontinent_id: "018" }, // South Africa (Southern Africa)
    "ZM": { continent_id: "002", subcontinent_id: "014" }, // Zambia (Eastern Africa)
    "ZW": { continent_id: "002", subcontinent_id: "014" }, // Zimbabwe (Eastern Africa)
    
    // Oceania (continent: 009)
    "AU": { continent_id: "009", subcontinent_id: "053" }, // Australia (Australia and New Zealand)
    "FJ": { continent_id: "009", subcontinent_id: "054" }, // Fiji (Melanesia)
    "FM": { continent_id: "009", subcontinent_id: "057" }, // Micronesia (Micronesia)
    "KI": { continent_id: "009", subcontinent_id: "057" }, // Kiribati (Micronesia)
    "MH": { continent_id: "009", subcontinent_id: "057" }, // Marshall Islands (Micronesia)
    "NR": { continent_id: "009", subcontinent_id: "057" }, // Nauru (Micronesia)
    "NZ": { continent_id: "009", subcontinent_id: "053" }, // New Zealand (Australia and New Zealand)
    "PG": { continent_id: "009", subcontinent_id: "054" }, // Papua New Guinea (Melanesia)
    "PW": { continent_id: "009", subcontinent_id: "057" }, // Palau (Micronesia)
    "SB": { continent_id: "009", subcontinent_id: "054" }, // Solomon Islands (Melanesia)
    "TO": { continent_id: "009", subcontinent_id: "061" }, // Tonga (Polynesia)
    "TV": { continent_id: "009", subcontinent_id: "061" }, // Tuvalu (Polynesia)
    "VU": { continent_id: "009", subcontinent_id: "054" }, // Vanuatu (Melanesia)
    "WS": { continent_id: "009", subcontinent_id: "061" }, // Samoa (Polynesia)
  };

  // First check if the country code exists in our mapping
  if (continentMap[countryCode]) {
    return continentMap[countryCode];
  }

  // If country code is not in our map, make a best guess based on the first letter
  const firstLetter = countryCode.charAt(0);
  
  // Default mapping based on first letter of country code
  const defaultContinentByFirstLetter = {
    "A": { continent_id: "142", subcontinent_id: "" }, // Mostly Asia
    "B": { continent_id: "150", subcontinent_id: "" }, // Mostly Europe
    "C": { continent_id: "019", subcontinent_id: "" }, // Mostly Americas
    "D": { continent_id: "150", subcontinent_id: "" }, // Mostly Europe
    "E": { continent_id: "150", subcontinent_id: "" }, // Mostly Europe
    "F": { continent_id: "150", subcontinent_id: "" }, // Mostly Europe
    "G": { continent_id: "150", subcontinent_id: "" }, // Mostly Europe
    "H": { continent_id: "019", subcontinent_id: "" }, // Mixed
    "I": { continent_id: "142", subcontinent_id: "" }, // Mostly Asia
    "J": { continent_id: "142", subcontinent_id: "" }, // Mostly Asia
    "K": { continent_id: "142", subcontinent_id: "" }, // Mostly Asia
    "L": { continent_id: "150", subcontinent_id: "" }, // Mostly Europe
    "M": { continent_id: "002", subcontinent_id: "" }, // Mixed
    "N": { continent_id: "150", subcontinent_id: "" }, // Mostly Europe
    "O": { continent_id: "142", subcontinent_id: "" }, // Mostly Asia
    "P": { continent_id: "019", subcontinent_id: "" }, // Mixed
    "Q": { continent_id: "142", subcontinent_id: "" }, // Mostly Asia (Qatar)
    "R": { continent_id: "150", subcontinent_id: "" }, // Mostly Europe
    "S": { continent_id: "150", subcontinent_id: "" }, // Mixed
    "T": { continent_id: "142", subcontinent_id: "" }, // Mixed
    "U": { continent_id: "019", subcontinent_id: "" }, // Mostly Americas
    "V": { continent_id: "019", subcontinent_id: "" }, // Mixed
    "W": { continent_id: "009", subcontinent_id: "" }, // Mostly Oceania
    "Y": { continent_id: "142", subcontinent_id: "" }, // Yemen
    "Z": { continent_id: "002", subcontinent_id: "" }, // Mostly Africa
  };

  return defaultContinentByFirstLetter[firstLetter] || { continent_id: "", subcontinent_id: "" };
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
    "U.S.A.": "US",
    "U.S.": "US",
    "United Kingdom": "GB",
    "UK": "GB",
    "Great Britain": "GB",
    "England": "GB",
    "Germany": "DE",
    "Deutschland": "DE",
    "France": "FR",
    "Spain": "ES",
    "España": "ES",
    "Italy": "IT",
    "Italia": "IT",
    "Belgium": "BE",
    "België": "BE",
    "Belgique": "BE",
    "Canada": "CA",
    "Australia": "AU",
    "Japan": "JP",
    "China": "CN",
    "India": "IN",
    "Brazil": "BR",
    "Brasil": "BR",
    "Mexico": "MX",
    "México": "MX",
    "Argentina": "AR",
    "South Africa": "ZA",
    "Russia": "RU",
    "Russian Federation": "RU",
    "Poland": "PL",
    "Polska": "PL",
    "Sweden": "SE",
    "Sverige": "SE",
    "Norway": "NO",
    "Norge": "NO",
    "Denmark": "DK",
    "Danmark": "DK",
    "Finland": "FI",
    "Suomi": "FI",
    "Ireland": "IE",
    "Portugal": "PT",
    "Greece": "GR",
    "Ελλάδα": "GR",
    "Turkey": "TR",
    "Türkiye": "TR",
    "Austria": "AT",
    "Österreich": "AT",
    "Switzerland": "CH",
    "Schweiz": "CH",
    "Suisse": "CH",
    "Luxembourg": "LU",
    "New Zealand": "NZ",
    "South Korea": "KR",
    "Republic of Korea": "KR",
    "Thailand": "TH",
    "Singapore": "SG",
    "Malaysia": "MY",
    "Indonesia": "ID",
    "Vietnam": "VN",
    "Philippines": "PH",
    "United Arab Emirates": "AE",
    "UAE": "AE",
    "Saudi Arabia": "SA",
    "Israel": "IL",
    "Egypt": "EG",
    "Nigeria": "NG",
    "Kenya": "KE",
    "Morocco": "MA",
    "Chile": "CL",
    "Colombia": "CO",
    "Peru": "PE",
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
  return countryName ? countryName.toUpperCase().substring(0, 2) : "";
}

/**
 * Convert region name to ISO region code
 * @param {string} regionName - Full region name
 * @param {string} countryCode - ISO country code
 * @returns {string} ISO region code
 */
function convertRegionToISO(regionName, countryCode) {
  if (!regionName || !countryCode) {
    return "";
  }
  
  // Netherlands regions mapping
  if (countryCode === "NL") {
    const nlRegions = {
      "North Holland": "NL-NH",
      "Noord-Holland": "NL-NH",
      "South Holland": "NL-ZH",
      "Zuid-Holland": "NL-ZH",
      "North Brabant": "NL-NB",
      "Noord-Brabant": "NL-NB",
      "South Brabant": "NL-NB",
      "Utrecht": "NL-UT",
      "Gelderland": "NL-GE",
      "Overijssel": "NL-OV",
      "Limburg": "NL-LI",
      "Groningen": "NL-GR",
      "Friesland": "NL-FR",
      "Fryslân": "NL-FR",
      "Drenthe": "NL-DR",
      "Flevoland": "NL-FL",
      "Zeeland": "NL-ZE",
    };
    return nlRegions[regionName] || `${countryCode}-${regionName.toUpperCase().substring(0, 2)}`;
  }
  
  // US states mapping
  if (countryCode === "US") {
    const usStates = {
      "Alabama": "US-AL",
      "Alaska": "US-AK",
      "Arizona": "US-AZ",
      "Arkansas": "US-AR",
      "California": "US-CA",
      "Colorado": "US-CO",
      "Connecticut": "US-CT",
      "Delaware": "US-DE",
      "Florida": "US-FL",
      "Georgia": "US-GA",
      "Hawaii": "US-HI",
      "Idaho": "US-ID",
      "Illinois": "US-IL",
      "Indiana": "US-IN",
      "Iowa": "US-IA",
      "Kansas": "US-KS",
      "Kentucky": "US-KY",
      "Louisiana": "US-LA",
      "Maine": "US-ME",
      "Maryland": "US-MD",
      "Massachusetts": "US-MA",
      "Michigan": "US-MI",
      "Minnesota": "US-MN",
      "Mississippi": "US-MS",
      "Missouri": "US-MO",
      "Montana": "US-MT",
      "Nebraska": "US-NE",
      "Nevada": "US-NV",
      "New Hampshire": "US-NH",
      "New Jersey": "US-NJ",
      "New Mexico": "US-NM",
      "New York": "US-NY",
      "North Carolina": "US-NC",
      "North Dakota": "US-ND",
      "Ohio": "US-OH",
      "Oklahoma": "US-OK",
      "Oregon": "US-OR",
      "Pennsylvania": "US-PA",
      "Rhode Island": "US-RI",
      "South Carolina": "US-SC",
      "South Dakota": "US-SD",
      "Tennessee": "US-TN",
      "Texas": "US-TX",
      "Utah": "US-UT",
      "Vermont": "US-VT",
      "Virginia": "US-VA",
      "Washington": "US-WA",
      "West Virginia": "US-WV",
      "Wisconsin": "US-WI",
      "Wyoming": "US-WY",
      "District of Columbia": "US-DC",
      "Washington DC": "US-DC",
      "Washington D.C.": "US-DC",
    };
    return usStates[regionName] || `${countryCode}-${regionName.toUpperCase().substring(0, 2)}`;
  }
  
  // UK regions/countries
  if (countryCode === "GB") {
    const ukRegions = {
      "England": "GB-ENG",
      "Scotland": "GB-SCT",
      "Wales": "GB-WLS",
      "Northern Ireland": "GB-NIR",
      "London": "GB-LDN",
      "Greater London": "GB-LDN",
    };
    return ukRegions[regionName] || `${countryCode}-${regionName.toUpperCase().substring(0, 2)}`;
  }
  
  // Default case for other countries
  return `${countryCode}-${regionName.toUpperCase().substring(0, 2)}`;
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
    userLocation.region_id = convertRegionToISO(regionName, countryCode);
    delete params.geo_region;
    delete params.region;
  }

  // Handle continent data from params
  if (params.continent) {
    // Map text continent names to their numeric codes
    const continentNameToCode = {
      "Europe": "150",
      "Americas": "019",
      "Asia": "142", 
      "Africa": "002",
      "Oceania": "009",
      "Antarctica": "010",
      // Also handle common abbreviations
      "EU": "150",
      "NA": "019", // North America
      "SA": "019", // South America (still part of Americas)
      "AS": "142",
      "AF": "002",
      "OC": "009",
      "AN": "010"
    };
    
    // Set the continent_id if it matches a known continent name or code
    if (continentNameToCode[params.continent]) {
      userLocation.continent_id = continentNameToCode[params.continent];
    }
    
    delete params.continent; // Remove from params after processing
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
  
  if(!userLocation.country_id) {
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
