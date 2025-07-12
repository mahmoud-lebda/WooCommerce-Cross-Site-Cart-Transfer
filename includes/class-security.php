<?php
/**
 * Security Functions for Cross-Site Cart Transfer
 * ملف الحماية والأمان للـ Plugin
 */

// منع الوصول المباشر
if (!defined('ABSPATH')) {
    exit;
}

class Cross_Site_Cart_Security {
    
    private $encryption_key;
    private $allowed_ips;
    private $rate_limit_threshold;
    
    public function __construct() {
        $this->encryption_key = get_option('cross_site_encryption_key', wp_salt());
        $this->allowed_ips = get_option('cross_site_allowed_ips', array());
        $this->rate_limit_threshold = get_option('cross_site_rate_limit', 100);
        
        add_action('rest_api_init', array($this, 'add_security_headers'));
        add_filter('rest_pre_dispatch', array($this, 'check_security'), 10, 3);
        add_action('init', array($this, 'generate_encryption_key'));
    }
    
    // إضافة headers الأمان
    public function add_security_headers() {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('Referrer-Policy: strict-origin-when-cross-origin');
    }
    
    // فحص الأمان قبل معالجة الطلبات
    public function check_security($result, $server, $request) {
        $route = $request->get_route();
        
        // فحص الطلبات المتعلقة بـ Plugin فقط
        if (strpos($route, '/cross-site-cart/') === false) {
            return $result;
        }
        
        // فحص IP Address
        if (!$this->check_ip_whitelist()) {
            return new WP_Error('forbidden_ip', 'Access denied from this IP address', array('status' => 403));
        }
        
        // فحص Rate Limiting
        if (!$this->check_rate_limit()) {
            return new WP_Error('rate_limit_exceeded', 'Rate limit exceeded', array('status' => 429));
        }
        
        // فحص التوقيع
        if (!$this->verify_request_signature($request)) {
            return new WP_Error('invalid_signature', 'Invalid request signature', array('status' => 401));
        }
        
        return $result;
    }
    
    // فحص IP Whitelist
    private function check_ip_whitelist() {
        if (empty($this->allowed_ips)) {
            return true; // إذا لم يتم تحديد IPs، السماح لجميع الطلبات
        }
        
        $client_ip = $this->get_client_ip();
        return in_array($client_ip, $this->allowed_ips);
    }
    
    // الحصول على IP Address الحقيقي
    private function get_client_ip() {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '';
    }
    
    // فحص Rate Limiting
    private function check_rate_limit() {
        $client_ip = $this->get_client_ip();
        $cache_key = 'rate_limit_' . md5($client_ip);
        
        $requests = get_transient($cache_key) ?: 0;
        
        if ($requests >= $this->rate_limit_threshold) {
            return false;
        }
        
        set_transient($cache_key, $requests + 1, 3600); // ساعة واحدة
        return true;
    }
    
    // التحقق من توقيع الطلب
    private function verify_request_signature($request) {
        $signature = $request->get_header('X-Signature');
        if (!$signature) {
            return false;
        }
        
        $body = $request->get_body();
        $timestamp = $request->get_header('X-Timestamp');
        
        // التحقق من صحة الوقت (منع Replay Attacks)
        if (!$timestamp || abs(time() - $timestamp) > 300) { // 5 دقائق
            return false;
        }
        
        $expected_signature = hash_hmac('sha256', $body . $timestamp, $this->encryption_key);
        
        return hash_equals($expected_signature, $signature);
    }
    
    // إنشاء مفتاح التشفير
    public function generate_encryption_key() {
        if (!get_option('cross_site_encryption_key')) {
            $key = wp_generate_password(64, true, true);
            update_option('cross_site_encryption_key', $key);
        }
    }
    
    // تشفير البيانات
    public function encrypt_data($data) {
        $method = 'AES-256-CBC';
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt(json_encode($data), $method, $this->encryption_key, 0, $iv);
        
        return base64_encode($encrypted . '::' . $iv);
    }
    
    // فك تشفير البيانات
    public function decrypt_data($encrypted_data) {
        $method = 'AES-256-CBC';
        list($encrypted, $iv) = explode('::', base64_decode($encrypted_data), 2);
        
        $decrypted = openssl_decrypt($encrypted, $method, $this->encryption_key, 0, $iv);
        
        return json_decode($decrypted, true);
    }
    
    // إنشاء توقيع للطلب
    public function create_request_signature($data, $timestamp) {
        return hash_hmac('sha256', json_encode($data) . $timestamp, $this->encryption_key);
    }
    
