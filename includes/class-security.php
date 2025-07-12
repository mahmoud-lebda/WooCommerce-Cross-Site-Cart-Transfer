<?php
/**
 * Security Functions for Cross-Site Cart Transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cross_Site_Cart_Security {
    
    private $encryption_key;
    private $allowed_ips;
    private $rate_limit_threshold;
    
    public function __construct() {
        // Don't initialize here - wait for WordPress to be ready
        add_action('init', array($this, 'init_security'), 1);
        add_action('rest_api_init', array($this, 'add_security_headers'));
        add_filter('rest_pre_dispatch', array($this, 'check_security'), 10, 3);
    }
    
    /**
     * Initialize security after WordPress is loaded
     */
    public function init_security() {
        $this->encryption_key = $this->get_encryption_key();
        $this->allowed_ips = Cross_Site_Cart_Plugin::get_option('allowed_ips', array());
        $this->rate_limit_threshold = Cross_Site_Cart_Plugin::get_option('rate_limit', 100);
    }
    
    /**
     * Get or create encryption key safely
     */
    private function get_encryption_key() {
        $key = Cross_Site_Cart_Plugin::get_option('encryption_key');
        
        if (empty($key)) {
            // Use WordPress constants if available, otherwise generate
            if (defined('AUTH_SALT') && defined('SECURE_AUTH_SALT')) {
                $key = hash('sha256', AUTH_SALT . SECURE_AUTH_SALT);
            } else {
                $key = wp_generate_password(64, true, true);
            }
            Cross_Site_Cart_Plugin::update_option('encryption_key', $key);
        }
        
        return $key;
    }
    
    /**
     * Add security headers
     */
    public function add_security_headers() {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('X-XSS-Protection: 1; mode=block');
            header('Referrer-Policy: strict-origin-when-cross-origin');
        }
    }
    
    /**
     * Check security before processing requests
     */
    public function check_security($result, $server, $request) {
        $route = $request->get_route();
        
        // Only check our plugin routes
        if (strpos($route, '/cross-site-cart/') === false) {
            return $result;
        }

        // Skip security checks for development/localhost
        if ($this->is_development_environment()) {
            return $result;
        }
        
        // Initialize if not done yet
        if (empty($this->encryption_key)) {
            $this->init_security();
        }
        
        // Check IP whitelist (skip if empty)
        if (!empty($this->allowed_ips) && !$this->check_ip_whitelist()) {
            $this->log_security_event('ip_blocked', array('ip' => $this->get_client_ip()));
            return new WP_Error('forbidden_ip', 'Access denied from this IP address', array('status' => 403));
        }
        
        // Check rate limiting (more lenient)
        if (!$this->check_rate_limit()) {
            $this->log_security_event('rate_limit_exceeded', array('ip' => $this->get_client_ip()));
            return new WP_Error('rate_limit_exceeded', 'Rate limit exceeded', array('status' => 429));
        }
        
        return $result;
    }

    /**
     * Check if this is a development environment
     */
    private function is_development_environment() {
        $url = home_url();
        return (
            strpos($url, 'localhost') !== false ||
            strpos($url, '.local') !== false ||
            strpos($url, '.dev') !== false ||
            strpos($url, '127.0.0.1') !== false ||
            (defined('WP_DEBUG') && WP_DEBUG)
        );
    }
    
    /**
     * Check IP whitelist
     */
    private function check_ip_whitelist() {
        if (empty($this->allowed_ips)) {
            return true; // Allow all if no restrictions
        }
        
        $client_ip = $this->get_client_ip();
        return in_array($client_ip, $this->allowed_ips);
    }
    
    /**
     * Get real client IP address
     */
    private function get_client_ip() {
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
        
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
    
    /**
     * Check rate limiting
     */
    private function check_rate_limit() {
        $client_ip = $this->get_client_ip();
        $cache_key = 'cross_site_rate_limit_' . md5($client_ip);
        
        $requests = get_transient($cache_key) ?: 0;
        
        if ($requests >= $this->rate_limit_threshold) {
            return false;
        }
        
        set_transient($cache_key, $requests + 1, HOUR_IN_SECONDS);
        return true;
    }
    
    /**
     * Verify request signature
     */
    public function verify_request_signature($request) {
        $signature = $request->get_header('X-Signature');
        if (!$signature) {
            return false;
        }
        
        $body = $request->get_body();
        $timestamp = $request->get_header('X-Timestamp');
        
        // Check timestamp (prevent replay attacks)
        if (!$timestamp || abs(time() - $timestamp) > 300) { // 5 minutes
            return false;
        }
        
        $expected_signature = hash_hmac('sha256', $body . $timestamp, $this->encryption_key);
        
        return hash_equals($expected_signature, $signature);
    }
    
    /**
     * Encrypt data
     */
    public function encrypt_data($data) {
        if (!function_exists('openssl_encrypt')) {
            return base64_encode(wp_json_encode($data)); // Fallback
        }
        
        $method = 'AES-256-CBC';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt(wp_json_encode($data), $method, $this->encryption_key, 0, $iv);
        
        return base64_encode($encrypted . '::' . $iv);
    }
    
    /**
     * Decrypt data
     */
    public function decrypt_data($encrypted_data) {
        if (!function_exists('openssl_decrypt')) {
            return json_decode(base64_decode($encrypted_data), true); // Fallback
        }
        
        $method = 'AES-256-CBC';
        $data = base64_decode($encrypted_data);
        
        if (strpos($data, '::') === false) {
            return json_decode(base64_decode($encrypted_data), true); // Old format fallback
        }
        
        list($encrypted, $iv) = explode('::', $data, 2);
        $decrypted = openssl_decrypt($encrypted, $method, $this->encryption_key, 0, $iv);
        
        return json_decode($decrypted, true);
    }
    
    /**
     * Create request signature
     */
    public function create_request_signature($data, $timestamp) {
        return hash_hmac('sha256', wp_json_encode($data) . $timestamp, $this->encryption_key);
    }
    
    /**
     * Log security events
     */
    public function log_security_event($event_type, $details) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'event_type' => $event_type,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'details' => $details
        );
        
        $existing_logs = get_option('cross_site_cart_security_logs', array());
        array_unshift($existing_logs, $log_entry);
        
        // Keep only last 1000 entries
        if (count($existing_logs) > 1000) {
            $existing_logs = array_slice($existing_logs, 0, 1000);
        }
        
        update_option('cross_site_cart_security_logs', $existing_logs);
    }
    
    /**
     * Detect suspicious activity
     */
    public function detect_suspicious_activity() {
        $client_ip = $this->get_client_ip();
        $logs = get_option('cross_site_cart_security_logs', array());
        
        $recent_failures = 0;
        $time_threshold = time() - 3600; // Last hour
        
        foreach ($logs as $log) {
            if ($log['ip_address'] === $client_ip && 
                strtotime($log['timestamp']) > $time_threshold &&
                in_array($log['event_type'], array('failed_auth', 'rate_limit_exceeded', 'invalid_signature'))) {
                $recent_failures++;
            }
        }
        
        return $recent_failures > 10; // More than 10 failures in an hour
    }
    
    /**
     * Temporarily ban IP
     */
    public function temporary_ban_ip($ip, $duration = 3600) {
        $banned_ips = get_option('cross_site_cart_banned_ips', array());
        $banned_ips[$ip] = time() + $duration;
        update_option('cross_site_cart_banned_ips', $banned_ips);
        
        $this->log_security_event('ip_banned', array('ip' => $ip, 'duration' => $duration));
    }
    
    /**
     * Check if IP is banned
     */
    public function is_ip_banned($ip) {
        $banned_ips = get_option('cross_site_cart_banned_ips', array());
        
        if (isset($banned_ips[$ip])) {
            if ($banned_ips[$ip] > time()) {
                return true;
            } else {
                // Remove expired ban
                unset($banned_ips[$ip]);
                update_option('cross_site_cart_banned_ips', $banned_ips);
            }
        }
        
        return false;
    }
    
    /**
     * Get security statistics
     */
    public function get_security_stats() {
        $logs = get_option('cross_site_cart_security_logs', array());
        $banned_ips = get_option('cross_site_cart_banned_ips', array());
        
        $stats = array(
            'total_events' => count($logs),
            'banned_ips' => count($banned_ips),
            'recent_events' => 0
        );
        
        $time_threshold = time() - (24 * 3600); // Last 24 hours
        
        foreach ($logs as $log) {
            if (strtotime($log['timestamp']) > $time_threshold) {
                $stats['recent_events']++;
            }
        }
        
        return $stats;
    }
}

