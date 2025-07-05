/**
 * Public JavaScript for GA4 Server-Side Tagging
 * Refactored to use GA4Utils for cleaner code
 *
 * @since      1.0.0
 */

(function ($) {
  "use strict";

  // GA4 Server-Side Tagging Client
  var GA4ServerSideTagging = {
        // Configuration
    config: window.ga4ServerSideTagging || {},
    pageStartTime: Date.now(),
    consentReady: false,
    trackedPageViews: new Set(), // Track URLs that have been tracked
    loadingSecureConfig: false, // Prevent multiple simultaneous secure config requests



    init: async function () {
      // Check for bots first - if bot detected, stop all tracking
      var userAgentInfo = GA4Utils.device.parseUserAgent();
      var clientBehavior = GA4Utils.botDetection.getClientBehaviorData();
      var sessionParams = { page_referrer: document.referrer || '' };
      
      if (GA4Utils.botDetection.isBot(userAgentInfo, sessionParams, clientBehavior)) {
        this.log("🤖 Bot detected - stopping all GA4 tracking");
        return;
      }

      // Check basic configuration first (synchronous)
      if (!this.config.measurementId) {
        this.log("Measurement ID not configured");
        return;
      }
      if (this.config.ga4TrackLoggedInUsers != true) {
        this.log("Not tracking logged in users");
        return;
      }

      this.log("🚀 Starting immediate initialization of user-facing features...");

      // Initialize GDPR Consent System with retry mechanism for slow loads (immediate)
      await this.initializeConsentSystemWithRetry();

      // Set up event listeners immediately (these will queue events until secure config is ready)
      this.setupEventListeners();

      // Initialize A/B testing immediately
      this.initializeABTesting();
      
      // Initialize Click tracking immediately
      this.initializeClickTracking();

      // Track page view immediately (will be queued until secure config is ready)
      this.log("🚀 Triggering trackPageView immediately on page load - will queue until secure config loads");
      this.trackPageView(); // Remove await - let it queue

      // Load secure configuration in background (don't block user experience)
      this.loadSecureConfigInBackground();

      // Log initialization
      this.log(
        "%c GA4 Server-Side Tagging initialized v1 ",
        "background: #4CAF50; color: white; font-size: 16px; font-weight: bold; padding: 8px 12px; border-radius: 4px;"
      );
    },




    /**
     * Load secure configuration in background without blocking user experience
     */
    loadSecureConfigInBackground: async function() {
      this.log("🔒 Loading secure configuration in background...");
      
      try {
        await this.loadSecureConfig();
        this.log("✅ Secure configuration loaded - processing queued events");
        
        // Process any queued events now that we have secure config
        this.processQueuedEvents();
        
      } catch (error) {
        this.log("⚠️ Failed to load secure configuration in background:", error.message);
        this.log("❌ Events will not be sent - secure config required");
        
        // No retry - secure config loading failed
      }
    },


    /**
     * Process any events that were queued while waiting for secure config
     */
    processQueuedEvents: function() {
      this.log("🚀 Secure config ready - processing queued events immediately");
      
      // Trigger immediate queue processing
      this.processEventQueue();
    },

    /**
     * Load secure configuration from REST API (simplified - no authentication tokens needed)
     */
    loadSecureConfig: async function() {
      // Prevent multiple simultaneous requests
      if (this.loadingSecureConfig) {
        this.log("⏳ Secure config already loading, skipping duplicate request");
        return;
      }
      
      this.loadingSecureConfig = true;
      
      try {
        // No authentication tokens needed - direct secure config request with essential headers
        let headers = {
          'Accept': 'application/json, text/plain, */*',
          'Content-Type': 'application/json'
        };

        // Add encryption header if encryption is enabled
        if (this.config.encryptionEnabled) {
          headers['X-Encrypted'] = 'true';
        }
        
        const response = await fetch(this.config.apiEndpoint + '/secure-config', {
          method: 'GET',
          headers: headers
        });

        if (response.ok) {
          let secureConfig = await response.json();
          
          // All secure config responses are encrypted - always decrypt
          if (secureConfig.jwt) {
            try {
              // Check if we have server entropy for secure key reconstruction
              if (secureConfig.client_fingerprint && secureConfig.server_entropy && secureConfig.time_slot) {
                // Use new secure key reconstruction
                const tempKey = await this.reconstructSecureEncryptionKey({
                  client_fingerprint: secureConfig.client_fingerprint,
                  server_entropy: secureConfig.server_entropy,
                  time_slot: secureConfig.time_slot
                });
                
                if (!tempKey) {
                  this.log("⚠️ Failed to reconstruct secure encryption key");
                  return;
                }
                
                // Create token renewal callback for secure keys
                const renewTokenCallback = async () => {
                  this.log("🔄 JWT token expired, requesting new secure config...");
                  
                  // Make a fresh request for secure config with cache-busting
                  const renewalHeaders = {
                    ...headers,
                    'Cache-Control': 'no-cache, no-store, must-revalidate',
                    'Pragma': 'no-cache',
                    'Expires': '0'
                  };
                  
                  const renewalResponse = await fetch(this.config.apiEndpoint + '/secure-config?t=' + Date.now(), {
                    method: 'GET',
                    headers: renewalHeaders
                  });
                  
                  if (renewalResponse.ok) {
                    const newSecureConfig = await renewalResponse.json();
                    this.log("🔄 Renewal response received:", {
                      hasJwt: !!newSecureConfig.jwt,
                      hasFingerprint: !!newSecureConfig.client_fingerprint,
                      hasEntropy: !!newSecureConfig.server_entropy,
                      timeSlot: newSecureConfig.time_slot
                    });
                    
                    if (newSecureConfig.jwt && newSecureConfig.client_fingerprint && newSecureConfig.server_entropy) {
                      // Reconstruct new key with new server entropy
                      const newTempKey = await this.reconstructSecureEncryptionKey({
                        client_fingerprint: newSecureConfig.client_fingerprint,
                        server_entropy: newSecureConfig.server_entropy,
                        time_slot: newSecureConfig.time_slot
                      });
                      
                      if (newTempKey) {
                        // Debug: Check the new JWT timestamps
                        try {
                          const jwtParts = newSecureConfig.jwt.split('.');
                          if (jwtParts.length === 3) {
                            const payload = JSON.parse(atob(jwtParts[1].replace(/-/g, '+').replace(/_/g, '/')));
                            const now = Math.floor(Date.now() / 1000);
                            this.log("🔍 New JWT timestamps:", {
                              issued: payload.iat,
                              expires: payload.exp,
                              current: now,
                              timeLeft: payload.exp - now
                            });
                          }
                        } catch (e) {
                          this.log("⚠️ Could not decode JWT for debugging:", e.message);
                        }
                        
                        this.log("✅ New secure encryption key reconstructed for renewed token");
                        return { jwt: newSecureConfig.jwt, key: newTempKey };
                      }
                    }
                  }
                  throw new Error('Failed to renew secure config token');
                };
                
                const decryptedData = await GA4Utils.encryption.decrypt(secureConfig.jwt, tempKey, {
                  renewTokenCallback: renewTokenCallback
                });
                secureConfig = JSON.parse(decryptedData);
                this.log("🔓 Secure config response decrypted with reconstructed key");
              } else {
                // No secure entropy provided - request fresh config instead of using legacy method
                this.log("⚠️ No secure entropy in response, requesting fresh secure config...");

                // Retry loadSecureConfig to get response with proper entropy
                return await this.loadSecureConfig();
              }
            } catch (decError) {
              this.log("⚠️ Failed to decrypt secure config response:", decError.message);
              return;
            }
          } else {
            this.log("⚠️ Expected encrypted response but received unencrypted data");
            return;
          }
                    
          // Derive the original keys from secured transmission
          const originalWorkerApiKey = secureConfig.workerApiKey ? 
            await this.deriveOriginalKey(secureConfig.workerApiKey, secureConfig.keyDerivationSalt) : '';
          const originalEncryptionKey = secureConfig.encryptionKey ? 
            await this.deriveOriginalKey(secureConfig.encryptionKey, secureConfig.keyDerivationSalt) : '';
          
          // Merge secure config into main config
          this.config.cloudflareWorkerUrl = secureConfig.cloudflareWorkerUrl || '';
          this.config.workerApiKey = originalWorkerApiKey;
          this.config.encryptionEnabled = secureConfig.encryptionEnabled || false;
          this.config.encryptionKey = originalEncryptionKey;
          
          // Load encryption settings from secure config if not already present
          if (secureConfig.encryptionEnabled !== undefined) {
            this.config.encryptionEnabled = secureConfig.encryptionEnabled;
          }
          if (secureConfig.encryptionKey && !this.config.encryptionKey) {
            this.config.encryptionKey = secureConfig.encryptionKey;
          }
          
          this.log("🔒 Secure configuration loaded successfully", {
            cloudflareWorkerUrl: this.config.cloudflareWorkerUrl,
            hasWorkerApiKey: !!this.config.workerApiKey,
            encryptionEnabled: !!this.config.encryptionEnabled
          });
        } else {
          this.log("⚠️ Failed to load secure configuration - status:", response.status, response.statusText);
        }
      } catch (error) {
        this.log("⚠️ Error loading secure configuration:", error.message);
      } finally {
        // Reset loading state regardless of success/failure
        this.loadingSecureConfig = false;
      }
    },

    /**
     * Derive original key from secured transmission using XOR deobfuscation.
     * This reverses the XOR obfuscation applied on the server side.
     */
    deriveOriginalKey: async function(obfuscatedKey, salt) {
      try {
        if (!obfuscatedKey || !salt) {
          return '';
        }

        this.log("🔑 Deriving original key from secured transmission...");
        
        // Base64 decode the obfuscated key
        const decodedKey = atob(obfuscatedKey);
        
        // XOR deobfuscation (same operation as obfuscation - XOR is reversible)
        const originalKey = this.xorDeobfuscate(decodedKey, salt);
        
        this.log("✅ Original key derived successfully");
        return originalKey;
        
      } catch (error) {
        this.log("⚠️ Failed to derive original key:", error.message);
        return '';
      }
    },

    /**
     * XOR deobfuscation function (reverses XOR obfuscation).
     */
    xorDeobfuscate: function(data, key) {
      const dataLen = data.length;
      const keyLen = key.length;
      let deobfuscated = '';
      
      for (let i = 0; i < dataLen; i++) {
        deobfuscated += String.fromCharCode(
          data.charCodeAt(i) ^ key.charCodeAt(i % keyLen)
        );
      }
      
      return deobfuscated;
    },

    /**
     * Reconstruct encryption key using server-provided entropy
     * @param {Object} serverEntropy Server entropy data from secure-config response
     * @returns {Promise<string>} Reconstructed encryption key
     */
    reconstructSecureEncryptionKey: async function(serverEntropy) {
      try {
        // Extract entropy components from server response
        const { client_fingerprint, server_entropy, time_slot } = serverEntropy;
        
        if (!client_fingerprint || !server_entropy || !time_slot) {
          throw new Error('Missing entropy components from server');
        }
        
        // Normalize site URL to match PHP get_site_url() format
        const siteUrl = window.location.origin.replace(/\/$/, '');
        
        // Recreate the same key material as PHP
        const keyMaterial = siteUrl + time_slot + client_fingerprint + server_entropy + 'ga4-secure-encryption';
        
        // Use Web Crypto API to generate SHA-256 hash
        const encoder = new TextEncoder();
        const data = encoder.encode(keyMaterial);
        const hashBuffer = await crypto.subtle.digest('SHA-256', data);
        
        // Convert to hex string (64 characters)
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        
        return hashHex;
      } catch (error) {
        this.log("⚠️ Failed to reconstruct secure encryption key:", error.message);
        return null;
      }
    },

    /**
     * Generate temporary encryption key for secure config (changes every 5 minutes).
     * @deprecated Use reconstructSecureEncryptionKey instead for better security
     * This matches the server-side implementation.
     */
    getTemporaryEncryptionKey: async function() {
      try {
        // Create key that changes every 5 minutes but is predictable
        const current5minSlot = Math.floor(Date.now() / (1000 * 300)); // 300 seconds = 5 minutes
        const siteUrl = window.location.origin;
        
        // Create deterministic key that changes every 5 minutes
        // Normalize site URL to match PHP get_site_url() format
        const normalizedSiteUrl = siteUrl.replace(/\/$/, ''); // Remove trailing slash
        const seedString = normalizedSiteUrl + current5minSlot + 'ga4-temp-encryption';
        
        // Use Web Crypto API to generate SHA-256 hash
        const encoder = new TextEncoder();
        const data = encoder.encode(seedString);
        const hashBuffer = await crypto.subtle.digest('SHA-256', data);
        
        // Convert to hex string (64 characters)
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        
        return hashHex;
      } catch (error) {
        this.log("⚠️ Failed to generate temporary encryption key:", error.message);
        return null;
      }
    },

    /**
     * Initialize consent system with retry mechanism for slow page loads
     */
    initializeConsentSystemWithRetry: async function() {
      const maxRetries = 10;
      const retryDelay = 500; // 500ms between retries
      
      for (let attempt = 1; attempt <= maxRetries; attempt++) {
        this.log(`🔄 Consent system initialization attempt ${attempt}/${maxRetries}`);
        
        // Check if all dependencies are loaded
        if (this.isConsentSystemReady()) {
          this.log("✅ Consent system dependencies loaded, initializing...");
          this.initializeConsentSystem();
          return;
        }
        
        if (attempt === maxRetries) {
          this.log("⚠️ Consent system dependencies not loaded after maximum retries, proceeding without consent management");
          this.onConsentReady();
          return;
        }
        
        this.log(`⏳ Consent system not ready, waiting ${retryDelay}ms before retry ${attempt + 1}...`);
        await this.delay(retryDelay);
      }
    },

    /**
     * Check if consent system dependencies are ready
     */
    isConsentSystemReady: function() {
      // Check if jQuery is loaded (required for event handling)
      if (typeof $ === 'undefined') {
        this.log("🔍 jQuery not loaded yet");
        return false;
      }
      
      // Check if consent settings are available
      if (!this.config.consentSettings) {
        this.log("🔍 Consent settings not available");
        return false;
      }
      
      // If consent mode is enabled, check if GA4ConsentManager is available
      if (this.config.consentSettings.consentModeEnabled) {
        if (!window.GA4ConsentManager || typeof window.GA4ConsentManager.init !== 'function') {
          this.log("🔍 GA4ConsentManager not loaded yet");
          return false;
        }
      }
      
      // Check if DOM is ready for consent banner detection
      if (document.readyState === 'loading') {
        this.log("🔍 DOM still loading");
        return false;
      }
      
      return true;
    },

    /**
     * Utility function to create a delay
     */
    delay: function(ms) {
      return new Promise(resolve => setTimeout(resolve, ms));
    },

    /**
     * Initialize consent system
     */
    initializeConsentSystem: function() {
      var self = this;
      
      this.log("🔍 Checking consent system configuration", {
        hasConsentSettings: !!(this.config.consentSettings),
        consentModeEnabled: this.config.consentSettings && this.config.consentSettings.consentModeEnabled,
        GA4ConsentManagerAvailable: !!(window.GA4ConsentManager),
        GA4ConsentManagerInitFunction: window.GA4ConsentManager && typeof window.GA4ConsentManager.init === 'function'
      });
      
      if (this.config.consentSettings && this.config.consentSettings.consentModeEnabled) {
        this.log("✅ Consent mode is enabled, attempting to initialize consent manager");
        
        // Initialize consent manager with reference to this tracking instance
        if (window.GA4ConsentManager && typeof window.GA4ConsentManager.init === 'function') {
          this.log("🎯 Initializing GA4ConsentManager with settings", this.config.consentSettings);
          window.GA4ConsentManager.init(this.config.consentSettings, this);
          
          // Listen for consent updates
          $(document).on('ga4ConsentUpdated', function(event, consent) {
            self.log("Consent updated", consent);
          });
        } else {
          this.log("❌ GA4ConsentManager not available - starting tracking without consent management");
          // If consent manager is not available, assume consent and start tracking
          this.onConsentReady();
        }
      } else {
        this.log("ℹ️ Consent mode disabled - starting tracking immediately");
        // If consent mode is disabled, start tracking immediately
        this.onConsentReady();
      }
    },

    /**
     * Called when consent is ready (either given/denied or not required)
     */
    onConsentReady: function() {
      this.consentReady = true;
      this.log("🚀 onConsentReady called - consent status changed", {
        currentUrl: window.location.href,
        hasConsentManager: !!(window.GA4ConsentManager),
        consentReady: this.consentReady,
        useServerSide: true, // Always enabled
        source: "onConsentReady function",
        note: "trackPageView was already called on page load - queued events will now be processed"
      });
      
      // trackPageView() is now called immediately on page load
      // This function just sets the consentReady flag
      // The consent manager will process any queued events
    },

    /**
     * Initialize A/B testing functionality
     */
    initializeABTesting: function() {
      this.log("🧪 [Main] Starting A/B testing initialization...", {
        hasGA4Utils: !!(window.GA4Utils),
        configKeys: this.config ? Object.keys(this.config) : 'no config'
      });
      
      if (this.config.abTestsEnabled && this.config.abTestsConfig) {
        try {
          // Parse and set up A/B tests
          var tests = JSON.parse(this.config.abTestsConfig);
          
          this.log("🧪 [Main] Parsed A/B testing config:", {
            abTestsEnabled: this.config.abTestsEnabled,
            abTestsConfig: tests
          });
          
          this.setupABTesting(tests);
          this.log("✅ [Main] A/B testing initialized successfully");
        } catch (error) {
          this.log("❌ [Main] Error initializing A/B testing:", error);
        }
      } else {
        this.log("ℹ️ [Main] A/B testing not enabled or no config provided");
      }
    },

    /**
     * Initialize Click tracking functionality
     */
    initializeClickTracking: function() {
      this.log("🎯 [Main] Starting Click tracking initialization...", {
        hasGA4Utils: !!(window.GA4Utils),
        configKeys: this.config ? Object.keys(this.config) : 'no config'
      });
      
      if (this.config.clickTracksEnabled && this.config.clickTracksConfig) {
        try {
          // Parse the click tracks configuration
          var clickTracks = JSON.parse(this.config.clickTracksConfig);
          
          this.log("🎯 [Main] Parsed Click tracking config:", {
            clickTracksEnabled: this.config.clickTracksEnabled,
            clickTracksConfig: clickTracks
          });
          
          this.setupClickTracking(clickTracks);
          this.log("✅ [Main] Click tracking initialized successfully");
        } catch (error) {
          this.log("❌ [Main] Error initializing Click tracking:", error);
        }
      } else {
        this.log("ℹ️ [Main] Click tracking not enabled or no config provided");
      }
    },

    /**
     * Set up click tracking for configured elements
     */
    setupClickTracking: function(clickTracks) {
      var self = this;
      
      if (!Array.isArray(clickTracks) || clickTracks.length === 0) {
        this.log("❌ [Click Tracking] Invalid or empty click tracks configuration");
        return;
      }
      
      clickTracks.forEach(function(track) {
        if (track.enabled && track.name && track.selector) {
          // Validate event name
          var eventName = self.validateAndCreateEventName(track.name);
          if (!eventName) {
            self.log("❌ [Click Tracking] Invalid event name for track:", track.name);
            return;
          }
          
          // Set up click listener
          $(document).on('click', track.selector, function() {
            self.trackClickEvent(eventName, track.selector, this);
          });
          
          self.log("🎯 [Click Tracking] Set up tracking for:", {
            name: track.name,
            eventName: eventName,
            selector: track.selector
          });
        }
      });
    },

    /**
     * Track click event
     */
    trackClickEvent: function(eventName, selector, element) {
      var session = GA4Utils.session.get();
      var userData = GA4Utils.storage.getUserData();
      var userAgent = GA4Utils.device.parseUserAgent();
      
      var eventData = {
        // Click tracking data
        click_selector: selector,
        click_element_tag: element.tagName.toLowerCase(),
        click_element_text: (element.textContent || '').trim().substring(0, 100),
        click_element_id: element.id || '',
        click_element_class: element.className || '',
        
        // Session data
        session_id: session.id,
        session_duration_seconds: Math.round((Date.now() - session.start) / 1000),
        
        // User data
        client_id: GA4Utils.clientId.get(),
        browser_name: userAgent.browser_name,
        device_type: userAgent.device_type,
        is_mobile: userAgent.is_mobile,
        language: navigator.language || '',
        
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

      this.trackEvent(eventName, eventData);
      this.log('🎯 [Click Tracking] Sent: ' + eventName, { selector: selector });
    },

    /**
     * Validate and create GA4-compliant event name
     */
    validateAndCreateEventName: function(inputName) {
      if (!inputName || typeof inputName !== 'string') {
        return null;
      }
      
      // Clean the name to be GA4 compliant
      var cleanName = inputName.toLowerCase()
        .replace(/[^a-z0-9]/g, '_')    // Replace non-alphanumeric with underscore
        .replace(/_+/g, '_')           // Replace multiple underscores with single
        .replace(/^_|_$/g, '');        // Remove leading/trailing underscores
      
      // Ensure it doesn't start with a number
      if (/^[0-9]/.test(cleanName)) {
        cleanName = 'click_' + cleanName;
      }
      
      // Ensure max 40 characters
      if (cleanName.length > 40) {
        cleanName = cleanName.substring(0, 40);
        // Remove trailing underscore if any
        cleanName = cleanName.replace(/_$/, '');
      }
      
      // Must have at least one character after cleaning
      if (cleanName.length === 0) {
        return null;
      }
      
      return cleanName;
    },

    /**
     * A/B Testing functionality - Moved from utilities
     */
    setupABTesting: function(tests) {
      var self = this;
      
      if (!Array.isArray(tests) || tests.length === 0) {
        this.log("❌ [A/B Testing] Invalid or empty tests configuration");
        return;
      }
      
      tests.forEach(function(test) {
        if (test.enabled && test.name && test.class_a && test.class_b) {
          self.setupABTest(test);
        }
      });
      
      this.log('🧪 [A/B Testing] Initialized ' + tests.length + ' tests');
    },

    /**
     * Set up click tracking for an A/B test
     */
    setupABTest: function(test) {
      var self = this;
      
      this.log('🧪 [A/B Testing] Setting up test:', test);
      
      // Check if elements exist on page
      var elementA = document.querySelector(test.class_a);
      var elementB = document.querySelector(test.class_b);
      
      this.log('🧪 [A/B Testing] Element detection:', {
        test_name: test.name,
        class_a: test.class_a,
        class_b: test.class_b,
        element_a_found: !!elementA,
        element_b_found: !!elementB
      });
      
      // Set up tracking for variant A (always set up event delegation)
      $(document).on('click', test.class_a, function() {
        self.log('🧪 [A/B Testing] Variant A clicked:', test.name);
        self.trackABTestEvent(test, 'A', this);
      });
      
      // Set up tracking for variant B (always set up event delegation)
      $(document).on('click', test.class_b, function() {
        self.log('🧪 [A/B Testing] Variant B clicked:', test.name);
        self.trackABTestEvent(test, 'B', this);
      });
      
      this.log('🧪 [A/B Testing] Event listeners attached for:', test.name);
    },

    /**
     * Track A/B test click event
     */
    trackABTestEvent: function(test, variant, element) {
      this.log('🧪 [A/B Testing] Tracking event for:', {
        test_name: test.name,
        variant: variant,
        element_tag: element.tagName,
        element_class: element.className,
        element_id: element.id
      });
      
      var session = GA4Utils.session.get();
      var userData = GA4Utils.storage.getUserData();
      var userAgent = GA4Utils.device.parseUserAgent();
      
      var eventName = this.createABTestEventName(test.name, variant);
      
      this.log('🧪 [A/B Testing] Created event name:', eventName);
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

      this.trackEvent(eventName, eventData);
      this.log('🧪 [A/B Testing] Sent: ' + eventName);
    },

    /**
     * Create GA4-compliant A/B test event name
     */
    createABTestEventName: function(testName, variant) {
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
    },

    /**
     * Test A/B testing functionality (for debugging)
     */
    testABTesting: function() {
      this.log('🧪 [A/B Testing] Running test...');
      
      if (!this.config.abTestsEnabled) {
        this.log('❌ [A/B Testing] A/B testing is not enabled in config');
        return;
      }
      
      if (!this.config.abTestsConfig) {
        this.log('❌ [A/B Testing] No A/B tests configuration found');
        return;
      }
      
      try {
        var tests = JSON.parse(this.config.abTestsConfig);
        this.log('🧪 [A/B Testing] Found tests:', tests);
        
        tests.forEach(function(test, index) {
          console.log(`Test ${index + 1}:`, test);
          
          // Check if elements exist
          var elemA = document.querySelector(test.class_a);
          var elemB = document.querySelector(test.class_b);
          
          console.log(`  - Variant A (${test.class_a}):`, elemA ? 'FOUND' : 'NOT FOUND');
          console.log(`  - Variant B (${test.class_b}):`, elemB ? 'FOUND' : 'NOT FOUND');
        });
        
      } catch (error) {
        this.log('❌ [A/B Testing] Error parsing config:', error);
      }
    },

  
    /**
     * REPLACE the existing trackPageView function with this enhanced version
     */
    trackPageView: async function () {
      // Check for bots before tracking
      var userAgentInfo = GA4Utils.device.parseUserAgent();
      var clientBehavior = GA4Utils.botDetection.getClientBehaviorData();
      var sessionParams = { page_referrer: document.referrer || '' };
      
      if (GA4Utils.botDetection.isBot(userAgentInfo, sessionParams, clientBehavior)) {
        this.log("🤖 Bot detected - skipping page view tracking");
        return;
      }

      var currentUrl = window.location.href;
      
      this.log("🎯 trackPageView called", {
        currentUrl: currentUrl,
        consentReady: this.consentReady,
        hasConsentManager: !!(window.GA4ConsentManager),
        trackedUrls: Array.from(this.trackedPageViews)
      });
      
      // Prevent duplicate page views for the same URL
      if (this.trackedPageViews.has(currentUrl)) {
        this.log("Page view already sent for this URL - preventing duplicate", { url: currentUrl });
        return;
      }
      
      this.trackedPageViews.add(currentUrl);
      this.log("📊 Starting page view tracking for URL", { url: currentUrl });
      
      // Get current session information using utils
      var session = GA4Utils.session.get();
      var isNewSession = session.isNew;

      // Get user agent and device information using utils
      var userAgentInfo = GA4Utils.device.parseUserAgent();
   
      // Get referrer information
      var referrer = document.referrer || "";
      var referrerURL = null;
      var referrerDomain = "";
      var ignore_referrer = false;

      // Parse referrer if it exists
      if (referrer) {
        try {
          referrerURL = new URL(referrer);
          referrerDomain = referrerURL.hostname;

          // Check if the referrer is from the same domain (internal link)
          if (referrerDomain === window.location.hostname) {
            ignore_referrer = true;
          }
        } catch (e) {
          this.log("Invalid referrer URL:", referrer);
        }
      }

      // Get UTM parameters using utils
      var utmParams = GA4Utils.utm.getAll();
      var gclid = GA4Utils.gclid.get();

      // Determine source and medium according to GA4 rules
      var attribution = this.calculateAttribution(
        utmParams,
        gclid,
        referrerDomain,
        referrer,
        ignore_referrer,
        isNewSession
      );

      this.log("Source and medium data:", {
        referrer: referrer,
        referrerDomain: referrerDomain,
        ...attribution,
        isNewSession: isNewSession,
        ignore_referrer: ignore_referrer,
      });

      // Store attribution data when it's available
      this.storeAttributionData(attribution, isNewSession, utmParams, gclid);

      var traffic_type = GA4Utils.traffic.getType(
        attribution.source,
        attribution.medium,
        referrerDomain
      );

      // Common session parameters needed for all page view events (with location data)
      var sessionParams = await this.buildSessionParams(
        session,
        userAgentInfo,
        attribution,
        traffic_type,
        referrer,
        ignore_referrer
      );

      if (isNewSession) {
        this.trackEvent("custom_session_start", sessionParams);
      }
      if (session.isFirstVisit && isNewSession) {
        this.trackEvent("custom_first_visit", sessionParams);
      }

      await this.completePageViewTracking(sessionParams, isNewSession);
    },

    /**
     * Calculate attribution based on UTM parameters, referrer, and other factors
     */
    calculateAttribution: function (
      utmParams,
      gclid,
      referrerDomain,
      referrer,
      ignore_referrer,
      isNewSession
    ) {
      var source = utmParams.utm_source || "";
      var medium = utmParams.utm_medium || "";
      var campaign = utmParams.utm_campaign || "(not set)";
      var content = utmParams.utm_content || "";
      var term = utmParams.utm_term || "";

      // If no UTM parameters but we have a referrer, determine source/medium
      if (!source && !medium && referrerDomain && !ignore_referrer) {
        var referrerAttribution = this.determineReferrerAttribution(
          referrerDomain,
          referrer,
          gclid
        );
        source = referrerAttribution.source;
        medium = referrerAttribution.medium;
        campaign = referrerAttribution.campaign;
      }

      // If we have a gclid but no UTM source/medium/campaign was set, override with Google Ads attribution
      if (gclid && !utmParams.utm_source && !utmParams.utm_medium) {
        source = "google";
        medium = "cpc";
        campaign = "(organic)";
      }

      // Handle cases where no attribution is determined yet
      if (!source && !medium) {
        console.log("DEBUG: No attribution found, calling handleNoAttribution", {
          isNewSession: isNewSession,
          ignore_referrer: ignore_referrer,
          referrerDomain: referrerDomain
        });
        var fallbackAttribution = this.handleNoAttribution(isNewSession);
        console.log("DEBUG: fallbackAttribution result:", fallbackAttribution);
        source = fallbackAttribution.source;
        medium = fallbackAttribution.medium;
        campaign = fallbackAttribution.campaign;
        content = fallbackAttribution.content;
        term = fallbackAttribution.term;
        gclid = fallbackAttribution.gclid;
      }

      return { source, medium, campaign, content, term, gclid };
    },

    /**
     * Determine attribution from referrer
     */
    determineReferrerAttribution: function (referrerDomain, referrer, gclid) {
      // Handle search engines - this is critical for organic search attribution
      if (referrerDomain.indexOf("google") > -1) {
        // Check if it's Google Ads or organic
        if (referrer.indexOf("gclid=") > -1 || gclid) {
          return { source: "google", medium: "cpc", campaign: "(organic)" };
        } else {
          return { source: "google", medium: "organic", campaign: "(organic)" };
        }
      } else if (referrerDomain.indexOf("bing") > -1 || referrerDomain.indexOf("msn.com") > -1) {
        return { source: "bing", medium: "organic", campaign: "(organic)" };
      } else if (referrerDomain.indexOf("yahoo") > -1) {
        return { source: "yahoo", medium: "organic", campaign: "(organic)" };
      } else if (referrerDomain.indexOf("duckduckgo") > -1) {
        return { source: "duckduckgo", medium: "organic", campaign: "(organic)" };
      } else if (referrerDomain.indexOf("yandex") > -1) {
        return { source: "yandex", medium: "organic", campaign: "(organic)" };
      } else if (referrerDomain.indexOf("baidu") > -1) {
        return { source: "baidu", medium: "organic", campaign: "(organic)" };
      } else if (
        referrerDomain.indexOf("facebook.com") > -1 ||
        referrerDomain.indexOf("instagram.com") > -1
      ) {
        // Social referrals
        return {
          source: referrerDomain.replace("www.", "").split(".")[0],
          medium: "social",
          campaign: "(social)",
        };
      } else if (
        referrerDomain !== window.location.hostname &&
        referrerDomain !== ""
      ) {
        // Regular referral - ensure it's not from the same domain
        return {
          source: referrerDomain,
          medium: "referral",
          campaign: "(referral)",
        };
      }

      return { source: "(direct)", medium: "(none)", campaign: "(not set)" };
    },

    /**
     * Handle cases where no attribution is determined (replaces handleDirectTraffic)
     */
    handleNoAttribution: function (isNewSession) {
      // Always try to get stored attribution first (for session continuity)
      var storedAttribution = this.getStoredAttribution();
      console.log("DEBUG: handleNoAttribution called", {
        isNewSession: isNewSession,
        storedAttribution: storedAttribution
      });

      // For continuing sessions with no attribution, mark as internal traffic
      if (!isNewSession) {
        console.log("DEBUG: Returning internal attribution for continuing session");
        return {
          source: "(internal)",
          medium: "internal",
          campaign: "(not set)",
          content: "",
          term: "",
          gclid: "",
        };
      }

      // If it's a new session but we have stored attribution and no current attribution
      // This handles the case where someone bookmarked a page or typed URL directly
      // but we want to preserve the last known traffic source for analysis
      if (
        isNewSession &&
        storedAttribution.source &&
        storedAttribution.source !== "(direct)"
      ) {
        // For new sessions with no current attribution, we can either:
        // 1. Use stored attribution (commented out below)
        // 2. Mark as direct but keep stored for reference

        // Option 1: Use stored attribution
        // return storedAttribution;

        // Option 2: Mark as direct (default GA4 behavior)
        return {
          source: "(direct)",
          medium: "(none)",
          campaign: "(not set)",
          content: "",
          term: "",
          gclid: "",
        };
      }

      // Default to direct traffic
      return {
        source: "(direct)",
        medium: "(none)",
        campaign: "(not set)",
        content: "",
        term: "",
        gclid: "",
      };
    },

    /**
     * Get stored attribution from centralized storage
     */
    getStoredAttribution: function () {
      var userData = GA4Utils.storage.getUserData();
      return {
        source: userData.lastSource || "",
        medium: userData.lastMedium || "",
        campaign: userData.lastCampaign || "(not set)",
        content: userData.lastContent || "",
        term: userData.lastTerm || "",
        gclid: userData.lastGclid || "",
      };
    },

    /**
     * Store attribution data in centralized storage
     */
    storeAttributionData: function (
      attribution,
      isNewSession,
      utmParams,
      gclid
    ) {
      // Store attribution if:
      // 1. It's a new session with any attribution, OR
      // 2. We have UTM parameters or gclid (new campaign data), OR
      // 3. The attribution is not direct traffic (preserve non-direct sources)
      var shouldStore =
        isNewSession ||
        utmParams.utm_source ||
        utmParams.utm_medium ||
        gclid ||
        (attribution.source && attribution.source !== "(direct)");

      if (shouldStore && attribution.source && attribution.medium) {
        var userData = GA4Utils.storage.getUserData();
        
        userData.lastSource = attribution.source;
        userData.lastMedium = attribution.medium;
        
        if (attribution.campaign) {
          userData.lastCampaign = attribution.campaign;
        }
        
        if (attribution.content) {
          userData.lastContent = attribution.content;
        }
        
        if (attribution.term) {
          userData.lastTerm = attribution.term;
        }
        
        if (attribution.gclid) {
          userData.lastGclid = attribution.gclid;
        }
        
        GA4Utils.storage.saveUserData(userData);
      }
    },

    /**
     * Build session parameters for tracking (with async location data)
     */
    buildSessionParams: async function (
      session,
      userAgentInfo,
      attribution,
      traffic_type,
      referrer,
      ignore_referrer
    ) {
      var sessionParams = {
        // Core identification (required)
        session_id: session.id,
        client_id: GA4Utils.clientId.get(),

        // Critical for GA4 real-time reporting
        engagement_time_msec: GA4Utils.time.calculateEngagementTime(
          session.start
        ),

        // Session flags
        ...(session.isNew && { session_start: 1 }),

        // Device and browser information
        browser_name: userAgentInfo.browser_name,
        device_type: userAgentInfo.device_type,
        screen_resolution: GA4Utils.device.getScreenResolution(),

        // Device type specific flags - only when true
        ...(ignore_referrer === true && { ignore_referrer: true }),
        ...(userAgentInfo.is_mobile === true && { is_mobile: true }),
        ...(userAgentInfo.device_type === "tablet" && { is_tablet: true }),
        ...(userAgentInfo.device_type === "desktop" && { is_desktop: true }),

        // Language and attribution
        language: navigator.language || "",
        source: attribution.source,
        medium: attribution.medium,
        campaign: attribution.campaign,
        traffic_type: traffic_type,

        // Add UTM content/term only if present to save param slots
        ...(attribution.content && { content: attribution.content }),
        ...(attribution.term && { term: attribution.term }),
        ...(attribution.gclid && { gclid: attribution.gclid }),

        // Page information
        page_title: document.title,
        page_location: window.location.href,
        page_referrer: referrer,

        // Timezone information (always included as base location data)
        timezone: this.getTimezone(),

        // Shortened user agent
        user_agent: userAgentInfo.user_agent,

        // Timestamp
        event_timestamp: Math.floor(Date.now() / 1000),
      };

      // Add timezone fallback location data first
      var timezone = sessionParams.timezone;
      if (timezone) {
        var timezoneLocation = GA4Utils.helpers.getLocationFromTimezone(timezone);
        if (timezoneLocation.continent) sessionParams.geo_continent = timezoneLocation.continent;
        if (timezoneLocation.country) sessionParams.geo_country_tz = timezoneLocation.country;
        if (timezoneLocation.city) sessionParams.geo_city_tz = timezoneLocation.city;
      }

      // Only try to get precise location data if we're actually sending events now (not queuing)
      // For queued events, location will be added when events are actually sent after consent
      if (window.GA4ConsentManager && window.GA4ConsentManager.hasConsent && window.GA4ConsentManager.hasConsent()) {
        try {
          // Check if we have consent for precise location data
          var consentData = null;
          if (window.GA4ConsentManager && typeof window.GA4ConsentManager.getConsentForServerSide === 'function') {
            consentData = window.GA4ConsentManager.getConsentForServerSide();
          } else {
            consentData = GA4Utils.consent.getForServerSide();
          }

          if (consentData && consentData.analytics_storage === "GRANTED") {
            // Check if IP geolocation is disabled by admin
            if (this.config.consentSettings && this.config.consentSettings.disableAllIP) {
              this.log("IP geolocation disabled by admin - using timezone fallback only");
            } else {
              this.log("Attempting to get precise location data - consent granted");
              
              // Wait for location data without timeout
              const locationData = await this.getUserLocation();

              if (locationData && locationData.latitude && locationData.longitude) {
                // Replace timezone fallback with precise data
                if (locationData.latitude) sessionParams.geo_latitude = locationData.latitude;
                if (locationData.longitude) sessionParams.geo_longitude = locationData.longitude;
                if (locationData.city) sessionParams.geo_city = locationData.city;
                if (locationData.country) sessionParams.geo_country = locationData.country;
                if (locationData.region) sessionParams.geo_region = locationData.region;
                
                this.log("Got precise location data", {
                  city: locationData.city,
                  country: locationData.country,
                  timezone_fallback_used: false
                });
              } else {
                this.log("Location API returned incomplete data, using timezone fallback");
              }
            }
          } else {
            this.log("Analytics consent denied, using timezone fallback only");
          }
        } catch (error) {
          this.log("Location API failed, using timezone fallback:", error.message);
        }
      } else {
        this.log("No consent determined yet or consent denied - events will be queued with timezone fallback only");
      }

      return sessionParams;
    },

    /**
     * Get user timezone
     */
    getTimezone: function() {
      return GA4Utils.helpers.getTimezone();
    },

    // Method to complete page view tracking after location attempt
    completePageViewTracking: async function (sessionParams, isNewSession) {
      // Log session information
      this.log("Page view params:", sessionParams);
      this.log("Is new session: " + isNewSession);
      this.log(
        "Is order received page: " +
          GA4Utils.page.isOrderConfirmationPage(this.config, "ga4")
      );

      // Track appropriate event based on page type
      if (GA4Utils.page.isProductListPage()) {
        this.trackProductListView(sessionParams);
      } else if (GA4Utils.page.isProductPage(this.config)) {
        this.trackProductView(sessionParams);
      } else {
        // Regular page - track page_view
        this.trackEvent("page_view", sessionParams);
      }
    },

    /**
     * Track product list view
     */
    trackProductListView: function (sessionParams) {
      var productListData = this.getProductListItems();

      if (productListData.items.length > 0) {
        // Combine session parameters with item list data
        var itemListData = {
          ...sessionParams,
          item_list_name: productListData.listName,
          item_list_id: productListData.listId,
          items: productListData.items,
        };

        this.trackEvent("view_item_list", itemListData);

        // Setup click tracking for products
        this.setupProductClickTracking(
          productListData.listName,
          productListData.listId,
          sessionParams
        );
      } else {
        // Fall back to page_view if no products found
        this.trackEvent("page_view", sessionParams);
      }
    },

    /**
     * Track single product view
     */
    trackProductView: function (sessionParams) {
      var productData = this.config.productData;

      var viewItemData = {
        ...sessionParams,
        currency: this.config.currency || "EUR",
        value: productData.price,
        items: [productData],
      };

      this.trackEvent("view_item", viewItemData);
    },

    // Set up event listeners
    setupEventListeners: function () {
      var self = this;

      // Track outbound links
      $(document).on(
        "click",
        'a[href^="http"]:not([href*="' + window.location.hostname + '"])',
        function () {
          var href = $(this).attr("href");
          var text = $(this).text();

          self.trackEvent("outbound_link", {
            link_url: href,
            link_text: text,
          });
        }
      );

      this.setupFormTracking();
      this.setupEcommerceTracking();
      this.setupScrollTracking();
      this.setupEngagementTracking();
      this.setupFileDownloadTracking();
      this.setupVideoTracking();
      this.setupSearchTracking();
      this.setupContactTracking();
      this.setupSocialTracking();
      this.setupButtonTracking();
      this.setupVisibilityTracking();
    },

    /**
     * Setup form tracking
     */

    setupFormTracking: function () {
      var self = this;
      var baseExclusions = ".cart, .woocommerce-cart-form, .checkout, .woocommerce-checkout";
      var conversionExclusions = [];
      
      if (self.config.conversionFormIds) {
        conversionExclusions = self.config.conversionFormIds.split(",").map(id => `#gform_${id.trim()}`);
      }
      
      if (self.config.quoteData && self.config.yithRaqFormId) {
        conversionExclusions.push(`#gform_${self.config.yithRaqFormId}`);
      }
      
      var trackForms = conversionExclusions.length ? 
        `form:not(${baseExclusions}, ${conversionExclusions.join(", ")})` : 
        `form:not(${baseExclusions})`;

      // Track form submissions (excluding WooCommerce forms and conversion forms)
      $(document).on("submit", trackForms, function () {
        // Skip tracking form submissions that are WooCommerce add to cart forms
        if (
          $(this).hasClass("cart") ||
          $(this).find(".add_to_cart_button").length ||
          $(this).find('button[name="add-to-cart"]').length
        ) {
          return;
        }

        var formId = $(this).attr("id") || "unknown";
        var formAction = $(this).attr("action") || "unknown";

        self.trackEvent("form_submit", {
          form_id: formId,
          form_action: formAction,
        });
      });

      // Track conversion form submissions
      if (
        typeof self.config.conversionFormIds !== "undefined" &&
        self.config.conversionFormIds
      ) {
        var conversionIds = self.config.conversionFormIds.split(",");

        conversionIds.forEach(function (id) {
          var trimmedId = id.trim();
          $(`#gform_${trimmedId}`).on("submit", function (event) {
            var formId = $(this).attr("id") || "unknown";
            var formAction = $(this).attr("action") || "unknown";
            var trackingData = {
              form_id: formId,
              form_action: formAction,
              pageTitle: document.title,
            };

            self.trackEvent("form_conversion", trackingData);
          });
        });
      }
    },
    /**
     * Setup file download tracking
     */
    setupFileDownloadTracking: function () {
      var self = this;

      var fileSelector = 'a[href*=".pdf"], a[href*=".zip"], a[href*=".doc"], a[href*=".docx"], a[href*=".xls"], a[href*=".xlsx"], a[href*=".ppt"], a[href*=".pptx"]';
      $(document).on("click", fileSelector, function () {
        var href = $(this).attr("href");
        var fileName = href.split("/").pop().split("?")[0];
        self.trackEvent("file_download", {
          file_name: fileName,
          file_extension: fileName.split(".").pop().toLowerCase(),
          link_url: href,
        });
      });
    },

    /**
     * Setup search tracking
     */
    setupSearchTracking: function () {
      var self = this;

      $(document).on("submit", 'form[role="search"], .search-form', function () {
        var searchQuery = $(this).find('input[type="search"], input[name*="search"], input[name="s"]').val();
        if (searchQuery && searchQuery.trim() !== "") {
          self.trackEvent("search", { search_term: searchQuery.trim() });
        }
      });
    },

    /**
     * Setup contact tracking (phone and email)
     */
    setupContactTracking: function () {
      var self = this;

      // Track phone number clicks
      $(document).on("click", 'a[href^="tel:"]', function () {
        var phone = $(this).attr("href").replace("tel:", "");
        self.trackEvent("phone_call", {
          phone_number: phone,
        });
      });

      // Track email clicks
      $(document).on("click", 'a[href^="mailto:"]', function () {
        var email = $(this).attr("href").replace("mailto:", "");
        self.trackEvent("email_click", {
          email_address: email,
        });
      });
    },

    /**
     * Setup social media tracking
     */
    setupSocialTracking: function () {
      var self = this;

      var socialSelector = 'a[href*="facebook.com"], a[href*="twitter.com"], a[href*="linkedin.com"], a[href*="instagram.com"], a[href*="youtube.com"], a[href*="tiktok.com"]';
      $(document).on("click", socialSelector, function () {
        var href = $(this).attr("href");
        self.trackEvent("social_click", {
          platform: GA4Utils.helpers.getSocialPlatform(href),
          link_url: href,
        });
      });
    },

    /**
     * Setup button tracking
     */
    setupButtonTracking: function () {
      var self = this;

      var buttonSelector = 'button, .btn, .button, input[type="submit"], input[type="button"]';
      $(document).on("click", buttonSelector, function () {
        if ($(this).attr("type") === "submit" && $(this).closest("form").length) return;
        
        var buttonText = $(this).text() || $(this).val() || $(this).attr("aria-label") || "Unknown";
        self.trackEvent("button_click", {
          button_text: buttonText.trim(),
          button_id: $(this).attr("id") || "",
          button_class: $(this).attr("class") || "",
        });
      });
    },

    /**
     * Setup visibility tracking
     */
    setupVisibilityTracking: function () {
      var self = this;

      document.addEventListener("visibilitychange", function () {
        if (document.hidden) {
          self.trackEvent("page_hidden", {
            time_on_page: Date.now() - self.pageStartTime,
          });
        } else {
          self.trackEvent("page_visible", {});
        }
      });
    },

    // Setup scroll depth tracking
    setupScrollTracking: function () {
      var self = this;
      var scrollDepths = [25, 50, 75, 90];
      var trackedDepths = [];

      $(window).on("scroll", function () {
        var scrollTop = $(window).scrollTop();
        var docHeight = $(document).height() - $(window).height();
        var scrollPercent = Math.round((scrollTop / docHeight) * 100);

        scrollDepths.forEach(function (depth) {
          if (scrollPercent >= depth && trackedDepths.indexOf(depth) === -1) {
            trackedDepths.push(depth);
            self.trackEvent("scroll", {
              percent_scrolled: depth,
            });
          }
        });
      });
    },

    // Setup user engagement tracking
    setupEngagementTracking: function () {
      var self = this;
      var engagementIntervals = [15, 30, 60, 120]; // seconds
      var trackedIntervals = [];

      setInterval(function () {
        if (!document.hidden) {
          var timeOnPage = Math.floor((Date.now() - self.pageStartTime) / 1000);

          engagementIntervals.forEach(function (interval) {
            if (
              timeOnPage >= interval &&
              trackedIntervals.indexOf(interval) === -1
            ) {
              trackedIntervals.push(interval);
              self.trackEvent("custom_user_engagement", {
                engagement_time_msec: interval * 1000,
              });
            }
          });
        }
      }, 15000); // Check every 15 seconds
    },

    // Setup video tracking
    setupVideoTracking: function () {
      var self = this;

      // Track when videos become visible in viewport
      $('iframe[src*="youtube.com"], iframe[src*="vimeo.com"]').each(
        function () {
          var $video = $(this);
          var videoUrl = $video.attr("src");
          var videoPlatform =
            videoUrl.indexOf("youtube") > -1 ? "YouTube" : "Vimeo";

          // Use Intersection Observer if available
          if ("IntersectionObserver" in window) {
            var observer = new IntersectionObserver(
              function (entries) {
                entries.forEach(function (entry) {
                  if (entry.isIntersecting) {
                    self.trackEvent("video_view", {
                      video_platform: videoPlatform,
                      video_url: videoUrl,
                    });
                    observer.unobserve(entry.target);
                  }
                });
              },
              { threshold: 0.5 }
            );

            observer.observe($video[0]);
          }
        }
      );
    },

    // Set up e-commerce tracking
    setupEcommerceTracking: function () {
      if (!this.config.isEcommerceEnabled) {
        return;
      }

      // Track add to cart events
      this.setupAddToCartTracking();

      // Track remove from cart events
      this.setupRemoveFromCartTracking();

      // Track checkout events
      this.setupCheckoutTracking();

      // Track purchase events
      this.setupPurchaseTracking();

      // Track quote form submission
      this.setupQuoteTracking();
    },

    /**
     * Setup add to cart tracking
     */
    setupAddToCartTracking: function () {
      var self = this;

      // Enhanced selector for more add-to-cart cases
      var addToCartSelectors = [
        ".single_add_to_cart_button.buy-now",
        '.cart button[type="submit"]',
        '.cart button[name="add-to-cart"]',
        'input[name="wc-buy-now"]',
        ".direct-inschrijven",
        ".add-request-quote-button",
        // Additional common WooCommerce selectors
        ".single_add_to_cart_button",
        'button[name="add-to-cart"]',
        ".ajax_add_to_cart",
        ".add_to_cart_button",
        ".product-add-to-cart button",
        ".wc-forward",
        // Shop/archive page buttons
        ".add-to-cart-button",
        ".product .button",
        ".woocommerce-loop-add-to-cart-link",
        // Variable product buttons
        ".variations_form .single_add_to_cart_button",
        // Quick view buttons
        ".quick-view-add-to-cart",
        // Mobile specific
        ".mobile-add-to-cart",
        // Links with add-to-cart parameter
        'a[href*="?add-to-cart="]',
        'a[href*="&add-to-cart="]',
      ].join(", ");

      $(document).on("click", addToCartSelectors, function (e) {
        var $button = $(this);
        var $productContainer = $button.closest(
          ".product, .woocommerce-loop-product, .post, .shop-item, [data-product-id]"
        );
        var quantity =
          parseInt($("input.qty, input[name='quantity'], .qty").val()) || 1;
        var productData = self.config.productData;

        // Extract product data from HTML if not available from config
        if (!productData || !productData.item_id || !productData.item_name) {
          productData = self.extractProductDataFromHTML(
            $button,
            $productContainer
          );
        }

        // If we have complete product data, send full event
        if (productData && productData.item_id && productData.item_name) {
          var itemData = Object.assign({}, productData, {
            quantity: quantity,
          });

          self.trackEvent("add_to_cart", {
            currency: productData.currency || "EUR",
            value: (productData.price || 0) * quantity,
            items: [itemData],
          });
        }
        // If we only have item_id, send minimal event
        else {
          var item_id = self.getProductIdOnly($button, $productContainer);
          if (item_id) {
            self.trackEvent("add_to_cart", {
              currency: "EUR",
              value: 0,
              items: [
                {
                  item_id: item_id,
                  quantity: quantity,
                },
              ],
            });
            self.log(
              "Tracked minimal 'add_to_cart' with item_id only: " + item_id
            );
          } else {
            self.log("Could not track 'add_to_cart' - no product ID found", {
              button: $button.attr("class"),
              container: $productContainer.length,
            });
          }
        }
      });
    },

    // Simplified function to just get product ID
    getProductIdOnly: function ($button, $container) {
      var item_id = null;

      try {
        // Method 1: Get from button data attributes
        item_id = $button.data("product_id") || $button.data("product-id");

        // Method 2: Get from container data attributes
        if (!item_id && $container.length) {
          item_id =
            $container.data("product-id") ||
            $container.data("product_id") ||
            $container.attr("data-ff-post-id") ||
            $container.find("[data-product-id]").first().data("product-id");
        }

        // Method 3: Get from hidden input or form
        if (!item_id) {
          var $form = $button.closest("form");
          if ($form.length) {
            item_id = $form
              .find('input[name="add-to-cart"], input[name="product_id"]')
              .val();
          }
        }

        // Method 4: Extract from URL or href
        if (!item_id) {
          var href = $button.attr("href");
          if (href && href.includes("add-to-cart=")) {
            var match = href.match(/add-to-cart=(\d+)/);
            if (match) item_id = match[1];
          }
        }

        return item_id ? String(item_id) : null;
      } catch (error) {
        return null;
      }
    },

    // Helper function to extract product data from HTML (no GTM data)
    extractProductDataFromHTML: function ($button, $container) {
      var self = this;
      var productData = {};

      try {
        // Get product ID first
        productData.item_id = self.getProductIdOnly($button, $container);

        if (!productData.item_id) {
          return null;
        }

        // Get product name
        productData.item_name =
          $container
            .find(
              ".woocommerce-loop-product__title a, .woocommerce-loop-product__title"
            )
            .first()
            .text()
            .trim() ||
          $container
            .find(".product-title, .product_title, h1, h2, h3, .entry-title")
            .first()
            .text()
            .trim() ||
          $container.find("a[title]").first().attr("title") ||
          $button.attr("aria-label") ||
          $button.attr("title") ||
          "";

        // Get price
        var priceText = "";
        var $priceElements = $container.find(".price");

        if ($priceElements.length) {
          var $salePrice = $priceElements.find("ins .woocommerce-Price-amount");
          var $regularPrice = $priceElements.find(".woocommerce-Price-amount");

          if ($salePrice.length) {
            priceText = $salePrice.first().text();
          } else if ($regularPrice.length) {
            priceText = $regularPrice.last().text();
          }
        }

        // Extract numeric price value
        if (priceText) {
          var priceMatch = priceText.match(/[\d,]+[.,]?\d*/);
          if (priceMatch) {
            productData.price = parseFloat(priceMatch[0].replace(",", "."));
          }
        }

        // Get currency
        var currencySymbol =
          $container.find(".woocommerce-Price-currencySymbol").first().text() ||
          "€";
        productData.currency = self.getCurrencyFromSymbol(currencySymbol);

        // Get category from CSS classes
        var classList = $container.attr("class") || "";
        var categoryMatch = classList.match(/product_cat-([^\s]+)/);
        if (categoryMatch) {
          productData.item_category = categoryMatch[1].replace(/-/g, " ");
          productData.item_list_id = productData.item_category;
          productData.item_list_name = productData.item_category;
        }

        // Get SKU
        productData.item_sku =
          $button.data("product_sku") || $container.find(".sku").text().trim();

        // Get variant info for variable products
        var $variations = $container.find(
          ".variations select, .variation-selector"
        );
        if ($variations.length) {
          var variants = [];
          $variations.each(function () {
            var $select = $(this);
            var selectedValue = $select.val();
            if (selectedValue && selectedValue !== "") {
              variants.push($select.find("option:selected").text().trim());
            }
          });
          if (variants.length) {
            productData.item_variant = variants.join(" / ");
          }
        }

        // Clean up the item_name
        if (productData.item_name) {
          productData.item_name = productData.item_name
            .replace(/^["']|["']$/g, "")
            .trim();
          productData.item_name = productData.item_name
            .replace(/^Toevoegen aan winkelwagen:\s*["']?|["']?$/g, "")
            .trim();
        }

        // If we don't have a name, return null to trigger minimal tracking
        if (!productData.item_name) {
          return null;
        }

        self.log("Extracted product data from HTML:", productData);
        return productData;
      } catch (error) {
        self.log("Error extracting product data from HTML:", error.message);
        return null;
      }
    },

    // Helper function to convert currency symbols to codes
    getCurrencyFromSymbol: function (symbol) {
      var currencyMap = {
        "€": "EUR",
        $: "USD",
        "£": "GBP",
        "¥": "JPY",
        kr: "SEK",
        zł: "PLN",
        "₹": "INR",
      };

      return currencyMap[symbol.trim()] || "EUR";
    },

    /**
     * Setup remove from cart tracking
     */
    setupRemoveFromCartTracking: function () {
      var self = this;

      $(document).on("click", ".woocommerce-cart-form .remove", function () {
        var $row = $(this).closest("tr");
        var productId = $(this).data("product_id") || "";

        // Extract product information
        var productInfo = self.extractCartProductInfo($row, productId);

        // Track the remove_from_cart event
        if (productId && productInfo.name) {
          self.trackEvent("remove_from_cart", {
            currency:
              (self.config.cartData && self.config.cartData.currency) ||
              self.config.currency ||
              "EUR",
            value: productInfo.price * productInfo.quantity,
            items: [productInfo.itemData],
          });
        } else {
          self.log("Not tracked 'remove_from_cart' - missing required data:", {
            productId: productId,
            productName: productInfo.name,
            price: productInfo.price,
            quantity: productInfo.quantity,
          });
        }
      });
    },

    /**
     * Extract product information from cart row
     */
    extractCartProductInfo: function ($row, productId) {
      // Extract product name
      var productName =
        $row.find(".product-name a").text().trim() ||
        $row.find(".product-name").text().trim();

      // Extract price
      var priceText = "";
      var $priceElement = $row
        .find(".product-price .woocommerce-Price-amount")
        .first();
      if ($priceElement.length === 0) {
        $priceElement = $row.find(".product-price .amount").first();
      }
      if ($priceElement.length === 0) {
        $priceElement = $row.find(".product-price").first();
      }

      if ($priceElement.length > 0) {
        priceText = $priceElement.text().replace(/[^\d.,]/g, "");
      }

      var price = parseFloat(priceText.replace(",", ".")) || 0;
      var quantity =
        parseInt($row.find(".product-quantity input.qty").val()) ||
        parseInt($row.find(".qty input").val()) ||
        1;

      // Try to get additional product data from cart data if available
      var productData = {};
      if (this.config.cartData && this.config.cartData.items) {
        var matchingItem = this.config.cartData.items.find(function (item) {
          return String(item.item_id) === String(productId);
        });

        if (matchingItem) {
          productData = matchingItem;
          productData.quantity = quantity;
        }
      }

      // Build the item data for GA4
      var itemData = {
        item_id: String(productId),
        item_name: productName,
        affiliation:
          productData.affiliation || this.config.siteName || "Website",
        coupon: productData.coupon || "",
        discount: productData.discount || 0,
        index: productData.index || 0,
        item_brand: productData.item_brand || "",
        item_category: productData.item_category || "",
        item_category2: productData.item_category2 || "",
        item_category3: productData.item_category3 || "",
        item_category4: productData.item_category4 || "",
        item_category5: productData.item_category5 || "",
        item_list_id: "cart",
        item_list_name: "Shopping Cart",
        item_variant: productData.item_variant || "",
        location_id: productData.location_id || "",
        price: price,
        quantity: quantity,
      };

      // Remove empty values
      Object.keys(itemData).forEach((key) => {
        if (
          itemData[key] === "" ||
          itemData[key] === null ||
          itemData[key] === undefined
        ) {
          delete itemData[key];
        }
      });

      return {
        name: productName,
        price: price,
        quantity: quantity,
        itemData: itemData,
      };
    },

    /**
     * Setup checkout tracking
     */
    setupCheckoutTracking: function () {
      if ($(".woocommerce-checkout").length) {
        if (!GA4Utils.page.isOrderConfirmationPage(this.config)) {
          this.trackBeginCheckout();
          this.setupCheckoutStepTracking();
        }
      }
    },

    /**
     * Track begin checkout
     */
    trackBeginCheckout: function () {
      // Check if we have cart data from PHP
      if (
        this.config.cartData &&
        this.config.cartData.items &&
        this.config.cartData.items.length > 0
      ) {
        var cartData = this.config.cartData;

        // Track begin_checkout with cart data
        this.trackEvent("begin_checkout", {
          currency: cartData.currency,
          value: cartData.value,
          coupon: cartData.coupon || undefined,
          items: cartData.items,
        });
      } else {
        this.log("No cart data available for checkout tracking");

        // Fallback: Track basic begin_checkout without items
        this.trackEvent("begin_checkout", {
          currency: this.config.currency || "EUR",
          value: 0,
        });
      }
    },

    /**
     * Setup checkout step tracking
     */
    setupCheckoutStepTracking: function () {
      var self = this;
      var cartData = this.config.cartData;

      if (!cartData) return;

      // Track shipping info
      $("form.checkout").on(
        "change",
        '#shipping_method input[type="radio"], #shipping_method input[type="hidden"]',
        function () {
          var shippingMethod = $(this).val();
          var shippingCost = 0;

          // Try to get shipping cost from the selected option
          var shippingLabel = $(
            'label[for="' + $(this).attr("id") + '"]'
          ).text();
          var costMatch = shippingLabel.match(/[\d.,]+/);
          if (costMatch) {
            shippingCost = parseFloat(costMatch[0].replace(",", "."));
          }

          self.trackEvent("add_shipping_info", {
            currency: cartData.currency,
            value: cartData.value + shippingCost,
            coupon: cartData.coupon || undefined,
            shipping_tier: shippingMethod,
            items: cartData.items,
          });
        }
      );

      // Track payment info
      $("form.checkout").on(
        "change",
        'input[name="payment_method"]',
        function () {
          var paymentMethod = $(this).val();

          self.trackEvent("add_payment_info", {
            currency: cartData.currency,
            value: cartData.value,
            coupon: cartData.coupon || undefined,
            payment_type: paymentMethod,
            items: cartData.items,
          });
        }
      );

      // Track when user starts filling billing info
      var billingTracked = false;
      $("form.checkout").on(
        "input change",
        "#billing_first_name, #billing_last_name, #billing_email",
        function () {
          if (!billingTracked) {
            billingTracked = true;

            self.trackEvent("begin_checkout", {
              currency: cartData.currency,
              value: cartData.value,
              coupon: cartData.coupon || undefined,
              items: cartData.items,
              checkout_step: 2,
              checkout_option: "billing_info_started",
            });
          }
        }
      );
    },

    /**
     * Setup purchase tracking
     */
    setupPurchaseTracking: function () {
      var self = this;

      // Track purchase event on order received page using safe tracking
      if (
        GA4Utils.page.isOrderConfirmationPageWithTracking(this.config, "ga4")
      ) {
        // Check if we have order data from the server
        if (this.config.orderData) {
          self.log(
            "Order data found, attempting safe purchase tracking",
            this.config.orderData
          );

          // Use safe tracking to prevent duplicates
          var wasTracked = GA4Utils.page.trackPurchaseSafely(
            function (orderData) {
              self.trackEvent("purchase", orderData);
            },
            "ga4",
            this.config.orderData,
            {
              source: "server_side_tracking",
              data_source: "server_config",
              timestamp_tracked: Date.now(),
            }
          );

          if (wasTracked) {
            self.log("Purchase event tracked successfully");
          } else {
            self.log(
              "Purchase event skipped - already tracked or invalid data"
            );
          }
        } else {
          // Try to extract order data from the page
          self.log(
            "No server order data found, attempting to extract from page"
          );
          var orderData = this.extractOrderDataFromPage();

          if (orderData.transaction_id) {
            // Use safe tracking with extracted data
            var wasTracked = GA4Utils.page.trackPurchaseSafely(
              function (extractedOrderData) {
                self.trackEvent("purchase", extractedOrderData);
              },
              "ga4",
              orderData,
              {
                source: "server_side_tracking",
                data_source: "page_extraction",
                timestamp_tracked: Date.now(),
              }
            );

            if (wasTracked) {
              self.log("Purchase event tracked from extracted data");
            } else {
              self.log(
                "Purchase event skipped - already tracked or invalid extracted data"
              );
            }
          } else {
            self.log("Could not extract valid order data from the page");
          }
        }
      } else {
        // Check if we're on an order page but tracking was skipped
        if (GA4Utils.page.isOrderConfirmationPage(this.config, "ga4")) {
          var orderId = GA4Utils.page.extractOrderId();
          if (orderId) {
            self.log(
              "Order confirmation page detected but purchase already tracked for order: " +
                orderId
            );
          } else {
            self.log("Order confirmation page detected but no order ID found");
          }
        }
      }
    },

    /**
     * Extract order data from page
     */
    extractOrderDataFromPage: function () {
      var orderId = "";
      var orderTotal = 0;

      // Try to get order ID from URL
      var orderIdMatch = window.location.pathname.match(
        /order-received\/(\d+)/
      );
      if (orderIdMatch && orderIdMatch[1]) {
        orderId = orderIdMatch[1];
      }

      return {
        transaction_id: orderId,
        affiliation: this.config.siteName || "Website",
        value: orderTotal,
        currency: this.config.currency || "EUR",
      };
    },

    /**
     * Setup quote tracking
     */
    setupQuoteTracking: function () {
      var self = this;

      if (self.config.yithRaqFormId) {
        $(`#gform_${self.config.yithRaqFormId}`).on("submit", function (event) {
          self.log("request a quote form fired");

          // Check if we have quote data from the server
          if (self.config.quoteData) {
            self.log(
              "Quote data found, tracking purchase event with attribution",
              self.config.quoteData
            );
            self.trackEvent("quote_request", self.config.quoteData);
          } else {
            // Fallback order data
            var orderData = self.extractOrderDataFromPage();
            if (orderData.transaction_id) {
              self.trackEvent("quote_request", orderData);
            } else {
              self.log("Could not extract order data from the page");
            }
          }
        });
      }
    },

    // Check if we're on a product list page
    isProductListPage: function () {
      return GA4Utils.page.isProductListPage();
    },

    // Check if we're on a single product page
    isProductPage: function () {
      return GA4Utils.page.isProductPage(this.config);
    },

    // Get product list items
    getProductListItems: function () {
      var listName = "Product List";
      var listId = "";
      var items = [];

      // Try to determine the list type
      if ($(".woocommerce-products-header__title").length) {
        listName = $(".woocommerce-products-header__title").text().trim();
      } else if ($(".page-title").length) {
        listName = $(".page-title").text().trim();
      }

      // Get products in the list
      $(".products .product").each(function (index) {
        var $product = $(this);
        var productId =
          $product.find(".add_to_cart_button").data("product_id") || "";
        var productName = $product
          .find(".woocommerce-loop-product__title")
          .text()
          .trim();
        var productPrice = $product
          .find(".price .amount")
          .text()
          .replace(/[^0-9,.]/g, "");

        if (productId && productName) {
          items.push({
            item_id: productId,
            item_name: productName,
            price: parseFloat(productPrice),
            index: index + 1,
            item_list_name: listName,
            item_list_id: listId,
          });
        }
      });

      return {
        listName: listName,
        listId: listId,
        items: items,
      };
    },

    // Setup click tracking for products in a list
    setupProductClickTracking: function (listName, listId, sessionParams) {
      var self = this;

      $(".products .product a").on("click", function () {
        var $product = $(this).closest(".product");
        var index = $product.index() + 1;
        var productId =
          $product.find(".add_to_cart_button").data("product_id") || "";
        var productName = $product
          .find(".woocommerce-loop-product__title")
          .text()
          .trim();

        if (productId && productName) {
          var selectItemData = {
            ...sessionParams,
            item_list_name: listName,
            item_list_id: listId,
            items: [
              {
                item_id: productId,
                item_name: productName,
                index: index,
                item_list_name: listName,
                item_list_id: listId,
              },
            ],
          };

          self.trackEvent("select_item", selectItemData);
        }
      });
    },

    /**
     * Get user location (IP-based only) using centralized storage
     */
    getUserLocation: function () {
      return GA4Utils.location.get();
    },

    /**
     * Get location based on IP address with fallback services
     */
    getIPBasedLocation: function () {
      return new Promise((resolve) => {
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
            };
            this.log("Location obtained from ipapi.co", locationData);
            resolve(locationData);
          })
          .catch((error) => {
            this.log(
              "First IP location service failed, trying fallback",
              error
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
                };

                this.log(
                  "Location obtained from fallback service",
                  locationData
                );
                resolve(locationData);
              })
              .catch((secondError) => {
                this.log(
                  "Second IP location service failed, trying final fallback",
                  secondError
                );

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
                    };

                    this.log(
                      "Location obtained from final fallback service",
                      locationData
                    );
                    resolve(locationData);
                  })
                  .catch((finalError) => {
                    this.log("All IP location services failed", finalError);
                    resolve({});
                  });
              });
          });
      });
    },

    /**
     * Build complete event data with all session context, attribution, and page data
     * This preserves the original page context when events are queued
     */
    buildCompleteEventData: async function (eventName, eventParams) {
      // Create a copy of the event params
      var params = JSON.parse(JSON.stringify(eventParams));
      var session = GA4Utils.session.get();

      // Add debug_mode and timestamp if not present
      if (!params.hasOwnProperty("debug_mode")) {
        if (Boolean(this.config.debugMode) === true) {
          params.debug_mode = Boolean(this.config.debugMode);
        }
      }

      if (!params.hasOwnProperty("event_timestamp")) {
        params.event_timestamp = Math.floor(Date.now() / 1000);
      }

      // Get consent data from consent manager (will be DENIED at queueing time)
      var consentData = null;
      if (window.GA4ConsentManager && typeof window.GA4ConsentManager.getConsentForServerSide === 'function') {
        consentData = window.GA4ConsentManager.getConsentForServerSide();
      } else {
        // Fallback to GA4Utils
        consentData = GA4Utils.consent.getForServerSide();
      }

      // Add session information (preserve original session context)
      if (!params.hasOwnProperty("session_id")) {
        params.session_id = session.id;
      }
      if (!params.hasOwnProperty("session_count")) {
        params.session_count = session.sessionCount;
      }
      if (!params.hasOwnProperty("engagement_time_msec")) {
        params.engagement_time_msec = GA4Utils.time.calculateEngagementTime(
          session.start
        );
      }

      // Capture and preserve COMPLETE original page context and attribution
      // Force override ALL page and attribution data to preserve original context
      var originalPageLocation = window.location.href;
      var originalPageTitle = document.title;
      var originalPageReferrer = document.referrer || "";
      
      // Get original attribution data from current page (this is what we want to preserve!)
      var originalUtmParams = GA4Utils.utm.getAll();
      var originalGclid = GA4Utils.gclid.get();
      var originalAttribution = this.calculateAttributionForOriginalPage(
        originalUtmParams,
        originalGclid,
        originalPageReferrer
      );
      
      // Override ALL page and attribution context in params
      params.page_location = originalPageLocation;
      params.page_title = originalPageTitle;
      params.page_referrer = originalPageReferrer;
      
      // Override attribution data
      if (originalAttribution.source) params.source = originalAttribution.source;
      if (originalAttribution.medium) params.medium = originalAttribution.medium;
      if (originalAttribution.campaign) params.campaign = originalAttribution.campaign;
      if (originalAttribution.content) params.content = originalAttribution.content;
      if (originalAttribution.term) params.term = originalAttribution.term;
      if (originalAttribution.gclid) params.gclid = originalAttribution.gclid;
      
      // Always add timezone (preserve original page timezone)
      if (!params.timezone) {
        params.timezone = this.getTimezone();
      }
      
      // Add location data from timezone as fallback
      if (params.timezone) {
        var timezoneLocation = GA4Utils.helpers.getLocationFromTimezone(params.timezone);
        if (!params.geo_continent && timezoneLocation.continent) {
          params.geo_continent = timezoneLocation.continent;
        }
        if (!params.geo_country_tz && timezoneLocation.country) {
          params.geo_country_tz = timezoneLocation.country;
        }
        if (!params.geo_city_tz && timezoneLocation.city) {
          params.geo_city_tz = timezoneLocation.city;
        }
      }
      
      this.log("🔒 Captured COMPLETE original context for preservation", {
        originalPageLocation: originalPageLocation,
        originalPageTitle: originalPageTitle,
        originalPageReferrer: originalPageReferrer,
        originalSource: originalAttribution.source,
        originalMedium: originalAttribution.medium,
        originalCampaign: originalAttribution.campaign
      });

      // Add location data based on current consent (will be DENIED initially)
      await this.addLocationDataWithConsent(params, consentData);

      // Get client ID
      var clientId = this.getConsentAwareClientId(consentData);
      if (clientId) {
        params.client_id = clientId;
      }

      // Get user agent and client behavior data (preserve original context)
      var userAgentInfo = GA4Utils.device.parseUserAgent();
      var clientBehavior = GA4Utils.botDetection.getClientBehaviorData();


      // Add bot detection data for Cloudflare Worker analysis
      params.botData = {
        user_agent_full: userAgentInfo.user_agent,
        browser_name: userAgentInfo.browser_name,
        device_type: userAgentInfo.device_type,
        is_mobile: userAgentInfo.is_mobile,
        has_javascript: clientBehavior.hasJavaScript,
        screen_available_width: clientBehavior.screenAvailWidth,
        screen_available_height: clientBehavior.screenAvailHeight,
        color_depth: clientBehavior.colorDepth,
        pixel_depth: clientBehavior.pixelDepth,
        timezone: clientBehavior.timezone,
        platform: clientBehavior.platform,
        cookie_enabled: clientBehavior.cookieEnabled,
        hardware_concurrency: clientBehavior.hardwareConcurrency,
        max_touch_points: clientBehavior.maxTouchPoints,
        webdriver_detected: clientBehavior.webdriver,
        has_automation_indicators: clientBehavior.hasAutomationIndicators,
        page_load_time: clientBehavior.pageLoadTime,
        user_interaction_detected: clientBehavior.hasInteracted,
        bot_score: GA4Utils.botDetection.calculateBotScore(
          userAgentInfo,
          clientBehavior
        ),
      };

      // Store original consent status for later processing
      params._originalConsentStatus = consentData;

      this.log("Built complete event data preserving original context", {
        eventName: eventName,
        pageLocation: params.page_location,
        sessionId: params.session_id
      });

      return params;
    },

    /**
     * Calculate attribution for the original page (similar to calculateAttribution but for preservation)
     */
    calculateAttributionForOriginalPage: function(utmParams, gclid, referrer) {
      var referrerDomain = "";
      var ignore_referrer = false;
      
      // Parse referrer if it exists
      if (referrer) {
        try {
          var referrerURL = new URL(referrer);
          referrerDomain = referrerURL.hostname;
          
          // Check if the referrer is from the same domain (internal link)
          if (referrerDomain === window.location.hostname) {
            ignore_referrer = true;
          }
        } catch (e) {
          this.log("Invalid referrer URL:", referrer);
        }
      }
      
      // Use the existing calculateAttribution logic but for current page context
      return this.calculateAttribution(
        utmParams,
        gclid, 
        referrerDomain,
        referrer,
        ignore_referrer,
        true // Assume new session for original page calculation
      );
    },

    trackEvent: function (eventName, eventParams = {}) {
      this.log("Tracking event: " + eventName, {
        eventName: eventName,
        hasPageLocation: !!eventParams.page_location,
        hasSessionId: !!eventParams.session_id,
        hasSource: !!eventParams.source,
        pageLocation: eventParams.page_location || 'not set',
        currentPageLocation: window.location.href
      });
      
      // Add stored attribution to conversion events
      var conversionEvents = ['purchase', 'quote_request', 'form_conversion'];
      if (conversionEvents.includes(eventName)) {
        var storedAttribution = this.getStoredAttribution();
        
        // Only add attribution if not already present and stored attribution exists
        if (!eventParams.source && storedAttribution.source) {
          eventParams.source = storedAttribution.source;
        }
        if (!eventParams.medium && storedAttribution.medium) {
          eventParams.medium = storedAttribution.medium;
        }
        if (!eventParams.campaign && storedAttribution.campaign) {
          eventParams.campaign = storedAttribution.campaign;
        }
        if (!eventParams.content && storedAttribution.content) {
          eventParams.content = storedAttribution.content;
        }
        if (!eventParams.term && storedAttribution.term) {
          eventParams.term = storedAttribution.term;
        }
        
        // Add traffic_type based on stored attribution
        if (!eventParams.traffic_type) {
          eventParams.traffic_type = GA4Utils.traffic.getType(
            storedAttribution.source,
            storedAttribution.medium,
            null // No referrer domain for stored attribution
          );
        }
        
        // Debug log only in debug mode
        if (this.config.debugMode) {
          console.log('DEBUG: Conversion event enriched with stored attribution:', {
            eventName: eventName,
            source: eventParams.source,
            medium: eventParams.medium,
            campaign: eventParams.campaign,
            traffic_type: eventParams.traffic_type
          });
        }
      }

      // Add debug_mode and timestamp if not present
      if (!eventParams.hasOwnProperty("debug_mode")) {
        if (Boolean(this.config.debugMode) === true) {
          eventParams.debug_mode = Boolean(this.config.debugMode);
        }
      }

      if (!eventParams.hasOwnProperty("event_timestamp")) {
        eventParams.event_timestamp = Math.floor(Date.now() / 1000);
      }

      // Check consent status via consent manager
      if (window.GA4ConsentManager && typeof window.GA4ConsentManager.shouldSendEvent === 'function') {
        // Check if eventParams already contains complete session data
        var hasCompleteData = eventParams.hasOwnProperty('page_location') && 
                             eventParams.hasOwnProperty('source') && 
                             eventParams.hasOwnProperty('medium') &&
                             eventParams.hasOwnProperty('session_id');
        
        var criticalEvents = ['page_view', 'custom_session_start', 'custom_first_visit'];
        
        if (criticalEvents.includes(eventName) && hasCompleteData) {
          // eventParams already contains complete session data - queue it directly
          var shouldSend = window.GA4ConsentManager.shouldSendEvent(eventName, eventParams, eventParams);
          
          if (!shouldSend) {
            this.log("🎯 Critical event queued with pre-built session data: " + eventName, {
              originalPageLocation: eventParams.page_location,
              originalSource: eventParams.source,
              originalMedium: eventParams.medium,
              sessionId: eventParams.session_id,
              currentPageLocation: window.location.href
            });
            return; // Event was queued with complete data
          }
        } else {
          // Regular processing for non-critical events or events without complete data
          var shouldSend = window.GA4ConsentManager.shouldSendEvent(eventName, eventParams);
          
          if (!shouldSend) {
            this.log("Event queued by consent manager: " + eventName);
            return; // Event was queued, don't send now
          }
        }
        
        // Consent manager says we can send, ensure our flag is also set
        if (!this.consentReady) {
          this.log("🔧 Consent manager says send, updating consentReady flag");
          this.consentReady = true;
        }
      } else if (!this.consentReady) {
        this.log("Consent not ready, skipping event: " + eventName, {
          consentReady: this.consentReady,
          hasConsentManager: !!(window.GA4ConsentManager)
        });
        return;
      }

      // Send the event
      this.trackEventInternal(eventName, eventParams);
    },

    /**
     * Internal tracking method (bypasses consent check)
     */
    trackEventInternal: function(eventName, eventParams) {
      // Always send server-side event (server-side tagging is always enabled)
      this.sendServerSideEvent(eventName, eventParams);
    },

    /**
     * Send raw event data without re-enriching (for queued events with complete data)
     */
    sendRawEventData: async function(eventName, completeEventData) {
      this.log("Sending raw event data (preserving original context)", {
        eventName: eventName,
        originalPageUrl: completeEventData.page_location,
        sessionId: completeEventData.session_id
      });
      
      // Update consent data to current status since original was DENIED (minimal fields only)
      var currentConsent = window.GA4ConsentManager ? 
        window.GA4ConsentManager.getConsentForServerSide() : 
        GA4Utils.consent.getForServerSide();
      completeEventData.consent = this.getMinimalConsent(currentConsent);
      
      // If consent is now granted, refresh location data for better accuracy
      // Use ad_user_data as the basis for location data collection
      if (currentConsent && currentConsent.analytics_storage === "GRANTED") {
        // Check if IP geolocation is disabled by admin
        if (this.config.consentSettings && this.config.consentSettings.disableAllIP) {
          this.log("IP geolocation disabled by admin - keeping timezone fallback for queued event");
        } else {
          try {
            this.log("Consent granted - refreshing location data for queued event");
            const locationData = await this.getUserLocation();
            
            if (locationData && locationData.latitude && locationData.longitude) {
              // Update with precise location data
              completeEventData.geo_latitude = locationData.latitude;
              completeEventData.geo_longitude = locationData.longitude;
            completeEventData.geo_city = locationData.city;
            completeEventData.geo_country = locationData.country;
            completeEventData.geo_region = locationData.region;
            
              this.log("Updated queued event with precise location data", {
                city: locationData.city,
                country: locationData.country
              });
            }
          } catch (error) {
            this.log("Failed to refresh location data for queued event, keeping timezone fallback:", error.message);
          }
        }
      }
      
      // Apply current GDPR anonymization based on new consent status
      if (window.GA4ConsentManager && typeof window.GA4ConsentManager.applyGDPRAnonymization === 'function') {
        completeEventData = window.GA4ConsentManager.applyGDPRAnonymization(completeEventData);
      } else {
        completeEventData = this.applyGDPRAnonymization(completeEventData, completeEventData.consent);
      }
      
      // Remove the temporary original consent status
      delete completeEventData._originalConsentStatus;
      
      // Use Cloudflare Worker endpoint, or queue if not available
      var endpoint = this.config.cloudflareWorkerUrl;
      var data = this.formatEventData(eventName, completeEventData, endpoint);
      
      if (endpoint) {
        this.sendAjaxPayload(endpoint, data);
      } else {
        this.log("Cloudflare Worker URL not loaded yet, using auto-detection");
        // Use 'auto' to trigger endpoint detection and queuing logic
        this.sendAjaxPayload('auto', data);
      }
    },

  /**
     * Enhanced sendServerSideEvent with consent handling
     */
    sendServerSideEvent: async function (eventName, eventParams) {
      // Create a copy of the event params
      var params = JSON.parse(JSON.stringify(eventParams));
      var session = GA4Utils.session.get();

      // Get consent data from consent manager
      var consentData = null;
      if (window.GA4ConsentManager && typeof window.GA4ConsentManager.getConsentForServerSide === 'function') {
        consentData = window.GA4ConsentManager.getConsentForServerSide();
      } else {
        // Fallback to GA4Utils
        consentData = GA4Utils.consent.getForServerSide();
      }

      this.log("Current consent status for server-side event", consentData);

      // Add user ID if available and consent allows
      if (!params.hasOwnProperty("user_id") && this.config.user_id) {
        if (consentData.analytics_storage === "GRANTED") {
          params.user_id = this.config.user_id;
        }
      }

      // Add session information
      if (!params.hasOwnProperty("session_id")) {
        params.session_id = session.id;
      }
      if (!params.hasOwnProperty("session_count")) {
        params.session_count = session.sessionCount;
      }
      if (!params.hasOwnProperty("engagement_time_msec")) {
        params.engagement_time_msec = GA4Utils.time.calculateEngagementTime(
          session.start
        );
      }

      // Handle location data based on consent
      await this.addLocationDataWithConsent(params, consentData);

      // Get client ID (handle consent-based anonymization)
      var clientId = this.getConsentAwareClientId(consentData);
      if (clientId) {
        params.client_id = clientId;
        this.log("Using client ID: " + clientId);
      }

      // Get user agent and client behavior data
      var userAgentInfo = GA4Utils.device.parseUserAgent();
      var clientBehavior = GA4Utils.botDetection.getClientBehaviorData();

      // Perform bot detection
      var botDetectionResult = GA4Utils.botDetection.isBot(
        userAgentInfo,
        params,
        clientBehavior
      );

      if (botDetectionResult) {
        this.log("Bot detected in sendServerSideEvent - aborting send", {
          eventName: eventName,
          userAgent: userAgentInfo.user_agent,
          geoLocation: `${params.geo_city}, ${params.geo_country}`,
          botScore: GA4Utils.botDetection.calculateBotScore(
            userAgentInfo,
            clientBehavior
          ),
        });
        return; // Don't send bot traffic
      }


      // Add bot detection data for Cloudflare Worker analysis
      params.botData = {
        user_agent_full: userAgentInfo.user_agent,
        browser_name: userAgentInfo.browser_name,
        device_type: userAgentInfo.device_type,
        is_mobile: userAgentInfo.is_mobile,
        has_javascript: clientBehavior.hasJavaScript,
        screen_available_width: clientBehavior.screenAvailWidth,
        screen_available_height: clientBehavior.screenAvailHeight,
        color_depth: clientBehavior.colorDepth,
        pixel_depth: clientBehavior.pixelDepth,
        timezone: clientBehavior.timezone,
        platform: clientBehavior.platform,
        cookie_enabled: clientBehavior.cookieEnabled,
        hardware_concurrency: clientBehavior.hardwareConcurrency,
        max_touch_points: clientBehavior.maxTouchPoints,
        webdriver_detected: clientBehavior.webdriver,
        has_automation_indicators: clientBehavior.hasAutomationIndicators,
        page_load_time: clientBehavior.pageLoadTime,
        user_interaction_detected: clientBehavior.hasInteracted,
        bot_score: GA4Utils.botDetection.calculateBotScore(
          userAgentInfo,
          clientBehavior
        ),
      };

      // Apply GDPR anonymization based on consent
      if (window.GA4ConsentManager && typeof window.GA4ConsentManager.applyGDPRAnonymization === 'function') {
        params = window.GA4ConsentManager.applyGDPRAnonymization(params);
      } else {
        params = this.applyGDPRAnonymization(params, consentData);
      }

      // Add consent data to the payload (only essential fields)
      params.consent = this.getMinimalConsent(consentData);

      // Use Cloudflare Worker endpoint, or queue if not available
      var endpoint = this.config.cloudflareWorkerUrl;
      
      // Format data based on endpoint type
      var data = this.formatEventData(eventName, params, endpoint);

      if (endpoint) {
        this.log("Sending to endpoint: " + endpoint, {
          consent: this.getMinimalConsent(consentData),
          anonymized: consentData.analytics_storage !== "GRANTED",
          eventName: eventName
        });
        
        this.sendAjaxPayload(endpoint, data);
      } else {
        this.log("Cloudflare Worker URL not loaded yet, queuing event", {
          eventName: eventName,
          consent: this.getMinimalConsent(consentData)
        });
        
        // Use 'auto' to trigger endpoint detection and queuing logic
        this.sendAjaxPayload('auto', data);
      }
    },


  /**
   * Get consent-aware client ID
   */
  getConsentAwareClientId: function(consentData) {
    if (window.GA4ConsentManager && typeof window.GA4ConsentManager.getConsentAwareClientId === 'function') {
      return window.GA4ConsentManager.getConsentAwareClientId();
    }
    
    // Fallback implementation
    if (consentData.analytics_storage === "GRANTED") {
      return GA4Utils.clientId.get();
    } else {
      var sessionId = GA4Utils.session.get().id;
      return "session_" + sessionId;
    }
  },

  /**
   * Get minimal consent object with only essential fields for payload optimization
   */
  getMinimalConsent: function(consentData) {
    if (!consentData) {
      return {
        ad_personalization: "DENIED",
        ad_user_data: "DENIED"
      };
    }
    
    return {
      ad_personalization: consentData.ad_personalization || "DENIED",
      ad_user_data: consentData.ad_user_data || "DENIED"
    };
  },

  /**
   * Add location data considering consent
   */
  addLocationDataWithConsent: async function(params, consentData) {
    // Always add timezone parameter as base information
    var timezone = GA4Utils.helpers.getTimezone();
    if (timezone) {
      params.timezone = timezone;
      
      // Extract location data from timezone as fallback
      var timezoneLocation = GA4Utils.helpers.getLocationFromTimezone(timezone);
      if (timezoneLocation.continent) params.geo_continent = timezoneLocation.continent;
      if (timezoneLocation.country) params.geo_country_tz = timezoneLocation.country;
      if (timezoneLocation.city) params.geo_city_tz = timezoneLocation.city;
    }
    
    if (consentData.analytics_storage === "GRANTED") {
        try {
          const locationData = await this.getUserLocation();
          if (locationData && locationData.latitude && locationData.longitude) {
            // Precise location data available - use it
            if (locationData.latitude) params.geo_latitude = locationData.latitude;
            if (locationData.longitude) params.geo_longitude = locationData.longitude;
            if (locationData.city) params.geo_city = locationData.city;
            if (locationData.country) params.geo_country = locationData.country;
            if (locationData.region) params.geo_region = locationData.region;
            
            this.log("Using precise location data", {
              city: locationData.city,
              country: locationData.country,
              timezone: params.timezone,
              timezone_fallback: {
                city: params.geo_city_tz,
                country: params.geo_country_tz,
                continent: params.geo_continent
              }
            });
          } else {
            // Location data failed or incomplete - timezone is already set as fallback
            this.log("Precise location unavailable, using timezone fallback", {
              timezone: params.timezone,
              geo_continent: params.geo_continent,
              geo_country_tz: params.geo_country_tz,
              geo_city_tz: params.geo_city_tz
            });
          }
        } catch (error) {
          this.log("Location tracking error, using timezone fallback:", {
            error: error.message,
            timezone: params.timezone,
            geo_continent: params.geo_continent,
            geo_country_tz: params.geo_country_tz,
            geo_city_tz: params.geo_city_tz
          });
        }
      
    } else {
      // No analytics consent - only continent-level location via timezone (already set above)
      this.log("No analytics consent, using timezone-based location only", {
        timezone: params.timezone,
        geo_continent: params.geo_continent,
        geo_country_tz: params.geo_country_tz,
        geo_city_tz: params.geo_city_tz
      });
    }
  },

  /**
   * Fallback GDPR anonymization (if consent manager doesn't handle it)
   */
  applyGDPRAnonymization: function(params, consentData) {
    var anonymizedParams = JSON.parse(JSON.stringify(params));

    if (consentData.analytics_storage === "DENIED") {
      // Remove/anonymize personal data
      delete anonymizedParams.user_id;
      
      // Anonymize user agent
      if (anonymizedParams.user_agent) {
        anonymizedParams.user_agent = this.anonymizeUserAgent(anonymizedParams.user_agent);
      }
      
      // Remove precise location data
      delete anonymizedParams.geo_latitude;
      delete anonymizedParams.geo_longitude;
      delete anonymizedParams.geo_city;
      delete anonymizedParams.geo_region;
      delete anonymizedParams.geo_country;
      
      // Set denied consent attribution for all traffic when analytics consent is denied
      anonymizedParams.source = "(denied consent)";
      anonymizedParams.medium = "(denied consent)";
    }

    if (consentData.ad_storage === "DENIED") {
      // Remove advertising/attribution data
      delete anonymizedParams.gclid;
      delete anonymizedParams.content;
      delete anonymizedParams.term;
      
      // Anonymize campaign info for paid traffic
      if (anonymizedParams.campaign && 
          !["(organic)", "(direct)", "(not set)", "(referral)"].includes(anonymizedParams.campaign)) {
        anonymizedParams.campaign = "(denied consent)";
      }
      
      // Set denied consent attribution for paid traffic
      if (anonymizedParams.medium && 
          ["cpc", "ppc", "paidsearch", "display", "banner", "cpm"].includes(anonymizedParams.medium)) {
        anonymizedParams.source = "(denied consent)";
        anonymizedParams.medium = "(denied consent)";
      }
    }

    return anonymizedParams;
  },

  /**
   * Anonymize user agent string
   */
  anonymizeUserAgent: function(userAgent) {
    return GA4Utils.device.anonymizeUserAgent(userAgent);
  },


  formatEventData: function (eventName, params, endpoint) {
    // Always use Cloudflare Worker format
    var data = {
      name: eventName,
      params: params,
    };
    this.log("Using Cloudflare Worker format", data);
    return data;
  },
    /**
     * Add location data to event parameters
     */
    addLocationData: async function (params) {
        try {
          // Get user location information
          const locationData = await this.getUserLocation();

          // Add location data to parameters
          if (locationData) {
            if (locationData.latitude)
              params.geo_latitude = locationData.latitude;
            if (locationData.longitude)
              params.geo_longitude = locationData.longitude;
            if (locationData.city) params.geo_city = locationData.city;
            if (locationData.country) params.geo_country = locationData.country;
            if (locationData.region) params.geo_region = locationData.region;
          }
        } catch (error) {
          this.log("Location tracking error:", error);
        }
    },


    /**
     * Send AJAX payload to endpoint
     */
    sendAjaxPayload: async function (endpoint, payload) {
      try {
        // All endpoints now use simplified authentication
        
        // For events that don't specify an endpoint, determine the target endpoint
        if (!endpoint || endpoint === 'auto') {
          // Check if secure config is loaded
          if (!this.isSecureConfigLoaded()) {
            this.log("⏳ Secure config not loaded yet, queuing event", {
              hasCloudflareUrl: !!this.config.cloudflareWorkerUrl,
              hasWorkerApiKey: !!this.config.workerApiKey
            });
            
            this.queueEventForLater('auto', payload);
            return Promise.resolve({ queued: true });
          }
          
          // Use Cloudflare Worker if available
          endpoint = this.config.cloudflareWorkerUrl;
          
          if (!endpoint) {
            this.log("❌ No endpoint available for sending event", {
              cloudflareWorkerUrl: this.config.cloudflareWorkerUrl,
              secureConfigLoaded: this.isSecureConfigLoaded()
            });
            
            this.queueEventForLater('auto', payload);
            return Promise.resolve({ queued: true });
          }
        }
        
        // Check if we have the required configuration for sending events
        if (!this.config.cloudflareWorkerUrl || !this.config.workerApiKey) {
          this.log("⏳ Cloudflare Worker config not ready, queuing event", {
            hasCloudflareUrl: !!this.config.cloudflareWorkerUrl,
            hasWorkerApiKey: !!this.config.workerApiKey,
            endpoint: endpoint
          });
          
          this.queueEventForLater(endpoint, payload);
          return Promise.resolve({ queued: true });
        }
        
        this.log("📤 Sending to Cloudflare Worker", {
          endpoint: endpoint,
          hasWorkerApiKey: !!this.config.workerApiKey
        });
        
        return await GA4Utils.ajax.sendPayloadFetch(
          endpoint,
          payload,
          this.config,
          "[GA4 Server-Side Tagging]"
        );
      } catch (error) {
        this.log("❌ Error sending event", {
          endpoint: endpoint,
          error: error.message
        });
        
        // Queue for retry if sending fails
        this.queueEventForLater(endpoint, payload);
        return Promise.resolve({ error: error.message });
      }
    },

    /**
     * Check if secure configuration has been loaded
     */
    isSecureConfigLoaded: function() {
      return !!(this.config.cloudflareWorkerUrl && this.config.workerApiKey);
    },

    // Event queuing for configuration delays
    eventQueue: [],

    /**
     * Queue event for retry when secure configuration is not available
     */
    queueEventForLater: function(endpoint, payload) {
      this.eventQueue.push({
        endpoint: endpoint,
        payload: payload,
        timestamp: Date.now()
      });
      
      this.log("📦 Event queued (will process when config ready)", {
        queueLength: this.eventQueue.length,
        endpoint: endpoint
      });
      
      // Queue is processed once when secure config loads
    },

    /**
     * Schedule periodic queue processing
     */
    processQueuedEvents: function() {
      this.log("🚀 Processing queued events (no retries)");
      this.processEventQueue();
    },

    /**
     * Process queued events
     */
    processEventQueue: async function() {
      if (this.eventQueue.length === 0) {
        return;
      }
      
      this.log("🔄 Processing event queue", {
        queueLength: this.eventQueue.length
      });
      
      var processedCount = 0;
      var failedCount = 0;
      
      // Process events one by one
      for (var i = this.eventQueue.length - 1; i >= 0; i--) {
        var queuedEvent = this.eventQueue[i];
        
        try {
          // For auto endpoints, resolve to actual endpoint now
          var actualEndpoint = queuedEvent.endpoint;
          if (actualEndpoint === 'auto') {
            actualEndpoint = this.config.cloudflareWorkerUrl;
            if (!actualEndpoint) {
              this.log("⚠️ Auto endpoint not resolved, discarding event");
              this.eventQueue.splice(i, 1);
              failedCount++;
              continue;
            }
          }
          
          var result = await this.sendAjaxPayload(actualEndpoint, queuedEvent.payload);
          
          if (result && !result.queued && !result.error) {
            // Successfully sent, remove from queue
            this.eventQueue.splice(i, 1);
            processedCount++;
            
            this.log("✅ Queued event processed successfully", {
              originalEndpoint: queuedEvent.endpoint,
              actualEndpoint: actualEndpoint,
              queueTime: Date.now() - queuedEvent.timestamp + 'ms'
            });
          } else {
            // Failed - remove from queue (no retry)
            this.eventQueue.splice(i, 1);
            failedCount++;
          }
        } catch (error) {
          this.log("❌ Failed to process queued event - discarding", {
            endpoint: queuedEvent.endpoint,
            error: error.message
          });
          // Remove failed event from queue (no retry)
          this.eventQueue.splice(i, 1);
          failedCount++;
        }
      }
      
      this.log("📊 Queue processing complete", {
        processed: processedCount,
        failed: failedCount,
        remaining: this.eventQueue.length
      });
      
      // No retry scheduling - failed events are discarded
      if (failedCount > 0) {
        this.log("⚠️ Some events failed and were discarded (no retries)", {
          discarded: failedCount
        });
      }
    },

    // Log messages if debug mode is enabled
    log: function (message, data) {
      GA4Utils.helpers.log(
        message,
        data,
        this.config,
        "[GA4 Server-Side Tagging]"
      );
    },
  };

  // Expose GA4ServerSideTagging globally for A/B testing and other modules
  window.GA4ServerSideTagging = GA4ServerSideTagging;
  
  // Expose test function globally for debugging
  window.testGA4ABTesting = function() {
    return GA4ServerSideTagging.testABTesting();
  };

  // Initialize when document is ready
  $(document).ready(async function () {
    await GA4ServerSideTagging.init();
  });
})(jQuery);
