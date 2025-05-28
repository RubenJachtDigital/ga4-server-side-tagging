<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @since      1.0.0
 * @package    GA4_Server_Side_Tagging
 */

if (!defined('WPINC')) {
    die;
}

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two hooks for
 * enqueuing the public-facing stylesheet and JavaScript.
 *
 * @since      1.0.0
 */
class GA4_Server_Side_Tagging_Public
{

    /**
     * The logger instance.
     *
     * @since    1.0.0
     * @access   private
     * @var      GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    private $logger;

    /**
     * Initialize the class and set its properties.
     *
     * @since    1.0.0
     * @param    GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Register the stylesheets for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_styles()
    {
        wp_enqueue_style(
            'ga4-server-side-tagging-public',
            GA4_SERVER_SIDE_TAGGING_PLUGIN_URL . 'public/css/ga4-server-side-tagging-public.css',
            array(),
            GA4_SERVER_SIDE_TAGGING_VERSION,
            'all'
        );
    }

    /**
     * Register the JavaScript for the public-facing side of the site.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts()
    {
        // Only enqueue if we have a measurement ID
        $measurement_id = get_option('ga4_measurement_id', '');
        if (empty($measurement_id)) {
            return;
        }

        // Check if we should track logged-in users
        if (is_user_logged_in() && !get_option('ga4_track_logged_in_users', true)) {
            return;
        }
        // 1. First enqueue the utilities library (dependency for other scripts)
        wp_enqueue_script(
            'ga4-utilities',
            GA4_SERVER_SIDE_TAGGING_PLUGIN_URL . 'public/js/ga4-utilities.js',
            array('jquery'),
            GA4_SERVER_SIDE_TAGGING_VERSION,
            false
        );

        // Enqueue the main tracking script
        wp_enqueue_script(
            'ga4-server-side-tagging-public',
            GA4_SERVER_SIDE_TAGGING_PLUGIN_URL . 'public/js/ga4-server-side-tagging-public.js',
            array('jquery'),
            GA4_SERVER_SIDE_TAGGING_VERSION,
            false
        );
        // Prepare data for the script
        $script_data = array(
            'measurementId' => $measurement_id,
            'useServerSide' => get_option('ga4_use_server_side', true),
            'debugMode' => get_option('ga4_server_side_tagging_debug_mode', false),
            'anonymizeIp' => get_option('ga4_anonymize_ip', true),
            'ga4TrackLoggedInUsers' => get_option('ga4_track_logged_in_users', true),
            'apiEndpoint' => rest_url('ga4-server-side-tagging/v1/collect'),
            'nonce' => wp_create_nonce('wp_rest'),
            'isEcommerceEnabled' => get_option('ga4_ecommerce_tracking', true),
            'cloudflareWorkerUrl' => get_option('ga4_cloudflare_worker_url', ''),
            'yithRaqFormId' => get_option('ga4_yith_raq_form_id', ''),
            'conversionFormIds' => get_option('ga4_conversion_form_ids', ''),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
            'siteName' => get_bloginfo('name'),
        );

        // Add product data if we're on a product page
        if (function_exists('is_product') && is_product()) {
            $script_data['productData'] = $this->get_current_product_data();
        }

        // Add cart data for checkout events
        if (function_exists('is_checkout') && (is_checkout() || is_cart())) {
            $script_data['cartData'] = $this->get_cart_data_for_tracking();
            $this->logger->info('Added cart data for checkout events. Items count: ' .
                (isset($script_data['cartData']['items_count']) ? $script_data['cartData']['items_count'] : 0));
        }

        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            $script_data['user_id'] = get_current_user_id();
        }

        // Method 1: Use WooCommerce's built-in function (RECOMMENDED)
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            $script_data['isThankYouPage'] = true;

            // Get order data if key is present
            if (isset($_GET['key'])) {
                $order_id = wc_get_order_id_by_order_key(wc_clean(wp_unslash($_GET['key'])));
                if ($order_id) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $script_data['orderData'] = $this->get_order_data_for_tracking($order);
                        $this->logger->info('Added order data for purchase event tracking. Order ID: ' . $order_id);
                    }
                }
            }
        } else {
            $script_data['isThankYouPage'] = false;
        }

        if (!empty($this->get_raq_cart_data())) {
            $quote_data = $this->get_order_quote_data_for_tracking();
            $this->logger->info('Quote data:' . json_encode($quote_data));
            $script_data['quoteData'] = $quote_data;
            $this->logger->info('Added quote data for request a quote event tracking. Order ID: ' . $quote_data['transaction_id']);
        }

        // Pass data to the script
        wp_localize_script(
            'ga4-server-side-tagging-public',
            'ga4ServerSideTagging',
            $script_data
        );
        $this->enqueue_google_ads_script();

    }

    /**
     * Add GA4 tracking code to the site header.
     *
     * @since    1.0.0
     */
    public function add_ga4_tracking_code()
    {
        // Only add tracking code if we have a measurement ID
        $measurement_id = get_option('ga4_measurement_id', '');

        // Get settings
        $use_server_side = get_option('ga4_use_server_side', true);
        $anonymize_ip = get_option('ga4_anonymize_ip', true);
        $debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);
        $cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');

