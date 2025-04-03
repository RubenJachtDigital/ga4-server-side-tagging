<?php

/**
 * WooCommerce integration for GA4 Server-Side Tagging.
 *
 * @since      1.0.0
 * @version    1.1.0
 * @package    GA4_Server_Side_Tagging
 */

if (! defined('WPINC')) {
    die;
}

/**
 * WooCommerce integration for GA4 Server-Side Tagging.
 *
 * This class handles the integration with WooCommerce for tracking e-commerce events.
 *
 * @since      1.0.0
 */
class GA4_Server_Side_Tagging_WooCommerce
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
     * Initialize the class.
     *
     * @since    1.0.0
     * @param    GA4_Server_Side_Tagging_Logger    $logger    The logger instance.
     */
    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Register additional WooCommerce hooks.
     * 
     * @since    1.0.0
     */
    public function register_hooks()
    {
        // Track direct checkout from product page
        add_action('woocommerce_before_checkout_form', array($this, 'check_direct_checkout'), 5);

        // Track AJAX add to cart
        add_action('wp_footer', array($this, 'add_ajax_tracking_script'));

        // Track "Buy Now" buttons that go directly to checkout
        add_action('woocommerce_after_add_to_cart_button', array($this, 'track_buy_now_buttons'), 50);

        // Track cart page view
        add_action('woocommerce_before_cart', array($this, 'track_view_cart'), 50);

        // Track item removal from cart
        add_action('woocommerce_cart_item_removed', array($this, 'track_remove_from_cart'), 10, 2);

        // Track product list views
        add_action('woocommerce_before_shop_loop', array($this, 'track_view_item_list'));

        // Track payment info
        add_action('woocommerce_after_checkout_form', array($this, 'add_payment_info_tracking'));

        // Track shipping info
        add_action('woocommerce_review_order_before_payment', array($this, 'track_add_shipping_info'));

        // Track begin checkout
        add_action('woocommerce_before_checkout_form', array($this, 'track_begin_checkout'), 10);
        add_action('gform_after_submission_3', array($this, 'track_quote'), 60, 1);
    }

    /**
     * Check if user went directly to checkout and track products in cart.
     * 
     * @since    1.0.0
     */
    public function check_direct_checkout()
    {
        // Only run once per session
        if (WC()->session && ! WC()->session->get('ga4_checkout_tracked', false)) {
            WC()->session->set('ga4_checkout_tracked', true);

            // Check if there are items in cart but no add_to_cart event was logged
            if (! WC()->cart->is_empty()) {
                $this->logger->info('Direct checkout detected - tracking cart items');

                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $product = $cart_item['data'];
                    $product_id = $product->get_id();
                    $variation_id = $cart_item['variation_id'];
                    $quantity = $cart_item['quantity'];

                    $this->logger->info('Tracking direct checkout item: ' . $product->get_name() . ' (Qty: ' . $quantity . ')');

                    // Track each item as an add_to_cart event
                    $this->track_add_to_cart($product_id, $quantity, $variation_id);
                }
            }
        }
    }

    /**
     * Add JavaScript to track AJAX add to cart events.
     * 
     * @since    1.0.0
     */
    public function add_ajax_tracking_script()
    {
        if (! get_option('ga4_ecommerce_tracking', true)) {
            return;
        }

?>
        <script>
            jQuery(function($) {
                // Track AJAX add to cart events
                $(document.body).on('added_to_cart', function(event, fragments, cart_hash, $button) {
                    if (typeof ga4ServerSideTagging !== 'undefined' && typeof ga4ServerSideTagging.trackEvent === 'function') {
                        // Already handled by the main JS file
                        return;
                    }

                    if (typeof gtag !== 'function') {
                        return;
                    }

                    var productData = {};

                    if ($button && $button.length) {
                        var $product = $button.closest('.product');
                        var productId = $button.data('product_id') || '';
                        var productName = $product.find('.woocommerce-loop-product__title').text() || 'Product';
                        var productPrice = $button.data('product_price') || 0;
                        var quantity = $button.data('quantity') || 1;

                        productData = {
                            item_id: productId,
                            item_name: productName,
                            price: parseFloat(productPrice),
                            quantity: parseInt(quantity)
                        };

                        console.log('[GA4] Tracking AJAX add_to_cart event', productData);

                        gtag('event', 'add_to_cart', {
                            currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                            value: productData.price * productData.quantity,
                            items: [productData]
                        });
                    }
                });

                // Track "Buy Now" buttons
                $(document.body).on('click', '.single_add_to_cart_button', function() {
                    var $form = $(this).closest('form.cart');
                    if ($form.find('input[name="wc-buy-now"]').length || $(this).hasClass('buy-now')) {
                        console.log('[GA4] Buy Now button clicked - tracking as add_to_cart');
                        // The add_to_cart event will be tracked by the regular WooCommerce hook
                    }
                });
            });
        </script>
    <?php
    }

    /**
     * Track "Buy Now" buttons that go directly to checkout.
     * 
     * @since    1.0.0
     */
    public function track_buy_now_buttons()
    {
        global $product;

        if (! is_object($product)) {
            return;
        }

        // Add data attributes to help with tracking
    ?>
        <script>
            jQuery(function($) {
                // Add data attributes to Buy Now buttons
                $('.single_add_to_cart_button.buy-now, input[name="wc-buy-now"], .direct-inschrijven, .add-request-quote-button').each(function() {
                    $(this).attr('data-ga4-buy-now', 'true');
                    $(this).attr('data-ga4-product-id', '<?php echo esc_js($product->get_id()); ?>');
                    $(this).attr('data-ga4-product-name', '<?php echo esc_js($product->get_name()); ?>');
                    $(this).attr('data-ga4-product-price', '<?php echo esc_js($product->get_price()); ?>');
                });
            });
        </script>
    <?php
    }

    /**
     * Track product view event.
     *
     * @since    1.0.0
     */
    public function track_product_view()
    {
        global $product;

        if (! is_object($product)) {
            return;
        }

        $product_data = $this->get_product_data($product);
        $this->logger->info('Product view: ' . $product->get_name());

        // Add script to track view_item event
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof gtag === 'function') {
                    gtag('event', 'view_item', {
                        currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                        value: <?php echo esc_js($product_data['price']); ?>,
                        items: [<?php echo wp_json_encode($product_data); ?>]
                    });
                }
            });
        </script>
    <?php
    }

    /**
     * Check if tracking should be enabled.
     *
     * @since    1.0.0
     * @return   boolean    Whether tracking should be enabled.
     */
    private function should_track()
    {
        // Check if we have a measurement ID
        $measurement_id = get_option('ga4_measurement_id', '');
        if (empty($measurement_id)) {
            return false;
        }

        // Check if we should track logged-in users
        if (is_user_logged_in() && ! get_option('ga4_track_logged_in_users', true)) {
            return false;
        }

        return true;
    }

    /**
     * Track add to cart events.
     *
     * @since    1.0.0
     * @param    int    $product_id    Product ID.
     * @param    int    $quantity      Quantity.
     * @param    int    $variation_id  Variation ID.
     */
    public function track_add_to_cart($product_id, $quantity = 1, $variation_id = 0)
    {
        // Check if we should track this event
        if (! $this->should_track()) {
            return;
        }

        // Get product data
        $product = wc_get_product($variation_id ? $variation_id : $product_id);
        if (! $product) {
            $this->logger->error('Failed to get product data for add_to_cart event. Product ID: ' . $product_id);
            return;
        }

        // Log the add to cart event
        $this->logger->info('Add to cart: ' . $product->get_name() . ' (ID: ' . $product->get_id() . ', Qty: ' . $quantity . ')');

        // Get product data
        $product_data = $this->get_product_data($product);
        $product_data['quantity'] = $quantity;

        // Get Cloudflare worker URL
        $cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');
        $use_server_side = get_option('ga4_use_server_side', true);
        $debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);

        // If server-side tracking is enabled and we have a Cloudflare worker URL
        if ($use_server_side && ! empty($cloudflare_worker_url)) {
            // Prepare event data
            $event_data = array(
                'name' => 'add_to_cart',
                'params' => array(
                    'items' => array($product_data),
                    'currency' => get_woocommerce_currency(),
                    'value' => $product->get_price() * $quantity,
                )
            );

            // Add client ID if available
            if (isset($_COOKIE['_ga'])) {
                $ga_cookie = $_COOKIE['_ga'];
                $parts = explode('.', $ga_cookie);
                if (count($parts) >= 4) {
                    $client_id = $parts[2] . '.' . $parts[3];
                    $event_data['params']['client_id'] = $client_id;
                }
            }

            // Add user ID if available
            if (is_user_logged_in()) {
                $event_data['params']['user_id'] = get_current_user_id();
            }

            // Send event to Cloudflare worker
            $response = wp_remote_post(
                $cloudflare_worker_url,
                array(
                    'headers' => array('Content-Type' => 'application/json'),
                    'body' => json_encode($event_data),
                    'timeout' => 5,
                )
            );

            // Log response for debugging
            if ($debug_mode) {
                if (is_wp_error($response)) {
                    $this->logger->error('Error sending add_to_cart event to Cloudflare worker: ' . $response->get_error_message());
                } else {
                    $this->logger->info('Add to cart event sent to Cloudflare worker. Response: ' . wp_remote_retrieve_body($response));
                }
            }
        }

        // Add JavaScript to track the event on the client side
        $currency = esc_js($product_data['currency'] ?? get_woocommerce_currency());
        $value = esc_js($product_data['price'] * $quantity);
        $item_id = esc_js($product_data['item_id']);
        $item_name = esc_js($product_data['item_name']);
        $price = esc_js($product_data['price']);
        $debug = $debug_mode ? 'true' : 'false';

        $script = <<<EOTJS
