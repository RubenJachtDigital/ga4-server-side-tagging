<?php

namespace GA4ServerSideTagging\Frontend;

use GA4ServerSideTagging\Core\GA4_Server_Side_Tagging_Logger;

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
    public function __construct(GA4_Server_Side_Tagging_Logger $logger)
    {
        $this->logger = $logger;
        
        // Start session if not already started
        if (!session_id()) {
            session_start();
        }
    }

    /**
     * Check if an order has already been tracked in this session
     *
     * @since    1.0.0
     * @param    int    $order_id    The order ID to check
     * @return   bool   True if order was already tracked, false otherwise
     */
    private function is_order_already_tracked($order_id)
    {
        if (!isset($_SESSION['ga4_tracked_orders'])) {
            $_SESSION['ga4_tracked_orders'] = array();
        }
        
        return in_array($order_id, $_SESSION['ga4_tracked_orders']);
    }

    /**
     * Mark an order as tracked in this session
     *
     * @since    1.0.0
     * @param    int    $order_id    The order ID to mark as tracked
     */
    private function mark_order_as_tracked($order_id)
    {
        if (!isset($_SESSION['ga4_tracked_orders'])) {
            $_SESSION['ga4_tracked_orders'] = array();
        }
        
        if (!in_array($order_id, $_SESSION['ga4_tracked_orders'])) {
            $_SESSION['ga4_tracked_orders'][] = $order_id;
            $this->logger->info('Order marked as tracked in session: ' . $order_id);
        }
    }

    /**
     * Clean up old tracked orders from session (keep only last 10 orders)
     *
     * @since    1.0.0
     */
    private function cleanup_tracked_orders()
    {
        if (isset($_SESSION['ga4_tracked_orders']) && count($_SESSION['ga4_tracked_orders']) > 10) {
            $_SESSION['ga4_tracked_orders'] = array_slice($_SESSION['ga4_tracked_orders'], -10);
        }
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

        // 2. Enqueue the consent management script BEFORE the main tracking script
        wp_enqueue_script(
            'ga4-server-side-tagging-consent-management',
            GA4_SERVER_SIDE_TAGGING_PLUGIN_URL . 'public/js/ga4-consent-manager.js',
            array('jquery', 'ga4-utilities'),
            GA4_SERVER_SIDE_TAGGING_VERSION,
            false
        );

        // 3. Enqueue the main tracking script (depends on both utilities and consent)
        wp_enqueue_script(
            'ga4-server-side-tagging-public',
            GA4_SERVER_SIDE_TAGGING_PLUGIN_URL . 'public/js/ga4-server-side-tagging-public.js',
            array('jquery', 'ga4-utilities', 'ga4-server-side-tagging-consent-management'),
            GA4_SERVER_SIDE_TAGGING_VERSION,
            false
        );

        // Prepare data for the script (enhanced with GDPR consent settings)
        $script_data = array(
            'measurementId' => $measurement_id,
            'debugMode' => (bool) get_option('ga4_server_side_tagging_debug_mode', false),
            'anonymizeIp' => (bool) get_option('ga4_anonymize_ip', true),
            'ga4TrackLoggedInUsers' => (bool) get_option('ga4_track_logged_in_users', true),
            'apiEndpoint' => rest_url('ga4-server-side-tagging/v1'),
            'nonce' => wp_create_nonce('wp_rest'),
            'isEcommerceEnabled' => (bool) get_option('ga4_ecommerce_tracking', true),
            'yithRaqFormId' => get_option('ga4_yith_raq_form_id', ''),
            'conversionFormIds' => get_option('ga4_conversion_form_ids', ''),
            'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
            'siteName' => get_bloginfo('name'),
            'encryptionEnabled' => (bool) get_option('ga4_jwt_encryption_enabled', false),
            'transmissionMethod' => get_option('ga4_transmission_method', 'secure_wp_to_cf'),
            'cloudflareWorkerUrl' => get_option('ga4_cloudflare_worker_url', ''),
            

            // GDPR Consent settings (enhanced)
            'consentSettings' => array(
                'useIubenda' => (bool) get_option('ga4_use_iubenda', false),
                'acceptSelector' => get_option('ga4_consent_accept_selector', '.accept-all'),
                'denySelector' => get_option('ga4_consent_deny_selector', '.deny-all'),
                'defaultTimeout' => (int) get_option('ga4_consent_default_timeout', 0),
                'timeoutAction' => get_option('ga4_consent_timeout_action', 'deny'),
                'consentModeEnabled' => (bool) get_option('ga4_consent_mode_enabled', true),
                'disableAllIP' => (bool) get_option('ga4_disable_all_ip', false),
                'storageExpirationHours' => (int) get_option('ga4_storage_expiration_hours', 24)
            ),

            // A/B Testing settings
            'abTestsEnabled' => (bool) get_option('ga4_ab_tests_enabled', false),
            'abTestsConfig' => get_option('ga4_ab_tests_config', '[]'),

            // Click Tracking settings
            'clickTracksEnabled' => (bool) get_option('ga4_click_tracks_enabled', false),
            'clickTracksConfig' => get_option('ga4_click_tracks_config', '[]')
        );

        // Add product data if we're on a product page
        if (function_exists('is_product') && is_product()) {
            $script_data['productData'] = $this->get_current_product_data();
        }

        // Add cart data for checkout events
        if (function_exists('is_checkout') && (is_checkout() || is_cart())) {
            $script_data['cartData'] = $this->get_cart_data_for_tracking();
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
                        // Check if this order was already tracked in this session
                        if ($this->is_order_already_tracked($order_id)) {
                            // Order already tracked - send orderSent flag instead
                            $script_data['orderSent'] = true;
                            $this->logger->warning('Duplicate order tracking prevented - Order ID: ' . $order_id . ' already tracked in this session');
                        } else {
                            // First time tracking this order - send order data and mark as tracked
                            $script_data['orderData'] = $this->get_order_data_for_tracking($order);
                            $this->mark_order_as_tracked($order_id);
                            $this->cleanup_tracked_orders();
                            $this->logger->info('Added order data for purchase event tracking. Order ID: ' . $order_id);
                        }
                    }
                }
            }
        } else {
            $script_data['isThankYouPage'] = false;
        }

        if (method_exists('\CompuactEudonetAPI\Functions\CustomFunctions', 'get_raq_cart_data')) {
            if (!empty(\CompuactEudonetAPI\Functions\CustomFunctions::get_raq_cart_data())) {
                $quote_data = $this->get_order_quote_data_for_tracking();
                $this->logger->info('Quote data:' . json_encode($quote_data));
                $script_data['quoteData'] = $quote_data;
                $this->logger->info('Added quote data for request a quote event tracking. Order ID: ' . $quote_data['transaction_id']);
            }
        }

        // Cloudflare Worker URL is now included in the main script_data array based on transmission method

        // Pass data to the script
        wp_localize_script(
            'ga4-server-side-tagging-public',
            'ga4ServerSideTagging',
            $script_data
        );

        // NOTE: Consent manager initialization is now handled by the main tracking script
        // to ensure proper tracking instance reference is passed
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
        $primary_item_list = null; // Will be set to the first product's primary category

        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $product_id = $cart_item['product_id'];
            $variation_id = $cart_item['variation_id'];

            // Get product categories
            $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
            $category = !empty($categories) ? $categories[0] : '';

            // Set primary item_list from first product's category if not already set
            if ($primary_item_list === null && !empty($category)) {
                $primary_item_list = $category;
            }

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
                'item_id' => $product_id,
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
                'item_list_id' => !empty($category) ? $category : 'cart',
                'item_list_name' => !empty($category) ? $category : 'Shopping Cart',
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

        $cart_data = array(
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

        // Add item_list information to the main cart data
        if ($primary_item_list !== null) {
            $cart_data['item_list_id'] = $primary_item_list;
            $cart_data['item_list_name'] = $primary_item_list;
        } else {
            $cart_data['item_list_id'] = 'cart';
            $cart_data['item_list_name'] = 'Shopping Cart';
        }

        return $cart_data;
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
     * @param    \WC_Order    $order    The WooCommerce order.
     * @return   array       The formatted order data.
     */
    private function get_order_data_for_tracking($order)
    {
        // Get order items
        $items = array();
        $item_list_id = null; // Will be set to the first product's primary category

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

                    // Set item_list_id to the first product's primary category if not already set
                    if ($item_list_id === null) {
                        $item_list_id = $categories[0];
                    }

                    // Add item_list_id to each product
                    $product_data['item_list_id'] = $categories[0];
                    $product_data['item_list_name'] = $categories[0];

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

        // Add item_list_id to the main order data if we found one
        if ($item_list_id !== null) {
            $order_data['item_list_id'] = $item_list_id;
            $order_data['item_list_name'] = $item_list_id;
        }

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

    private function get_order_quote_data_for_tracking()
    {
        $total = 0;
        $items = [];
        $order_number = date('ym') . sprintf('%08d', mt_rand(10000000, 99999999));

        $this->logger->info('Preparing quote data for request a quote event. Order #' . $order_number);
        if (!class_exists('\CompuactEudonetAPI\Functions\CustomFunctions')) {
            $this->logger->info('\CompuactEudonetAPI\Functions\CustomFunctions\ Doesnt exist');
            return;
        }
        $cart_items = \CompuactEudonetAPI\Functions\CustomFunctions::get_raq_cart_data();


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


        // Use the enhanced build_product_data_array function
        $product_data = $this->build_product_data_array($product, null, 'product_page', 0);

        if (!$product_data) {
            return [];
        }

        // Add product page specific data
        $product_data['item_url'] = get_permalink($product->get_id());

        // Add product rating if available
        if ($product->get_average_rating()) {
            $product_data['item_rating'] = (float) $product->get_average_rating();
            $product_data['item_rating_count'] = (int) $product->get_rating_count();
        }

        // Add product gallery count
        $gallery_ids = $product->get_gallery_image_ids();
        $product_data['item_image_count'] = count($gallery_ids) + 1; // +1 for main image

        // Add sale percentage if on sale
        if ($product->is_on_sale() && $product->get_regular_price()) {
            $regular_price = (float) $product->get_regular_price();
            $sale_price = (float) $product->get_sale_price();
            $discount_percentage = round((($regular_price - $sale_price) / $regular_price) * 100);
            $product_data['discount_percentage'] = $discount_percentage;
        }

        // Handle variable products - check for selected variation in URL
        if ($product->is_type('variable')) {
            $variation_id = null;
            $variation = null;

            // Check if variation is selected via URL parameters
            if (isset($_GET) && !empty($_GET)) {
                $variation_attributes = [];
                foreach ($_GET as $key => $value) {
                    if (strpos($key, 'attribute_') === 0 && !empty($value)) {
                        $variation_attributes[$key] = $value;
                    }
                }

                if (!empty($variation_attributes)) {
                    // Find matching variation
                    $data_store = \WC_Data_Store::load('product');
                    $variation_id = $data_store->find_matching_product_variation($product, $variation_attributes);

                    if ($variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation && $variation->exists()) {
                            // Update product data with variation-specific information
                            $variation_data = $this->build_product_data_array($product, $variation, 'product_page', 0);
                            if ($variation_data) {
                                $product_data = array_merge($product_data, $variation_data);
                            }
                        }
                    }
                }
            }

            // If no specific variation selected, keep as variable product
            if (!$variation) {
                $product_data['item_variant'] = 'Variable Product';
            }
        }

        // Handle grouped products
        if ($product->is_type('grouped')) {
            $product_data['item_variant'] = 'Grouped Product';

            // Add information about grouped products
            $grouped_products = $product->get_children();
            if (!empty($grouped_products)) {
                $product_data['grouped_products_count'] = count($grouped_products);
            }
        }

        // Handle subscription products (if WooCommerce Subscriptions is active)
        if ($product->is_type('subscription') || $product->is_type('variable-subscription')) {
            $product_data['item_variant'] = 'Subscription Product';

            // Add subscription-specific data if available
            if (method_exists($product, 'get_subscription_period')) {
                $product_data['subscription_period'] = $product->get_subscription_period();
                $product_data['subscription_period_interval'] = $product->get_subscription_period_interval();
            }
        }

        // Add product tags if available
        $product_tags = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'));
        if (!empty($product_tags)) {
            $product_data['item_tags'] = implode(', ', array_slice($product_tags, 0, 5)); // Limit to 5 tags
        }

        // Remove empty values to keep the data clean
        $product_data = array_filter($product_data, function ($value) {
            return $value !== '' && $value !== null && $value !== 0;
        });

        return $product_data;
    }

    /**
     * Helper function to build product data array
     */
    private function build_product_data_array($product, $variation = null, $context = 'general', $index = 0)
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

        // Determine item_list based on context
        $item_list_id = '';
        $item_list_name = '';

        switch ($context) {
            case 'cart':
                $item_list_id = !empty($categories) ? $categories[0] : 'cart';
                $item_list_name = !empty($categories) ? $categories[0] : 'Shopping Cart';
                break;
            case 'purchase':
                $item_list_id = !empty($categories) ? $categories[0] : 'purchase';
                $item_list_name = !empty($categories) ? $categories[0] : 'Purchase';
                break;
            case 'product_page':
                $item_list_id = !empty($categories) ? $categories[0] : 'product_detail';
                $item_list_name = !empty($categories) ? $categories[0] : 'Product Detail';
                break;
            case 'search_results':
                $item_list_id = 'search_results';
                $item_list_name = 'Search Results';
                break;
            case 'category':
                // If we know the specific category being viewed
                $item_list_id = !empty($categories) ? $categories[0] : 'category';
                $item_list_name = !empty($categories) ? $categories[0] : 'Category';
                break;
            case 'related_products':
                $item_list_id = 'related_products';
                $item_list_name = 'Related Products';
                break;
            case 'cross_sell':
                $item_list_id = 'cross_sell';
                $item_list_name = 'Cross-sell Products';
                break;
            case 'upsell':
                $item_list_id = 'upsell';
                $item_list_name = 'Up-sell Products';
                break;
            default:
                $item_list_id = !empty($categories) ? $categories[0] : 'general';
                $item_list_name = !empty($categories) ? $categories[0] : 'Product List';
        }

        $product_data = [
            'item_id' => (string) $product->get_id(), // Always use parent product ID
            'item_name' => $actual_product->get_name(),
            'affiliation' => get_bloginfo('name'),
            'item_brand' => $brand,
            'item_category' => !empty($categories) ? $categories[0] : '',
            'item_category2' => isset($categories[1]) ? $categories[1] : '',
            'item_category3' => isset($categories[2]) ? $categories[2] : '',
            'item_category4' => isset($categories[3]) ? $categories[3] : '',
            'item_category5' => isset($categories[4]) ? $categories[4] : '',
            'item_list_id' => $item_list_id,
            'item_list_name' => $item_list_name,
            'price' => (float) $actual_product->get_price(),
            'quantity' => 1,
            'currency' => get_woocommerce_currency(),
            'index' => $index, // Important for GA4 position tracking
        ];

        // Add SKU
        if ($actual_product->get_sku()) {
            $product_data['item_sku'] = $actual_product->get_sku();
        }

        // Add discount information if product is on sale
        if ($actual_product->is_on_sale()) {
            $regular_price = (float) $actual_product->get_regular_price();
            $sale_price = (float) $actual_product->get_sale_price();
            if ($regular_price > $sale_price) {
                $product_data['discount'] = $regular_price - $sale_price;
            }
        }

        // Add stock status
        $product_data['item_stock_status'] = $actual_product->is_in_stock() ? 'in_stock' : 'out_of_stock';

        // Add product type
        $product_data['item_type'] = $actual_product->get_type(); // simple, variable, grouped, etc.

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

        // Add location ID if you have multiple stores/warehouses

        // Add custom fields if available
        $custom_fields = [
            '_product_condition' => 'item_condition', // new, used, refurbished
            '_product_google_category' => 'google_product_category',
            '_product_gtin' => 'item_gtin',
            '_product_mpn' => 'item_mpn',
        ];

        foreach ($custom_fields as $meta_key => $item_key) {
            $meta_value = get_post_meta($actual_product->get_id(), $meta_key, true);
            if (!empty($meta_value)) {
                $product_data[$item_key] = $meta_value;
            }
        }

        // Remove empty values
        return array_filter($product_data, function ($value) {
            return $value !== '' && $value !== null && $value !== 0;
        });
    }

    /**
     * Check if time-based encryption is available (WordPress salts configured)
     * 
     * @return bool True if time-based encryption is available
     */
    private function is_time_based_encryption_available()
    {
        try {
            // Check if WordPress option values are configured
            $auth_key = get_option('ga4_time_based_auth_key', '');
            $salt = get_option('ga4_time_based_salt', '');
            $auth_key_available = !empty($auth_key);
            $salt_available = !empty($salt);
            
            // Also check if user has explicitly enabled it (default to false for safety)
            $user_enabled = get_option('ga4_time_based_encryption_enabled', false);
            
            return $auth_key_available && $salt_available && $user_enabled;
        } catch (\Exception $e) {
            // Log the error and return false as safe default
            error_log('GA4 Time-based encryption check failed: ' . $e->getMessage());
            return false;
        }
    }

}
