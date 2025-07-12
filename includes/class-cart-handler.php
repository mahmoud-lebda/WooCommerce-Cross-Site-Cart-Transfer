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

        // Ensure session is started
        if (!WC()->session->get_session_cookie()) {
            WC()->session->set_customer_session_cookie(true);
        }

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
                $product->set_catalog_visibility('hidden'); // Keep hidden from catalog
                $product->save();
            }

            // Prepare cart item data
            $cart_item_data = array(
                '_cross_site_transfer' => true,
                '_cross_site_source' => $product_data['source_site'],
                '_cross_site_original_price' => $product_data['price'],
                '_cross_site_original_id' => $product_data['original_product_id'],
                '_cross_site_transfer_time' => current_time('mysql'),
                '_cross_site_transfer_meta' => $product_data['meta_data'] ?? array()
            );

            // Add product to cart
            $cart_item_key = WC()->cart->add_to_cart(
                $product_id,
                $product_data['quantity'],
                $product_data['variation_id'] ?? 0,
                $product_data['variation_data'] ?? array(),
                $cart_item_data
            );

            if (!$cart_item_key) {
                // Get WooCommerce notices for better error reporting
                $notices = wc_get_notices('error');
                $error_message = 'Failed to add product to cart';
                
                if (!empty($notices)) {
                    $error_message .= ': ' . $notices[0]['notice'];
                    wc_clear_notices();
                }
                
                throw new Exception($error_message);
            }

            // Calculate totals
            WC()->cart->calculate_totals();
            
            // Save session data
            WC()->session->save_data();

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
        // Determine product type
        $product_type = 'simple';
        if (!empty($product_data['variation_id'])) {
            $product_type = 'variable';
        }

        // Create product object
        if ($product_type === 'variable') {
            $product = new WC_Product_Variable();
        } else {
            $product = new WC_Product_Simple();
        }

        // Set basic product data
        $product->set_name($product_data['name']);
        $product->set_description($product_data['description'] ?? '');
        $product->set_short_description($product_data['short_description'] ?? '');
        $product->set_sku($product_data['sku']);
        $product->set_price($product_data['price']);
        $product->set_regular_price($product_data['price']);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden'); // Hide from catalog searches
        $product->set_virtual(true); // Mark as virtual to avoid shipping issues

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

        // Handle categories if provided
        if (!empty($product_data['categories'])) {
            $this->assign_product_categories($product_id, $product_data['categories']);
        }

        // Handle images if provided
        if (!empty($product_data['images'])) {
            $this->assign_product_images($product_id, $product_data['images']);
        }

        return $product_id;
    }

    /**
     * Assign categories to product
     */
    private function assign_product_categories($product_id, $categories) {
        $term_ids = array();
        
        foreach ($categories as $category_name) {
            if (empty($category_name)) continue;
            
            // Check if category exists
            $term = get_term_by('name', $category_name, 'product_cat');
            
            if (!$term) {
                // Create category if it doesn't exist
                $term_data = wp_insert_term($category_name, 'product_cat');
                if (!is_wp_error($term_data)) {
                    $term_ids[] = $term_data['term_id'];
                }
            } else {
                $term_ids[] = $term->term_id;
            }
        }
        
        if (!empty($term_ids)) {
            wp_set_object_terms($product_id, $term_ids, 'product_cat');
        }
    }

    /**
     * Assign images to product
     */
    private function assign_product_images($product_id, $images) {
        $gallery_ids = array();
        $featured_image_id = null;
        
        foreach ($images as $index => $image) {
            if (empty($image['url'])) continue;
            
            // Download and attach image
            $attachment_id = $this->download_and_attach_image($image['url'], $product_id, $image['alt'] ?? '');
            
            if ($attachment_id) {
                if ($index === 0) {
                    $featured_image_id = $attachment_id;
                } else {
                    $gallery_ids[] = $attachment_id;
                }
            }
        }
        
        // Set featured image
        if ($featured_image_id) {
            set_post_thumbnail($product_id, $featured_image_id);
        }
        
        // Set gallery images
        if (!empty($gallery_ids)) {
            update_post_meta($product_id, '_product_image_gallery', implode(',', $gallery_ids));
        }
    }

    /**
     * Download and attach image to product
     */
    private function download_and_attach_image($image_url, $product_id, $alt_text = '') {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        try {
            // Download image
            $tmp = download_url($image_url);
            
            if (is_wp_error($tmp)) {
                return false;
            }

            // Get file info
            $file_array = array(
                'name' => basename($image_url),
                'tmp_name' => $tmp
            );

            // Upload image
            $attachment_id = media_handle_sideload($file_array, $product_id);

            if (is_wp_error($attachment_id)) {
                @unlink($tmp);
                return false;
            }

            // Set alt text
            if ($alt_text) {
                update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt_text);
            }

            return $attachment_id;

        } catch (Exception $e) {
            return false;
        }
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

        if (isset($cart_item['_cross_site_transfer_meta']) && is_array($cart_item['_cross_site_transfer_meta'])) {
            foreach ($cart_item['_cross_site_transfer_meta'] as $key => $value) {
                if (!empty($value) && is_string($value) && strlen($value) < 100) {
                    $item_data[] = array(
                        'name' => ucfirst(str_replace('_', ' ', $key)),
                        'value' => esc_html($value)
                    );
                }
            }
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
            
            if (!empty($values['_cross_site_transfer_meta'])) {
                $item->add_meta_data('_cross_site_transfer_meta', $values['_cross_site_transfer_meta'], true);
            }
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
     * Validate cart before checkout
     */
    public function validate_cart() {
        if (!WC()->cart || WC()->cart->is_empty()) {
            return array(
                'valid' => false,
                'message' => 'Cart is empty'
            );
        }

        $errors = array();
        
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            
            // Check if product exists and is purchasable
            if (!$product || !$product->exists()) {
                $errors[] = 'Product no longer exists';
                continue;
            }
            
            if (!$product->is_purchasable()) {
                $errors[] = sprintf('Product "%s" is not purchasable', $product->get_name());
                continue;
            }
            
            // Check stock if managed
            if ($product->managing_stock()) {
                if (!$product->has_enough_stock($cart_item['quantity'])) {
                    $errors[] = sprintf('Not enough stock for product "%s"', $product->get_name());
                }
            }
        }

        return array(
            'valid' => empty($errors),
            'errors' => $errors
        );
    }

    /**
     * Clear expired cart sessions
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