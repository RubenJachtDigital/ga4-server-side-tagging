/**
 * Google Ads Conversion Tracking via Existing GA4 Cloudflare Worker
 * Sends conversion data to the same worker that handles GA4 events
 *
 * @since 1.0.0
 */

(function ($) {
  "use strict";

  // Google Ads Conversion Tracking Client
  var GoogleAdsTracking = {
    // Configuration
    config: window.googleAdsTracking || {},

    init: function () {
      // Check if we have the required configuration
      if (!this.config.conversionId) {
        this.log("Google Ads Conversion ID not configured");
        return;
      }

      if (!this.config.cloudflareWorkerUrl) {
        this.log("Cloudflare Worker URL not configured - using existing GA4 worker");
        // Try to use the GA4 worker URL if available
        if (window.ga4ServerSideTagging && window.ga4ServerSideTagging.cloudflareWorkerUrl) {
          this.config.cloudflareWorkerUrl = window.ga4ServerSideTagging.cloudflareWorkerUrl;
          this.log("Using GA4 Cloudflare Worker URL: " + this.config.cloudflareWorkerUrl);
        } else {
          this.log("No Cloudflare Worker URL available");
          return;
        }
      }

      // Set up conversion tracking
      this.setupConversionTracking();

      // Log initialization
      this.log("Google Ads Conversion Tracking initialized via existing Cloudflare Worker");
    },

    // Set up conversion tracking based on page type and events
    setupConversionTracking: function () {
      var self = this;

      // Track purchase conversions on order confirmation page
      if (this.isOrderConfirmationPage()) {
        this.trackPurchaseConversion();
      }

      // Track quote request conversions
      if (this.config.quoteData) {
        this.trackQuoteConversion();
      }

      // Set up event listeners for other conversions
      this.setupEventListeners();
    },

    // Track purchase conversion
    trackPurchaseConversion: function () {
      if (!this.config.orderData) {
        this.log("No order data available for purchase conversion");
        return;
      }

      var orderData = this.config.orderData;
      var conversionData = this.buildPurchaseConversionData(orderData);
      
      this.log("Tracking purchase conversion", conversionData);
      this.sendConversionToWorker('google_ads_purchase', conversionData);
    },

    // Track quote/lead conversion
    trackQuoteConversion: function () {
      if (!this.config.quoteData) {
        this.log("No quote data available for lead conversion");
        return;
      }

      var quoteData = this.config.quoteData;
      var conversionData = this.buildLeadConversionData(quoteData);
      
      this.log("Tracking lead conversion", conversionData);
      this.sendConversionToWorker('google_ads_lead', conversionData);
    },

    // Build purchase conversion data
    buildPurchaseConversionData: function (orderData) {
      var userInfo = this.getUserInfo();
      var sessionInfo = this.getSessionInfo();
      
      return {
        // Event identification
        conversion_type: 'purchase',
        conversion_id: this.config.conversionId,
        conversion_label: this.config.purchaseConversionLabel || '',
        
        // Order Information
        transaction_id: orderData.transaction_id,
        value: parseFloat(orderData.value) || 0,
        currency: orderData.currency || 'EUR',
        
        // Enhanced Conversions Data (will be hashed on server)
        email: userInfo.email || (orderData.customer_data ? orderData.customer_data.email : ''),
        phone: userInfo.phone || (orderData.customer_data ? orderData.customer_data.phone : ''),
        first_name: userInfo.firstName || (orderData.customer_data ? orderData.customer_data.first_name : ''),
        last_name: userInfo.lastName || (orderData.customer_data ? orderData.customer_data.last_name : ''),
        street_address: orderData.customer_data ? orderData.customer_data.address : '',
        city: orderData.customer_data ? orderData.customer_data.city : '',
        region: orderData.customer_data ? orderData.customer_data.state : '',
        postal_code: orderData.customer_data ? orderData.customer_data.postcode : '',
        country: orderData.customer_data ? orderData.customer_data.country : '',
        
        // Attribution Data
        gclid: this.getGclid() || '',
        utm_source: this.getUtmSource() || '',
        utm_medium: this.getUtmMedium() || '',
        utm_campaign: this.getUtmCampaign() || '',
        utm_content: this.getUtmContent() || '',
        utm_term: this.getUtmTerm() || '',
        
        // Session and tracking data
        client_id: sessionInfo.client_id,
        session_id: sessionInfo.session_id,
        user_agent: navigator.userAgent,
        page_location: window.location.href,
        page_referrer: document.referrer || '',
        timestamp: Math.floor(Date.now() / 1000),
        
        // Items data for enhanced ecommerce
        items: orderData.items ? orderData.items.map(function(item) {
          return {
            item_id: item.item_id,
            item_name: item.item_name,
            item_category: item.item_category || '',
            quantity: parseInt(item.quantity) || 1,
            price: parseFloat(item.price) || 0
          };
        }) : []
      };
    },

    // Build lead conversion data
    buildLeadConversionData: function (quoteData) {
      var userInfo = this.getUserInfo();
      var sessionInfo = this.getSessionInfo();
      
      return {
        // Event identification
        conversion_type: 'lead',
        conversion_id: this.config.conversionId,
        conversion_label: this.config.leadConversionLabel || '',
        
        // Lead Information
        lead_id: quoteData.transaction_id || this.generateUniqueId(),
        value: parseFloat(quoteData.value) || parseFloat(this.config.defaultLeadValue) || 0,
        currency: quoteData.currency || 'EUR',
        
        // Enhanced Conversions Data
        email: userInfo.email || '',
        phone: userInfo.phone || '',
        first_name: userInfo.firstName || '',
        last_name: userInfo.lastName || '',
        
        // Attribution Data
        gclid: this.getGclid() || '',
        utm_source: this.getUtmSource() || '',
        utm_medium: this.getUtmMedium() || '',
        utm_campaign: this.getUtmCampaign() || '',
        utm_content: this.getUtmContent() || '',
        utm_term: this.getUtmTerm() || '',
        
        // Session and tracking data
        client_id: sessionInfo.client_id,
        session_id: sessionInfo.session_id,
        user_agent: navigator.userAgent,
        page_location: window.location.href,
        page_referrer: document.referrer || '',
        timestamp: Math.floor(Date.now() / 1000),
        
        // Items data (for quote requests)
        items: quoteData.items ? quoteData.items.map(function(item) {
          return {
            item_id: item.item_id,
            item_name: item.item_name,
            item_category: item.item_category || '',
            quantity: parseInt(item.quantity) || 1,
            price: parseFloat(item.price) || 0
          };
        }) : []
      };
    },

    // Send conversion data to Cloudflare Worker (same format as GA4 events)
    sendConversionToWorker: function (eventName, conversionData) {
      var self = this;
      
      // Format the payload like a GA4 event for the existing worker
      var payload = {
        name: eventName,
        params: conversionData
      };

      this.log("Sending Google Ads conversion to Cloudflare Worker", payload);

      fetch(this.config.cloudflareWorkerUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify(payload)
      })
      .then(function(response) {
        if (response.ok) {
          return response.json();
        }
        throw new Error('Network response was not ok: ' + response.status);
      })
      .then(function(data) {
        self.log("Google Ads conversion sent successfully", data);
      })
      .catch(function(error) {
        self.log("Error sending Google Ads conversion", error);
        console.error('[Google Ads Tracking] Error sending conversion:', error);
      });
    },

    // Set up event listeners for other conversion types
    setupEventListeners: function () {
      var self = this;

      // Track form submissions as leads (excluding WooCommerce forms)
      $(document).on('submit', 'form:not(.cart, .woocommerce-cart-form, .checkout, .woocommerce-checkout)', function() {
        var $form = $(this);
        var formId = $form.attr('id') || '';
        
        // Skip if it's a search form or other non-conversion form
        if ($form.hasClass('search-form') || $form.attr('role') === 'search') {
          return;
        }

        // Build lead conversion data from form
        var leadData = {
          transaction_id: 'form_' + formId + '_' + Date.now(),
          value: self.config.defaultLeadValue || 0,
          currency: self.config.currency || 'EUR'
        };

        var conversionData = self.buildLeadConversionData(leadData);
        
        self.log("Tracking form submission as lead conversion", conversionData);
        self.sendConversionToWorker('google_ads_lead', conversionData);
      });

      // Track specific quote form submission
      if ($('#gform_3').length) {
        $('#gform_3').on('submit', function() {
          self.log("Request a quote form submitted");
          
          if (self.config.quoteData) {
            self.trackQuoteConversion();
          } else {
            // Create basic quote data if not available
            var quoteData = {
              transaction_id: 'quote_' + Date.now(),
              value: self.config.defaultQuoteValue || 0,
              currency: self.config.currency || 'EUR'
            };
            
            var conversionData = self.buildLeadConversionData(quoteData);
            self.sendConversionToWorker('google_ads_lead', conversionData);
          }
        });
      }

      // Track phone clicks as conversions (if configured)
      if (self.config.trackPhoneCalls) {
        $(document).on('click', 'a[href^="tel:"]', function() {
          var phoneNumber = $(this).attr('href').replace('tel:', '');
          
          var leadData = {
            transaction_id: 'phone_' + Date.now(),
            value: self.config.phoneCallValue || 0,
            currency: self.config.currency || 'EUR',
            conversion_action: 'phone_call',
            phone_number: phoneNumber
          };

          var conversionData = self.buildLeadConversionData(leadData);
          self.sendConversionToWorker('google_ads_phone_call', conversionData);
        });
      }

      // Track email clicks as conversions (if configured)
      if (self.config.trackEmailClicks) {
        $(document).on('click', 'a[href^="mailto:"]', function() {
          var emailAddress = $(this).attr('href').replace('mailto:', '');
          
          var leadData = {
            transaction_id: 'email_' + Date.now(),
            value: self.config.emailClickValue || 0,
            currency: self.config.currency || 'EUR',
            conversion_action: 'email_click',
            email_clicked: emailAddress
          };

          var conversionData = self.buildLeadConversionData(leadData);
          self.sendConversionToWorker('google_ads_email_click', conversionData);
        });
      }
    },

    // Helper function to get user information
    getUserInfo: function () {
      var userInfo = {
        email: '',
        phone: '',
        firstName: '',
        lastName: ''
      };

      // Try to get from logged-in user data if available
      if (this.config.userData) {
        userInfo.email = this.config.userData.email || '';
        userInfo.phone = this.config.userData.phone || '';
        userInfo.firstName = this.config.userData.first_name || '';
        userInfo.lastName = this.config.userData.last_name || '';
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
    },

    // Get session information (reuse GA4 session if available)
    getSessionInfo: function () {
      var sessionInfo = {
        client_id: '',
        session_id: ''
      };

      // Try to get from GA4 tracking if available
      if (window.ga4ServerSideTagging && typeof window.ga4ServerSideTagging.getClientId === 'function') {
        sessionInfo.client_id = window.ga4ServerSideTagging.getClientId();
      } else {
        // Generate our own client ID
        sessionInfo.client_id = this.getClientId();
      }

      // Try to get session ID from localStorage
      sessionInfo.session_id = localStorage.getItem('server_side_ga4_session_id') || this.generateUniqueId();

      return sessionInfo;
    },

    // Get client ID (reuse from GA4 tracking or generate)
    getClientId: function () {
      // Try to get from localStorage if available
      if (window.localStorage) {
        var storedClientId = localStorage.getItem('server_side_ga4_client_id');
        if (storedClientId) {
          return storedClientId;
        }
      }
      return this.generateClientId();
    },

    // Generate a random client ID and store it
    generateClientId: function () {
      var clientId = Math.round(2147483647 * Math.random()) + '.' + Math.round(Date.now() / 1000);

      // Store in localStorage if available
      if (window.localStorage) {
        localStorage.setItem('server_side_ga4_client_id', clientId);
      }

      return clientId;
    },

    // Check if we're on an order confirmation page
    isOrderConfirmationPage: function () {
      return this.config.isThankYouPage === true ||
             window.location.href.indexOf('/checkout/order-received/') > -1 ||
             window.location.href.indexOf('/inschrijven/order-received/') > -1 ||
             window.location.href.indexOf('/order-pay/') > -1 ||
             window.location.href.indexOf('/thank-you/') > -1;
    },

    // Get UTM parameters
    getUtmSource: function () {
      return this.getParameterByName('utm_source');
    },

    getUtmMedium: function () {
      return this.getParameterByName('utm_medium');
    },

    getUtmCampaign: function () {
      return this.getParameterByName('utm_campaign');
    },

    getUtmContent: function () {
      return this.getParameterByName('utm_content');
    },

    getUtmTerm: function () {
      return this.getParameterByName('utm_term');
    },

    getGclid: function () {
      return this.getParameterByName('gclid');
    },

    // Helper function to get URL parameters
    getParameterByName: function (name, url) {
      if (!url) url = window.location.href;
      name = name.replace(/[\[\]]/g, '\\$&');
      var regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)'),
          results = regex.exec(url);
      if (!results) return null;
      if (!results[2]) return '';
      return decodeURIComponent(results[2].replace(/\+/g, ' '));
    },

    // Generate unique ID
    generateUniqueId: function () {
      return Date.now().toString() + '_' + Math.random().toString(36).substr(2, 9);
    },

    // Log messages if debug mode is enabled
    log: function (message, data) {
      if (this.config.debugMode && window.console) {
        if (data) {
          console.log('[Google Ads Tracking] ' + message, data);
        } else {
          console.log('[Google Ads Tracking] ' + message);
        }
      }
    }
  };

  // Initialize when document is ready
  $(document).ready(function () {
    GoogleAdsTracking.init();
  });

})(jQuery);