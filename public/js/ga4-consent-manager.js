/**
 * GDPR Consent Manager
 * Handles consent management for GA4 Server-Side Tagging
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

    /**
     * Initialize the consent manager
     */
    init: function (config) {
      this.config = config || {};
      
      GA4Utils.helpers.log(
        "Initializing GDPR Consent Manager",
        this.config,
        this.config,
        "[Consent Manager]"
      );

      // Check for existing consent
      var existingConsent = this.getStoredConsent();
      
      if (existingConsent) {
        GA4Utils.helpers.log(
          "Found existing consent",
          existingConsent,
          this.config,
          "[Consent Manager]"
        );
        this.applyConsent(existingConsent);
        return;
      }

      // Set up consent listeners
      this.setupConsentListeners();

      // Set up default consent timeout if configured
      if (this.config.defaultTimeout && this.config.defaultTimeout > 0) {
        this.setupDefaultConsentTimeout();
      }

      // Initialize consent mode
      if (this.config.consentModeEnabled) {
        this.initializeConsentMode();
      }
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

      // Wait for Iubenda to be available
      var checkIubenda = function () {
        if (typeof _iub !== 'undefined' && _iub.csConfiguration) {
          // Iubenda is loaded
          _iub.csConfiguration.callback = _iub.csConfiguration.callback || {};
          
          // Listen for consent given
          _iub.csConfiguration.callback.onConsentGiven = function () {
            GA4Utils.helpers.log(
              "Iubenda consent given",
              null,
              self.config,
              "[Consent Manager]"
            );
            self.handleConsentGiven();
          };

          // Listen for consent rejected
          _iub.csConfiguration.callback.onConsentRejected = function () {
            GA4Utils.helpers.log(
              "Iubenda consent rejected",
              null,
              self.config,
              "[Consent Manager]"
            );
            self.handleConsentDenied();
          };

          // Check if consent was already given
          if (typeof _iub.csConfiguration.callback.onConsentFirstGiven === 'function') {
            _iub.csConfiguration.callback.onConsentFirstGiven();
          }
        } else {
          // Retry after 100ms
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

      // Accept consent listener
      if (this.config.acceptSelector) {
        $(document).on('click', this.config.acceptSelector, function (e) {
          GA4Utils.helpers.log(
            "Custom accept consent clicked",
            { selector: self.config.acceptSelector },
            self.config,
            "[Consent Manager]"
          );
          self.handleConsentGiven();
        });
      }

      // Deny consent listener
      if (this.config.denySelector) {
        $(document).on('click', this.config.denySelector, function (e) {
          GA4Utils.helpers.log(
            "Custom deny consent clicked",
            { selector: self.config.denySelector },
            self.config,
            "[Consent Manager]"
          );
          self.handleConsentDenied();
        });
      }
    },

    /**
     * Setup default consent timeout
     */
    setupDefaultConsentTimeout: function () {
      var self = this;
      
      GA4Utils.helpers.log(
        "Setting up consent timeout",
        { timeout: this.config.defaultTimeout },
        this.config,
        "[Consent Manager]"
      );

      this.consentTimeout = setTimeout(function () {
        GA4Utils.helpers.log(
          "Consent timeout reached - applying default consent",
          null,
          self.config,
          "[Consent Manager]"
        );
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
        analytics_storage: 'granted',
        ad_storage: 'granted',
        ad_user_data: 'granted',
        ad_personalization: 'granted',
        functionality_storage: 'granted',
        personalization_storage: 'granted',
        security_storage: 'granted',
        timestamp: Date.now()
      };

      this.storeConsent(consent);
      this.applyConsent(consent);
      
      GA4Utils.helpers.log(
        "Consent granted - full tracking enabled",
        consent,
        this.config,
        "[Consent Manager]"
      );
    },

    /**
     * Handle consent denied
     */
    handleConsentDenied: function () {
      this.clearConsentTimeout();
      
      var consent = {
        analytics_storage: 'denied',
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        functionality_storage: 'denied',
        personalization_storage: 'denied',
        security_storage: 'granted', // Always granted for security
        timestamp: Date.now()
      };

      this.storeConsent(consent);
      this.applyConsent(consent);
      
      GA4Utils.helpers.log(
        "Consent denied - limited tracking enabled",
        consent,
        this.config,
        "[Consent Manager]"
      );
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
        // Set default consent state (denied by default for GDPR compliance)
        gtag('consent', 'default', {
          analytics_storage: 'denied',
          ad_storage: 'denied',
          ad_user_data: 'denied',
          ad_personalization: 'denied',
          functionality_storage: 'denied',
          personalization_storage: 'denied',
          security_storage: 'granted',
          wait_for_update: 2000 // Wait 2 seconds for consent update
        });

        GA4Utils.helpers.log(
          "Google Consent Mode v2 initialized",
          null,
          this.config,
          "[Consent Manager]"
        );
      }
    },

    /**
     * Store consent in localStorage
     */
    storeConsent: function (consent) {
      try {
        localStorage.setItem('ga4_consent_status', JSON.stringify(consent));
        GA4Utils.helpers.log(
          "Consent stored in localStorage",
          consent,
          this.config,
          "[Consent Manager]"
        );
      } catch (e) {
        GA4Utils.helpers.log(
          "Failed to store consent in localStorage",
          e,
          this.config,
          "[Consent Manager]"
        );
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
            // Remove expired consent
            this.clearStoredConsent();
          }
        }
      } catch (e) {
        GA4Utils.helpers.log(
          "Failed to retrieve consent from localStorage",
          e,
          this.config,
          "[Consent Manager]"
        );
      }
      return null;
    },

    /**
     * Clear stored consent
     */
    clearStoredConsent: function () {
      try {
        localStorage.removeItem('ga4_consent_status');
        GA4Utils.helpers.log(
          "Cleared stored consent",
          null,
          this.config,
          "[Consent Manager]"
        );
      } catch (e) {
        GA4Utils.helpers.log(
          "Failed to clear stored consent",
          e,
          this.config,
          "[Consent Manager]"
        );
      }
    },

    /**
     * Check if analytics tracking is allowed
     */
    isAnalyticsAllowed: function () {
      var consent = this.getStoredConsent();
      return consent && consent.analytics_storage === 'granted';
    },

    /**
     * Check if advertising tracking is allowed
     */
    isAdvertisingAllowed: function () {
      var consent = this.getStoredConsent();
      return consent && consent.ad_storage === 'granted';
    },

    /**
     * Get consent mode for server-side events
     */
    getConsentMode: function () {
      var consent = this.getStoredConsent();
      
      if (!consent) {
        return 'unknown';
      }
      
      if (consent.analytics_storage === 'granted' && consent.ad_storage === 'granted') {
        return 'granted';
      } else if (consent.analytics_storage === 'denied' && consent.ad_storage === 'denied') {
        return 'denied';
      } else {
        return 'partial';
      }
    },

    /**
     * Get location data based on consent
     */
    getConsentBasedLocation: function () {
      return new Promise((resolve, reject) => {
        var consent = this.getStoredConsent();
        
        if (consent && consent.analytics_storage === 'granted') {
          // Full location tracking allowed
          GA4Utils.location.get()
            .then(resolve)
            .catch(reject);
        } else {
          // Only timezone-based location allowed
          this.getTimezoneBasedLocation()
            .then(resolve)
            .catch(reject);
        }
      });
    },

    /**
     * Get timezone-based location (continent level only)
     */
    getTimezoneBasedLocation: function () {
      return new Promise((resolve) => {
        try {
          var timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
          
          GA4Utils.helpers.log(
            "Getting timezone-based location",
            { timezone: timezone },
            this.config,
            "[Consent Manager]"
          );

          if (!timezone) {
            resolve({});
            return;
          }

          // Map common timezones to continents and countries
          var timezoneMap = {
            // Europe
            'Europe/Amsterdam': { continent: 'Europe', country: 'NL', city: 'Amsterdam' },
            'Europe/Berlin': { continent: 'Europe', country: 'DE', city: 'Berlin' },
            'Europe/Brussels': { continent: 'Europe', country: 'BE', city: 'Brussels' },
            'Europe/London': { continent: 'Europe', country: 'GB', city: 'London' },
            'Europe/Paris': { continent: 'Europe', country: 'FR', city: 'Paris' },
            'Europe/Rome': { continent: 'Europe', country: 'IT', city: 'Rome' },
            'Europe/Madrid': { continent: 'Europe', country: 'ES', city: 'Madrid' },
            'Europe/Stockholm': { continent: 'Europe', country: 'SE', city: 'Stockholm' },
            'Europe/Warsaw': { continent: 'Europe', country: 'PL', city: 'Warsaw' },
            'Europe/Vienna': { continent: 'Europe', country: 'AT', city: 'Vienna' },
            
            // North America
            'America/New_York': { continent: 'North America', country: 'US', city: 'New York' },
            'America/Los_Angeles': { continent: 'North America', country: 'US', city: 'Los Angeles' },
            'America/Chicago': { continent: 'North America', country: 'US', city: 'Chicago' },
            'America/Toronto': { continent: 'North America', country: 'CA', city: 'Toronto' },
            'America/Vancouver': { continent: 'North America', country: 'CA', city: 'Vancouver' },
            'America/Mexico_City': { continent: 'North America', country: 'MX', city: 'Mexico City' },
            
            // Asia
            'Asia/Tokyo': { continent: 'Asia', country: 'JP', city: 'Tokyo' },
            'Asia/Shanghai': { continent: 'Asia', country: 'CN', city: 'Shanghai' },
            'Asia/Singapore': { continent: 'Asia', country: 'SG', city: 'Singapore' },
            'Asia/Kolkata': { continent: 'Asia', country: 'IN', city: 'Kolkata' },
            'Asia/Dubai': { continent: 'Asia', country: 'AE', city: 'Dubai' },
            
            // Australia & Oceania
            'Australia/Sydney': { continent: 'Oceania', country: 'AU', city: 'Sydney' },
            'Australia/Melbourne': { continent: 'Oceania', country: 'AU', city: 'Melbourne' },
            'Pacific/Auckland': { continent: 'Oceania', country: 'NZ', city: 'Auckland' },
            
            // South America
            'America/Sao_Paulo': { continent: 'South America', country: 'BR', city: 'Sao Paulo' },
            'America/Buenos_Aires': { continent: 'South America', country: 'AR', city: 'Buenos Aires' },
            'America/Lima': { continent: 'South America', country: 'PE', city: 'Lima' },
            
            // Africa
            'Africa/Cairo': { continent: 'Africa', country: 'EG', city: 'Cairo' },
            'Africa/Johannesburg': { continent: 'Africa', country: 'ZA', city: 'Johannesburg' },
            'Africa/Lagos': { continent: 'Africa', country: 'NG', city: 'Lagos' }
          };

          var locationData = timezoneMap[timezone];
          
          if (!locationData) {
            // Fallback: try to extract continent from timezone
            var parts = timezone.split('/');
            if (parts.length >= 2) {
              var continent = parts[0];
              var city = parts[1].replace(/_/g, ' ');
              
              locationData = {
                continent: continent,
                country: '',
                city: city
              };
            } else {
              locationData = { continent: 'Unknown', country: '', city: '' };
            }
          }

          // For GDPR compliance, only return continent when consent is denied
          var result = {
            geo_continent: locationData.continent,
            timezone: timezone
          };

          GA4Utils.helpers.log(
            "Timezone-based location determined",
            result,
            this.config,
            "[Consent Manager]"
          );

          resolve(result);
        } catch (e) {
          GA4Utils.helpers.log(
            "Error getting timezone-based location",
            e,
            this.config,
            "[Consent Manager]"
          );
          resolve({});
        }
      });
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
      
      // Reset consent mode if enabled
      if (this.config.consentModeEnabled) {
        this.initializeConsentMode();
      }
      
      GA4Utils.helpers.log(
        "Consent reset",
        null,
        this.config,
        "[Consent Manager]"
      );
    }
  };

  // Expose globally
  window.GA4ConsentManager = GA4ConsentManager;

})(window, jQuery);