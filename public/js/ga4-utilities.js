/**
 * GA4 Server-Side Tagging Utilities
 * Complete utilities with location management, purchase tracking, and AJAX handling
 *
 * @since 1.0.0
 */

(function (window, $) {
  "use strict";

  // Create global namespace
  window.GA4Utils = window.GA4Utils || {};

  var GA4Utils = {
    
    /**
     * Client ID Management
     */
    clientId: {
      /**
       * Get or generate client ID
       * @returns {string}
       */
      get: function() {
        // Try to get from localStorage if available
        if (window.localStorage) {
          var storedClientId = localStorage.getItem('server_side_ga4_client_id');
          if (storedClientId) {
            return storedClientId;
          }
        }
        return this.generate();
      },

      /**
       * Generate a random client ID and store it
       * @returns {string}
       */
      generate: function() {
        var clientId = Math.round(2147483647 * Math.random()) + '.' + Math.round(Date.now() / 1000);

        // Store in localStorage if available
        if (window.localStorage) {
          localStorage.setItem('server_side_ga4_client_id', clientId);
        }

        return clientId;
      }
    },

    /**
     * Session Management
     */
    session: {
      /**
       * Get or create session information
       * @returns {Object}
       */
      get: function() {
        var sessionId = localStorage.getItem('server_side_ga4_session_id');
        var sessionStart = localStorage.getItem('server_side_ga4_session_start');
        var firstVisit = localStorage.getItem('server_side_ga4_first_visit');
        var sessionCount = parseInt(localStorage.getItem('server_side_ga4_session_count') || '0');
        var now = Date.now();
        var isNew = false;
        var isFirstVisit = false;

        // Check if this is the first visit ever
        if (!firstVisit) {
          localStorage.setItem('server_side_ga4_first_visit', now);
          isFirstVisit = true;
        }

        // If no session or session expired (30 min inactive)
        if (!sessionId || !sessionStart || now - parseInt(sessionStart) > 30 * 60 * 1000) {
          // Clear expired session data
          this.clear();

          // Generate a more robust session ID using timestamp and random values
          sessionId = GA4Utils.helpers.generateUniqueId();
          sessionStart = now;

          // Store session data
          localStorage.setItem('server_side_ga4_session_id', sessionId);
          localStorage.setItem('server_side_ga4_session_start', sessionStart);

          // Increment session count
          sessionCount++;
          localStorage.setItem('server_side_ga4_session_count', sessionCount);

          isNew = true;
        }

        return {
          id: sessionId,
          start: parseInt(sessionStart),
          isNew: isNew,
          isFirstVisit: isFirstVisit,
          sessionCount: sessionCount,
          duration: now - parseInt(sessionStart)
        };
      },

      /**
       * Clear expired session data
       */
      clear: function() {
        // Clear session-specific data but keep user-level data
        localStorage.removeItem('server_side_ga4_session_id');
        localStorage.removeItem('server_side_ga4_session_start');

        // Clear attribution data that's tied to sessions
        localStorage.removeItem('server_side_ga4_last_source');
        localStorage.removeItem('server_side_ga4_last_medium');
        localStorage.removeItem('server_side_ga4_last_campaign');
        localStorage.removeItem('server_side_ga4_last_content');
        localStorage.removeItem('server_side_ga4_last_term');
        localStorage.removeItem('server_side_ga4_last_gclid');

        // Clear purchase tracking data (30 min expiry)
        this._clearExpiredPurchaseTracking();
        
        // Clear expired location data (1 hour expiry)
        this._clearExpiredLocationData();
      },

      /**
       * Clear expired purchase tracking data
       * @private
       */
      _clearExpiredPurchaseTracking: function() {
        if (!window.localStorage) return;

        var keysToRemove = [];
        var now = Date.now();
        var thirtyMinutes = 30 * 60 * 1000; // 30 minutes in milliseconds

        // Loop through all localStorage keys to find purchase tracking entries
        for (var i = 0; i < localStorage.length; i++) {
          var key = localStorage.key(i);
          
          if (key && key.startsWith('purchase_tracked_')) {
            try {
              var data = JSON.parse(localStorage.getItem(key) || '{}');
              
              // Check if the entry has expired (older than 30 minutes)
              if (data.timestamp && (now - data.timestamp > thirtyMinutes)) {
                keysToRemove.push(key);
              }
            } catch (e) {
              // If we can't parse the data, remove it
              keysToRemove.push(key);
            }
          }
        }

        // Remove expired entries
        keysToRemove.forEach(function(key) {
          localStorage.removeItem(key);
          GA4Utils.helpers.log('Removed expired purchase tracking: ' + key, null, {debugMode: true}, '[Purchase Tracking Cleanup]');
        });

        if (keysToRemove.length > 0) {
          GA4Utils.helpers.log('Cleaned up ' + keysToRemove.length + ' expired purchase tracking entries', null, {debugMode: true}, '[Purchase Tracking Cleanup]');
        }
      },

      /**
       * Clear expired location data
       * @private
       */
      _clearExpiredLocationData: function() {
        if (!window.localStorage) return;

        var locationKey = 'user_location_data';
        var locationData = localStorage.getItem(locationKey);
        
        if (locationData) {
          try {
            var data = JSON.parse(locationData);
            var now = Date.now();
            var oneHour = 60 * 60 * 1000; // 1 hour in milliseconds
            
            // Check if the location data has expired (older than 1 hour)
            if (data.timestamp && (now - data.timestamp > oneHour)) {
              localStorage.removeItem(locationKey);
              GA4Utils.helpers.log('Removed expired location data', null, {debugMode: true}, '[Location Data Cleanup]');
            }
          } catch (e) {
            // If we can't parse the data, remove it
            localStorage.removeItem(locationKey);
            GA4Utils.helpers.log('Removed invalid location data', null, {debugMode: true}, '[Location Data Cleanup]');
          }
        }
      },

      /**
       * Get session information formatted for tracking
       * @returns {Object}
       */
      getInfo: function() {
        var session = this.get();
        return {
          client_id: GA4Utils.clientId.get(),
          session_id: session.id,
          session_count: session.sessionCount,
          is_new_session: session.isNew,
          is_first_visit: session.isFirstVisit
        };
      }
    },

    /**
     * UTM Parameter Management
     */
    utm: {
      /**
       * Get UTM source
       * @returns {string|null}
       */
      getSource: function() {
        return GA4Utils.url.getParameterByName('utm_source');
      },

      /**
       * Get UTM medium
       * @returns {string|null}
       */
      getMedium: function() {
        return GA4Utils.url.getParameterByName('utm_medium');
      },

      /**
       * Get UTM campaign
       * @returns {string|null}
       */
      getCampaign: function() {
        return GA4Utils.url.getParameterByName('utm_campaign');
      },

      /**
       * Get UTM content
       * @returns {string|null}
       */
      getContent: function() {
        return GA4Utils.url.getParameterByName('utm_content');
      },

      /**
       * Get UTM term
       * @returns {string|null}
       */
      getTerm: function() {
        return GA4Utils.url.getParameterByName('utm_term');
      },

      /**
       * Get all UTM parameters
       * @returns {Object}
       */
      getAll: function() {
        return {
          utm_source: this.getSource() || '',
          utm_medium: this.getMedium() || '',
          utm_campaign: this.getCampaign() || '',
          utm_content: this.getContent() || '',
          utm_term: this.getTerm() || ''
        };
      }
    },

    /**
     * Google Click ID Management
     */
    gclid: {
      /**
       * Get Google Click ID from URL
       * @returns {string|null}
       */
      get: function() {
        return GA4Utils.url.getParameterByName('gclid');
      }
    },

    /**
     * URL Utilities
     */
    url: {
      /**
       * Get URL parameter by name
       * @param {string} name Parameter name
       * @param {string} url URL to parse (defaults to current URL)
       * @returns {string|null}
       */
      getParameterByName: function(name, url) {
        if (!url) url = window.location.href;
        name = name.replace(/[\[\]]/g, '\\$&');
        var regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)'),
            results = regex.exec(url);
        if (!results) return null;
        if (!results[2]) return '';
        return decodeURIComponent(results[2].replace(/\+/g, ' '));
      },

      /**
       * Get page location without parameters (trimmed to 100 chars)
       * @param {string} url URL to process
       * @returns {string}
       */
      getLocationWithoutParams: function(url) {
        try {
          // Parse the URL
          const parsedUrl = new URL(url);

          // Get the base URL without parameters (protocol + hostname + pathname)
          let cleanUrl = parsedUrl.protocol + '//' + parsedUrl.hostname + parsedUrl.pathname;

          // Trim to 100 characters if needed
          if (cleanUrl.length > 100) {
            cleanUrl = cleanUrl.substring(0, 100);
          }

          return cleanUrl;
        } catch (e) {
          // In case of invalid URL, return a trimmed version of the original
          return url.split('?')[0].substring(0, 100);
        }
      }
    },

    /**
     * Device and Browser Detection
     */
    device: {
      /**
       * Parse user agent and get device information
       * @returns {Object}
       */
      parseUserAgent: function() {
        var userAgent = navigator.userAgent;
        var clientHints = this.getClientHints();

        // Initialize the result object
        var parsedUA = {
          browser_name: '',
          device_type: '',
          user_agent: userAgent.substring(0, 100), // Limit to 100 chars
          is_mobile: false,
          is_tablet: false,
          is_desktop: false
        };

        // Use Client Hints API if available (more reliable than user agent parsing)
        if (clientHints) {
          parsedUA.browser_name = clientHints.brands?.[0]?.brand || '';
          parsedUA.is_mobile = clientHints.mobile || false;
        }

        // Parse browser information from user agent
        if (!parsedUA.browser_name) {
          if (userAgent.indexOf('Chrome') > -1) {
            if (userAgent.indexOf('Edg') > -1) {
              parsedUA.browser_name = 'Microsoft Edge';
            } else if (userAgent.indexOf('OPR') > -1 || userAgent.indexOf('Opera') > -1) {
              parsedUA.browser_name = 'Opera';
            } else if (userAgent.indexOf('YaBrowser') > -1) {
              parsedUA.browser_name = 'Yandex Browser';
            } else if (userAgent.indexOf('Brave') > -1) {
              parsedUA.browser_name = 'Brave';
            } else {
              parsedUA.browser_name = 'Chrome';
            }
          } else if (userAgent.indexOf('Firefox') > -1) {
            parsedUA.browser_name = 'Firefox';
          } else if (userAgent.indexOf('Safari') > -1 && userAgent.indexOf('Chrome') === -1) {
            parsedUA.browser_name = 'Safari';
          } else if (userAgent.indexOf('Trident') > -1 || userAgent.indexOf('MSIE') > -1) {
            parsedUA.browser_name = 'Internet Explorer';
          } else if (userAgent.indexOf('SamsungBrowser') > -1) {
            parsedUA.browser_name = 'Samsung Internet';
          } else if (userAgent.indexOf('UCBrowser') > -1) {
            parsedUA.browser_name = 'UC Browser';
          } else {
            parsedUA.browser_name = 'Unknown';
          }
        }

        // Determine device type based on user agent
        if (userAgent.indexOf('iPhone') > -1 ||
            (userAgent.indexOf('Android') > -1 && userAgent.indexOf('Mobile') > -1) ||
            userAgent.indexOf('Windows Phone') > -1 ||
            userAgent.indexOf('BlackBerry') > -1) {
          parsedUA.device_type = 'mobile';
          parsedUA.is_mobile = true;
        } else if (userAgent.indexOf('iPad') > -1 ||
                   (userAgent.indexOf('Android') > -1 && userAgent.indexOf('Mobile') === -1) ||
                   userAgent.indexOf('Tablet') > -1) {
          parsedUA.device_type = 'tablet';
          parsedUA.is_tablet = true;
        } else {
          parsedUA.device_type = 'desktop';
          parsedUA.is_desktop = true;
        }

        // Override with client hints if available
        if (clientHints && clientHints.mobile) {
          if (userAgent.indexOf('iPad') > -1 || userAgent.indexOf('Tablet') > -1 ||
              (userAgent.indexOf('Android') > -1 && userAgent.indexOf('Mobile') === -1)) {
            parsedUA.device_type = 'tablet';
            parsedUA.is_tablet = true;
            parsedUA.is_mobile = false;
          } else {
            parsedUA.device_type = 'mobile';
            parsedUA.is_mobile = true;
            parsedUA.is_tablet = false;
          }
          parsedUA.is_desktop = false;
        } else if (clientHints && !clientHints.mobile) {
          parsedUA.device_type = 'desktop';
          parsedUA.is_desktop = true;
          parsedUA.is_mobile = false;
          parsedUA.is_tablet = false;
        }

        return parsedUA;
      },

      /**
       * Get User Agent Client Hints (modern approach)
       * @returns {Object|null}
       */
      getClientHints: function() {
        // Check if User Agent Client Hints API is available
        if (navigator.userAgentData) {
          try {
            return {
              brands: navigator.userAgentData.brands,
              mobile: navigator.userAgentData.mobile,
              platform: navigator.userAgentData.platform
            };
          } catch (e) {
            console.log('Error accessing User Agent Client Hints:', e);
            return null;
          }
        }
        return null;
      },

      /**
       * Get screen resolution
       * @returns {string}
       */
      getScreenResolution: function() {
        if (typeof window !== 'undefined' && window.screen) {
          return window.screen.width + 'x' + window.screen.height;
        }
        return 'unknown';
      }
    },

    /**
     * User Information Management
     */
    user: {
      /**
       * Get user information from various sources
       * @param {Object} configData Optional config data with user info
       * @returns {Object}
       */
      getInfo: function(configData) {
        var userInfo = {
          email: '',
          phone: '',
          firstName: '',
          lastName: ''
        };

        // Try to get from config data if available
        if (configData && configData.userData) {
          userInfo.email = configData.userData.email || '';
          userInfo.phone = configData.userData.phone || '';
          userInfo.firstName = configData.userData.first_name || '';
          userInfo.lastName = configData.userData.last_name || '';
        }

        // Try to get from form fields on the page
        var $emailField = $('input[type="email"], input[name*="email"]').first();
        if ($emailField.length && $emailField.val()) {
          userInfo.email = $emailField.val();
        }

        var $phoneField = $('input[type="tel"], input[name*="phone"]').first();
        if ($phoneField.length && $phoneField.val()) {
          userInfo.phone = $phoneField.val();
        }

        var $firstNameField = $('input[name*="first_name"], input[name*="fname"]').first();
        if ($firstNameField.length && $firstNameField.val()) {
          userInfo.firstName = $firstNameField.val();
        }

        var $lastNameField = $('input[name*="last_name"], input[name*="lname"]').first();
        if ($lastNameField.length && $lastNameField.val()) {
          userInfo.lastName = $lastNameField.val();
        }

        return userInfo;
      }
    },

    /**
     * Location Data Management
     */
    location: {
      /**
       * Get cached location data if valid, otherwise fetch fresh data
       * @returns {Promise<Object>}
       */
      get: function() {
        return new Promise((resolve, reject) => {
          // First check if we have cached location data
          const cachedLocation = localStorage.getItem("user_location_data");
          
          if (cachedLocation) {
            try {
              const locationData = JSON.parse(cachedLocation);
              const now = Date.now();
              const oneHour = 60 * 60 * 1000; // 1 hour in milliseconds
              
              // Check if cached data is still valid (1 hour expiry)
              if (locationData.timestamp && (now - locationData.timestamp < oneHour)) {
                GA4Utils.helpers.log('Using cached location data', locationData, {debugMode: true}, '[Location Utils]');
                resolve(locationData);
                return;
              } else {
                // Expired, remove it
                localStorage.removeItem("user_location_data");
                GA4Utils.helpers.log('Cached location data expired, fetching fresh data', null, {debugMode: true}, '[Location Utils]');
              }
            } catch (e) {
              GA4Utils.helpers.log('Error parsing cached location data', e, {debugMode: true}, '[Location Utils]');
              localStorage.removeItem("user_location_data");
            }
          }

          // Fetch fresh location data
          this.fetch()
            .then((ipLocationData) => {
              resolve(ipLocationData);
            })
            .catch((err) => {
              GA4Utils.helpers.log('IP location error', err, {debugMode: true}, '[Location Utils]');
              resolve({});
            });
        });
      },

      /**
       * Fetch fresh location data from IP geolocation services
       * @returns {Promise<Object>}
       */
      fetch: function() {
        return new Promise((resolve, reject) => {
          GA4Utils.helpers.log('Fetching fresh location data', null, {debugMode: true}, '[Location Utils]');
          
          // Try ipapi.co first
          fetch("https://ipapi.co/json/")
            .then((response) => {
              if (!response.ok) throw new Error("ipapi.co lookup failed");
              return response.json();
            })
            .then((data) => {
              const locationData = {
                latitude: parseFloat(data.latitude),
                longitude: parseFloat(data.longitude),
                city: data.city || "",
                region: data.region || "",
                country: data.country_name || "",
                timestamp: Date.now() // Add timestamp
              };
              
              // Cache the fresh data
              this.cache(locationData);
              GA4Utils.helpers.log('Location obtained from ipapi.co', locationData, {debugMode: true}, '[Location Utils]');
              resolve(locationData);
            })
            .catch((error) => {
              GA4Utils.helpers.log('First IP location service failed, trying fallback', error, {debugMode: true}, '[Location Utils]');

              // Fallback to ipinfo.io
              fetch("https://ipinfo.io/json")
                .then((response) => {
                  if (!response.ok) throw new Error("ipinfo.io lookup failed");
                  return response.json();
                })
                .then((data) => {
                  let coords = [0, 0];
                  if (data.loc && data.loc.includes(",")) {
                    coords = data.loc.split(",");
                  }

                  const locationData = {
                    latitude: parseFloat(coords[0]),
                    longitude: parseFloat(coords[1]),
                    city: data.city || "",
                    region: data.region || "",
                    country: data.country || "",
                    timestamp: Date.now() // Add timestamp
                  };

                  // Cache the fresh data
                  this.cache(locationData);
                  GA4Utils.helpers.log('Location obtained from fallback service', locationData, {debugMode: true}, '[Location Utils]');
                  resolve(locationData);
                })
                .catch((secondError) => {
                  GA4Utils.helpers.log('Second IP location service failed, trying final fallback', secondError, {debugMode: true}, '[Location Utils]');

                  // Final fallback to geoiplookup.io
                  fetch("https://json.geoiplookup.io/")
                    .then((response) => {
                      if (!response.ok) throw new Error("geoiplookup.io failed");
                      return response.json();
                    })
                    .then((data) => {
                      const locationData = {
                        latitude: parseFloat(data.latitude),
                        longitude: parseFloat(data.longitude),
                        city: data.city || "",
                        region: data.region || "",
                        country: data.country_name || "",
                        timestamp: Date.now() // Add timestamp
                      };

                      // Cache the fresh data
                      this.cache(locationData);
                      GA4Utils.helpers.log('Location obtained from final fallback service', locationData, {debugMode: true}, '[Location Utils]');
                      resolve(locationData);
                    })
                    .catch((finalError) => {
                      GA4Utils.helpers.log('All IP location services failed', finalError, {debugMode: true}, '[Location Utils]');
                      resolve({});
                    });
                });
            });
        });
      },

      /**
       * Cache location data in localStorage
       * @param {Object} locationData Location data to cache
       */
      cache: function(locationData) {
        if (!locationData.timestamp) {
          locationData.timestamp = Date.now();
        }
        
        try {
          localStorage.setItem("user_location_data", JSON.stringify(locationData));
          GA4Utils.helpers.log('Location data cached successfully', null, {debugMode: true}, '[Location Utils]');
        } catch (e) {
          GA4Utils.helpers.log('Error caching location data', e, {debugMode: true}, '[Location Utils]');
        }
      },

      /**
       * Get cached location data without fetching fresh data
       * @returns {Object|null}
       */
      getCached: function() {
        const cachedLocation = localStorage.getItem("user_location_data");
        
        if (cachedLocation) {
          try {
            const locationData = JSON.parse(cachedLocation);
            const now = Date.now();
            const oneHour = 60 * 60 * 1000;
            
            // Check if still valid
            if (locationData.timestamp && (now - locationData.timestamp < oneHour)) {
              return locationData;
            } else {
              // Expired
              this.clearCache();
              return null;
            }
          } catch (e) {
            GA4Utils.helpers.log('Error parsing cached location data', e, {debugMode: true}, '[Location Utils]');
            this.clearCache();
            return null;
          }
        }
        
        return null;
      },

      /**
       * Clear cached location data
       */
      clearCache: function() {
        localStorage.removeItem("user_location_data");
        GA4Utils.helpers.log('Location cache cleared', null, {debugMode: true}, '[Location Utils]');
      },

      /**
       * Check if cached location data exists and is valid
       * @returns {boolean}
       */
      hasCachedData: function() {
        return this.getCached() !== null;
      },

      /**
       * Get location data age in milliseconds
       * @returns {number|null}
       */
      getCacheAge: function() {
        const cached = this.getCached();
        if (cached && cached.timestamp) {
          return Date.now() - cached.timestamp;
        }
        return null;
      },

      /**
       * Force refresh location data
       * @returns {Promise<Object>}
       */
      refresh: function() {
        this.clearCache();
        return this.fetch();
      }
    },

    /**
     * Traffic Type Determination
     */
    traffic: {
      /**
       * Determine traffic type based on source and medium
       * @param {string} source Traffic source
       * @param {string} medium Traffic medium
       * @returns {string}
       */
      getType: function(source, medium) {
        // Direct traffic
        if (source === '(direct)' && medium === 'none') {
          return 'direct';
        }

        // Organic search
        if (medium === 'organic') {
          return 'organic';
        }

        // Paid search
        if (medium === 'cpc' || medium === 'ppc' || medium === 'paidsearch') {
          return 'paid_search';
        }

        // Social media
        if (medium === 'social' || medium === 'sm' ||
            source === 'facebook' || source === 'instagram' ||
            source === 'twitter' || source === 'linkedin' ||
            source === 'pinterest' || source === 'youtube') {
          return 'social';
        }

        // Email marketing
        if (medium === 'email') {
          return 'email';
        }

        // Affiliates
        if (medium === 'affiliate') {
          return 'affiliate';
        }

        // Regular referral traffic (links from other sites)
        if (medium === 'referral') {
          return 'referral';
        }

        // Display or banner ads
        if (medium === 'display' || medium === 'banner' || medium === 'cpm') {
          return 'display';
        }

        // Video ads
        if (medium === 'video') {
          return 'video';
        }

        // Default fallback
        return 'other';
      }
    },

    /**
     * Time and Engagement Utilities
     */
    time: {
      /**
       * Calculate engagement time
       * @param {number} startTime Start time timestamp
       * @returns {number}
       */
      calculateEngagementTime: function(startTime) {
        var currentTime = Date.now();
        var engagementTime = currentTime - (startTime || currentTime);

        // Ensure minimum engagement time of 1000ms for GA4 compatibility
        // and maximum of 30 minutes (1800000ms) to prevent unrealistic values
        return Math.max(1000, Math.min(engagementTime, 1800000));
      }
    },

    /**
     * Page Detection Utilities
     */
    page: {
      /**
       * Check if we're on an order confirmation page
       * @param {Object} config Configuration object
       * @param {string} trackingType Type of tracking ('ga4' or 'google_ads')
       * @returns {boolean}
       */
      isOrderConfirmationPage: function(config, trackingType) {
        trackingType = trackingType || 'ga4'; // Default to GA4
        
        // First check if this is actually an order confirmation page
        var isOrderPage = this._checkOrderPageCriteria(config);
        
        if (!isOrderPage) {
          return false;
        }

        // If it's an order page, check if we should track based on tracking type
        return this._shouldTrackPurchase(trackingType);
      },

      /**
       * Internal method to check if page meets order confirmation criteria
       * @param {Object} config Configuration object
       * @returns {boolean}
       * @private
       */
      _checkOrderPageCriteria: function(config) {
        // Check config value
        if (config && config.isThankYouPage === true) {
          return true;
        }

        // Check URL patterns
        var isUrlMatch = window.location.href.indexOf('/checkout/order-received/') > -1 ||
                        window.location.href.indexOf('/inschrijven/order-received/') > -1 ||
                        window.location.href.indexOf('/order-pay/') > -1 ||
                        window.location.href.indexOf('/thank-you/') > -1;
        
        if (isUrlMatch) {
          return true;
        }

        // Check for WooCommerce body class
        var hasWooClass = document.body.classList.contains('woocommerce-order-received') ||
                         (document.body.classList.contains('woocommerce-checkout') &&
                          document.querySelector('.woocommerce-order-overview') !== null);
        
        if (hasWooClass) {
          return true;
        }

        // Check for order ID in URL parameters
        var urlParams = new URLSearchParams(window.location.search);
        var hasOrderParam = urlParams.has('order') || urlParams.has('order_id');
        
        if (hasOrderParam) {
          return true;
        }

        // Check for thank you page elements
        var hasThankYouElements = document.querySelector('.woocommerce-thankyou-order-details') !== null ||
                                 document.querySelector('.woocommerce-order-received') !== null ||
                                 document.querySelector('.woocommerce-notice--success') !== null;
        
        return hasThankYouElements;
      },

      /**
       * Check if purchase should be tracked based on tracking type and previous tracking
       * @param {string} trackingType Type of tracking ('ga4' or 'google_ads')
       * @returns {boolean}
       * @private
       */
      _shouldTrackPurchase: function(trackingType) {
        var orderId = this._extractOrderId();
        
        if (!orderId) {
          // If we can't get an order ID, allow tracking but warn
          GA4Utils.helpers.log('No order ID found, allowing purchase tracking', null, {debugMode: true}, '[Purchase Tracking]');
          return true;
        }

        var storageKey = 'purchase_tracked_' + trackingType + '_' + orderId;
        var storedData = localStorage.getItem(storageKey);

        if (storedData) {
          try {
            var data = JSON.parse(storedData);
            var now = Date.now();
            var thirtyMinutes = 30 * 60 * 1000; // 30 minutes in milliseconds
            
            // Check if the tracking data has expired
            if (data.timestamp && (now - data.timestamp > thirtyMinutes)) {
              // Expired, remove it and allow tracking
              localStorage.removeItem(storageKey);
              GA4Utils.helpers.log('Purchase tracking expired for ' + trackingType + ', order: ' + orderId, null, {debugMode: true}, '[Purchase Tracking]');
            } else {
              // Still valid, prevent duplicate tracking
              GA4Utils.helpers.log('Purchase already tracked for ' + trackingType + ', order: ' + orderId, null, {debugMode: true}, '[Purchase Tracking]');
              return false;
            }
          } catch (e) {
            // If we can't parse the data, remove it and allow tracking
            localStorage.removeItem(storageKey);
            GA4Utils.helpers.log('Invalid purchase tracking data, removing for ' + trackingType + ', order: ' + orderId, null, {debugMode: true}, '[Purchase Tracking]');
          }
        }

        // Mark as tracked with timestamp
        var trackingData = {
          timestamp: Date.now(),
          orderId: orderId,
          trackingType: trackingType
        };
        
        localStorage.setItem(storageKey, JSON.stringify(trackingData));
        GA4Utils.helpers.log('Marking purchase as tracked for ' + trackingType + ', order: ' + orderId, null, {debugMode: true}, '[Purchase Tracking]');
        
        return true;
      },

      /**
       * Extract order ID from various sources
       * @returns {string|null}
       * @private
       */
      _extractOrderId: function() {
        // Try to get order ID from URL path
        var orderIdMatch = window.location.pathname.match(/order-received\/(\d+)/);
        if (orderIdMatch && orderIdMatch[1]) {
          return orderIdMatch[1];
        }

        // Try to get from URL parameters
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('order')) {
          return urlParams.get('order');
        }
        if (urlParams.has('order_id')) {
          return urlParams.get('order_id');
        }

        // Try to extract from page content
        var orderElement = document.querySelector('.woocommerce-order-number, .order-number');
        if (orderElement) {
          var orderText = orderElement.textContent || orderElement.innerText;
          var orderMatch = orderText.match(/\d+/);
          if (orderMatch) {
            return orderMatch[0];
          }
        }

        // Try to get from hidden input fields
        var orderInput = document.querySelector('input[name="order_id"], input[name="order"]');
        if (orderInput && orderInput.value) {
          return orderInput.value;
        }

        return null;
      },

      /**
       * Check if we're on a product list page
       * @returns {boolean}
       */
      isProductListPage: function() {
        return $('.woocommerce .products').length && !$('.woocommerce-product-gallery').length;
      },

      /**
       * Check if we're on a single product page
       * @param {Object} config Configuration object
       * @returns {boolean}
       */
      isProductPage: function(config) {
        return config && config.productData ? true : false;
      },

      /**
       * Reset purchase tracking for testing purposes
       * @param {string} orderId Order ID to reset
       * @param {string} trackingType Optional tracking type to reset ('ga4', 'google_ads', or 'both')
       */
      resetPurchaseTracking: function(orderId, trackingType) {
        trackingType = trackingType || 'both';
        
        if (trackingType === 'both' || trackingType === 'ga4') {
          localStorage.removeItem('purchase_tracked_ga4_' + orderId);
        }
        
        if (trackingType === 'both' || trackingType === 'google_ads') {
          localStorage.removeItem('purchase_tracked_google_ads_' + orderId);
        }
        
        GA4Utils.helpers.log('Reset purchase tracking for order: ' + orderId + ', type: ' + trackingType, null, {debugMode: true}, '[Purchase Tracking]');
      },

      /**
       * Manually clean up all expired purchase tracking data
       * Can be called independently for maintenance
       */
      cleanupExpiredPurchaseTracking: function() {
        GA4Utils.session._clearExpiredPurchaseTracking();
      },

      /**
       * Manually clean up expired location data
       * Can be called independently for maintenance
       */
      cleanupExpiredLocationData: function() {
        GA4Utils.session._clearExpiredLocationData();
      },

      /**
       * Clean up all expired data (purchase tracking and location)
       */
      cleanupAllExpiredData: function() {
        this.cleanupExpiredPurchaseTracking();
        this.cleanupExpiredLocationData();
      }
    },

    /**
     * AJAX Utilities
     */
    ajax: {
      /**
       * Send AJAX payload to endpoint
       * @param {string} endpoint URL to send to
       * @param {Object} payload Data to send
       * @param {Object} config Configuration object
       * @param {string} logPrefix Prefix for log messages
       * @returns {Promise}
       */
      sendPayload: function(endpoint, payload, config, logPrefix) {
        logPrefix = logPrefix || '[GA4Utils AJAX]';
        
        return new Promise(function(resolve, reject) {
          $.ajax({
            url: endpoint,
            type: "POST",
            data: JSON.stringify(payload),
            contentType: "application/json",
            beforeSend: function (xhr) {
              // Add nonce for WordPress REST API
              if (config && config.apiEndpoint && endpoint === config.apiEndpoint) {
                xhr.setRequestHeader("X-WP-Nonce", config.nonce || '');
              }
              GA4Utils.helpers.log("Sending AJAX request to: " + endpoint, null, config, logPrefix);
            },
            success: function (response) {
              GA4Utils.helpers.log("AJAX request sent successfully", response, config, logPrefix);
              resolve(response);
            },
            error: function (xhr, status, error) {
              GA4Utils.helpers.log("Error sending AJAX request: " + error, xhr, config, logPrefix);
              console.error(logPrefix + " Error sending request:", {
                status: status,
                error: error,
                response: xhr.responseText,
              });
              reject(new Error(error));
            },
          });
        });
      },

      /**
       * Send payload using Fetch API (alternative to jQuery AJAX)
       * @param {string} endpoint URL to send to
       * @param {Object} payload Data to send
       * @param {Object} config Configuration object
       * @param {string} logPrefix Prefix for log messages
       * @returns {Promise}
       */
      sendPayloadFetch: function(endpoint, payload, config, logPrefix) {
        logPrefix = logPrefix || '[GA4Utils Fetch]';
        
        var headers = {
          'Content-Type': 'application/json'
        };

        // Add nonce for WordPress REST API
        if (config && config.apiEndpoint && endpoint === config.apiEndpoint) {
          headers['X-WP-Nonce'] = config.nonce || '';
        }

        GA4Utils.helpers.log("Sending Fetch request to: " + endpoint, null, config, logPrefix);

        return fetch(endpoint, {
          method: 'POST',
          headers: headers,
          body: JSON.stringify(payload)
        })
        .then(function(response) {
          if (!response.ok) {
            throw new Error('Network response was not ok: ' + response.status);
          }
          return response.json();
        })
        .then(function(data) {
          GA4Utils.helpers.log("Fetch request sent successfully", data, config, logPrefix);
          return data;
        })
        .catch(function(error) {
          GA4Utils.helpers.log("Error sending Fetch request", error, config, logPrefix);
          console.error(logPrefix + " Error sending request:", error);
          throw error;
        });
      }
    },

    /**
     * Helper Utilities
     */
    helpers: {
      /**
       * Generate unique ID
       * @returns {string}
       */
      generateUniqueId: function() {
        return Date.now().toString();
      },

      /**
       * Log messages if debug mode is enabled
       * @param {string} message Log message
       * @param {*} data Optional data to log
       * @param {Object} config Configuration object
       * @param {string} prefix Log prefix
       */
      log: function(message, data, config, prefix) {
        prefix = prefix || '[GA4 Utils]';
        if (config && config.debugMode && window.console) {
          if (data) {
            console.log(prefix + ' ' + message, data);
          } else {
            console.log(prefix + ' ' + message);
          }
        }
      },

      /**
       * Get social media platform from URL
       * @param {string} url URL to check
       * @returns {string}
       */
      getSocialPlatform: function(url) {
        if (url.indexOf('facebook.com') > -1) return 'Facebook';
        if (url.indexOf('twitter.com') > -1) return 'Twitter';
        if (url.indexOf('linkedin.com') > -1) return 'LinkedIn';
        if (url.indexOf('instagram.com') > -1) return 'Instagram';
        if (url.indexOf('youtube.com') > -1) return 'YouTube';
        if (url.indexOf('tiktok.com') > -1) return 'TikTok';
        return 'Other';
      },

      /**
       * Debounce function calls
       * @param {Function} func Function to debounce
       * @param {number} wait Wait time in milliseconds
       * @param {boolean} immediate Execute immediately on first call
       * @returns {Function}
       */
      debounce: function(func, wait, immediate) {
        var timeout;
        return function() {
          var context = this, args = arguments;
          var later = function() {
            timeout = null;
            if (!immediate) func.apply(context, args);
          };
          var callNow = immediate && !timeout;
          clearTimeout(timeout);
          timeout = setTimeout(later, wait);
          if (callNow) func.apply(context, args);
        };
      },

      /**
       * Throttle function calls
       * @param {Function} func Function to throttle
       * @param {number} limit Limit in milliseconds
       * @returns {Function}
       */
      throttle: function(func, limit) {
        var inThrottle;
        return function() {
          var args = arguments;
          var context = this;
          if (!inThrottle) {
            func.apply(context, args);
            inThrottle = true;
            setTimeout(function() { inThrottle = false; }, limit);
          }
        };
      },

      /**
       * Check if element is in viewport
       * @param {Element} element DOM element to check
       * @param {number} threshold Threshold percentage (0-1)
       * @returns {boolean}
       */
      isInViewport: function(element, threshold) {
        threshold = threshold || 0.5;
        
        if (!element || typeof element.getBoundingClientRect !== 'function') {
          return false;
        }
        
        var rect = element.getBoundingClientRect();
        var windowHeight = window.innerHeight || document.documentElement.clientHeight;
        var windowWidth = window.innerWidth || document.documentElement.clientWidth;
        
        var verticalVisible = (rect.top + rect.height * threshold) <= windowHeight && 
                             (rect.bottom - rect.height * threshold) >= 0;
        var horizontalVisible = (rect.left + rect.width * threshold) <= windowWidth && 
                               (rect.right - rect.width * threshold) >= 0;
        
        return verticalVisible && horizontalVisible;
      },

      /**
       * Get cookie value by name
       * @param {string} name Cookie name
       * @returns {string|null}
       */
      getCookie: function(name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(';');
        for(var i = 0; i < ca.length; i++) {
          var c = ca[i];
          while (c.charAt(0) === ' ') c = c.substring(1, c.length);
          if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
        }
        return null;
      },

      /**
       * Set cookie
       * @param {string} name Cookie name
       * @param {string} value Cookie value
       * @param {number} days Days until expiration
       * @param {string} path Cookie path
       * @param {string} domain Cookie domain
       */
      setCookie: function(name, value, days, path, domain) {
        var expires = "";
        if (days) {
          var date = new Date();
          date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
          expires = "; expires=" + date.toUTCString();
        }
        
        var cookieString = name + "=" + (value || "") + expires + "; path=" + (path || "/");
        if (domain) {
          cookieString += "; domain=" + domain;
        }
        
        document.cookie = cookieString;
      },

      /**
       * Remove cookie
       * @param {string} name Cookie name
       * @param {string} path Cookie path
       * @param {string} domain Cookie domain
       */
      removeCookie: function(name, path, domain) {
        this.setCookie(name, "", -1, path, domain);
      }
    }
  };

  // Expose the utilities globally
  window.GA4Utils = GA4Utils;

  // Initialize cleanup on page load
  $(document).ready(function() {
    // Clean up expired data on page load
    GA4Utils.page.cleanupAllExpiredData();
    
    GA4Utils.helpers.log('GA4Utils initialized and cleanup completed', null, {debugMode: true}, '[GA4Utils Init]');
  });

})(window, jQuery);