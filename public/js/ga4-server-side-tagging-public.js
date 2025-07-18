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
    eventQueue: [], // Queue events until consent is determined



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

      this.log("🚀 Starting immediate initialization...");

      // Initialize GDPR Consent System with retry mechanism for slow loads (immediate)
      await this.initializeConsentSystemWithRetry();

      // Note: Event queue restoration is handled by GA4ConsentManager

      // Set up event listeners immediately
      this.setupEventListeners();

      // Initialize A/B testing immediately
      this.initializeABTesting();
      
      // Initialize Click tracking immediately
      this.initializeClickTracking();

      // Track page view immediately
      this.trackPageView();

      // Log initialization
      this.log(
        "%c GA4 Server-Side Tagging initialized v4",
        "background: #4CAF50; color: white; font-size: 16px; font-weight: bold; padding: 8px 12px; border-radius: 4px;"
      );
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
          this.log(`Test ${index + 1}:`, test);
          
          // Check if elements exist
          var elemA = document.querySelector(test.class_a);
          var elemB = document.querySelector(test.class_b);
          
          this.log(`  - Variant A (${test.class_a}):`, elemA ? 'FOUND' : 'NOT FOUND');
          this.log(`  - Variant B (${test.class_b}):`, elemB ? 'FOUND' : 'NOT FOUND');
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
        await this.trackEvent("custom_session_start", sessionParams);
      }
      if (session.isFirstVisit && isNewSession) {
        await this.trackEvent("custom_first_visit", sessionParams);
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

      // If we have a gclid, always override with Google Ads attribution (gclid is definitive proof of paid traffic)
      if (gclid) {
        source = "google";
        medium = "cpc";
        campaign = utmParams.utm_campaign || "(not set)";
      }

      // Handle cases where no attribution is determined yet
      if (!source && !medium) {
        this.log("DEBUG: No attribution found, calling handleNoAttribution", {
          isNewSession: isNewSession,
          ignore_referrer: ignore_referrer,
          referrerDomain: referrerDomain
        });
        var fallbackAttribution = this.handleNoAttribution(isNewSession);
        this.log("DEBUG: fallbackAttribution result:", fallbackAttribution);
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
          return { source: "google", medium: "cpc", campaign: "(not set)" };
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
      this.log("DEBUG: handleNoAttribution called", {
        isNewSession: isNewSession,
        storedAttribution: storedAttribution
      });

      // For continuing sessions with no attribution, mark as internal traffic
      if (!isNewSession) {
        this.log("DEBUG: Returning internal attribution for continuing session");
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
      // 3. The attribution is not direct or internal traffic (preserve only real external sources)
      var shouldStore =
        isNewSession ||
        utmParams.utm_source ||
        utmParams.utm_medium ||
        gclid ||
        (attribution.source && attribution.source !== "(direct)" && attribution.source !== "(internal)");

      if (shouldStore && attribution.source && attribution.medium) {
        var userData = GA4Utils.storage.getUserData();
        
        // Debug log for attribution storage
        if (this.config && this.config.debugMode) {
          this.log('DEBUG: Storing new attribution data:', {
            isNewSession: isNewSession,
            attribution: attribution,
            previousStored: {
              source: userData.lastSource,
              medium: userData.lastMedium,
              campaign: userData.lastCampaign
            }
          });
        }
        
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
      } else {
        // Debug log for attribution preservation
        if (this.config && this.config.debugMode) {
          var userData = GA4Utils.storage.getUserData();
          this.log('DEBUG: Preserving existing attribution (not storing):', {
            isNewSession: isNewSession,
            currentAttribution: attribution,
            shouldStore: shouldStore,
            preservedStored: {
              source: userData.lastSource,
              medium: userData.lastMedium,
              campaign: userData.lastCampaign
            }
          });
        }
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
        await this.trackProductListView(sessionParams);
      } else if (GA4Utils.page.isProductPage(this.config)) {
        await this.trackProductView(sessionParams);
      } else {
        // Regular page - track page_view
        await this.trackEvent("page_view", sessionParams);
      }
    },

    /**
     * Track product list view
     */
    trackProductListView: async function (sessionParams) {
      var productListData = this.getProductListItems();

      if (productListData.items.length > 0) {
        // Combine session parameters with item list data
        var itemListData = {
          ...sessionParams,
          item_list_name: productListData.listName,
          item_list_id: productListData.listId,
          items: productListData.items,
        };

        await this.trackEvent("view_item_list", itemListData);

        // Setup click tracking for products
        this.setupProductClickTracking(
          productListData.listName,
          productListData.listId,
          sessionParams
        );
      } else {
        // Fall back to page_view if no products found
        await this.trackEvent("page_view", sessionParams);
      }
    },

    /**
     * Track single product view
     */
    trackProductView: async function (sessionParams) {
      var productData = this.config.productData;

      var viewItemData = {
        ...sessionParams,
        currency: this.config.currency || "EUR",
        value: productData.price,
        items: [productData],
      };

      await this.trackEvent("view_item", viewItemData);
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
      this.setupPageUnloadListener();
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

    /**
     * Setup page unload listener - REMOVED: No longer needed with immediate event sending
     * Events are now sent immediately when consent is available
     */
    setupPageUnloadListener: function() {
      this.log("👂 Page unload listeners removed - using immediate event sending with consent");
      // No unload listeners needed - events are sent immediately when consent is available
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

      // Check if order was already sent (PHP session-based duplicate prevention)
      if (this.config.orderSent === true) {
        self.log("Order already tracked in PHP session - skipping duplicate tracking");
        return;
      }

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

    trackEvent: async function (eventName, eventParams = {}) {
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
        
        this.log('DEBUG: Stored attribution for conversion event:', {
            eventName: eventName,
            storedAttribution: storedAttribution
        });
        
        
        // Force stored attribution for conversion events (overrides current attribution)
        if (storedAttribution.source) {
          eventParams.source = storedAttribution.source;
        }
        if (storedAttribution.medium) {
          eventParams.medium = storedAttribution.medium;
        }
        if (storedAttribution.campaign) {
          eventParams.campaign = storedAttribution.campaign;
        }
        if (storedAttribution.content) {
          eventParams.content = storedAttribution.content;
        }
        if (storedAttribution.term) {
          eventParams.term = storedAttribution.term;
        }
        if (storedAttribution.gclid) {
          eventParams.gclid = storedAttribution.gclid;
        }
        
        // Force traffic_type based on stored attribution
        eventParams.traffic_type = GA4Utils.traffic.getType(
          storedAttribution.source,
          storedAttribution.medium,
          null // No referrer domain for stored attribution
        );
        
        // Debug log only in debug mode
        if (this.config.debugMode) {
          this.log('DEBUG: Conversion event enriched with stored attribution:', {
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

      // ALWAYS ensure complete location data is present for all events
      try {
        eventParams = await this.enrichEventWithLocationData(eventParams, eventName);
        this.log("✅ Event enriched with location data", {
          eventName: eventName,
          hasLocationData: !!(eventParams.geo_latitude && eventParams.geo_longitude)
        });
      } catch (error) {
        this.log("⚠️ Failed to enrich event with location data, using fallback", {
          eventName: eventName,
          error: error.message
        });
        // Still ensure basic location data as fallback
        this.ensureBasicLocationData(eventParams);
      }

      // Add stored attribution data for non-foundational events only
      this.addStoredAttributionData(eventParams, eventName);

      // Check consent status via consent manager
      if (window.GA4ConsentManager && typeof window.GA4ConsentManager.shouldSendEvent === 'function') {
        // Check if eventParams already contains complete session data
        var hasCompleteData = eventParams.hasOwnProperty('page_location') && 
                             eventParams.hasOwnProperty('source') && 
                             eventParams.hasOwnProperty('medium') &&
                             eventParams.hasOwnProperty('session_id');
        
        var criticalEvents = ['page_view', 'custom_session_start', 'custom_first_visit'];
        
        if (criticalEvents.includes(eventName) && hasCompleteData) {
          // eventParams already contains complete session data - check if should send immediately
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
          } else {
            // Send immediately with sendBeacon for reliability
            this.log("✅ Consent available - sending critical event immediately: " + eventName);
            this.sendServerSideEventReliable(eventName, eventParams, true); // true = critical
            return;
          }
        } else {
          // Regular processing for non-critical events or events without complete data
          var shouldSend = window.GA4ConsentManager.shouldSendEvent(eventName, eventParams);
          
          if (!shouldSend) {
            this.log("Event queued by consent manager: " + eventName);
            return; // Event was queued, don't send now
          } else {
            // Send immediately with sendBeacon for reliability
            this.log("✅ Consent available - sending event immediately: " + eventName);
            this.sendServerSideEventReliable(eventName, eventParams, false); // false = not critical
            return;
          }
        }
      } else if (!this.consentReady) {
        this.log("Consent not ready, skipping event: " + eventName, {
          consentReady: this.consentReady,
          hasConsentManager: !!(window.GA4ConsentManager)
        });
        return;
      }

      // Fallback: Send the event normally if no consent manager
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
      
      // Use single send-event endpoint
      var data = this.formatEventData(eventName, completeEventData);
      this.sendAjaxPayload(null, data);
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

      // Use single send-event endpoint
      var data = this.formatEventData(eventName, params);
      
      this.log("Sending via send-event endpoint", {
        consent: this.getMinimalConsent(consentData),
        anonymized: consentData.analytics_storage !== "GRANTED",
        eventName: eventName
      });
      
      // Determine transmission method based on configuration
      const transmissionMethod = this.config.transmissionMethod || 'secure_wp_to_cf';
      
      if (transmissionMethod === 'direct_to_cf' && this.config.cloudflareWorkerUrl) {
        this.log("⚡ Direct to Cloudflare transmission - sending directly to Worker", {
          eventName: eventName,
          workerUrl: this.config.cloudflareWorkerUrl,
          method: 'direct_to_cf',
          bypassWordPress: true,
          botDetection: false
        });
        
        this.sendSimpleRequest(data, false); // false = no bot detection
      } else if (transmissionMethod === 'wp_endpoint_to_cf' && this.config.cloudflareWorkerUrl) {
        this.log("🛡️ WP Bot Check before sending to CF transmission - optimized WordPress endpoint", {
          eventName: eventName,
          workerUrl: this.config.cloudflareWorkerUrl,
          method: 'wp_endpoint_to_cf',
          bypassWordPress: false,
          botDetection: true,
          apiKeyValidation: false,
          encryption: false,
          asyncForwarding: true
        });
        
        this.sendAjaxPayload(null, data); // Use optimized WordPress endpoint
      } else {
        this.log("🔒 Secure WordPress to Cloudflare transmission - sending via WordPress endpoint", {
          eventName: eventName,
          method: 'secure_wp_to_cf',
          encryption: true,
          apiKeyValidation: true
        });
        
        this.sendAjaxPayload(null, data);
      }
    },

    /**
     * Send single event immediately using reliable method (sendBeacon)
     * @param {string} eventName - The event name
     * @param {Object} eventParams - Event parameters
     * @param {boolean} isCritical - Whether this is a critical event
     */
    sendServerSideEventReliable: async function(eventName, eventParams, isCritical = false) {
      try {
        // Use existing sendServerSideEvent logic but with reliable transmission
        this.log("🚨 Sending event with reliable method: " + eventName, {
          eventName: eventName,
          isCritical: isCritical,
          payloadSizeEstimate: JSON.stringify(eventParams).length + " bytes"
        });
        
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

        this.log("Current consent status for reliable event", consentData);

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
        this.log("📍 Adding location data with consent");
        await this.addLocationDataWithConsent(params, consentData);
        this.log("✅ Location data added successfully");

        // Get client ID (handle consent-based anonymization)
        var clientId = this.getConsentAwareClientId(consentData);
        if (clientId) {
          params.client_id = clientId;
          this.log("Using client ID: " + clientId);
        }

        // Bot detection removed - not needed for immediate reliable sending
        
        // Prepare event data
        this.log("📦 Preparing event data");
        var data = {
          name: eventName,
          params: params,
          consent: consentData,
          clientId: clientId,
          timestamp: Date.now()
        };

        // Send with reliable method
        const transmissionMethod = this.config.transmissionMethod || 'secure_wp_to_cf';
        
        this.log("🚀 Sending reliable event via " + transmissionMethod, {
          eventName: eventName,
          transmissionMethod: transmissionMethod,
          consent: consentData,
          isCritical: isCritical
        });

        if (transmissionMethod === 'direct_to_cf' && this.config.cloudflareWorkerUrl) {
          this.log("⚡ Direct to Cloudflare reliable transmission");
          await this.sendEventToCloudflareReliable(data, isCritical);
        } else if (transmissionMethod === 'wp_endpoint_to_cf' && this.config.cloudflareWorkerUrl) {
          this.log("🛡️ WP Bot Check before sending to CF reliable transmission");
          await this.sendEventViaWordPressReliable(data, false, isCritical);
        } else {
          this.log("🔒 Secure WordPress to Cloudflare reliable transmission");
          await this.sendEventViaWordPressReliable(data, true, isCritical);
        }
      } catch (error) {
        this.log("❌ Error in sendServerSideEventReliable", {
          eventName: eventName,
          error: error.message,
          stack: error.stack
        });
        // Fallback to regular tracking
        this.log("🔄 Falling back to regular event tracking");
        this.trackEventInternal(eventName, eventParams);
      }
    },

    /**
     * Send single event to Cloudflare using reliable method
     * @param {Object} eventData - The event data
     * @param {boolean} isCritical - Whether this is a critical event
     */
    sendEventToCloudflareReliable: async function(eventData, isCritical = false) {
      if (!this.config.cloudflareWorkerUrl) {
        this.log("❌ Cloudflare Worker URL not configured for reliable transmission");
        return;
      }

      try {
        // Format event data using batch payload structure
        var payloadData = {
          batch: false, // Mark as single event
          events: [
            {
              name: eventData.name,
              params: eventData.params || {},
              isCompleteData: true,
              timestamp: eventData.timestamp || Date.now()
            }
          ],
          consent: eventData.consent || {},
          timestamp: eventData.timestamp || Date.now()
        };
        
        await GA4Utils.ajax.sendPayloadReliable(
          this.config.cloudflareWorkerUrl,
          payloadData,
          this.config,
          "[GA4 Reliable Event CF]",
          isCritical
        );
        this.log("✅ Single event sent reliably to Cloudflare Worker (batch structure)", {
          eventName: eventData.name,
          isCritical: isCritical,
          hasConsent: !!(eventData.consent)
        });
      } catch (error) {
        this.log("❌ Reliable event sending failed", error);
      }
    },

    /**
     * Send single event via WordPress using reliable method
     * @param {Object} eventData - The event data
     * @param {boolean} withEncryption - Whether to use encryption
     * @param {boolean} isCritical - Whether this is a critical event
     */
    sendEventViaWordPressReliable: async function(eventData, withEncryption = false, isCritical = false) {
      var endpoint = this.config.apiEndpoint + '/send-events';

      if (!endpoint || !this.config.apiEndpoint) {
        this.log("❌ WordPress endpoint not configured for reliable transmission", {
          apiEndpoint: this.config.apiEndpoint,
          endpoint: endpoint
        });
        return;
      }

      try {
        // Format the event data for WordPress endpoint using batch payload structure
        var payloadData = {
          batch: false, // Mark as single event
          events: [
            {
              name: eventData.name,
              params: eventData.params || {},
              isCompleteData: true,
              timestamp: eventData.timestamp || Date.now()
            }
          ],
          consent: eventData.consent || {},
          timestamp: eventData.timestamp || Date.now()
        };
        
        this.log("📤 Sending single event via WordPress endpoint (batch structure)", {
          endpoint: endpoint,
          eventName: eventData.name,
          withEncryption: withEncryption,
          isCritical: isCritical,
          hasConsent: !!(eventData.consent),
          payloadSize: JSON.stringify(payloadData).length + ' bytes'
        });
        
        // Create a modified config with encryption enabled if needed
        var configWithEncryption = JSON.parse(JSON.stringify(this.config));
        if (withEncryption) {
          configWithEncryption.encryptionEnabled = true;
          
          this.log("🔒 Enabling encryption for reliable transmission", {
            encryptionEnabled: true
          });
        }
        
        await GA4Utils.ajax.sendPayloadReliable(
          endpoint,
          payloadData,
          configWithEncryption,
          "[GA4 Reliable Event WP]",
          isCritical
        );
        this.log("✅ Single event sent reliably via WordPress (batch structure)", {
          eventName: eventData.name,
          isCritical: isCritical,
          withEncryption: withEncryption,
          hasConsent: !!(eventData.consent)
        });
      } catch (error) {
        this.log("❌ Reliable event sending via WordPress failed", error);
      }
    },

    /**
     * Send multiple events as a batch payload
     * @param {Object} batchPayload - Batch payload with events array
     */
    sendBatchEvents: function(batchPayload, isCritical = false) {
      if (!batchPayload || !batchPayload.events || batchPayload.events.length === 0) {
        this.log("No events in batch payload");
        return;
      }

      this.log("📦 Processing batch payload with " + batchPayload.events.length + " events", {
        ...batchPayload,
        isCritical: isCritical
      });

      // Determine transmission method based on configuration
      const transmissionMethod = this.config.transmissionMethod || 'secure_wp_to_cf';
      
      // Format batch data for transmission
      var data = {
        batch: true,
        events: batchPayload.events,
        consent: batchPayload.consent,
        timestamp: batchPayload.timestamp || Date.now()
      };

      this.log("🚀 Sending batch via " + transmissionMethod, {
        eventCount: data.events.length,
        transmissionMethod: transmissionMethod,
        consent: data.consent,
        isCritical: isCritical
      });

      if (transmissionMethod === 'direct_to_cf' && this.config.cloudflareWorkerUrl) {
        this.log("⚡ Direct to Cloudflare batch transmission");
        this.sendBatchToCloudflare(data, isCritical);
      } else if (transmissionMethod === 'wp_endpoint_to_cf' && this.config.cloudflareWorkerUrl) {
        this.log("🛡️ WP Bot Check before sending to CF batch transmission (balanced)");
        this.sendBatchViaWordPress(data, false, isCritical); // Use optimized WordPress endpoint (no encryption)
      } else {
        this.log("🔒 Secure WordPress to Cloudflare batch transmission");
        this.sendBatchViaWordPress(data, true, isCritical); // true = with encryption
      }
    },

    /**
     * Send batch directly to Cloudflare Worker (direct transmission)
     * @param {Object} batchData - The batch data to send
     * @param {boolean} isCritical - Whether this is a critical event
     */
    sendBatchToCloudflare: function(batchData, isCritical = false) {
      if (!this.config.cloudflareWorkerUrl) {
        this.log("❌ Cloudflare Worker URL not configured for direct transmission");
        return;
      }

      // For critical events, use reliable sending method with sendBeacon fallback
      if (isCritical) {
        this.log("🚨 Critical batch event - using reliable sending method");
        try {
          // For direct to Cloudflare, we need to use a simple payload format
          GA4Utils.ajax.sendPayloadReliable(
            this.config.cloudflareWorkerUrl,
            batchData,
            this.config,
            "[GA4 Critical Batch CF]",
            true // isCritical
          ).then(function(response) {
            this.log("✅ Critical batch sent reliably to Cloudflare Worker", {
              method: response.method || 'fetch',
              success: response.success
            });
          }.bind(this)).catch(function(error) {
            this.log("❌ Critical batch sending failed", error);
          }.bind(this));
        } catch (error) {
          this.log("❌ Error with reliable batch sending", error);
        }
      } else {
        // Use standard fetch with proper headers for non-critical events
        try {
          var payload = JSON.stringify(batchData);
          
          // Prepare headers for the request
          const headers = {
            'Content-Type': 'application/json',
            'X-Simple-request': 'true'
          };
          
          // Add X-WP-Nonce header if we have a nonce (for wp_endpoint_to_cf method)
          const currentNonce = window.ga4ServerSideTagging?.nonce || this.config.nonce;
          if (currentNonce) {
            headers['X-WP-Nonce'] = currentNonce;
          }

          // Use fetch with proper headers for Cloudflare Worker
          fetch(this.config.cloudflareWorkerUrl, {
            method: 'POST',
            headers: headers,
            body: payload,
            keepalive: true // Important for page unload
          }).then(function(response) {
            this.log("✅ Batch sent via fetch to Cloudflare Worker", {
              status: response.status,
              ok: response.ok
            });
          }.bind(this)).catch(function(error) {
            this.log("❌ Error sending batch to Cloudflare Worker", error);
          }.bind(this));

        } catch (error) {
          this.log("❌ Error preparing batch for Cloudflare Worker", error);
        }
      }
    },

    /**
     * Send batch via WordPress endpoint (balanced or secure transmission)
     * @param {Object} batchData - The batch data to send
     * @param {boolean} useEncryption - Whether to use encryption
     * @param {boolean} isCritical - Whether this is a critical event
     */
    sendBatchViaWordPress: function(batchData, useEncryption, isCritical = false) {
      var endpoint = this.config.apiEndpoint + '/send-events';
      
      try {
        // If encryption is enabled, use sendPayloadReliable which handles encryption
        if (useEncryption && this.config.encryptionEnabled) {
          GA4Utils.ajax.sendPayloadReliable(endpoint, batchData, this.config, "[GA4 Batch Encrypted]", isCritical)
            .then(function(response) {
              this.log("✅ Encrypted batch sent via sendPayloadReliable to WordPress endpoint", {
                encrypted: true,
                useEncryption: useEncryption,
                isCritical: isCritical,
                method: response.method || 'fetch'
              });
            }.bind(this))
            .catch(function(error) {
              this.log("❌ Error sending encrypted batch to WordPress endpoint", error);
            }.bind(this));
          return;
        }
        
        // For non-encrypted batch requests, use reliable method for critical events
        if (isCritical) {
          this.log("🚨 Critical batch event - using reliable sending method (non-encrypted)");
          GA4Utils.ajax.sendPayloadReliable(endpoint, batchData, this.config, "[GA4 Batch Critical]", isCritical)
            .then(function(response) {
              this.log("✅ Critical batch sent via sendPayloadReliable to WordPress endpoint", {
                encrypted: false,
                useEncryption: useEncryption,
                isCritical: isCritical,
                method: response.method || 'fetch'
              });
            }.bind(this))
            .catch(function(error) {
              this.log("❌ Error sending critical batch to WordPress endpoint", error);
            }.bind(this));
        } else {
          // For non-critical events, use direct fetch
          var payload = JSON.stringify(batchData);     

          // Use fetch for batch requests
          // Use comprehensive headers for proper validation handling
          var headers = {
            "Content-Type": "application/json",
            "Origin": window.location.origin,
            "Referer": window.location.href,
            "Accept": "application/json, text/plain, */*",
            "Accept-Language": navigator.language || "en-US",
            "X-WP-Nonce": window.ga4ServerSideTagging?.nonce || this.config.nonce || ""
          };

          fetch(endpoint, {
            method: 'POST',
            headers: headers,
            body: payload,
            keepalive: true // Important for page unload
          }).then(function(response) {
            if (response.ok) {
              this.log("✅ Batch sent via fetch to WordPress endpoint", {
                status: response.status,
                ok: response.ok,
                encrypted: useEncryption
              });
            } else {
              this.log("❌ WordPress endpoint returned error", {
                status: response.status,
                statusText: response.statusText,
                ok: response.ok
            });
            
            // Try to parse error response
            response.text().then(function(text) {
              this.log("Error response body:", text);
            }.bind(this)).catch(function() {
              this.log("Could not parse error response");
            }.bind(this));
          }
        }.bind(this)).catch(function(error) {
          this.log("❌ Network error sending batch to WordPress endpoint", error);
        }.bind(this));
        }

      } catch (error) {
        this.log("❌ Error preparing batch for WordPress endpoint", error);
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


  formatEventData: function (eventName, params) {
    // Format for send-event endpoint
    var data = {
      name: eventName,
      params: params,
    };
    this.log("Formatting event data for send-event endpoint", data);
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
     * Send Simple request directly to Cloudflare Worker (bypasses WordPress)
     */
    sendSimpleRequest: async function (payload, enableBotDetection = false) {
      try {
        const botDetectionEnabled = enableBotDetection;
        
        this.log("🚀 Sending Simple request directly to Cloudflare Worker", {
          workerUrl: this.config.cloudflareWorkerUrl,
          eventName: payload.name,
          bypassWordPress: true,
          encryptionDisabled: true,
          apiKeyValidationDisabled: true,
          botDetectionEnabled: botDetectionEnabled
        });

        // Perform bot validation via WordPress endpoint if enabled
        if (botDetectionEnabled) {
          const botValidationResult = await this.validateBotForSimpleRequest();
          if (!botValidationResult.success) {
            this.log("🤖 Bot validation failed for Simple request", {
              eventName: payload.name,
              error: botValidationResult.message,
              isBot: botValidationResult.is_bot
            });
            return Promise.resolve({ 
              error: "Bot validation failed", 
              is_bot: botValidationResult.is_bot 
            });
          }
          
          this.log("✅ Bot validation passed for Simple request", {
            eventName: payload.name,
            validationCached: botValidationResult.session_cached
          });
        }

        // Prepare headers for the request
        const headers = {
          'Content-Type': 'application/json',
          'X-Simple-request': 'true'
        };
        
        // Add X-WP-Nonce header if we have a nonce (for wp_endpoint_to_cf method)
        const currentNonce = window.ga4ServerSideTagging?.nonce || this.config.nonce;
        if (currentNonce) {
          headers['X-WP-Nonce'] = currentNonce;
        }

        const response = await fetch(this.config.cloudflareWorkerUrl, {
          method: 'POST',
          headers: headers,
          body: JSON.stringify({
            event_name: payload.name,
            params: payload.params || {}
          }),
          keepalive: true // Important for page unload
        });

        if (!response.ok) {
          throw new Error(`HTTP error! status: ${response.status}`);
        }

        const result = await response.json();
        
        this.log("✅ Simple request sent successfully", {
          eventName: payload.name,
          status: response.status,
          response: result,
          botValidationPerformed: botDetectionEnabled
        });

        return result;

      } catch (error) {
        this.log("❌ Simple request failed", {
          eventName: payload.name,
          error: error.message,
          workerUrl: this.config.cloudflareWorkerUrl
        });
        return Promise.resolve({ error: error.message });
      }
    },


    /**
     * Send AJAX payload to endpoint
     */
    sendAjaxPayload: async function (endpoint, payload) {
      try {
        // Check if consent is ready - if not, queue the event
        if (!this.consentReady && !this.hasConsentDecision()) {
          this.log("⏳ Consent not determined yet, queuing event", {
            eventName: payload.name,
            queueLength: this.eventQueue.length + 1
          });
          
          // Queue event with location data enrichment
          await this.queueEventForConsent(payload);
          return Promise.resolve({ queued: true });
        }
        
        // Use the single send-event endpoint
        const sendEventEndpoint = this.config.apiEndpoint + '/send-events';
        
        this.log("📤 Sending event via send-event endpoint", {
          endpoint: sendEventEndpoint,
          eventName: payload.name,
          consentReady: this.consentReady,
          encryptionEnabled: this.config.encryptionEnabled
        });
        
        // Log encryption status for debugging
        if (this.config.encryptionEnabled) {
          this.log("🔐 [GA4 Encryption] Event will be encrypted before sending", {
            eventName: payload.name,
            endpoint: sendEventEndpoint,
            timestamp: new Date().toISOString()
          });
        } else {
          this.log("📤 [GA4 Encryption] Event will be sent unencrypted", {
            eventName: payload.name,
            endpoint: sendEventEndpoint,
            timestamp: new Date().toISOString()
          });
        }
        
        return await GA4Utils.ajax.sendPayloadFetch(
          sendEventEndpoint,
          {
            event_name: payload.name,
            params: payload.params || {}
          },
          this.config,
          "[GA4 Server-Side Tagging]"
        );
      } catch (error) {
        this.log("❌ Error sending event", {
          error: error.message
        });
        return Promise.resolve({ error: error.message });
      }
    },

    /**
     * Queue event for consent decision with persistent storage
     */
    queueEventForConsent: async function(payload) {
      this.log("📦 QUEUEING EVENT - Starting persistent queue process", {
        eventName: payload.name,
        currentQueueLength: this.eventQueue.length,
        hasLocationData: !!(payload.params && payload.params.geo_latitude && payload.params.geo_longitude)
      });
      
      // Enrich payload with location data before queuing
      try {
        if (payload.params) {
          payload.params = await this.enrichEventWithLocationData(payload.params, payload.name);
          this.log("✅ Payload enriched with location data", {
            eventName: payload.name,
            hasLocationData: !!(payload.params.geo_latitude && payload.params.geo_longitude)
          });
        }
      } catch (error) {
        this.log("⚠️ Failed to enrich payload with location data", {
          eventName: payload.name,
          error: error.message
        });
      }
      
      const queuedEvent = {
        payload: payload,
        timestamp: Date.now(),
        id: this.generateEventId()
      };
      
      // Add to in-memory queue
      this.eventQueue.push(queuedEvent);
      
      // Persist to localStorage for cross-page persistence
      this.log("💾 Persisting event queue to localStorage...");
      this.persistEventQueue();
      
      this.log("📦 Event queued for consent decision with complete data", {
        eventName: payload.name,
        queueLength: this.eventQueue.length,
        eventId: queuedEvent.id,
        persistedToStorage: true,
        hasLocationData: !!(payload.params && payload.params.geo_latitude && payload.params.geo_longitude)
      });
      
      // Check if batch size limit reached and send automatically
      this.checkBatchSizeLimit();
    },

    /**
     * Enrich event parameters with complete location data
     * @param {Object} eventParams - Event parameters to enrich
     * @param {string} eventName - Event name for logging
     * @returns {Promise<Object>} - Enriched event parameters
     */
    enrichEventWithLocationData: async function(eventParams, eventName) {
      this.log("🌍 Enriching event with location data", {
        eventName: eventName,
        hasTimezone: !!eventParams.timezone,
        hasBasicGeo: !!(eventParams.geo_continent || eventParams.geo_country_tz)
      });
      
      // First ensure basic location data from timezone
      this.ensureBasicLocationData(eventParams);
      
      // Check if we should get precise IP location data
      var shouldGetPreciseLocation = false;
      
      // Since this function is called for queued events (before consent is set),
      // we should ALWAYS fetch IP location data for accurate geolocation
      // The Cloudflare Worker will handle consent-based filtering later
      shouldGetPreciseLocation = true;
      var fetchReason = "pre_consent_queue";
      
      // Check current consent status for logging purposes
      var consentData = null;
      if (window.GA4ConsentManager && typeof window.GA4ConsentManager.getConsentForServerSide === 'function') {
        consentData = window.GA4ConsentManager.getConsentForServerSide();
      } else {
        consentData = GA4Utils.consent.getForServerSide();
      }
      
      if (consentData && consentData.analytics_storage === "GRANTED") {
        fetchReason = "consent_granted";
      }
      
      // Check if IP geolocation is disabled by admin
      if (this.config.consentSettings && this.config.consentSettings.disableAllIP) {
        shouldGetPreciseLocation = false;
        this.log("IP geolocation disabled by admin - using timezone fallback only");
      }
      
      // Get precise location data (always for queued events)
      if (shouldGetPreciseLocation) {
        try {
          this.log("🎯 Fetching precise location data for queue", {
            reason: fetchReason,
            consentStatus: consentData?.analytics_storage || "unknown"
          });
          
          const locationData = await this.getUserLocation();
          
          if (locationData && locationData.latitude && locationData.longitude) {
            // Add precise location data
            eventParams.geo_latitude = locationData.latitude;
            eventParams.geo_longitude = locationData.longitude;
            if (locationData.city) eventParams.geo_city = locationData.city;
            if (locationData.country) eventParams.geo_country = locationData.country;
            if (locationData.region) eventParams.geo_region = locationData.region;
            
            this.log("✅ Precise location data added to queued event", {
              city: locationData.city,
              country: locationData.country,
              region: locationData.region,
              hasCoordinates: !!(locationData.latitude && locationData.longitude),
              reason: fetchReason
            });
          } else {
            this.log("⚠️ Precise location data incomplete, using timezone fallback");
          }
        } catch (error) {
          this.log("❌ Failed to get precise location data for queue", {
            eventName: eventName,
            error: error.message,
            fallbackUsed: "timezone-based location"
          });
        }
      } else {
        this.log("🔒 IP geolocation disabled - using timezone fallback only");
      }
      
      return eventParams;
    },

    /**
     * Check if batch size limit (35+ events) is reached and send automatically
     */
    checkBatchSizeLimit: function() {
      var batchSizeLimit = this.config?.batchSizeLimit || 35;
      var currentBatchSize = this.eventQueue.length;
      
      if (currentBatchSize >= batchSizeLimit) {
        this.log("🚨 Batch size limit reached - automatically sending batch", {
          currentBatchSize: currentBatchSize,
          batchSizeLimit: batchSizeLimit,
          triggerReason: "batch_size_limit"
        });
        
        // Use consent manager to send batch if available
        if (window.GA4ConsentManager && typeof window.GA4ConsentManager.sendBatchEvents === 'function') {
          window.GA4ConsentManager.sendBatchEvents(false); // false = not critical (not during page unload)
        } else {
          this.log("⚠️ No consent manager available for batch sending");
        }
      } else {
        this.log("📊 Batch size check", {
          currentBatchSize: currentBatchSize,
          batchSizeLimit: batchSizeLimit,
          remaining: batchSizeLimit - currentBatchSize
        });
      }
    },

    /**
     * Generate unique event ID for queue tracking
     */
    generateEventId: function() {
      return 'evt_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
    },

    /**
     * Persist event queue to localStorage
     */
    persistEventQueue: function() {
      try {
        this.log("💾 PERSIST QUEUE - Starting persistence process...", {
          currentQueueLength: this.eventQueue.length,
          hasLocalStorage: typeof localStorage !== 'undefined'
        });
        
        const queueData = {
          events: this.eventQueue,
          timestamp: Date.now(),
          version: '1.0'
        };
        
        // Clean up expired events before saving
        const cleanedEvents = this.cleanupExpiredQueuedEvents(queueData.events);
        queueData.events = cleanedEvents;
        
        // Limit queue size to prevent localStorage bloat
        if (queueData.events.length > this.getMaxQueueSize()) {
          queueData.events = queueData.events.slice(-this.getMaxQueueSize());
          this.log("⚠️ Queue size limit reached, keeping most recent events", {
            maxSize: this.getMaxQueueSize(),
            keptEvents: queueData.events.length
          });
        }
        
        const serializedData = JSON.stringify(queueData);
        localStorage.setItem('ga4_persistent_event_queue', serializedData);
        
        this.log("✅ Event queue persisted to localStorage", {
          eventCount: queueData.events.length,
          storageSize: serializedData.length + ' bytes',
          storageKey: 'ga4_persistent_event_queue'
        });
        
        // Verify the data was stored correctly
        const verification = localStorage.getItem('ga4_persistent_event_queue');
        this.log("🔍 Storage verification", {
          stored: !!verification,
          size: verification ? verification.length : 0
        });
        
      } catch (error) {
        this.log("❌ Failed to persist event queue", {
          error: error.message,
          stack: error.stack
        });
      }
    },

    /**
     * Restore event queue from localStorage on page load
     */
    restoreEventQueue: function() {
      try {
        this.log("🔄 RESTORE QUEUE - Starting restoration process...", {
          hasLocalStorage: typeof localStorage !== 'undefined',
          currentQueueLength: this.eventQueue.length
        });
        
        const storedData = localStorage.getItem('ga4_persistent_event_queue');
        if (!storedData) {
          this.log("📭 No persistent event queue found in localStorage");
          return;
        }
        
        this.log("📦 Found stored queue data", {
          dataSize: storedData.length + ' bytes'
        });
        
        const queueData = JSON.parse(storedData);
        if (!queueData || !queueData.events || !Array.isArray(queueData.events)) {
          this.log("⚠️ Invalid queue data format, clearing storage", {
            hasQueueData: !!queueData,
            hasEvents: !!(queueData && queueData.events),
            isArray: !!(queueData && queueData.events && Array.isArray(queueData.events))
          });
          localStorage.removeItem('ga4_persistent_event_queue');
          return;
        }
        
        this.log("📊 Queue data analysis", {
          totalEvents: queueData.events.length,
          queueTimestamp: queueData.timestamp,
          queueVersion: queueData.version,
          queueAge: Date.now() - queueData.timestamp + 'ms'
        });
        
        // Clean up expired events
        const validEvents = this.cleanupExpiredQueuedEvents(queueData.events);
        
        // Restore events to in-memory queue
        this.eventQueue = validEvents;
        
        this.log("✅ Event queue restored from localStorage", {
          restoredEvents: validEvents.length,
          totalFound: queueData.events.length,
          expiredEvents: queueData.events.length - validEvents.length,
          queueAge: Date.now() - queueData.timestamp + 'ms'
        });
        
        // Update localStorage with cleaned events if any were expired
        if (validEvents.length !== queueData.events.length) {
          this.log("🧹 Updating localStorage with cleaned events");
          this.persistEventQueue();
        }
        
      } catch (error) {
        this.log("❌ Failed to restore event queue", {
          error: error.message,
          stack: error.stack
        });
        // Clear corrupted data
        localStorage.removeItem('ga4_persistent_event_queue');
      }
    },

    /**
     * Clean up expired queued events
     */
    cleanupExpiredQueuedEvents: function(events) {
      const maxAge = this.getQueueExpiration();
      const now = Date.now();
      
      const validEvents = events.filter(event => {
        const age = now - event.timestamp;
        return age <= maxAge;
      });
      
      if (validEvents.length !== events.length) {
        this.log("🧹 Cleaned up expired queued events", {
          removed: events.length - validEvents.length,
          remaining: validEvents.length,
          maxAge: maxAge / 1000 + 's'
        });
      }
      
      return validEvents;
    },

    /**
     * Get maximum queue size (prevent localStorage bloat)
     */
    getMaxQueueSize: function() {
      return 50; // Reasonable limit for queued events
    },

    /**
     * Get queue expiration time in milliseconds
     */
    getQueueExpiration: function() {
      return 24 * 60 * 60 * 1000; // 24 hours in milliseconds
    },

    /**
     * Clear persistent event queue
     */
    clearPersistentEventQueue: function() {
      try {
        localStorage.removeItem('ga4_persistent_event_queue');
        this.log("🗑️ Persistent event queue cleared");
      } catch (error) {
        this.log("❌ Failed to clear persistent event queue", {
          error: error.message
        });
      }
    },

    /**
     * Set up periodic cleanup of expired events
     */
    setupPeriodicCleanup: function() {
      // Clean up expired events every 5 minutes
      setInterval(() => {
        this.periodicCleanup();
      }, 5 * 60 * 1000); // 5 minutes

      // Initial cleanup on page load
      this.periodicCleanup();
    },

    /**
     * Perform periodic cleanup of expired events and storage optimization
     */
    periodicCleanup: function() {
      try {
        const storedData = localStorage.getItem('ga4_persistent_event_queue');
        if (!storedData) return;

        const queueData = JSON.parse(storedData);
        if (!queueData || !queueData.events || !Array.isArray(queueData.events)) return;

        const initialCount = queueData.events.length;
        const cleanedEvents = this.cleanupExpiredQueuedEvents(queueData.events);

        // Check storage size and optimize if needed
        const currentSize = JSON.stringify(queueData).length;
        const maxSize = this.getMaxStorageSize();

        if (currentSize > maxSize) {
          // Keep only the most recent events if storage is too large
          const maxEvents = Math.floor(this.getMaxQueueSize() * 0.7); // 70% of max
          cleanedEvents.splice(0, cleanedEvents.length - maxEvents);
          
          this.log("⚠️ Storage size limit exceeded, reduced queue size", {
            originalSize: currentSize + ' bytes',
            maxSize: maxSize + ' bytes',
            eventsKept: cleanedEvents.length,
            eventsRemoved: initialCount - cleanedEvents.length
          });
        }

        // Update storage if changes were made
        if (cleanedEvents.length !== initialCount) {
          if (cleanedEvents.length > 0) {
            localStorage.setItem('ga4_persistent_event_queue', JSON.stringify({
              events: cleanedEvents,
              timestamp: Date.now(),
              version: '1.0'
            }));
          } else {
            localStorage.removeItem('ga4_persistent_event_queue');
          }

          this.log("🧹 Periodic cleanup completed", {
            originalEvents: initialCount,
            cleanedEvents: cleanedEvents.length,
            removedEvents: initialCount - cleanedEvents.length
          });
        }

      } catch (error) {
        this.log("❌ Error during periodic cleanup", {
          error: error.message
        });
      }
    },

    /**
     * Get maximum storage size for the queue in bytes
     */
    getMaxStorageSize: function() {
      return 50 * 1024; // 50KB - reasonable limit for localStorage
    },

    /**
     * Get storage usage statistics
     */
    getQueueStorageStats: function() {
      try {
        const storedData = localStorage.getItem('ga4_persistent_event_queue');
        if (!storedData) {
          return {
            exists: false,
            size: 0,
            eventCount: 0
          };
        }

        const queueData = JSON.parse(storedData);
        return {
          exists: true,
          size: storedData.length,
          eventCount: queueData.events ? queueData.events.length : 0,
          timestamp: queueData.timestamp,
          version: queueData.version
        };
      } catch (error) {
        return {
          exists: false,
          size: 0,
          eventCount: 0,
          error: error.message
        };
      }
    },

    /**
     * Test function for debugging persistent queue functionality
     * Use this in browser console: GA4ServerSideTagging.testPersistentQueue()
     */
    testPersistentQueue: async function() {
      this.log("🧪 TESTING PERSISTENT QUEUE FUNCTIONALITY");
      
      // Test 1: Check current state
      const stats = this.getQueueStorageStats();
      this.log("📊 Current Queue Stats", stats);
      
      // Test 2: Queue a test event
      const testPayload = {
        name: 'test_event',
        params: {
          event_category: 'test',
          event_label: 'persistent_queue_test',
          test_timestamp: Date.now()
        }
      };
      
      this.log("📦 Queuing test event...");
      await this.queueEventForConsent(testPayload);
      
      // Test 3: Check state after queuing
      const newStats = this.getQueueStorageStats();
      this.log("📊 Queue Stats After Adding Event", newStats);
      
      // Test 4: Manually check localStorage
      const rawData = localStorage.getItem('ga4_persistent_event_queue');
      this.log("🔍 Raw localStorage Data", {
        exists: !!rawData,
        size: rawData ? rawData.length : 0,
        preview: rawData ? rawData.substring(0, 100) + '...' : 'none'
      });
      
      // Test 5: Test restoration
      this.log("🔄 Testing queue restoration...");
      const originalQueue = [...this.eventQueue];
      this.eventQueue = []; // Clear in-memory queue
      this.restoreEventQueue();
      
      this.log("✅ Test completed", {
        originalQueueLength: originalQueue.length,
        restoredQueueLength: this.eventQueue.length,
        testPassed: this.eventQueue.length === originalQueue.length
      });
      
      return {
        success: this.eventQueue.length === originalQueue.length,
        originalLength: originalQueue.length,
        restoredLength: this.eventQueue.length,
        storageStats: newStats
      };
    },

    /**
     * Process queued events when consent is granted or denied
     */
    processQueuedEvents: function() {
      if (this.eventQueue.length === 0) {
        this.log("📭 No events in queue to process");
        return;
      }

      this.log("🚀 Processing queued events", {
        queueLength: this.eventQueue.length,
        consentReady: this.consentReady
      });

      // Process all queued events
      const eventsToProcess = [...this.eventQueue];
      this.eventQueue = []; // Clear the in-memory queue
      
      // Clear persistent storage since events are being processed
      this.clearPersistentEventQueue();

      eventsToProcess.forEach(async (queuedEvent) => {
        try {
          this.log("📤 Processing queued event", {
            eventName: queuedEvent.payload.name,
            eventId: queuedEvent.id || 'unknown',
            queueTime: Date.now() - queuedEvent.timestamp + 'ms'
          });
          
          await this.sendAjaxPayload(null, queuedEvent.payload);
        } catch (error) {
          this.log("❌ Failed to process queued event", {
            eventName: queuedEvent.payload.name,
            eventId: queuedEvent.id || 'unknown',
            error: error.message
          });
        }
      });
    },

    /**
     * Check if user has made a consent decision
     */
    hasConsentDecision: function() {
      return GA4ConsentManager && GA4ConsentManager.hasConsentDecision && GA4ConsentManager.hasConsentDecision();
    },


    /**
     * Ensure all events have basic location data (timezone-based fallback)
     */
    ensureBasicLocationData: function(params) {
      // If we don't have any location data, add timezone-based fallback
      if (!params.geo_continent && !params.geo_country_tz && !params.geo_city_tz && 
          !params.geo_country && !params.geo_city && !params.geo_region) {
        
        var timezone = params.timezone || GA4Utils.helpers.getTimezone();
        if (timezone) {
          params.timezone = timezone;
          var timezoneLocation = GA4Utils.helpers.getLocationFromTimezone(timezone);
          if (timezoneLocation.continent) params.geo_continent = timezoneLocation.continent;
          if (timezoneLocation.country) params.geo_country_tz = timezoneLocation.country;
          if (timezoneLocation.city) params.geo_city_tz = timezoneLocation.city;
          
          this.log("Added basic location data from timezone", {
            timezone: timezone,
            continent: timezoneLocation.continent,
            country: timezoneLocation.country,
            city: timezoneLocation.city
          });
        }
      }
    },

    /**
     * Add stored attribution data to non-foundational events for proper attribution tracking
     */
    addStoredAttributionData: function(params, eventName) {
      // Foundational events (page_view, session_start, first_visit) should keep their current attribution
      var foundationalEvents = ['custom_session_start', 'custom_first_visit', 'page_view', 'view_item', 'view_item_list'];
      
      if (foundationalEvents.includes(eventName)) {
        this.log("Foundational event - keeping current attribution", {
          eventName: eventName,
          source: params.source,
          medium: params.medium
        });
        return;
      }
      
      // Get stored attribution data (original traffic source)
      var storedAttribution = this.getStoredAttribution();
      
      // Special handling for conversion events - they get both source and originalSource with stored values
      var conversionEvents = ['purchase', 'quote_request', 'form_conversion'];
      
      if (conversionEvents.includes(eventName)) {
        // For conversion events, set both source and originalSource to stored attribution
        if (storedAttribution.source) {
          params.source = storedAttribution.source;
        }
        if (storedAttribution.medium) {
          params.medium = storedAttribution.medium;
        }
        if (storedAttribution.campaign) {
          params.campaign = storedAttribution.campaign;
        }
        if (storedAttribution.content) {
          params.content = storedAttribution.content;
        }
        if (storedAttribution.term) {
          params.term = storedAttribution.term;
        }
        if (storedAttribution.gclid) {
          params.gclid = storedAttribution.gclid;
        }
        
        // Calculate and add traffic type for both current and original
        if (storedAttribution.source && storedAttribution.medium) {
          var trafficType = GA4Utils.traffic.getType(
            storedAttribution.source,
            storedAttribution.medium,
            null // No referrer domain for stored attribution
          );
          params.traffic_type = trafficType;
        }
        
        this.log("Added stored attribution as both current and original attribution for conversion event", {
          eventName: eventName,
          source: params.source,
          originalSource: params.originalSource,
          medium: params.medium,
          originalMedium: params.originalMedium
        });
        return;
      }
      
      // For other non-foundational events, add stored attribution as original attribution fields
      // Remove current attribution fields and only keep original attribution
      if (storedAttribution.source) {
        params.originalSource = storedAttribution.source;
      }
      if (storedAttribution.medium) {
        params.originalMedium = storedAttribution.medium;
      }
      if (storedAttribution.campaign) {
        params.originalCampaign = storedAttribution.campaign;
      }
      if (storedAttribution.content) {
        params.originalContent = storedAttribution.content;
      }
      if (storedAttribution.term) {
        params.originalTerm = storedAttribution.term;
      }
      if (storedAttribution.gclid) {
        params.originalGclid = storedAttribution.gclid;
      }
      
      // Calculate and add original traffic type
      if (storedAttribution.source && storedAttribution.medium) {
        params.originalTrafficType = GA4Utils.traffic.getType(
          storedAttribution.source,
          storedAttribution.medium,
          null // No referrer domain for stored attribution
        );
      }
      
      // Remove current attribution fields for non-foundational events
      // Only keep original attribution fields
      delete params.source;
      delete params.medium;
      delete params.campaign;
      delete params.content;
      delete params.term;
      delete params.gclid;
      delete params.traffic_type;
      
      this.log("Added stored attribution as original attribution for non-foundational event", {
        eventName: eventName,
        originalSource: params.originalSource,
        originalMedium: params.originalMedium,
        originalCampaign: params.originalCampaign,
        originalTrafficType: params.originalTrafficType
      });
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
