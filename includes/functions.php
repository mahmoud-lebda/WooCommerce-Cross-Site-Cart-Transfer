<?php
/**
 * Helper functions for Cross-Site Cart Transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log errors with context
 */
function cross_site_cart_log_error($message, $context = array()) {
    if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) {
        return;
    }

    $log_message = '[Cross-Site Cart] ' . $message;
    
    if (!empty($context)) {
        $log_message .= ' | Context: ' . wp_json_encode($context);
    }

    error_log($log_message);
}

/**
 * Get plugin option with prefix
 */
function cross_site_cart_get_option($option, $default = false) {
    return Cross_Site_Cart_Plugin::get_option($option, $default);
}

/**
 * Update plugin option with prefix
 */
function cross_site_cart_update_option($option, $value) {
    return Cross_Site_Cart_Plugin::update_option($option, $value);
}

/**
 * Check if plugin is properly configured
 */
function cross_site_cart_is_configured() {
    return !empty(cross_site_cart_get_option('target_url')) &&
           !empty(cross_site_cart_get_option('api_key')) &&
           !empty(cross_site_cart_get_option('api_secret'));
}

/**
 * Check if plugin is enabled and configured
 */
function cross_site_cart_is_active() {
    return cross_site_cart_get_option('enabled') && cross_site_cart_is_configured();
}

/**
 * Get transfer statistics
 */
function cross_site_cart_get_stats() {
    return Cross_Site_Cart_Product_Transfer::get_transfer_stats();
}

/**
 * Format bytes to human readable format
 */
function cross_site_cart_format_bytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

/**
 * Sanitize and validate URL
 */
function cross_site_cart_validate_url($url) {
    $url = sanitize_url($url);
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    
    $parsed = parse_url($url);
    
    // Must have scheme and host
    if (!isset($parsed['scheme']) || !isset($parsed['host'])) {
        return false;
    }
    
    // Must be HTTP or HTTPS
    if (!in_array($parsed['scheme'], array('http', 'https'))) {
        return false;
    }
    
    return $url;
}

/**
 * Check if current request is from allowed IP
 */
function cross_site_cart_is_ip_allowed() {
    $allowed_ips = cross_site_cart_get_option('allowed_ips', array());
    
    if (empty($allowed_ips)) {
        return true; // Allow all if no restrictions set
    }
    
    $client_ip = cross_site_cart_get_client_ip();
    
    return in_array($client_ip, $allowed_ips);
}

/**
 * Get real client IP address
 */
