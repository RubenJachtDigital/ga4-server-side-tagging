/**
 * Public JavaScript for GA4 Server-Side Tagging
 *
 * @since      1.0.0
 */

(function ($) {
  "use strict";

  // GA4 Server-Side Tagging Client
  var GA4ServerSideTagging = {
    // Configuration
    config: window.ga4ServerSideTagging || {},

    init: function () {
      // Check if we have the required configuration
      if (!this.config.measurementId) {
        this.log("Measurement ID not configured");
        return;
      }

      if (this.config.useServerSide == true) {
        this.trackPageView();
      }

      // Set up event listeners
      this.setupEventListeners();

      // Log initialization
      this.log("GA4 Server-Side Tagging initialized v4");
    },

    // Main page view tracking function
    trackPageView: function () {
      // Get current session information
      var session = this.getSession();
      var isNewSession = session.isNew; // Track if this is a new session

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

      // Get UTM parameters from URL
      var utmSource = this.getUtmSource();
      var utmMedium = this.getUtmMedium();
      var utmCampaign = this.getUtmCampaign();
      var utmContent = this.getUtmContent();
      var utmTerm = this.getUtmTerm();

      // Get Google Click ID (gclid) from URL
      var gclid = this.getGclid();

      // Determine source and medium according to GA4 rules
      var source = utmSource || "";
      var medium = utmMedium || "";
      var campaign = utmCampaign || "(not set)";
      var content = utmContent || "";
      var term = utmTerm || "";

      // If no UTM parameters but we have a referrer, determine source/medium
      if (!source && !medium && referrerDomain && !ignore_referrer) {
        // Handle search engines - this is critical for organic search attribution
        if (referrerDomain.indexOf("google") > -1) {
          // Check if it's Google Ads or organic
          if (referrer.indexOf("gclid=") > -1 || gclid) {
            source = "google";
            medium = "cpc";
          } else {
            // Google organic search
            source = "google";
            medium = "organic";
          }
        } else if (referrerDomain.indexOf("bing") > -1) {
          source = "bing";
          medium = "organic";
        } else if (referrerDomain.indexOf("yahoo") > -1) {
          source = "yahoo";
          medium = "organic";
        } else if (
          referrerDomain.indexOf("facebook.com") > -1 ||
          referrerDomain.indexOf("instagram.com") > -1
        ) {
          // Social referrals
          source = referrerDomain.replace("www.", "").split(".")[0];
          medium = "social";
        } else if (
          referrerDomain !== window.location.hostname &&
          referrerDomain !== ""
        ) {
          // Regular referral - ensure it's not from the same domain
          source = referrerDomain;
          medium = "referral";
        }
      }

      // If we have a gclid but no UTM source/medium was set, override with Google Ads attribution
      if (gclid && !utmSource && !utmMedium) {
        source = "google";
        medium = "cpc";
      }

      // No UTM and no referrer (or ignored referrer) means direct traffic
      if (!source && !medium) {
        // For direct traffic, check if we should use last non-direct attribution
        if (!isNewSession) {
          // For subsequent hits in an existing session, try to use stored attribution
          source =
            localStorage.getItem("server_side_ga4_last_source") || "(direct)";
          medium =
            localStorage.getItem("server_side_ga4_last_medium") || "none";
          campaign =
            localStorage.getItem("server_side_ga4_last_campaign") ||
            "(not set)";
          content = localStorage.getItem("server_side_ga4_last_content") || "";
          term = localStorage.getItem("server_side_ga4_last_term") || "";
          gclid = localStorage.getItem("server_side_ga4_last_gclid") || "";
        } else {
          source = "(direct)";
          medium = "none";
        }
      }

      // Store attribution data when it's available (for new sessions or when UTM params are present)
      if (
        (isNewSession || utmSource || utmMedium || gclid) &&
        source &&
        medium
      ) {
        localStorage.setItem("server_side_ga4_last_source", source);
        localStorage.setItem("server_side_ga4_last_medium", medium);
        if (campaign)
          localStorage.setItem("server_side_ga4_last_campaign", campaign);
        if (content)
          localStorage.setItem("server_side_ga4_last_content", content);
        if (term) localStorage.setItem("server_side_ga4_last_term", term);
        if (gclid) localStorage.setItem("server_side_ga4_last_gclid", gclid);
      }

      // Common session parameters needed for all page view events
      var sessionParams = {
        // Session identification
        session_id: session.id,

        // Critical for GA4 real-time reporting
        engagement_time_msec: 1000,

        // Session state flags
        session_engaged: true,
        engaged_session_event: true,

        // First visit indicators for new users
        first_visit: session.isFirstVisit ? 1 : 0,
        new_visitor: session.sessionCount === 1 ? 1 : 0,

        // Client information
        client_id: this.getClientId(),

        // Device information
        language: navigator.language || "",
        screen_resolution: window.screen
          ? window.screen.width + "x" + window.screen.height
          : "",

        // Traffic attribution parameters
        source: source,
        medium: medium,
        campaign: campaign,

        // Add content and term parameters if available
        ...(content && { content: content }),
        ...(term && { term: term }),

        // Add gclid parameter if available
        ...(gclid && { gclid: gclid }),

        // Flag for internal navigation
        ignore_referrer: ignore_referrer,

        // Page information
        page_title: document.title,
        page_location: this.getPageLocationWithoutParams(window.location.href),
        page_path: this.getPageLocationWithoutParams(window.location.pathname),
        page_referrer: referrer,

        // Event timestamp
        event_timestamp: Math.floor(Date.now() / 1000),
      };

      // Send session_start event if this is a new session
      if (isNewSession) {
        this.log("Sending session_start event");
        this.trackEvent("session_start", sessionParams);
      }

      this.log("Page view params:", sessionParams);
      this.log("Is new session: " + isNewSession);
      this.log("Is order received page: " + this.isOrderConfirmationPage());

      // Track appropriate event based on page type
      if (this.isProductListPage()) {
        // Product list page - track view_item_list instead of page_view
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
      } else if (this.isProductPage()) {
        // Single product page - track view_item
        var productData = this.config.productData;

        var viewItemData = {
          ...sessionParams,
          currency: this.config.currency || "EUR",
          value: productData.price,
          items: [productData],
        };

        this.trackEvent("view_item", viewItemData);
      } else {
        // Regular page - track page_view
        this.trackEvent("page_view", sessionParams);
      }
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

      // Track form submissions (excluding WooCommerce forms)
      $(document).on(
        "submit",
        "form:not(.cart, .woocommerce-cart-form, .checkout, .woocommerce-checkout, #gform_3)",
        function () {
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
        }
      );

      // Track WooCommerce specific events if enabled
      if (this.config.isEcommerceEnabled) {
        this.setupEcommerceTracking();
      }
    },

    // Set up e-commerce tracking
    setupEcommerceTracking: function () {
      var self = this;

      // Track add to cart button clicks
      $(document).on("click", ".add_to_cart_button", function () {
        var productId = $(this).data("product_id");
        var productSku = $(this).data("product_sku");
        var productName = $(this)
          .closest(".product")
          .find(".woocommerce-loop-product__title")
          .text();
        var productPrice = $(this).data("product_price") || 0;

        self.trackEvent("add_to_cart", {
          item_id: productId,
          item_name: productName,
          item_sku: productSku,
          price: productPrice,
          quantity: 1,
        });
      });

      // Track AJAX add to cart events
      $(document.body).on(
        "added_to_cart",
        function (event, fragments, cart_hash, $button) {
          if ($button) {
            var productId = $button.data("product_id");
            var productSku = $button.data("product_sku");
            var productName = $button
              .closest(".product")
              .find(".woocommerce-loop-product__title")
              .text();
            var productPrice = $button.data("product_price") || 0;

            self.trackEvent("add_to_cart", {
              item_id: productId,
              item_name: productName,
              item_sku: productSku,
              price: productPrice,
              quantity: 1,
            });
          }
        }
      );

      $(document).on(
        "click",
        '.single_add_to_cart_button.buy-now, input[name="wc-buy-now"], .direct-inschrijven, .add-request-quote-button',
        function () {
          var $button = $(this);
          var productId = $button.data("ga4-product-id");
          var productName = $button.data("ga4-product-name");
          var productPrice = $button.data("ga4-product-price") || 0;
          var quantity = parseInt($("input.qty").val()) || 1;
          var productData = self.config.productData;

          // Check if productId is empty or not found
          if (!productId) {
            // Get item_name from h1.bde-heading
            productName = document.title;

            // Get item_id from body class (postid-XXXXX)
            var bodyClasses = $("body").attr("class").split(" ");
            for (var i = 0; i < bodyClasses.length; i++) {
              if (bodyClasses[i].startsWith("postid-")) {
                productId = bodyClasses[i].replace("postid-", "");
                break;
              }
            }

            // Set price to 0
            productPrice = productData.price;
          }

          self.trackEvent("add_to_cart", {
            item_id: productId,
            item_name: productName,
            price: parseFloat(productPrice),
            items: [productData],
            quantity: quantity,
            value: parseFloat(productPrice),
          });

          self.log("Tracked Buy Now button click as add_to_cart", {
            item_id: productId,
            item_name: productName,
            price: parseFloat(productPrice),
            items: [productData],
            value: parseFloat(productPrice),
            quantity: quantity,
          });
        }
      );

      // Track single product add to cart form submissions
      $(document).on("submit", "form.cart", function (e) {
        // Don't track if it's already tracked by other handlers
        if ($(this).find(".add_to_cart_button").length) {
          return;
        }

        var $form = $(this);
        var $button = $form.find(".single_add_to_cart_button");

        // Skip if it's a Buy Now button (already tracked)
        if (
          $button.hasClass("buy-now") ||
          $form.find('input[name="wc-buy-now"]').length
        ) {
          return;
        }

        // Get product data from the page
        var productId =
          $form.find('input[name="add-to-cart"]').val() ||
          $form.find('button[name="add-to-cart"]').val();
        var productName = $(".product_title").text();
        var productPrice = $(".woocommerce-Price-amount")
          .first()
          .text()
          .replace(/[^0-9,.]/g, "");
        var quantity = parseInt($form.find("input.qty").val()) || 1;

        self.trackEvent("add_to_cart", {
          item_id: productId,
          item_name: productName,
          price: parseFloat(productPrice),
          quantity: quantity,
        });

        self.log("Tracked form submission as add_to_cart", {
          item_id: productId,
          item_name: productName,
          price: parseFloat(productPrice),
          quantity: quantity,
        });
      });

      // Track product detail views
      if ($(".woocommerce-product-gallery").length) {
        var productId =
          $('input[name="product_id"]').val() ||
          $('input[name="add-to-cart"]').val();
        var productName = $(".product_title").text();
        var productPrice = $(".woocommerce-Price-amount")
          .first()
          .text()
          .replace(/[^0-9,.]/g, "");

        if (productId && productName) {
          if (self.config.useServerSide != true) {
            self.trackEvent("view_item", {
              item_id: productId,
              item_name: productName,
              price: parseFloat(productPrice),
            });
          }
        }
      }

      // Track remove from cart events
      $(document).on("click", ".woocommerce-cart-form .remove", function () {
        var $row = $(this).closest("tr");
        var productName = $row.find(".product-name").text().trim();
        var productId = $(this).data("product_id") || "";
        var price = $row
          .find(".product-price .amount")
          .text()
          .replace(/[^0-9,.]/g, "");
        var quantity =
          parseInt($row.find(".product-quantity input.qty").val()) || 1;

        self.trackEvent("remove_from_cart", {
          item_id: productId,
          item_name: productName,
          price: parseFloat(price),
          quantity: quantity,
        });

        self.log("Tracked remove_from_cart event", {
          item_id: productId,
          item_name: productName,
          price: parseFloat(price),
          quantity: quantity,
        });
      });

      // Track checkout steps
      if ($(".woocommerce-checkout").length) {
        // Track begin_checkout event
        self.trackEvent("begin_checkout", {
          // Event data will be populated by the server-side code
        });

        // Track shipping info
        $("form.checkout").on(
          "change",
          '#shipping_method input[type="radio"], #shipping_method input[type="hidden"]',
          function () {
            var shippingMethod = $(this).val();

            self.trackEvent("add_shipping_info", {
              shipping_tier: shippingMethod,
            });

            self.log("Tracked add_shipping_info event", {
              shipping_tier: shippingMethod,
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
              payment_type: paymentMethod,
            });

            self.log("Tracked add_payment_info event", {
              payment_type: paymentMethod,
            });
          }
        );
      }

      // Track purchase event on order received page
      if (self.isOrderConfirmationPage()) {
        // Check if we have order data from the server
        if (
          typeof self.config.orderData !== "undefined" &&
          self.config.orderData
        ) {
          // Add attribution data to order data
          var orderData = self.config.orderData;

          self.log(
            "Order data found, tracking purchase event with attribution",
            orderData
          );
          self.trackEvent("purchase", orderData);
        } else {
          // Try to extract order data from the page
          self.log("Attempting to extract order data from the page");

          var orderId = "";
          var orderTotal = 0;

          // Try to get order ID from URL
          var orderIdMatch = window.location.pathname.match(
            /order-received\/(\d+)/
          );
          if (orderIdMatch && orderIdMatch[1]) {
            orderId = orderIdMatch[1];
          }

          // If we have at least an order ID, send a minimal purchase event
          if (orderId) {
            self.trackEvent("purchase", {
              transaction_id: orderId,
              affiliation: self.config.siteName || "Website",
              value: orderTotal,
              currency: self.config.currency || "EUR",
            });

            self.log("Tracked minimal purchase event with attribution", {
              transaction_id: orderId,
              value: orderTotal,
            });
          } else {
            self.log("Could not extract order data from the page");
          }
        }
      }
      if ($("#gform_3")) {
        $("#gform_3").on("submit", function (event) {
          self.log("request a quote form fired");
          // Check if we have order data from the server
          if (
            typeof self.config.quoteData !== "undefined" &&
            self.config.quoteData
          ) {
            // Add attribution data to quote data
            var quoteData = self.config.quoteData;

            self.log(
              "Quote data found, tracking purchase event with attribution",
              quoteData
            );
            self.trackEvent("purchase", quoteData);
          } else {
            // Try to extract order data from the page
            self.log("Attempting to extract order data from the page");

            var orderId = "";
            var orderTotal = 0;

            // Try to get order ID from URL
            var orderIdMatch = window.location.pathname.match(
              /order-received\/(\d+)/
            );
            if (orderIdMatch && orderIdMatch[1]) {
              orderId = orderIdMatch[1];
            }

            // If we have at least an order ID, send a minimal purchase event
            if (orderId) {
              self.trackEvent("purchase", {
                transaction_id: orderId,
                value: orderTotal,
                currency: self.config.currency || "EUR",
              });

              self.log("Tracked minimal purchase event with attribution", {
                transaction_id: orderId,
                value: orderTotal,
              });
            } else {
              self.log("Could not extract order data from the page");
            }
          }
        });
      }
      // Track product list views
      if (
        $(".woocommerce .products").length &&
        !$(".woocommerce-product-gallery").length
      ) {
        var listName = "Product List";
        var listId = "";

        // Try to determine the list type
        if ($(".woocommerce-products-header__title").length) {
          listName = $(".woocommerce-products-header__title").text().trim();
        } else if ($(".page-title").length) {
          listName = $(".page-title").text().trim();
        }

        // Get products in the list
        var items = [];
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

        if (items.length > 0) {
          if (self.config.useServerSide != true) {
            self.trackEvent("view_item_list", {
              item_list_name: listName,
              item_list_id: listId,
              items: items,
            });

            // Track product clicks for select_item events
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
                self.trackEvent("select_item", {
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
                });

                self.log("Tracked select_item event", {
                  item_id: productId,
                  item_name: productName,
                  index: index,
                  item_list_name: listName,
                });
              }
            });
          }
        }
      }
    },
    // Add this helper function to your JavaScript
    getPageLocationWithoutParams: function (url) {
      try {
        // Parse the URL
        const parsedUrl = new URL(url);

        // Get the base URL without parameters (protocol + hostname + pathname)
        let cleanUrl =
          parsedUrl.protocol + "//" + parsedUrl.hostname + parsedUrl.pathname;

        // Trim to 100 characters if needed
        if (cleanUrl.length > 100) {
          cleanUrl = cleanUrl.substring(0, 100);
        }

        return cleanUrl;
      } catch (e) {
        // In case of invalid URL, return a trimmed version of the original
        return url.split("?")[0].substring(0, 100);
      }
    },

    // Get UTM parameters from URL
    getUtmSource: function () {
      return this.getParameterByName("utm_source");
    },

    getUtmMedium: function () {
      return this.getParameterByName("utm_medium");
    },

    getUtmCampaign: function () {
      return this.getParameterByName("utm_campaign");
    },

    getUtmContent: function () {
      return this.getParameterByName("utm_content");
    },

    getUtmTerm: function () {
      return this.getParameterByName("utm_term");
    },
    getGclid: function () {
      return this.getParameterByName("gclid");
    },

    // Helper function to get URL parameters (if you don't already have this)
    getParameterByName: function (name, url) {
      if (!url) url = window.location.href;
      name = name.replace(/[\[\]]/g, "\\$&");
      var regex = new RegExp("[?&]" + name + "(=([^&#]*)|&|#|$)"),
        results = regex.exec(url);
      if (!results) return null;
      if (!results[2]) return "";
      return decodeURIComponent(results[2].replace(/\+/g, " "));
    },

    // Track an event
    trackEvent: function (eventName, eventParams = {}) {
      // Log the event
      this.log("Tracking event: " + eventName, eventParams);
      var session = this.getSession();

      // Add session_id to event params if not already present
      if (!eventParams.hasOwnProperty("session_id")) {
        eventParams.session_id = session.id;
      }

      // Add debug_mode to event params if not already present and ensure it's a boolean
      if (!eventParams.hasOwnProperty("debug_mode")) {
        if (Boolean(this.config.debugMode) === true) {
          eventParams.debug_mode = Boolean(this.config.debugMode);
        }
      }

      if (!eventParams.hasOwnProperty("event_timestamp")) {
        // Add timestamp to event params
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
    sendServerSideEvent: function (eventName, eventParams) {
      var self = this;

      // Create a copy of the event params to avoid modifying the original
      var params = JSON.parse(JSON.stringify(eventParams));

      // Get client ID from cookie if available
      var clientId = this.getClientId();
      if (clientId) {
        params.client_id = clientId;
        this.log("Using client ID from cookie: " + clientId);
      } else if (typeof ga === "function" && ga.getAll && ga.getAll()[0]) {
        params.client_id = ga.getAll()[0].get("clientId");
        this.log("Using client ID from GA: " + params.client_id);
      } else {
        // Generate a random client ID if none exists
        params.client_id = this.generateClientId();
        this.log("Generated random client ID: " + params.client_id);
      }

      // Determine endpoint
      var endpoint = this.config.cloudflareWorkerUrl || this.config.apiEndpoint;

      if (!endpoint) {
        this.log("No server-side endpoint configured");
        return;
      }

      this.log("Sending to endpoint: " + endpoint);

      // Format data based on endpoint type
      var data;
      if (endpoint === this.config.cloudflareWorkerUrl) {
        // Direct format for Cloudflare Worker
        data = {
          name: eventName,
          params: params,
        };
        this.log("Using Cloudflare Worker format", data);
      } else {
        // Legacy format for WordPress REST API
        data = {
          event_name: eventName,
          event_data: params,
        };
        this.log("Using WordPress REST API format", data);
      }

      this.sendAjaxPayload(endpoint, data);
    },
    sendAjaxPayload: function (endpoint, payload) {
      var self = this;

      // Send the request
      $.ajax({
        url: endpoint,
        type: "POST",
        data: JSON.stringify(payload),
        contentType: "application/json",
        beforeSend: function (xhr) {
          // Add nonce for WordPress REST API
          if (endpoint === self.config.apiEndpoint) {
            xhr.setRequestHeader("X-WP-Nonce", self.config.nonce);
          }
          self.log("Sending AJAX request to: " + endpoint);
        },
        success: function (response) {
          self.log("Server-side event sent successfully", response);
        },
        error: function (xhr, status, error) {
          self.log("Error sending server-side event: " + error, xhr);
          console.error("[GA4 Server-Side Tagging] Error sending event:", {
            status: status,
            error: error,
            response: xhr.responseText,
          });
        },
      });
    },
    // Option 2: Add more detailed logging within the function
    isOrderConfirmationPage: function () {
      // Check config value
      if (this.config && this.config.isThankYouPage === true) {
        this.log("Order page detected via config setting");
        return true;
      }

      // Check URL patterns
      const isUrlMatch =
        window.location.href.indexOf("/checkout/order-received/") > -1 ||
        window.location.href.indexOf("/inschrijven/order-received/") > -1 ||
        window.location.href.indexOf("/order-pay/") > -1 ||
        window.location.href.indexOf("/thank-you/") > -1;
      if (isUrlMatch) {
        this.log("Order page detected via URL pattern");
        return true;
      }

      // Check for WooCommerce body class
      const hasWooClass =
        document.body.classList.contains("woocommerce-order-received") ||
        (document.body.classList.contains("woocommerce-checkout") &&
          document.querySelector(".woocommerce-order-overview") !== null);
      if (hasWooClass) {
        this.log("Order page detected via WooCommerce classes");
        return true;
      }

      // Check for order ID in URL parameters
      const urlParams = new URLSearchParams(window.location.search);
      const hasOrderParam = urlParams.has("order") || urlParams.has("order_id");
      if (hasOrderParam) {
        this.log("Order page detected via URL parameters");
        return true;
      }

      // Check for thank you page elements
      const hasThankYouElements =
        document.querySelector(".woocommerce-thankyou-order-details") !==
          null ||
        document.querySelector(".woocommerce-order-received") !== null ||
        document.querySelector(".woocommerce-notice--success") !== null;
      if (hasThankYouElements) {
        this.log("Order page detected via thank you page elements");
        return true;
      }

      this.log("Not an order page");
      return false;
    },
    getSession: function () {
      var sessionId = localStorage.getItem("server_side_ga4_session_id");
      var sessionStart = localStorage.getItem("server_side_ga4_session_start");
      var firstVisit = localStorage.getItem("server_side_ga4_first_visit");
      var sessionCount = parseInt(
        localStorage.getItem("server_side_ga4_session_count") || "0"
      );
      var now = Date.now();
      var isNew = false;
      var isFirstVisit = false;

      // Check if this is the first visit ever
      if (!firstVisit) {
        localStorage.setItem("server_side_ga4_first_visit", now);
        isFirstVisit = true;
      }

      // If no session or session expired (30 min inactive)
      if (
        !sessionId ||
        !sessionStart ||
        now - parseInt(sessionStart) > 30 * 60 * 1000
      ) {
        // Generate a more robust session ID using timestamp and random values
        sessionId = this.generateUniqueId();
        sessionStart = now;

        // Store session data
        localStorage.setItem("server_side_ga4_session_id", sessionId);
        localStorage.setItem("server_side_ga4_session_start", sessionStart);

        // Increment session count
        sessionCount++;
        localStorage.setItem("server_side_ga4_session_count", sessionCount);

        isNew = true;
      } else {
        // Update session timestamp on activity
        localStorage.setItem("server_side_ga4_session_start", now);
      }

      return {
        id: sessionId,
        start: parseInt(sessionStart),
        isNew: isNew,
        isFirstVisit: isFirstVisit,
        sessionCount: sessionCount,
        duration: now - parseInt(sessionStart),
      };
    },

    // Helper function to generate a unique ID
    generateUniqueId: function () {
      return Date.now().toString();
    },

    // Check if we're on a product list page
    isProductListPage: function () {
      return (
        $(".woocommerce .products").length &&
        !$(".woocommerce-product-gallery").length
      );
    },

    // Check if we're on a single product page
    isProductPage: function () {
      return this.config.productData ? true : false;
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

          this.trackEvent("select_item", selectItemData);

          this.log("Tracked select_item event", {
            item_id: productId,
            item_name: productName,
            index: index,
            item_list_name: listName,
          });
        }
      });
    },

    // Get client ID from cookie
    getClientId: function () {
      // Try to get client ID from _ga cookie
      // var gaCookie = this.getCookie("_ga");
      // if (gaCookie) {
      //   var parts = gaCookie.split(".");
      //   if (parts.length >= 4) {
      //     return parts[2] + "." + parts[3];
      //   }
      // }

      // Try to get from localStorage if available
      if (window.localStorage) {
        var storedClientId = localStorage.getItem("server_side_ga4_client_id");
        if (storedClientId) {
          return storedClientId;
        }
      }
      return this.generateClientId();
    },

    // Generate a random client ID and store it
    generateClientId: function () {
      var clientId =
        Math.round(2147483647 * Math.random()) +
        "." +
        Math.round(Date.now() / 1000);

      // Store in localStorage if available
      if (window.localStorage) {
        localStorage.setItem("server_side_ga4_client_id", clientId);
      }

      return clientId;
    },

    // Get cookie value by name
    getCookie: function (name) {
      var match = document.cookie.match(
        new RegExp("(^|;\\s*)(" + name + ")=([^;]*)")
      );
      return match ? match[3] : null;
    },

    // Log messages if debug mode is enabled
    log: function (message, data) {
      if (this.config.debugMode && window.console) {
        if (data) {
          console.log("[GA4 Server-Side Tagging] " + message, data);
        } else {
          console.log("[GA4 Server-Side Tagging] " + message);
        }
      }
    },
  };

  // Initialize when document is ready
  $(document).ready(function () {
    GA4ServerSideTagging.init();
  });
})(jQuery);
