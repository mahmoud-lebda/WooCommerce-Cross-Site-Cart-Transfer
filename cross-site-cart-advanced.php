<?php
/**
 * Advanced Functions for Cross-Site Cart Transfer
 * هذا الملف يحتوي على functions إضافية للتحكم المتقدم
 */

// إضافة hooks إضافية للتحكم في العملية
add_action('cross_site_before_transfer', 'log_transfer_attempt');
add_action('cross_site_after_transfer', 'log_transfer_success');
add_action('cross_site_transfer_failed', 'log_transfer_failure');

// تسجيل محاولات التحويل
function log_transfer_attempt($product_data) {
    error_log('Cross-site transfer attempt for product: ' . $product_data['name']);
}

function log_transfer_success($product_data) {
    error_log('Cross-site transfer successful for product: ' . $product_data['name']);
}

function log_transfer_failure($product_data, $error) {
    error_log('Cross-site transfer failed for product: ' . $product_data['name'] . ' Error: ' . $error);
}

// إضافة class للتحكم في عمليات التحويل المتقدمة
class Cross_Site_Cart_Advanced {
    
    public function __construct() {
        add_filter('cross_site_product_data', array($this, 'enhance_product_data'), 10, 2);
        add_filter('cross_site_cart_item_data', array($this, 'enhance_cart_item_data'), 10, 3);
        add_action('woocommerce_cart_item_removed', array($this, 'handle_cart_item_removal'));
        add_action('woocommerce_order_status_completed', array($this, 'handle_order_completion'));
    }
    
    // تحسين بيانات المنتج قبل التحويل
    public function enhance_product_data($product_data, $product) {
        // إضافة معلومات إضافية للمنتج
        $product_data['categories'] = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
        $product_data['tags'] = wp_get_post_terms($product->get_id(), 'product_tag', array('fields' => 'names'));
        $product_data['attributes'] = $product->get_attributes();
        
        // إضافة صور المنتج
        $product_data['images'] = array();
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
        
        return $product_data;
    }
    
    // تحسين بيانات عنصر السلة
    public function enhance_cart_item_data($cart_item_data, $product_id, $variation_id) {
        // إضافة معلومات إضافية لعنصر السلة
        $cart_item_data['transfer_timestamp'] = current_time('mysql');
        $cart_item_data['transfer_source'] = home_url();
        $cart_item_data['user_ip'] = $_SERVER['REMOTE_ADDR'];
        
        return $cart_item_data;
    }
    
    // التعامل مع إزالة عناصر السلة
    public function handle_cart_item_removal($cart_item_key) {
        $cart_item = WC()->cart->get_cart_item($cart_item_key);
        
        if (isset($cart_item['source_site'])) {
            // إشعار الموقع المصدر بإزالة العنصر
            $this->notify_source_site_removal($cart_item);
        }
    }
    
    // إشعار الموقع المصدر بإزالة العنصر
    private function notify_source_site_removal($cart_item) {
        $source_site = $cart_item['source_site'];
        $notification_url = $source_site . '/wp-json/cross-site-cart/v1/item-removed';
        
        wp_remote_post($notification_url, array(
            'body' => json_encode(array(
                'product_id' => $cart_item['product_id'],
                'removed_at' => current_time('mysql')
            )),
            'headers' => array('Content-Type' => 'application/json')
        ));
    }
    
    // التعامل مع إتمام الطلب
    public function handle_order_completion($order_id) {
        $order = wc_get_order($order_id);
        
        // البحث عن منتجات محولة في الطلب
        $transferred_items = array();
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('source_site')) {
                $transferred_items[] = array(
                    'product_id' => $item->get_product_id(),
                    'quantity' => $item->get_quantity(),
                    'total' => $item->get_total(),
                    'source_site' => $item->get_meta('source_site')
                );
            }
        }
        
        // إرسال تقرير للمواقع المصدرية
        foreach ($transferred_items as $item) {
            $this->send_completion_report($item, $order);
        }
    }
    
    // إرسال تقرير إتمام الطلب
    private function send_completion_report($item, $order) {
        $source_site = $item['source_site'];
        $report_url = $source_site . '/wp-json/cross-site-cart/v1/order-completed';
        
        wp_remote_post($report_url, array(
            'body' => json_encode(array(
                'order_id' => $order->get_id(),
                'order_total' => $order->get_total(),
                'order_status' => $order->get_status(),
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'item_total' => $item['total'],
                'completed_at' => current_time('mysql'),
                'customer_email' => $order->get_billing_email(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
            )),
            'headers' => array('Content-Type' => 'application/json')
        ));
    }
}

