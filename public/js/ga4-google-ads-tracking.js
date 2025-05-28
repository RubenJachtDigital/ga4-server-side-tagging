/**
 * Google Ads Conversion Tracking via Existing GA4 Cloudflare Worker
 * Refactored to use GA4Utils for cleaner code and consistent AJAX handling
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

      // Always use the existing GA4 Cloudflare Worker URL
      if (window.ga4ServerSideTagging && window.ga4ServerSideTagging.cloudflareWorkerUrl) {
        this.config.cloudflareWorkerUrl = window.ga4ServerSideTagging.cloudflareWorkerUrl;
        this.log("Using existing GA4 Cloudflare Worker URL: " + this.config.cloudflareWorkerUrl);
      } else {
        this.log("No GA4 Cloudflare Worker URL available");
        return;
      }

      // Set up conversion tracking
      this.setupConversionTracking();

      // Log initialization
      this.log("Google Ads Conversion Tracking initialized via existing Cloudflare Worker");
    },

    // Set up conversion tracking based on page type and events
    setupConversionTracking: function () {
      // Track purchase conversions on order confirmation page
      if (GA4Utils.page.isOrderConfirmationPage(this.config, 'google_ads')) {
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

    // Build purchase conversion data (using utilities)
    buildPurchaseConversionData: function (orderData) {
      var userInfo = GA4Utils.user.getInfo(this.config);
      var sessionInfo = GA4Utils.session.getInfo();
      var utmParams = GA4Utils.utm.getAll();
      var gclid = GA4Utils.gclid.get();
      
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
        
        // Attribution Data (using utilities)
        gclid: gclid || '',
        utm_source: utmParams.utm_source,
        utm_medium: utmParams.utm_medium,
        utm_campaign: utmParams.utm_campaign,
        utm_content: utmParams.utm_content,
        utm_term: utmParams.utm_term,
        
        // Session and tracking data (using utilities)
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

    // Build lead conversion data (using utilities)
    buildLeadConversionData: function (quoteData) {
      var userInfo = GA4Utils.user.getInfo(this.config);
      var sessionInfo = GA4Utils.session.getInfo();
      var utmParams = GA4Utils.utm.getAll();
      var gclid = GA4Utils.gclid.get();
      
      return {
        // Event identification
        conversion_type: 'lead',
        conversion_id: this.config.conversionId,
        conversion_label: this.config.leadConversionLabel || '',
        
        // Lead Information
        lead_id: quoteData.transaction_id || GA4Utils.helpers.generateUniqueId(),
        value: parseFloat(quoteData.value) || parseFloat(this.config.defaultLeadValue) || 0,
        currency: quoteData.currency || 'EUR',
        
        // Enhanced Conversions Data
        email: userInfo.email || '',
        phone: userInfo.phone || '',
        first_name: userInfo.firstName || '',
        last_name: userInfo.lastName || '',
        
        // Attribution Data (using utilities)
        gclid: gclid || '',
        utm_source: utmParams.utm_source,
        utm_medium: utmParams.utm_medium,
        utm_campaign: utmParams.utm_campaign,
        utm_content: utmParams.utm_content,
        utm_term: utmParams.utm_term,
        
        // Session and tracking data (using utilities)
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

    // Send conversion data to Cloudflare Worker using GA4Utils AJAX
    sendConversionToWorker: function (eventName, conversionData) {
      var self = this;
      
      // Format the payload like a GA4 event for the existing worker
      var payload = {
        name: eventName,
        params: conversionData
      };

      this.log("Sending Google Ads conversion to Cloudflare Worker", payload);

      // Use GA4Utils AJAX functionality for consistent handling
      GA4Utils.ajax.sendPayloadFetch(this.config.cloudflareWorkerUrl, payload, this.config, "[Google Ads Tracking]")
        .then(function(data) {
          self.log("Google Ads conversion sent successfully", data);
        })
        .catch(function(error) {
          self.log("Error sending Google Ads conversion", error);
        });
    },

    // Set up event listeners for other conversion types
    setupEventListeners: function () {
      var self = this;

      // Setup individual tracking methods
      this.setupFormConversionTracking();
      this.setupQuoteFormTracking();
      this.setupPhoneCallTracking();
      this.setupEmailClickTracking();
    },

    /**
     * Setup form submission tracking as lead conversions
     */
    setupFormConversionTracking: function () {
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
    },

    /**
     * Setup specific quote form tracking
     */
    setupQuoteFormTracking: function () {
      var self = this;

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
    },

    /**
     * Setup phone call tracking
     */
    setupPhoneCallTracking: function () {
      var self = this;

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
    },

    /**
     * Setup email click tracking
     */
    setupEmailClickTracking: function () {
      var self = this;

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

    /**
     * Log messages using GA4Utils
     */
    log: function (message, data) {
      GA4Utils.helpers.log(message, data, this.config, '[Google Ads Tracking]');
    }
  };

  // Initialize when document is ready
  $(document).ready(function () {
    GoogleAdsTracking.init();
  });

})(jQuery);