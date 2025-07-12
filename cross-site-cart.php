<?php
/**
 * Plugin Name: WooCommerce Cross-Site Cart Transfer
 * Description: Transfer products from one WooCommerce site to another with cart functionality
 * Version: 1.2.0
 * Author: Smartify Solutions
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 4.0
 * WC tested up to: 8.0
 * Text Domain: cross-site-cart
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CROSS_SITE_CART_VERSION', '1.2.0');
define('CROSS_SITE_CART_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CROSS_SITE_CART_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CROSS_SITE_CART_PLUGIN_FILE', __FILE__);

/**
 * Main Plugin Class
 */
class Cross_Site_Cart_Plugin {

    /**
     * Single instance of the plugin
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    public $admin;
    public $frontend;
    public $api;
    public $security;

    /**
     * Get single instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->load_dependencies();
        // Don't initialize components here - wait for WordPress to be ready
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('plugins_loaded', array($this, 'check_requirements'), 5);
        add_action('init', array($this, 'init'), 10);
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Load required files
     */
    private function load_dependencies() {
        // Core classes
        require_once CROSS_SITE_CART_PLUGIN_DIR . 'includes/functions.php';
        require_once CROSS_SITE_CART_PLUGIN_DIR . 'includes/class-security.php';
        require_once CROSS_SITE_CART_PLUGIN_DIR . 'includes/class-cart-handler.php';
        require_once CROSS_SITE_CART_PLUGIN_DIR . 'includes/class-product-transfer.php';
        require_once CROSS_SITE_CART_PLUGIN_DIR . 'includes/class-api.php';
        require_once CROSS_SITE_CART_PLUGIN_DIR . 'includes/class-frontend.php';
        require_once CROSS_SITE_CART_PLUGIN_DIR . 'includes/class-admin.php';
    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Only initialize if WordPress and WooCommerce are ready
        if ($this->check_requirements()) {
            $this->security = new Cross_Site_Cart_Security();
            $this->api = new Cross_Site_Cart_API();
            $this->frontend = new Cross_Site_Cart_Frontend();
            $this->admin = new Cross_Site_Cart_Admin();
        }
    }

    /**
     * Check plugin requirements
     */
    public function check_requirements() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        return true;
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>Cross-Site Cart Transfer:</strong> WooCommerce is required but not active. Please install and activate WooCommerce.</p></div>';
    }

    /**
     * Initialize plugin
     */
    public function init() {
        // Load text domain
        load_plugin_textdomain('cross-site-cart', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Initialize components after WordPress is fully loaded
        $this->init_components();
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();
        
        // Set default options
        $this->set_default_options();
        
        // Schedule cleanup events
        $this->schedule_events();
        
        // Set activation notice
        set_transient('cross_site_cart_activation_notice', true, 60);
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('cross_site_cart_cleanup');
        
        // Clear transients
        delete_transient('cross_site_cart_activation_notice');
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Transfer logs table - Updated structure
        $table_name = $wpdb->prefix . 'cross_site_transfers';
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            source_product_id mediumint(9) NOT NULL,
            target_product_id mediumint(9) DEFAULT NULL,
            transfer_data longtext NOT NULL,
            transfer_status varchar(20) DEFAULT 'pending',
            source_site varchar(255) DEFAULT NULL,
            target_site varchar(255) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            error_message text DEFAULT NULL,
            PRIMARY KEY (id),
            KEY source_product_id (source_product_id),
            KEY transfer_status (transfer_status),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Update table if it exists but missing columns
        $this->update_database_tables();
    }

    /**
     * Update existing database tables to add missing columns
     */
    private function update_database_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cross_site_transfers';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name));
        
        if ($table_exists) {
            // Add missing columns if they don't exist
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table_name}");
            
            if (!in_array('source_site', $columns)) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN source_site varchar(255) DEFAULT NULL");
            }
            
            if (!in_array('target_site', $columns)) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN target_site varchar(255) DEFAULT NULL");
            }
            
            if (!in_array('error_message', $columns)) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN error_message text DEFAULT NULL");
            }
        }
    }

    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $defaults = array(
            'cross_site_cart_enabled' => 0,
            'cross_site_cart_target_url' => '',
            'cross_site_cart_api_key' => '',
            'cross_site_cart_api_secret' => '',
            'cross_site_cart_ssl_verify' => 1,
            'cross_site_cart_rate_limit' => 100,
            'cross_site_cart_allowed_ips' => array(),
            'cross_site_cart_encryption_key' => wp_generate_password(64, true, true),
            'cross_site_cart_version' => CROSS_SITE_CART_VERSION
        );

        foreach ($defaults as $option => $value) {
            if (false === get_option($option)) {
                update_option($option, $value);
            }
        }
    }

    /**
     * Schedule cleanup events
     */
    private function schedule_events() {
        if (!wp_next_scheduled('cross_site_cart_cleanup')) {
            wp_schedule_event(time(), 'daily', 'cross_site_cart_cleanup');
        }
    }

    /**
     * Get plugin option
     */
    public static function get_option($option, $default = false) {
        return get_option('cross_site_cart_' . $option, $default);
    }

    /**
     * Update plugin option
     */
    public static function update_option($option, $value) {
        return update_option('cross_site_cart_' . $option, $value);
    }

    /**
     * Delete plugin option
     */
    public static function delete_option($option) {
        return delete_option('cross_site_cart_' . $option);
    }
}

/**
 * Initialize the plugin
 */
function cross_site_cart() {
    return Cross_Site_Cart_Plugin::get_instance();
}

// Start the plugin
cross_site_cart();