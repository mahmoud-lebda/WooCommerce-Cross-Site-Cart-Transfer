# Cross-Site Cart Transfer Plugin

## Overview

A powerful WordPress/WooCommerce plugin that allows seamless product transfers between two WooCommerce sites. When a customer clicks "Add to Cart" on Site 1, they are automatically redirected to Site 2 with the product already in their cart, maintaining all product data, pricing, and metadata.

## 🚀 Key Features

- **Seamless Transfer**: Transfer products between sites without manual intervention
- **Price Preservation**: Maintains original pricing from source site
- **Metadata Transfer**: Transfers all custom fields and product metadata
- **SKU-Based Matching**: Finds existing products by SKU or creates new ones
- **Auto Product Creation**: Creates products on target site if they don't exist
- **Return Button**: Adds "Back to Site 1" button on thank you page
- **Security**: Built-in encryption, rate limiting, and SSL support
- **No File Modifications**: Everything works automatically without editing theme files

## 📋 Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.4+
- SSL Certificate (recommended for production)
- Two WordPress/WooCommerce sites

## 📦 Installation

### Step 1: Download and Install

1. Download the plugin files
2. Create folder: `/wp-content/plugins/cross-site-cart/`
3. Upload all files to this folder on **both sites**
4. Activate plugin on **both sites**

### File Structure:
```
/wp-content/plugins/cross-site-cart/
├── cross-site-cart.php (main file)
├── cross-site-cart-advanced.php (optional advanced features)
├── includes/
│   └── class-security.php (security features)
└── README.md
```

### Step 2: Configure Target Site (Site 2)

