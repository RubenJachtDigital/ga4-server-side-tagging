<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @since      1.0.0
 * @package    GA4_Server_Side_Tagging
 */

if (! defined('WPINC')) {
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
        if (is_user_logged_in() && ! get_option('ga4_track_logged_in_users', true)) {
            return;
        }

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
            'apiEndpoint' => rest_url('ga4-server-side-tagging/v1/collect'),
            'nonce' => wp_create_nonce('wp_rest'),
            'isEcommerceEnabled' => get_option('ga4_ecommerce_tracking', true),
            'cloudflareWorkerUrl' => get_option('ga4_cloudflare_worker_url', ''),
            'currency' => get_woocommerce_currency(),
        );

        // Add order data for purchase event if on the order received page
        if (is_wc_endpoint_url('order-received') && isset($_GET['key'])) {
            $order_id = wc_get_order_id_by_order_key(wc_clean(wp_unslash($_GET['key'])));

            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $script_data['orderData'] = $this->get_order_data_for_tracking($order);
                    $this->logger->info('Added order data for purchase event tracking. Order ID: ' . $order_id);
                }
            }
        }

        // Pass data to the script
        wp_localize_script(
            'ga4-server-side-tagging-public',
            'ga4ServerSideTagging',
            $script_data
        );
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
        if (empty($measurement_id)) {
            return;
        }

        // Check if we should track logged-in users
        if (is_user_logged_in() && ! get_option('ga4_track_logged_in_users', true)) {
            return;
        }

        // Get settings
        $use_server_side = get_option('ga4_use_server_side', true);
        $anonymize_ip = get_option('ga4_anonymize_ip', true);
        $debug_mode = get_option('ga4_server_side_tagging_debug_mode', false);
        $cloudflare_worker_url = get_option('ga4_cloudflare_worker_url', '');

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

            <?php if ($anonymize_ip) : ?>
                gtag('set', 'anonymize_ip', true);
            <?php endif; ?>

            <?php if ($debug_mode) : ?>
                gtag('config', '<?php echo esc_js($measurement_id); ?>', {
                    'debug_mode': true
                });
            <?php else : ?>
                gtag('config', '<?php echo esc_js($measurement_id); ?>');
            <?php endif; ?>

            <?php if ($use_server_side && ! empty($cloudflare_worker_url)) : ?>
                // Configure server-side endpoint
                gtag('config', '<?php echo esc_js($measurement_id); ?>', {
                    'transport_url': '<?php echo esc_js($cloudflare_worker_url); ?>',
                    'first_party_collection': true
                });

                // Send page_view event to Cloudflare Worker
                (function() {
                    // Get client ID from cookie or generate a new one
                    var clientId = '';
                    var match = document.cookie.match(/_ga=GA\d\.\d\.(\d+\.\d+)/);
                    if (match) {
                        clientId = match[1];
                    } else {
                        clientId = Math.random().toString(36).substring(2, 15) +
                            Math.random().toString(36).substring(2, 15) +
                            Math.random().toString(36).substring(2, 15) +
                            Math.random().toString(36).substring(2, 15);
                    }

                    <?php if (is_product()) : ?>
                        // For product pages, send view_item event instead of page_view
                        var productData = <?php echo wp_json_encode($this->get_current_product_data()); ?>;

                        // Prepare view_item event data
                        var viewItemData = {
                            name: 'view_item',
                            params: {
                                currency: '<?php echo get_woocommerce_currency(); ?>',
                                value: productData.price,
                                items: [productData],
                                page_title: '<?php echo esc_js(get_the_title()); ?>',
                                page_location: '<?php echo esc_js(get_permalink()); ?>',
                                page_path: '<?php echo esc_js(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)); ?>',
                                client_id: clientId
                            }
                        };

                        <?php if (is_user_logged_in()) : ?>
                            // Add user ID for logged-in users
                            viewItemData.params.user_id = '<?php echo esc_js(get_current_user_id()); ?>';
                        <?php endif; ?>

                        // Send to Cloudflare Worker
                        fetch('<?php echo esc_js(get_option('ga4_cloudflare_worker_url', '')); ?>', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify(viewItemData)
                            })
                            .then(function(response) {
                                if (<?php echo get_option('ga4_server_side_tagging_debug_mode', false) ? 'true' : 'false'; ?>) {
                                    console.log('[GA4 Server-Side Tagging] View item event sent to Cloudflare Worker', viewItemData);
                                    response.json().then(function(data) {
                                        console.log('[GA4 Server-Side Tagging] Cloudflare Worker response:', data);
                                    });
                                }
                            })
                            .catch(function(error) {
                                if (<?php echo get_option('ga4_server_side_tagging_debug_mode', false) ? 'true' : 'false'; ?>) {
                                    console.error('[GA4 Server-Side Tagging] Error sending view item to Cloudflare Worker:', error);
                                }
                            });
                    <?php else : ?>
                        // For non-product pages, send regular page_view event
                        // Prepare page view data
                        var pageViewData = {
                            name: 'page_view',
                            params: {
                                page_title: '<?php echo esc_js(get_the_title()); ?>',
                                page_location: '<?php echo esc_js(get_permalink()); ?>',
                                page_path: '<?php echo esc_js(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)); ?>',
                                client_id: clientId
                            }
                        };

                        <?php if (is_user_logged_in()) : ?>
                            // Add user ID for logged-in users
                            pageViewData.params.user_id = '<?php echo esc_js(get_current_user_id()); ?>';
                        <?php endif; ?>

                        // Send to Cloudflare Worker
                        fetch('<?php echo esc_js(get_option('ga4_cloudflare_worker_url', '')); ?>', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json'
                                },
                                body: JSON.stringify(pageViewData)
                            })
                            .then(function(response) {
                                if (<?php echo get_option('ga4_server_side_tagging_debug_mode', false) ? 'true' : 'false'; ?>) {
                                    console.log('[GA4 Server-Side Tagging] Page view sent to Cloudflare Worker', pageViewData);
                                    response.json().then(function(data) {
                                        console.log('[GA4 Server-Side Tagging] Cloudflare Worker response:', data);
                                    });
                                }
                            })
                            .catch(function(error) {
                                if (<?php echo get_option('ga4_server_side_tagging_debug_mode', false) ? 'true' : 'false'; ?>) {
                                    console.error('[GA4 Server-Side Tagging] Error sending page view to Cloudflare Worker:', error);
                                }
                            });
                    <?php endif; ?>
                })();
            <?php endif; ?>

            <?php if (is_user_logged_in()) : ?>
                // Set user ID for logged-in users
                gtag('set', 'user_id', '<?php echo esc_js(get_current_user_id()); ?>');
            <?php endif; ?>

            <?php
            // Add purchase event tracking on the order received page
            if (is_wc_endpoint_url('order-received') && isset($_GET['key'])) :
                $order_id = wc_get_order_id_by_order_key(wc_clean(wp_unslash($_GET['key'])));
                if ($order_id) :
                    $order = wc_get_order($order_id);
                    if ($order) :
                        $order_data = $this->get_order_data_for_tracking($order);
                        $order_json = wp_json_encode($order_data);
            ?>
                        // Track purchase event
                        gtag('event', 'purchase', <?php echo $order_json; ?>);

                        <?php if ($use_server_side && !empty($cloudflare_worker_url)) : ?>
                                // Send purchase event to Cloudflare Worker
                                (function() {
                                    // Get client ID
                                    var clientId = '';
                                    var match = document.cookie.match(/_ga=GA\d\.\d\.(\d+\.\d+)/);
                                    if (match) {
                                        clientId = match[1];
                                    } else {
                                        clientId = Math.random().toString(36).substring(2, 15) +
                                            Math.random().toString(36).substring(2, 15);
                                    }

                                    // Prepare purchase data
                                    var purchaseData = {
                                        name: 'purchase',
                                        params: <?php echo $order_json; ?>
                                    };

                                    // Add client ID
                                    purchaseData.params.client_id = clientId;

                                    <?php if (is_user_logged_in()) : ?>
                                        // Add user ID for logged-in users
                                        purchaseData.params.user_id = '<?php echo esc_js(get_current_user_id()); ?>';
                                    <?php endif; ?>

                                    // Send to Cloudflare Worker
                                    fetch('<?php echo esc_js($cloudflare_worker_url); ?>', {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json'
                                            },
                                            body: JSON.stringify(purchaseData)
                                        })
                                        .then(function(response) {
                                            if (<?php echo $debug_mode ? 'true' : 'false'; ?>) {
                                                console.log('[GA4 Server-Side Tagging] Purchase event sent to Cloudflare Worker', purchaseData);
                                                response.json().then(function(data) {
                                                    console.log('[GA4 Server-Side Tagging] Cloudflare Worker response:', data);
                                                });
                                            }
                                        })
                                        .catch(function(error) {
                                            if (<?php echo $debug_mode ? 'true' : 'false'; ?>) {
                                                console.error('[GA4 Server-Side Tagging] Error sending purchase event to Cloudflare Worker:', error);
                                            }
                                        });
                                })();
                        <?php endif; // if ($use_server_side && !empty($cloudflare_worker_url)) 
                        ?>

            <?php
                    endif; // if ($order)
                endif; // if ($order_id)
            endif; // if (is_wc_endpoint_url('order-received') && isset($_GET['key']))
            ?>
            <?php
            // Add purchase event tracking on the order received page
            if (isset($_POST['input_2']) && !empty($_POST['input_2']) && get_the_ID() == 82850) :
                $order_data = $this->get_order_quote_data_for_tracking();
                $order_json = wp_json_encode($order_data);
            ?>
                // Track purchase event
                gtag('event', 'purchase', <?php echo $order_json; ?>);

                <?php if ($use_server_side && !empty($cloudflare_worker_url)) : ?>
                        // Send purchase event to Cloudflare Worker
                        (function() {
                            // Get client ID
                            var clientId = '';
                            var match = document.cookie.match(/_ga=GA\d\.\d\.(\d+\.\d+)/);
                            if (match) {
                                clientId = match[1];
                            } else {
                                clientId = Math.random().toString(36).substring(2, 15) +
                                    Math.random().toString(36).substring(2, 15);
                            }

                            // Prepare purchase data
                            var purchaseData = {
                                name: 'purchase',
                                params: <?php echo $order_json; ?>
                            };

                            // Add client ID
                            purchaseData.params.client_id = clientId;

                            <?php if (is_user_logged_in()) : ?>
                                // Add user ID for logged-in users
                                purchaseData.params.user_id = '<?php echo esc_js(get_current_user_id()); ?>';
                            <?php endif; ?>

                            // Send to Cloudflare Worker
                            fetch('<?php echo esc_js($cloudflare_worker_url); ?>', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json'
                                    },
                                    body: JSON.stringify(purchaseData)
                                })
                                .then(function(response) {
                                    if (<?php echo $debug_mode ? 'true' : 'false'; ?>) {
                                        console.log('[GA4 Server-Side Tagging] Purchase event sent to Cloudflare Worker', purchaseData);
                                        response.json().then(function(data) {
                                            console.log('[GA4 Server-Side Tagging] Cloudflare Worker response:', data);
                                        });
                                    }
                                })
                                .catch(function(error) {
                                    if (<?php echo $debug_mode ? 'true' : 'false'; ?>) {
                                        console.error('[GA4 Server-Side Tagging] Error sending purchase event to Cloudflare Worker:', error);
                                    }
                                });
                        })();
                <?php endif; // if ($use_server_side && !empty($cloudflare_worker_url)) 
                ?>

            <?php
            endif; // isset($_POST['input_2']) && !empty($_POST['input_2']) && get_the_ID() == 82850
            ?>
        </script>
        <!-- End GA4 Server-Side Tagging -->
