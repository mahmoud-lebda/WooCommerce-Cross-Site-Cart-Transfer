<?php
/**
 * REST API Handler for Cross-Site Cart Transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cross_Site_Cart_API {

    private $namespace = 'cross-site-cart/v1';

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('rest_api_init', array($this, 'setup_cors'));
    }

    /**
     * Setup CORS headers for cross-origin requests
     */
    public function setup_cors() {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', array($this, 'send_cors_headers'));
    }

    /**
     * Send custom CORS headers
     */
    public function send_cors_headers($value) {
        $origin = get_http_origin();
        $allowed_origins = array(
            Cross_Site_Cart_Plugin::get_option('target_url'),
            home_url()
        );

        if (in_array($origin, $allowed_origins) || $this->is_development_environment()) {
            header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Source-Site, X-Transfer-Version');

        return $value;
    }

    /**
     * Check if this is a development environment
     */
    private function is_development_environment() {
        return defined('WP_DEBUG') && WP_DEBUG || 
               strpos(home_url(), 'localhost') !== false ||
               strpos(home_url(), '.local') !== false ||
               strpos(home_url(), '.dev') !== false;
    }

    /**
     * Register REST API routes
     */
    public function register_routes() {
        // Receive product from source site
        register_rest_route($this->namespace, '/receive-product', array(
            'methods' => 'POST',
            'callback' => array($this, 'receive_product'),
            'permission_callback' => array($this, 'check_permissions'),
            'args' => array(
                'product_data' => array(
                    'required' => true,
                    'type' => 'object'
                ),
                'timestamp' => array(
                    'required' => true,
                    'type' => 'integer'
                ),
                'signature' => array(
                    'required' => true,
                    'type' => 'string'
                )
            )
        ));

        // Test connection endpoint
        register_rest_route($this->namespace, '/test-connection', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => '__return_true'
        ));

        // Get cart contents
        register_rest_route($this->namespace, '/cart', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_cart'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Update stock notification
        register_rest_route($this->namespace, '/update-stock', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_stock'),
            'permission_callback' => array($this, 'check_permissions')
        ));

        // Order completion notification
        register_rest_route($this->namespace, '/order-completed', array(
            'methods' => 'POST',
            'callback' => array($this, 'order_completed'),
            'permission_callback' => array($this, 'check_permissions')
        ));
    }

    /**
     * Check API permissions
     */
    public function check_permissions($request) {
        // Check for proper authentication
        $auth_header = $request->get_header('Authorization');
        
        if (!$auth_header) {
            return new WP_Error('missing_auth', 'Authorization header required', array('status' => 401));
        }

        // Verify Basic Auth
        if (strpos($auth_header, 'Basic ') === 0) {
            $credentials = base64_decode(substr($auth_header, 6));
            if (strpos($credentials, ':') === false) {
                return new WP_Error('invalid_auth', 'Invalid authorization format', array('status' => 401));
            }

            list($key, $secret) = explode(':', $credentials, 2);
            
            // For now, allow any valid-looking credentials
            // In production, you might want to validate against stored API keys
            if (!empty($key) && !empty($secret)) {
                return true;
            }
        }

        return new WP_Error('invalid_auth', 'Invalid authorization credentials', array('status' => 401));
    }

    /**
     * Receive and process product from source site
     */
    public function receive_product($request) {
        try {
            $params = $request->get_json_params();
            
            if (!$params || !isset($params['product_data'])) {
                return new WP_Error('invalid_data', 'Invalid request data', array('status' => 400));
            }

            $product_data = $params['product_data'];
            $timestamp = $params['timestamp'] ?? time();
            $signature = $params['signature'] ?? '';

            // Verify timestamp (prevent replay attacks)
            if (abs(time() - $timestamp) > 300) { // 5 minutes
                return new WP_Error('expired_request', 'Request has expired', array('status' => 400));
            }

            // Verify signature if available
            if (!empty($signature)) {
                $expected_signature = $this->create_signature($product_data, $timestamp);
                if (!hash_equals($expected_signature, $signature)) {
                    return new WP_Error('invalid_signature', 'Invalid request signature', array('status' => 401));
                }
            }

            // Process the product transfer
            $cart_handler = new Cross_Site_Cart_Handler();
            $result = $cart_handler->add_product_to_cart($product_data);

            if ($result['success']) {
                return rest_ensure_response(array(
                    'success' => true,
                    'message' => 'Product added to cart successfully',
                    'data' => array(
                        'product_id' => $result['product_id'],
                        'cart_item_key' => $result['cart_item_key'],
                        'redirect_url' => $result['cart_url'],
                        'cart_count' => $result['cart_count'],
                        'cart_total' => $result['cart_total']
                    )
                ));
            } else {
                return new WP_Error('transfer_failed', $result['message'], array('status' => 500));
            }

        } catch (Exception $e) {
            cross_site_cart_log_error('API receive_product error: ' . $e->getMessage());
            return new WP_Error('server_error', 'Internal server error', array('status' => 500));
        }
    }

    /**
     * Test connection endpoint
     */
    public function test_connection($request) {
        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Connection successful',
            'data' => array(
                'site_url' => home_url(),
                'site_name' => get_bloginfo('name'),
                'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'Not installed',
                'plugin_version' => CROSS_SITE_CART_VERSION,
                'timestamp' => current_time('mysql'),
                'timezone' => wp_timezone_string()
            )
        ));
    }

    /**
     * Get cart contents
     */
    public function get_cart($request) {
        $cart_handler = new Cross_Site_Cart_Handler();
        
        if (!WC()->cart) {
            return new WP_Error('cart_not_available', 'Cart not available', array('status' => 500));
        }

        $cart_contents = $cart_handler->get_cart_contents();
        $validation = $cart_handler->validate_cart();

        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'contents' => $cart_contents,
                'count' => WC()->cart->get_cart_contents_count(),
                'total' => WC()->cart->get_cart_total(),
                'subtotal' => WC()->cart->get_cart_subtotal(),
                'is_empty' => WC()->cart->is_empty(),
                'validation' => $validation
            )
        ));
    }

    /**
     * Update stock notification from target site
     */
    public function update_stock($request) {
        $params = $request->get_json_params();
        
        if (!isset($params['product_id']) || !isset($params['quantity_sold'])) {
            return new WP_Error('missing_data', 'Missing required parameters', array('status' => 400));
        }

        $product_id = intval($params['product_id']);
        $quantity_sold = intval($params['quantity_sold']);
        $order_id = $params['order_id'] ?? null;

        $product = wc_get_product($product_id);
        
        if (!$product) {
            return new WP_Error('product_not_found', 'Product not found', array('status' => 404));
        }

        if ($product->managing_stock()) {
            $current_stock = $product->get_stock_quantity();
            $new_stock = max(0, $current_stock - $quantity_sold);
            
            $product->set_stock_quantity($new_stock);
            $product->save();

            // Log stock update
            cross_site_cart_log_error("Stock updated for product {$product_id}: -{$quantity_sold} (Order: {$order_id})");

            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Stock updated successfully',
                'data' => array(
                    'product_id' => $product_id,
                    'previous_stock' => $current_stock,
                    'new_stock' => $new_stock,
                    'quantity_sold' => $quantity_sold
                )
            ));
        }

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Product does not manage stock',
            'data' => array(
                'product_id' => $product_id,
                'manages_stock' => false
            )
        ));
    }

    /**
     * Order completion notification from target site
     */
    public function order_completed($request) {
        $params = $request->get_json_params();
        
        if (!isset($params['order_id']) || !isset($params['product_id'])) {
            return new WP_Error('missing_data', 'Missing required parameters', array('status' => 400));
        }

        $order_id = intval($params['order_id']);
        $product_id = intval($params['product_id']);
        $quantity = intval($params['quantity'] ?? 1);
        $item_total = floatval($params['item_total'] ?? 0);
        $customer_email = sanitize_email($params['customer_email'] ?? '');

        // Log the completion
        cross_site_cart_log_error("Order completion notification: Order {$order_id}, Product {$product_id}, Customer: {$customer_email}");

        // Update completion statistics
        $total_completed = get_option('cross_site_cart_completed_orders', 0);
        $total_revenue = get_option('cross_site_cart_total_revenue', 0);
        
        update_option('cross_site_cart_completed_orders', $total_completed + 1);
        update_option('cross_site_cart_total_revenue', $total_revenue + $item_total);

        // Trigger action for custom handling
        do_action('cross_site_cart_order_completed', array(
            'order_id' => $order_id,
            'product_id' => $product_id,
            'quantity' => $quantity,
            'total' => $item_total,
            'customer_email' => $customer_email
        ));

        return rest_ensure_response(array(
            'success' => true,
            'message' => 'Order completion recorded',
            'data' => array(
                'order_id' => $order_id,
                'product_id' => $product_id,
                'recorded_at' => current_time('mysql')
            )
        ));
    }

    /**
     * Create signature for request validation
     */
    private function create_signature($data, $timestamp) {
        $string_to_sign = wp_json_encode($data) . $timestamp;
        return hash_hmac('sha256', $string_to_sign, Cross_Site_Cart_Plugin::get_option('encryption_key'));
    }

    /**
     * Handle OPTIONS requests for CORS preflight
     */
    public function handle_preflight() {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce');
            header('Access-Control-Max-Age: 86400');
            exit;
        }
    }

    /**
     * Get API status and diagnostics
     */
    public function get_status($request) {
        $diagnostics = array(
            'plugin_version' => CROSS_SITE_CART_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'Not installed',
            'php_version' => PHP_VERSION,
            'server_time' => current_time('mysql'),
            'timezone' => wp_timezone_string(),
            'ssl_enabled' => is_ssl(),
            'cart_available' => class_exists('WC_Cart'),
            'session_available' => class_exists('WC_Session_Handler'),
            'rest_api_enabled' => get_option('woocommerce_api_enabled', 'yes') === 'yes'
        );

        // Check configuration
        $config_status = array(
            'plugin_enabled' => Cross_Site_Cart_Plugin::get_option('enabled'),
            'target_url_set' => !empty(Cross_Site_Cart_Plugin::get_option('target_url')),
            'api_credentials_set' => !empty(Cross_Site_Cart_Plugin::get_option('api_key')) && !empty(Cross_Site_Cart_Plugin::get_option('api_secret')),
            'ssl_verification' => Cross_Site_Cart_Plugin::get_option('ssl_verify', true)
        );

        // Get recent transfer statistics
        $stats = Cross_Site_Cart_Product_Transfer::get_transfer_stats();

        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'diagnostics' => $diagnostics,
                'configuration' => $config_status,
                'statistics' => $stats
            )
        ));
    }
}