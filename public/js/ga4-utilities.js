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
     * Centralized Storage Management for GDPR Compliance
     */
    storage: {
      /**
       * Get unified user data from localStorage
       * @returns {Object}
       */
      getUserData: function() {
        try {
          var data = localStorage.getItem('ga4_user_data');
          if (data) {
            var parsed = JSON.parse(data);
            
            // Check if data is expired
            var expirationHours = this.getExpirationHours();
            var now = Date.now();
            var ageHours = (now - parsed.timestamp) / (60 * 60 * 1000);
            
            
            if (parsed.timestamp && (now - parsed.timestamp) > (expirationHours * 60 * 60 * 1000)) {
              // Data expired, remove it
              this.clearUserData();
              return this.getDefaultUserData();
            }
            return parsed;
          } else {
          }
        } catch (e) {
          this.clearUserData();
        }
        return this.getDefaultUserData();
      },

      /**
       * Save unified user data to localStorage
       * @param {Object} userData User data object
       */
      saveUserData: function(userData) {
        try {
          userData.timestamp = Date.now();
          localStorage.setItem('ga4_user_data', JSON.stringify(userData));
        } catch (e) {
        }
      },

      /**
       * Get default user data structure
       * @returns {Object}
       */
      getDefaultUserData: function() {
        var defaultData = {
          // Client identification
          clientId: null,
          sessionId: null,
          sessionStart: null,
          sessionCount: 0,
          firstVisit: null,
          
          // Attribution data
          lastSource: '',
          lastMedium: '',
          lastCampaign: '',
          lastContent: '',
          lastTerm: '',
          lastGclid: '',
          
          // Location data
          location: null,
          
          // Consent data (separate from main data for compliance)
          // consent: null, // Keep this separate in ga4_consent_status
          
          // Metadata
          timestamp: Date.now(),
          version: '1.0'
        };
        
        return defaultData;
      },

      /**
       * Get expiration hours from admin setting
       * @returns {number}
       */
      getExpirationHours: function() {
        // Try to get from global config first
        if (window.ga4ServerSideTagging && window.ga4ServerSideTagging.consentSettings) {
          return window.ga4ServerSideTagging.consentSettings.storageExpirationHours || 24;
        }
        return 24; // Default 24 hours
      },

      /**
       * Clear all user data (but preserve consent data)
       */
      clearUserData: function() {
        localStorage.removeItem('ga4_user_data');
        
        // Also clean up legacy items if they exist
        var legacyKeys = [
          'server_side_ga4_client_id',
          'server_side_ga4_session_id', 
          'server_side_ga4_session_start',
          'server_side_ga4_session_count',
          'server_side_ga4_first_visit',
          'server_side_ga4_last_source',
          'server_side_ga4_last_medium',
          'server_side_ga4_last_campaign',
          'server_side_ga4_last_content',
          'server_side_ga4_last_term',
          'server_side_ga4_last_gclid',
          'user_location_data'
        ];
        
        legacyKeys.forEach(function(key) {
          localStorage.removeItem(key);
          localStorage.removeItem(key + '_timestamp');
        });

        // IMPORTANT: Do NOT clear consent data (ga4_consent_status)
        // Consent should persist longer than tracking data for GDPR compliance
      },

      /**
       * Migrate legacy data to new centralized system
       */
      migrateLegacyData: function() {
        
        // Check if we already have centralized data
        var existingData = localStorage.getItem('ga4_user_data');
        if (existingData) {
          return JSON.parse(existingData);
        }
        
        var userData = this.getDefaultUserData();
        var needsMigration = false;
        var migratedItems = [];

        // Migrate client ID
        var oldClientId = localStorage.getItem('server_side_ga4_client_id');
        if (oldClientId && !userData.clientId) {
          userData.clientId = oldClientId;
          needsMigration = true;
          migratedItems.push('clientId: ' + oldClientId);
        }

        // Migrate session data
        var oldSessionId = localStorage.getItem('server_side_ga4_session_id');
        var oldSessionStart = localStorage.getItem('server_side_ga4_session_start');
        var oldSessionCount = localStorage.getItem('server_side_ga4_session_count');
        var oldFirstVisit = localStorage.getItem('server_side_ga4_first_visit');

        if (oldSessionId) {
          userData.sessionId = oldSessionId;
          userData.sessionStart = parseInt(oldSessionStart) || Date.now();
          userData.sessionCount = parseInt(oldSessionCount) || 1;
          userData.firstVisit = parseInt(oldFirstVisit) || Date.now();
          needsMigration = true;
          migratedItems.push('sessionData: ' + oldSessionId);
        }

        // Migrate attribution data
        var attributionKeys = ['source', 'medium', 'campaign', 'content', 'term', 'gclid'];
        attributionKeys.forEach(function(key) {
          var oldValue = localStorage.getItem('server_side_ga4_last_' + key);
          if (oldValue) {
            userData['last' + key.charAt(0).toUpperCase() + key.slice(1)] = oldValue;
            needsMigration = true;
            migratedItems.push(key + ': ' + oldValue);
          }
        });

        // Migrate location data
        var oldLocation = localStorage.getItem('user_location_data');
        if (oldLocation) {
          try {
            userData.location = JSON.parse(oldLocation);
            needsMigration = true;
            migratedItems.push('location data');
          } catch (e) {
            console.log('❌ [GA4 Storage] Error migrating location data:', e);
          }
        }

        if (needsMigration) {
          this.saveUserData(userData);
          
          // Clean up old data after successful migration
          setTimeout(() => {
            this.clearLegacyData(); // Clean only legacy keys, not the new centralized data
          }, 100);
          
        } else {
        }

        return userData;
      },

      /**
       * Clear only legacy data items (not the new centralized data)
       */
      clearLegacyData: function() {
        var legacyKeys = [
          'server_side_ga4_client_id',
          'server_side_ga4_session_id', 
          'server_side_ga4_session_start',
          'server_side_ga4_session_count',
          'server_side_ga4_first_visit',
          'server_side_ga4_last_source',
          'server_side_ga4_last_medium',
          'server_side_ga4_last_campaign',
          'server_side_ga4_last_content',
          'server_side_ga4_last_term',
          'server_side_ga4_last_gclid',
          'user_location_data'
        ];
        
        var removedItems = [];
        legacyKeys.forEach(function(key) {
          if (localStorage.getItem(key)) {
            localStorage.removeItem(key);
            removedItems.push(key);
          }
          var timestampKey = key + '_timestamp';
          if (localStorage.getItem(timestampKey)) {
            localStorage.removeItem(timestampKey);
            removedItems.push(timestampKey);
          }
        });
        
        if (removedItems.length > 0) {
        }
      },

      /**
       * Check if data is expired and clean up if needed
       */
      cleanupExpiredData: function() {
        var userData = this.getUserData();
        var expirationHours = this.getExpirationHours();
        var now = Date.now();
        
        if (userData.timestamp && (now - userData.timestamp) > (expirationHours * 60 * 60 * 1000)) {
          this.clearUserData();
          return true;
        }
        return false;
      },

      /**
       * Get consent data (separate from user tracking data)
       * @returns {Object|null}
       */
      getConsentData: function() {
        try {
          var consentData = localStorage.getItem('ga4_consent_status');
          if (consentData) {
            return JSON.parse(consentData);
          }
        } catch (e) {
        }
        return null;
      },

      /**
       * Save consent data (separate from user tracking data)
       * @param {Object} consentData
       */
      saveConsentData: function(consentData) {
        try {
          consentData.timestamp = Date.now();
          localStorage.setItem('ga4_consent_status', JSON.stringify(consentData));
        } catch (e) {
        }
      },

      /**
       * Check if consent data exists (consent never expires automatically)
       * @returns {boolean}
       */
      hasValidConsent: function() {
        var consentData = this.getConsentData();
        if (!consentData || !consentData.timestamp) {
          return false; // No consent data = needs to choose
        }

        var now = Date.now();
        // Consent persists for 1 year unless user changes it
        var oneYearMs = 365 * 24 * 60 * 60 * 1000; // 1 year
        var ageMs = now - consentData.timestamp;
        var ageDays = ageMs / (24 * 60 * 60 * 1000);

        // Only expire after 1 year (this is just for data cleanup, not functional expiry)
        if (ageMs > oneYearMs) {
          return false;
        }
        
        return true;
      },

      /**
       * Clear only consent data (for privacy requests or manual reset)
       */
      clearConsentData: function() {
        localStorage.removeItem('ga4_consent_status');
      },

      /**
       * Update consent choice (replaces old consent with new choice)
       * @param {Object} newConsentData New consent choice
       */
      updateConsentChoice: function(newConsentData) {
        this.saveConsentData(newConsentData);
      },

      /**
       * Test function to verify the new storage system is working
       * Call GA4Utils.storage.testNewStorage() in console to test
       */
      testNewStorage: function() {
        console.log('🧪 [GA4 Storage] Testing new centralized storage system...');
        
        // Test 1: Get current data
        console.log('📋 Test 1: Getting current user data');
        var userData = this.getUserData();
        console.log('Current data:', userData);
        
        // Test 2: Generate client ID
        console.log('📋 Test 2: Testing client ID generation');
        var clientId = GA4Utils.clientId.get();
        console.log('Client ID:', clientId);
        
        // Test 3: Test session data
        console.log('📋 Test 3: Testing session data');
        var session = GA4Utils.session.get();
        console.log('Session data:', session);
        
        // Test 4: Check data retention setting
        console.log('📋 Test 4: Checking data retention setting');
        var expirationHours = this.getExpirationHours();
        console.log('Data retention hours:', expirationHours);
        
        // Test 5: Check consent data
        console.log('📋 Test 5: Testing consent data persistence');
        var consentData = this.getConsentData();
        var hasValidConsent = this.hasValidConsent();
        console.log('Consent data:', consentData);
        console.log('Has valid consent (never expires unless 1+ year old):', hasValidConsent);
        
        // Test 6: Show localStorage status
        console.log('📋 Test 6: localStorage status');
        console.log('New centralized data exists:', !!localStorage.getItem('ga4_user_data'));
        console.log('Consent data exists:', !!localStorage.getItem('ga4_consent_status'));
        console.log('Legacy client ID exists:', !!localStorage.getItem('server_side_ga4_client_id'));
        console.log('Legacy session ID exists:', !!localStorage.getItem('server_side_ga4_session_id'));
        
        console.log('✅ [GA4 Storage] Test completed! Check the logs above.');
        
        return {
          userData: userData,
          clientId: clientId,
          session: session,
          expirationHours: expirationHours,
          consentData: consentData,
          hasValidConsent: hasValidConsent,
          newDataExists: !!localStorage.getItem('ga4_user_data'),
          consentDataExists: !!localStorage.getItem('ga4_consent_status'),
          legacyDataExists: {
            clientId: !!localStorage.getItem('server_side_ga4_client_id'),
            sessionId: !!localStorage.getItem('server_side_ga4_session_id')
          }
        };
      }
    },

    /**
     * Client ID Management using centralized storage
     */
    clientId: {
      /**
       * Get or generate client ID
       * @returns {string}
       */
      get: function () {
        var userData = GA4Utils.storage.getUserData();
        
        if (userData.clientId) {
          return userData.clientId;
        }
        
        return this.generate();
      },

      /**
       * Generate a random client ID and store it
       * @returns {string}
       */
      generate: function () {
        var clientId =
          Math.round(2147483647 * Math.random()) +
          "." +
          Math.round(Date.now() / 1000);


        // Store in centralized storage
        var userData = GA4Utils.storage.getUserData();
        userData.clientId = clientId;
        GA4Utils.storage.saveUserData(userData);

        return clientId;
      },

      /**
       * Get session-based client ID (for when consent is denied)
       * @returns {string}
       */
      getSessionBased: function() {
        var sessionId = GA4Utils.session.get().id;
        return "session_" + sessionId;
      }

    },

    /**
     * Session Management with centralized data storage
     */
    session: {
      /**
       * Get or create session information
       * @returns {Object}
       */
      get: function () {
        // Get centralized user data
        var userData = GA4Utils.storage.getUserData();
        var now = Date.now();
        var isNew = false;
        var isFirstVisit = false;

        // Check if this is the first visit ever
        if (!userData.firstVisit) {
          userData.firstVisit = now;
          isFirstVisit = true;
        }

        // If no session or session expired (30 min inactive)
        if (
          !userData.sessionId ||
          !userData.sessionStart ||
          now - userData.sessionStart > 30 * 60 * 1000
        ) {
          // Clear expired session data
          this.clear();

          // Generate a more robust session ID using timestamp and random values
          userData.sessionId = GA4Utils.helpers.generateUniqueId();
          userData.sessionStart = now;

          // Increment session count
          userData.sessionCount = (userData.sessionCount || 0) + 1;

          isNew = true;
        }

        // Save updated data
        GA4Utils.storage.saveUserData(userData);

        return {
          id: userData.sessionId,
          start: userData.sessionStart,
          isNew: isNew,
          isFirstVisit: isFirstVisit,
          sessionCount: userData.sessionCount || 1,
          duration: now - userData.sessionStart,
        };
      },

      /**
       * Clear expired session data
       */
      clear: function () {
        // Clear session-specific data but keep user-level data
        localStorage.removeItem("server_side_ga4_session_id");
        localStorage.removeItem("server_side_ga4_session_start");

        // Clear attribution data that's tied to sessions
        localStorage.removeItem("server_side_ga4_last_source");
        localStorage.removeItem("server_side_ga4_last_medium");
        localStorage.removeItem("server_side_ga4_last_campaign");
        localStorage.removeItem("server_side_ga4_last_content");
        localStorage.removeItem("server_side_ga4_last_term");
        localStorage.removeItem("server_side_ga4_last_gclid");

        // Clear purchase tracking data (30 min expiry)
        this._clearExpiredPurchaseTracking();

        // Clear expired user data
        this._clearExpiredUserData();
      },

      /**
       * Clear expired purchase tracking data
       * @private
       */
      _clearExpiredPurchaseTracking: function () {
        if (!window.localStorage) return;

        var keysToRemove = [];
        var now = Date.now();
        var thirtyMinutes = 30 * 60 * 1000; // 30 minutes in milliseconds

        // Loop through all localStorage keys to find purchase tracking entries
        for (var i = 0; i < localStorage.length; i++) {
          var key = localStorage.key(i);

          if (key && key.startsWith("purchase_tracked_")) {
            try {
              var data = JSON.parse(localStorage.getItem(key) || "{}");

              // Check if the entry has expired (older than 30 minutes)
              if (data.timestamp && now - data.timestamp > thirtyMinutes) {
                keysToRemove.push(key);
              }
            } catch (e) {
              // If we can't parse the data, remove it
              keysToRemove.push(key);
            }
          }
        }

        // Remove expired entries
        keysToRemove.forEach(function (key) {
          localStorage.removeItem(key);
          GA4Utils.helpers.log(
            "Removed expired purchase tracking: " + key,
            null,
            {},
            "[Purchase Tracking Cleanup]"
          );
        });

        if (keysToRemove.length > 0) {
          GA4Utils.helpers.log(
            "Cleaned up " +
              keysToRemove.length +
              " expired purchase tracking entries",
            null,
            {},
            "[Purchase Tracking Cleanup]"
          );
        }
      },


      /**
       * Clear all expired user data based on configurable storage expiration
       * @param {number} [customExpirationHours] - Optional custom expiration time in hours
       */
      _clearExpiredUserData: function (customExpirationHours) {
        if (!window.localStorage) return;

        // Get expiration time from config or use default
        var expirationHours = customExpirationHours;
        if (!expirationHours) {
          // Try to get from global config
          if (window.ga4ServerSideTagging && window.ga4ServerSideTagging.consentSettings) {
            expirationHours = window.ga4ServerSideTagging.consentSettings.storageExpirationHours || 24;
          } else {
            expirationHours = 24; // Default 24 hours
          }
        }

        var expirationMs = expirationHours * 60 * 60 * 1000; // Convert hours to milliseconds
        var now = Date.now();
        var cleanedItems = [];

        // Define user data keys that should be cleaned up
        // NOTE: ga4_consent_status is NOT included - it has separate expiration rules
        var userDataKeys = [
          // Location data
          'user_location_data',
          
          // Client and session data (these should persist longer but still expire)
          'server_side_ga4_client_id',
          'server_side_ga4_session_id',
          'server_side_ga4_session_start',
          'server_side_ga4_session_count',
          'server_side_ga4_first_visit',
          
          // Attribution data
          'server_side_ga4_last_source',
          'server_side_ga4_last_medium',
          'server_side_ga4_last_campaign',
          'server_side_ga4_last_content',
          'server_side_ga4_last_term',
          'server_side_ga4_last_gclid'
          
          // IMPORTANT: ga4_consent_status is deliberately NOT included here
          // Consent data has separate expiration rules (30 days vs configurable hours)
        ];

        // Clean up timestamped data
        userDataKeys.forEach(function(key) {
          var data = localStorage.getItem(key);
          if (data) {
            try {
              // For JSON data with timestamps
              if (data.startsWith('{') || data.startsWith('[')) {
                var parsedData = JSON.parse(data);
                if (parsedData.timestamp && now - parsedData.timestamp > expirationMs) {
                  localStorage.removeItem(key);
                  cleanedItems.push(key);
                }
              } else {
                // For simple values, check if they're old enough
                // This applies to items without timestamps - use a longer expiration
                var extendedExpiration = expirationMs * 7; // 7x longer for non-timestamped data
                var itemAge = now - (localStorage.getItem(key + '_timestamp') || 0);
                if (itemAge > extendedExpiration) {
                  localStorage.removeItem(key);
                  localStorage.removeItem(key + '_timestamp');
                  cleanedItems.push(key);
                }
              }
            } catch (e) {
              // If we can't parse the data, it's invalid - remove it
              localStorage.removeItem(key);
              cleanedItems.push(key + ' (invalid)');
            }
          }
        });

        // Clean up purchase tracking data (separate expiration - 30 minutes)
        this._clearExpiredPurchaseTracking();

        // Clean up session storage (queued events)
        this._clearExpiredSessionData(expirationHours);

        // Check consent data expiration separately (uses longer expiration)
        this._cleanupExpiredConsentData();

        // Log cleanup results
        if (cleanedItems.length > 0) {
          GA4Utils.helpers.log(
            "Cleaned up expired user data (" + expirationHours + "h expiration)",
            { cleanedItems: cleanedItems, expirationHours: expirationHours },
            {},
            "[User Data Cleanup]"
          );
        }
      },

      /**
       * Clear expired session storage data (queued events)
       * @param {number} expirationHours - Expiration time in hours
       */
      _clearExpiredSessionData: function (expirationHours) {
        if (!window.sessionStorage) return;

        var expirationMs = expirationHours * 60 * 60 * 1000;
        var now = Date.now();

        // Clean up queued events
        var queuedEvents = sessionStorage.getItem('ga4_queued_events');
        if (queuedEvents) {
          try {
            var events = JSON.parse(queuedEvents);
            var validEvents = events.filter(function(event) {
              return event.timestamp && (now - event.timestamp < expirationMs);
            });
            
            if (validEvents.length !== events.length) {
              if (validEvents.length === 0) {
                sessionStorage.removeItem('ga4_queued_events');
              } else {
                sessionStorage.setItem('ga4_queued_events', JSON.stringify(validEvents));
              }
              
              GA4Utils.helpers.log(
                "Cleaned up expired queued events",
                { 
                  original: events.length, 
                  remaining: validEvents.length,
                  expirationHours: expirationHours 
                },
                {},
                "[Session Data Cleanup]"
              );
            }
          } catch (e) {
            // Invalid data, remove it
            sessionStorage.removeItem('ga4_queued_events');
          }
        }
      },

      /**
       * Clean up very old consent data (1+ year old) - for data hygiene only
       * @private
       */
      _cleanupExpiredConsentData: function() {
        if (!GA4Utils.storage.hasValidConsent()) {
          // hasValidConsent already handles logging and cleanup if over 1 year old
          // This is just for data hygiene, not functional consent expiry
        }
      },

      /**
       * Get session information formatted for tracking
       * @returns {Object}
       */
      getInfo: function () {
        var session = this.get();
        return {
          client_id: GA4Utils.clientId.get(),
          session_id: session.id,
          session_count: session.sessionCount,
          is_new_session: session.isNew,
          is_first_visit: session.isFirstVisit,
        };
      },
    },

    /**
     * UTM Parameter Management
     */
    utm: {
      /**
       * Get UTM source
       * @returns {string|null}
       */
      getSource: function () {
        return GA4Utils.url.getParameterByName("utm_source");
      },

      /**
       * Get UTM medium
       * @returns {string|null}
       */
      getMedium: function () {
        return GA4Utils.url.getParameterByName("utm_medium");
      },

      /**
       * Get UTM campaign
       * @returns {string|null}
       */
      getCampaign: function () {
        return GA4Utils.url.getParameterByName("utm_campaign");
      },

      /**
       * Get UTM content
       * @returns {string|null}
       */
      getContent: function () {
        return GA4Utils.url.getParameterByName("utm_content");
      },

      /**
       * Get UTM term
       * @returns {string|null}
       */
      getTerm: function () {
        return GA4Utils.url.getParameterByName("utm_term");
      },

      /**
       * Get all UTM parameters
       * @returns {Object}
       */
      getAll: function () {
        return {
          utm_source: this.getSource() || "",
          utm_medium: this.getMedium() || "",
          utm_campaign: this.getCampaign() || "",
          utm_content: this.getContent() || "",
          utm_term: this.getTerm() || "",
        };
      },
    },

    /**
     * Google Click ID Management
     */
    gclid: {
      /**
       * Get Google Click ID from URL
       * @returns {string|null}
       */
      get: function () {
        return GA4Utils.url.getParameterByName("gclid");
      },
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
      getParameterByName: function (name, url) {
        if (!url) url = window.location.href;
        name = name.replace(/[\[\]]/g, "\\$&");
        var regex = new RegExp("[?&]" + name + "(=([^&#]*)|&|#|$)"),
          results = regex.exec(url);
        if (!results) return null;
        if (!results[2]) return "";
        return decodeURIComponent(results[2].replace(/\+/g, " "));
      },
    },

    /**
     * Device and Browser Detection
     */
    device: {
        anonymizeUserAgent: function(userAgent) {
          if (!userAgent) return "";
          
          // Remove version numbers and specific details
          return userAgent
            .replace(/\d+\.\d+[\.\d]*/g, "x.x") // Replace version numbers
            .replace(/\([^)]*\)/g, "(anonymous)") // Replace system info in parentheses
            .substring(0, 100); // Truncate to 100 characters
        },
      /**
       * Parse user agent and get device information
       * @returns {Object}
       */
      parseUserAgent: function () {
        var userAgent = navigator.userAgent;
        var clientHints = this.getClientHints();

        // Initialize the result object
        var parsedUA = {
          browser_name: "",
          device_type: "",
          user_agent: userAgent,
          is_mobile: false,
          is_tablet: false,
          is_desktop: false,
        };

        // Use Client Hints API if available (more reliable than user agent parsing)
        if (clientHints) {
          parsedUA.browser_name = clientHints.brands?.[0]?.brand || "";
          parsedUA.is_mobile = clientHints.mobile || false;
        }

        // Parse browser information from user agent
        if (!parsedUA.browser_name) {
          if (userAgent.indexOf("Chrome") > -1) {
            if (userAgent.indexOf("Edg") > -1) {
              parsedUA.browser_name = "Microsoft Edge";
            } else if (
              userAgent.indexOf("OPR") > -1 ||
              userAgent.indexOf("Opera") > -1
            ) {
              parsedUA.browser_name = "Opera";
            } else if (userAgent.indexOf("YaBrowser") > -1) {
              parsedUA.browser_name = "Yandex Browser";
            } else if (userAgent.indexOf("Brave") > -1) {
              parsedUA.browser_name = "Brave";
            } else {
              parsedUA.browser_name = "Chrome";
            }
          } else if (userAgent.indexOf("Firefox") > -1) {
            parsedUA.browser_name = "Firefox";
          } else if (
            userAgent.indexOf("Safari") > -1 &&
            userAgent.indexOf("Chrome") === -1
          ) {
            parsedUA.browser_name = "Safari";
          } else if (
            userAgent.indexOf("Trident") > -1 ||
            userAgent.indexOf("MSIE") > -1
          ) {
            parsedUA.browser_name = "Internet Explorer";
          } else if (userAgent.indexOf("SamsungBrowser") > -1) {
            parsedUA.browser_name = "Samsung Internet";
          } else if (userAgent.indexOf("UCBrowser") > -1) {
            parsedUA.browser_name = "UC Browser";
          } else {
            parsedUA.browser_name = "Unknown";
          }
        }

        // Determine device type based on user agent
        if (
          userAgent.indexOf("iPhone") > -1 ||
          (userAgent.indexOf("Android") > -1 &&
            userAgent.indexOf("Mobile") > -1) ||
          userAgent.indexOf("Windows Phone") > -1 ||
          userAgent.indexOf("BlackBerry") > -1
        ) {
          parsedUA.device_type = "mobile";
          parsedUA.is_mobile = true;
        } else if (
          userAgent.indexOf("iPad") > -1 ||
          (userAgent.indexOf("Android") > -1 &&
            userAgent.indexOf("Mobile") === -1) ||
          userAgent.indexOf("Tablet") > -1
        ) {
          parsedUA.device_type = "tablet";
          parsedUA.is_tablet = true;
        } else {
          parsedUA.device_type = "desktop";
          parsedUA.is_desktop = true;
        }

        // Override with client hints if available
        if (clientHints && clientHints.mobile) {
          if (
            userAgent.indexOf("iPad") > -1 ||
            userAgent.indexOf("Tablet") > -1 ||
            (userAgent.indexOf("Android") > -1 &&
              userAgent.indexOf("Mobile") === -1)
          ) {
            parsedUA.device_type = "tablet";
            parsedUA.is_tablet = true;
            parsedUA.is_mobile = false;
          } else {
            parsedUA.device_type = "mobile";
            parsedUA.is_mobile = true;
            parsedUA.is_tablet = false;
          }
          parsedUA.is_desktop = false;
        } else if (clientHints && !clientHints.mobile) {
          parsedUA.device_type = "desktop";
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
      getClientHints: function () {
        // Check if User Agent Client Hints API is available
        if (navigator.userAgentData) {
          try {
            return {
              brands: navigator.userAgentData.brands,
              mobile: navigator.userAgentData.mobile,
              platform: navigator.userAgentData.platform,
            };
          } catch (e) {
            console.log("Error accessing User Agent Client Hints:", e);
            return null;
          }
        }
        return null;
      },

      /**
       * Get screen resolution
       * @returns {string}
       */
      getScreenResolution: function () {
        if (typeof window !== "undefined" && window.screen) {
          return window.screen.width + "x" + window.screen.height;
        }
        return "unknown";
      },
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
      getInfo: function (configData) {
        var userInfo = {
          email: "",
          phone: "",
          firstName: "",
          lastName: "",
        };

        // Try to get from config data if available
        if (configData && configData.userData) {
          userInfo.email = configData.userData.email || "";
          userInfo.phone = configData.userData.phone || "";
          userInfo.firstName = configData.userData.first_name || "";
          userInfo.lastName = configData.userData.last_name || "";
        }

        // Try to get from form fields on the page
        var $emailField = $(
          'input[type="email"], input[name*="email"]'
        ).first();
        if ($emailField.length && $emailField.val()) {
          userInfo.email = $emailField.val();
        }

        var $phoneField = $('input[type="tel"], input[name*="phone"]').first();
        if ($phoneField.length && $phoneField.val()) {
          userInfo.phone = $phoneField.val();
        }

        var $firstNameField = $(
          'input[name*="first_name"], input[name*="fname"]'
        ).first();
        if ($firstNameField.length && $firstNameField.val()) {
          userInfo.firstName = $firstNameField.val();
        }

        var $lastNameField = $(
          'input[name*="last_name"], input[name*="lname"]'
        ).first();
        if ($lastNameField.length && $lastNameField.val()) {
          userInfo.lastName = $lastNameField.val();
        }

        return userInfo;
      },
    },

    /**
     * Location Data Management using centralized storage
     */
    location: {
      /**
       * Get cached location data if valid, otherwise fetch fresh data
       * @returns {Promise<Object>}
       */
      get: function () {
        return new Promise((resolve, reject) => {
          // Get location data from centralized storage
          var userData = GA4Utils.storage.getUserData();
          var expirationHours = GA4Utils.storage.getExpirationHours();

          if (userData.location && userData.location.timestamp) {
            var now = Date.now();
            var expirationMs = expirationHours * 60 * 60 * 1000;

            // Check if cached data is still valid
            if (now - userData.location.timestamp < expirationMs) {
              GA4Utils.helpers.log(
                "Using cached location data from centralized storage",
                userData.location,
                {},
                "[Location Utils]"
              );
              resolve(userData.location);
              return;
            } else {
              GA4Utils.helpers.log(
                "Cached location data expired, fetching fresh data",
                null,
                {},
                "[Location Utils]"
              );
            }
          }

          // Fetch fresh location data
          this.fetch()
            .then((ipLocationData) => {
              // Store in centralized storage
              userData.location = ipLocationData;
              GA4Utils.storage.saveUserData(userData);
              resolve(ipLocationData);
            })
            .catch((err) => {
              GA4Utils.helpers.log(
                "IP location error",
                err,
                {},
                "[Location Utils]"
              );
              resolve({});
            });
        });
      },

      /**
       * Fetch fresh location data from IP geolocation services
       * @returns {Promise<Object>}
       */
      fetch: function () {
        return new Promise((resolve, reject) => {
          GA4Utils.helpers.log(
            "Fetching fresh location data",
            null,
            {},
            "[Location Utils]"
          );

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
                timestamp: Date.now(), // Add timestamp
              };

              // Cache the fresh data
              this.cache(locationData);
              GA4Utils.helpers.log(
                "Location obtained from ipapi.co",
                locationData,
                {},
                "[Location Utils]"
              );
              resolve(locationData);
            })
            .catch((error) => {
              GA4Utils.helpers.log(
                "First IP location service failed, trying fallback",
                error,
                {},
                "[Location Utils]"
              );

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
                    timestamp: Date.now(), // Add timestamp
                  };

                  // Cache the fresh data
                  this.cache(locationData);
                  GA4Utils.helpers.log(
                    "Location obtained from fallback service",
                    locationData,
                    {},
                    "[Location Utils]"
                  );
                  resolve(locationData);
                })
                .catch((secondError) => {
                  GA4Utils.helpers.log(
                    "Second IP location service failed, trying final fallback",
                    secondError,
                    {},
                    "[Location Utils]"
                  );

                  // Final fallback to geoiplookup.io
                  fetch("https://json.geoiplookup.io/")
                    .then((response) => {
                      if (!response.ok)
                        throw new Error("geoiplookup.io failed");
                      return response.json();
                    })
                    .then((data) => {
                      const locationData = {
                        latitude: parseFloat(data.latitude),
                        longitude: parseFloat(data.longitude),
                        city: data.city || "",
                        region: data.region || "",
                        country: data.country_name || "",
                        timestamp: Date.now(), // Add timestamp
                      };

                      // Cache the fresh data
                      this.cache(locationData);
                      GA4Utils.helpers.log(
                        "Location obtained from final fallback service",
                        locationData,
                        {},
                        "[Location Utils]"
                      );
                      resolve(locationData);
                    })
                    .catch((finalError) => {
                      GA4Utils.helpers.log(
                        "All IP location services failed",
                        finalError,
                        {},
                        "[Location Utils]"
                      );
                      resolve({});
                    });
                });
            });
        });
      },

      /**
       * Cache location data in centralized storage
       * @param {Object} locationData Location data to cache
       */
      cache: function (locationData) {
        if (!locationData.timestamp) {
          locationData.timestamp = Date.now();
        }

        try {
          var userData = GA4Utils.storage.getUserData();
          userData.location = locationData;
          GA4Utils.storage.saveUserData(userData);
          
          GA4Utils.helpers.log(
            "Location data cached successfully in centralized storage",
            null,
            {},
            "[Location Utils]"
          );
        } catch (e) {
          GA4Utils.helpers.log(
            "Error caching location data",
            e,
            {},
            "[Location Utils]"
          );
        }
      },

      /**
       * Get cached location data without fetching fresh data
       * @returns {Object|null}
       */
      getCached: function () {
        var userData = GA4Utils.storage.getUserData();
        var expirationHours = GA4Utils.storage.getExpirationHours();

        if (userData.location && userData.location.timestamp) {
          var now = Date.now();
          var expirationMs = expirationHours * 60 * 60 * 1000;

          // Check if still valid
          if (now - userData.location.timestamp < expirationMs) {
            return userData.location;
          } else {
            // Expired
            this.clearCache();
            return null;
          }
        }

        return null;
      },

      /**
       * Clear cached location data
       */
      clearCache: function () {
        var userData = GA4Utils.storage.getUserData();
        userData.location = null;
        GA4Utils.storage.saveUserData(userData);
        
        GA4Utils.helpers.log(
          "Location cache cleared from centralized storage",
          null,
          {},
          "[Location Utils]"
        );
      },

      /**
       * Check if cached location data exists and is valid
       * @returns {boolean}
       */
      hasCachedData: function () {
        return this.getCached() !== null;
      },

      /**
       * Get location data age in milliseconds
       * @returns {number|null}
       */
      getCacheAge: function () {
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
      refresh: function () {
        this.clearCache();
        return this.fetch();
      },
    },
    /**
     * MODIFICATIONS FOR GA4 SERVER-SIDE TAGGING FILES
     * These functions should be added/modified in your existing files
     */

    // ============================================================================
    // 1. ADD TO GA4Utils (paste-2.txt) - Add to the GA4Utils object
    // ============================================================================

    /**
     * Bot Detection Utilities - Add this to your GA4Utils object
     */
    botDetection: {
      /**
       * Comprehensive bot detection - returns true if traffic should be filtered
       */
      isBot: function (userAgentInfo, sessionParams, clientBehavior = {}) {
        const checks = [
          this.checkUserAgent(userAgentInfo.user_agent),
          this.checkSuspiciousGeoLocation(sessionParams),
          this.checkBehaviorPatterns(clientBehavior, sessionParams),
          this.checkWebDriver(userAgentInfo),
          this.checkHeadlessBrowser(userAgentInfo),
          this.checkKnownBotIPs(sessionParams.client_ip),
          this.checkSuspiciousReferrers(sessionParams.page_referrer),
        ];

        return checks.some((check) => check === true);
      },

      /**
       * User Agent based bot detection
       */
      checkUserAgent: function (userAgent) {
        if (!userAgent) return true;

        const botPatterns = [
          /bot/i,
          /crawl/i,
          /spider/i,
          /scraper/i,
          /googlebot/i,
          /bingbot/i,
          /yahoo/i,
          /duckduckbot/i,
          /baiduspider/i,
          /yandexbot/i,
          /sogou/i,
          /facebookexternalhit/i,
          /twitterbot/i,
          /linkedinbot/i,
          /whatsapp/i,
          /telegrambot/i,
          /semrushbot/i,
          /ahrefsbot/i,
          /mj12bot/i,
          /dotbot/i,
          /screaming frog/i,
          /seobility/i,
          /headlesschrome/i,
          /phantomjs/i,
          /slimerjs/i,
          /htmlunit/i,
          /selenium/i,
          /pingdom/i,
          /uptimerobot/i,
          /statuscake/i,
          /site24x7/i,
          /newrelic/i,
          /python/i,
          /requests/i,
          /curl/i,
          /wget/i,
          /apache-httpclient/i,
          /java/i,
          /okhttp/i,
          /^mozilla\/5\.0$/i,
          /compatible;?\s*$/i,
          /chrome-lighthouse/i,
          /pagespeed/i,
        ];

        return botPatterns.some((pattern) => pattern.test(userAgent));
      },

      /**
       * Geographic location based detection
       */
      checkSuspiciousGeoLocation: function (sessionParams) {
        const suspiciousLocations = [
          { city: "mountain view", country: "us" },
          { city: "charlotte", country: "us" },
          { city: "ashburn", country: "us" },
          { city: "santa clara", country: "us" },
          { city: "palo alto", country: "us" },
          { city: "menlo park", country: "us" },
          { city: "fremont", country: "us" },
          { city: "sunnyvale", country: "us" },
          { city: "dublin", country: "ie" },
          { city: "oregon", country: "us" },
          { city: "virginia", country: "us" },
          { city: "seoul", country: "kr" },
          { city: "singapore", country: "sg" },
          { city: "mumbai", country: "in" },
          { city: "frankfurt", country: "de" },
          { city: "london", country: "gb" },
        ];

        const userCity = (sessionParams.geo_city || "").toLowerCase();
        const userCountry = (sessionParams.geo_country || "").toLowerCase();

        if (!userCity || !userCountry) return false;

        return suspiciousLocations.some(
          (loc) => loc.city === userCity && loc.country === userCountry
        );
      },

      /**
       * Behavior pattern detection
       */
      checkBehaviorPatterns: function (clientBehavior, sessionParams) {
        const suspiciousPatterns = [];

        if (sessionParams.engagement_time_msec < 1000) {
          suspiciousPatterns.push("short_engagement");
        }

        if (clientBehavior.hasJavaScript === false) {
          suspiciousPatterns.push("no_javascript");
        }

        const resolution = sessionParams.screen_resolution || "";
        const botResolutions = ["1024x768", "1366x768", "1920x1080", "800x600"];
        if (botResolutions.includes(resolution)) {
          suspiciousPatterns.push("bot_resolution");
        }

        const timestamp = sessionParams.event_timestamp;
        if (timestamp && timestamp % 10 === 0) {
          suspiciousPatterns.push("round_timestamp");
        }

        return suspiciousPatterns.length >= 2;
      },

      /**
       * WebDriver detection
       */
      checkWebDriver: function (userAgentInfo) {
        return (
          /webdriver/i.test(userAgentInfo.user_agent) ||
          /automation/i.test(userAgentInfo.user_agent)
        );
      },

      /**
       * Headless browser detection
       */
      checkHeadlessBrowser: function (userAgentInfo) {
        const headlessPatterns = [
          /headless/i,
          /phantomjs/i,
          /slimerjs/i,
          /htmlunit/i,
        ];

        return headlessPatterns.some((pattern) =>
          pattern.test(userAgentInfo.user_agent)
        );
      },

      /**
       * Known bot IP ranges
       */
      checkKnownBotIPs: function (clientIP) {
        if (!clientIP) return false;

        const botIPRanges = [
          "66.249.",
          "64.233.",
          "72.14.",
          "74.125.",
          "209.85.",
          "216.239.",
          "40.77.",
          "207.46.",
          "65.52.",
          "54.",
          "3.",
          "18.",
          "52.",
        ];

        return botIPRanges.some((range) => clientIP.startsWith(range));
      },

      /**
       * Suspicious referrer detection
       */
      checkSuspiciousReferrers: function (referrer) {
        if (!referrer) return false;

        const suspiciousReferrers = [
          /semalt\.com/i,
          /darodar\.com/i,
          /savetubevideo\.com/i,
          /kambasoft\.com/i,
          /gobongo\.info/i,
          /googlebot\.com/i,
          /crawl-66-249/i,
          /uptimerobot\.com/i,
          /pingdom\.com/i,
        ];

        return suspiciousReferrers.some((pattern) => pattern.test(referrer));
      },

      /**
       * Get client behavior data for bot detection
       */
      getClientBehaviorData: function () {
        const behaviorData = {
          hasJavaScript: true,
          screenAvailWidth: screen.availWidth,
          screenAvailHeight: screen.availHeight,
          colorDepth: screen.colorDepth,
          pixelDepth: screen.pixelDepth,
          timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
          language: navigator.language,
          languages: navigator.languages ? navigator.languages.join(",") : "",
          platform: navigator.platform,
          cookieEnabled: navigator.cookieEnabled,
          doNotTrack: navigator.doNotTrack,
          hardwareConcurrency: navigator.hardwareConcurrency,
          maxTouchPoints: navigator.maxTouchPoints || 0,
          webdriver: navigator.webdriver || false,
          hasAutomationIndicators:
            window.navigator.webdriver ||
            window.callPhantom ||
            window._phantom ||
            window.__nightmare ||
            window.Buffer ||
            window.emit ||
            window.spawn ||
            false,
          pageLoadTime: performance.timing
            ? performance.timing.loadEventEnd -
              performance.timing.navigationStart
            : 0,
          hasInteracted: this.getUserInteractionState(),
        };

        return behaviorData;
      },

      /**
       * Track user interaction state
       */
      getUserInteractionState: function () {
        if (!window._userHasInteracted) {
          window._userHasInteracted = false;

          ["click", "keydown", "scroll", "touchstart"].forEach((event) => {
            document.addEventListener(
              event,
              function () {
                window._userHasInteracted = true;
              },
              { once: true, passive: true }
            );
          });
        }

        return window._userHasInteracted;
      },

      /**
       * Calculate bot probability score (0-100)
       */
      calculateBotScore: function (userAgentInfo, clientBehavior) {
        let score = 0;

        if (this.checkUserAgent(userAgentInfo.user_agent)) score += 40;
        if (this.checkWebDriver(userAgentInfo)) score += 30;
        if (this.checkHeadlessBrowser(userAgentInfo)) score += 35;
        if (!clientBehavior.hasJavaScript) score += 25;
        if (clientBehavior.hasAutomationIndicators) score += 30;
        if (!clientBehavior.cookieEnabled) score += 15;
        if (clientBehavior.pageLoadTime < 100) score += 20;
        if (!clientBehavior.hasInteracted) score += 10;

        return Math.min(score, 100);
      },
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
      getType: function (source, medium, referrerDomain) {
        // Payment provider traffic - check if referrer is a payment provider
        if (referrerDomain && this.isPaymentProvider(referrerDomain)) {
          return "payment_referrer";
        }

        // Internal traffic - check if referrer domain matches current domain
        if (referrerDomain && referrerDomain === window.location.hostname) {
          return "internal";
        }

        // Direct traffic
        if (source === "(direct)" && medium === "none") {
          return "direct";
        }

        // Organic search
        if (medium === "organic") {
          return "organic";
        }

        // Paid search
        if (medium === "cpc" || medium === "ppc" || medium === "paidsearch") {
          return "paid_search";
        }

        // Social media
        if (
          medium === "social" ||
          medium === "sm" ||
          source === "facebook" ||
          source === "instagram" ||
          source === "twitter" ||
          source === "linkedin" ||
          source === "pinterest" ||
          source === "youtube"
        ) {
          return "social";
        }

        // Email marketing
        if (medium === "email") {
          return "email";
        }

        // Affiliates
        if (medium === "affiliate") {
          return "affiliate";
        }

        // Regular referral traffic (links from other sites)
        if (medium === "referral") {
          return "referral";
        }

        // Display or banner ads
        if (medium === "display" || medium === "banner" || medium === "cpm") {
          return "display";
        }

        // Video ads
        if (medium === "video") {
          return "video";
        }

        // Default fallback
        return "other";
      },

      // Helper function to identify payment providers
      isPaymentProvider: function (domain) {
        const paymentProviders = [
          // PayPal
          "paypal.com",
          "paypal.me",
          "paypalobjects.com",

          // Stripe
          "stripe.com",
          "checkout.stripe.com",

          // Square
          "squareup.com",
          "square.com",
          "cash.app",

          // Apple Pay
          "apple.com",
          "icloud.com",

          // Google Pay
          "pay.google.com",
          "payments.google.com",

          // Amazon Pay
          "payments.amazon.com",
          "amazon.com",

          // Other major payment providers
          "checkout.com",
          "adyen.com",
          "worldpay.com",
          "authorize.net",
          "braintreepayments.com",
          "razorpay.com",
          "payu.com",
          "mollie.com",
          "klarna.com",
          "afterpay.com",
          "affirm.com",
          "sezzle.com",
          "zip.co",
          "laybuy.com",
          "paymi.com",
          "venmo.com",
          "zelle.com",
          "wise.com",
          "remitly.com",
          "xoom.com",
        ];

        // Check if the domain matches any payment provider
        return paymentProviders.some((provider) => {
          // Check exact match or subdomain match
          return domain === provider || domain.endsWith("." + provider);
        });
      },
    },

    consent: {
      /**
       * Check if user has given analytics consent
       */
      hasAnalyticsConsent: function () {
        if (window.GA4ConsentManager && typeof window.GA4ConsentManager.isAnalyticsAllowed === 'function') {
          return window.GA4ConsentManager.isAnalyticsAllowed();
        }
        
        // Fallback to checking global status
        var consent = window.GA4ConsentStatus;
        return consent && consent.analytics_storage === "GRANTED";
      },

      /**
       * Check if user has given advertising consent
       */
      hasAdvertisingConsent: function () {
        if (window.GA4ConsentManager && typeof window.GA4ConsentManager.isAdvertisingAllowed === 'function') {
          return window.GA4ConsentManager.isAdvertisingAllowed();
        }
        
        // Fallback to checking global status
        var consent = window.GA4ConsentStatus;
        return consent && consent.ad_storage === "GRANTED";
      },

      /**
       * Get consent mode
       */
      getMode: function () {
        if (window.GA4ConsentManager && typeof window.GA4ConsentManager.getConsentMode === 'function') {
          return window.GA4ConsentManager.getConsentMode();
        }
        
        // Fallback implementation
        var consent = window.GA4ConsentStatus;
        if (!consent) {
          return "UNKNOWN";
        }

        if (
          consent.analytics_storage === "GRANTED" &&
          consent.ad_storage === "GRANTED"
        ) {
          return "GRANTED";
        } else if (
          consent.analytics_storage === "DENIED" &&
          consent.ad_storage === "DENIED"
        ) {
          return "DENIED";
        } else {
          return "PARTIAL";
        }
      },

      /**
       * Get consent data for server-side events
       */
      getForServerSide: function () {
        if (window.GA4ConsentManager && typeof window.GA4ConsentManager.getConsentForServerSide === 'function') {
          return window.GA4ConsentManager.getConsentForServerSide();
        }
        
        // Fallback implementation
        var consent = window.GA4ConsentStatus;

        if (!consent) {
          return {
            analytics_storage: "DENIED", // Default to DENIED for GDPR compliance
            ad_storage: "DENIED",
            ad_user_data: "DENIED",
            ad_personalization: "DENIED",
            functionality_storage: "DENIED",
            personalization_storage: "DENIED",
            security_storage: "GRANTED",
            consent_mode: "DENIED",
            consent_timestamp: null,
          };
        }

        return {
          analytics_storage: consent.analytics_storage || "DENIED",
          ad_storage: consent.ad_storage || "DENIED",
          ad_user_data: consent.ad_user_data || "DENIED",
          ad_personalization: consent.ad_personalization || "DENIED",
          functionality_storage: consent.functionality_storage || "DENIED",
          personalization_storage: consent.personalization_storage || "DENIED",
          security_storage: consent.security_storage || "GRANTED",
          consent_mode: this.getMode(),
          consent_timestamp: consent.timestamp,
        };
      },

      /**
       * Check if we should track user data
       */
      shouldTrackUserData: function() {
        return this.hasAnalyticsConsent();
      },

      /**
       * Check if we should track advertising data
       */
      shouldTrackAdvertisingData: function() {
        return this.hasAdvertisingConsent();
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
      calculateEngagementTime: function (startTime) {
        var currentTime = Date.now();
        var engagementTime = currentTime - (startTime || currentTime);

        // Ensure minimum engagement time of 1000ms for GA4 compatibility
        // and maximum of 30 minutes (1800000ms) to prevent unrealistic values
        return Math.max(1000, Math.min(engagementTime, 1800000));
      },
    },

    /**
     * Page Detection and Purchase Tracking Utilities
     */
    page: {
      /**
       * Check if we're on an order confirmation page
       * @param {Object} config Configuration object
       * @param {string} trackingType Type of tracking ('ga4' or 'google_ads')
       * @returns {boolean}
       */
      isOrderConfirmationPage: function (config, trackingType) {
        trackingType = trackingType || "ga4"; // Default to GA4

        // First check if this is actually an order confirmation page
        var isOrderPage = this._checkOrderPageCriteria(config);

        return isOrderPage;
      },

      /**
       * Internal method to check if page meets order confirmation criteria
       * @param {Object} config Configuration object
       * @returns {boolean}
       * @private
       */
      _checkOrderPageCriteria: function (config) {
        // Check config value
        if (config && config.isThankYouPage === true) {
          return true;
        }

        // Check URL patterns
        var isUrlMatch =
          window.location.href.indexOf("/checkout/order-received/") > -1 ||
          window.location.href.indexOf("/inschrijven/order-received/") > -1 ||
          window.location.href.indexOf("/order-pay/") > -1 ||
          window.location.href.indexOf("/thank-you/") > -1;

        if (isUrlMatch) {
          return true;
        }

        // Check for WooCommerce body class
        var hasWooClass =
          document.body.classList.contains("woocommerce-order-received") ||
          (document.body.classList.contains("woocommerce-checkout") &&
            document.querySelector(".woocommerce-order-overview") !== null);

        if (hasWooClass) {
          return true;
        }

        // Check for order ID in URL parameters
        var urlParams = new URLSearchParams(window.location.search);
        var hasOrderParam = urlParams.has("order") || urlParams.has("order_id");

        if (hasOrderParam) {
          return true;
        }

        // Check for thank you page elements
        var hasThankYouElements =
          document.querySelector(".woocommerce-thankyou-order-details") !==
            null ||
          document.querySelector(".woocommerce-order-received") !== null ||
          document.querySelector(".woocommerce-notice--success") !== null;

        return hasThankYouElements;
      },

      /**
       * Check if purchase should be tracked based on tracking type and previous tracking
       * @param {string} trackingType Type of tracking ('ga4' or 'google_ads')
       * @returns {boolean}
       */
      shouldTrackPurchase: function (trackingType) {
        trackingType = trackingType || "ga4";

        // First check if this is actually an order confirmation page
        var isOrderPage = this.isOrderConfirmationPage({}, trackingType);

        if (!isOrderPage) {
          GA4Utils.helpers.log(
            "Not an order confirmation page - skipping purchase tracking",
            null,
            {},
            "[Purchase Tracking]"
          );
          return false;
        }

        // Extract order ID from various sources
        var orderId = this.extractOrderId();

        if (!orderId) {
          GA4Utils.helpers.log(
            "No order ID found - cannot track purchase",
            null,
            {},
            "[Purchase Tracking]"
          );
          return false;
        }

        // Check if this order has already been tracked for this tracking type
        var hasBeenTracked = this.hasOrderBeenTracked(orderId, trackingType);

        if (hasBeenTracked) {
          GA4Utils.helpers.log(
            "Order " +
              orderId +
              " has already been tracked for " +
              trackingType,
            null,
            {},
            "[Purchase Tracking]"
          );
          return false;
        }

        GA4Utils.helpers.log(
          "Order " + orderId + " should be tracked for " + trackingType,
          { orderId: orderId, trackingType: trackingType },
          {},
          "[Purchase Tracking]"
        );

        return true;
      },

      /**
       * Extract order ID from various sources
       * @returns {string|null}
       */
      extractOrderId: function () {
        var orderId = null;

        // Method 1: Check URL parameters
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has("order")) {
          orderId = urlParams.get("order");
        } else if (urlParams.has("order_id")) {
          orderId = urlParams.get("order_id");
        }

        // Method 2: Check URL path for WooCommerce order-received pattern
        if (!orderId) {
          var orderIdMatch = window.location.pathname.match(
            /order-received\/(\d+)/
          );
          if (orderIdMatch && orderIdMatch[1]) {
            orderId = orderIdMatch[1];
          }
        }

        // Method 3: Check for order ID in page content
        if (!orderId) {
          var orderElements = document.querySelectorAll(
            ".woocommerce-order-overview__order .woocommerce-order-overview__order-number, " +
              ".woocommerce-thankyou-order-details .woocommerce-order-overview__order-number, " +
              ".order-number, " +
              ".order_details .order-number"
          );

          for (var i = 0; i < orderElements.length; i++) {
            var orderText =
              orderElements[i].textContent || orderElements[i].innerText;
            var orderMatch = orderText.match(/\d+/);
            if (orderMatch) {
              orderId = orderMatch[0];
              break;
            }
          }
        }

        // Method 4: Check for order ID in scripts or data attributes
        if (!orderId) {
          var orderDataElements = document.querySelectorAll("[data-order-id]");
          if (orderDataElements.length > 0) {
            orderId = orderDataElements[0].getAttribute("data-order-id");
          }
        }

        // Method 5: Check for order ID in hidden form fields
        if (!orderId) {
          var hiddenOrderInput = document.querySelector(
            'input[name="order_id"], input[name="order-id"]'
          );
          if (hiddenOrderInput) {
            orderId = hiddenOrderInput.value;
          }
        }

        // Clean and validate order ID
        if (orderId) {
          orderId = orderId.toString().trim();
          // Ensure it's a valid order ID (contains at least one digit)
          if (!/\d/.test(orderId)) {
            orderId = null;
          }
        }

        GA4Utils.helpers.log(
          "Extracted order ID: " + (orderId || "none"),
          { orderId: orderId },
          {},
          "[Purchase Tracking]"
        );

        return orderId;
      },

      /**
       * Check if an order has been tracked for a specific tracking type
       * @param {string} orderId Order ID to check
       * @param {string} trackingType Type of tracking ('ga4' or 'google_ads')
       * @returns {boolean}
       */
      hasOrderBeenTracked: function (orderId, trackingType) {
        if (!orderId || !trackingType) {
          return false;
        }

        var storageKey = "purchase_tracked_" + trackingType + "_" + orderId;
        var trackedData = localStorage.getItem(storageKey);

        if (!trackedData) {
          return false;
        }

        try {
          var data = JSON.parse(trackedData);
          var now = Date.now();
          var thirtyMinutes = 30 * 60 * 1000; // 30 minutes in milliseconds

          // Check if the tracking record is still valid (within 30 minutes)
          if (data.timestamp && now - data.timestamp < thirtyMinutes) {
            return true;
          } else {
            // Expired, remove it
            localStorage.removeItem(storageKey);
            GA4Utils.helpers.log(
              "Expired purchase tracking record removed: " + storageKey,
              null,
              {},
              "[Purchase Tracking]"
            );
            return false;
          }
        } catch (e) {
          // Invalid data, remove it
          localStorage.removeItem(storageKey);
          GA4Utils.helpers.log(
            "Invalid purchase tracking record removed: " + storageKey,
            null,
            {},
            "[Purchase Tracking]"
          );
          return false;
        }
      },

      /**
       * Mark an order as tracked for a specific tracking type
       * @param {string} orderId Order ID to mark as tracked
       * @param {string} trackingType Type of tracking ('ga4' or 'google_ads')
       * @param {Object} additionalData Additional data to store with the tracking record
       */
      markOrderAsTracked: function (orderId, trackingType, additionalData) {
        if (!orderId || !trackingType) {
          GA4Utils.helpers.log(
            "Cannot mark order as tracked - missing orderId or trackingType",
            { orderId: orderId, trackingType: trackingType },
            {},
            "[Purchase Tracking]"
          );
          return;
        }

        var storageKey = "purchase_tracked_" + trackingType + "_" + orderId;
        var trackingData = {
          orderId: orderId,
          trackingType: trackingType,
          timestamp: Date.now(),
          url: window.location.href,
          ...(additionalData || {}),
        };

        try {
          localStorage.setItem(storageKey, JSON.stringify(trackingData));
          GA4Utils.helpers.log(
            "Marked order as tracked: " + orderId + " for " + trackingType,
            trackingData,
            {},
            "[Purchase Tracking]"
          );
        } catch (e) {
          GA4Utils.helpers.log(
            "Error marking order as tracked",
            { error: e, orderId: orderId, trackingType: trackingType },
            {},
            "[Purchase Tracking]"
          );
        }
      },

      /**
       * Enhanced order confirmation page detection with purchase tracking logic
       * @param {Object} config Configuration object
       * @param {string} trackingType Type of tracking ('ga4' or 'google_ads')
       * @returns {boolean}
       */
      isOrderConfirmationPageWithTracking: function (config, trackingType) {
        trackingType = trackingType || "ga4";

        // First check if this is actually an order confirmation page
        var isOrderPage = this.isOrderConfirmationPage(config, trackingType);

        if (!isOrderPage) {
          return false;
        }

        // If it's an order page, check if we should track it
        return this.shouldTrackPurchase(trackingType);
      },

      /**
       * Safe purchase tracking method that prevents duplicates
       * @param {Function} trackingCallback Function to call for actual tracking
       * @param {string} trackingType Type of tracking ('ga4' or 'google_ads')
       * @param {Object} orderData Order data to track
       * @param {Object} additionalData Additional data to store with tracking record
       * @returns {boolean} True if tracking was executed, false if skipped
       */
      trackPurchaseSafely: function (
        trackingCallback,
        trackingType,
        orderData,
        additionalData
      ) {
        if (!this.shouldTrackPurchase(trackingType)) {
          return false;
        }

        var orderId = orderData.transaction_id || this.extractOrderId();

        if (!orderId) {
          GA4Utils.helpers.log(
            "Cannot track purchase - no order ID available",
            orderData,
            {},
            "[Purchase Tracking]"
          );
          return false;
        }

        try {
          // Execute the tracking callback
          if (typeof trackingCallback === "function") {
            trackingCallback(orderData);

            // Mark as tracked after successful execution
            this.markOrderAsTracked(orderId, trackingType, {
              value: orderData.value,
              currency: orderData.currency,
              items_count: orderData.items ? orderData.items.length : 0,
              ...(additionalData || {}),
            });

            GA4Utils.helpers.log(
              "Successfully tracked purchase for order: " + orderId,
              { trackingType: trackingType, orderData: orderData },
              {},
              "[Purchase Tracking]"
            );

            return true;
          } else {
            GA4Utils.helpers.log(
              "Invalid tracking callback provided",
              { trackingType: trackingType },
              {},
              "[Purchase Tracking]"
            );
            return false;
          }
        } catch (error) {
          GA4Utils.helpers.log(
            "Error executing purchase tracking",
            { error: error, orderId: orderId, trackingType: trackingType },
            {},
            "[Purchase Tracking]"
          );
          return false;
        }
      },

      /**
       * Get all tracked purchases (for debugging)
       * @returns {Array}
       */
      getTrackedPurchases: function () {
        var trackedPurchases = [];

        if (!window.localStorage) {
          return trackedPurchases;
        }

        for (var i = 0; i < localStorage.length; i++) {
          var key = localStorage.key(i);

          if (key && key.startsWith("purchase_tracked_")) {
            try {
              var data = JSON.parse(localStorage.getItem(key));
              trackedPurchases.push({
                key: key,
                data: data,
              });
            } catch (e) {
              // Invalid data, skip it
            }
          }
        }

        return trackedPurchases;
      },

      /**
       * Clear all purchase tracking data (for debugging/testing)
       * @param {string} trackingType Optional - specific tracking type to clear
       */
      clearAllPurchaseTracking: function (trackingType) {
        if (!window.localStorage) {
          return;
        }

        var keysToRemove = [];
        var prefix = trackingType
          ? "purchase_tracked_" + trackingType + "_"
          : "purchase_tracked_";

        for (var i = 0; i < localStorage.length; i++) {
          var key = localStorage.key(i);

          if (key && key.startsWith(prefix)) {
            keysToRemove.push(key);
          }
        }

        keysToRemove.forEach(function (key) {
          localStorage.removeItem(key);
        });

        GA4Utils.helpers.log(
          "Cleared " +
            keysToRemove.length +
            " purchase tracking records" +
            (trackingType ? " for " + trackingType : ""),
          null,
          {},
          "[Purchase Tracking]"
        );
      },

      /**
       * Check if we're on a product list page
       * @returns {boolean}
       */
      isProductListPage: function () {
        return (
          $(".woocommerce .products").length &&
          !$(".woocommerce-product-gallery").length
        );
      },

      /**
       * Check if we're on a single product page
       * @param {Object} config Configuration object
       * @returns {boolean}
       */
      isProductPage: function (config) {
        return config && config.productData ? true : false;
      },

      /**
       * Reset purchase tracking for testing purposes
       * @param {string} orderId Order ID to reset
       * @param {string} trackingType Optional tracking type to reset ('ga4', or 'all')
       */
      resetPurchaseTracking: function (orderId, trackingType) {
        trackingType = trackingType || "all";

        if (trackingType === "all" || trackingType === "ga4") {
          localStorage.removeItem("purchase_tracked_ga4_" + orderId);
        }

        GA4Utils.helpers.log(
          "Reset purchase tracking for order: " +
            orderId +
            ", type: " +
            trackingType,
          null,
          {},
          "[Purchase Tracking]"
        );
      },

      /**
       * Manually clean up all expired purchase tracking data
       * Can be called independently for maintenance
       */
      cleanupExpiredPurchaseTracking: function () {
        GA4Utils.session._clearExpiredPurchaseTracking();
      },

      /**
       * Manually clean up expired user data
       * Can be called independently for maintenance
       * @param {number} customExpirationHours Optional custom expiration time in hours
       */
      cleanupExpiredUserData: function (customExpirationHours) {
        GA4Utils.session._clearExpiredUserData(customExpirationHours);
      },

      /**
       * Clean up all expired data (comprehensive user data cleanup)
       * This method replaces cleanupExpiredLocationData and cleanupExpiredPurchaseTracking
       * @param {number} customExpirationHours Optional custom expiration time in hours
       */
      cleanupAllExpiredData: function (customExpirationHours) {
        GA4Utils.session._clearExpiredUserData(customExpirationHours);
      },

      /**
       * @deprecated Use cleanupExpiredUserData() instead
       * Backward compatibility alias for cleanupExpiredLocationData
       */
      cleanupExpiredLocationData: function () {
        this.cleanupExpiredUserData();
      },
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
      sendPayload: function (endpoint, payload, config, logPrefix) {
        logPrefix = logPrefix || "[GA4Utils AJAX]";

        return new Promise(function (resolve, reject) {
          $.ajax({
            url: endpoint,
            type: "POST",
            data: JSON.stringify(payload),
            contentType: "application/json",
            beforeSend: function (xhr) {
              // Add nonce for WordPress REST API
              if (
                config &&
                config.apiEndpoint &&
                endpoint === config.apiEndpoint
              ) {
                xhr.setRequestHeader("X-WP-Nonce", config.nonce || "");
              }
              GA4Utils.helpers.log(
                "Sending AJAX request to: " + endpoint,
                null,
                config,
                logPrefix
              );
            },
            success: function (response) {
              GA4Utils.helpers.log(
                "AJAX request sent successfully",
                response,
                config,
                logPrefix
              );
              resolve(response);
            },
            error: function (xhr, status, error) {
              GA4Utils.helpers.log(
                "Error sending AJAX request: " + error,
                xhr,
                config,
                logPrefix
              );
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
      sendPayloadFetch: function (endpoint, payload, config, logPrefix) {
        logPrefix = logPrefix || "[GA4Utils Fetch]";

        var headers = {
          "Content-Type": "application/json",
        };

        // Add nonce for WordPress REST API
        if (config && config.apiEndpoint && endpoint === config.apiEndpoint) {
          headers["X-WP-Nonce"] = config.nonce || "";
        }

        GA4Utils.helpers.log(
          "Sending Fetch request to: " + endpoint,
          null,
          config,
          logPrefix
        );

        return fetch(endpoint, {
          method: "POST",
          headers: headers,
          body: JSON.stringify(payload),
        })
          .then(function (response) {
            if (!response.ok) {
              throw new Error(
                "Network response was not ok: " + response.status
              );
            }
            return response.json();
          })
          .then(function (data) {
            GA4Utils.helpers.log(
              "Fetch request sent successfully",
              data,
              config,
              logPrefix
            );
            return data;
          })
          .catch(function (error) {
            GA4Utils.helpers.log(
              "Error sending Fetch request",
              error,
              config,
              logPrefix
            );
            console.error(logPrefix + " Error sending request:", error);
            throw error;
          });
      },
    },

    /**
     * Helper Utilities
     */
    helpers: {
      /**
       * Generate unique ID
       * @returns {string}
       */
      generateUniqueId: function () {
        return Date.now().toString();
      },

      /**
       * Log messages if debug mode is enabled
       * @param {string} message Log message
       * @param {*} data Optional data to log
       * @param {Object} config Configuration object
       * @param {string} prefix Log prefix
       */
      log: function (message, data, config, prefix) {
        prefix = prefix || "[GA4 Utils]";
        if (config && config.debugMode && window.console) {
          if (data) {
            console.log(prefix + " " + message, data);
          } else {
            console.log(prefix + " " + message);
          }
        }
      },

      /**
       * Get social media platform from URL
       * @param {string} url URL to check
       * @returns {string}
       */
      getSocialPlatform: function (url) {
        if (url.indexOf("facebook.com") > -1) return "Facebook";
        if (url.indexOf("twitter.com") > -1) return "Twitter";
        if (url.indexOf("linkedin.com") > -1) return "LinkedIn";
        if (url.indexOf("instagram.com") > -1) return "Instagram";
        if (url.indexOf("youtube.com") > -1) return "YouTube";
        if (url.indexOf("tiktok.com") > -1) return "TikTok";
        return "Other";
      },

      /**
       * Debounce function calls
       * @param {Function} func Function to debounce
       * @param {number} wait Wait time in milliseconds
       * @param {boolean} immediate Execute immediately on first call
       * @returns {Function}
       */
      debounce: function (func, wait, immediate) {
        var timeout;
        return function () {
          var context = this,
            args = arguments;
          var later = function () {
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
      throttle: function (func, limit) {
        var inThrottle;
        return function () {
          var args = arguments;
          var context = this;
          if (!inThrottle) {
            func.apply(context, args);
            inThrottle = true;
            setTimeout(function () {
              inThrottle = false;
            }, limit);
          }
        };
      },

      /**
       * Check if element is in viewport
       * @param {Element} element DOM element to check
       * @param {number} threshold Threshold percentage (0-1)
       * @returns {boolean}
       */
      isInViewport: function (element, threshold) {
        threshold = threshold || 0.5;

        if (!element || typeof element.getBoundingClientRect !== "function") {
          return false;
        }

        var rect = element.getBoundingClientRect();
        var windowHeight =
          window.innerHeight || document.documentElement.clientHeight;
        var windowWidth =
          window.innerWidth || document.documentElement.clientWidth;

        var verticalVisible =
          rect.top + rect.height * threshold <= windowHeight &&
          rect.bottom - rect.height * threshold >= 0;
        var horizontalVisible =
          rect.left + rect.width * threshold <= windowWidth &&
          rect.right - rect.width * threshold >= 0;

        return verticalVisible && horizontalVisible;
      },

      /**
       * Get cookie value by name
       * @param {string} name Cookie name
       * @returns {string|null}
       */
      getCookie: function (name) {
        var nameEQ = name + "=";
        var ca = document.cookie.split(";");
        for (var i = 0; i < ca.length; i++) {
          var c = ca[i];
          while (c.charAt(0) === " ") c = c.substring(1, c.length);
          if (c.indexOf(nameEQ) === 0)
            return c.substring(nameEQ.length, c.length);
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
      setCookie: function (name, value, days, path, domain) {
        var expires = "";
        if (days) {
          var date = new Date();
          date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
          expires = "; expires=" + date.toUTCString();
        }

        var cookieString =
          name + "=" + (value || "") + expires + "; path=" + (path || "/");
        if (domain) {
          cookieString += "; domain=" + domain;
        }

        document.cookie = cookieString;
      },

      /**
       * Get user timezone
       * @returns {string} Timezone identifier (e.g., "Europe/Amsterdam")
       */
      getTimezone: function() {
        try {
          return Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch (e) {
          console.log("Error getting timezone:", e);
          return "";
        }
      },

      /**
       * Get continent from timezone
       * @param {string} timezone Timezone identifier
       * @returns {string} Continent name
       */
      getContinentFromTimezone: function(timezone) {
        if (!timezone) return "";
        try {
          var timezoneRegions = timezone.split("/");
          return timezoneRegions.length > 0 ? timezoneRegions[0] : "";
        } catch (e) {
          console.log("Error extracting continent from timezone:", e);
          return "";
        }
      },

      /**
       * Get city from timezone
       * @param {string} timezone Timezone identifier (e.g., "Europe/Amsterdam")
       * @returns {string} City name
       */
      getCityFromTimezone: function(timezone) {
        if (!timezone) return "";
        try {
          var timezoneRegions = timezone.split("/");
          if (timezoneRegions.length >= 2) {
            // Convert timezone city format to readable format
            // e.g., "New_York" -> "New York", "Los_Angeles" -> "Los Angeles"
            return timezoneRegions[timezoneRegions.length - 1].replace(/_/g, " ");
          }
          return "";
        } catch (e) {
          console.log("Error extracting city from timezone:", e);
          return "";
        }
      },

      /**
       * Get country from timezone
       * @param {string} timezone Timezone identifier
       * @returns {string} Country name
       */
      getCountryFromTimezone: function(timezone) {
        if (!timezone) return "";
        
        // Map of timezone regions to countries
        var timezoneToCountry = {
          // Europe
          "Europe/Amsterdam": "Netherlands",
          "Europe/London": "United Kingdom", 
          "Europe/Paris": "France",
          "Europe/Berlin": "Germany",
          "Europe/Rome": "Italy",
          "Europe/Madrid": "Spain",
          "Europe/Vienna": "Austria",
          "Europe/Brussels": "Belgium",
          "Europe/Zurich": "Switzerland",
          "Europe/Stockholm": "Sweden",
          "Europe/Oslo": "Norway",
          "Europe/Copenhagen": "Denmark",
          "Europe/Helsinki": "Finland",
          "Europe/Warsaw": "Poland",
          "Europe/Prague": "Czech Republic",
          "Europe/Budapest": "Hungary",
          "Europe/Athens": "Greece",
          "Europe/Lisbon": "Portugal",
          "Europe/Dublin": "Ireland",
          "Europe/Moscow": "Russia",
          "Europe/Kiev": "Ukraine",
          
          // Americas
          "America/New_York": "United States",
          "America/Los_Angeles": "United States",
          "America/Chicago": "United States",
          "America/Denver": "United States",
          "America/Phoenix": "United States",
          "America/Toronto": "Canada",
          "America/Vancouver": "Canada",
          "America/Montreal": "Canada",
          "America/Mexico_City": "Mexico",
          "America/Sao_Paulo": "Brazil",
          "America/Buenos_Aires": "Argentina",
          "America/Santiago": "Chile",
          "America/Lima": "Peru",
          "America/Bogota": "Colombia",
          "America/Caracas": "Venezuela",
          
          // Asia
          "Asia/Tokyo": "Japan",
          "Asia/Shanghai": "China",
          "Asia/Hong_Kong": "Hong Kong",
          "Asia/Singapore": "Singapore",
          "Asia/Seoul": "South Korea",
          "Asia/Bangkok": "Thailand",
          "Asia/Jakarta": "Indonesia",
          "Asia/Manila": "Philippines",
          "Asia/Kuala_Lumpur": "Malaysia",
          "Asia/Mumbai": "India",
          "Asia/Kolkata": "India",
          "Asia/Delhi": "India",
          "Asia/Dubai": "United Arab Emirates",
          "Asia/Riyadh": "Saudi Arabia",
          "Asia/Tel_Aviv": "Israel",
          "Asia/Istanbul": "Turkey",
          
          // Oceania
          "Australia/Sydney": "Australia",
          "Australia/Melbourne": "Australia",
          "Australia/Perth": "Australia",
          "Australia/Brisbane": "Australia",
          "Pacific/Auckland": "New Zealand",
          "Pacific/Fiji": "Fiji",
          
          // Africa
          "Africa/Cairo": "Egypt",
          "Africa/Lagos": "Nigeria",
          "Africa/Johannesburg": "South Africa",
          "Africa/Nairobi": "Kenya",
          "Africa/Casablanca": "Morocco",
          "Africa/Tunis": "Tunisia",
        };
        
        try {
          // Direct lookup first
          if (timezoneToCountry[timezone]) {
            return timezoneToCountry[timezone];
          }
          
          // Fallback: extract from timezone structure
          var timezoneRegions = timezone.split("/");
          if (timezoneRegions.length >= 2) {
            var continent = timezoneRegions[0];
            var location = timezoneRegions[1];
            
            // Handle some common patterns
            if (continent === "America") {
              if (location.includes("New_York") || location.includes("Los_Angeles") || 
                  location.includes("Chicago") || location.includes("Denver") || 
                  location.includes("Phoenix") || location.includes("Detroit") ||
                  location.includes("Atlanta") || location.includes("Miami")) {
                return "United States";
              }
              if (location.includes("Toronto") || location.includes("Vancouver") || 
                  location.includes("Montreal") || location.includes("Edmonton")) {
                return "Canada";
              }
              if (location.includes("Mexico")) {
                return "Mexico";
              }
            }
            
            // Generic fallback - use location name as country
            return location.replace(/_/g, " ");
          }
          
          return "";
        } catch (e) {
          console.log("Error extracting country from timezone:", e);
          return "";
        }
      },

      /**
       * Get complete location data from timezone
       * @param {string} timezone Timezone identifier
       * @returns {Object} Location data with continent, country, and city
       */
      getLocationFromTimezone: function(timezone) {
        return {
          continent: this.getContinentFromTimezone(timezone),
          country: this.getCountryFromTimezone(timezone),
          city: this.getCityFromTimezone(timezone)
        };
      },

      /**
       * Remove cookie
       * @param {string} name Cookie name
       * @param {string} path Cookie path
       * @param {string} domain Cookie domain
       */
      removeCookie: function (name, path, domain) {
        this.setCookie(name, "", -1, path, domain);
      },

      /**
       * Comprehensive user data cleanup for GDPR compliance
       * This method can be called externally for manual cleanup or privacy compliance
       * @param {Object} options Configuration options for cleanup
       * @param {boolean} options.clearClientId Whether to clear client ID (default: true)
       * @param {boolean} options.clearSessionData Whether to clear session data (default: true)
       * @param {boolean} options.clearConsentData Whether to clear consent data (default: false)
       * @param {boolean} options.clearLocationData Whether to clear location data (default: true)
       * @param {boolean} options.clearPurchaseTracking Whether to clear purchase tracking (default: true)
       * @param {boolean} options.clearQueuedEvents Whether to clear queued events (default: true)
       * @param {boolean} options.clearAttribution Whether to clear attribution data (default: true)
       * @param {boolean} options.clearUserInfo Whether to clear user information (default: true)
       * @param {Array} options.customKeys Additional custom keys to remove
       * @param {string} options.reason Reason for cleanup (for logging)
       * @returns {Object} Cleanup results summary
       */
      cleanupUserData: function(options) {
        options = options || {};
        
        // Default options
        var defaults = {
          clearClientId: true,
          clearSessionData: true,
          clearConsentData: false,
          clearLocationData: true,
          clearPurchaseTracking: true,
          clearQueuedEvents: true,
          clearAttribution: true,
          clearUserInfo: true,
          customKeys: [],
          reason: 'Manual cleanup'
        };
        
        // Merge options with defaults
        for (var key in defaults) {
          if (options[key] === undefined) {
            options[key] = defaults[key];
          }
        }
        
        var cleanupResults = {
          localStorage: [],
          sessionStorage: [],
          cookies: [],
          customKeys: [],
          timestamp: Date.now(),
          reason: options.reason
        };
        
        // 1. Clear Client ID
        if (options.clearClientId) {
          if (localStorage.getItem('server_side_ga4_client_id')) {
            localStorage.removeItem('server_side_ga4_client_id');
            cleanupResults.localStorage.push('server_side_ga4_client_id');
          }
        }
        
        // 2. Clear Session Data
        if (options.clearSessionData) {
          var sessionKeys = [
            'server_side_ga4_session_id',
            'server_side_ga4_session_start',
            'server_side_ga4_session_count',
            'server_side_ga4_first_visit'
          ];
          
          sessionKeys.forEach(function(key) {
            if (localStorage.getItem(key)) {
              localStorage.removeItem(key);
              cleanupResults.localStorage.push(key);
            }
          });
        }
        
        // 3. Clear Attribution Data
        if (options.clearAttribution) {
          var attributionKeys = [
            'server_side_ga4_last_source',
            'server_side_ga4_last_medium',
            'server_side_ga4_last_campaign',
            'server_side_ga4_last_content',
            'server_side_ga4_last_term',
            'server_side_ga4_last_gclid'
          ];
          
          attributionKeys.forEach(function(key) {
            if (localStorage.getItem(key)) {
              localStorage.removeItem(key);
              cleanupResults.localStorage.push(key);
            }
          });
        }
        
        // 4. Clear Location Data
        if (options.clearLocationData) {
          if (localStorage.getItem('user_location_data')) {
            localStorage.removeItem('user_location_data');
            cleanupResults.localStorage.push('user_location_data');
          }
        }
        
        // 5. Clear Consent Data (optional)
        if (options.clearConsentData) {
          if (localStorage.getItem('ga4_consent_status')) {
            localStorage.removeItem('ga4_consent_status');
            cleanupResults.localStorage.push('ga4_consent_status');
          }
        }
        
        // 6. Clear Purchase Tracking Data
        if (options.clearPurchaseTracking) {
          var purchaseKeys = [];
          
          // Find all purchase tracking keys
          for (var i = 0; i < localStorage.length; i++) {
            var key = localStorage.key(i);
            if (key && key.startsWith('purchase_tracked_')) {
              purchaseKeys.push(key);
            }
          }
          
          // Remove purchase tracking keys
          purchaseKeys.forEach(function(key) {
            localStorage.removeItem(key);
            cleanupResults.localStorage.push(key);
          });
        }
        
        // 7. Clear Queued Events (sessionStorage)
        if (options.clearQueuedEvents && window.sessionStorage) {
          if (sessionStorage.getItem('ga4_queued_events')) {
            sessionStorage.removeItem('ga4_queued_events');
            cleanupResults.sessionStorage.push('ga4_queued_events');
          }
        }
        
        // 8. Clear User Information (if stored)
        if (options.clearUserInfo) {
          var userInfoKeys = [
            'ga4_user_email',
            'ga4_user_phone',
            'ga4_user_first_name',
            'ga4_user_last_name',
            'ga4_user_data'
          ];
          
          userInfoKeys.forEach(function(key) {
            if (localStorage.getItem(key)) {
              localStorage.removeItem(key);
              cleanupResults.localStorage.push(key);
            }
          });
        }
        
        // 9. Clear Custom Keys
        if (options.customKeys && Array.isArray(options.customKeys)) {
          options.customKeys.forEach(function(key) {
            if (localStorage.getItem(key)) {
              localStorage.removeItem(key);
              cleanupResults.customKeys.push(key);
            }
            if (window.sessionStorage && sessionStorage.getItem(key)) {
              sessionStorage.removeItem(key);
              cleanupResults.customKeys.push(key + ' (session)');
            }
          });
        }
        
        // 10. Clear relevant cookies
        var cookiesToClear = [
          '_ga',
          '_ga_' + (window.ga4ServerSideTagging ? window.ga4ServerSideTagging.measurementId : ''),
          '_gid',
          '_gat',
          '_gtag',
          'ga4_consent',
          'ga4_client_id'
        ];
        
        cookiesToClear.forEach(function(cookieName) {
          if (cookieName && GA4Utils.helpers.getCookie(cookieName)) {
            GA4Utils.helpers.removeCookie(cookieName);
            GA4Utils.helpers.removeCookie(cookieName, '/', '.' + window.location.hostname);
            cleanupResults.cookies.push(cookieName);
          }
        });
        
        // Calculate totals
        cleanupResults.totals = {
          localStorage: cleanupResults.localStorage.length,
          sessionStorage: cleanupResults.sessionStorage.length,
          cookies: cleanupResults.cookies.length,
          customKeys: cleanupResults.customKeys.length,
          total: cleanupResults.localStorage.length + 
                cleanupResults.sessionStorage.length + 
                cleanupResults.cookies.length + 
                cleanupResults.customKeys.length
        };
        
        // Log cleanup results
        GA4Utils.helpers.log(
          'Comprehensive user data cleanup completed',
          {
            reason: options.reason,
            results: cleanupResults,
            options: options
          },
          {},
          '[GDPR Cleanup]'
        );
        
        // Trigger cleanup of expired data as well
        if (GA4Utils.session && typeof GA4Utils.session._clearExpiredUserData === 'function') {
          GA4Utils.session._clearExpiredUserData();
        }
        
        return cleanupResults;
      },

      /**
       * Quick GDPR compliance cleanup - removes all user data
       * This is a convenience method for complete data removal
       * @param {string} reason Reason for cleanup (for logging)
       * @returns {Object} Cleanup results summary
       */
      gdprCompliantCleanup: function(reason) {
        reason = reason || 'GDPR compliance request';
        
        return this.cleanupUserData({
          clearClientId: true,
          clearSessionData: true,
          clearConsentData: false, // Keep consent data for compliance
          clearLocationData: true,
          clearPurchaseTracking: true,
          clearQueuedEvents: true,
          clearAttribution: true,
          clearUserInfo: true,
          reason: reason
        });
      },

      /**
       * Selective cleanup for consent withdrawal
       * Removes tracking data but keeps consent preferences
       * @param {string} reason Reason for cleanup (for logging)
       * @returns {Object} Cleanup results summary
       */
      consentWithdrawalCleanup: function(reason) {
        reason = reason || 'Consent withdrawal';
        
        return this.cleanupUserData({
          clearClientId: true,
          clearSessionData: true,
          clearConsentData: false, // Keep consent data to remember withdrawal
          clearLocationData: true,
          clearPurchaseTracking: false, // Keep purchase data for business purposes
          clearQueuedEvents: true,
          clearAttribution: true,
          clearUserInfo: true,
          reason: reason
        });
      },

      /**
       * Reset all tracking data (for testing or fresh start)
       * @param {string} reason Reason for cleanup (for logging)
       * @returns {Object} Cleanup results summary
       */
      resetAllTrackingData: function(reason) {
        reason = reason || 'Reset all tracking data';
        
        return this.cleanupUserData({
          clearClientId: true,
          clearSessionData: true,
          clearConsentData: true,
          clearLocationData: true,
          clearPurchaseTracking: true,
          clearQueuedEvents: true,
          clearAttribution: true,
          clearUserInfo: true,
          reason: reason
        });
      },

      /**
       * Get summary of stored user data (for privacy dashboard)
       * @returns {Object} Summary of all stored data
       */
      getStoredDataSummary: function() {
        var summary = {
          localStorage: {
            clientId: localStorage.getItem('server_side_ga4_client_id') !== null,
            sessionData: localStorage.getItem('server_side_ga4_session_id') !== null,
            consentData: localStorage.getItem('ga4_consent_status') !== null,
            locationData: localStorage.getItem('user_location_data') !== null,
            attributionData: localStorage.getItem('server_side_ga4_last_source') !== null,
            userInfo: localStorage.getItem('ga4_user_data') !== null,
            purchaseTracking: 0
          },
          sessionStorage: {
            queuedEvents: window.sessionStorage ? sessionStorage.getItem('ga4_queued_events') !== null : false
          },
          cookies: {
            ga: GA4Utils.helpers.getCookie('_ga') !== null,
            gid: GA4Utils.helpers.getCookie('_gid') !== null,
            gat: GA4Utils.helpers.getCookie('_gat') !== null,
            consent: GA4Utils.helpers.getCookie('ga4_consent') !== null
          },
          timestamp: Date.now()
        };
        
        // Count purchase tracking entries
        for (var i = 0; i < localStorage.length; i++) {
          var key = localStorage.key(i);
          if (key && key.startsWith('purchase_tracked_')) {
            summary.localStorage.purchaseTracking++;
          }
        }
        
        return summary;
      },
    },

    /**
     * A/B Testing functionality - Optimized click tracking
     */
    abTesting: {
      /**
       * Initialize A/B tests for the current session
       */
      init: function(config) {
        if (!config || !config.abTestsEnabled || !config.abTestsConfig) return;

        try {
          var tests = JSON.parse(config.abTestsConfig);
          if (!Array.isArray(tests) || tests.length === 0) return;

          var self = this;
          tests.forEach(function(test) {
            if (test.enabled && test.name && test.class_a && test.class_b) {
              self.setupTest(test);
            }
          });
          
          console.log('🧪 [A/B Testing] Initialized ' + tests.length + ' tests');
        } catch (e) {
          console.log('❌ [A/B Testing] Config error:', e);
        }
      },

      /**
       * Set up click tracking for a test
       */
      setupTest: function(test) {
        var self = this;
        
        // Set up tracking for both variants
        if (document.querySelector(test.class_a)) {
          $(document).on('click', test.class_a, function() {
            self.track(test, 'A', this);
          });
        }
        
        if (document.querySelector(test.class_b)) {
          $(document).on('click', test.class_b, function() {
            self.track(test, 'B', this);
          });
        }
      },

      /**
       * Track A/B test click event
       */
      track: function(test, variant, element) {
        var session = GA4Utils.session.get();
        var userData = GA4Utils.storage.getUserData();
        var userAgent = GA4Utils.device.parseUserAgent();
        
        var eventName = this.createEventName(test.name, variant);
        var eventData = {
          // A/B test data
          ab_test_name: test.name,
          ab_test_variant: variant,
          ab_test_element_class: variant === 'A' ? test.class_a : test.class_b,
          
          // Session data
          session_id: session.id,
          session_duration_seconds: Math.round((Date.now() - session.start) / 1000),
          
          // User data
          client_id: GA4Utils.clientId.get(),
          browser_name: userAgent.browser_name,
          device_type: userAgent.device_type,
          is_mobile: userAgent.is_mobile,
          language: navigator.language || '',
          
          // Element data
          element_tag: element.tagName.toLowerCase(),
          element_text: (element.textContent || '').trim().substring(0, 100),
          element_id: element.id || '',
          
          // Page data
          page_location: window.location.href,
          page_title: document.title,
          page_referrer: document.referrer || '',
          
          // Attribution data
          source: userData.lastSource || '',
          medium: userData.lastMedium || '',
          campaign: userData.lastCampaign || '',
          
          // Meta data
          timezone: GA4Utils.helpers.getTimezone(),
          engagement_time_msec: GA4Utils.time.calculateEngagementTime(session.start),
          event_timestamp: Math.floor(Date.now() / 1000)
        };

        if (window.GA4ServerSideTagging && window.GA4ServerSideTagging.trackEvent) {
          window.GA4ServerSideTagging.trackEvent(eventName, eventData);
          console.log('🧪 [A/B Testing] Sent: ' + eventName);
        }
      },

      /**
       * Create GA4-compliant event name
       */
      createEventName: function(testName, variant) {
        var clean = testName.toLowerCase()
          .replace(/[^a-z0-9]/g, '_')
          .replace(/_+/g, '_')
          .replace(/^_|_$/g, '');
        
        var eventName = clean + '_' + variant.toLowerCase();
        
        // Ensure max 40 chars
        if (eventName.length > 40) {
          clean = clean.substring(0, 37);
          eventName = clean + '_' + variant.toLowerCase();
        }
        
        // No leading numbers
        if (/^[0-9]/.test(eventName)) {
          eventName = 'ab_' + eventName;
          if (eventName.length > 40) {
            eventName = eventName.substring(0, 40);
          }
        }
        
        return eventName;
      }
    },
  };

  // Expose the utilities globally
  window.GA4Utils = GA4Utils;

  // Initialize cleanup on page load
  $(document).ready(function () {
    // Migrate legacy data to new centralized system
    GA4Utils.storage.migrateLegacyData();
    
    // Clean up expired data on page load
    GA4Utils.storage.cleanupExpiredData();
    GA4Utils.page.cleanupAllExpiredData();

    GA4Utils.helpers.log(
      "GA4Utils initialized, migration and cleanup completed",
      null,
      {},
      "[GA4Utils Init]"
    );
  });
})(window, jQuery);
