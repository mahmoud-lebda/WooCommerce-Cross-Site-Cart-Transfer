<?php
/**
 * Product Transfer Handler for Cross-Site Cart Transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cross_Site_Cart_Product_Transfer {

    private $target_url;
    private $api_key;
    private $api_secret;
    private $ssl_verify;

    public function __construct() {
        $this->target_url = Cross_Site_Cart_Plugin::get_option('target_url');
        $this->api_key = Cross_Site_Cart_Plugin::get_option('api_key');
        $this->api_secret = Cross_Site_Cart_Plugin::get_option('api_secret');
        $this->ssl_verify = Cross_Site_Cart_Plugin::get_option('ssl_verify', true);
    }

    /**
     * Transfer product to target site
     */
    public function transfer_product($product, $quantity, $variation_id = 0, $variation_data = array()) {
        try {
            // Validate configuration
            if (empty($this->target_url) || empty($this->api_key) || empty($this->api_secret)) {
                throw new Exception('Cross-site transfer not properly configured');
            }

            // Collect product data
            $product_data = $this->collect_product_data($product, $quantity, $variation_id, $variation_data);

            // Log transfer attempt
            $this->log_transfer_attempt($product_data);

            // Send to target site
            $response = $this->send_to_target_site($product_data);

            if ($response['success']) {
                $this->log_transfer_success($product_data, $response);
                return array(
                    'success' => true,
                    'redirect_url' => $response['data']['redirect_url'],
                    'message' => 'Product transferred successfully'
                );
            } else {
                $this->log_transfer_failure($product_data, $response['message']);
                return array(
                    'success' => false,
                    'message' => $response['message']
                );
            }

        } catch (Exception $e) {
            $this->log_transfer_error($product->get_id(), $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }

    /**
     * Collect comprehensive product data for transfer
     */
    private function collect_product_data($product, $quantity, $variation_id, $variation_data) {
        // Get the actual product to transfer (variation if applicable)
        $transfer_product = $variation_id ? wc_get_product($variation_id) : $product;

        $product_data = array(
            'original_product_id' => $product->get_id(),
            'variation_id' => $variation_id,
            'sku' => $transfer_product->get_sku() ?: $product->get_sku(),
            'name' => $transfer_product->get_name(),
            'description' => $product->get_description(),
            'short_description' => $product->get_short_description(),
            'price' => $transfer_product->get_price(),
            'regular_price' => $transfer_product->get_regular_price(),
            'sale_price' => $transfer_product->get_sale_price(),
            'quantity' => $quantity,
            'weight' => $transfer_product->get_weight(),
            'dimensions' => array(
                'length' => $transfer_product->get_length(),
                'width' => $transfer_product->get_width(),
                'height' => $transfer_product->get_height()
            ),
            'variation_data' => $variation_data,
            'source_site' => home_url(),
            'timestamp' => current_time('mysql'),
            'meta_data' => array(),
            'images' => array(),
            'categories' => array(),
            'tags' => array(),
            'attributes' => array()
        );

        // Collect meta data
        $meta_data = get_post_meta($product->get_id());
        foreach ($meta_data as $key => $value) {
            // Skip internal WordPress and WooCommerce meta
            if (strpos($key, '_') === 0) {
                continue;
            }
            
            // Only include simple meta values
            if (is_array($value) && count($value) === 1) {
                $product_data['meta_data'][$key] = $value[0];
            } elseif (is_string($value)) {
                $product_data['meta_data'][$key] = $value;
            }
        }

        // Collect images
        $product_data['images'] = $this->collect_product_images($product);

        // Collect taxonomies
        $product_data['categories'] = $this->get_product_terms($product->get_id(), 'product_cat');
        $product_data['tags'] = $this->get_product_terms($product->get_id(), 'product_tag');

        // Collect attributes
        $product_data['attributes'] = $this->collect_product_attributes($product);

        // Add variation-specific data if applicable
        if ($variation_id && $transfer_product) {
            $product_data['variation_attributes'] = $transfer_product->get_variation_attributes();
            $product_data['variation_meta'] = get_post_meta($variation_id);
        }

        return apply_filters('cross_site_cart_product_data', $product_data, $product);
    }

    /**
     * Collect product images
     */
    private function collect_product_images($product) {
        $images = array();
        
        // Featured image
        $featured_image_id = $product->get_image_id();
        if ($featured_image_id) {
            $images[] = $this->format_image_data($featured_image_id);
        }

        // Gallery images
        $gallery_image_ids = $product->get_gallery_image_ids();
        foreach ($gallery_image_ids as $image_id) {
            $images[] = $this->format_image_data($image_id);
        }

        return $images;
    }

    /**
     * Format image data for transfer
     */
    private function format_image_data($image_id) {
        return array(
            'id' => $image_id,
            'url' => wp_get_attachment_url($image_id),
            'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
            'title' => get_the_title($image_id),
            'caption' => wp_get_attachment_caption($image_id)
        );
    }

    /**
     * Get product terms by taxonomy
     */
    private function get_product_terms($product_id, $taxonomy) {
        $terms = wp_get_post_terms($product_id, $taxonomy, array('fields' => 'names'));
        return is_wp_error($terms) ? array() : $terms;
    }

    /**
     * Collect product attributes
     */
    private function collect_product_attributes($product) {
        $attributes = array();
        
        foreach ($product->get_attributes() as $attribute) {
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
                $attributes[$attribute->get_name()] = is_wp_error($terms) ? array() : $terms;
            } else {
                $attributes[$attribute->get_name()] = $attribute->get_options();
            }
        }

        return $attributes;
    }

    /**
     * Send product data to target site
     */
    private function send_to_target_site($product_data) {
        $api_url = rtrim($this->target_url, '/') . '/wp-json/cross-site-cart/v1/receive-product';

        $body = array(
            'product_data' => $product_data,
            'timestamp' => time(),
            'signature' => $this->create_signature($product_data)
        );

        $args = array(
            'body' => wp_json_encode($body),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Basic ' . base64_encode($this->api_key . ':' . $this->api_secret),
                'X-Source-Site' => home_url(),
                'X-Transfer-Version' => CROSS_SITE_CART_VERSION,
                'User-Agent' => 'CrossSiteCart/' . CROSS_SITE_CART_VERSION
            ),
            'timeout' => 30,
            'httpversion' => '1.1',
            'blocking' => true,
            'sslverify' => $this->ssl_verify
        );

        // Add retry logic for SSL issues
        $response = wp_remote_post($api_url, $args);

        // Retry without SSL verification if SSL error
        if (is_wp_error($response) && $this->is_ssl_error($response)) {
            cross_site_cart_log_error('SSL error, retrying without verification: ' . $response->get_error_message());
            
            $args['sslverify'] = false;
            $response = wp_remote_post($api_url, $args);
        }

        return $this->process_response($response);
    }

    /**
     * Check if error is SSL related
     */
    private function is_ssl_error($response) {
        $error_message = $response->get_error_message();
        $ssl_keywords = array('SSL', 'certificate', 'peer certificate', 'SSL_VERIFY_RESULT');
        
        foreach ($ssl_keywords as $keyword) {
            if (stripos($error_message, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Create request signature for security
     */
    private function create_signature($data) {
        $timestamp = time();
        $string_to_sign = wp_json_encode($data) . $timestamp;
        return hash_hmac('sha256', $string_to_sign, Cross_Site_Cart_Plugin::get_option('encryption_key'));
    }

    /**
     * Process API response
     */
    private function process_response($response) {
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => 'Connection failed: ' . $response->get_error_message()
            );
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            return array(
                'success' => false,
                'message' => "HTTP {$response_code}: " . substr($response_body, 0, 200)
            );
        }

        $data = json_decode($response_body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array(
                'success' => false,
                'message' => 'Invalid response format: ' . json_last_error_msg()
            );
        }

        return $data;
    }

    /**
     * Log transfer attempt
     */
    private function log_transfer_attempt($product_data) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cross_site_transfers';
        
        $wpdb->insert(
            $table_name,
            array(
                'source_product_id' => $product_data['original_product_id'],
                'transfer_data' => wp_json_encode($product_data),
                'transfer_status' => 'initiated',
                'source_site' => home_url(),
                'target_site' => $this->target_url,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );

        // Update transfer statistics
        $total_transfers = get_option('cross_site_cart_total_transfers', 0);
        update_option('cross_site_cart_total_transfers', $total_transfers + 1);

        do_action('cross_site_cart_transfer_initiated', $product_data);
    }

    /**
     * Log successful transfer
     */
    private function log_transfer_success($product_data, $response) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cross_site_transfers';
        
        $wpdb->update(
            $table_name,
            array(
                'transfer_status' => 'completed',
                'target_product_id' => $response['data']['product_id'] ?? null,
                'completed_at' => current_time('mysql')
            ),
            array('source_product_id' => $product_data['original_product_id']),
            array('%s', '%d', '%s'),
            array('%d')
        );

        // Update success statistics
        $successful_transfers = get_option('cross_site_cart_successful_transfers', 0);
        update_option('cross_site_cart_successful_transfers', $successful_transfers + 1);

        do_action('cross_site_cart_transfer_completed', $product_data, $response);
    }

    /**
     * Log transfer failure
     */
    private function log_transfer_failure($product_data, $error_message) {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cross_site_transfers';
        
        $wpdb->update(
            $table_name,
            array(
                'transfer_status' => 'failed',
                'error_message' => $error_message,
                'completed_at' => current_time('mysql')
            ),
            array('source_product_id' => $product_data['original_product_id']),
            array('%s', '%s', '%s'),
            array('%d')
        );

        // Update failure statistics
        $failed_transfers = get_option('cross_site_cart_failed_transfers', 0);
        update_option('cross_site_cart_failed_transfers', $failed_transfers + 1);

        do_action('cross_site_cart_transfer_failed', $product_data, $error_message);
    }

    /**
     * Log transfer error
     */
    private function log_transfer_error($product_id, $error_message) {
        cross_site_cart_log_error("Transfer error for product {$product_id}: {$error_message}");
    }

    /**
     * Test connection to target site
     */
    public function test_connection() {
        if (empty($this->target_url)) {
            return array(
                'success' => false,
                'message' => 'Target URL not configured'
            );
        }

        $test_url = rtrim($this->target_url, '/') . '/wp-json/cross-site-cart/v1/test-connection';

        $args = array(
            'timeout' => 15,
            'headers' => array(
                'User-Agent' => 'CrossSiteCart-Test/' . CROSS_SITE_CART_VERSION
            ),
            'sslverify' => $this->ssl_verify
        );

        $response = wp_remote_get($test_url, $args);

        // Retry without SSL if needed
        if (is_wp_error($response) && $this->is_ssl_error($response)) {
            $args['sslverify'] = false;
            $response = wp_remote_get($test_url, $args);
            
            if (!is_wp_error($response)) {
                $result = $this->process_response($response);
                if ($result['success']) {
                    $result['ssl_warning'] = 'Connection successful but SSL verification was disabled';
                }
                return $result;
            }
        }

        return $this->process_response($response);
    }

    /**
     * Get transfer statistics
     */
    public static function get_transfer_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cross_site_transfers';
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total_transfers,
                SUM(CASE WHEN transfer_status = 'completed' THEN 1 ELSE 0 END) as successful_transfers,
                SUM(CASE WHEN transfer_status = 'failed' THEN 1 ELSE 0 END) as failed_transfers,
                SUM(CASE WHEN transfer_status = 'initiated' THEN 1 ELSE 0 END) as pending_transfers
            FROM {$table_name}
        ", ARRAY_A);

        return $stats ?: array(
            'total_transfers' => 0,
            'successful_transfers' => 0,
            'failed_transfers' => 0,
            'pending_transfers' => 0
        );
    }
}