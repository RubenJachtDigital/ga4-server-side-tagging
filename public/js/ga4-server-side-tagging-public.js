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

    // Initialize
    init: function () {
      // Check if we have the required configuration
      if (!this.config.measurementId) {
        this.log("Measurement ID not configured");
        return;
      }
      // Set up sessions start
      this.trackSessionStart();

      // Set up pageview
      this.trackPageView();

      // Set up event listeners
      this.setupEventListeners();

      // Log initialization
      this.log("GA4 Server-Side Tagging initialized v3");
    },

    trackPageView: function () {
      // Check if we're on a product page
      if (this.config.productData) {
        // We're on a product page, so track view_item instead of page_view
        var viewItemData = {
          currency: this.config.currency || "EUR",
          value: this.config.productData.price,
          items: [this.config.productData],
          page_title: document.title,
          page_location: window.location.href,
          page_path: window.location.pathname,
          referrer: document.referrer,
        };

        // Add UTM parameters if available
        viewItemData.source =
          this.getUtmSource() || document.referrer || "(direct)";
        viewItemData.medium = this.getUtmMedium() || "(none)";
        viewItemData.campaign = this.getUtmCampaign() || "(not set)";

        this.log("Tracking view_item event for product page", viewItemData);
        this.trackEvent("view_item", viewItemData);
      }
      // For regular pages, track page_view
      else if (this.config.pageViewData) {
        this.log("Tracking page_view event", this.config.pageViewData);
        this.trackEvent("page_view", this.config.pageViewData);
      } else {
        // Fallback to basic page view tracking if no data available
        var pageViewData = {
          page_title: document.title,
          page_location: window.location.href,
          page_path: window.location.pathname,
          referrer: document.referrer,
        };
        this.log("Tracking basic page_view event", pageViewData);
        this.trackEvent("page_view", pageViewData);
      }
    },

    // Track session_start event only if not already sent for this session
    trackSessionStart: function () {
      var session = this.getSessionId();

      this.log("Session data:", session);
      
      if (!session.isNew) {
        this.log("Session already started, skipping session_start");
        return;
      }

      var sessionStartData = {
        page_location: window.location.href,
        page_path: window.location.pathname,
        page_title: document.title,
        engagement_time_msec: 1000,
        session_id: session.id,
        client_id: this.getClientId(),
        source: this.getUtmSource() || this.getReferrerSource() || "(direct)",
        medium: this.getUtmMedium() || this.getReferrerMedium() || "(none)",
        campaign: this.getUtmCampaign() || "(not set)",
      };

      this.log("Tracking session_start event", sessionStartData);
      this.trackEvent("session_start", sessionStartData);
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

          // Check if productId is empty or not found
          if (!productId) {
            // Get item_name from h1.bde-heading
            productName = $("h1.bde-heading").text().trim();

            // Get item_id from body class (postid-XXXXX)
            var bodyClasses = $("body").attr("class").split(" ");
            for (var i = 0; i < bodyClasses.length; i++) {
              if (bodyClasses[i].startsWith("postid-")) {
                productId = bodyClasses[i].replace("postid-", "");
                break;
              }
            }

            // Set price to 0
            productPrice = 0;
          }

          self.trackEvent("add_to_cart", {
            item_id: productId,
            item_name: productName,
            price: parseFloat(productPrice),
            quantity: quantity,
          });

          self.log("Tracked Buy Now button click as add_to_cart", {
            item_id: productId,
            item_name: productName,
            price: parseFloat(productPrice),
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
          self.trackEvent("view_item", {
            item_id: productId,
            item_name: productName,
            price: parseFloat(productPrice),
          });
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
      if (
        window.location.href.indexOf("/checkout/order-received/") > -1 ||
        window.location.href.indexOf("/order-pay/") > -1 ||
        window.location.href.indexOf("/thank-you/") > -1
      ) {
        // Check if we have order data from the server
        if (
          typeof self.config.orderData !== "undefined" &&
          self.config.orderData
        ) {
          // Add attribution data to order data
          var orderData = self.config.orderData;

          // Add source attribution parameters
          orderData.source =
            self.getUtmSource() || document.referrer || "(direct)";
          orderData.medium = self.getUtmMedium() || "(none)";
          orderData.campaign = self.getUtmCampaign() || "(not set)";

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
              // Add attribution data
              source: self.getUtmSource() || document.referrer || "(direct)",
              medium: self.getUtmMedium() || "(none)",
              campaign: self.getUtmCampaign() || "(not set)",
            });

            self.log("Tracked minimal purchase event with attribution", {
              transaction_id: orderId,
              value: orderTotal,
              source: self.getUtmSource() || document.referrer || "(direct)",
              medium: self.getUtmMedium() || "(none)",
              campaign: self.getUtmCampaign() || "(not set)",
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

            // Add source attribution parameters
            quoteData.source =
              self.getUtmSource() || document.referrer || "(direct)";
            quoteData.medium = self.getUtmMedium() || "(none)";
            quoteData.campaign = self.getUtmCampaign() || "(not set)";

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
                // Add attribution data
                source: self.getUtmSource() || document.referrer || "(direct)",
                medium: self.getUtmMedium() || "(none)",
                campaign: self.getUtmCampaign() || "(not set)",
              });

              self.log("Tracked minimal purchase event with attribution", {
                transaction_id: orderId,
                value: orderTotal,
                source: self.getUtmSource() || document.referrer || "(direct)",
                medium: self.getUtmMedium() || "(none)",
                campaign: self.getUtmCampaign() || "(not set)",
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

    getParameterByName: function (name) {
      var match = RegExp("[?&]" + name + "=([^&]*)").exec(
        window.location.search
      );
      return match && decodeURIComponent(match[1].replace(/\+/g, " "));
    },

    // Track an event
    trackEvent: function (eventName, eventParams) {
      // Create a copy of event params to avoid modifying the original
      var params = Object.assign({}, eventParams);

      // Log the event
      this.log("Tracking event: " + eventName, params);

      // Add timestamp to event params
      params.event_time = Math.floor(Date.now() / 1000);

      // For server-side tracking, add these important parameters
      if (this.config.useServerSide) {
        // Add standard attribution parameters if not already present
        if (!params.source)
          params.source =
            this.getUtmSource() || this.getReferrerSource() || "(direct)";
        if (!params.medium)
          params.medium =
            this.getUtmMedium() || this.getReferrerMedium() || "(none)";
        if (!params.campaign)
          params.campaign = this.getUtmCampaign() || "(not set)";
        if (!params.term)
          params.term = this.getParameterByName("utm_term") || "(not set)";
        if (!params.content)
          params.content =
            this.getParameterByName("utm_content") || "(not set)";
        if (!params.session_id) {
          var session = this.getSessionId();
          params.session_id = session.id; // Use the session ID from the getSessionId() function
        }
        if (!params.engagement_time_msec) params.engagement_time_msec = 1000;

        // Make sure user data is included
        params.client_id = this.getClientId();

        // Send the event server-side
        this.sendServerSideEvent(eventName, params);
      } else if (typeof gtag === "function") {
        gtag("event", eventName, params);
        this.log("Sent to gtag: " + eventName);
      } else {
        console.error("[GA4 Server-Side Tagging] Error sending event", {
          config: this.config,
          eventName: eventName,
          eventParams: params,
        });
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

      // Send the request
      $.ajax({
        url: endpoint,
        type: "POST",
        data: JSON.stringify(data),
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

    // Get referrer source information
    getReferrerSource: function () {
      var referrer = document.referrer;
      if (!referrer) return "(direct)";

      var referrerHostname = new URL(referrer).hostname;
      var currentHostname = window.location.hostname;

      // If same domain, it's internal
      if (referrerHostname === currentHostname) return "(internal)";

      // Check for search engines
      var searchEngines = {
        google: "google",
        bing: "bing",
        yahoo: "yahoo",
        duckduckgo: "duckduckgo",
        yandex: "yandex",
        baidu: "baidu",
      };

      for (var engine in searchEngines) {
        if (referrerHostname.indexOf(engine) !== -1) {
          return searchEngines[engine];
        }
      }

      // If not a search engine, use the hostname as source
      return referrerHostname;
    },

    getReferrerMedium: function () {
      var referrer = document.referrer;
      if (!referrer) return "(none)";

      var referrerHostname = new URL(referrer).hostname;
      var currentHostname = window.location.hostname;

      // If same domain, it's internal
      if (referrerHostname === currentHostname) return "(internal)";

      // Check for search engines
      var searchEngines = {
        google: "organic",
        bing: "organic",
        yahoo: "organic",
        duckduckgo: "organic",
        yandex: "organic",
        baidu: "organic",
      };

      for (var engine in searchEngines) {
        if (referrerHostname.indexOf(engine) !== -1) {
          return searchEngines[engine];
        }
      }

      // Social media
      var socialMedia = {
        facebook: "social",
        twitter: "social",
        instagram: "social",
        linkedin: "social",
        reddit: "social",
        pinterest: "social",
      };

      for (var site in socialMedia) {
        if (referrerHostname.indexOf(site) !== -1) {
          return socialMedia[site];
        }
      }

      // Default for all other external sites
      return "referral";
    },

    getSessionId: function () {
      var sessionId = localStorage.getItem("ga4_session_id");
      var sessionStart = localStorage.getItem("ga4_session_start");
      var now = Date.now();
      var isNew = false;

      // If no session or session expired (30 min inactive)
      if (!sessionId || !sessionStart || now - sessionStart > 30 * 60 * 1000) {
        sessionId =
          Math.random().toString(36).substring(2, 15) +
          Math.random().toString(36).substring(2, 15);
        localStorage.setItem("ga4_session_id", sessionId);
        localStorage.setItem("ga4_session_start", now);
        isNew = true;
      } else {
        // Update session timestamp on activity
        localStorage.setItem("ga4_session_start", now);
      }

      return {
        id: sessionId,
        isNew: isNew,
      };
    },

    // Get client ID from cookie
    getClientId: function () {
      // Try to get client ID from _ga cookie
      var gaCookie = this.getCookie("_ga");
      if (gaCookie) {
        var parts = gaCookie.split(".");
        if (parts.length >= 4) {
          return parts[2] + "." + parts[3];
        }
      }

      // Try to get from localStorage if available
      if (window.localStorage) {
        var storedClientId = localStorage.getItem("ga4_client_id");
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
        localStorage.setItem("ga4_client_id", clientId);
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
