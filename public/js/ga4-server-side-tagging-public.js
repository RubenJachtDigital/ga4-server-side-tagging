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

    init: function () {
      // Check if we have the required configuration
      if (!this.config.measurementId) {
        this.log("Measurement ID not configured");
        return;
      }
      if (this.config.ga4TrackLoggedInUsers != true) {
        this.log("Not tracking logged in users");
        return;
      }

      if (this.config.useServerSide == true) {
        this.trackPageView();
      }

      // Set up event listeners
      this.setupEventListeners();

      // Log initialization
      this.log("GA4 Server-Side Tagging initialized v7");
    },

    trackPageView: function () {
      // Get current session information using utils
      var session = GA4Utils.session.get();
      this.log("session data: " + session.start);
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

      // Common session parameters needed for all page view events
      var sessionParams = this.buildSessionParams(
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

      this.completePageViewTracking(sessionParams, isNewSession);
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
        var fallbackAttribution = this.handleNoAttribution(isNewSession);
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
      } else if (referrerDomain.indexOf("bing") > -1) {
        return { source: "bing", medium: "organic", campaign: "(organic)" };
      } else if (referrerDomain.indexOf("yahoo") > -1) {
        return { source: "yahoo", medium: "organic", campaign: "(organic)" };
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

      return { source: "", medium: "", campaign: "(not set)" };
    },

    /**
     * Handle cases where no attribution is determined (replaces handleDirectTraffic)
     */
    handleNoAttribution: function (isNewSession) {
      // Always try to get stored attribution first (for session continuity)
      var storedAttribution = this.getStoredAttribution();

      // If we have stored attribution and it's not a new session, use it
      if (
        !isNewSession &&
        storedAttribution.source &&
        storedAttribution.source !== "(direct)"
      ) {
        return storedAttribution;
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
          medium: "none",
          campaign: "(not set)",
          content: "",
          term: "",
          gclid: "",
        };
      }

      // Default to direct traffic
      return {
        source: "(direct)",
        medium: "none",
        campaign: "(not set)",
        content: "",
        term: "",
        gclid: "",
      };
    },

    /**
     * Get stored attribution from localStorage
     */
    getStoredAttribution: function () {
      return {
        source: localStorage.getItem("server_side_ga4_last_source") || "",
        medium: localStorage.getItem("server_side_ga4_last_medium") || "",
        campaign:
          localStorage.getItem("server_side_ga4_last_campaign") || "(not set)",
        content: localStorage.getItem("server_side_ga4_last_content") || "",
        term: localStorage.getItem("server_side_ga4_last_term") || "",
        gclid: localStorage.getItem("server_side_ga4_last_gclid") || "",
      };
    },

    /**
     * Store attribution data in localStorage
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
        localStorage.setItem("server_side_ga4_last_source", attribution.source);
        localStorage.setItem("server_side_ga4_last_medium", attribution.medium);
        if (attribution.campaign)
          localStorage.setItem(
            "server_side_ga4_last_campaign",
            attribution.campaign
          );
        if (attribution.content)
          localStorage.setItem(
            "server_side_ga4_last_content",
            attribution.content
          );
        if (attribution.term)
          localStorage.setItem("server_side_ga4_last_term", attribution.term);
        if (attribution.gclid)
          localStorage.setItem("server_side_ga4_last_gclid", attribution.gclid);
      }
    },

    /**
     * Build session parameters for tracking
     */
    buildSessionParams: function (
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
        page_location: GA4Utils.url.getLocationWithoutParams(
          window.location.href
        ),
        page_referrer: referrer,

        // Shortened user agent
        user_agent: userAgentInfo.user_agent,

        // Timestamp
        event_timestamp: Math.floor(Date.now() / 1000),
      };

      return sessionParams;
    },

    // Method to complete page view tracking after location attempt
    completePageViewTracking: function (sessionParams, isNewSession) {
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
      var trackForms =
        "form:not(.cart, .woocommerce-cart-form, .checkout, .woocommerce-checkout)";

      // Build exclusion selectors for conversion forms
      var conversionFormExclusions = "";
      if (
        typeof self.config.conversionFormIds !== "undefined" &&
        self.config.conversionFormIds
      ) {
        var conversionIds = self.config.conversionFormIds.split(",");
        conversionFormExclusions = conversionIds
          .map((id) => `#gform_${id.trim()}`)
          .join(", ");
      }

      // Add YITH RAQ form exclusion if it exists
      if (
        typeof self.config.quoteData !== "undefined" &&
        self.config.quoteData &&
        self.config.yithRaqFormId
      ) {
        if (conversionFormExclusions) {
          conversionFormExclusions += `, #gform_${self.config.yithRaqFormId}`;
        } else {
          conversionFormExclusions = `#gform_${self.config.yithRaqFormId}`;
        }
      }

      // Update trackForms selector with all exclusions
      if (conversionFormExclusions) {
        trackForms = `form:not(.cart, .woocommerce-cart-form, .checkout, .woocommerce-checkout, ${conversionFormExclusions})`;
      }

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

      $(document).on(
        "click",
        'a[href*=".pdf"], a[href*=".zip"], a[href*=".doc"], a[href*=".docx"], a[href*=".xls"], a[href*=".xlsx"], a[href*=".ppt"], a[href*=".pptx"]',
        function () {
          var href = $(this).attr("href");
          var fileName = href.split("/").pop().split("?")[0];
          var fileExtension = fileName.split(".").pop().toLowerCase();

          self.trackEvent("file_download", {
            file_name: fileName,
            file_extension: fileExtension,
            link_url: href,
          });
        }
      );
    },

    /**
     * Setup search tracking
     */
    setupSearchTracking: function () {
      var self = this;

      $(document).on(
        "submit",
        'form[role="search"], .search-form',
        function () {
          var searchQuery = $(this)
            .find(
              'input[type="search"], input[name*="search"], input[name="s"]'
            )
            .val();
          if (searchQuery && searchQuery.trim() !== "") {
            self.trackEvent("search", {
              search_term: searchQuery.trim(),
            });
          }
        }
      );
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

      $(document).on(
        "click",
        'a[href*="facebook.com"], a[href*="twitter.com"], a[href*="linkedin.com"], a[href*="instagram.com"], a[href*="youtube.com"], a[href*="tiktok.com"]',
        function () {
          var href = $(this).attr("href");
          var platform = GA4Utils.helpers.getSocialPlatform(href);

          self.trackEvent("social_click", {
            platform: platform,
            link_url: href,
          });
        }
      );
    },

    /**
     * Setup button tracking
     */
    setupButtonTracking: function () {
      var self = this;

      $(document).on(
        "click",
        'button, .btn, .button, input[type="submit"], input[type="button"]',
        function () {
          // Skip if it's a form submit (already tracked above)
          if (
            $(this).attr("type") === "submit" &&
            $(this).closest("form").length
          ) {
            return;
          }

          var buttonText =
            $(this).text() ||
            $(this).val() ||
            $(this).attr("aria-label") ||
            "Unknown";
          var buttonId = $(this).attr("id") || "";
          var buttonClass = $(this).attr("class") || "";

          self.trackEvent("button_click", {
            button_text: buttonText.trim(),
            button_id: buttonId,
            button_class: buttonClass,
          });
        }
      );
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

      var self = this;

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

      $(document).on(
        "click",
        '.single_add_to_cart_button.buy-now, .cart button[type="submit"], .cart button[name="add-to-cart"], input[name="wc-buy-now"], .direct-inschrijven, .add-request-quote-button',
        function () {
          var quantity = parseInt($("input.qty").val()) || 1;
          var productData = self.config.productData;

          if (productData && productData.item_id && productData.item_name) {
            var itemData = Object.assign({}, productData, {
              quantity: quantity,
            });

            self.trackEvent("add_to_cart", {
              currency: productData.currency,
              value: productData.price * quantity,
              items: [itemData],
            });
          } else {
            self.log("Not tracked 'add_to_cart' - missing product data");
          }
        }
      );
    },

    /**
     * Setup remove from cart tracking
     */
    setupRemoveFromCartTracking: function () {
      var self = this;

      $(document).on("click", ".woocommerce-cart-form .remove", function () {
        var $row = $(this).closest("tr");
        var $removeLink = $(this);

        // Get product data from the remove link and row
        var productId = $removeLink.data("product_id") || "";
        var cartItemKey = $removeLink.data("cart_item_key") || "";

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
      var self = this;

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
     * Get user location (IP-based only)
     */
    getUserLocation: function () {
      return new Promise((resolve, reject) => {
        // First check if we have cached location data
        const cachedLocation = localStorage.getItem("user_location_data");
        if (cachedLocation) {
          try {
            const locationData = JSON.parse(cachedLocation);
            const now = Date.now();
            const oneHour = 60 * 60 * 1000; // 1 hour in milliseconds

            // Check if cached data is still valid (1 hour expiry)
            if (
              locationData.timestamp &&
              now - locationData.timestamp < oneHour
            ) {
              resolve(locationData);
              return;
            } else {
              // Expired, remove it
              localStorage.removeItem("user_location_data");
              this.log("Cached location data expired, fetching fresh data");
            }
          } catch (e) {
            this.log("Error parsing cached location data");
            localStorage.removeItem("user_location_data");
          }
        }

        // Use IP-based geolocation
        this.getIPBasedLocation()
          .then((ipLocationData) => {
            // Add timestamp to the location data
            ipLocationData.timestamp = Date.now();
            localStorage.setItem(
              "user_location_data",
              JSON.stringify(ipLocationData)
            );
            resolve(ipLocationData);
          })
          .catch((err) => {
            this.log("IP location error:", err);
            resolve({});
          });
      });
    },

    /**
     * Get location based on IP address with fallback services
     */
    getIPBasedLocation: function () {
      return new Promise((resolve, reject) => {
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

    // Track an event
    trackEvent: function (eventName, eventParams = {}) {
      // Log the event
      this.log("Tracking event: " + eventName, eventParams);

      // Add debug_mode to event params if not already present
      if (!eventParams.hasOwnProperty("debug_mode")) {
        if (Boolean(this.config.debugMode) === true) {
          eventParams.debug_mode = Boolean(this.config.debugMode);
        }
      }

      if (!eventParams.hasOwnProperty("event_timestamp")) {
        eventParams.event_timestamp = Math.floor(Date.now() / 1000);
      }

      // Send server-side event if enabled
      if (this.config.useServerSide) {
        this.sendServerSideEvent(eventName, eventParams);
      } else if (typeof gtag === "function") {
        gtag("event", eventName, eventParams);
        this.log("Sent to gtag: " + eventName);
      }
    },

    // Send server-side event
    sendServerSideEvent: async function (eventName, eventParams) {
      // Create a copy of the event params
      var params = JSON.parse(JSON.stringify(eventParams));
      var session = GA4Utils.session.get();

      // Add user ID if available
      if (!params.hasOwnProperty("user_id") && this.config.user_id) {
        params.user_id = this.config.user_id;
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

      // Handle location data based on IP anonymization setting
      await this.addLocationData(params);

      // Get client ID
      var clientId = GA4Utils.clientId.get();
      if (clientId) {
        params.client_id = clientId;
        this.log("Using client ID: " + clientId);
      }

      // Determine endpoint
      var endpoint = this.config.cloudflareWorkerUrl || this.config.apiEndpoint;

      if (!endpoint) {
        this.log("No server-side endpoint configured");
        return;
      }

      this.log("Sending to endpoint: " + endpoint);

      // Format data based on endpoint type
      var data = this.formatEventData(eventName, params, endpoint);
      this.sendAjaxPayload(endpoint, data);
    },

    /**
     * Add location data to event parameters
     */
    addLocationData: async function (params) {
      // Check if IP anonymization is enabled
      if (this.config.anonymizeIp == true) {
        this.log(
          "IP anonymization is enabled - skipping IP-based location tracking"
        );

        // Use timezone-based general region information instead
        try {
          const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone;
          if (timezone) {
            const timezoneRegions = timezone.split("/");
            if (timezoneRegions.length > 0) {
              params.continent = timezoneRegions[0] || "";
            }
          }
        } catch (e) {
          this.log("Error getting timezone info:", e);
        }
      } else {
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
      }
    },

    /**
     * Format event data based on endpoint type
     */
    formatEventData: function (eventName, params, endpoint) {
      if (endpoint === this.config.cloudflareWorkerUrl) {
        // Direct format for Cloudflare Worker
        var data = {
          name: eventName,
          params: params,
        };
        this.log("Using Cloudflare Worker format", data);
        return data;
      } else {
        // Legacy format for WordPress REST API
        var data = {
          event_name: eventName,
          event_data: params,
        };
        this.log("Using WordPress REST API format", data);
        return data;
      }
    },

    /**
     * Send AJAX payload to endpoint
     */
    sendAjaxPayload: function (endpoint, payload) {
      GA4Utils.ajax.sendPayload(
        endpoint,
        payload,
        this.config,
        "[GA4 Server-Side Tagging]"
      );
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

  // Initialize when document is ready
  $(document).ready(function () {
    GA4ServerSideTagging.init();
  });
})(jQuery);
