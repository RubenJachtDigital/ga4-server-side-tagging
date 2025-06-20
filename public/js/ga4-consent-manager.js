/**
 * GDPR Consent Manager with Event Queue
 * Handles all consent management and controls event sending
 * 
 * @since 1.0.0
 */

(function (window, $) {
  "use strict";

  // Create global namespace
  window.GA4ConsentManager = window.GA4ConsentManager || {};

  var GA4ConsentManager = {
    // Configuration
    config: null,
    consentTimeout: null,
    eventQueue: [], // Queue for events waiting for consent
    consentGiven: false,
    consentStatus: null,
    trackingInstance: null, // Reference to main tracking instance

    /**
     * Initialize the consent manager
     */
    init: function (config, trackingInstance) {
      this.config = config || {};
      this.trackingInstance = trackingInstance;
      
      this.log("Initializing GDPR Consent Manager with event queue", this.config);

      // Check for existing consent
      var existingConsent = this.getStoredConsent();
      
      if (existingConsent) {
        this.log("Found existing consent", existingConsent);
        this.consentGiven = true;
        this.consentStatus = existingConsent;
        this.applyConsent(existingConsent);
        
        // Allow tracking to start immediately
        this.enableTracking();
        return;
      }

      // No existing consent - set up listeners and wait
      this.consentGiven = false;
      this.setupConsentListeners();

      // Set up default consent timeout if configured
      if (this.config.defaultTimeout && this.config.defaultTimeout > 0) {
        this.setupDefaultConsentTimeout();
      }

      // Initialize consent mode with denied state
      if (this.config.consentModeEnabled) {
        this.initializeConsentMode();
      }

      this.log("Waiting for user consent - events will be queued");
    },

    /**
     * Check if consent has been given
     */
    hasConsent: function() {
      return this.consentGiven;
    },

    /**
     * Get current consent status
     */
    getCurrentConsent: function() {
      return this.consentStatus;
    },

    /**
     * Check if an event should be sent or queued
     * @param {string} eventName - The event name
     * @param {Object} eventParams - The event parameters
     * @returns {boolean} - true if event should be sent now, false if queued
     */
    shouldSendEvent: function(eventName, eventParams) {
      if (this.consentGiven) {
        return true; // Send immediately
      }

      // Queue the event
      this.queueEvent(eventName, eventParams);
      return false; // Don't send now
    },

    /**
     * Queue an event until consent is given
     */
    queueEvent: function(eventName, eventParams) {
      this.eventQueue.push({
        eventName: eventName,
        eventParams: eventParams,
        timestamp: Date.now()
      });

      this.log("Event queued waiting for consent", { 
        eventName: eventName, 
        queueLength: this.eventQueue.length 
      });
    },

    /**
     * Process all queued events
     */
    processQueuedEvents: function() {
      if (this.eventQueue.length === 0) {
        this.log("No queued events to process");
        return;
      }

      this.log("Processing queued events", { count: this.eventQueue.length });

      // Process events in order
      var eventsToProcess = this.eventQueue.slice(); // Copy array
      this.eventQueue = []; // Clear queue immediately

      // Use setTimeout to ensure events are processed after consent is fully applied
      setTimeout(function() {
        eventsToProcess.forEach(function(queuedEvent, index) {
          this.log("Sending queued event", { 
            eventName: queuedEvent.eventName, 
            index: index + 1,
            total: eventsToProcess.length,
            age: Date.now() - queuedEvent.timestamp 
          });

          // Add small delay between events to prevent overwhelming the server
          setTimeout(function() {
            this.sendEventWithBypass(queuedEvent.eventName, queuedEvent.eventParams);
          }.bind(this), index * 100); // 100ms delay between events
          
        }.bind(this));
      }.bind(this), 200); // 200ms delay before starting to process
    },

    /**
     * Send event bypassing consent check (for queued events)
     */
    sendEventWithBypass: function(eventName, eventParams) {
      this.log("Sending bypassed event", { eventName: eventName, params: eventParams });
      
      if (this.trackingInstance && typeof this.trackingInstance.sendServerSideEvent === 'function') {
        // Call server-side tracking directly
        this.trackingInstance.sendServerSideEvent(eventName, eventParams);
      } else if (this.trackingInstance && typeof this.trackingInstance.trackEventInternal === 'function') {
        // Call internal tracking method
        this.trackingInstance.trackEventInternal(eventName, eventParams);
      } else if (window.GA4ServerSideTagging && typeof window.GA4ServerSideTagging.sendServerSideEvent === 'function') {
        // Fallback to global instance
        window.GA4ServerSideTagging.sendServerSideEvent(eventName, eventParams);
      } else if (window.GA4ServerSideTagging && typeof window.GA4ServerSideTagging.trackEventInternal === 'function') {
        // Fallback to global instance internal method
        window.GA4ServerSideTagging.trackEventInternal(eventName, eventParams);
      } else {
        // Final fallback to gtag if available
        if (typeof gtag === "function") {
          gtag("event", eventName, eventParams);
          this.log("Sent queued event via gtag", { eventName: eventName });
        } else {
          this.log("No tracking method available for queued event", { eventName: eventName });
        }
      }
    },

    /**
     * Enable tracking by notifying main tracking instance
     */
    enableTracking: function() {
      // Initialize main tracking if it hasn't been initialized yet
      if (typeof GA4ServerSideTagging !== "undefined" && !GA4ServerSideTagging.consentReady) {
        try {
          this.log("Initializing GA4ServerSideTagging after consent");
          GA4ServerSideTagging.init();
        } catch (error) {
          this.log("Error initializing GA4ServerSideTagging", error);
        }
      }
      
      if (this.trackingInstance && typeof this.trackingInstance.onConsentReady === 'function') {
        this.trackingInstance.onConsentReady();
      } else if (typeof GA4ServerSideTagging !== "undefined" && typeof GA4ServerSideTagging.onConsentReady === 'function') {
        GA4ServerSideTagging.onConsentReady();
      }
    },

    /**
     * Clear event queue
     */
    clearEventQueue: function() {
      var queueLength = this.eventQueue.length;
      this.eventQueue = [];
      
      this.log("Event queue cleared", { clearedEvents: queueLength });
    },

    /**
     * Setup consent listeners based on configuration
     */
    setupConsentListeners: function () {
      if (this.config.useIubenda) {
        this.setupIubendaListeners();
      } else {
        this.setupCustomConsentListeners();
      }
    },

    /**
     * Setup Iubenda consent listeners
     */
    setupIubendaListeners: function () {
      var self = this;

      var checkIubenda = function () {
        if (typeof _iub !== 'undefined' && _iub.csConfiguration) {
          _iub.csConfiguration.callback = _iub.csConfiguration.callback || {};
          
          _iub.csConfiguration.callback.onConsentGiven = function () {
            self.log("Iubenda consent given");
            self.handleConsentGiven();
          };

          _iub.csConfiguration.callback.onConsentRejected = function () {
            self.log("Iubenda consent rejected");
            self.handleConsentDenied();
          };

          if (typeof _iub.csConfiguration.callback.onConsentFirstGiven === 'function') {
            _iub.csConfiguration.callback.onConsentFirstGiven();
          }
        } else {
          setTimeout(checkIubenda, 100);
        }
      };

      checkIubenda();
    },

    /**
     * Setup custom consent listeners using CSS selectors
     */
    setupCustomConsentListeners: function () {
      var self = this;

      if (this.config.acceptSelector) {
        // Use both click and change events to catch different consent implementations
        $(document).on('click change', this.config.acceptSelector, function (e) {
          e.preventDefault(); // Prevent default behavior
          self.log("Custom accept consent clicked", { selector: self.config.acceptSelector });
          
          // Add small delay to ensure consent UI has updated
          setTimeout(function() {
            self.handleConsentGiven();
          }, 100);
        });
        
        // Also listen for the button becoming visible (some consent tools load dynamically)
        this.watchForConsentButtons(this.config.acceptSelector, function() {
          self.log("Accept consent button detected");
        });
      }

      if (this.config.denySelector) {
        $(document).on('click change', this.config.denySelector, function (e) {
          e.preventDefault(); // Prevent default behavior
          self.log("Custom deny consent clicked", { selector: self.config.denySelector });
          
          // Add small delay to ensure consent UI has updated
          setTimeout(function() {
            self.handleConsentDenied();
          }, 100);
        });
        
        // Also listen for the button becoming visible
        this.watchForConsentButtons(this.config.denySelector, function() {
          self.log("Deny consent button detected");
        });
      }

      // Also try to detect consent changes by polling localStorage/cookies
      this.startConsentPolling();
    },

    /**
     * Watch for consent buttons to appear (for dynamically loaded consent tools)
     */
    watchForConsentButtons: function(selector, callback) {
      var self = this;
      var checkInterval = setInterval(function() {
        if ($(selector).length > 0) {
          clearInterval(checkInterval);
          if (callback) callback();
          
          // Re-attach listeners to ensure they work
          $(document).off('click change', selector).on('click change', selector, function(e) {
            e.preventDefault();
            self.log("Consent button clicked after detection", { selector: selector });
            
            setTimeout(function() {
              if (selector === self.config.acceptSelector) {
                self.handleConsentGiven();
              } else {
                self.handleConsentDenied();
              }
            }, 100);
          });
        }
      }, 500);

      // Stop checking after 30 seconds
      setTimeout(function() {
        clearInterval(checkInterval);
      }, 30000);
    },

    /**
     * Poll for consent changes (fallback for consent tools that don't trigger events)
     */
    startConsentPolling: function() {
      var self = this;
      var pollInterval = setInterval(function() {
        if (self.consentGiven) {
          clearInterval(pollInterval);
          return;
        }

        // Check if consent has been set in localStorage by other means
        var storedConsent = self.getStoredConsent();
        if (storedConsent && !self.consentGiven) {
          self.log("Consent detected via polling", storedConsent);
          self.consentGiven = true;
          self.consentStatus = storedConsent;
          self.applyConsent(storedConsent);
          self.enableTracking();
          self.processQueuedEvents();
          clearInterval(pollInterval);
        }

        // Check for common consent cookies
        self.checkConsentCookies();
      }, 1000);

      // Stop polling after 2 minutes
      setTimeout(function() {
        clearInterval(pollInterval);
      }, 120000);
    },

    /**
     * Check for common consent cookies
     */
    checkConsentCookies: function() {
      if (this.consentGiven) return;

      var commonConsentCookies = [
        'cookieConsent',
        'cookie_consent',
        'gdpr_consent',
        'privacy_consent',
        'cookie-consent',
        'acceptCookies',
        'cookiesAccepted'
      ];

      for (var i = 0; i < commonConsentCookies.length; i++) {
        var cookieValue = this.getCookie(commonConsentCookies[i]);
        if (cookieValue) {
          this.log("Consent cookie detected", { cookie: commonConsentCookies[i], value: cookieValue });
          
          // Try to determine if it's accept or deny based on cookie value
          var normalizedValue = cookieValue.toLowerCase();
          if (normalizedValue.includes('accept') || normalizedValue.includes('true') || normalizedValue === '1' || normalizedValue === 'yes') {
            this.handleConsentGiven();
            return;
          } else if (normalizedValue.includes('deny') || normalizedValue.includes('false') || normalizedValue === '0' || normalizedValue === 'no') {
            this.handleConsentDenied();
            return;
          }
        }
      }
    },

    /**
     * Get cookie value by name
     */
    getCookie: function(name) {
      var nameEQ = name + "=";
      var ca = document.cookie.split(';');
      for (var i = 0; i < ca.length; i++) {
        var c = ca[i];
        while (c.charAt(0) === ' ') c = c.substring(1, c.length);
        if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
      }
      return null;
    },

    /**
     * Setup default consent timeout
     */
    setupDefaultConsentTimeout: function () {
      var self = this;
      
      this.log("Setting up consent timeout", { timeout: this.config.defaultTimeout });

      this.consentTimeout = setTimeout(function () {
        self.log("Consent timeout reached - applying default consent");
        self.handleConsentGiven();
      }, this.config.defaultTimeout * 1000);
    },

    /**
     * Clear consent timeout
     */
    clearConsentTimeout: function () {
      if (this.consentTimeout) {
        clearTimeout(this.consentTimeout);
        this.consentTimeout = null;
      }
    },

    /**
     * Handle consent given
     */
    handleConsentGiven: function () {
      this.clearConsentTimeout();
      
      var consent = {
        analytics_storage: 'GRANTED',
        ad_storage: 'GRANTED',
        ad_user_data: 'GRANTED',
        ad_personalization: 'GRANTED',
        functionality_storage: 'GRANTED',
        personalization_storage: 'GRANTED',
        security_storage: 'GRANTED',
        timestamp: Date.now()
      };

      this.consentGiven = true;
      this.consentStatus = consent;
      
      this.storeConsent(consent);
      this.applyConsent(consent);
      
      this.log("Consent granted - processing queued events", { 
        consent: consent, 
        queuedEvents: this.eventQueue.length 
      });

      // Enable tracking and process queued events
      this.enableTracking();
      this.processQueuedEvents();
    },

    /**
     * Handle consent denied
     */
    handleConsentDenied: function () {
      this.clearConsentTimeout();
      
      var consent = {
        analytics_storage: 'DENIED',
        ad_storage: 'DENIED',
        ad_user_data: 'DENIED',
        ad_personalization: 'DENIED',
        functionality_storage: 'DENIED',
        personalization_storage: 'DENIED',
        security_storage: 'GRANTED',
        timestamp: Date.now()
      };

      this.consentGiven = true; // Allow events to be sent (but anonymized)
      this.consentStatus = consent;

      this.storeConsent(consent);
      this.applyConsent(consent);
      
      this.log("Consent denied - processing queued events with anonymization", { 
        consent: consent, 
        queuedEvents: this.eventQueue.length 
      });

      // Enable tracking and process queued events (they will be anonymized)
      this.enableTracking();
      this.processQueuedEvents();
    },

    /**
     * Apply consent settings
     */
    applyConsent: function (consent) {
      // Store consent globally for other functions to access
      window.GA4ConsentStatus = consent;

      // Update consent mode if enabled
      if (this.config.consentModeEnabled && typeof gtag === 'function') {
        gtag('consent', 'update', {
          analytics_storage: consent.analytics_storage,
          ad_storage: consent.ad_storage,
          ad_user_data: consent.ad_user_data,
          ad_personalization: consent.ad_personalization,
          functionality_storage: consent.functionality_storage,
          personalization_storage: consent.personalization_storage,
          security_storage: consent.security_storage
        });
      }

      // Trigger custom event for other scripts to listen to
      $(document).trigger('ga4ConsentUpdated', [consent]);
    },

    /**
     * Initialize Google Consent Mode v2
     */
    initializeConsentMode: function () {
      if (typeof gtag === 'function') {
        gtag('consent', 'default', {
          analytics_storage: 'denied',
          ad_storage: 'denied',
          ad_user_data: 'denied',
          ad_personalization: 'denied',
          functionality_storage: 'denied',
          personalization_storage: 'denied',
          security_storage: 'granted',
          wait_for_update: 30000 // Wait 30 seconds for consent update
        });

        this.log("Google Consent Mode v2 initialized with denied defaults");
      }
    },

    /**
     * Store consent in localStorage
     */
    storeConsent: function (consent) {
      try {
        localStorage.setItem('ga4_consent_status', JSON.stringify(consent));
        this.log("Consent stored in localStorage", consent);
      } catch (e) {
        this.log("Failed to store consent in localStorage", e);
      }
    },

    /**
     * Get stored consent from localStorage
     */
    getStoredConsent: function () {
      try {
        var stored = localStorage.getItem('ga4_consent_status');
        if (stored) {
          var consent = JSON.parse(stored);
          
          // Check if consent is still valid (not older than 1 year)
          var oneYear = 365 * 24 * 60 * 60 * 1000;
          if (consent.timestamp && (Date.now() - consent.timestamp) < oneYear) {
            return consent;
          } else {
            this.clearStoredConsent();
          }
        }
      } catch (e) {
        this.log("Failed to retrieve consent from localStorage", e);
      }
      return null;
    },

    /**
     * Clear stored consent
     */
    clearStoredConsent: function () {
      try {
        localStorage.removeItem('ga4_consent_status');
        this.log("Cleared stored consent");
      } catch (e) {
        this.log("Failed to clear stored consent", e);
      }
    },

    /**
     * Get consent data formatted for server-side events
     */
    getConsentForServerSide: function () {
      var consent = this.consentStatus || this.getStoredConsent();

      if (!consent) {
        return {
          analytics_storage: "DENIED",
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
        consent_mode: this.getConsentMode(),
        consent_timestamp: consent.timestamp,
      };
    },

    /**
     * Check if analytics tracking is allowed
     */
    isAnalyticsAllowed: function () {
      var consent = this.getStoredConsent();
      return consent && consent.analytics_storage === 'GRANTED';
    },

    /**
     * Check if advertising tracking is allowed
     */
    isAdvertisingAllowed: function () {
      var consent = this.getStoredConsent();
      return consent && consent.ad_storage === 'GRANTED';
    },

    /**
     * Get consent mode
     */
    getConsentMode: function () {
      var consent = this.getStoredConsent();
      
      if (!consent) {
        return 'UNKNOWN';
      }
      
      if (consent.analytics_storage === 'GRANTED' && consent.ad_storage === 'GRANTED') {
        return 'GRANTED';
      } else if (consent.analytics_storage === 'DENIED' && consent.ad_storage === 'DENIED') {
        return 'DENIED';
      } else {
        return 'PARTIAL';
      }
    },

    /**
     * Get consent-aware client ID
     */
    getConsentAwareClientId: function() {
      var consent = this.getConsentForServerSide();
      
      if (consent.analytics_storage === "GRANTED") {
        return GA4Utils.clientId.get();
      } else {
        // Use session-based client ID for denied consent
        var sessionId = GA4Utils.session.get().id;
        return "session_" + sessionId;
      }
    },

    /**
     * Apply GDPR anonymization to event parameters
     */
    applyGDPRAnonymization: function(params) {
      var consent = this.getConsentForServerSide();
      var anonymizedParams = JSON.parse(JSON.stringify(params));

      if (consent.analytics_storage === "DENIED") {
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
      }

      if (consent.ad_storage === "DENIED") {
        // Remove advertising/attribution data
        delete anonymizedParams.gclid;
        delete anonymizedParams.content;
        delete anonymizedParams.term;
        
        // Anonymize campaign info for paid traffic
        if (anonymizedParams.campaign && 
            !["(organic)", "(direct)", "(not set)", "(referral)"].includes(anonymizedParams.campaign)) {
          anonymizedParams.campaign = "(not provided)";
        }
        
        // Remove detailed referrer info for paid traffic
        if (anonymizedParams.medium && 
            ["cpc", "ppc", "paidsearch", "display", "banner", "cpm"].includes(anonymizedParams.medium)) {
          anonymizedParams.source = "(not provided)";
          anonymizedParams.medium = "(not provided)";
        }
      }

      return anonymizedParams;
    },

    /**
     * Anonymize user agent string
     */
    anonymizeUserAgent: function(userAgent) {
      if (!userAgent) return "";
      
      return userAgent
        .replace(/\d+\.\d+[\.\d]*/g, "x.x") // Replace version numbers
        .replace(/\([^)]*\)/g, "(anonymous)") // Replace system info in parentheses
        .substring(0, 100); // Truncate to 100 characters
    },

    /**
     * Manual consent management methods
     */
    grantConsent: function () {
      this.handleConsentGiven();
    },

    denyConsent: function () {
      this.handleConsentDenied();
    },

    resetConsent: function () {
      this.clearStoredConsent();
      this.clearConsentTimeout();
      this.clearEventQueue();
      this.consentGiven = false;
      this.consentStatus = null;
      
      // Reset consent mode if enabled
      if (this.config.consentModeEnabled) {
        this.initializeConsentMode();
      }
      
      this.log("Consent reset - events will be queued again");
    },

    /**
     * Debug logging helper
     */
    log: function(message, data) {
      if (this.config && this.config.debugMode && window.console) {
        var prefix = "[GA4 Consent Manager]";
        if (data) {
          console.log(prefix + " " + message, data);
        } else {
          console.log(prefix + " " + message);
        }
      }
    },

    /**
     * Force process queued events (for debugging)
     */
    forceProcessQueue: function() {
      this.log("Force processing queue", { queueLength: this.eventQueue.length });
      this.processQueuedEvents();
    },

    /**
     * Get debug information
     */
    getDebugInfo: function() {
      return {
        consentGiven: this.consentGiven,
        consentStatus: this.consentStatus,
        queueLength: this.eventQueue.length,
        queuedEvents: this.eventQueue.map(function(event) {
          return {
            eventName: event.eventName,
            age: Date.now() - event.timestamp
          };
        }),
        config: this.config,
        hasTrackingInstance: !!this.trackingInstance
      };
    }
  };

  // Expose globally
  window.GA4ConsentManager = GA4ConsentManager;

})(window, jQuery);