<?php
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
            if (! $product) {
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
                if (! empty($categories)) {
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
        if (! empty($shipping_methods)) {
            $shipping_method = reset($shipping_methods);
            $order_data['shipping_tier'] = $shipping_method->get_method_title();
        }

        return $order_data;
    }

    private static function get_transient_user_id()
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

    private static function get_raq_cart_data()
    {
        $transient_key = self::get_transient_user_id();
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

    private function get_order_quote_data_for_tracking()
    {
        $total = 0;
        $items = [];
        $order_number = date('ym') . sprintf('%08d', mt_rand(10000000, 99999999));

        $this->logger->info('Preparing order data for request a quote event. Order #' . $order_number);

        $cart_items = self::get_raq_cart_data();

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
                'quantity' => $product->get_quantity(),
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
                if (! empty($categories)) {
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

        // Get product data
        $product_data = [
            'item_id' => $product->get_id(),
            'item_name' => $product->get_name(),
            'price' => (float) $product->get_price(),
        ];

        // Add optional parameters if available
        if ($product->get_sku()) {
            $product_data['item_sku'] = $product->get_sku();
        }

        if ($product->get_category_ids()) {
            $categories = [];
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

        // If it's a variable product, add variant info
        if ($product->is_type('variable')) {
            $product_data['item_variant'] = 'Variable Product';
        }

        return $product_data;
    }
}