// Don't instantiate here - let the main plugin handle it

// CSRF Protection
add_action('init', 'cross_site_cart_csrf_protection');
function cross_site_cart_csrf_protection() {
    if (!session_id()) {
        session_start();
    }
    
    if (!isset($_SESSION['cross_site_csrf_token'])) {
        $_SESSION['cross_site_csrf_token'] = wp_generate_password(32, false);
    }
}

/**
 * Verify CSRF token
 */
function cross_site_cart_verify_csrf_token($token) {
    return isset($_SESSION['cross_site_csrf_token']) && 
           hash_equals($_SESSION['cross_site_csrf_token'], $token);
}

/**
 * Additional security headers
 */
add_action('send_headers', 'cross_site_cart_additional_security_headers');
function cross_site_cart_additional_security_headers() {
    if (!headers_sent()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\';');
        header('X-Permitted-Cross-Domain-Policies: none');
    }
}

/**
 * Monitor failed login attempts
 */
add_action('wp_login_failed', 'cross_site_cart_monitor_failed_logins');
function cross_site_cart_monitor_failed_logins($username) {
    // Only log if our plugin is active
    if (!Cross_Site_Cart_Plugin::get_option('enabled')) {
        return;
    }
    
    $security = new Cross_Site_Cart_Security();
    $security->init_security();
    $security->log_security_event('login_failed', array('username' => $username));
    
    if ($security->detect_suspicious_activity()) {
        $security->temporary_ban_ip($security->get_client_ip());
    }
}

/**
 * Cleanup old security data
 */
add_action('cross_site_cart_cleanup', 'cross_site_cart_cleanup_security_data');
function cross_site_cart_cleanup_security_data() {
    // Clean old security logs (30 days)
    $logs = get_option('cross_site_cart_security_logs', array());
    $cutoff_time = time() - (30 * DAY_IN_SECONDS);
    
    $logs = array_filter($logs, function($log) use ($cutoff_time) {
        return strtotime($log['timestamp']) > $cutoff_time;
    });
    
    update_option('cross_site_cart_security_logs', $logs);
    
    // Clean expired IP bans
    $banned_ips = get_option('cross_site_cart_banned_ips', array());
    $current_time = time();
    
    foreach ($banned_ips as $ip => $ban_time) {
        if ($ban_time < $current_time) {
            unset($banned_ips[$ip]);
        }
    }
    
    update_option('cross_site_cart_banned_ips', $banned_ips);
}