function cross_site_cart_get_client_ip() {
    $ip_headers = array(
        'HTTP_CF_CONNECTING_IP',     // CloudFlare
        'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
        'HTTP_X_FORWARDED',          // Proxy
        'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
        'HTTP_FORWARDED_FOR',        // Proxy
        'HTTP_FORWARDED',            // Proxy
        'REMOTE_ADDR'                // Standard
    );
    
    foreach ($ip_headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            
            // Handle comma-separated IPs
            if (strpos($ip, ',') !== false) {
                $ip = trim(explode(',', $ip)[0]);
            }
            
            // Validate IP
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/**
 * Check rate limit for IP
 */
function cross_site_cart_check_rate_limit($ip = null) {
    if (!$ip) {
        $ip = cross_site_cart_get_client_ip();
    }
    
    $rate_limit = cross_site_cart_get_option('rate_limit', 100);
    $cache_key = 'cross_site_rate_limit_' . md5($ip);
    
    $requests = get_transient($cache_key) ?: 0;
    
    if ($requests >= $rate_limit) {
        return false;
    }
    
    set_transient($cache_key, $requests + 1, HOUR_IN_SECONDS);
    return true;
}

/**
 * Create secure hash for data
 */
function cross_site_cart_create_hash($data) {
    $key = cross_site_cart_get_option('encryption_key');
    
    // If no key exists, create one using WordPress constants
    if (empty($key)) {
        if (defined('AUTH_SALT') && defined('SECURE_AUTH_SALT')) {
            $key = hash('sha256', AUTH_SALT . SECURE_AUTH_SALT);
        } else {
            $key = wp_generate_password(64, true, true);
        }
        cross_site_cart_update_option('encryption_key', $key);
    }
    
    return hash_hmac('sha256', is_array($data) ? wp_json_encode($data) : $data, $key);
}

/**
 * Verify secure hash
 */
function cross_site_cart_verify_hash($data, $hash) {
    $expected_hash = cross_site_cart_create_hash($data);
    return hash_equals($expected_hash, $hash);
}

/**
 * Encrypt sensitive data
 */
function cross_site_cart_encrypt($data) {
    $key = cross_site_cart_get_option('encryption_key');
    
    // If no key exists, create one
    if (empty($key)) {
        if (defined('AUTH_SALT') && defined('SECURE_AUTH_SALT')) {
            $key = hash('sha256', AUTH_SALT . SECURE_AUTH_SALT);
        } else {
            $key = wp_generate_password(64, true, true);
        }
        cross_site_cart_update_option('encryption_key', $key);
    }
    
    $method = 'AES-256-CBC';
    
    if (!function_exists('openssl_encrypt')) {
        return base64_encode(wp_json_encode($data)); // Fallback to base64
    }
    
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
    $encrypted = openssl_encrypt(wp_json_encode($data), $method, $key, 0, $iv);
    
    return base64_encode($encrypted . '::' . $iv);
}

/**
 * Decrypt sensitive data
 */
function cross_site_cart_decrypt($encrypted_data) {
    $key = cross_site_cart_get_option('encryption_key');
    
    // If no key exists, create one
    if (empty($key)) {
        if (defined('AUTH_SALT') && defined('SECURE_AUTH_SALT')) {
            $key = hash('sha256', AUTH_SALT . SECURE_AUTH_SALT);
        } else {
            $key = wp_generate_password(64, true, true);
        }
        cross_site_cart_update_option('encryption_key', $key);
    }
    
    $method = 'AES-256-CBC';
    
    if (!function_exists('openssl_decrypt')) {
        return json_decode(base64_decode($encrypted_data), true); // Fallback
    }
    
    $data = base64_decode($encrypted_data);
    
    if (strpos($data, '::') === false) {
        return json_decode(base64_decode($encrypted_data), true); // Fallback for old data
    }
    
    list($encrypted, $iv) = explode('::', $data, 2);
    $decrypted = openssl_decrypt($encrypted, $method, $key, 0, $iv);
    
    return json_decode($decrypted, true);
}

/**
 * Clean up old data
 */
function cross_site_cart_cleanup_old_data() {
    global $wpdb;
    
    // Clean old transfer logs (older than 90 days)
    $table_name = $wpdb->prefix . 'cross_site_transfers';
    $wpdb->query($wpdb->prepare("
        DELETE FROM {$table_name} 
        WHERE created_at < %s
    ", date('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS))));
    
    // Clean old security logs
    $security_logs = get_option('cross_site_cart_security_logs', array());
    if (!empty($security_logs)) {
        $cutoff_time = time() - (30 * DAY_IN_SECONDS); // 30 days
        
        $security_logs = array_filter($security_logs, function($log) use ($cutoff_time) {
            return strtotime($log['timestamp']) > $cutoff_time;
        });
        
        update_option('cross_site_cart_security_logs', $security_logs);
    }
    
    // Clean expired temporary bans
    $banned_ips = get_option('cross_site_cart_banned_ips', array());
    if (!empty($banned_ips)) {
        $current_time = time();
        
        foreach ($banned_ips as $ip => $ban_time) {
            if ($ban_time < $current_time) {
                unset($banned_ips[$ip]);
            }
        }
        
        update_option('cross_site_cart_banned_ips', $banned_ips);
    }
    
    // Clean orphaned transferred products (not purchased within 7 days)
    $wpdb->query($wpdb->prepare("
        DELETE posts FROM {$wpdb->posts} posts
        INNER JOIN {$wpdb->postmeta} meta ON posts.ID = meta.post_id
        WHERE posts.post_type = 'product'
        AND meta.meta_key = '_cross_site_transferred'
        AND meta.meta_value = '1'
        AND posts.ID NOT IN (
            SELECT DISTINCT product_id 
            FROM {$wpdb->prefix}woocommerce_order_items 
            WHERE order_item_type = 'line_item'
        )
        AND posts.post_date < %s
    ", date('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS))));
}

/**
 * Get system information for debugging
 */
function cross_site_cart_get_system_info() {
    global $wpdb;
    
    return array(
        'plugin_version' => CROSS_SITE_CART_VERSION,
        'wordpress_version' => get_bloginfo('version'),
        'woocommerce_version' => defined('WC_VERSION') ? WC_VERSION : 'Not installed',
        'php_version' => PHP_VERSION,
        'mysql_version' => $wpdb->db_version(),
        'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
        'upload_max_filesize' => ini_get('upload_max_filesize'),
        'post_max_size' => ini_get('post_max_size'),
        'ssl_enabled' => is_ssl(),
        'curl_available' => function_exists('curl_init'),
        'openssl_available' => function_exists('openssl_encrypt'),
        'gd_available' => extension_loaded('gd'),
        'site_url' => home_url(),
        'admin_email' => get_option('admin_email'),
        'timezone' => wp_timezone_string(),
        'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
        'plugin_settings' => array(
            'enabled' => cross_site_cart_get_option('enabled'),
            'target_url_set' => !empty(cross_site_cart_get_option('target_url')),
            'api_credentials_set' => cross_site_cart_is_configured(),
            'ssl_verify' => cross_site_cart_get_option('ssl_verify'),
            'rate_limit' => cross_site_cart_get_option('rate_limit'),
        )
    );
}

/**
 * Generate diagnostic report
 */
function cross_site_cart_generate_diagnostic_report() {
    $info = cross_site_cart_get_system_info();
    $stats = cross_site_cart_get_stats();
    
    $report = "=== Cross-Site Cart Transfer Diagnostic Report ===\n";
    $report .= "Generated: " . current_time('Y-m-d H:i:s') . "\n\n";
    
    $report .= "=== System Information ===\n";
    foreach ($info as $key => $value) {
        if (is_array($value)) {
            $report .= ucfirst(str_replace('_', ' ', $key)) . ":\n";
            foreach ($value as $subkey => $subvalue) {
                $report .= "  " . ucfirst(str_replace('_', ' ', $subkey)) . ": " . ($subvalue ? 'Yes' : 'No') . "\n";
            }
        } else {
            $report .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
        }
    }
    
    $report .= "\n=== Transfer Statistics ===\n";
    foreach ($stats as $key => $value) {
        $report .= ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "\n";
    }
    
    return $report;
}

/**
 * Schedule cleanup events
 */
function cross_site_cart_schedule_cleanup() {
    if (!wp_next_scheduled('cross_site_cart_cleanup')) {
        wp_schedule_event(time(), 'daily', 'cross_site_cart_cleanup');
    }
}

// Hook cleanup function
add_action('cross_site_cart_cleanup', 'cross_site_cart_cleanup_old_data');

/**
 * Shortcode to display transfer information
 */
function cross_site_cart_transfer_info_shortcode($atts) {
    $atts = shortcode_atts(array(
        'message' => __('Products will be transferred to our secure checkout site.', 'cross-site-cart'),
        'style' => 'default'
    ), $atts);
    
    if (!cross_site_cart_is_active()) {
        return '';
    }
    
    ob_start();
    ?>
    <div class="cross-site-transfer-notice">
        <span class="transfer-icon">🔄</span>
        <span class="transfer-message"><?php echo esc_html($atts['message']); ?></span>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('cross_site_transfer_info', 'cross_site_cart_transfer_info_shortcode');

/**
 * Shortcode to display transfer statistics
 */
function cross_site_cart_stats_shortcode($atts) {
    $atts = shortcode_atts(array(
        'show' => 'all' // all, transfers, success_rate, revenue
    ), $atts);
    
    $stats = cross_site_cart_get_stats();
    $total_revenue = get_option('cross_site_cart_total_revenue', 0);
    $success_rate = $stats['total_transfers'] > 0 ? 
        round(($stats['successful_transfers'] / $stats['total_transfers']) * 100, 1) : 0;
    
    ob_start();
    ?>
    <div class="cross-site-stats-display">
        <?php if ($atts['show'] === 'all' || $atts['show'] === 'transfers'): ?>
            <div class="stat-item">
                <span class="stat-number"><?php echo number_format($stats['total_transfers']); ?></span>
                <span class="stat-label"><?php _e('Total Transfers', 'cross-site-cart'); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($atts['show'] === 'all' || $atts['show'] === 'success_rate'): ?>
            <div class="stat-item">
                <span class="stat-number"><?php echo $success_rate; ?>%</span>
                <span class="stat-label"><?php _e('Success Rate', 'cross-site-cart'); ?></span>
            </div>
        <?php endif; ?>
        
        <?php if ($atts['show'] === 'all' || $atts['show'] === 'revenue'): ?>
            <div class="stat-item">
                <span class="stat-number"><?php echo wc_price($total_revenue); ?></span>
                <span class="stat-label"><?php _e('Total Revenue', 'cross-site-cart'); ?></span>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
add_shortcode('cross_site_stats', 'cross_site_cart_stats_shortcode');