        if (empty($measurement_id)) {
            return;
        }

        // Check if we should track logged-in users
        if (is_user_logged_in() && !get_option('ga4_track_logged_in_users', true)) {
            return;
        }

        if ($use_server_side == true) {
            return;
        }


        // Log page view
        $this->logger->info('Page view: ' . get_the_title() . ' (' . get_permalink() . ')');

        // Output the GA4 tracking code
        ?>
        <!-- GA4 Server-Side Tagging -->
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr($measurement_id); ?>"></script>

        <script>
            window.dataLayer = window.dataLayer || [];

            function gtag() {
                dataLayer.push(arguments);
            }
            gtag('js', new Date());
            <?php if ($anonymize_ip): ?>
                gtag('set', 'anonymize_ip', true);
            <?php endif; ?>

            <?php if ($debug_mode): ?>
                gtag('config', '<?php echo esc_js($measurement_id); ?>', {
                    'debug_mode': true
                });
            <?php else: ?>
                gtag('config', '<?php echo esc_js($measurement_id); ?>');
            <?php endif; ?>

            <?php if (is_user_logged_in()): ?>
                // Set user ID for logged-in users
                gtag('set', 'user_id', '<?php echo esc_js(get_current_user_id()); ?>');
            <?php endif; ?>
        </script>
        <!-- End GA4 Server-Side Tagging -->
        <?php
    }

    public function enqueue_google_ads_script()
    {
        // Check if Google Ads tracking is enabled
        $google_ads_conversion_id = get_option('ga4_google_ads_conversion_id', '');
        if (empty($google_ads_conversion_id)) {
            return;
        }

        // Check if we should track logged-in users
        if (is_user_logged_in() && !get_option('ga4_track_logged_in_users', true)) {
            return;
        }

        // Enqueue the Google Ads tracking script
        wp_enqueue_script(
            'ga4-google-ads-tracking',
            GA4_SERVER_SIDE_TAGGING_PLUGIN_URL . 'public/js/ga4-google-ads-tracking.js',
            array('jquery'),
            GA4_SERVER_SIDE_TAGGING_VERSION,
            false
        );

        // Prepare data for the Google Ads script
        $google_ads_data = array(
            'conversionId' => $google_ads_conversion_id,
            'purchaseConversionLabel' => get_option('ga4_google_ads_purchase_label', ''),
            'leadConversionLabel' => get_option('ga4_google_ads_lead_label', ''),
            'cloudflareWorkerUrl' => get_option('ga4_cloudflare_worker_url', ''),
            'debugMode' => get_option('ga4_server_side_tagging_debug_mode', false),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',

            // Conversion Values
            'defaultLeadValue' => get_option('ga4_google_ads_default_lead_value', 0),
            'defaultQuoteValue' => get_option('ga4_google_ads_default_quote_value', 0),
            'phoneCallValue' => get_option('ga4_google_ads_phone_call_value', 0),
            'emailClickValue' => get_option('ga4_google_ads_email_click_value', 0),

            // Tracking Options
            'trackPhoneCalls' => get_option('ga4_google_ads_track_phone_calls', false),
            'trackEmailClicks' => get_option('ga4_google_ads_track_email_clicks', false),
        );

        // Add user data if logged in
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $google_ads_data['userData'] = array(
                'email' => $current_user->user_email,
                'first_name' => $current_user->first_name,
                'last_name' => $current_user->last_name,
            );
        }

        // Add order data if we're on order confirmation page
        if (function_exists('is_order_received_page') && is_order_received_page()) {
            $google_ads_data['isThankYouPage'] = true;

            // Get order data if key is present
            if (isset($_GET['key'])) {
                $order_id = wc_get_order_id_by_order_key(wc_clean(wp_unslash($_GET['key'])));
                if ($order_id) {
                    $order = wc_get_order($order_id);
                    if ($order) {
                        $google_ads_data['orderData'] = $this->get_order_data_for_google_ads($order);
                        $this->logger->info('Added order data for Google Ads conversion tracking. Order ID: ' . $order_id);
                    }
                }
            }
        } else {
            $google_ads_data['isThankYouPage'] = false;
        }

        // Add quote data if available
        if (!empty($this->get_raq_cart_data())) {
            $quote_data = $this->get_quote_data_for_google_ads();
            $google_ads_data['quoteData'] = $quote_data;
            $this->logger->info('Added quote data for Google Ads conversion tracking.');
        }

        // Pass data to the script
        wp_localize_script(
            'ga4-google-ads-tracking',
            'googleAdsTracking',
            $google_ads_data
        );
    }

    /**
     * Get order data formatted for Google Ads conversions
     */
    private function get_order_data_for_google_ads($order)
    {
        if (!$order) {
            return null;
        }

        $order_data = array(
            'transaction_id' => $order->get_order_number(),
            'value' => floatval($order->get_total()),
            'currency' => $order->get_currency(),
            'customer_data' => array(
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'address' => $order->get_billing_address_1(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
            ),
            'items' => array()
        );

        // Get order items
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if ($product) {
                $order_data['items'][] = array(
                    'item_id' => (string) $product->get_id(),
                    'item_name' => $item->get_name(),
                    'item_category' => $this->get_product_category($product),
                    'quantity' => $item->get_quantity(),
                    'price' => floatval($item->get_total() / $item->get_quantity())
                );
            }
        }

        return $order_data;
    }

    /**
     * Get quote data formatted for Google Ads conversions
     */
    private function get_quote_data_for_google_ads()
    {
        $cart_data = $this->get_raq_cart_data();
        if (empty($cart_data)) {
            return null;
        }

        $quote_data = array(
            'transaction_id' => 'quote_' . time() . '_' . wp_rand(1000, 9999),
            'value' => 0, // Set appropriate value for quote requests
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
            'items' => array()
        );

        $total_value = 0;

        foreach ($cart_data as $item) {
            $item_data = array(
                'item_id' => (string) $item['product_id'],
                'item_name' => $item['name'],
                'item_category' => '',
                'quantity' => $item['quantity'],
                'price' => floatval($item['price'])
            );

            $quote_data['items'][] = $item_data;
            $total_value += floatval($item['price']) * intval($item['quantity']);
        }

        $quote_data['value'] = $total_value;

        return $quote_data;
    }

    /**
     * Get current cart data for GA4 tracking
     * 
     * @return array Cart data formatted for GA4 events
     */
    public function get_cart_data_for_tracking()
    {
        if (!function_exists('WC') || !WC()->cart) {
            return array();
        }

        $cart = WC()->cart;

        if ($cart->is_empty()) {
            return array();
        }

        $cart_items = array();
        $item_index = 1;

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'];

            // Get product categories
            $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
            $category = !empty($categories) ? $categories[0] : '';

            // Get additional category levels for GA4
            $category2 = isset($categories[1]) ? $categories[1] : '';
            $category3 = isset($categories[2]) ? $categories[2] : '';
            $category4 = isset($categories[3]) ? $categories[3] : '';
            $category5 = isset($categories[4]) ? $categories[4] : '';

            // Get product brand (if you have a brand taxonomy or custom field)
            $brand = '';
            if (taxonomy_exists('product_brand')) {
                $brands = wp_get_post_terms($product_id, 'product_brand', array('fields' => 'names'));
                $brand = !empty($brands) ? $brands[0] : '';
            } else {
                // Alternative: get brand from custom field
                $brand = get_post_meta($product_id, '_product_brand', true);
            }

            $cart_items[] = array(
                'item_id' => $variation_id ? $variation_id : $product_id,
                'item_name' => $product->get_name(),
                'affiliation' => get_bloginfo('name'),
                'coupon' => '', // Will be set later if coupon is applied
                'discount' => 0, // Will be calculated later if discount is applied
                'index' => $item_index,
                'item_brand' => $brand,
                'item_category' => $category,
                'item_category2' => $category2,
                'item_category3' => $category3,
                'item_category4' => $category4,
                'item_category5' => $category5,
                'item_list_id' => 'cart',
                'item_list_name' => 'Shopping Cart',
                'item_variant' => $variation_id ? $this->get_variation_attributes($cart_item) : '',
                'location_id' => '', // Set if you have multiple store locations
                'price' => floatval($product->get_price()),
                'quantity' => intval($cart_item['quantity'])
            );

            $item_index++;
        }

        // Get applied coupons
        $applied_coupons = $cart->get_applied_coupons();
        $coupon_codes = !empty($applied_coupons) ? implode(',', $applied_coupons) : '';

        // Calculate discount amount
        $discount_amount = $cart->get_discount_total();

        // Update coupon and discount info for items if coupon is applied
        if (!empty($coupon_codes)) {
            foreach ($cart_items as &$item) {
                $item['coupon'] = $coupon_codes;
                // Distribute discount proportionally (simplified approach)
                $item['discount'] = $discount_amount > 0 ?
                    round(($discount_amount * $item['price'] * $item['quantity']) / $cart->get_subtotal(), 2) : 0;
            }
        }

        return array(
            'currency' => get_woocommerce_currency(),
            'value' => floatval($cart->get_total('edit')),
            'coupon' => $coupon_codes,
            'items' => $cart_items,
            // Additional cart information
            'subtotal' => floatval($cart->get_subtotal()),
            'tax' => floatval($cart->get_total_tax()),
            'shipping' => floatval($cart->get_shipping_total()),
            'discount' => floatval($discount_amount),
            'items_count' => $cart->get_cart_contents_count()
        );
    }

    /**
     * Get variation attributes as a string
     * 
     * @param array $cart_item Cart item data
     * @return string Variation attributes formatted as string
     */
    private function get_variation_attributes($cart_item)
    {
        if (empty($cart_item['variation']) || !is_array($cart_item['variation'])) {
            return '';
        }

        $attributes = array();
        foreach ($cart_item['variation'] as $key => $value) {
            if (empty($value))
                continue;

            // Remove 'attribute_' prefix and format
            $attribute_name = str_replace('attribute_', '', $key);
            $attribute_name = str_replace('pa_', '', $attribute_name); // Remove taxonomy prefix
            $attribute_name = ucfirst(str_replace('-', ' ', $attribute_name));

            $attributes[] = $attribute_name . ': ' . $value;
        }

        return implode(', ', $attributes);
    }


    /**
     * Get order data formatted for GA4 purchase event tracking.
     *
     * @since    1.0.0
     * @param    WC_Order    $order    The WooCommerce order.
     * @return   array       The formatted order data.
     */
    private function get_order_data_for_tracking($order)
    {
        $this->logger->info('Preparing order data for purchase event. Order #' . $order->get_order_number());

        // Get order items
        $items = array();
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $product_data = array(
                'item_id' => $product->get_id(),
                'item_name' => $item->get_name(),
                'price' => (float) $order->get_item_total($item, false),
                'quantity' => $item->get_quantity(),
            );

            // Add optional parameters if available
            if ($product->get_sku()) {
                $product_data['item_sku'] = $product->get_sku();
            }

            if ($product->get_category_ids()) {
                $categories = array();
                foreach ($product->get_category_ids() as $cat_id) {
                    $term = get_term_by('id', $cat_id, 'product_cat');
                    if ($term) {
                        $categories[] = $term->name;
                    }
                }
                if (!empty($categories)) {
                    $product_data['item_category'] = $categories[0];

                    // Add additional categories if available
                    for ($i = 1; $i < min(5, count($categories)); $i++) {
                        $product_data['item_category' . ($i + 1)] = $categories[$i];
                    }
                }
            }

            $items[] = $product_data;
        }

        // Get order data
        $order_data = array(
            'transaction_id' => $order->get_order_number(),
            'affiliation' => get_bloginfo('name'),
            'value' => (float) $order->get_total(),
            'tax' => (float) $order->get_total_tax(),
            'shipping' => (float) $order->get_shipping_total(),
            'currency' => $order->get_currency(),
            'items' => $items,
        );

        // Add coupon data if available
        if ($order->get_coupon_codes()) {
            $order_data['coupon'] = implode(', ', $order->get_coupon_codes());
        }

        // Add payment method
        $order_data['payment_type'] = $order->get_payment_method_title();

        // Add shipping tier
        $shipping_methods = $order->get_shipping_methods();
        if (!empty($shipping_methods)) {
            $shipping_method = reset($shipping_methods);
            $order_data['shipping_tier'] = $shipping_method->get_method_title();
        }

        return $order_data;
    }

    public function get_transient_user_id()
    {
        // Get the user's IP address
        $user_ip = $_SERVER['REMOTE_ADDR'];

        // Optionally, you can add the User-Agent string to make the key more unique
        $user_agent = $_SERVER['HTTP_USER_AGENT'];

        // Combine IP and User-Agent to create a more unique key
        $unique_key = md5($user_ip . $user_agent);

        // Generate a unique transient key for the user based on IP/User-Agent
        $transient_key = 'custom_raq_compuact_cart_' . $unique_key;
        return $transient_key;
    }

    public function get_raq_cart_data()
    {
        $transient_key = $this->get_transient_user_id();
        // Retrieve the stored product clicks for the specific user/session
        $product_clicks = get_transient($transient_key);

        if ($product_clicks !== false && is_array($product_clicks)) {
            $results = [];
            foreach ($product_clicks as $variation_id) {
                // Get the parent product ID for the variation
                $parent_id = wp_get_post_parent_id($variation_id);
                $results[] = [
                    'variation_id' => $variation_id,
                    'parent_id' => $parent_id ? $parent_id : $variation_id,
                ];
            }
            return $results;
        }
        return [];
    }

    private function get_order_quote_data_for_tracking()
    {
        $total = 0;
        $items = [];
        $order_number = date('ym') . sprintf('%08d', mt_rand(10000000, 99999999));

        $this->logger->info('Preparing quote data for request a quote event. Order #' . $order_number);

        $cart_items = $this->get_raq_cart_data();

        // Log cart items for debugging
        $this->logger->info('Quote cart items retrieved', [
            'cart_items_count' => count($cart_items)
        ]);

        if (empty($cart_items)) {
            $this->logger->warning('No cart items found for quote tracking');
            return;
        }


        foreach ($cart_items as $item) {
            $variation_id = $item['variation_id'];
            $product = wc_get_product($variation_id);

            if (!$product) {
                $this->logger->warning('Could not retrieve product for variation', [
                    'variation_id' => $variation_id
                ]);
                continue;
            }

            $total += $product->get_price();
            $product_data = [
                'item_id' => $product->get_id(),
                'item_name' => $product->get_name(),
                'price' => (float) $product->get_price(),
                'quantity' => 1,
            ];
            // Add optional parameters if available
            if ($product->get_sku()) {
                $product_data['item_sku'] = $product->get_sku();
            }

            if ($product->get_category_ids()) {
                $categories = array();
                foreach ($product->get_category_ids() as $cat_id) {
                    $term = get_term_by('id', $cat_id, 'product_cat');
                    if ($term) {
                        $categories[] = $term->name;
                    }
                }
                if (!empty($categories)) {
                    $product_data['item_category'] = $categories[0];

                    // Add additional categories if available
                    for ($i = 1; $i < min(5, count($categories)); $i++) {
                        $product_data['item_category' . ($i + 1)] = $categories[$i];
                    }
                }
            }


            $items[] = $product_data;
        }
        // Get order data
        $order_data = array(
            'transaction_id' => $order_number,
            'affiliation' => get_bloginfo('name'),
            'value' => (float) $total,
            'tax' => (float) 0,
            'shipping' => (float) 0,
            'currency' => 'EUR',
            'items' => $items,
        );
        $order_data['coupon'] = '';
        $order_data['payment_type'] = '';
        $order_data['shipping_tier'] = '';

        return $order_data;
    }

    /**
     * Get current product data for GA4 tracking.
     *
     * @since    1.0.0
     * @version  1.0.1
     * @return   array    The product data formatted for GA4.
     */
    private function get_current_product_data()
    {
        global $product;

        if (!is_object($product)) {
            $product = wc_get_product(get_the_ID());
        }

        if (!$product) {
            return [];
        }

        $this->logger->info('Getting product data for view_item event: ' . $product->get_name());

        // Get product categories
        $categories = [];
        if ($product->get_category_ids()) {
            foreach ($product->get_category_ids() as $cat_id) {
                $term = get_term_by('id', $cat_id, 'product_cat');
                if ($term) {
                    $categories[] = $term->name;
                }
            }
        }

        // Get product brand (if you have a brand taxonomy or custom field)
        $brand = '';
        if (taxonomy_exists('product_brand')) {
            $brands = wp_get_post_terms($product->get_id(), 'product_brand', array('fields' => 'names'));
            $brand = !empty($brands) ? $brands[0] : '';
        } else {
            // Alternative: get brand from custom field
            $brand = get_post_meta($product->get_id(), '_product_brand', true);
        }

        // Base product data with proper GA4 formatting
        $product_data = [
            'item_id' => (string) $product->get_id(), // Convert to string for GA4
            'item_name' => $product->get_name(),
            'affiliation' => get_bloginfo('name'),
            'coupon' => '', // Will be set if coupon is applied
            'discount' => 0, // Will be set if discount is applied
            'index' => 0, // Position in list (if applicable)
            'item_brand' => $brand,
            'item_category' => !empty($categories) ? $categories[0] : '',
            'item_category2' => isset($categories[1]) ? $categories[1] : '',
            'item_category3' => isset($categories[2]) ? $categories[2] : '',
            'item_category4' => isset($categories[3]) ? $categories[3] : '',
            'item_category5' => isset($categories[4]) ? $categories[4] : '',
            'item_list_id' => '', // Set if product is in a specific list
            'item_list_name' => '', // Set if product is in a specific list
            'item_variant' => '',
            'location_id' => '', // Set if you have multiple store locations
            'price' => (float) $product->get_price(),
            'quantity' => 1, // Default quantity for view_item events
            'currency' => get_woocommerce_currency()
        ];

        // Add SKU if available
        if ($product->get_sku()) {
            $product_data['item_sku'] = $product->get_sku();
        }

        // Handle variable products
        if ($product->is_type('variable')) {
            $product_data['item_variant'] = 'Variable Product';

            // If we're on a variation page, get the specific variation data
            if (isset($_GET) && !empty($_GET)) {
                $variation_attributes = [];
                foreach ($_GET as $key => $value) {
                    if (strpos($key, 'attribute_') === 0) {
                        $attribute_name = str_replace('attribute_', '', $key);
                        $attribute_name = str_replace('pa_', '', $attribute_name);
                        $attribute_name = ucfirst(str_replace('-', ' ', $attribute_name));
                        $variation_attributes[] = $attribute_name . ': ' . $value;
                    }
                }
                if (!empty($variation_attributes)) {
                    $product_data['item_variant'] = implode(', ', $variation_attributes);
                }
            }
        }

        // Handle grouped products
        if ($product->is_type('grouped')) {
            $product_data['item_variant'] = 'Grouped Product';
        }

        // Handle subscription products (if WooCommerce Subscriptions is active)
        if ($product->is_type('subscription') || $product->is_type('variable-subscription')) {
            $product_data['item_variant'] = 'Subscription Product';
        }

        // Remove empty values to keep the data clean
        $product_data = array_filter($product_data, function ($value) {
            return $value !== '' && $value !== null;
        });

        return $product_data;
    }

    /**
     * Get current product data for view_item event (wrapper for proper event structure)
     */
    public function get_view_item_event_data()
    {
        $product_data = $this->get_current_product_data();

        if (empty($product_data)) {
            return [];
        }

        // Structure for view_item event
        return [
            'currency' => $product_data['currency'],
            'value' => $product_data['price'],
            'items' => [$product_data]
        ];
    }

    /**
     * Enhanced function to get product data with variation support
     */
    private function get_current_product_data_with_variation()
    {
        global $product;

        if (!is_object($product)) {
            $product = wc_get_product(get_the_ID());
        }

        if (!$product) {
            return [];
        }

        // If this is a variation, get both parent and variation data
        if ($product->is_type('variation')) {
            $parent_product = wc_get_product($product->get_parent_id());
            $product_data = $this->build_product_data_array($parent_product, $product);
        } else {
            $product_data = $this->build_product_data_array($product);
        }

        return $product_data;
    }

    /**
     * Helper function to build product data array
     */
    private function build_product_data_array($product, $variation = null)
    {
        $actual_product = $variation ?: $product;

        // Get categories from parent product
        $categories = [];
        if ($product->get_category_ids()) {
            foreach ($product->get_category_ids() as $cat_id) {
                $term = get_term_by('id', $cat_id, 'product_cat');
                if ($term) {
                    $categories[] = $term->name;
                }
            }
        }

        // Get brand
        $brand = '';
        if (taxonomy_exists('product_brand')) {
            $brands = wp_get_post_terms($product->get_id(), 'product_brand', array('fields' => 'names'));
            $brand = !empty($brands) ? $brands[0] : '';
        } else {
            $brand = get_post_meta($product->get_id(), '_product_brand', true);
        }

        $product_data = [
            'item_id' => (string) $actual_product->get_id(),
            'item_name' => $actual_product->get_name(),
            'affiliation' => get_bloginfo('name'),
            'item_brand' => $brand,
            'item_category' => !empty($categories) ? $categories[0] : '',
            'item_category2' => isset($categories[1]) ? $categories[1] : '',
            'item_category3' => isset($categories[2]) ? $categories[2] : '',
            'item_category4' => isset($categories[3]) ? $categories[3] : '',
            'item_category5' => isset($categories[4]) ? $categories[4] : '',
            'price' => (float) $actual_product->get_price(),
            'quantity' => 1,
            'currency' => get_woocommerce_currency()
        ];

        // Add SKU
        if ($actual_product->get_sku()) {
            $product_data['item_sku'] = $actual_product->get_sku();
        }

        // Add variation attributes if this is a variation
        if ($variation) {
            $attributes = [];
            foreach ($variation->get_variation_attributes() as $attribute => $value) {
                if (empty($value))
                    continue;

                $attribute_name = str_replace('attribute_', '', $attribute);
                $attribute_name = str_replace('pa_', '', $attribute_name);
                $attribute_name = ucfirst(str_replace('-', ' ', $attribute_name));

                $attributes[] = $attribute_name . ': ' . $value;
            }

            if (!empty($attributes)) {
                $product_data['item_variant'] = implode(', ', $attributes);
            }
        }

        // Remove empty values
        return array_filter($product_data, function ($value) {
            return $value !== '' && $value !== null;
        });
    }
}
