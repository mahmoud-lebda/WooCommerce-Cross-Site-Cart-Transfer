<?php
/**
 * Cart Handler for Cross-Site Cart Transfer
 * Manages cart operations on the target site
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cross_Site_Cart_Handler {

    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Initialize WooCommerce for API requests
        add_action('rest_api_init', array($this, 'init_woocommerce_for_api'));
        
        // Modify cart item prices for transferred products
        add_action('woocommerce_before_calculate_totals', array($this, 'modify_transferred_item_prices'));
        
        // Add transfer information to cart items
        add_filter('woocommerce_get_item_data', array($this, 'add_transfer_info_to_cart'), 10, 2);
        
        // Save transfer meta to order items
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_transfer_meta_to_order'), 10, 4);
        
        // Prevent quantity changes for transferred products
        add_filter('woocommerce_cart_item_quantity', array($this, 'disable_quantity_change_for_transferred_products'), 10, 3);
        add_filter('woocommerce_cart_item_remove_link', array($this, 'disable_remove_link_for_transferred_products'), 10, 2);
        
        // Add CSS to hide quantity controls
        add_action('wp_head', array($this, 'add_cart_restrictions_css'));
    }

    /**
     * Initialize WooCommerce for REST API requests
     */
    public function init_woocommerce_for_api() {
        // Ensure WooCommerce is properly initialized for API requests
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $this->ensure_woocommerce_loaded();
        }
    }

    /**
     * Ensure WooCommerce is fully loaded and cart is available
     */
    private function ensure_woocommerce_loaded() {
        if (!class_exists('WooCommerce')) {
            return false;
        }

        // Initialize WooCommerce if not already done
        if (!did_action('woocommerce_loaded')) {
            WC();
        }

        // Start session if needed
        if (!WC()->session) {
            WC()->session = new WC_Session_Handler();
            WC()->session->init();
        }

        // Initialize customer
        if (!WC()->customer) {
            WC()->customer = new WC_Customer();
        }

        // Initialize cart
        if (!WC()->cart) {
            WC()->cart = new WC_Cart();
        }

        // Ensure session is started and cart is loaded from session
        if (!WC()->session->get_session_cookie()) {
            WC()->session->set_customer_session_cookie(true);
        }

        // Load cart from session
        WC()->cart->get_cart_from_session();
        
        return true;
    }

    /**
     * Add product to target site cart
     */
    public function add_product_to_cart($product_data) {
        try {
            // Ensure WooCommerce is loaded
            if (!$this->ensure_woocommerce_loaded()) {
                throw new Exception('WooCommerce not available');
            }

            // Clear existing cart to ensure clean transfer
            WC()->cart->empty_cart();

            // Find or create product
            $product_id = $this->find_or_create_product($product_data);
            if (!$product_id) {
                throw new Exception('Failed to find or create product');
            }

            // Validate product
            $product = wc_get_product($product_id);
            if (!$product || !$product->exists()) {
                throw new Exception('Product does not exist');
            }

            // Ensure product is purchasable
            if (!$product->is_purchasable()) {
                $product->set_status('publish');
                $product->set_catalog_visibility('hidden');
                $product->save();
            }

            // Prepare cart item data with original price
            $cart_item_data = array(
                '_cross_site_transfer' => true,
                '_cross_site_source' => $product_data['source_site'],
                '_cross_site_original_price' => $product_data['price'],
                '_cross_site_original_id' => $product_data['original_product_id'],
                '_cross_site_transfer_time' => current_time('mysql'),
                '_cross_site_transfer_meta' => $product_data['meta_data'] ?? array()
            );

            // Set the product price to the original price BEFORE adding to cart
            $product->set_price($product_data['price']);
            $product->set_regular_price($product_data['price']);
            
            // Add product to cart
            $cart_item_key = WC()->cart->add_to_cart(
                $product_id,
                $product_data['quantity'],
                $product_data['variation_id'] ?? 0,
                $product_data['variation_data'] ?? array(),
                $cart_item_data
            );

            if (!$cart_item_key) {
                $notices = wc_get_notices('error');
                $error_message = 'Failed to add product to cart';
                
                if (!empty($notices)) {
                    $error_message .= ': ' . $notices[0]['notice'];
                    wc_clear_notices();
                }
                
                throw new Exception($error_message);
            }

            // Force the cart item to use original price
            $cart_item = WC()->cart->get_cart_item($cart_item_key);
            if ($cart_item) {
                $cart_item['data']->set_price($product_data['price']);
            }

            // Calculate totals
            WC()->cart->calculate_totals();
            
            // Save session data
            WC()->session->save_data();

            // Force session update
            if (method_exists(WC()->session, 'set_customer_session_cookie')) {
                WC()->session->set_customer_session_cookie(true);
            }

            return array(
                'success' => true,
                'cart_item_key' => $cart_item_key,
                'product_id' => $product_id,
                'cart_url' => wc_get_cart_url(),
                'cart_count' => WC()->cart->get_cart_contents_count(),
                'cart_total' => WC()->cart->get_cart_total()
            );

        } catch (Exception $e) {
            cross_site_cart_log_error('Cart handler error: ' . $e->getMessage(), array(
                'product_data' => $product_data,
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ));

            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Find existing product by SKU or create new one
     */
    private function find_or_create_product($product_data) {
        // Try to find existing product by SKU
        if (!empty($product_data['sku'])) {
            $product_id = wc_get_product_id_by_sku($product_data['sku']);
            if ($product_id) {
                return $product_id;
            }
        }

        // Create new product
        return $this->create_product_from_data($product_data);
    }

    /**
     * Create new product from transferred data
     */
    private function create_product_from_data($product_data) {
        // Create product object
        $product = new WC_Product_Simple();

        // Set basic product data
        $product->set_name($product_data['name']);
        $product->set_description($product_data['description'] ?? '');
        $product->set_short_description($product_data['short_description'] ?? '');
        $product->set_sku($product_data['sku']);
        $product->set_price($product_data['price']);
        $product->set_regular_price($product_data['price']);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden');
        $product->set_virtual(true);

        // Set dimensions if provided
        if (!empty($product_data['weight'])) {
            $product->set_weight($product_data['weight']);
        }
        
        if (!empty($product_data['dimensions'])) {
            $product->set_length($product_data['dimensions']['length'] ?? '');
            $product->set_width($product_data['dimensions']['width'] ?? '');
            $product->set_height($product_data['dimensions']['height'] ?? '');
        }

        // Save product
        $product_id = $product->save();

        if (!$product_id) {
            throw new Exception('Failed to create product');
        }

        // Add meta data
        if (!empty($product_data['meta_data'])) {
            foreach ($product_data['meta_data'] as $key => $value) {
                if (!empty($key) && $value !== '') {
                    update_post_meta($product_id, $key, $value);
                }
            }
        }

        // Add transfer tracking meta
        update_post_meta($product_id, '_cross_site_transferred', true);
        update_post_meta($product_id, '_cross_site_source_id', $product_data['original_product_id']);
        update_post_meta($product_id, '_cross_site_source_site', $product_data['source_site']);
        update_post_meta($product_id, '_cross_site_created', current_time('mysql'));

        return $product_id;
    }

    /**
     * Modify cart item prices for transferred products
     */
    public function modify_transferred_item_prices($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cart_item) {
            if (isset($cart_item['_cross_site_original_price'])) {
                $cart_item['data']->set_price($cart_item['_cross_site_original_price']);
            }
        }
    }

    /**
     * Add transfer information to cart display
     */
    public function add_transfer_info_to_cart($item_data, $cart_item) {
        if (isset($cart_item['_cross_site_source'])) {
            $source_domain = parse_url($cart_item['_cross_site_source'], PHP_URL_HOST);
            $item_data[] = array(
                'name' => __('Transferred from', 'cross-site-cart'),
                'value' => '<span class="cross-site-transfer-source">🔄 ' . esc_html($source_domain) . '</span>'
            );
        }

        return $item_data;
    }

    /**
     * Save transfer meta to order items
     */
    public function save_transfer_meta_to_order($item, $cart_item_key, $values, $order) {
        if (isset($values['_cross_site_transfer']) && $values['_cross_site_transfer']) {
            $item->add_meta_data('_cross_site_source', $values['_cross_site_source'], true);
            $item->add_meta_data('_cross_site_original_price', $values['_cross_site_original_price'], true);
            $item->add_meta_data('_cross_site_original_id', $values['_cross_site_original_id'], true);
            $item->add_meta_data('_cross_site_transfer_time', $values['_cross_site_transfer_time'], true);
        }
    }

    /**
     * Disable quantity changes for transferred products
     */
    public function disable_quantity_change_for_transferred_products($product_quantity, $cart_item_key, $cart_item) {
        if (isset($cart_item['_cross_site_transfer']) && $cart_item['_cross_site_transfer']) {
            return '<span class="transferred-quantity">' . $cart_item['quantity'] . '</span>';
        }
        return $product_quantity;
    }

    /**
     * Disable remove link for transferred products
     */
    public function disable_remove_link_for_transferred_products($remove_link, $cart_item_key) {
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        
        if (isset($cart_item['_cross_site_transfer']) && $cart_item['_cross_site_transfer']) {
            return '';
        }
        return $remove_link;
    }

    /**
     * Add CSS to restrict cart modifications
     */
    public function add_cart_restrictions_css() {
        if (is_cart()) {
            ?>
            <style>
            .transferred-quantity {
                font-weight: bold;
                color: #666;
                background: #f0f0f0;
                padding: 8px 12px;
                border-radius: 4px;
                display: inline-block;
            }
            
            .cart_item[data-transferred="true"]::after {
                content: "⚠️ This product was transferred from another site. Quantity cannot be changed.";
                display: block;
                font-size: 12px;
                color: #856404;
                background: #fff3cd;
                border: 1px solid #ffeaa7;
                padding: 8px 12px;
                border-radius: 4px;
                margin-top: 10px;
            }
            </style>
            
            <script>
            jQuery(document).ready(function($) {
                $('.cart_item').each(function() {
                    var $row = $(this);
                    if ($row.find('.cross-site-transfer-source').length > 0) {
                        $row.attr('data-transferred', 'true');
                    }
                });
            });
            </script>
            <?php
        }
    }

    /**
     * Get cart contents for API response
     */
    public function get_cart_contents() {
        if (!WC()->cart) {
            return array();
        }

        $cart_contents = array();
        foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
            $product = $cart_item['data'];
            $cart_contents[] = array(
                'key' => $cart_item_key,
                'product_id' => $cart_item['product_id'],
                'variation_id' => $cart_item['variation_id'],
                'quantity' => $cart_item['quantity'],
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'total' => WC()->cart->get_product_subtotal($product, $cart_item['quantity']),
                'is_transferred' => isset($cart_item['_cross_site_transfer']) ? $cart_item['_cross_site_transfer'] : false
            );
        }

        return $cart_contents;
    }

    /**
     * Clean up expired cart sessions
     */
    public static function cleanup_expired_sessions() {
        global $wpdb;

        // Delete sessions older than 7 days
        $wpdb->query($wpdb->prepare("
            DELETE FROM {$wpdb->prefix}woocommerce_sessions 
            WHERE session_expiry < %d
        ", time() - (7 * DAY_IN_SECONDS)));

        // Clean up transferred products that were never purchased
        $expired_products = $wpdb->get_results($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = '_cross_site_created' 
            AND meta_value < %s
            AND post_id NOT IN (
                SELECT DISTINCT product_id FROM {$wpdb->prefix}woocommerce_order_items
            )
        ", date('Y-m-d H:i:s', time() - (30 * DAY_IN_SECONDS))));

        foreach ($expired_products as $product) {
            wp_delete_post($product->post_id, true);
        }
    }
}

// Schedule cleanup
add_action('cross_site_cart_cleanup', array('Cross_Site_Cart_Handler', 'cleanup_expired_sessions'));