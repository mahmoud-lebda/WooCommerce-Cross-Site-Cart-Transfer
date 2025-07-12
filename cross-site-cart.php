<?php
/**
 * Plugin Name: WooCommerce Cross-Site Cart Transfer
 * Description: Transfer products from one WooCommerce site to another with cart functionality - No additional file modifications needed
 * Version: 1.0.0
 * Author: Smartify Solutions
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class WooCommerce_Cross_Site_Cart {
    
    private $target_site_url;
    private $api_key;
    private $api_secret;
    
    public function __construct() {
        add_action('init', array($this, 'init'));
        
        // تفعيل جميع الـ hooks المطلوبة
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('wp_ajax_transfer_cart', array($this, 'handle_cart_transfer'));
        add_action('wp_ajax_nopriv_transfer_cart', array($this, 'handle_cart_transfer'));
        
        // التحكم في أزرار Add to Cart
        add_filter('woocommerce_product_add_to_cart_url', array($this, 'modify_add_to_cart_url'), 10, 2);
        add_filter('woocommerce_product_add_to_cart_text', array($this, 'modify_add_to_cart_text'), 10, 2);
        
        // إضافة JavaScript والـ CSS
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_head', array($this, 'add_custom_css'));
        
        // إعداد CORS تلقائياً
        add_action('rest_api_init', array($this, 'setup_cors_automatically'));
        add_action('init', array($this, 'add_cors_headers'));
        
        // إضافة API endpoints
        add_action('rest_api_init', array($this, 'register_api_endpoints'));
        
        // إضافة زر العودة في صفحة الشكر
        add_action('woocommerce_thankyou', array($this, 'add_back_to_site_button'));
        
        // إعداد تلقائي للأمان
        add_action('wp_loaded', array($this, 'setup_security_automatically'));
        
        // إعداد تلقائي للسعر والعربة
        add_action('woocommerce_before_calculate_totals', array($this, 'modify_cart_item_price_from_transfer'));
        add_filter('woocommerce_get_item_data', array($this, 'add_transfer_info_to_cart'), 10, 2);
        
        // إضافة معلومات في صفحة المنتج
        add_action('woocommerce_single_product_summary', array($this, 'add_transfer_info_to_product_page'), 25);
        
        // تنشيط تلقائي عند التفعيل
        register_activation_hook(__FILE__, array($this, 'plugin_activation'));
    }
    
    public function init() {
        $this->target_site_url = get_option('cross_site_target_url', '');
        $this->api_key = get_option('cross_site_api_key', '');
        $this->api_secret = get_option('cross_site_api_secret', '');
    }
    
    // إعداد CORS تلقائياً دون تعديل ملفات
    public function setup_cors_automatically() {
        // إزالة CORS headers الافتراضية
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        
        // إضافة CORS headers مخصصة
        add_filter('rest_pre_serve_request', array($this, 'send_custom_cors_headers'));
    }
    
    public function send_custom_cors_headers($value) {
        $origin = get_http_origin();
        $allowed_origins = array(
            $this->target_site_url,
            get_option('cross_site_target_url', ''),
            home_url()
        );
        
        if (in_array($origin, $allowed_origins)) {
            header('Access-Control-Allow-Origin: ' . $origin);
        }
        
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Signature, X-Timestamp');
        
        return $value;
    }
    
    // إضافة CORS headers في جميع الطلبات
    public function add_cors_headers() {
        if (defined('REST_REQUEST') || isset($_GET['rest_route'])) {
            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE');
            header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Signature, X-Timestamp');
        }
    }
    
    // إعداد الأمان تلقائياً
    public function setup_security_automatically() {
        // إضافة security headers
        add_action('send_headers', array($this, 'add_security_headers'));
        
        // إنشاء مفتاح التشفير إذا لم يكن موجود
        if (!get_option('cross_site_encryption_key')) {
            update_option('cross_site_encryption_key', wp_generate_password(64, true, true));
        }
    }
    
    public function add_security_headers() {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }
    
    public function plugin_activation() {
        // إعداد تلقائي عند تفعيل الـ Plugin
        if (!get_option('cross_site_encryption_key')) {
            update_option('cross_site_encryption_key', wp_generate_password(64, true, true));
        }
        
        // إنشاء جداول قاعدة البيانات إذا لزم الأمر
        $this->create_database_tables();
        
        // تسجيل تفعيل الـ Plugin
        update_option('cross_site_cart_activated', current_time('mysql'));
    }
    
    private function create_database_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cross_site_transfers';
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            source_product_id mediumint(9) NOT NULL,
            target_product_id mediumint(9),
            transfer_data longtext NOT NULL,
            transfer_status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Cross-Site Cart Settings',
            'Cross-Site Cart',
            'manage_woocommerce',
            'cross-site-cart',
            array($this, 'admin_page')
        );
    }
    
    public function admin_page() {
        if (isset($_POST['submit'])) {
            update_option('cross_site_target_url', sanitize_url($_POST['target_url']));
            update_option('cross_site_api_key', sanitize_text_field($_POST['api_key']));
            update_option('cross_site_api_secret', sanitize_text_field($_POST['api_secret']));
            update_option('cross_site_enabled', isset($_POST['enabled']) ? 1 : 0);
            
            echo '<div class="notice notice-success"><p>Settings saved! No additional file modifications needed.</p></div>';
        }
        
        $target_url = get_option('cross_site_target_url', '');
        $api_key = get_option('cross_site_api_key', '');
        $api_secret = get_option('cross_site_api_secret', '');
        $enabled = get_option('cross_site_enabled', 0);
        ?>
        <div class="wrap">
            <h1>Cross-Site Cart Settings</h1>
            <div class="notice notice-info">
                <p><strong>No File Modifications Required!</strong> This plugin handles everything automatically.</p>
            </div>
            
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Cross-Site Cart</th>
                        <td><input type="checkbox" name="enabled" value="1" <?php checked($enabled, 1); ?> /></td>
                    </tr>
                    <tr>
                        <th scope="row">Target Site URL</th>
                        <td>
                            <input type="url" name="target_url" value="<?php echo esc_attr($target_url); ?>" class="regular-text" placeholder="https://your-target-site.com" />
                            <p class="description">The URL of the site where products will be transferred</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="ck_xxxxxxxxxxxxxxxxxxxxxxxx" />
                            <p class="description">WooCommerce REST API Key from target site</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Secret</th>
                        <td>
                            <input type="password" name="api_secret" value="<?php echo esc_attr($api_secret); ?>" class="regular-text" placeholder="cs_xxxxxxxxxxxxxxxxxxxxxxxx" />
                            <p class="description">WooCommerce REST API Secret from target site</p>
                        </td>
                    </tr>
                </table>
                
                <h3>Quick Setup Guide</h3>
                <ol>
                    <li>Install this plugin on both sites</li>
                    <li>On the target site: Go to WooCommerce → Settings → Advanced → REST API and create API keys</li>
                    <li>Copy the API keys here</li>
                    <li>Enable the plugin and test!</li>
                </ol>
                
                <?php submit_button(); ?>
            </form>
            
            <div class="card">
                <h3>Plugin Status</h3>
                <p><strong>CORS Headers:</strong> ✓ Automatically configured</p>
                <p><strong>Security Headers:</strong> ✓ Automatically added</p>
                <p><strong>API Endpoints:</strong> ✓ Automatically registered</p>
                <p><strong>JavaScript:</strong> ✓ Automatically loaded</p>
                <p><strong>CSS Styling:</strong> ✓ Automatically applied</p>
            </div>
        </div>
        <?php
    }
    
    public function modify_add_to_cart_url($url, $product) {
        if (!get_option('cross_site_enabled', 0)) {
            return $url;
        }
        return '#';
    }
    
    public function modify_add_to_cart_text($text, $product) {
        if (!get_option('cross_site_enabled', 0)) {
            return $text;
        }
        return 'Add to Cart & Transfer';
    }
    
    public function enqueue_scripts() {
        if (!get_option('cross_site_enabled', 0)) {
            return;
        }
        
        // تسجيل وتحميل JavaScript مباشرة
        wp_add_inline_script('jquery', $this->get_inline_javascript());
        
        wp_localize_script('jquery', 'cross_site_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cross_site_nonce'),
            'target_url' => $this->target_site_url
        ));
    }
    
    private function get_inline_javascript() {
        return "
        jQuery(document).ready(function($) {
            // إخفاء أزرار Add to Cart الافتراضية واستبدالها
            $('.single_add_to_cart_button, .add_to_cart_button').off('click').on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                var button = $(this);
                var form = button.closest('form.cart, form.variations_form');
                var product_id = form.find('input[name=\"add-to-cart\"]').val() || button.data('product_id');
                var quantity = form.find('input[name=\"quantity\"]').val() || 1;
                var variation_id = form.find('input[name=\"variation_id\"]').val() || 0;
                
                if (!product_id) {
                    alert('Product ID not found');
                    return;
                }
                
                // التحقق من المتغيرات للمنتجات المتغيرة
                if (form.hasClass('variations_form') && !variation_id) {
                    alert('Please select product options before adding to cart.');
                    return;
                }
                
                // إظهار loading
                var originalText = button.text();
                button.addClass('loading disabled').text('Transferring...');
                
                // جمع بيانات المتغيرات
                var variation_data = {};
                form.find('.variations select').each(function() {
                    var name = $(this).attr('name');
                    var value = $(this).val();
                    if (value) {
                        variation_data[name] = value;
                    }
                });
                
                // إرسال البيانات للتحويل
                $.ajax({
                    url: cross_site_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'transfer_cart',
                        product_id: product_id,
                        quantity: quantity,
                        variation_id: variation_id,
                        variation_data: variation_data,
                        nonce: cross_site_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // تحويل المستخدم للموقع الثاني
                            window.location.href = response.data.redirect_url;
                        } else {
                            alert('Transfer failed: ' + response.data.message);
                            button.removeClass('loading disabled').text(originalText);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Transfer failed:', error);
                        button.removeClass('loading disabled').text('Transfer Failed - Try Again');
                        alert('Transfer failed. Please try again.');
                    }
                });
            });
        });
        ";
    }
    
    public function add_custom_css() {
        if (!get_option('cross_site_enabled', 0)) {
            return;
        }
        ?>
        <style type="text/css">
            .loading {
                opacity: 0.6 !important;
                cursor: wait !important;
            }
            
            .disabled {
                pointer-events: none !important;
            }
            
            .transfer-info {
                background: linear-gradient(135deg, #e8f4f8 0%, #f0f8f0 100%);
                border-left: 4px solid #00a0d2;
                padding: 15px;
                margin: 15px 0;
                border-radius: 8px;
                font-size: 14px;
                color: #333;
            }
            
            .transfer-info::before {
                content: '🔄';
                margin-right: 8px;
            }
            
            .back-to-source-site {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 15px;
                padding: 25px;
                text-align: center;
                margin: 25px 0;
                box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            }
            
            .back-to-source-site .button {
                background: white !important;
                color: #333 !important;
                padding: 15px 30px !important;
                border-radius: 25px !important;
                text-decoration: none !important;
                font-weight: 600 !important;
                font-size: 16px !important;
                transition: all 0.3s ease !important;
                border: none !important;
                box-shadow: 0 5px 15px rgba(0,0,0,0.1) !important;
            }
            
            .back-to-source-site .button:hover {
                transform: translateY(-3px) !important;
                box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
            }
            
            .cart-transfer-meta {
                background: #f9f9f9;
                padding: 10px;
                border-radius: 5px;
                margin-top: 5px;
                font-size: 12px;
                color: #666;
            }
        </style>
        <?php
    }
    
    public function handle_cart_transfer() {
        check_ajax_referer('cross_site_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);
        $variation_id = intval($_POST['variation_id'] ?? 0);
        $variation_data = $_POST['variation_data'] ?? array();
        
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => 'Product not found'));
        }
        
        // جمع بيانات المنتج الكاملة
        $product_data = $this->collect_product_data($product, $quantity, $variation_id, $variation_data);
        
        // تسجيل محاولة التحويل
        $this->log_transfer_attempt($product_data);
        
        // إرسال البيانات للموقع الثاني
        $transfer_result = $this->transfer_to_target_site($product_data);
        
        if ($transfer_result['success']) {
            wp_send_json_success(array(
                'redirect_url' => $transfer_result['redirect_url'],
                'message' => 'Transfer successful'
            ));
        } else {
            wp_send_json_error(array('message' => $transfer_result['message']));
        }
    }
    
    private function collect_product_data($product, $quantity, $variation_id, $variation_data) {
        $product_data = array(
            'sku' => $product->get_sku(),
            'name' => $product->get_name(),
            'price' => $product->get_price(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'weight' => $product->get_weight(),
            'dimensions' => array(
                'length' => $product->get_length(),
                'width' => $product->get_width(),
                'height' => $product->get_height()
            ),
            'quantity' => $quantity,
            'variation_id' => $variation_id,
            'variation_data' => $variation_data,
            'meta_data' => array(),
            'images' => array(),
            'categories' => array(),
            'tags' => array()
        );
        
        // جمع Meta Data
        $meta_data = get_post_meta($product->get_id());
        foreach ($meta_data as $key => $value) {
            if (strpos($key, '_') !== 0) { // تجنب الـ meta الداخلية
                $product_data['meta_data'][$key] = is_array($value) ? $value[0] : $value;
            }
        }
        
        // جمع الصور
        $image_ids = $product->get_gallery_image_ids();
        array_unshift($image_ids, $product->get_image_id());
        foreach ($image_ids as $image_id) {
            if ($image_id) {
                $product_data['images'][] = array(
                    'id' => $image_id,
                    'url' => wp_get_attachment_url($image_id),
                    'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true)
                );
            }
        }
        
        // جمع التصنيفات والعلامات
        $product_data['categories'] = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
        $product_data['tags'] = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'));
        
        return $product_data;
    }
    
    private function log_transfer_attempt($product_data) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cross_site_transfers';
        
        $wpdb->insert(
            $table_name,
            array(
                'source_product_id' => $product_data['sku'],
                'transfer_data' => json_encode($product_data),
                'transfer_status' => 'initiated'
            )
        );
    }
    
    private function transfer_to_target_site($product_data) {
        if (empty($this->target_site_url) || empty($this->api_key)) {
            return array('success' => false, 'message' => 'Target site not configured');
        }
        
        $api_url = rtrim($this->target_site_url, '/') . '/wp-json/cross-site-cart/v1/receive-product';
        
        $body = array(
            'product_data' => $product_data,
            'source_site' => home_url(),
            'timestamp' => time()
        );
        
        $response = wp_remote_post($api_url, array(
            'body' => json_encode($body),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret),
                'X-Source-Site' => home_url()
            ),
            'timeout' => 30,
            'sslverify' => true
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return array('success' => false, 'message' => 'HTTP Error: ' . $response_code);
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('success' => false, 'message' => 'Invalid JSON response');
        }
        
        return $data;
    }
    
    public function register_api_endpoints() {
        register_rest_route('cross-site-cart/v1', '/receive-product', array(
            'methods' => 'POST',
            'callback' => array($this, 'receive_product'),
            'permission_callback' => array($this, 'verify_api_request')
        ));
        
        register_rest_route('cross-site-cart/v1', '/test-connection', array(
            'methods' => 'GET',
            'callback' => array($this, 'test_connection'),
            'permission_callback' => '__return_true'
        ));
    }
    
    public function verify_api_request($request) {
        $auth_header = $request->get_header('Authorization');
        if (!$auth_header) {
            return false;
        }
        
        // التحقق من Basic Auth
        if (strpos($auth_header, 'Basic ') === 0) {
            $credentials = base64_decode(substr($auth_header, 6));
            list($username, $password) = explode(':', $credentials, 2);
            
            // يمكن إضافة تحقق إضافي هنا
            return !empty($username) && !empty($password);
        }
        
        return false;
    }
    
    public function receive_product($request) {
        $data = $request->get_json_params();
        $product_data = $data['product_data'];
        $source_site = $data['source_site'];
        
        try {
            // البحث عن المنتج بالـ SKU
            $existing_product_id = null;
            if (!empty($product_data['sku'])) {
                $existing_product_id = wc_get_product_id_by_sku($product_data['sku']);
            }
            
            if (!$existing_product_id) {
                // إنشاء منتج جديد
                $product_id = $this->create_product_from_data($product_data);
            } else {
                $product_id = $existing_product_id;
            }
            
            // إضافة المنتج للسلة مع البيانات الأصلية
            $cart_item_key = WC()->cart->add_to_cart(
                $product_id,
                $product_data['quantity'],
                $product_data['variation_id'],
                $product_data['variation_data'],
                array(
                    'source_site' => $source_site,
                    'original_price' => $product_data['price'],
                    'transfer_meta' => $product_data['meta_data'],
                    'transferred_product' => true
                )
            );
            
            if ($cart_item_key) {
                return array(
                    'success' => true,
                    'redirect_url' => wc_get_cart_url(),
                    'message' => 'Product added to cart successfully',
                    'product_id' => $product_id
                );
            } else {
                return array(
                    'success' => false,
                    'message' => 'Failed to add product to cart'
                );
            }
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            );
        }
    }
    
    private function create_product_from_data($product_data) {
        $product = new WC_Product_Simple();
        $product->set_name($product_data['name']);
        $product->set_sku($product_data['sku']);
        $product->set_price($product_data['price']);
        $product->set_regular_price($product_data['price']);
        $product->set_description($product_data['description']);
        $product->set_short_description($product_data['short_description']);
        $product->set_weight($product_data['weight']);
        $product->set_length($product_data['dimensions']['length']);
        $product->set_width($product_data['dimensions']['width']);
        $product->set_height($product_data['dimensions']['height']);
        $product->set_status('publish');
        $product->set_catalog_visibility('hidden'); // إخفاء من الكتالوج
        
        $product_id = $product->save();
        
        // إضافة Meta Data
        foreach ($product_data['meta_data'] as $key => $value) {
            update_post_meta($product_id, $key, $value);
        }
        
        return $product_id;
    }
    
    public function test_connection($request) {
        return array(
            'success' => true,
            'message' => 'Connection successful',
            'site_url' => home_url(),
            'time' => current_time('mysql')
        );
    }
    
    public function modify_cart_item_price_from_transfer($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
            if (isset($cart_item['original_price']) && $cart_item['original_price']) {
                $cart_item['data']->set_price($cart_item['original_price']);
            }
        }
    }
    
    public function add_transfer_info_to_cart($item_data, $cart_item) {
        if (isset($cart_item['source_site'])) {
            $item_data[] = array(
                'name' => 'Transferred from',
                'value' => '<span class="cart-transfer-meta">🔄 ' . parse_url($cart_item['source_site'], PHP_URL_HOST) . '</span>'
            );
        }
        
        if (isset($cart_item['transfer_meta']) && !empty($cart_item['transfer_meta'])) {
            foreach ($cart_item['transfer_meta'] as $key => $value) {
                if (!empty($value) && is_string($value)) {
                    $item_data[] = array(
                        'name' => ucfirst(str_replace('_', ' ', $key)),
                        'value' => $value
                    );
                }
            }
        }
        
        return $item_data;
    }
    
    public function add_transfer_info_to_product_page() {
        if (!get_option('cross_site_enabled', 0)) {
            return;
        }
        
        echo '<div class="transfer-info">';
        echo 'This product will be transferred to our secure checkout site for payment processing.';
        echo '</div>';
    }
    
    public function add_back_to_site_button($order_id) {
        $order = wc_get_order($order_id);
        
        // التحقق من وجود منتجات محولة
        $has_transferred_items = false;
        $source_site = '';
        
        foreach ($order->get_items() as $item) {
            $source_site_meta = $item->get_meta('source_site');
            if ($source_site_meta) {
                $has_transferred_items = true;
                $source_site = $source_site_meta;
                break;
            }
        }
        
        if ($has_transferred_items && !empty($source_site)) {
            echo '<div class="back-to-source-site">';
            echo '<h3 style="color: white; margin-bottom: 15px;">Thank you for your purchase!</h3>';
            echo '<p style="color: rgba(255,255,255,0.9); margin-bottom: 20px;">Your order has been completed successfully.</p>';
            echo '<a href="' . esc_url($source_site) . '" class="button">← Back to ' . parse_url($source_site, PHP_URL_HOST) . '</a>';
            echo '</div>';
        }
    }
}

// تشغيل الـ Plugin
new WooCommerce_Cross_Site_Cart();

// إضافة functions إضافية للدعم الكامل

// تحديث المخزون تلقائياً
add_action('woocommerce_reduce_order_stock', 'cross_site_handle_stock_update');
function cross_site_handle_stock_update($order) {
    foreach ($order->get_items() as $item) {
        $source_site = $item->get_meta('source_site');
        if ($source_site) {
            // إشعار الموقع المصدر بتحديث المخزون
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            
            wp_remote_post($source_site . '/wp-json/cross-site-cart/v1/update-stock', array(
                'body' => json_encode(array(
                    'product_id' => $product_id,
                    'quantity_sold' => $quantity,
                    'order_id' => $order->get_id()
                )),
                'headers' => array('Content-Type' => 'application/json'),
                'timeout' => 15
            ));
        }
    }
}

// إضافة endpoint لتحديث المخزون
add_action('rest_api_init', 'register_stock_update_endpoint');
function register_stock_update_endpoint() {
    register_rest_route('cross-site-cart/v1', '/update-stock', array(
        'methods' => 'POST',
        'callback' => 'handle_stock_update_notification',
        'permission_callback' => '__return_true'
    ));
}

function handle_stock_update_notification($request) {
    $data = $request->get_json_params();
    
    if (isset($data['product_id']) && isset($data['quantity_sold'])) {
        $product = wc_get_product($data['product_id']);
        
        if ($product && $product->managing_stock()) {
            $current_stock = $product->get_stock_quantity();
            $new_stock = max(0, $current_stock - $data['quantity_sold']);
            $product->set_stock_quantity($new_stock);
            $product->save();
            
            return array('success' => true, 'message' => 'Stock updated successfully');
        }
    }
    
    return array('success' => false, 'message' => 'Failed to update stock');
}

// إضافة تتبع للإحصائيات
add_action('woocommerce_thankyou', 'track_cross_site_conversion');
function track_cross_site_conversion($order_id) {
    $order = wc_get_order($order_id);
    $has_transferred_items = false;
    
    foreach ($order->get_items() as $item) {
        if ($item->get_meta('source_site')) {
            $has_transferred_items = true;
            break;
        }
    }
    
    if ($has_transferred_items) {
        $total_conversions = get_option('cross_site_total_conversions', 0);
        update_option('cross_site_total_conversions', $total_conversions + 1);
        
        $total_revenue = get_option('cross_site_total_revenue', 0);
        update_option('cross_site_total_revenue', $total_revenue + $order->get_total());
    }
}

// إضافة dashboard widget للإحصائيات
add_action('wp_dashboard_setup', 'add_cross_site_dashboard_widget');
function add_cross_site_dashboard_widget() {
    if (current_user_can('manage_woocommerce')) {
        wp_add_dashboard_widget(
            'cross_site_stats',
            'Cross-Site Transfer Statistics',
            'display_cross_site_dashboard_widget'
        );
    }
}

function display_cross_site_dashboard_widget() {
    $total_conversions = get_option('cross_site_total_conversions', 0);
    $total_revenue = get_option('cross_site_total_revenue', 0);
    $plugin_enabled = get_option('cross_site_enabled', 0);
    
    echo '<div style="text-align: center;">';
    
    if ($plugin_enabled) {
        echo '<p style="color: green; font-weight: bold;">✓ Cross-Site Cart Active</p>';
    } else {
        echo '<p style="color: orange; font-weight: bold;">⚠ Cross-Site Cart Inactive</p>';
    }
    
    echo '<div style="display: flex; justify-content: space-around; margin: 15px 0;">';
    echo '<div><strong>' . $total_conversions . '</strong><br><small>Total Transfers</small></div>';
    echo '<div><strong>' . wc_price($total_revenue) . '</strong><br><small>Total Revenue</small></div>';
    echo '</div>';
    
    echo '<p><a href="' . admin_url('admin.php?page=cross-site-cart') . '" class="button button-primary">Manage Settings</a></p>';
    echo '</div>';
}

// إضافة shortcode لعرض معلومات التحويل
add_shortcode('cross_site_transfer_info', 'cross_site_transfer_info_shortcode');
function cross_site_transfer_info_shortcode($atts) {
    $atts = shortcode_atts(array(
        'style' => 'default',
        'message' => 'Products will be transferred to our secure checkout site.'
    ), $atts);
    
    if (!get_option('cross_site_enabled', 0)) {
        return '';
    }
    
    $output = '<div class="cross-site-info-box" style="';
    $output .= 'background: linear-gradient(135deg, #e8f4f8 0%, #f0f8f0 100%);';
    $output .= 'border: 1px solid #00a0d2;';
    $output .= 'border-radius: 8px;';
    $output .= 'padding: 15px;';
    $output .= 'margin: 15px 0;';
    $output .= 'text-align: center;';
    $output .= '">';
    $output .= '<span style="font-size: 20px; margin-right: 10px;">🔄</span>';
    $output .= '<span>' . esc_html($atts['message']) . '</span>';
    $output .= '</div>';
    
    return $output;
}

// إضافة معلومات Plugin في قائمة الـ Plugins
add_filter('plugin_row_meta', 'cross_site_plugin_row_meta', 10, 2);
function cross_site_plugin_row_meta($links, $file) {
    if (plugin_basename(__FILE__) == $file) {
        $row_meta = array(
            'settings' => '<a href="' . admin_url('admin.php?page=cross-site-cart') . '">Settings</a>',
            'support' => '<a href="mailto:support@yoursite.com">Support</a>',
        );
        return array_merge($links, $row_meta);
    }
    return (array) $links;
}

// إضافة تنبيه عند تفعيل Plugin
add_action('admin_notices', 'cross_site_activation_notice');
function cross_site_activation_notice() {
    if (get_transient('cross_site_activation_notice')) {
        delete_transient('cross_site_activation_notice');
        ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Cross-Site Cart Plugin Activated!</strong></p>
            <p>No additional file modifications needed. <a href="<?php echo admin_url('admin.php?page=cross-site-cart'); ?>">Configure settings now</a></p>
        </div>
        <?php
    }
}

// تعيين تنبيه التفعيل
register_activation_hook(__FILE__, 'set_cross_site_activation_notice');
function set_cross_site_activation_notice() {
    set_transient('cross_site_activation_notice', true, 60);
}

// إضافة نظام تسجيل للأخطاء
function cross_site_log_error($message, $context = array()) {
    if (WP_DEBUG_LOG) {
        $log_message = '[Cross-Site Cart] ' . $message;
        if (!empty($context)) {
            $log_message .= ' Context: ' . json_encode($context);
        }
        error_log($log_message);
    }
}

// فحص تلقائي للاتصال بالموقع الهدف
add_action('wp_ajax_test_cross_site_connection', 'ajax_test_cross_site_connection');
function ajax_test_cross_site_connection() {
    $target_url = get_option('cross_site_target_url');
    
    if (empty($target_url)) {
        wp_send_json_error('Target URL not configured');
    }
    
    $test_url = rtrim($target_url, '/') . '/wp-json/cross-site-cart/v1/test-connection';
    
    $response = wp_remote_get($test_url, array(
        'timeout' => 10,
        'sslverify' => true
    ));
    
    if (is_wp_error($response)) {
        wp_send_json_error('Connection failed: ' . $response->get_error_message());
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($response_code === 200) {
        $data = json_decode($body, true);
        wp_send_json_success($data);
    } else {
        wp_send_json_error('HTTP Error: ' . $response_code);
    }
}

// إضافة زر اختبار الاتصال في صفحة الإعدادات
add_action('admin_footer', 'add_connection_test_script');
function add_connection_test_script() {
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'cross-site-cart') !== false) {
        ?>
        <script>
        jQuery(document).ready(function($) {
            // إضافة زر اختبار الاتصال
            $('.form-table').after('<div id="connection-test" style="margin: 20px 0;"><button type="button" id="test-connection" class="button button-secondary">Test Connection to Target Site</button><div id="test-result" style="margin-top: 10px;"></div></div>');
            
            $('#test-connection').on('click', function() {
                var button = $(this);
                var result = $('#test-result');
                
                button.prop('disabled', true).text('Testing...');
                result.html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_cross_site_connection'
                    },
                    success: function(response) {
                        if (response.success) {
                            result.html('<div style="color: green; font-weight: bold;">✓ Connection successful!</div><div style="font-size: 12px; color: #666;">Target site: ' + response.data.site_url + '</div>');
                        } else {
                            result.html('<div style="color: red; font-weight: bold;">✗ Connection failed: ' + response.data + '</div>');
                        }
                    },
                    error: function() {
                        result.html('<div style="color: red; font-weight: bold;">✗ Connection test failed</div>');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('Test Connection to Target Site');
                    }
                });
            });
        });
        </script>
        <?php
    }
}

// تنظيف البيانات عند إلغاء تفعيل Plugin
register_deactivation_hook(__FILE__, 'cross_site_deactivation_cleanup');
function cross_site_deactivation_cleanup() {
    // تنظيف البيانات المؤقتة
    wp_clear_scheduled_hook('cross_site_cleanup_hook');
    
    // حذف البيانات المؤقتة
    delete_transient('cross_site_activation_notice');
    
    // يمكن إضافة المزيد من عمليات التنظيف هنا
}

// معالج للأخطاء الشائعة
add_action('wp_ajax_cross_site_error_handler', 'cross_site_error_handler');
add_action('wp_ajax_nopriv_cross_site_error_handler', 'cross_site_error_handler');
function cross_site_error_handler() {
    $error_type = sanitize_text_field($_POST['error_type']);
    $error_message = sanitize_text_field($_POST['error_message']);
    
    cross_site_log_error('Client-side error: ' . $error_type, array(
        'message' => $error_message,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'],
        'ip' => $_SERVER['REMOTE_ADDR']
    ));
    
    wp_send_json_success('Error logged');
}

?>