// تشغيل الكلاس المتقدم
new Cross_Site_Cart_Advanced();

// إضافة functions للتحكم في السعر والخصومات
add_filter('woocommerce_cart_item_price', 'display_transferred_item_price', 10, 3);
add_filter('woocommerce_cart_item_subtotal', 'display_transferred_item_subtotal', 10, 3);

function display_transferred_item_price($price, $cart_item, $cart_item_key) {
    if (isset($cart_item['original_price'])) {
        return wc_price($cart_item['original_price']);
    }
    return $price;
}

function display_transferred_item_subtotal($subtotal, $cart_item, $cart_item_key) {
    if (isset($cart_item['original_price'])) {
        return wc_price($cart_item['original_price'] * $cart_item['quantity']);
    }
    return $subtotal;
}

// إضافة معلومات إضافية في صفحة السلة
add_filter('woocommerce_get_item_data', 'add_transfer_info_to_cart', 10, 2);
function add_transfer_info_to_cart($item_data, $cart_item) {
    if (isset($cart_item['source_site'])) {
        $item_data[] = array(
            'name' => 'Transferred from',
            'value' => parse_url($cart_item['source_site'], PHP_URL_HOST)
        );
    }
    
    if (isset($cart_item['transfer_meta']) && !empty($cart_item['transfer_meta'])) {
        foreach ($cart_item['transfer_meta'] as $key => $value) {
            $item_data[] = array(
                'name' => ucfirst(str_replace('_', ' ', $key)),
                'value' => $value
            );
        }
    }
    
    return $item_data;
}

// التحكم في حالة المخزون للمنتجات المحولة
add_action('woocommerce_reduce_order_stock', 'handle_transferred_product_stock');
function handle_transferred_product_stock($order) {
    foreach ($order->get_items() as $item) {
        if ($item->get_meta('source_site')) {
            // إشعار الموقع المصدر بتقليل المخزون
            $source_site = $item->get_meta('source_site');
            $stock_url = $source_site . '/wp-json/cross-site-cart/v1/reduce-stock';
            
            wp_remote_post($stock_url, array(
                'body' => json_encode(array(
                    'product_id' => $item->get_product_id(),
                    'quantity' => $item->get_quantity(),
                    'order_id' => $order->get_id()
                )),
                'headers' => array('Content-Type' => 'application/json')
            ));
        }
    }
}

// إضافة API endpoints إضافية
add_action('rest_api_init', 'register_additional_cross_site_endpoints');
function register_additional_cross_site_endpoints() {
    // endpoint لاستقبال إشعارات إزالة العناصر
    register_rest_route('cross-site-cart/v1', '/item-removed', array(
        'methods' => 'POST',
        'callback' => 'handle_item_removed_notification',
        'permission_callback' => '__return_true'
    ));
    
    // endpoint لاستقبال تقارير إتمام الطلبات
    register_rest_route('cross-site-cart/v1', '/order-completed', array(
        'methods' => 'POST',
        'callback' => 'handle_order_completion_notification',
        'permission_callback' => '__return_true'
    ));
    
    // endpoint لتقليل المخزون
    register_rest_route('cross-site-cart/v1', '/reduce-stock', array(
        'methods' => 'POST',
        'callback' => 'handle_stock_reduction_notification',
        'permission_callback' => '__return_true'
    ));
    
    // endpoint للتحقق من حالة المنتج
    register_rest_route('cross-site-cart/v1', '/check-product', array(
        'methods' => 'GET',
        'callback' => 'check_product_status',
        'permission_callback' => '__return_true'
    ));
}

function handle_item_removed_notification($request) {
    $data = $request->get_json_params();
    
    // تسجيل عملية الإزالة
    error_log('Product removed from target site: ' . $data['product_id']);
    
    return array('success' => true, 'message' => 'Notification received');
}

function handle_order_completion_notification($request) {
    $data = $request->get_json_params();
    
    // تسجيل إتمام الطلب
    error_log('Order completed on target site: ' . $data['order_id']);
    
    // يمكن إضافة logic إضافي هنا مثل تحديث الإحصائيات
    update_option('cross_site_completed_orders', get_option('cross_site_completed_orders', 0) + 1);
    
    return array('success' => true, 'message' => 'Order completion recorded');
}

function handle_stock_reduction_notification($request) {
    $data = $request->get_json_params();
    
    // تقليل المخزون للمنتج المحدد
    $product = wc_get_product($data['product_id']);
    if ($product && $product->managing_stock()) {
        $new_stock = $product->get_stock_quantity() - $data['quantity'];
        $product->set_stock_quantity($new_stock);
        $product->save();
        
        error_log('Stock reduced for product ' . $data['product_id'] . ' by ' . $data['quantity']);
    }
    
    return array('success' => true, 'message' => 'Stock updated');
}

