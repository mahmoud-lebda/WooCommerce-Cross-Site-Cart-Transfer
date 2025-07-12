<?php
/**
 * Admin functionality for Cross-Site Cart Transfer
 */

if (!defined('ABSPATH')) {
    exit;
}

class Cross_Site_Cart_Admin {

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_test_cross_site_connection', array($this, 'ajax_test_connection'));
        add_action('admin_notices', array($this, 'admin_notices'));
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
    }

    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Cross-Site Cart Transfer', 'cross-site-cart'),
            __('Cross-Site Cart', 'cross-site-cart'),
            'manage_woocommerce',
            'cross-site-cart',
            array($this, 'admin_page')
        );

        add_submenu_page(
            'cross-site-cart',
            __('Transfer Logs', 'cross-site-cart'),
            __('Transfer Logs', 'cross-site-cart'),
            'manage_woocommerce',
            'cross-site-cart-logs',
            array($this, 'logs_page')
        );

        add_submenu_page(
            'cross-site-cart',
            __('Security Settings', 'cross-site-cart'),
            __('Security', 'cross-site-cart'),
            'manage_options',
            'cross-site-cart-security',
            array($this, 'security_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting('cross_site_cart_settings', 'cross_site_cart_enabled');
        register_setting('cross_site_cart_settings', 'cross_site_cart_target_url');
        register_setting('cross_site_cart_settings', 'cross_site_cart_api_key');
        register_setting('cross_site_cart_settings', 'cross_site_cart_api_secret');
        register_setting('cross_site_cart_settings', 'cross_site_cart_ssl_verify');

        register_setting('cross_site_cart_security', 'cross_site_cart_rate_limit');
        register_setting('cross_site_cart_security', 'cross_site_cart_allowed_ips');
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'cross-site-cart') === false) {
            return;
        }

        wp_enqueue_style(
            'cross-site-cart-admin',
            CROSS_SITE_CART_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CROSS_SITE_CART_VERSION
        );

        wp_enqueue_script(
            'cross-site-cart-admin',
            CROSS_SITE_CART_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CROSS_SITE_CART_VERSION,
            true
        );

        wp_localize_script('cross-site-cart-admin', 'crossSiteCartAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cross_site_cart_admin_nonce'),
            'messages' => array(
                'testing' => __('Testing connection...', 'cross-site-cart'),
                'success' => __('Connection successful!', 'cross-site-cart'),
                'failed' => __('Connection failed', 'cross-site-cart')
            )
        ));
    }

    /**
     * Main admin page
     */
    public function admin_page() {
        if (isset($_POST['submit'])) {
            $this->save_settings();
        }

        $enabled = Cross_Site_Cart_Plugin::get_option('enabled');
        $target_url = Cross_Site_Cart_Plugin::get_option('target_url');
        $api_key = Cross_Site_Cart_Plugin::get_option('api_key');
        $api_secret = Cross_Site_Cart_Plugin::get_option('api_secret');
        $ssl_verify = Cross_Site_Cart_Plugin::get_option('ssl_verify', true);

        $stats = Cross_Site_Cart_Product_Transfer::get_transfer_stats();
        ?>
        <div class="wrap">
            <h1><?php _e('Cross-Site Cart Transfer Settings', 'cross-site-cart'); ?></h1>

            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Settings saved successfully!', 'cross-site-cart'); ?></p>
                </div>
            <?php endif; ?>

            <div class="cross-site-cart-admin">
                <div class="admin-content">
                    <form method="post" action="">
                        <?php wp_nonce_field('cross_site_cart_settings', 'cross_site_cart_nonce'); ?>
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php _e('Enable Cross-Site Transfer', 'cross-site-cart'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="cross_site_cart_enabled" value="1" <?php checked($enabled, 1); ?> />
                                        <?php _e('Enable product transfers between sites', 'cross-site-cart'); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('Target Site URL', 'cross-site-cart'); ?></th>
                                <td>
                                    <input type="url" name="cross_site_cart_target_url" value="<?php echo esc_attr($target_url); ?>" class="regular-text" placeholder="https://checkout-site.com" />
                                    <p class="description"><?php _e('The URL of the site where products will be transferred for checkout', 'cross-site-cart'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('API Consumer Key', 'cross-site-cart'); ?></th>
                                <td>
                                    <input type="text" name="cross_site_cart_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="ck_xxxxxxxxxxxxxxxxxxxxxxxx" />
                                    <p class="description"><?php _e('WooCommerce REST API Consumer Key from the target site', 'cross-site-cart'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('API Consumer Secret', 'cross-site-cart'); ?></th>
                                <td>
                                    <input type="password" name="cross_site_cart_api_secret" value="<?php echo esc_attr($api_secret); ?>" class="regular-text" placeholder="cs_xxxxxxxxxxxxxxxxxxxxxxxx" />
                                    <p class="description"><?php _e('WooCommerce REST API Consumer Secret from the target site', 'cross-site-cart'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php _e('SSL Verification', 'cross-site-cart'); ?></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="cross_site_cart_ssl_verify" value="1" <?php checked($ssl_verify, 1); ?> />
                                        <?php _e('Verify SSL certificates (recommended for production)', 'cross-site-cart'); ?>
                                    </label>
                                    <p class="description"><?php _e('Uncheck this if you\'re getting SSL certificate errors during testing', 'cross-site-cart'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <div class="connection-test-section">
                            <h3><?php _e('Connection Test', 'cross-site-cart'); ?></h3>
                            <button type="button" id="test-connection" class="button button-secondary">
                                <?php _e('Test Connection to Target Site', 'cross-site-cart'); ?>
                            </button>
                            <div id="connection-result"></div>
                        </div>

                        <?php submit_button(); ?>
                    </form>
                </div>

                <div class="admin-sidebar">
                    <div class="sidebar-widget stats-widget">
                        <h3><?php _e('Transfer Statistics', 'cross-site-cart'); ?></h3>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <span class="stat-number"><?php echo number_format($stats['total_transfers']); ?></span>
                                <span class="stat-label"><?php _e('Total Transfers', 'cross-site-cart'); ?></span>
                            </div>
                            <div class="stat-item success">
                                <span class="stat-number"><?php echo number_format($stats['successful_transfers']); ?></span>
                                <span class="stat-label"><?php _e('Successful', 'cross-site-cart'); ?></span>
                            </div>
                            <div class="stat-item error">
                                <span class="stat-number"><?php echo number_format($stats['failed_transfers']); ?></span>
                                <span class="stat-label"><?php _e('Failed', 'cross-site-cart'); ?></span>
                            </div>
                            <div class="stat-item pending">
                                <span class="stat-number"><?php echo number_format($stats['pending_transfers']); ?></span>
                                <span class="stat-label"><?php _e('Pending', 'cross-site-cart'); ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="sidebar-widget setup-widget">
                        <h3><?php _e('Quick Setup Guide', 'cross-site-cart'); ?></h3>
                        <ol>
                            <li><?php _e('Install this plugin on both sites', 'cross-site-cart'); ?></li>
                            <li><?php _e('On target site: Create WooCommerce API keys', 'cross-site-cart'); ?></li>
                            <li><?php _e('On source site: Enter target URL and API keys', 'cross-site-cart'); ?></li>
                            <li><?php _e('Test the connection', 'cross-site-cart'); ?></li>
                            <li><?php _e('Enable the plugin and test transfers', 'cross-site-cart'); ?></li>
                        </ol>
                    </div>

                    <div class="sidebar-widget status-widget">
                        <h3><?php _e('Plugin Status', 'cross-site-cart'); ?></h3>
                        <ul class="status-list">
                            <li class="<?php echo $enabled ? 'status-enabled' : 'status-disabled'; ?>">
                                <?php echo $enabled ? '✓' : '✗'; ?> <?php _e('Plugin Enabled', 'cross-site-cart'); ?>
                            </li>
                            <li class="<?php echo !empty($target_url) ? 'status-enabled' : 'status-disabled'; ?>">
                                <?php echo !empty($target_url) ? '✓' : '✗'; ?> <?php _e('Target URL Set', 'cross-site-cart'); ?>
                            </li>
                            <li class="<?php echo !empty($api_key) && !empty($api_secret) ? 'status-enabled' : 'status-disabled'; ?>">
                                <?php echo !empty($api_key) && !empty($api_secret) ? '✓' : '✗'; ?> <?php _e('API Keys Set', 'cross-site-cart'); ?>
                            </li>
                            <li class="<?php echo $ssl_verify ? 'status-enabled' : 'status-warning'; ?>">
                                <?php echo $ssl_verify ? '✓' : '⚠'; ?> <?php _e('SSL Verification', 'cross-site-cart'); ?>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Transfer logs page
     */
    public function logs_page() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'cross_site_transfers';
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        // Get total count
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
        $total_pages = ceil($total_items / $per_page);

        // Get logs
        $logs = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM {$table_name} 
            ORDER BY created_at DESC 
            LIMIT %d OFFSET %d
        ", $per_page, $offset));

        ?>
        <div class="wrap">
            <h1><?php _e('Transfer Logs', 'cross-site-cart'); ?></h1>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="filter_status">
                        <option value=""><?php _e('All statuses', 'cross-site-cart'); ?></option>
                        <option value="initiated"><?php _e('Initiated', 'cross-site-cart'); ?></option>
                        <option value="completed"><?php _e('Completed', 'cross-site-cart'); ?></option>
                        <option value="failed"><?php _e('Failed', 'cross-site-cart'); ?></option>
                    </select>
                    <button class="button"><?php _e('Filter', 'cross-site-cart'); ?></button>
                </div>
                
                <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="displaying-num"><?php printf(__('%d items', 'cross-site-cart'), $total_items); ?></span>
                    <?php echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => '‹',
                        'next_text' => '›',
                        'total' => $total_pages,
                        'current' => $current_page
                    )); ?>
                </div>
                <?php endif; ?>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('ID', 'cross-site-cart'); ?></th>
                        <th><?php _e('Product ID', 'cross-site-cart'); ?></th>
                        <th><?php _e('Status', 'cross-site-cart'); ?></th>
                        <th><?php _e('Source Site', 'cross-site-cart'); ?></th>
                        <th><?php _e('Target Site', 'cross-site-cart'); ?></th>
                        <th><?php _e('Created', 'cross-site-cart'); ?></th>
                        <th><?php _e('Completed', 'cross-site-cart'); ?></th>
                        <th><?php _e('Actions', 'cross-site-cart'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="8"><?php _e('No transfer logs found.', 'cross-site-cart'); ?></td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html($log->id); ?></td>
                                <td><?php echo esc_html($log->source_product_id); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo esc_attr($log->transfer_status); ?>">
                                        <?php echo esc_html(ucfirst($log->transfer_status)); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html(parse_url($log->source_site, PHP_URL_HOST)); ?></td>
                                <td><?php echo esc_html(parse_url($log->target_site, PHP_URL_HOST)); ?></td>
                                <td><?php echo esc_html($log->created_at); ?></td>
                                <td><?php echo esc_html($log->completed_at ?: '-'); ?></td>
                                <td>
                                    <button class="button button-small view-details" data-log-id="<?php echo esc_attr($log->id); ?>">
                                        <?php _e('View Details', 'cross-site-cart'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Security settings page
     */
    public function security_page() {
        if (isset($_POST['submit'])) {
            $this->save_security_settings();
        }

        $rate_limit = Cross_Site_Cart_Plugin::get_option('rate_limit', 100);
        $allowed_ips = Cross_Site_Cart_Plugin::get_option('allowed_ips', array());
        $allowed_ips_text = implode("\n", $allowed_ips);

        ?>
        <div class="wrap">
            <h1><?php _e('Security Settings', 'cross-site-cart'); ?></h1>

            <form method="post">
                <?php wp_nonce_field('cross_site_cart_security', 'cross_site_cart_security_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php _e('Rate Limiting', 'cross-site-cart'); ?></th>
                        <td>
                            <input type="number" name="cross_site_cart_rate_limit" value="<?php echo esc_attr($rate_limit); ?>" min="1" max="1000" />
                            <p class="description"><?php _e('Maximum requests per hour per IP address', 'cross-site-cart'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php _e('Allowed IP Addresses', 'cross-site-cart'); ?></th>
                        <td>
                            <textarea name="cross_site_cart_allowed_ips" rows="10" cols="50" class="regular-text"><?php echo esc_textarea($allowed_ips_text); ?></textarea>
                            <p class="description"><?php _e('One IP address per line. Leave empty to allow all IPs.', 'cross-site-cart'); ?></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <h2><?php _e('Security Logs', 'cross-site-cart'); ?></h2>
            <?php $this->display_security_logs(); ?>
        </div>
        <?php
    }

    /**
     * Display security logs
     */
    private function display_security_logs() {
        $logs = get_option('cross_site_cart_security_logs', array());
        $recent_logs = array_slice($logs, 0, 50); // Show last 50 logs

        if (empty($recent_logs)) {
            echo '<p>' . __('No security events logged yet.', 'cross-site-cart') . '</p>';
            return;
        }

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Timestamp', 'cross-site-cart'); ?></th>
                    <th><?php _e('Event Type', 'cross-site-cart'); ?></th>
                    <th><?php _e('IP Address', 'cross-site-cart'); ?></th>
                    <th><?php _e('Details', 'cross-site-cart'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html($log['timestamp']); ?></td>
                        <td><?php echo esc_html($log['event_type']); ?></td>
                        <td><?php echo esc_html($log['ip_address']); ?></td>
                        <td><?php echo esc_html(wp_json_encode($log['details'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Save main settings
     */
    private function save_settings() {
        if (!wp_verify_nonce($_POST['cross_site_cart_nonce'], 'cross_site_cart_settings')) {
            return;
        }

        Cross_Site_Cart_Plugin::update_option('enabled', isset($_POST['cross_site_cart_enabled']) ? 1 : 0);
        Cross_Site_Cart_Plugin::update_option('target_url', sanitize_url($_POST['cross_site_cart_target_url']));
        Cross_Site_Cart_Plugin::update_option('api_key', sanitize_text_field($_POST['cross_site_cart_api_key']));
        Cross_Site_Cart_Plugin::update_option('api_secret', sanitize_text_field($_POST['cross_site_cart_api_secret']));
        Cross_Site_Cart_Plugin::update_option('ssl_verify', isset($_POST['cross_site_cart_ssl_verify']) ? 1 : 0);

        wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }

    /**
     * Save security settings
     */
    private function save_security_settings() {
        if (!wp_verify_nonce($_POST['cross_site_cart_security_nonce'], 'cross_site_cart_security')) {
            return;
        }

        $rate_limit = intval($_POST['cross_site_cart_rate_limit']);
        $allowed_ips = array_filter(array_map('trim', explode("\n", $_POST['cross_site_cart_allowed_ips'])));

        Cross_Site_Cart_Plugin::update_option('rate_limit', $rate_limit);
        Cross_Site_Cart_Plugin::update_option('allowed_ips', $allowed_ips);

        wp_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }

    /**
     * AJAX test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('cross_site_cart_admin_nonce', 'nonce');

        $transfer = new Cross_Site_Cart_Product_Transfer();
        $result = $transfer->test_connection();

        if ($result['success']) {
            wp_send_json_success($result['data'] ?? $result);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    /**
     * Admin notices
     */
    public function admin_notices() {
        // Activation notice
        if (get_transient('cross_site_cart_activation_notice')) {
            delete_transient('cross_site_cart_activation_notice');
            ?>
            <div class="notice notice-success is-dismissible">
                <p><strong><?php _e('Cross-Site Cart Transfer Plugin Activated!', 'cross-site-cart'); ?></strong></p>
                <p><?php printf(__('Configure your settings <a href="%s">here</a> to start transferring products between sites.', 'cross-site-cart'), admin_url('admin.php?page=cross-site-cart')); ?></p>
            </div>
            <?php
        }

        // Configuration warnings
        if (!Cross_Site_Cart_Plugin::get_option('enabled')) {
            return;
        }

        $warnings = array();

        if (empty(Cross_Site_Cart_Plugin::get_option('target_url'))) {
            $warnings[] = __('Target site URL is not configured.', 'cross-site-cart');
        }

        if (empty(Cross_Site_Cart_Plugin::get_option('api_key')) || empty(Cross_Site_Cart_Plugin::get_option('api_secret'))) {
            $warnings[] = __('API credentials are not configured.', 'cross-site-cart');
        }

        if (!Cross_Site_Cart_Plugin::get_option('ssl_verify')) {
            $warnings[] = __('SSL verification is disabled - not recommended for production.', 'cross-site-cart');
        }

        if (!empty($warnings)) {
            ?>
            <div class="notice notice-warning">
                <p><strong><?php _e('Cross-Site Cart Transfer:', 'cross-site-cart'); ?></strong></p>
                <ul style="margin-left: 20px;">
                    <?php foreach ($warnings as $warning): ?>
                        <li><?php echo esc_html($warning); ?></li>
                    <?php endforeach; ?>
                </ul>
                <p><a href="<?php echo admin_url('admin.php?page=cross-site-cart'); ?>"><?php _e('Configure settings', 'cross-site-cart'); ?></a></p>
            </div>
            <?php
        }

        // Security alerts
        $this->show_security_alerts();
    }

    /**
     * Show security alerts
     */
    private function show_security_alerts() {
        $logs = get_option('cross_site_cart_security_logs', array());
        $recent_attacks = 0;
        $time_threshold = time() - 3600; // Last hour

        foreach ($logs as $log) {
            if (strtotime($log['timestamp']) > $time_threshold && 
                in_array($log['event_type'], array('failed_auth', 'rate_limit_exceeded', 'ip_banned'))) {
                $recent_attacks++;
            }
        }

        if ($recent_attacks > 5) {
            ?>
            <div class="notice notice-error">
                <p><strong><?php _e('Security Alert:', 'cross-site-cart'); ?></strong> 
                <?php printf(__('%d suspicious activities detected in the last hour.', 'cross-site-cart'), $recent_attacks); ?>
                <a href="<?php echo admin_url('admin.php?page=cross-site-cart-security'); ?>"><?php _e('View details', 'cross-site-cart'); ?></a></p>
            </div>
            <?php
        }
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        wp_add_dashboard_widget(
            'cross_site_cart_stats',
            __('Cross-Site Transfer Statistics', 'cross-site-cart'),
            array($this, 'dashboard_widget_content')
        );
    }

    /**
     * Dashboard widget content
     */
    public function dashboard_widget_content() {
        $enabled = Cross_Site_Cart_Plugin::get_option('enabled');
        $stats = Cross_Site_Cart_Product_Transfer::get_transfer_stats();
        $total_revenue = get_option('cross_site_cart_total_revenue', 0);
        $completed_orders = get_option('cross_site_cart_completed_orders', 0);

        ?>
        <div class="cross-site-dashboard-widget">
            <div class="widget-status">
                <?php if ($enabled): ?>
                    <span class="status-indicator active"></span>
                    <strong><?php _e('Active', 'cross-site-cart'); ?></strong>
                <?php else: ?>
                    <span class="status-indicator inactive"></span>
                    <strong><?php _e('Inactive', 'cross-site-cart'); ?></strong>
                <?php endif; ?>
            </div>

            <div class="widget-stats">
                <div class="stat-row">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo number_format($stats['total_transfers']); ?></span>
                        <span class="stat-label"><?php _e('Total Transfers', 'cross-site-cart'); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo number_format($completed_orders); ?></span>
                        <span class="stat-label"><?php _e('Completed Orders', 'cross-site-cart'); ?></span>
                    </div>
                </div>
                <div class="stat-row">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo wc_price($total_revenue); ?></span>
                        <span class="stat-label"><?php _e('Total Revenue', 'cross-site-cart'); ?></span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo $stats['total_transfers'] > 0 ? round(($stats['successful_transfers'] / $stats['total_transfers']) * 100, 1) : 0; ?>%</span>
                        <span class="stat-label"><?php _e('Success Rate', 'cross-site-cart'); ?></span>
                    </div>
                </div>
            </div>

            <div class="widget-actions">
                <a href="<?php echo admin_url('admin.php?page=cross-site-cart'); ?>" class="button button-primary button-small">
                    <?php _e('Manage Settings', 'cross-site-cart'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=cross-site-cart-logs'); ?>" class="button button-secondary button-small">
                    <?php _e('View Logs', 'cross-site-cart'); ?>
                </a>
            </div>
        </div>

        <style>
        .cross-site-dashboard-widget {
            padding: 10px 0;
        }
        .widget-status {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
        }
        .status-indicator.active {
            background-color: #46b450;
        }
        .status-indicator.inactive {
            background-color: #dc3232;
        }
        .widget-stats {
            margin: 15px 0;
        }
        .stat-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .stat-item {
            text-align: center;
            flex: 1;
        }
        .stat-number {
            display: block;
            font-size: 18px;
            font-weight: bold;
            color: #0073aa;
        }
        .stat-label {
            display: block;
            font-size: 12px;
            color: #666;
        }
        .widget-actions {
            margin-top: 15px;
            display: flex;
            gap: 8px;
        }
        </style>
        <?php
    }
}