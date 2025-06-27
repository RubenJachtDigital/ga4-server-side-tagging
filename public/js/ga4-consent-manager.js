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
    sessionStorageKey: 'ga4_queued_events', // Key for storing events in sessionStorage

    /**
     * Initialize the consent manager
     */
    init: function (config, trackingInstance) {
      this.config = config || {};
      this.trackingInstance = trackingInstance;
      
      
      // Load any existing queued events from session storage
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
     * @param {Object} eventParams - The basic event parameters
     * @param {Object} completeEventData - The complete event data with all context (optional)
     * @returns {boolean} - true if event should be sent now, false if queued
     */
    shouldSendEvent: function(eventName, eventParams, completeEventData) {
      
      if (this.consentGiven) {
        return true; // Send immediately
      }

      // Queue the event with complete data if available
      if (completeEventData) {
        this.queueEvent(eventName, completeEventData, true); // true = isCompleteData
      } else {
        this.queueEvent(eventName, eventParams, false); // false = basicParams only
      }
      return false; // Don't send now
    },

    /**
     * Queue an event until consent is given
     * @param {string} eventName - The event name
     * @param {Object} eventParams - Event parameters (basic or complete)
     * @param {boolean} isCompleteData - Whether eventParams contains complete enriched data
     */
    queueEvent: function(eventName, eventParams, isCompleteData = false) {
      var queuedEvent = {
        eventName: eventName,
        eventParams: eventParams,
        timestamp: Date.now(),
        isCompleteData: isCompleteData
      };
      
      // If we don't have complete data, add basic page context for reference
      if (!isCompleteData) {
        queuedEvent.pageUrl = window.location.href;
        queuedEvent.pageTitle = document.title;
      }
      
      this.eventQueue.push(queuedEvent);
      
      // Save to session storage to persist across page navigation
      this.saveQueuedEventsToSession();

    },

    /**
     * Process all queued events
     */
    processQueuedEvents: function() {
      if (this.eventQueue.length === 0) {
        return;
      }

      // Prevent duplicate processing if events were already processed
      if (this.eventsProcessed) {
        this.eventQueue = []; // Clear queue
        return;
      }


      // Mark events as being processed
      this.eventsProcessed = true;

      // Process events in order
      var eventsToProcess = this.eventQueue.slice(); // Copy array
      this.eventQueue = []; // Clear queue immediately to prevent reprocessing
      
      // Clear session storage since events are being processed
      this.clearQueuedEventsFromSession();

      // Use setTimeout to ensure events are processed after consent is fully applied
      setTimeout(function() {
        eventsToProcess.forEach(function(queuedEvent, index) {

          // Add small delay between events to prevent overwhelming the server
          setTimeout(async function() {
            await this.sendEventWithBypass(queuedEvent.eventName, queuedEvent.eventParams, queuedEvent.isCompleteData);
          }.bind(this), index * 100); // 100ms delay between events
          
        }.bind(this));
      }.bind(this), 200); // 200ms delay before starting to process
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
            setTimeout(function() {
              self.handleConsentGiven('button_click');
            }, 50); // Small delay to ensure Iubenda has finished processing
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
            setTimeout(function() {
              self.handleConsentDenied('button_click');
            }, 50); // Small delay to ensure Iubenda has finished processing
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
      
      // Accept button listeners
      $(document).on('click', iubendaAcceptSelectors, function(e) {
        self.log("🟢 Iubenda ACCEPT button clicked directly (fallback)", { 
          element: this.className,
          selector: this.tagName + '.' + this.className.split(' ').join('.')
        });
        
        // Small delay to let Iubenda process first, then handle our consent
        setTimeout(function() {
          if (!self.consentProcessed) {
            self.log("📝 Processing accept via button fallback");
            self.handleConsentGiven('button_click');
          } else {
            self.log("ℹ️ Accept already processed via callback, skipping fallback");
          }
        }, 100);
      });
      
      // Reject button listeners  
      $(document).on('click', iubendaRejectSelectors, function(e) {
        self.log("🔴 Iubenda REJECT button clicked directly (fallback)", { 
          element: this.className,
          selector: this.tagName + '.' + this.className.split(' ').join('.')
        });
        
        // Small delay to let Iubenda process first, then handle our consent
        setTimeout(function() {
          if (!self.consentProcessed) {
            self.log("📝 Processing reject via button fallback");
            self.handleConsentDenied('button_click');
          } else {
            self.log("ℹ️ Reject already processed via callback, skipping fallback");
          }
        }, 100);
      });
      
      // Also watch for buttons that appear dynamically
      this.watchForIubendaButtons();
    },

    /**
     * Watch for Iubenda buttons that might be added dynamically
     */
    watchForIubendaButtons: function() {
      var self = this;
      var checkInterval = setInterval(function() {
        var acceptBtn = document.querySelector('.iubenda-cs-accept-btn, .iub-cs-accept-btn');
        var rejectBtn = document.querySelector('.iubenda-cs-reject-btn, .iub-cs-reject-btn');
        
        if (acceptBtn || rejectBtn) {
          self.log("✨ Iubenda buttons detected in DOM", {
            acceptBtn: !!acceptBtn,
            rejectBtn: !!rejectBtn,
            acceptClass: acceptBtn ? acceptBtn.className : 'none',
            rejectClass: rejectBtn ? rejectBtn.className : 'none'
          });
          clearInterval(checkInterval);
        }
      }, 500);

      // Stop checking after 30 seconds
      setTimeout(function() {
        clearInterval(checkInterval);
      }, 30000);
    },

    /**
     * Check for existing Iubenda consent that may have been set before our listeners
     */
    checkExistingIubendaConsent: function() {
      var self = this;
      
      // Wait a bit for Iubenda to initialize
      setTimeout(function() {
        self.log("🔍 Checking for existing Iubenda consent", {
          iubAvailable: typeof _iub !== 'undefined',
          hasCs: typeof _iub !== 'undefined' && _iub.cs,
          hasApi: typeof _iub !== 'undefined' && _iub.cs && _iub.cs.api,
          apiMethods: typeof _iub !== 'undefined' && _iub.cs && _iub.cs.api ? Object.keys(_iub.cs.api) : []
        });
        
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
            // Continue waiting for user interaction
          }
        } else {
          self.log("⚠️ Iubenda not fully loaded yet, waiting for user interaction");
        }
      }, 1000); // Increased timeout to give Iubenda more time to load
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
      if (this.consentProcessed && this.consentGiven && this.consentStatus && this.consentStatus.analytics_storage === 'GRANTED') {
        this.log("⚠️ Consent already granted - ignoring duplicate consent given", {
          consentGiven: this.consentGiven,
          consentProcessed: this.consentProcessed,
          currentStatus: this.consentStatus.analytics_storage,
          source: "Probably user clicked after auto-accept timeout"
        });
        return;
      }
      
      // Allow updates from DENIED to GRANTED
      if (this.consentProcessed && this.consentStatus && this.consentStatus.analytics_storage === 'DENIED') {
        this.log("🔄 Updating consent from DENIED to GRANTED");
        // Reset flags to allow update
        this.consentProcessed = false;
        // Don't reset eventsProcessed here - we don't want to reprocess the same events
      }

      this.clearConsentTimeout();
      
      var consent = {
        analytics_storage: 'GRANTED',
        ad_storage: 'GRANTED',
        ad_user_data: 'GRANTED',
        ad_personalization: 'GRANTED',
        functionality_storage: 'GRANTED',
        personalization_storage: 'GRANTED',
        security_storage: 'GRANTED',
        consent_reason: reason,
        timestamp: Date.now()
      };

      this.consentGiven = true;
      this.consentStatus = consent;
      this.consentProcessed = true; // NEW: Mark as processed to prevent duplicates
      
      this.storeConsent(consent);
      this.applyConsent(consent);
      
      this.log("✅ Consent granted - processing queued events", { 
        consent: consent, 
        queuedEvents: this.eventQueue.length,
        consentProcessed: this.consentProcessed
      });

      // Enable tracking and process queued events
      this.enableTracking();
      this.processQueuedEvents();
    },

    /**
     * Handle consent denied - Allow updates from granted to denied
     * @param {string} reason - How consent was obtained: 'button_click' or 'automatic_delay'
     */
    handleConsentDenied: function (reason = 'button_click') {
      // Allow updates if consent was previously granted
      if (this.consentProcessed && this.consentGiven && this.consentStatus && this.consentStatus.analytics_storage === 'DENIED') {
        this.log("⚠️ Consent already denied - ignoring duplicate consent denied", {
          consentGiven: this.consentGiven,
          consentProcessed: this.consentProcessed,
          currentStatus: this.consentStatus.analytics_storage
        });
        return;
      }
      
      if (this.consentProcessed && this.consentStatus && this.consentStatus.analytics_storage === 'GRANTED') {
        this.log("🔄 Updating consent from GRANTED to DENIED");
        // Reset flags to allow update
        this.consentProcessed = false;
      }

      this.clearConsentTimeout();
      
      var consent = {
        analytics_storage: 'DENIED',
        ad_storage: 'DENIED',
        ad_user_data: 'DENIED',
        ad_personalization: 'DENIED',
        functionality_storage: 'DENIED',
        personalization_storage: 'DENIED',
        security_storage: 'GRANTED',
        consent_reason: reason,
        timestamp: Date.now()
      };

      this.consentGiven = true; // Allow events to be sent (but anonymized)
      this.consentStatus = consent;
      this.consentProcessed = true; // NEW: Mark as processed to prevent duplicates

      this.storeConsent(consent);
      this.applyConsent(consent);
      
      this.log("❌ Consent denied - processing queued events with anonymization", { 
        consent: consent, 
        queuedEvents: this.eventQueue.length,
        consentProcessed: this.consentProcessed
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
          analytics_storage: 'DENIED',
          ad_storage: 'DENIED',
          ad_user_data: 'DENIED',
          ad_personalization: 'DENIED',
          functionality_storage: 'DENIED',
          personalization_storage: 'DENIED',
          security_storage: 'GRANTED',
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
          consent_reason: "automatic_delay"
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
        consent_reason: consent.consent_reason || "button_click"
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
     * Load queued events from session storage
     */
    loadQueuedEventsFromSession: function() {
      try {
        var stored = sessionStorage.getItem(this.sessionStorageKey);
        if (stored) {
          var storedEvents = JSON.parse(stored);
          if (Array.isArray(storedEvents) && storedEvents.length > 0) {
            // Filter out events older than 1 hour to prevent stale events
            var oneHour = 60 * 60 * 1000;
            var now = Date.now();
            var validEvents = storedEvents.filter(function(event) {
              return event.timestamp && (now - event.timestamp) < oneHour;
            });
            
            this.eventQueue = validEvents;
            this.log("📦 Loaded queued events from session storage", {
              totalStored: storedEvents.length,
              validEvents: validEvents.length,
              filteredOldEvents: storedEvents.length - validEvents.length,
              events: validEvents.map(function(e) { 
                return {
                  name: e.eventName, 
                  isComplete: e.isCompleteData,
                  pageUrl: e.isCompleteData ? e.eventParams.page_location : e.pageUrl
                };
              })
            });
            
            // Update session storage with only valid events
            if (validEvents.length !== storedEvents.length) {
              this.saveQueuedEventsToSession();
            }
          }
        }
      } catch (e) {
        this.log("Failed to load queued events from session storage", e);
        this.eventQueue = [];
      }
    },

    /**
     * Save queued events to session storage
     */
    saveQueuedEventsToSession: function() {
      try {
        sessionStorage.setItem(this.sessionStorageKey, JSON.stringify(this.eventQueue));
        this.log("Saved queued events to session storage", {
          eventCount: this.eventQueue.length
        });
      } catch (e) {
        this.log("Failed to save queued events to session storage", e);
      }
    },

    /**
     * Clear queued events from session storage
     */
    clearQueuedEventsFromSession: function() {
      try {
        sessionStorage.removeItem(this.sessionStorageKey);
        this.log("Cleared queued events from session storage");
      } catch (e) {
        this.log("Failed to clear queued events from session storage", e);
      }
    }

  };

  // Expose globally
  window.GA4ConsentManager = GA4ConsentManager;

})(window, jQuery);