function check_product_status($request) {
    $product_id = $request->get_param('product_id');
    $sku = $request->get_param('sku');
    
    if ($sku) {
        $product_id = wc_get_product_id_by_sku($sku);
    }
    
    if (!$product_id) {
        return array('exists' => false, 'message' => 'Product not found');
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        return array('exists' => false, 'message' => 'Product not found');
    }
    
    return array(
        'exists' => true,
        'id' => $product_id,
        'name' => $product->get_name(),
        'price' => $product->get_price(),
        'stock_status' => $product->get_stock_status(),
        'stock_quantity' => $product->get_stock_quantity()
    );
}

// إضافة shortcode لعرض إحصائيات التحويل
add_shortcode('cross_site_stats', 'display_cross_site_stats');
function display_cross_site_stats($atts) {
    $atts = shortcode_atts(array(
        'show' => 'all' // all, transfers, completions
    ), $atts);
    
    $completed_orders = get_option('cross_site_completed_orders', 0);
    $total_transfers = get_option('cross_site_total_transfers', 0);
    
    $output = '<div class="cross-site-stats">';
    
    if ($atts['show'] == 'all' || $atts['show'] == 'transfers') {
        $output .= '<div class="stat-item">Total Transfers: ' . $total_transfers . '</div>';
    }
    
    if ($atts['show'] == 'all' || $atts['show'] == 'completions') {
        $output .= '<div class="stat-item">Completed Orders: ' . $completed_orders . '</div>';
    }
    
    $output .= '</div>';
    
    return $output;
}

// إضافة CSS للإحصائيات
add_action('wp_head', 'cross_site_stats_css');
function cross_site_stats_css() {
    echo '<style>
        .cross-site-stats {
            display: flex;
            gap: 20px;
            padding: 15px;
            background: #f1f1f1;
            border-radius: 5px;
            margin: 10px 0;
        }
        .stat-item {
            background: white;
            padding: 10px 15px;
            border-radius: 3px;
            font-weight: bold;
            text-align: center;
        }
    </style>';
}

// إضافة تنبيهات للمدير
add_action('admin_notices', 'cross_site_admin_notices');
function cross_site_admin_notices() {
    if (!get_option('cross_site_enabled', 0)) {
        return;
    }
    
    $target_url = get_option('cross_site_target_url', '');
    if (empty($target_url)) {
        echo '<div class="notice notice-warning"><p>Cross-Site Cart: Please configure the target site URL in WooCommerce settings.</p></div>';
    }
    
    $api_key = get_option('cross_site_api_key', '');
    if (empty($api_key)) {
        echo '<div class="notice notice-warning"><p>Cross-Site Cart: Please configure the API key for secure transfers.</p></div>';
    }
}

// إضافة معلومات في صفحة المنتج
add_action('woocommerce_single_product_summary', 'add_transfer_info_to_product_page', 25);
function add_transfer_info_to_product_page() {
    if (!get_option('cross_site_enabled', 0)) {
        return;
    }
    
    echo '<div class="transfer-info" style="margin: 10px 0; padding: 10px; background: #e8f4f8; border-radius: 5px;">';
    echo '<small>🔄 This product will be transferred to our secure checkout site for payment processing.</small>';
    echo '</div>';
}

// إضافة تتبع للتحويلات
add_action('cross_site_before_transfer', 'track_transfer_attempt');
function track_transfer_attempt($product_data) {
    $total_transfers = get_option('cross_site_total_transfers', 0);
    update_option('cross_site_total_transfers', $total_transfers + 1);
}

// إضافة نظام cache للمنتجات المحولة
class Cross_Site_Product_Cache {
    private $cache_key = 'cross_site_products_cache';
    private $cache_duration = 3600; // ساعة واحدة
    
    public function get_cached_product($sku) {
        $cache = get_transient($this->cache_key);
        return isset($cache[$sku]) ? $cache[$sku] : false;
    }
    
    public function cache_product($sku, $product_data) {
        $cache = get_transient($this->cache_key) ?: array();
        $cache[$sku] = $product_data;
        set_transient($this->cache_key, $cache, $this->cache_duration);
    }
    
    public function clear_cache() {
        delete_transient($this->cache_key);
    }
}

// تشغيل نظام الـ cache
$cross_site_cache = new Cross_Site_Product_Cache();

?>