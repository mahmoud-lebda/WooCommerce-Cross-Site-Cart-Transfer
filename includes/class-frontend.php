<?php
/**
 * Frontend functionality for Cross-Site Cart Transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cross_Site_Cart_Frontend {

    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize frontend hooks
     */
    private function init_hooks() {
        // Only load if plugin is enabled
        if (!Cross_Site_Cart_Plugin::get_option('enabled')) {
            return;
        }

        // Prevent normal cart functionality on source site
        add_action('wp_loaded', array($this, 'disable_normal_cart'), 5);
        
        // Override add to cart behavior
        add_filter('woocommerce_product_add_to_cart_url', array($this, 'override_add_to_cart_url'), 10, 2);
        add_filter('woocommerce_product_add_to_cart_text', array($this, 'modify_add_to_cart_text'), 10, 2);
        
        // Load scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_cross_site_transfer_product', array($this, 'handle_product_transfer'));
        add_action('wp_ajax_nopriv_cross_site_transfer_product', array($this, 'handle_product_transfer'));
        
        // Add transfer info to product page
        add_action('woocommerce_single_product_summary', array($this, 'add_transfer_info'), 25);
        
        // Add back button on target site
        add_action('woocommerce_thankyou', array($this, 'add_back_button'));
    }

    /**
     * Disable normal WooCommerce cart functionality on source site
     */
    public function disable_normal_cart() {
        // Remove default add to cart actions
        remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30);
        remove_action('woocommerce_simple_add_to_cart', 'woocommerce_simple_add_to_cart', 30);
        remove_action('woocommerce_variable_add_to_cart', 'woocommerce_variable_add_to_cart', 30);
        
        // Prevent AJAX add to cart
        add_filter('woocommerce_is_sold_individually', '__return_true', 999);
        
        // Disable cart page and checkout on source site
        add_action('template_redirect', array($this, 'redirect_cart_pages'));
    }

    /**
     * Redirect cart and checkout pages on source site
     */
    public function redirect_cart_pages() {
        if (is_cart() || is_checkout()) {
            $target_url = Cross_Site_Cart_Plugin::get_option('target_url');
            if ($target_url) {
                wp_redirect($target_url . '/cart/');
                exit;
            }
        }
    }

    /**
     * Override add to cart URL to prevent normal functionality
     */
    public function override_add_to_cart_url($url, $product) {
        return '#transfer-product';
    }

    /**
     * Modify add to cart button text
     */
    public function modify_add_to_cart_text($text, $product) {
        return __('Transfer to Checkout', 'cross-site-cart');
    }

    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // CSS
        wp_enqueue_style(
            'cross-site-cart-frontend',
            CROSS_SITE_CART_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            CROSS_SITE_CART_VERSION
        );

        // JavaScript
        wp_enqueue_script(
            'cross-site-cart-frontend',
            CROSS_SITE_CART_PLUGIN_URL . 'assets/js/frontend.js',
            array('jquery'),
            CROSS_SITE_CART_VERSION,
            true
        );

        // Localize script
        wp_localize_script('cross-site-cart-frontend', 'crossSiteCart', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cross_site_cart_nonce'),
            'targetUrl' => Cross_Site_Cart_Plugin::get_option('target_url'),
            'messages' => array(
                'processing' => __('Processing...', 'cross-site-cart'),
                'transferring' => __('Transferring to secure checkout...', 'cross-site-cart'),
                'error' => __('Transfer failed. Please try again.', 'cross-site-cart'),
                'selectOptions' => __('Please select product options.', 'cross-site-cart')
            )
        ));
    }

    /**
     * Handle AJAX product transfer
     */
    public function handle_product_transfer() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cross_site_cart_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }

        // Get product data
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']) ?: 1;
        $variation_id = intval($_POST['variation_id']) ?: 0;
        $variation_data = $_POST['variation_data'] ?: array();

        // Validate product
        $product = wc_get_product($product_id);
        if (!$product || !$product->exists()) {
            wp_send_json_error(array('message' => 'Product not found'));
        }

        // For variable products, ensure variation is selected
        if ($product->is_type('variable') && !$variation_id) {
            wp_send_json_error(array('message' => 'Please select product options'));
        }

        try {
            // Create transfer handler
            $transfer = new Cross_Site_Cart_Product_Transfer();
            
            // Transfer product to target site
            $result = $transfer->transfer_product($product, $quantity, $variation_id, $variation_data);

            if ($result['success']) {
                wp_send_json_success(array(
                    'message' => 'Product transferred successfully',
                    'redirect_url' => $result['redirect_url']
                ));
            } else {
                wp_send_json_error(array('message' => $result['message']));
            }

        } catch (Exception $e) {
            cross_site_cart_log_error('Transfer error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Transfer failed: ' . $e->getMessage()));
        }
    }

    /**
     * Add transfer information to product page
     */
    public function add_transfer_info() {
        ?>
        <div class="cross-site-transfer-info">
            <div class="transfer-notice">
                <span class="transfer-icon">🔄</span>
                <span class="transfer-text">
                    <?php _e('This product will be transferred to our secure checkout site for payment.', 'cross-site-cart'); ?>
                </span>
            </div>
        </div>
        <?php
    }

    /**
     * Add back button on target site thank you page
     */
    public function add_back_button($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Check if order contains transferred products
        $source_site = null;
        foreach ($order->get_items() as $item) {
            $source_site_meta = $item->get_meta('_cross_site_source');
            if ($source_site_meta) {
                $source_site = $source_site_meta;
                break;
            }
        }

        if ($source_site) {
            $source_domain = parse_url($source_site, PHP_URL_HOST);
            ?>
            <div class="cross-site-back-button">
                <div class="back-button-container">
                    <h3><?php _e('Thank you for your purchase!', 'cross-site-cart'); ?></h3>
                    <p><?php _e('Your order has been processed successfully.', 'cross-site-cart'); ?></p>
                    <a href="<?php echo esc_url($source_site); ?>" class="button back-to-source">
                        <?php printf(__('← Back to %s', 'cross-site-cart'), esc_html($source_domain)); ?>
                    </a>
                </div>
            </div>
            <?php
        }
    }
}