1. Go to **WooCommerce → Settings → Advanced → REST API**
2. Click **"Add Key"**
3. Set description: "Cross-Site Cart Transfer"
4. User: Administrator
5. Permissions: **Read/Write**
6. Click **"Generate API Key"**
7. **Copy the Consumer Key and Consumer Secret** (you'll need these)

### Step 3: Configure Source Site (Site 1)

1. Go to **WooCommerce → Cross-Site Cart**
2. Check **"Enable Cross-Site Cart"**
3. Enter **Target Site URL**: `https://your-checkout-site.com`
4. Enter **API Key**: `ck_xxxxxxxxxxxxxxxxxx`
5. Enter **API Secret**: `cs_xxxxxxxxxxxxxxxxxx`
6. Click **"Save Changes"**
7. Click **"Test Connection"** to verify setup

## ⚙️ Configuration Options

### Basic Settings

| Setting | Description |
|---------|-------------|
| **Enable Cross-Site Cart** | Turn the plugin on/off |
| **Target Site URL** | Full URL of the checkout site |
| **API Key** | WooCommerce REST API Consumer Key |
| **API Secret** | WooCommerce REST API Consumer Secret |
| **SSL Verification** | Enable/disable SSL certificate verification |

### Advanced Settings (Optional)

- **Allowed IP Addresses**: Restrict transfers to specific IPs
- **Rate Limiting**: Limit requests per hour (default: 100)
- **Security Logs**: View transfer attempts and security events

## 🛠️ How It Works

### Transfer Process

1. **Customer clicks "Add to Cart"** on Site 1
2. **JavaScript intercepts** the click event
3. **Product data is collected** (name, price, SKU, metadata)
4. **AJAX request sends data** to Site 1 backend
5. **Site 1 sends secure request** to Site 2 API
6. **Site 2 receives product data** and processes it:
   - Searches for existing product by SKU
   - Creates new product if not found
   - Adds product to cart with original pricing
7. **Customer is redirected** to Site 2 cart page
8. **Customer completes purchase** on Site 2
9. **"Back to Site 1" button** appears on thank you page

### Data Transferred

- Product name and description
- Original pricing (preserved exactly)
- SKU and product attributes
- Custom metadata and fields
- Product images and gallery
- Categories and tags
- Variation data (for variable products)

## 🔧 Troubleshooting

### Common Issues

#### 1. SSL Certificate Errors
**Error**: `cURL error 60: SSL certificate problem`

**Solution**:
- Uncheck "SSL Verification" in plugin settings
- Install proper SSL certificate on target site
- Use Cloudflare or Let's Encrypt for free SSL

#### 2. Connection Failed
**Error**: `Connection failed: Could not resolve host`

**Solutions**:
- Verify target site URL is correct
- Check if target site is accessible
- Ensure target site has plugin installed and activated

#### 3. HTTP 500 Error
**Error**: `HTTP Error: 500`

**Solutions**:
- Check if plugin is activated on target site
- Verify WooCommerce is active on target site
- Check debug.log for detailed error messages

#### 4. Products Not Added to Cart
**Solutions**:
- Verify API keys are correct
- Check product is purchasable
- Enable WordPress debug logging
- Check WooCommerce cart initialization

### Debug Mode

Add to `wp-config.php` for detailed logging:
```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check logs at: `/wp-content/debug.log`

## 🔒 Security Features

### Built-in Security

- **API Authentication**: Secure REST API communication
- **Rate Limiting**: Prevents abuse (100 requests/hour default)
- **IP Whitelisting**: Restrict access to specific IPs
- **SSL Support**: Encrypted data transmission
- **Input Validation**: Sanitized and validated data
- **Security Headers**: CORS, XSS, and content-type protection

### Security Best Practices

1. **Use SSL certificates** on both sites
2. **Regularly update** WordPress, WooCommerce, and the plugin
3. **Monitor security logs** for suspicious activity
4. **Use strong API keys** and rotate them periodically
5. **Enable IP whitelisting** if sites have static IPs

## 📊 Monitoring and Analytics

### Dashboard Widget

View transfer statistics in WordPress admin dashboard:
- Total successful transfers
- Total revenue from transfers
- Plugin status and health

### Available Shortcodes

Display transfer information on frontend:
```php
[cross_site_transfer_info message="Products will be transferred to secure checkout"]
[cross_site_stats show="all"]
```

### Logs and Reports

- Security event logging
- Transfer attempt tracking
- Error logging with stack traces
- Success/failure statistics

## 🎨 Customization

### CSS Customization

The plugin includes built-in styling, but you can customize:

```css
/* Transfer info box */
.transfer-info {
    background: your-color;
    border-color: your-border-color;
}

/* Back to site button */
.back-to-source-site .button {
    background: your-button-color;
    color: your-text-color;
}

/* Loading state */
.loading {
    opacity: 0.6;
    cursor: wait;
}
```

### PHP Hooks

Available hooks for developers:

```php
// Before transfer
do_action('cross_site_before_transfer', $product_data);

// After successful transfer
do_action('cross_site_after_transfer', $product_data);

// On transfer failure
do_action('cross_site_transfer_failed', $product_data, $error);

// Filter product data
$product_data = apply_filters('cross_site_product_data', $product_data, $product);
```

## 🌐 Multi-Site Considerations

### Network/Multisite Setup

- Install plugin on each site individually
- Configure each site pair separately
- Monitor network-wide statistics if needed

### Multiple Target Sites

To support multiple checkout sites:
1. Duplicate plugin folder with different name
2. Modify plugin constants and names
3. Configure each instance separately

## 📈 Performance Optimization

### Recommended Settings

- **Enable object caching** (Redis/Memcached)
- **Use CDN** for better global performance
- **Optimize database** regularly
- **Monitor server resources** during high traffic

### Caching Considerations

- Product cache duration: 1 hour (default)
- Session data: Stored in WooCommerce sessions
- Security logs: Auto-cleaned after 30 days

## 🆘 Support and Maintenance

### Regular Maintenance

1. **Update plugins** regularly
2. **Monitor error logs** weekly
3. **Test transfers** after updates
4. **Review security logs** monthly
5. **Backup sites** before major changes

### Getting Help

1. **Check this README** first
2. **Enable debug logging** to identify issues
3. **Test connection** using plugin's test button
4. **Check both sites** have plugin activated
5. **Verify API keys** are correct

### Known Limitations

- Requires JavaScript enabled on client side
- Works with standard WooCommerce products
- Some third-party plugins may need compatibility updates
- Real-time inventory sync requires additional setup

## 📝 Changelog

### Version 1.0.0
- Initial release
- Basic product transfer functionality
- Security features implementation
- SSL support and error handling
- Dashboard integration
- Comprehensive logging system

## 📄 License

This plugin is licensed under GPL v2 or later.

## 🤝 Contributing

1. Fork the repository
2. Create feature branch
3. Make changes and test thoroughly
4. Submit pull request with detailed description

## ⚠️ Disclaimer

- Test thoroughly in staging environment before production use
- Always backup your sites before installation
- Monitor transfers closely during initial setup
- This plugin modifies cart behavior - ensure compatibility with your theme/plugins

---

**Plugin Author**: Your Name  
**Version**: 1.0.0  
**Last Updated**: 2025-07-12  
**Tested up to**: WordPress 6.3, WooCommerce 8.0

For technical support or feature requests, please contact: support@yoursite.com