<script>
(function() {
    // Check if gtag is available
    if (typeof gtag === 'function') {
        // Track add to cart event
        gtag('event', 'add_to_cart', {
            currency: '{$currency}',
            value: {$value},
            items: [{
                item_id: '{$item_id}',
                item_name: '{$item_name}',
                price: {$price},
                quantity: {$quantity}
            }]
        });
        
        if ({$debug}) {
            console.log('[GA4 Server-Side Tagging] Add to cart event tracked', {
                currency: '{$currency}',
                value: {$value},
                items: [{
                    item_id: '{$item_id}',
                    item_name: '{$item_name}',
                    price: {$price},
                    quantity: {$quantity}
                }]
            });
        }
    } else if ({$debug}) {
        console.error('[GA4 Server-Side Tagging] gtag not available for add_to_cart event');
    }
})();
</script>
EOTJS;

        // Add the script to the footer
        add_action('wp_footer', function () use ($script) {
            echo $script;
        });
    }

    /**
     * Track checkout step.
     *
     * @since    1.0.0
     */
    public function track_checkout_step()
    {
        $cart_items = array();
        $cart_total = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $product_data = $this->get_product_data($product);
            $product_data['quantity'] = $cart_item['quantity'];
            $cart_items[] = $product_data;
            $cart_total += $product_data['price'] * $cart_item['quantity'];
        }

        $this->logger->info('Begin checkout: ' . count($cart_items) . ' items, total: ' . $cart_total);

        // Send server-side event
        $this->send_server_side_event('begin_checkout', array(
            'currency' => get_woocommerce_currency(),
            'value' => $cart_total,
            'items' => $cart_items,
        ));

        // Add JavaScript to track client-side event
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof gtag === 'function') {
                    gtag('event', 'begin_checkout', {
                        currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                        value: <?php echo esc_js($cart_total); ?>,
                        items: <?php echo wp_json_encode($cart_items); ?>
                    });
                }
            });
        </script>
    <?php
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
                    'parent_id'    => $parent_id ? $parent_id : $variation_id,
                ];
            }
            return $results;
        }
        return [];
    }

    /**
     * Track quote event for Gravity Forms.
     *
     * @since    1.1.0
     * @param    array    $entry     The Gravity Forms entry.
     * @param    array    $form      The Gravity Forms form object.
     * @param    mixed    $result    The result of form submission.
     */
    public function track_quote($entry, $form)
    {
        // Log the method call for debugging
        $this->logger->info('track_quote method called', [
            'form_id' => $form['id'],
            'entry_id' => $entry['id']
        ]);

        // Validate the form ID if needed
        if ($form['id'] !== 3) {
            $this->logger->warning('Track quote not triggered - incorrect form ID', [
                'expected_form_id' => 3,
                'actual_form_id' => $form['id']
            ]);
            return;
        }

        $cart_items = $this->get_raq_cart_data();

        // Log cart items for debugging
        $this->logger->info('Quote cart items retrieved', [
            'cart_items_count' => count($cart_items)
        ]);

        if (empty($cart_items)) {
            $this->logger->warning('No cart items found for quote tracking');
            return;
        }

        $total = 0;
        $items = [];
        $order_number = $entry['id']; // Use Gravity Forms entry ID instead of random number

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
            $product_data = $this->get_product_data($product);
            $items[] = $product_data;
        }

        // Prevent duplicate tracking
        if (get_post_meta($entry['id'], '_ga4_quote_tracked', true)) {
            $this->logger->info('Quote already tracked', [
                'entry_id' => $entry['id']
            ]);
            return;
        }

        $this->logger->info('Quote tracked', [
            'order_number' => $order_number,
            'total' => $total,
            'items_count' => count($items)
        ]);

        // Send server-side event
        $this->send_server_side_event('purchase', array(
            'transaction_id' => $order_number,
            'affiliation' => get_bloginfo('name'),
            'currency' => 'EUR',
            'value' => $total,
            'tax' => 0,
            'shipping' => 0,
            'items' => $items,
        ));

        // Mark as tracked
        update_post_meta($entry['id'], '_ga4_quote_tracked', true);

        // Client-side tracking
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof gtag === 'function') {
                    gtag('event', 'purchase', {
                        transaction_id: '<?php echo esc_js($order_number); ?>',
                        affiliation: '<?php echo esc_js(get_bloginfo('name')); ?>',
                        currency: '<?php echo esc_js('EUR'); ?>',
                        value: <?php echo esc_js($total); ?>,
                        tax: <?php echo esc_js(0); ?>,
                        shipping: <?php echo esc_js(0); ?>,
                        items: <?php echo wp_json_encode($items); ?>
                    });
                    console.log('[GA4] Tracked generate_lead event');
                }
            });
        </script>
    <?php
    }
    /**
     * Track purchase event.
     *
     * @since    1.0.0
     * @param    int    $order_id    Order ID.
     */
    public function track_purchase($order_id)
    {
        // Check if we already processed this order to prevent duplicate events
        if (get_post_meta($order_id, '_ga4_purchase_tracked', true)) {
            return;
        }

        // Get order
        $order = wc_get_order($order_id);

        if (! $order) {
            return;
        }

        // Get order number (compatible with sequential order numbers)
        $order_number = method_exists($order, 'get_order_number') ? $order->get_order_number() : $order->get_id();

        // Get order items
        $items = array();
        $total_value = 0;

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();

            if (! $product) {
                continue;
            }

            $product_data = $this->get_product_data($product);
            $product_data['quantity'] = $item->get_quantity();
            $items[] = $product_data;
            $total_value += $product_data['price'] * $item->get_quantity();
        }

        $this->logger->info('Purchase: Order #' . $order_number . ', total: ' . $total_value);

        // Send server-side event
        $this->send_server_side_event('purchase', array(
            'transaction_id' => $order_number,
            'affiliation' => get_bloginfo('name'),
            'currency' => $order->get_currency(),
            'value' => $total_value,
            'tax' => $order->get_total_tax(),
            'shipping' => $order->get_shipping_total(),
            'items' => $items,
        ));

        // Mark order as tracked
        update_post_meta($order_id, '_ga4_purchase_tracked', true);

        // Add JavaScript to track client-side event
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof gtag === 'function') {
                    gtag('event', 'purchase', {
                        transaction_id: '<?php echo esc_js($order_number); ?>',
                        affiliation: '<?php echo esc_js(get_bloginfo('name')); ?>',
                        currency: '<?php echo esc_js($order->get_currency()); ?>',
                        value: <?php echo esc_js($total_value); ?>,
                        tax: <?php echo esc_js($order->get_total_tax()); ?>,
                        shipping: <?php echo esc_js($order->get_shipping_total()); ?>,
                        items: <?php echo wp_json_encode($items); ?>
                    });
                }
            });
        </script>
    <?php
    }

    /**
     * Get product data formatted for GA4.
     *
     * @since    1.0.0
     * @param    WC_Product    $product    The product object.
     * @return   array                     Product data formatted for GA4.
     */
    private function get_product_data($product)
    {
        $categories = array();
        $category_ids = $product->get_category_ids();

        if (! empty($category_ids)) {
            foreach ($category_ids as $category_id) {
                $term = get_term_by('id', $category_id, 'product_cat');
                if ($term) {
                    $categories[] = $term->name;
                }
            }
        }

        return array(
            'item_id' => $product->get_id(),
            'item_name' => $product->get_name(),
            'item_brand' => $this->get_product_brand($product),
            'item_category' => isset($categories[0]) ? $categories[0] : '',
            'item_category2' => isset($categories[1]) ? $categories[1] : '',
            'item_category3' => isset($categories[2]) ? $categories[2] : '',
            'item_variant' => $product->get_type() === 'variation' ? $this->get_variation_name($product) : '',
            'price' => (float) $product->get_price(),
        );
    }

    /**
     * Get product brand.
     *
     * @since    1.0.0
     * @param    WC_Product    $product    The product object.
     * @return   string                    The product brand.
     */
    private function get_product_brand($product)
    {
        $brand = '';

        // Check for common brand taxonomies
        $brand_taxonomies = array('brand', 'product_brand', 'pwb-brand');

        foreach ($brand_taxonomies as $taxonomy) {
            if (taxonomy_exists($taxonomy)) {
                $terms = get_the_terms($product->get_id(), $taxonomy);
                if ($terms && ! is_wp_error($terms)) {
                    $brand = $terms[0]->name;
                    break;
                }
            }
        }

        return $brand;
    }

    /**
     * Get variation name.
     *
     * @since    1.0.0
     * @param    WC_Product_Variation    $variation    The variation object.
     * @return   string                               The variation name.
     */
    private function get_variation_name($variation)
    {
        $attributes = $variation->get_attributes();
        $variation_name = array();

        foreach ($attributes as $key => $value) {
            $taxonomy = str_replace('attribute_', '', $key);
            $term = get_term_by('slug', $value, $taxonomy);
            $variation_name[] = $term ? $term->name : $value;
        }

        return implode(', ', $variation_name);
    }

    /**
     * Send server-side event to GA4.
     *
     * @since    1.0.0
     * @param    string    $event_name    The event name.
     * @param    array     $event_data    The event data.
     */
    private function send_server_side_event($event_name, $event_data)
    {
        $measurement_id = get_option('ga4_measurement_id');
        $api_secret = get_option('ga4_api_secret');
        $cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');

        if (empty($measurement_id) || empty($api_secret)) {
            $this->logger->warning('Server-side event not sent: Missing measurement ID or API secret');
            return;
        }

        // Get client ID from cookie if available
        $client_id = isset($_COOKIE['_ga']) ? $this->extract_ga_client_id($_COOKIE['_ga']) : $this->generate_client_id();
        $this->logger->info('Using client ID: ' . $client_id . ' for event: ' . $event_name);

        // Prepare event data
        $payload = array(
            'client_id' => $client_id,
            'events' => array(
                array(
                    'name' => $event_name,
                    'params' => $event_data,
                ),
            ),
        );

        // Add user ID if logged in
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $payload['user_id'] = (string) $user_id;
            $this->logger->info('Added user ID: ' . $user_id . ' to event: ' . $event_name);
        }

        // Log the complete payload
        $this->logger->log_data($payload, 'GA4 Measurement Protocol payload for ' . $event_name, 'info');

        // Determine if we should use Cloudflare Worker or direct GA4 API
        if (!empty($cloudflare_worker_url)) {
            $this->logger->info('Sending event via Cloudflare Worker: ' . $cloudflare_worker_url);

            // Format for Cloudflare Worker
            $worker_payload = array(
                'name' => $event_name,
                'params' => $event_data
            );

            // Add client ID
            $worker_payload['params']['client_id'] = $client_id;

            // Add user ID if available
            if (isset($payload['user_id'])) {
                $worker_payload['params']['user_id'] = $payload['user_id'];
            }

            $this->logger->log_data($worker_payload, 'Cloudflare Worker payload for ' . $event_name, 'info');

            $response = wp_remote_post($cloudflare_worker_url, array(
                'method' => 'POST',
                'timeout' => 5,
                'redirection' => 5,
                'httpversion' => '1.1',
                'blocking' => true, // Set to true for debugging
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($worker_payload),
                'cookies' => array(),
            ));

            if (is_wp_error($response)) {
                $this->logger->error('Cloudflare Worker event error: ' . $response->get_error_message());
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                $this->logger->info('Cloudflare Worker response: ' . $response_code);
                $this->logger->log_data(json_decode($response_body, true), 'Cloudflare Worker response body', 'info');
            }
        } else {
            // Send directly to GA4 Measurement Protocol
            $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . $measurement_id . '&api_secret=' . $api_secret;
            $this->logger->info('Sending event directly to GA4 API: ' . $url);

            $response = wp_remote_post($url, array(
                'method' => 'POST',
                'timeout' => 5,
                'redirection' => 5,
                'httpversion' => '1.1',
                'blocking' => true, // Set to true for debugging
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($payload),
                'cookies' => array(),
            ));

            if (is_wp_error($response)) {
                $this->logger->error('GA4 API event error: ' . $response->get_error_message());
            } else {
                $response_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                $this->logger->info('GA4 API response: ' . $response_code);
                $this->logger->log_data(array('body' => $response_body), 'GA4 API response body', 'info');
            }
        }
    }

    /**
     * Extract client ID from GA cookie.
     *
     * @since    1.0.0
     * @param    string    $ga_cookie    The GA cookie value.
     * @return   string                  The client ID.
     */
    private function extract_ga_client_id($ga_cookie)
    {
        $parts = explode('.', $ga_cookie);
        if (count($parts) > 2) {
            return $parts[2] . '.' . $parts[3];
        }
        return $this->generate_client_id();
    }

    /**
     * Generate a random client ID.
     *
     * @since    1.0.0
     * @return   string    A random client ID.
     */
    private function generate_client_id()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Track view_cart event.
     *
     * @since    1.0.0
     */
    public function track_view_cart()
    {
        $cart_items = array();
        $cart_total = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $product_data = $this->get_product_data($product);
            $product_data['quantity'] = $cart_item['quantity'];
            $cart_items[] = $product_data;
            $cart_total += $product_data['price'] * $cart_item['quantity'];
        }

        $this->logger->info('View cart: ' . count($cart_items) . ' items, total: ' . $cart_total);

        // Send server-side event
        $this->send_server_side_event('view_cart', array(
            'currency' => get_woocommerce_currency(),
            'value' => $cart_total,
            'items' => $cart_items,
        ));

        // Add JavaScript to track client-side event
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof gtag === 'function') {
                    gtag('event', 'view_cart', {
                        currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                        value: <?php echo esc_js($cart_total); ?>,
                        items: <?php echo wp_json_encode($cart_items); ?>
                    });
                    console.log('[GA4] Tracked view_cart event');
                }
            });
        </script>
    <?php
    }

    /**
     * Track remove_from_cart event.
     *
     * @since    1.0.0
     * @param    string    $cart_item_key    Cart item key.
     * @param    WC_Cart   $cart             Cart object.
     */
    public function track_remove_from_cart($cart_item_key, $cart)
    {
        // Get the removed item from session
        $removed_item = WC()->session->get('removed_cart_contents')[$cart_item_key] ?? null;

        if (! $removed_item) {
            return;
        }

        $product = $removed_item['data'];
        $product_id = $product->get_id();
        $quantity = $removed_item['quantity'];

        if (! is_object($product)) {
            return;
        }

        $product_data = $this->get_product_data($product);
        $product_data['quantity'] = $quantity;

        $this->logger->info('Remove from cart: ' . $product->get_name() . ' (Qty: ' . $quantity . ')');
        $this->logger->log_data($product_data, 'Product data for remove_from_cart event');

        // Prepare event data
        $event_data = array(
            'currency' => get_woocommerce_currency(),
            'value' => $product_data['price'] * $quantity,
            'items' => array($product_data),
        );

        // Send server-side event
        $this->send_server_side_event('remove_from_cart', $event_data);

        // Add JavaScript for client-side tracking
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof gtag === 'function') {
                    gtag('event', 'remove_from_cart', {
                        currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                        value: <?php echo esc_js($product_data['price'] * $quantity); ?>,
                        items: [<?php echo wp_json_encode($product_data); ?>]
                    });
                    console.log('[GA4] Tracked remove_from_cart event');
                }
            });
        </script>
    <?php
    }

    /**
     * Track view_item_list event.
     *
     * @since    1.0.0
     */
    public function track_view_item_list()
    {
        global $wp_query;

        // Get current category or tag name
        $list_name = 'Product List';
        $list_id = '';

        if (is_product_category()) {
            $category = get_queried_object();
            $list_name = $category->name;
            $list_id = 'category_' . $category->term_id;
        } elseif (is_product_tag()) {
            $tag = get_queried_object();
            $list_name = $tag->name;
            $list_id = 'tag_' . $tag->term_id;
        } elseif (is_search()) {
            $list_name = 'Search Results';
            $list_id = 'search_' . get_search_query();
        } elseif (is_shop()) {
            $list_name = 'Shop Page';
            $list_id = 'shop';
        }

        // Get products in the current view
        $products = array();
        $items = array();

        if ($wp_query->have_posts()) {
            $index = 1;
            while ($wp_query->have_posts() && $index <= 10) { // Limit to 10 products
                $wp_query->the_post();
                global $product;

                if (! is_object($product)) {
                    continue;
                }

                $product_data = $this->get_product_data($product);
                $product_data['index'] = $index;
                $product_data['item_list_name'] = $list_name;
                $product_data['item_list_id'] = $list_id;

                $items[] = $product_data;
                $products[] = $product->get_id();
                $index++;
            }
            wp_reset_postdata();
        }

        if (empty($items)) {
            return;
        }

        $this->logger->info('View item list: ' . $list_name . ' (' . count($items) . ' items)');
        $this->logger->log_data($items, 'Items in view_item_list event');

        // Prepare event data
        $event_data = array(
            'item_list_name' => $list_name,
            'item_list_id' => $list_id,
            'items' => $items,
        );

        // Send server-side event
        $this->send_server_side_event('view_item_list', $event_data);

        // Add JavaScript for client-side tracking
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof gtag === 'function') {
                    gtag('event', 'view_item_list', <?php echo wp_json_encode($event_data); ?>);
                    console.log('[GA4] Tracked view_item_list event');

                    // Track select_item events when products are clicked
                    jQuery('.products .product a').on('click', function() {
                        var $product = jQuery(this).closest('.product');
                        var productId = $product.data('product_id') || $product.find('.add_to_cart_button, .direct-inschrijven, .add-request-quote-button').data('product_id');
                        var productIndex = $product.index() + 1;
                        var productName = $product.find('.woocommerce-loop-product__title').text();

                        if (productId) {
                            gtag('event', 'select_item', {
                                item_list_name: '<?php echo esc_js($list_name); ?>',
                                item_list_id: '<?php echo esc_js($list_id); ?>',
                                items: [{
                                    item_id: productId,
                                    item_name: productName,
                                    index: productIndex,
                                    item_list_name: '<?php echo esc_js($list_name); ?>',
                                    item_list_id: '<?php echo esc_js($list_id); ?>'
                                }]
                            });
                            console.log('[GA4] Tracked select_item event');
                        }
                    });
                }
            });
        </script>
    <?php
    }

    /**
     * Add payment info tracking script.
     *
     * @since    1.0.0
     */
    public function add_payment_info_tracking()
    {
        $cart_items = array();
        $cart_total = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $product_data = $this->get_product_data($product);
            $product_data['quantity'] = $cart_item['quantity'];
            $cart_items[] = $product_data;
            $cart_total += $product_data['price'] * $cart_item['quantity'];
        }

        if (empty($cart_items)) {
            return;
        }

        // Add JavaScript to track payment method selection
    ?>
        <script>
            jQuery(function($) {
                // Track when payment method is selected
                $('form.checkout').on('change', 'input[name="payment_method"]', function() {
                    var paymentMethod = $(this).val();

                    if (typeof gtag === 'function') {
                        gtag('event', 'add_payment_info', {
                            currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                            value: <?php echo esc_js($cart_total); ?>,
                            payment_type: paymentMethod,
                            items: <?php echo wp_json_encode($cart_items); ?>
                        });
                        console.log('[GA4] Tracked add_payment_info event for ' + paymentMethod);
                    }

                    // Send server-side event
                    fetch('<?php echo esc_js(get_option('ga4_cloudflare_worker_url', '')); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json'
                            },
                            body: JSON.stringify({
                                name: 'add_payment_info',
                                params: {
                                    currency: '<?php echo esc_js(get_woocommerce_currency()); ?>',
                                    value: <?php echo esc_js($cart_total); ?>,
                                    payment_type: paymentMethod,
                                    items: <?php echo wp_json_encode($cart_items); ?>,
                                    client_id: (function() {
                                        var match = document.cookie.match(/_ga=GA\d\.\d\.(\d+\.\d+)/);
                                        return match ? match[1] : '';
                                    })(),
                                    user_id: '<?php echo is_user_logged_in() ? esc_js(get_current_user_id()) : ''; ?>'
                                }
                            })
                        })
                        .then(function(response) {
                            if (<?php echo get_option('ga4_server_side_tagging_debug_mode', false) ? 'true' : 'false'; ?>) {
                                console.log('[GA4 Server-Side Tagging] Add payment info event sent to Cloudflare Worker');
                                response.json().then(function(data) {
                                    console.log('[GA4 Server-Side Tagging] Cloudflare Worker response:', data);
                                });
                            }
                        })
                        .catch(function(error) {
                            if (<?php echo get_option('ga4_server_side_tagging_debug_mode', false) ? 'true' : 'false'; ?>) {
                                console.error('[GA4 Server-Side Tagging] Error sending add payment info to Cloudflare Worker:', error);
                            }
                        });
                });
            });
        </script>
    <?php
    }

    /**
     * Track add_shipping_info event.
     *
     * @since    1.0.0
     */
    public function track_add_shipping_info()
    {
        // Only track once per session
        if (WC()->session && WC()->session->get('ga4_shipping_info_tracked', false)) {
            return;
        }

        // Mark as tracked
        if (WC()->session) {
            WC()->session->set('ga4_shipping_info_tracked', true);
        }

        $cart_items = array();
        $cart_total = 0;
        $shipping_tier = '';

        // Get selected shipping method
        $chosen_shipping_methods = WC()->session->get('chosen_shipping_methods');
        if (! empty($chosen_shipping_methods)) {
            $shipping_tier = $chosen_shipping_methods[0];
        }

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $product_data = $this->get_product_data($product);
            $product_data['quantity'] = $cart_item['quantity'];
            $cart_items[] = $product_data;
            $cart_total += $product_data['price'] * $cart_item['quantity'];
        }

        if (empty($cart_items)) {
            return;
        }

        $this->logger->info('Add shipping info: ' . $shipping_tier);

        // Prepare event data
        $event_data = array(
            'currency' => get_woocommerce_currency(),
            'value' => $cart_total,
            'shipping_tier' => $shipping_tier,
            'items' => $cart_items,
        );

        // Send server-side event
        $this->send_server_side_event('add_shipping_info', $event_data);

        // Add JavaScript for client-side tracking
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof gtag === 'function') {
                    gtag('event', 'add_shipping_info', <?php echo wp_json_encode($event_data); ?>);
                    console.log('[GA4] Tracked add_shipping_info event');
                }
            });
        </script>
    <?php
    }

    /**
     * Track begin_checkout event.
     *
     * @since    1.0.1
     */
    public function track_begin_checkout()
    {
        // Only track once per session
        if (WC()->session && WC()->session->get('ga4_begin_checkout_tracked', false)) {
            return;
        }

        // Mark as tracked
        if (WC()->session) {
            WC()->session->set('ga4_begin_checkout_tracked', true);
        }

        $cart_items = array();
        $cart_total = 0;

        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            if (! is_object($product)) {
                continue;
            }

            $product_data = $this->get_product_data($product);
            $product_data['quantity'] = $cart_item['quantity'];
            $cart_items[] = $product_data;
            $cart_total += $product_data['price'] * $cart_item['quantity'];
        }

        if (empty($cart_items)) {
            return;
        }

        $this->logger->info('Begin checkout: ' . count($cart_items) . ' items, total: ' . $cart_total);

        // Prepare event data
        $event_data = array(
            'currency' => get_woocommerce_currency(),
            'value' => $cart_total,
            'items' => $cart_items,
        );

        // Send server-side event
        $this->send_server_side_event('begin_checkout', $event_data);

        // Add JavaScript for client-side tracking
    ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                if (typeof gtag === 'function') {
                    gtag('event', 'begin_checkout', <?php echo wp_json_encode($event_data); ?>);
                    console.log('[GA4] Tracked begin_checkout event');
                }
            });
        </script>
<?php
    }
}
