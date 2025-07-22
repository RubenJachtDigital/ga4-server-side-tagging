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
    consentProcessed: false, // Flag to prevent duplicate consent processing
    eventsProcessed: false, // Flag to prevent duplicate event processing
    storageKey: 'ga4_user_data', // Key for storing events in unified user data storage (persistent across page refreshes)

    /**
     * Initialize the consent manager
     */
    init: function (config, trackingInstance) {
      this.config = config || {};
      this.trackingInstance = trackingInstance;
      
      this.log("🚀 GA4ConsentManager initialization started", {
        timestamp: Date.now(),
        pageUrl: window.location.href,
        storageKey: this.storageKey
      });
      
      // Load any existing queued events from localStorage
      this.loadQueuedEventsFromSession();

      // Check for existing consent
      var existingConsent = this.getStoredConsent();
      
      if (existingConsent) {
        this.consentGiven = true;
        this.consentStatus = existingConsent;
        this.consentProcessed = true; // NEW: Mark as processed to prevent duplicates
        this.applyConsent(existingConsent);
        
        // Allow tracking to start immediately
        this.enableTracking();
        return;
      }

      // No existing consent - set up listeners and wait
      this.consentGiven = false;
      this.consentProcessed = false; // NEW: Reset flag for new consent flow
      this.setupConsentListeners();

      // Initialize consent mode with denied state BEFORE setting timeout
      if (this.config.consentModeEnabled) {
        this.initializeConsentMode();
      }

      // Set up default consent timeout if configured (AFTER consent mode initialization)
      // Auto-accept after X seconds regardless of consent method
      if (this.config.defaultTimeout && this.config.defaultTimeout > 0) {
        this.setupDefaultConsentTimeout();
      }

    },

    /**
     * Setup page unload listener - REMOVED: No longer needed with immediate event sending
     * Events are now sent immediately when consent is available
     */
    setupPageUnloadListener: function() {
      this.log("👂 Page unload listeners removed - using immediate event sending with consent");
      // No unload listeners needed - events are sent immediately when consent is available
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
     * NEW BEHAVIOR: Only queue if consent not given, otherwise send immediately
     * @param {string} eventName - The event name
     * @param {Object} eventParams - The basic event parameters
     * @param {Object} completeEventData - The complete event data with all context (optional)
     * @returns {boolean} - True if event should be sent immediately, false if queued
     */
    shouldSendEvent: function(eventName, eventParams, completeEventData) {
      
      // If consent is already given, send immediately
      if (this.consentGiven) {
        this.log("✅ Consent given - sending event immediately", {
          eventName: eventName,
          consentStatus: this.consentStatus
        });
        return true; // Send immediately
      }
      
      // No consent yet - queue the event
      this.log("⏳ No consent yet - queuing event", {
        eventName: eventName,
        currentQueueLength: this.eventQueue.length
      });
      
      // Queue the event with complete data if available (async operation)
      if (completeEventData) {
        this.queueEvent(eventName, completeEventData, true); // true = isCompleteData
      } else {
        this.queueEvent(eventName, eventParams, false); // false = basicParams only
      }
      return false; // Queue until consent
    },

    /**
     * Queue an event until consent is given
     * @param {string} eventName - The event name
     * @param {Object} eventParams - Event parameters (basic or complete)
     * @param {boolean} isCompleteData - Whether eventParams contains complete enriched data
     */
    queueEvent: async function(eventName, eventParams, isCompleteData = false) {
      this.log("📦 Starting event queue process", {
        eventName: eventName,
        isCompleteData: isCompleteData,
        hasLocationData: !!(eventParams.geo_latitude && eventParams.geo_longitude)
      });
      
      // If not complete data, ensure we have full location data before queuing
      if (!isCompleteData) {
        try {
          eventParams = await this.enrichEventWithLocationData(eventParams);
          this.log("✅ Event enriched with location data", {
            eventName: eventName,
            hasLocationData: !!(eventParams.geo_latitude && eventParams.geo_longitude)
          });
        } catch (error) {
          this.log("⚠️ Failed to enrich event with location data, using fallback", {
            eventName: eventName,
            error: error.message
          });
        }
      }
      
      var queuedEvent = {
        eventName: eventName,
        eventParams: eventParams,
        timestamp: Date.now(),
        isCompleteData: true // Now always complete after location enrichment
      };
      
      // Add basic page context for reference
      queuedEvent.pageUrl = window.location.href;
      queuedEvent.pageTitle = document.title;
      
      // Store directly in userData - no in-memory queue needed
      var userData = GA4Utils.storage.getUserData();
      if (!userData.batchEvents) {
        userData.batchEvents = [];
      }
      userData.batchEvents.push(queuedEvent);
      GA4Utils.storage.saveUserData(userData);
      
      // Keep in-memory queue in sync for compatibility - load from storage to ensure sync
      this.eventQueue = userData.batchEvents;
      
      this.log("📦 Event stored directly in unified storage with complete data", {
        eventName: eventName,
        queueLength: userData.batchEvents.length,
        inMemoryQueueLength: this.eventQueue.length,
        timestamp: queuedEvent.timestamp,
        hasLocationData: !!(eventParams.geo_latitude && eventParams.geo_longitude),
        isCompleteData: true
      });
      
      // No batch size limit check needed - events are only queued until consent is given
    },

    /**
     * Enrich event with complete location data before queuing
     * @param {Object} eventParams - Event parameters to enrich
     * @returns {Promise<Object>} - Enriched event parameters
     */
    enrichEventWithLocationData: async function(eventParams) {
      this.log("🌍 Enriching event with location data", {
        hasTimezone: !!eventParams.timezone,
        hasBasicGeo: !!(eventParams.geo_continent || eventParams.geo_country_tz)
      });
      
      // Ensure we have timezone-based location data as fallback
      var timezone = eventParams.timezone || GA4Utils.helpers.getTimezone();
      if (timezone) {
        eventParams.timezone = timezone;
        var timezoneLocation = GA4Utils.helpers.getLocationFromTimezone(timezone);
        if (!eventParams.geo_continent && timezoneLocation.continent) {
          eventParams.geo_continent = timezoneLocation.continent;
        }
        if (!eventParams.geo_country_tz && timezoneLocation.country) {
          eventParams.geo_country_tz = timezoneLocation.country;
        }
        if (!eventParams.geo_city_tz && timezoneLocation.city) {
          eventParams.geo_city_tz = timezoneLocation.city;
        }
      }
      
      // Check if we should get precise IP location data
      var shouldGetPreciseLocation = false;
      
      // Since this function is called for queued events (before consent is set),
      // we should ALWAYS fetch IP location data for accurate geolocation
      // The Cloudflare Worker will handle consent-based filtering later
      shouldGetPreciseLocation = true;
      var fetchReason = "pre_consent_queue";
      
      // Check current consent status for logging purposes
      if (this.consentGiven && this.consentStatus && this.consentStatus.ad_user_data === "GRANTED") {
        fetchReason = "consent_granted";
      }
      
      // Check if IP geolocation is disabled by admin
      if (this.config && this.config.consentSettings && this.config.consentSettings.disableAllIP) {
        shouldGetPreciseLocation = false;
        this.log("IP geolocation disabled by admin - using timezone fallback only");
      }
      
      // Get precise location data (always for queued events)
      if (shouldGetPreciseLocation) {
        try {
          this.log("🎯 Fetching precise location data for queue", {
            reason: fetchReason,
            consentGiven: this.consentGiven,
            consentStatus: this.consentStatus?.ad_user_data || "unknown"
          });
          
          // Get location data from tracking instance if available
          var locationData = null;
          if (this.trackingInstance && typeof this.trackingInstance.getUserLocation === 'function') {
            locationData = await this.trackingInstance.getUserLocation();
          } else {
            // Fallback to utilities
            locationData = await GA4Utils.location.get();
          }
          
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
          this.log("❌ Failed to get precise location data", {
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
     * Process all queued events immediately when consent is granted
     * NEW: Send all queued events as a single batch using sendBeacon for reliability
     */
    processQueuedEvents: function() {
      // Get events directly from userData storage
      var userData = GA4Utils.storage.getUserData();
      var batchEvents = userData.batchEvents || [];
      
      if (!batchEvents || batchEvents.length === 0) {
        this.log("📋 No queued events to process");
        return;
      }
      
      this.log("🚀 Processing queued events as batch immediately with consent", {
        queuedEvents: batchEvents.length,
        consentStatus: this.consentStatus
      });
      
      // Send all queued events as a single batch using reliable method (sendBeacon)
      // Note: sendBatchEvents will handle queue clearing after successful send
      this.sendBatchEvents(true); // true = critical (use sendBeacon)
      
      // Notify tracking instances that consent is ready
      if (typeof GA4ServerSideTagging !== 'undefined' && GA4ServerSideTagging.onConsentReady) {
        GA4ServerSideTagging.onConsentReady();
      }
    },

    /**
     * Send all queued events as a single batch payload
     * Only sends events if consent has been determined (granted or denied)
     * @param {boolean} isCritical - Whether this is a critical event (page unload)
     */
    sendBatchEvents: function(isCritical = false) {
      // Get events directly from userData storage
      var userData = GA4Utils.storage.getUserData();
      var batchEvents = userData.batchEvents || [];
      
      if (!batchEvents || batchEvents.length === 0) {
        this.log("📤 No events to send in batch");
        return;
      }

      // Check if consent has been processed - only send if consent has been determined
      if (!this.consentProcessed) {
        this.log("❌ Consent not yet processed - not sending batch events", {
          queuedEvents: batchEvents.length,
          consentGiven: this.consentGiven,
          consentProcessed: this.consentProcessed,
          consentStatus: this.consentStatus
        });
        return;
      }

      this.log("✅ Consent confirmed - proceeding with batch send", {
        queuedEvents: batchEvents.length,
        consentGiven: this.consentGiven,
        consentStatus: this.consentStatus
      });

      // Get current consent data
      var consentData = this.getConsentForServerSide();
      
      // Prepare batch payload
      var batchPayload = {
        batch: true,
        events: [],
        consent: consentData,
        timestamp: Date.now()
      };

      // Process each event in the batch with size validation
      const maxBatchSize = 500 * 1024; // 500KB max batch size
      let currentBatchSize = JSON.stringify(batchPayload).length;
      
      for (let i = 0; i < batchEvents.length; i++) {
        const queuedEvent = batchEvents[i];
        const eventData = {
          name: queuedEvent.eventName,
          params: queuedEvent.eventParams,
          isCompleteData: queuedEvent.isCompleteData,
          timestamp: queuedEvent.timestamp
        };
        
        // Add page context if event doesn't have complete data
        if (!queuedEvent.isCompleteData && queuedEvent.pageUrl) {
          eventData.pageUrl = queuedEvent.pageUrl;
          eventData.pageTitle = queuedEvent.pageTitle;
        }
        
        // Check if adding this event would exceed the batch size limit
        const eventSize = JSON.stringify(eventData).length;
        if (currentBatchSize + eventSize > maxBatchSize) {
          this.log("⚠️ Batch size limit reached, limiting events in batch", {
            currentSize: currentBatchSize,
            maxSize: maxBatchSize,
            eventsIncluded: batchPayload.events.length,
            eventsSkipped: batchEvents.length - batchPayload.events.length,
            skipReason: "batch_size_limit"
          });
          break; // Stop processing more events
        }
        
        batchPayload.events.push(eventData);
        currentBatchSize += eventSize;
      }

      this.log("📤 Sending batch payload with " + batchPayload.events.length + " events", batchPayload);

      // Send batch to the tracking instance
      let batchSent = false;
      
      if (this.trackingInstance && typeof this.trackingInstance.sendBatchEvents === 'function') {
        this.trackingInstance.sendBatchEvents(batchPayload, isCritical);
        batchSent = true;
      } else if (typeof GA4ServerSideTagging !== 'undefined' && typeof GA4ServerSideTagging.sendBatchEvents === 'function') {
        GA4ServerSideTagging.sendBatchEvents(batchPayload, isCritical);
        batchSent = true;
      } else {
        this.log("❌ No batch sending method available");
      }

      // Only clear events if they were actually sent
      if (batchSent) {
        // Clear events directly from userData storage
        userData.batchEvents = [];
        GA4Utils.storage.saveUserData(userData);
        
        // Clear in-memory queue for compatibility
        this.eventQueue = [];
        
        this.log("📤 Batch events sent successfully, all queues cleared", {
          eventsSent: batchPayload.events.length,
          batchSize: JSON.stringify(batchPayload).length + ' bytes'
        });
      } else {
        this.log("❌ Batch events not sent, queues preserved", {
          queueLength: batchEvents.length
        });
      }
    },

    /**
     * Send event bypassing consent check (for queued events)
     * @param {string} eventName - The event name
     * @param {Object} eventParams - Event parameters (basic or complete)
     * @param {boolean} isCompleteData - Whether eventParams contains complete enriched data
     */
    sendEventWithBypass: async function(eventName, eventParams, isCompleteData = false) {
      
      if (isCompleteData) {
        // Event has complete data, send directly without re-enriching
        if (this.trackingInstance && typeof this.trackingInstance.sendCompleteEventData === 'function') {
          // Use new method for complete data
          await this.trackingInstance.sendCompleteEventData(eventName, eventParams);
        } else if (this.trackingInstance && typeof this.trackingInstance.sendServerSideEvent === 'function') {
          // Fallback: Update consent data and send
          eventParams.consent = this.getConsentForServerSide();
          await this.trackingInstance.sendRawEventData(eventName, eventParams);
        } else {
        }
      } else {
        // Basic event data, process normally
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
          } else {
          }
        }
      }
    },

    /**
     * Enable tracking by notifying main tracking instance
     */
    enableTracking: function() {
      
      // Set global consent ready flag
      window.GA4ConsentReady = true;
      
      // Directly set the consentReady flag on the tracking instance
      if (typeof GA4ServerSideTagging !== "undefined") {
        GA4ServerSideTagging.consentReady = true;
      }
      
      // Set on tracking instance reference if available
      if (this.trackingInstance) {
        this.trackingInstance.consentReady = true;
      }
      
      // Call onConsentReady methods
      if (this.trackingInstance && typeof this.trackingInstance.onConsentReady === 'function') {
        try {
          this.trackingInstance.onConsentReady();
        } catch (error) {
        }
      } else if (typeof GA4ServerSideTagging !== "undefined" && typeof GA4ServerSideTagging.onConsentReady === 'function') {
        try {
          GA4ServerSideTagging.onConsentReady();
        } catch (error) {
        }
      }
      
      // Trigger a custom event that can be listened to by other scripts
      $(document).trigger('ga4TrackingEnabled', [this.consentStatus]);
      
    },

    /**
     * Clear event queue
     */
    clearEventQueue: function() {
      var queueLength = this.eventQueue.length;
      this.eventQueue = [];
      
      // Also clear from session storage
      this.clearQueuedEventsFromSession();
      
    },

    /**
     * Setup consent listeners based on configuration
     */
    setupConsentListeners: function () {
      // Check if useIubenda is true (can be string "1" or boolean true)
      var useIubenda = this.config.useIubenda === true || this.config.useIubenda === "1" || this.config.useIubenda === 1;
      
      this.log("🎯 Setting up consent listeners", {
        useIubenda: useIubenda,
        useIubendaRaw: this.config.useIubenda,
        hasAcceptSelector: !!(this.config.acceptSelector),
        hasDenySelector: !!(this.config.denySelector),
        acceptSelector: this.config.acceptSelector,
        denySelector: this.config.denySelector
      });
      
      if (useIubenda) {
        this.log("🔵 Using Iubenda consent management (ignoring custom selectors)");
        this.setupIubendaListeners();
      } else if (this.config.acceptSelector || this.config.denySelector) {
        this.log("🟡 Using custom CSS selector consent management");
        this.setupCustomConsentListeners();
      } else {
        this.log("⚠️ No consent method configured - using timeout or default consent");
      }
    },

    /**
     * Setup Iubenda consent listeners
     */
    setupIubendaListeners: function () {
      var self = this;

      var checkIubenda = function () {
        if (typeof _iub !== 'undefined' && _iub.csConfiguration) {
          self.log("✅ Iubenda detected, setting up callbacks (custom selectors will be ignored)");
          
          // Store original callbacks if they exist
          var originalCallbacks = _iub.csConfiguration.callback || {};
          _iub.csConfiguration.callback = _iub.csConfiguration.callback || {};
          
          // Set up consent given callback
          _iub.csConfiguration.callback.onConsentGiven = function () {
            self.log("🟢 Iubenda consent given callback triggered");
            
            // Call original callback if it existed
            if (originalCallbacks.onConsentGiven && typeof originalCallbacks.onConsentGiven === 'function') {
              try {
                originalCallbacks.onConsentGiven();
              } catch (e) {
                self.log("Error calling original onConsentGiven callback", e);
              }
            }
            
            // Handle our consent logic - ensure it runs
            self.log("🚀 Processing consent given...");
            // Immediate processing for better responsiveness
            self.handleConsentGiven('iubenda_callback');
            
            // Also set a small fallback in case the immediate processing has issues
            setTimeout(function() {
              if (!self.consentProcessed) {
                self.log("📝 Fallback: Processing consent given after delay");
                self.handleConsentGiven('iubenda_callback_fallback');
              }
            }, 25); // Reduced delay for better UX
          };

          // Set up consent rejected callback
          _iub.csConfiguration.callback.onConsentRejected = function () {
            self.log("🔴 Iubenda consent rejected callback triggered");
            
            // Call original callback if it existed
            if (originalCallbacks.onConsentRejected && typeof originalCallbacks.onConsentRejected === 'function') {
              try {
                originalCallbacks.onConsentRejected();
              } catch (e) {
                self.log("Error calling original onConsentRejected callback", e);
              }
            }
            
            // Handle our consent logic - ensure it runs
            self.log("🚀 Processing consent denied...");
            // Immediate processing for better responsiveness
            self.handleConsentDenied('iubenda_callback');
            
            // Also set a small fallback in case the immediate processing has issues
            setTimeout(function() {
              if (!self.consentProcessed) {
                self.log("📝 Fallback: Processing consent denied after delay");
                self.handleConsentDenied('iubenda_callback_fallback');
              }
            }, 25); // Reduced delay for better UX
          };

          // Set up first consent callback
          _iub.csConfiguration.callback.onConsentFirstGiven = function () {
            self.log("🆕 Iubenda first consent given callback triggered");
            
            // Call original callback if it existed
            if (originalCallbacks.onConsentFirstGiven && typeof originalCallbacks.onConsentFirstGiven === 'function') {
              try {
                originalCallbacks.onConsentFirstGiven();
              } catch (e) {
                self.log("Error calling original onConsentFirstGiven callback", e);
              }
            }
            
            // This is triggered when consent is given for the first time
            self.log("🚀 Processing first consent given...");
            setTimeout(function() {
              self.handleConsentGiven('button_click');
            }, 50);
          };

          self.log("✅ Iubenda callbacks configured successfully");
          
          // IMPORTANT: Also set up direct button click listeners as fallback
          // In case Iubenda callbacks don't fire properly
          self.setupIubendaButtonFallback();
          
          // Check if consent was already given/denied before we attached listeners
          // Focus on callbacks working for new decisions rather than complex existing consent detection
          setTimeout(function() {
            self.checkExistingIubendaConsent();
          }, 200);
        } else {
          // Keep checking for Iubenda to load
          setTimeout(checkIubenda, 100);
        }
      };

      checkIubenda();
    },

    /**
     * Setup direct Iubenda button click listeners as fallback
     */
    setupIubendaButtonFallback: function() {
      var self = this;
      
      // Iubenda specific button selectors
      var iubendaAcceptSelectors = [
        '.iubenda-cs-accept-btn',
        '.iub-cs-accept-btn', 
        '[class*="iubenda"][class*="accept"]',
        '[class*="iub"][class*="accept"]'
      ].join(', ');
      
      var iubendaRejectSelectors = [
        '.iubenda-cs-reject-btn',
        '.iub-cs-reject-btn',
        '[class*="iubenda"][class*="reject"]',
        '[class*="iub"][class*="reject"]',
        '[class*="iubenda"][class*="deny"]',
        '[class*="iub"][class*="deny"]'
      ].join(', ');
      
      this.log("🎯 Setting up Iubenda button fallback listeners", {
        acceptSelectors: iubendaAcceptSelectors,
        rejectSelectors: iubendaRejectSelectors
      });
      
      // Accept button listeners with immediate and delayed processing
      $(document).on('click', iubendaAcceptSelectors, function(e) {
        var clickTimestamp = Date.now();
        self.log("🟢 Iubenda ACCEPT button clicked directly (fallback)", { 
          element: this.className,
          selector: this.tagName + '.' + this.className.split(' ').join('.'),
          clickTimestamp: clickTimestamp,
          eventType: e.type,
          target: e.target.tagName,
          currentTarget: this.tagName,
          consentProcessed: self.consentProcessed,
          timestamp: new Date().toISOString()
        });
        
        // Immediate processing attempt
        if (!self.consentProcessed) {
          self.log("📝 Immediate processing accept via button click", {
            processingTimestamp: Date.now(),
            timeSinceClick: Date.now() - clickTimestamp
          });
          self.handleConsentGiven('button_click_immediate');
        } else {
          self.log("ℹ️ Accept button clicked but consent already processed", {
            clickTimestamp: clickTimestamp,
            alreadyProcessed: true
          });
        }
        
        // Also set a delayed fallback in case Iubenda callbacks override our immediate processing
        setTimeout(function() {
          if (!self.consentProcessed) {
            self.log("📝 Delayed processing accept via button fallback", {
              delayedTimestamp: Date.now(),
              totalTimeSinceClick: Date.now() - clickTimestamp,
              delay: '50ms'
            });
            self.handleConsentGiven('button_click_delayed');
          } else {
            self.log("ℹ️ Accept already processed, skipping delayed fallback", {
              delayedTimestamp: Date.now(),
              totalTimeSinceClick: Date.now() - clickTimestamp
            });
          }
        }, 50); // Reduced from 100ms to 50ms
      });
      
      // Reject button listeners with immediate and delayed processing
      $(document).on('click', iubendaRejectSelectors, function(e) {
        var clickTimestamp = Date.now();
        self.log("🔴 Iubenda REJECT button clicked directly (fallback)", { 
          element: this.className,
          selector: this.tagName + '.' + this.className.split(' ').join('.'),
          clickTimestamp: clickTimestamp,
          eventType: e.type,
          target: e.target.tagName,
          currentTarget: this.tagName,
          consentProcessed: self.consentProcessed,
          timestamp: new Date().toISOString()
        });
        
        // Immediate processing attempt
        if (!self.consentProcessed) {
          self.log("📝 Immediate processing reject via button click", {
            processingTimestamp: Date.now(),
            timeSinceClick: Date.now() - clickTimestamp
          });
          self.handleConsentDenied('button_click_immediate');
        } else {
          self.log("ℹ️ Reject button clicked but consent already processed", {
            clickTimestamp: clickTimestamp,
            alreadyProcessed: true
          });
        }
        
        // Also set a delayed fallback in case Iubenda callbacks override our immediate processing
        setTimeout(function() {
          if (!self.consentProcessed) {
            self.log("📝 Delayed processing reject via button fallback", {
              delayedTimestamp: Date.now(),
              totalTimeSinceClick: Date.now() - clickTimestamp,
              delay: '50ms'
            });
            self.handleConsentDenied('button_click_delayed');
          } else {
            self.log("ℹ️ Reject already processed, skipping delayed fallback", {
              delayedTimestamp: Date.now(),
              totalTimeSinceClick: Date.now() - clickTimestamp
            });
          }
        }, 50); // Reduced from 100ms to 50ms
      });
      
      // Also watch for buttons that appear dynamically
      this.watchForIubendaButtons();
      
      // Add high-priority click capture for immediate response
      this.setupImmediateClickCapture();
    },

    /**
     * Setup immediate click capture using event capture phase for maximum responsiveness
     */
    setupImmediateClickCapture: function() {
      var self = this;
      
      // Use capture phase to catch clicks before Iubenda processes them
      document.addEventListener('click', function(e) {
        var target = e.target;
        
        // Check if clicked element or its parents match Iubenda selectors
        var element = target;
        var maxDepth = 5; // Check up to 5 parent levels
        var depth = 0;
        
        while (element && depth < maxDepth) {
          var className = element.className || '';
          var isAcceptBtn = (
            className.includes('iubenda-cs-accept') ||
            className.includes('iub-cs-accept') ||
            (className.includes('iubenda') && className.includes('accept')) ||
            (className.includes('iub') && className.includes('accept'))
          );
          
          var isRejectBtn = (
            className.includes('iubenda-cs-reject') ||
            className.includes('iub-cs-reject') ||
            (className.includes('iubenda') && (className.includes('reject') || className.includes('deny'))) ||
            (className.includes('iub') && (className.includes('reject') || className.includes('deny')))
          );
          
          if (isAcceptBtn && !self.consentProcessed) {
            self.log("⚡ IMMEDIATE Iubenda accept detected via capture phase", {
              element: element.tagName,
              className: className,
              depth: depth
            });
            
            // Process immediately without any delay
            setTimeout(function() {
              if (!self.consentProcessed) {
                self.handleConsentGiven('immediate_capture');
              }
            }, 0);
            break;
          }
          
          if (isRejectBtn && !self.consentProcessed) {
            self.log("⚡ IMMEDIATE Iubenda reject detected via capture phase", {
              element: element.tagName,
              className: className,
              depth: depth
            });
            
            // Process immediately without any delay
            setTimeout(function() {
              if (!self.consentProcessed) {
                self.handleConsentDenied('immediate_capture');
              }
            }, 0);
            break;
          }
          
          element = element.parentElement;
          depth++;
        }
      }, true); // Use capture phase for earliest detection
    },

    /**
     * Watch for Iubenda buttons that might be added dynamically (enhanced for slow loading)
     */
    watchForIubendaButtons: function() {
      var self = this;
      var checkCount = 0;
      var maxChecks = 120; // 60 seconds (120 * 500ms)
      var intervalDelay = 500;
      
      var checkInterval = setInterval(function() {
        checkCount++;
        
        var acceptBtn = document.querySelector('.iubenda-cs-accept-btn, .iub-cs-accept-btn');
        var rejectBtn = document.querySelector('.iubenda-cs-reject-btn, .iub-cs-reject-btn');
        
        if (acceptBtn || rejectBtn) {
          self.log("✨ Iubenda buttons detected in DOM", {
            acceptBtn: !!acceptBtn,
            rejectBtn: !!rejectBtn,
            acceptClass: acceptBtn ? acceptBtn.className : 'none',
            rejectClass: rejectBtn ? rejectBtn.className : 'none',
            detectedAfter: (checkCount * intervalDelay) + 'ms'
          });
          clearInterval(checkInterval);
        } else if (checkCount >= maxChecks) {
          self.log("⏰ Stopped watching for Iubenda buttons after 60 seconds");
          clearInterval(checkInterval);
        } else if (checkCount % 20 === 0) {
          // Log progress every 10 seconds for slow loading scenarios
          self.log(`⏳ Still watching for Iubenda buttons... (${checkCount * intervalDelay / 1000}s elapsed)`);
        }
      }, intervalDelay);
    },

    /**
     * Check for existing Iubenda consent that may have been set before our listeners
     */
    checkExistingIubendaConsent: function() {
      var self = this;
      
      // Enhanced retry mechanism for slow loading (especially incognito mode)
      this.checkExistingConsentWithRetry(0);
    },

    /**
     * Enhanced retry mechanism for checking existing consent
     */
    checkExistingConsentWithRetry: function(attempt) {
      var self = this;
      var maxAttempts = 15; // Increased from implicit 1 to 15 attempts
      var baseDelay = 500; // Base delay in ms
      var maxDelay = 3000; // Maximum delay in ms
      
      // Calculate exponential backoff delay, but cap at maxDelay
      var delay = Math.min(baseDelay * Math.pow(1.2, attempt), maxDelay);
      
      setTimeout(function() {
        self.log(`🔍 Checking for existing Iubenda consent (attempt ${attempt + 1}/${maxAttempts})`, {
          iubAvailable: typeof _iub !== 'undefined',
          hasCs: typeof _iub !== 'undefined' && _iub.cs,
          hasApi: typeof _iub !== 'undefined' && _iub.cs && _iub.cs.api,
          apiMethods: typeof _iub !== 'undefined' && _iub.cs && _iub.cs.api ? Object.keys(_iub.cs.api) : [],
          domReady: document.readyState,
          attempt: attempt + 1
        });
        
        // Check if all necessary dependencies are loaded
        var dependenciesReady = (
          typeof _iub !== 'undefined' && 
          _iub.cs && 
          document.readyState !== 'loading'
        );
        
        if (!dependenciesReady && attempt < maxAttempts - 1) {
          self.log(`⏳ Dependencies not ready, retrying in ${delay}ms...`);
          self.checkExistingConsentWithRetry(attempt + 1);
          return;
        }
        
        if (typeof _iub !== 'undefined' && _iub.cs) {
          try {
            // In incognito mode or fresh sessions, wait for user interaction instead of assuming consent
            var consentStatus = null;
            
            // Only check for existing consent if we're confident it's valid
            // Look for Iubenda banner - if it's visible, no consent has been given yet
            var banner = document.querySelector('#iubenda-cs-banner, .iub-cs-banner, [id*="iubenda"], .iubenda-cs-banner, [class*="iubenda"]');
            
            if (banner && (banner.offsetParent !== null && banner.style.display !== 'none')) {
              // Banner is visible = no consent given yet
              self.log("🟡 Iubenda banner is visible, waiting for user interaction");
              consentStatus = null; // Don't assume any consent
            } else {
              // Banner is hidden or doesn't exist, check if consent was actually stored
              self.log("🔍 Iubenda banner not visible, checking for stored consent");
              
              // Check for explicit consent storage (be very strict)
              var cookieConsent = self.getCookie('iubenda_cookie_policy_agreement');
              var localConsent = localStorage.getItem('iubenda_cookie_policy_agreement');
              
              // Only trust consent if we have explicit storage AND the banner is actually hidden
              if ((cookieConsent || localConsent) && !banner) {
                var storedValue = cookieConsent || localConsent;
                var isConsented = storedValue === 'true' || storedValue === '1';
                var isDenied = storedValue === 'false' || storedValue === '0';
                
                if (isConsented || isDenied) {
                  consentStatus = { consent: isConsented };
                  self.log("📋 Valid Iubenda consent found in storage", { 
                    consent: isConsented, 
                    source: cookieConsent ? 'cookie' : 'localStorage',
                    value: storedValue
                  });
                } else {
                  self.log("⚠️ Iubenda storage found but value unclear, waiting for user interaction", { value: storedValue });
                }
              } else {
                self.log("ℹ️ No clear Iubenda consent storage found, waiting for user interaction");
              }
            }
            
            // Process the consent status
            if (consentStatus && consentStatus.consent === true) {
              self.log("✅ Existing Iubenda consent found (granted)");
              self.handleConsentGiven('button_click');
            } else if (consentStatus && consentStatus.consent === false) {
              self.log("❌ Existing Iubenda consent found (denied)");
              self.handleConsentDenied('button_click');
            } else {
              self.log("⏳ No existing Iubenda consent found, waiting for user interaction");
            }
          } catch (error) {
            self.log("❌ Error checking existing Iubenda consent", error);
            if (attempt < maxAttempts - 1) {
              self.log(`⏳ Error occurred, retrying in ${delay}ms... (attempt ${attempt + 1}/${maxAttempts})`);
              self.checkExistingConsentWithRetry(attempt + 1);
              return;
            }
            // Continue waiting for user interaction after max attempts
          }
        } else if (attempt < maxAttempts - 1) {
          self.log(`⏳ Iubenda not available yet, retrying in ${delay}ms... (attempt ${attempt + 1}/${maxAttempts})`);
          self.checkExistingConsentWithRetry(attempt + 1);
          return;
        } else {
          self.log("⚠️ Iubenda not available after maximum attempts, proceeding without Iubenda consent detection");
        }
      }, delay);
    },

    /**
     * Setup custom consent listeners using CSS selectors
     */
    setupCustomConsentListeners: function () {
      var self = this;
      
      // Double-check that we're not using Iubenda (safety check)
      var useIubenda = this.config.useIubenda === true || this.config.useIubenda === "1" || this.config.useIubenda === 1;
      if (useIubenda) {
        this.log("⚠️ Attempted to setup custom selectors but Iubenda is enabled - skipping");
        return;
      }
      
      this.log("🔧 Setting up custom consent listeners with selectors", {
        acceptSelector: this.config.acceptSelector,
        denySelector: this.config.denySelector
      });

      if (this.config.acceptSelector) {
        // Use both click and change events to catch different consent implementations
        $(document).on('click change', this.config.acceptSelector, function (e) {
          e.preventDefault(); // Prevent default behavior
          self.log("Custom accept consent clicked", { selector: self.config.acceptSelector });
          
          // Add small delay to ensure consent UI has updated
          setTimeout(function() {
            self.handleConsentGiven('button_click');
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
            self.handleConsentDenied('button_click');
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
                self.handleConsentGiven('button_click');
              } else {
                self.handleConsentDenied('button_click');
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
          self.consentProcessed = true; // NEW: Mark as processed
          // Add consent_reason if not present
          if (!storedConsent.consent_reason) {
            storedConsent.consent_reason = 'button_click';
          }
          self.applyConsent(storedConsent);
          self.enableTracking();
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
            this.handleConsentGiven('button_click');
            return;
          } else if (normalizedValue.includes('deny') || normalizedValue.includes('false') || normalizedValue === '0' || normalizedValue === 'no') {
            this.handleConsentDenied('button_click');
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
     * Setup default consent timeout - auto-accept after X seconds
     */
    setupDefaultConsentTimeout: function () {
      var self = this;
      
      // Don't set timeout if it's 0 or negative
      if (!this.config.defaultTimeout || this.config.defaultTimeout <= 0) {
        this.log("Default consent timeout disabled (set to 0 or negative)");
        return;
      }
      
      var timeoutAction = this.config.timeoutAction || 'deny';
      var timeoutActionText = timeoutAction === 'accept' ? 'accept' : 'deny';
      
      this.log("⏰ Setting up auto-" + timeoutActionText + " timeout", { 
        timeout: this.config.defaultTimeout,
        timeoutMs: this.config.defaultTimeout * 1000,
        timeoutAction: timeoutAction,
        message: "Will auto-" + timeoutActionText + " consent after " + this.config.defaultTimeout + " seconds"
      });

      this.consentTimeout = setTimeout(function () {
        // Check if consent was already given manually
        if (self.consentProcessed) {
          self.log("⏰ Timeout reached but consent already processed manually - no action needed");
          return;
        }
        
        if (timeoutAction === 'accept') {
          self.log("⏰ Auto-accept timeout reached (" + self.config.defaultTimeout + "s) - accepting consent automatically");
          self.handleConsentGiven('automatic_delay');
        } else {
          self.log("⏰ Auto-deny timeout reached (" + self.config.defaultTimeout + "s) - denying consent automatically");
          self.handleConsentDenied('automatic_delay');
        }
      }, this.config.defaultTimeout * 1000);
      
      // Log countdown in debug mode
      if (this.config.debugMode) {
        var countdown = this.config.defaultTimeout;
        var countdownInterval = setInterval(function() {
          countdown--;
          if (countdown <= 0 || self.consentProcessed) {
            clearInterval(countdownInterval);
            return;
          }
          
          if (countdown % 5 === 0) { // Log every 5 seconds
            self.log("⏳ Auto-" + timeoutActionText + " in " + countdown + " seconds (user can still accept/deny manually)");
          }
        }, 1000);
      }
    },

    /**
     * Clear consent timeout
     */
    clearConsentTimeout: function () {
      if (this.consentTimeout) {
        clearTimeout(this.consentTimeout);
        this.consentTimeout = null;
        this.log("🚫 Consent timeout cleared");
      }
    },

    /**
     * Handle consent given - Allow updates from denied to granted, prevent duplicates
     * @param {string} reason - How consent was obtained: 'button_click' or 'automatic_delay'
     */
    handleConsentGiven: function (reason = 'button_click') {
      // Prevent duplicate processing if consent is already GRANTED
      if (this.consentProcessed && this.consentGiven && this.consentStatus && this.consentStatus.ad_user_data === 'GRANTED') {
        this.log("⚠️ Consent already granted - ignoring duplicate consent given", {
          consentGiven: this.consentGiven,
          consentProcessed: this.consentProcessed,
          currentStatus: this.consentStatus.ad_user_data,
          source: "Probably user clicked after auto-accept timeout"
        });
        return;
      }
      
      // Allow updates from DENIED to GRANTED
      if (this.consentProcessed && this.consentStatus && this.consentStatus.ad_user_data === 'DENIED') {
        this.log("🔄 Updating consent from DENIED to GRANTED");
        // Reset flags to allow update
        this.consentProcessed = false;
        // Don't reset eventsProcessed here - we don't want to reprocess the same events
      }

      this.clearConsentTimeout();
      
      var consent = {
        ad_user_data: 'GRANTED',
        ad_personalization: 'GRANTED',
        consent_reason: reason,
      };

      this.consentGiven = true;
      this.consentStatus = consent;
      this.consentProcessed = true; // NEW: Mark as processed to prevent duplicates
      
      this.storeConsent(consent);
      this.applyConsent(consent);
      
      this.log("✅ Consent granted - processing queued events", { 
        consent: consent, 
        queuedEvents: this.eventQueue.length,
        consentProcessed: this.consentProcessed,
        note: "Events remain queued for batch sending on page unload"
      });

      // Enable tracking (events remain queued for batch sending)
      this.enableTracking();
    },

    /**
     * Handle consent denied - Allow updates from granted to denied
     * @param {string} reason - How consent was obtained: 'button_click' or 'automatic_delay'
     */
    handleConsentDenied: function (reason = 'button_click') {
      // Allow updates if consent was previously granted
      if (this.consentProcessed && this.consentGiven && this.consentStatus && this.consentStatus.ad_user_data === 'DENIED') {
        this.log("⚠️ Consent already denied - ignoring duplicate consent denied", {
          consentGiven: this.consentGiven,
          consentProcessed: this.consentProcessed,
          currentStatus: this.consentStatus.ad_user_data
        });
        return;
      }
      
      if (this.consentProcessed && this.consentStatus && this.consentStatus.ad_user_data === 'GRANTED') {
        this.log("🔄 Updating consent from GRANTED to DENIED");
        // Reset flags to allow update
        this.consentProcessed = false;
      }

      this.clearConsentTimeout();
      
      var consent = {
        ad_user_data: 'DENIED',
        ad_personalization: 'DENIED',
        consent_reason: reason,
      };

      this.consentGiven = true; // Allow events to be sent (but anonymized)
      this.consentStatus = consent;
      this.consentProcessed = true; // NEW: Mark as processed to prevent duplicates

      this.storeConsent(consent);
      this.applyConsent(consent);
      
      this.log("❌ Consent denied - processing queued events with anonymization", { 
        consent: consent, 
        queuedEvents: this.eventQueue.length,
        consentProcessed: this.consentProcessed,
        note: "Events remain queued for batch sending with anonymization on page unload"
      });

      // Enable tracking (events remain queued for batch sending with anonymization)
      this.enableTracking();
    },

    /**
     * Apply consent settings
     */
    applyConsent: function (consent) {
      // Store consent globally for other functions to access
      window.GA4ConsentStatus = consent;

      // Update consent mode if enabled
      if (this.config.consentModeEnabled && typeof gtag === 'function') {
        // Create full consent object for Google Consent Mode
        var fullConsent = {
          ad_user_data: consent.ad_user_data,
          ad_personalization: consent.ad_personalization,
        };
        
        gtag('consent', 'update', fullConsent);
      }

      // Process queued events now that consent is determined
      this.processQueuedEvents();

      // Trigger custom event for other scripts to listen to
      $(document).trigger('ga4ConsentUpdated', [consent]);
    },

    /**
     * Initialize Google Consent Mode v2
     */
    initializeConsentMode: function () {
      if (typeof gtag === 'function') {
        gtag('consent', 'default', {
          ad_user_data: 'DENIED',
          ad_personalization: 'DENIED'
        });

        this.log("Google Consent Mode v2 initialized with denied defaults");
      }
    },

    /**
     * Store consent in localStorage
     */
    storeConsent: function (consent) {
      try {
        // Add timestamp for expiration validation
        consent.timestamp = Date.now();
        localStorage.setItem('ga4_consent_status', JSON.stringify(consent));
        this.log("Consent stored in localStorage with timestamp", consent);
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
     * Get consent data formatted for server-side events (GA4 only needs ad_user_data, ad_personalization, and consent_reason)
     */
    getConsentForServerSide: function () {
      var consent = this.consentStatus || this.getStoredConsent();

      if (!consent) {
        return {
          ad_user_data: "DENIED",
          ad_personalization: "DENIED",
          consent_reason: "no_consent"
        };
      }

      return {
        ad_user_data: consent.ad_user_data || "DENIED",
        ad_personalization: consent.ad_personalization || "DENIED",
        consent_reason: consent.consent_reason || "unknown"
      };
    },

    /**
     * Check if analytics tracking is allowed
     */
    isAnalyticsAllowed: function () {
      var consent = this.getStoredConsent();
      return consent && consent.ad_user_data === 'GRANTED';
    },

    /**
     * Check if advertising tracking is allowed
     */
    isAdvertisingAllowed: function () {
      var consent = this.getStoredConsent();
      return consent && (consent.ad_user_data === 'GRANTED' || consent.ad_personalization === 'GRANTED');
    },

    /**
     * Get consent mode
     */
    getConsentMode: function () {
      var consent = this.getStoredConsent();
      
      if (!consent) {
        return 'UNKNOWN';
      }
      
      if (consent.ad_user_data === 'GRANTED' && consent.ad_personalization === 'GRANTED') {
        return 'GRANTED';
      } else if (consent.ad_user_data === 'DENIED' && consent.ad_personalization === 'DENIED') {
        return 'DENIED';
      } else {
        return 'DENIED';
      }
    },

    /**
     * Get consent-aware client ID
     */
    getConsentAwareClientId: function() {
      var consent = this.getConsentForServerSide();
      
      if (consent.ad_user_data === "GRANTED") {
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

      if (consent.ad_user_data === "DENIED") {
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

      if (consent.ad_user_data === "DENIED" && consent.ad_personalization === "DENIED") {
        // Remove advertising/attribution data
        delete anonymizedParams.gclid;
        delete anonymizedParams.content;
        delete anonymizedParams.term;
        
        // Anonymize campaign info for paid traffic
        if (anonymizedParams.campaign && 
            !["(organic)", "(direct)", "(not set)", "(referral)"].includes(anonymizedParams.campaign)) {
          anonymizedParams.campaign = "(denied consent)";
        }
        
        // Remove detailed referrer info for paid traffic
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

    /**
     * Manual consent management methods
     */
    grantConsent: function () {
      this.handleConsentGiven('button_click');
    },

    denyConsent: function () {
      this.handleConsentDenied('button_click');
    },


    testForceConsent: function() {
      this.log("🧪 TEST: Force enabling consent for testing (bypass normal flow)");
      this.consentGiven = true;
      this.consentStatus = {
        ad_user_data: 'GRANTED',
        ad_personalization: 'GRANTED',
      };
      this.log("🧪 TEST: Consent forced for testing - you can now test batch sending");
    },

    resetConsent: function () {
      this.clearStoredConsent();
      this.clearConsentTimeout();
      this.clearEventQueue();
      this.consentGiven = false;
      this.consentStatus = null;
      this.consentProcessed = false; // Reset the processed flag
      this.eventsProcessed = false; // Reset the events processed flag
      
      // Reset consent mode if enabled
      if (this.config.consentModeEnabled) {
        this.initializeConsentMode();
      }
      
      this.log("Consent reset - events will be queued again");
    },

    // Log messages if debug mode is enabled
    log: function (message, data) {
      GA4Utils.helpers.log(
        message,
        data,
        this.config,
        "[GA4 Consent Manager]"
      );
    },


    /**
     * Load queued events from unified user data storage for persistence across page refreshes
     */
    loadQueuedEventsFromSession: function() {
      try {
        this.log("🔍 Starting queue restoration from unified user data storage", {
          currentQueueLength: this.eventQueue.length
        });
        
        // Load from unified user data storage (same as attribution data)
        var userData = GA4Utils.storage.getUserData();
        var batchEvents = userData.batchEvents || [];
        
        // Clean up old storage key if it exists (migration)
        try {
          var oldStorageData = localStorage.getItem('ga4_queued_events');
          if (oldStorageData) {
            this.log("🔄 Migrating events from old storage key to unified storage");
            var oldData = JSON.parse(oldStorageData);
            if (oldData && oldData.events && Array.isArray(oldData.events) && oldData.events.length > 0) {
              // Merge old events with new ones (if any)
              batchEvents = batchEvents.concat(oldData.events);
              this.log("📦 Merged " + oldData.events.length + " events from old storage");
            }
            // Remove old storage key
            localStorage.removeItem('ga4_queued_events');
            this.log("🧹 Cleaned up old storage key");
          }
        } catch (e) {
          this.log("⚠️ Failed to migrate from old storage key:", e);
        }
        
        this.log("🔍 User data storage check", {
          hasUserData: !!userData,
          hasBatchEvents: !!batchEvents,
          batchEventCount: batchEvents.length,
          userDataTimestamp: userData.timestamp
        });
        
        if (batchEvents && Array.isArray(batchEvents) && batchEvents.length > 0) {
          // Filter out events older than 24 hours to prevent stale data
          const maxAge = 24 * 60 * 60 * 1000; // 24 hours
          const now = Date.now();
          const validEvents = batchEvents.filter(function(event) {
            return event.timestamp && (now - event.timestamp) < maxAge;
          });
          
          // Update both in-memory queue and storage
          this.eventQueue = validEvents;
          
          // If we cleaned up expired events, save back to storage
          if (validEvents.length !== batchEvents.length) {
            this.log("🧹 Saving cleaned events back to unified storage");
            userData.batchEvents = validEvents;
            GA4Utils.storage.saveUserData(userData);
          }
          
          this.log("✅ Loaded queued events from unified user data storage", {
            totalFound: batchEvents.length,
            validEvents: validEvents.length,
            expiredEvents: batchEvents.length - validEvents.length,
            events: validEvents.map(function(e) { 
              return {
                name: e.eventName, 
                isComplete: e.isCompleteData,
                pageUrl: e.isCompleteData ? e.eventParams.page_location : e.pageUrl
              };
            })
          });
        } else {
          this.log("📭 No queued events found in unified user data storage");
          this.eventQueue = [];
        }
        
      } catch (e) {
        this.log("❌ Failed to load queued events from unified user data storage", e);
        this.eventQueue = [];
      }
    },

    /**
     * Save queued events to unified user data storage for persistence across page refreshes
     * NOTE: This function is now largely obsolete since events are stored directly in queueEvent
     */
    saveQueuedEventsToSession: function() {
      // This function is kept for compatibility but events are now stored directly
      this.log("⚠️ saveQueuedEventsToSession called but events are now stored directly");
    },

    /**
     * Clear queued events from unified user data storage
     * NOTE: This function is now largely obsolete since clearing is done directly in sendBatchEvents
     */
    clearQueuedEventsFromSession: function() {
      // This function is kept for compatibility but clearing is now done directly
      this.log("⚠️ clearQueuedEventsFromSession called but clearing is now done directly");
    },


  };

  // Expose globally
  window.GA4ConsentManager = GA4ConsentManager;
  
  // Track localStorage changes for debugging
  const originalSetItem = localStorage.setItem;
  const originalRemoveItem = localStorage.removeItem;
  const originalClear = localStorage.clear;
  
  localStorage.setItem = function(key, value) {
    if (key === 'ga4_user_data') {
      try {
        var parsed = JSON.parse(value);
        var batchEventCount = parsed.batchEvents ? parsed.batchEvents.length : 0;
        if(window.GA4ServerSideTagging.debugMode){
          console.log("[GA4 Consent] 💾 SETTING unified storage:", key, value ? value.length + ' bytes' : 'null', 'batchEvents:', batchEventCount);
        }
      } catch (e) {
          if(window.GA4ServerSideTagging.debugMode){
          console.log("[GA4 Consent] 💾 SETTING unified storage:", key, value ? value.length + ' bytes' : 'null', 'batchEvents:', batchEventCount);
        }      
      }
    }
    return originalSetItem.apply(this, arguments);
  };
  
  localStorage.removeItem = function(key) {
    if (key === 'ga4_user_data') {
      if(window.GA4ServerSideTagging.debugMode){
      console.log("[GA4 Consent] 🗑️ REMOVING unified storage:", key);
    }
  }
    return originalRemoveItem.apply(this, arguments);
  };
  
  localStorage.clear = function() {
    if(window.GA4ServerSideTagging.debugMode){
      console.log("[GA4 Consent] 🧹 CLEARING ALL localStorage");
    }
    return originalClear.apply(this, arguments);
  };
  

})(window, jQuery);