    // تسجيل محاولات الاختراق
    public function log_security_event($event_type, $details) {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'event_type' => $event_type,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'details' => $details
        );
        
        $existing_logs = get_option('cross_site_security_logs', array());
        array_unshift($existing_logs, $log_entry);
        
        // الاحتفاظ بآخر 1000 log entry فقط
        if (count($existing_logs) > 1000) {
            $existing_logs = array_slice($existing_logs, 0, 1000);
        }
        
        update_option('cross_site_security_logs', $existing_logs);
    }
    
    // فحص وجود محاولات اختراق
    public function detect_suspicious_activity() {
        $client_ip = $this->get_client_ip();
        $logs = get_option('cross_site_security_logs', array());
        
        $recent_failures = 0;
        $time_threshold = time() - 3600; // آخر ساعة
        
        foreach ($logs as $log) {
            if ($log['ip_address'] === $client_ip && 
                strtotime($log['timestamp']) > $time_threshold &&
                in_array($log['event_type'], array('failed_auth', 'rate_limit_exceeded', 'invalid_signature'))) {
                $recent_failures++;
            }
        }
        
        return $recent_failures > 10; // أكثر من 10 محاولات فاشلة في الساعة
    }
    
    // حظر IP مؤقتاً
    public function temporary_ban_ip($ip, $duration = 3600) {
        $banned_ips = get_option('cross_site_banned_ips', array());
        $banned_ips[$ip] = time() + $duration;
        update_option('cross_site_banned_ips', $banned_ips);
        
        $this->log_security_event('ip_banned', array('ip' => $ip, 'duration' => $duration));
    }
    
    // فحص إذا كان IP محظور
    public function is_ip_banned($ip) {
        $banned_ips = get_option('cross_site_banned_ips', array());
        
        if (isset($banned_ips[$ip])) {
            if ($banned_ips[$ip] > time()) {
                return true;
            } else {
                // إزالة الحظر المنتهي الصلاحية
                unset($banned_ips[$ip]);
                update_option('cross_site_banned_ips', $banned_ips);
            }
        }
        
        return false;
    }
}

// تشغيل كلاس الأمان
new Cross_Site_Cart_Security();

// إضافة middleware للحماية من CSRF
add_action('init', 'cross_site_csrf_protection');
function cross_site_csrf_protection() {
    if (!session_id()) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = wp_generate_password(32, false);
    }
}

// فحص CSRF Token
function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// إضافة حماية ضد SQL Injection
add_filter('cross_site_sanitize_input', 'cross_site_sanitize_sql_input');
function cross_site_sanitize_sql_input($input) {
    global $wpdb;
    
    if (is_array($input)) {
        return array_map('cross_site_sanitize_sql_input', $input);
    }
    
    return $wpdb->prepare('%s', $input);
}

// حماية ضد XSS
function cross_site_sanitize_output($output) {
    if (is_array($output)) {
        return array_map('cross_site_sanitize_output', $output);
    }
    
    return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
}

// فلترة المدخلات
add_filter('cross_site_filter_input', 'cross_site_comprehensive_input_filter');
function cross_site_comprehensive_input_filter($input) {
    // إزالة المحارف الخطيرة
    $dangerous_chars = array('<script', '</script', 'javascript:', 'vbscript:', 'onload=', 'onerror=');
    
    foreach ($dangerous_chars as $char) {
        $input = str_ireplace($char, '', $input);
    }
    
    // تنظيف HTML
    $input = wp_kses($input, array(
        'a' => array('href' => array(), 'title' => array()),
        'br' => array(),
        'em' => array(),
        'strong' => array(),
        'p' => array()
    ));
    
    return trim($input);
}

// مراقبة محاولات الوصول المشبوهة
add_action('wp_login_failed', 'cross_site_monitor_failed_logins');
function cross_site_monitor_failed_logins($username) {
    $security = new Cross_Site_Cart_Security();
    $security->log_security_event('login_failed', array('username' => $username));
    
    if ($security->detect_suspicious_activity()) {
        $security->temporary_ban_ip($security->get_client_ip());
    }
}

// إضافة headers أمان إضافية
add_action('send_headers', 'cross_site_additional_security_headers');
function cross_site_additional_security_headers() {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    header('Content-Security-Policy: default-src \'self\'; script-src \'self\' \'unsafe-inline\'; style-src \'self\' \'unsafe-inline\';');
    header('X-Permitted-Cross-Domain-Policies: none');
}

// فحص التحديثات الأمنية
add_action('wp_version_check', 'cross_site_security_updates_check');
function cross_site_security_updates_check() {
    $current_version = get_option('cross_site_cart_version', '1.0.0');
    $latest_version = '1.0.0'; // يتم جلبها من الخادم
    
    if (version_compare($current_version, $latest_version, '<')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning"><p>Cross-Site Cart: A security update is available. Please update immediately.</p></div>';
        });
    }
}

// تنظيف البيانات التلقائي
add_action('wp', 'schedule_cross_site_security_cleanup');
function schedule_cross_site_security_cleanup() {
    if (!wp_next_scheduled('cross_site_security_cleanup')) {
        wp_schedule_event(time(), 'daily', 'cross_site_security_cleanup');
    }
}

add_action('cross_site_security_cleanup', 'cross_site_cleanup_security_data');
function cross_site_cleanup_security_data() {
    // تنظيف السجلات القديمة
    $logs = get_option('cross_site_security_logs', array());
    $cutoff_time = time() - (30 * 24 * 3600); // 30 يوم
    
    $logs = array_filter($logs, function($log) use ($cutoff_time) {
        return strtotime($log['timestamp']) > $cutoff_time;
    });
    
    update_option('cross_site_security_logs', $logs);
    
    // تنظيف IPs المحظورة المنتهية الصلاحية
    $banned_ips = get_option('cross_site_banned_ips', array());
    $current_time = time();
    
    foreach ($banned_ips as $ip => $ban_time) {
        if ($ban_time < $current_time) {
            unset($banned_ips[$ip]);
        }
    }
    
    update_option('cross_site_banned_ips', $banned_ips);
}

// إضافة صفحة إعدادات الأمان في لوحة التحكم
add_action('admin_menu', 'cross_site_security_admin_menu');
function cross_site_security_admin_menu() {
    add_submenu_page(
        'cross-site-cart',
        'Security Settings',
        'Security',
        'manage_options',
        'cross-site-security',
        'cross_site_security_admin_page'
    );
}

function cross_site_security_admin_page() {
    if (isset($_POST['submit'])) {
        $allowed_ips = array_map('trim', explode("\n", $_POST['allowed_ips']));
        $rate_limit = intval($_POST['rate_limit']);
        
        update_option('cross_site_allowed_ips', array_filter($allowed_ips));
        update_option('cross_site_rate_limit', $rate_limit);
        
        echo '<div class="notice notice-success"><p>Security settings saved!</p></div>';
    }
    
    $allowed_ips = implode("\n", get_option('cross_site_allowed_ips', array()));
    $rate_limit = get_option('cross_site_rate_limit', 100);
    $logs = get_option('cross_site_security_logs', array());
    ?>
    <div class="wrap">
        <h1>Cross-Site Cart Security Settings</h1>
        
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row">Allowed IP Addresses</th>
                    <td>
                        <textarea name="allowed_ips" rows="10" cols="50" class="regular-text"><?php echo esc_textarea($allowed_ips); ?></textarea>
                        <p class="description">One IP address per line. Leave empty to allow all IPs.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Rate Limit (requests per hour)</th>
                    <td><input type="number" name="rate_limit" value="<?php echo esc_attr($rate_limit); ?>" min="1" max="1000" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <h2>Security Logs</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Timestamp</th>
                    <th>Event Type</th>
                    <th>IP Address</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_slice($logs, 0, 50) as $log): ?>
                <tr>
                    <td><?php echo esc_html($log['timestamp']); ?></td>
                    <td><?php echo esc_html($log['event_type']); ?></td>
                    <td><?php echo esc_html($log['ip_address']); ?></td>
                    <td><?php echo esc_html(json_encode($log['details'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// إضافة تنبيهات أمنية في لوحة التحكم
add_action('admin_notices', 'cross_site_security_admin_notices');
function cross_site_security_admin_notices() {
    $logs = get_option('cross_site_security_logs', array());
    $recent_attacks = 0;
    $time_threshold = time() - 3600; // آخر ساعة
    
    foreach ($logs as $log) {
        if (strtotime($log['timestamp']) > $time_threshold && 
            in_array($log['event_type'], array('failed_auth', 'rate_limit_exceeded', 'ip_banned'))) {
            $recent_attacks++;
        }
    }
    
    if ($recent_attacks > 5) {
        echo '<div class="notice notice-error"><p><strong>Security Alert:</strong> ' . $recent_attacks . ' suspicious activities detected in the last hour.</p></div>';
    }
}

?>