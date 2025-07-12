jQuery(document).ready(function($) {
    'use strict';

    // Cross-Site Cart Admin Handler
    var CrossSiteCartAdmin = {
        
        init: function() {
            this.bindEvents();
            this.initializePage();
        },

        bindEvents: function() {
            // Connection test button
            $(document).on('click', '#test-connection', this.testConnection);
            
            // Log details buttons
            $(document).on('click', '.view-details', this.viewLogDetails);
            
            // Form validation
            $(document).on('submit', 'form', this.validateForm);
            
            // Auto-save settings
            $(document).on('change', 'input, select, textarea', this.autoSave);
        },

        initializePage: function() {
            // Add connection test button if not present
            this.addConnectionTestButton();
            
            // Initialize tooltips
            this.initializeTooltips();
            
            // Check initial configuration
            this.checkConfiguration();
        },

        addConnectionTestButton: function() {
            if ($('#test-connection').length === 0 && $('.form-table').length > 0) {
                var testSection = `
                    <div class="connection-test-section" style="margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 6px;">
                        <h3>${crossSiteCartAdmin.messages.testConnection || 'Test Connection'}</h3>
                        <p>Test the connection to your target site to ensure everything is configured correctly.</p>
                        <button type="button" id="test-connection" class="button button-secondary">
                            ${crossSiteCartAdmin.messages.testConnection || 'Test Connection to Target Site'}
                        </button>
                        <div id="connection-result" style="margin-top: 15px;"></div>
                    </div>
                `;
                $('.form-table').after(testSection);
            }
        },

        testConnection: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $result = $('#connection-result');
            
            // Show loading state
            $button.prop('disabled', true)
                   .text(crossSiteCartAdmin.messages.testing || 'Testing connection...');
            
            $result.html('<div class="testing-indicator">🔄 Testing connection...</div>');

            // Send AJAX request
            $.ajax({
                url: crossSiteCartAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'test_cross_site_connection',
                    nonce: crossSiteCartAdmin.nonce
                },
                timeout: 30000, // 30 seconds
                success: function(response) {
                    CrossSiteCartAdmin.handleTestSuccess(response, $result);
                },
                error: function(xhr, status, error) {
                    CrossSiteCartAdmin.handleTestError(xhr, status, error, $result);
                },
                complete: function() {
                    $button.prop('disabled', false)
                           .text(crossSiteCartAdmin.messages.testConnection || 'Test Connection to Target Site');
                }
            });
        },

        handleTestSuccess: function(response, $result) {
            if (response.success) {
                var data = response.data;
                var resultHtml = `
                    <div class="test-result test-success">
                        <h4>✅ ${crossSiteCartAdmin.messages.success || 'Connection Successful!'}</h4>
                        <div class="test-details">
                            <p><strong>Target Site:</strong> ${data.site_url || 'Unknown'}</p>
                            <p><strong>Site Name:</strong> ${data.site_name || 'Unknown'}</p>
                            <p><strong>WooCommerce Version:</strong> ${data.woocommerce_version || 'Unknown'}</p>
                            <p><strong>Plugin Version:</strong> ${data.plugin_version || 'Unknown'}</p>
                            <p><strong>Server Time:</strong> ${data.timestamp || 'Unknown'}</p>
                        </div>
                        ${data.ssl_warning ? `<div class="ssl-warning">⚠️ ${data.ssl_warning}</div>` : ''}
                    </div>
                `;
                $result.html(resultHtml);
            } else {
                this.handleTestError(null, 'error', response.data, $result);
            }
        },

        handleTestError: function(xhr, status, error, $result) {
            var message = crossSiteCartAdmin.messages.failed || 'Connection failed';
            
            if (typeof error === 'string') {
                message += ': ' + error;
            } else if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                message += ': ' + xhr.responseJSON.data;
            } else if (status === 'timeout') {
                message += ': Connection timed out';
            } else if (xhr && xhr.status === 0) {
                message += ': Network error or CORS issue';
            }

            var resultHtml = `
                <div class="test-result test-error">
                    <h4>❌ ${message}</h4>
                    <div class="troubleshooting">
                        <h5>Troubleshooting Tips:</h5>
                        <ul>
                            <li>Verify the target URL is correct and accessible</li>
                            <li>Ensure the plugin is installed and activated on the target site</li>
                            <li>Check that API credentials are correct</li>
                            <li>Try disabling SSL verification temporarily</li>
                            <li>Check for firewall or server restrictions</li>
                        </ul>
                    </div>
                </div>
            `;
            $result.html(resultHtml);
        },

        viewLogDetails: function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var logId = $button.data('log-id');
            
            // Create modal or expand details
            var $row = $button.closest('tr');
            var $existingDetails = $row.next('.log-details-row');
            
            if ($existingDetails.length > 0) {
                $existingDetails.remove();
                $button.text('View Details');
                return;
            }
            
            $button.text('Loading...');
            
            // Simulate loading details (in real implementation, make AJAX call)
            setTimeout(function() {
                var detailsHtml = `
                    <tr class="log-details-row">
                        <td colspan="8">
                            <div class="log-details-content">
                                <h4>Transfer Details</h4>
                                <div class="details-grid">
                                    <div class="detail-item">
                                        <strong>Log ID:</strong> ${logId}
                                    </div>
                                    <div class="detail-item">
                                        <strong>User Agent:</strong> Cross-Site-Cart/1.0
                                    </div>
                                    <div class="detail-item">
                                        <strong>Request Data:</strong>
                                        <pre>Loading...</pre>
                                    </div>
                                </div>
                                <button class="button button-small close-details">Close Details</button>
                            </div>
                        </td>
                    </tr>
                `;
                
                $row.after(detailsHtml);
                $button.text('Hide Details');
                
                // Handle close button
                $row.next().find('.close-details').on('click', function() {
                    $row.next('.log-details-row').remove();
                    $button.text('View Details');
                });
            }, 500);
        },

        validateForm: function(e) {
            var $form = $(this);
            var isValid = true;
            var errors = [];
            
            // Validate target URL
            var targetUrl = $form.find('input[name="cross_site_cart_target_url"]').val();
            if (targetUrl && !CrossSiteCartAdmin.isValidUrl(targetUrl)) {
                errors.push('Please enter a valid target URL');
                isValid = false;
            }
            
            // Validate API credentials
            var apiKey = $form.find('input[name="cross_site_cart_api_key"]').val();
            var apiSecret = $form.find('input[name="cross_site_cart_api_secret"]').val();
            
            if (targetUrl && (!apiKey || !apiSecret)) {
                errors.push('API credentials are required when target URL is set');
                isValid = false;
            }
            
            if (apiKey && !apiKey.startsWith('ck_')) {
                errors.push('API key should start with "ck_"');
                isValid = false;
            }
            
            if (apiSecret && !apiSecret.startsWith('cs_')) {
                errors.push('API secret should start with "cs_"');
                isValid = false;
            }
            
            // Show errors if any
            if (!isValid) {
                e.preventDefault();
                this.showErrors(errors);
            }
            
            return isValid;
        },

        isValidUrl: function(url) {
            try {
                new URL(url);
                return url.startsWith('http://') || url.startsWith('https://');
            } catch (e) {
                return false;
            }
        },

        showErrors: function(errors) {
            // Remove existing error notices
            $('.cross-site-admin-error').remove();
            
            var errorHtml = `
                <div class="notice notice-error cross-site-admin-error">
                    <h4>Please fix the following errors:</h4>
                    <ul>
                        ${errors.map(error => `<li>${error}</li>`).join('')}
                    </ul>
                </div>
            `;
            
            $('.wrap h1').after(errorHtml);
            
            // Scroll to errors
            $('html, body').animate({
                scrollTop: $('.cross-site-admin-error').offset().top - 20
            }, 300);
        },

        autoSave: function() {
            var $input = $(this);
            var $form = $input.closest('form');
            
            // Only auto-save certain fields
            if (!$input.hasClass('auto-save')) {
                return;
            }
            
            clearTimeout(this.autoSaveTimeout);
            this.autoSaveTimeout = setTimeout(function() {
                CrossSiteCartAdmin.saveSettings($form, true);
            }, 2000);
        },

        saveSettings: function($form, isAutoSave) {
            var formData = $form.serialize() + '&action=save_cross_site_settings';
            
            if (isAutoSave) {
                formData += '&auto_save=1';
            }
            
            $.ajax({
                url: crossSiteCartAdmin.ajaxUrl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (!isAutoSave && response.success) {
                        CrossSiteCartAdmin.showNotice('Settings saved successfully!', 'success');
                    }
                },
                error: function() {
                    if (!isAutoSave) {
                        CrossSiteCartAdmin.showNotice('Failed to save settings', 'error');
                    }
                }
            });
        },

        showNotice: function(message, type) {
            var $notice = $(`<div class="notice notice-${type} is-dismissible cross-site-temp-notice"><p>${message}</p></div>`);
            $('.wrap h1').after($notice);
            
            setTimeout(function() {
                $notice.fadeOut();
            }, 3000);
        },

        initializeTooltips: function() {
            // Add tooltips to help icons
            $('[data-tooltip]').each(function() {
                var $element = $(this);
                var tooltip = $element.data('tooltip');
                
                $element.on('mouseenter', function() {
                    var $tooltip = $(`<div class="cross-site-tooltip">${tooltip}</div>`);
                    $('body').append($tooltip);
                    
                    var offset = $element.offset();
                    $tooltip.css({
                        top: offset.top - $tooltip.outerHeight() - 10,
                        left: offset.left + ($element.outerWidth() / 2) - ($tooltip.outerWidth() / 2)
                    });
                });
                
                $element.on('mouseleave', function() {
                    $('.cross-site-tooltip').remove();
                });
            });
        },

        checkConfiguration: function() {
            var targetUrl = $('input[name="cross_site_cart_target_url"]').val();
            var apiKey = $('input[name="cross_site_cart_api_key"]').val();
            var apiSecret = $('input[name="cross_site_cart_api_secret"]').val();
            var enabled = $('input[name="cross_site_cart_enabled"]').is(':checked');
            
            var $statusWidget = $('.status-widget .status-list');
            if ($statusWidget.length === 0) {
                return;
            }
            
            // Update status indicators
            this.updateStatusIndicator($statusWidget, 'Plugin Enabled', enabled);
            this.updateStatusIndicator($statusWidget, 'Target URL Set', !!targetUrl);
            this.updateStatusIndicator($statusWidget, 'API Keys Set', !!(apiKey && apiSecret));
            
            // Show warning if enabled but not configured
            if (enabled && (!targetUrl || !apiKey || !apiSecret)) {
                this.showConfigurationWarning();
            }
        },

        updateStatusIndicator: function($container, label, status) {
            var $item = $container.find(`li:contains("${label}")`);
            if ($item.length > 0) {
                $item.removeClass('status-enabled status-disabled status-warning');
                $item.addClass(status ? 'status-enabled' : 'status-disabled');
                $item.find('span').first().text(status ? '✓' : '✗');
            }
        },

        showConfigurationWarning: function() {
            if ($('.configuration-warning').length > 0) {
                return;
            }
            
            var warningHtml = `
                <div class="notice notice-warning configuration-warning">
                    <p><strong>Configuration Incomplete:</strong> The plugin is enabled but not fully configured. Please complete the setup to start transferring products.</p>
                </div>
            `;
            
            $('.wrap h1').after(warningHtml);
        }
    };

    // Initialize admin functionality
    CrossSiteCartAdmin.init();

    // Handle dynamic content updates
    $(document).ajaxComplete(function() {
        setTimeout(function() {
            CrossSiteCartAdmin.checkConfiguration();
        }, 100);
    });

    // Add admin styles
    if (!$('#cross-site-cart-admin-css').length) {
        var adminCss = `
            <style id="cross-site-cart-admin-css">
                .connection-test-section {
                    background: #f9f9f9;
                    border: 1px solid #ddd;
                    border-radius: 6px;
                    padding: 20px;
                    margin: 20px 0;
                }
                
                .testing-indicator {
                    color: #0073aa;
                    font-weight: 500;
                }
                
                .test-result {
                    padding: 15px;
                    border-radius: 6px;
                    margin-top: 10px;
                }
                
                .test-success {
                    background: #d4edda;
                    border: 1px solid #c3e6cb;
                    color: #155724;
                }
                
                .test-error {
                    background: #f8d7da;
                    border: 1px solid #f5c6cb;
                    color: #721c24;
                }
                
                .test-details p {
                    margin: 5px 0;
                }
                
                .ssl-warning {
                    background: #fff3cd;
                    border: 1px solid #ffeaa7;
                    color: #856404;
                    padding: 10px;
                    border-radius: 4px;
                    margin-top: 10px;
                }
                
                .troubleshooting ul {
                    margin: 10px 0 0 20px;
                }
                
                .troubleshooting li {
                    margin: 5px 0;
                }
                
                .log-details-content {
                    background: #f9f9f9;
                    padding: 15px;
                    border-radius: 6px;
                }
                
                .details-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                    gap: 15px;
                    margin: 15px 0;
                }
                
                .detail-item strong {
                    display: block;
                    margin-bottom: 5px;
                }
                
                .detail-item pre {
                    background: white;
                    padding: 10px;
                    border: 1px solid #ddd;
                    border-radius: 4px;
                    font-size: 12px;
                    max-height: 200px;
                    overflow-y: auto;
                }
                
                .status-enabled {
                    color: #155724;
                }
                
                .status-disabled {
                    color: #721c24;
                }
                
                .status-warning {
                    color: #856404;
                }
                
                .cross-site-tooltip {
                    position: absolute;
                    background: #333;
                    color: white;
                    padding: 8px 12px;
                    border-radius: 4px;
                    font-size: 12px;
                    max-width: 200px;
                    z-index: 9999;
                    pointer-events: none;
                }
                
                .cross-site-tooltip::after {
                    content: '';
                    position: absolute;
                    top: 100%;
                    left: 50%;
                    margin-left: -5px;
                    border: 5px solid transparent;
                    border-top-color: #333;
                }
                
                @media (max-width: 768px) {
                    .details-grid {
                        grid-template-columns: 1fr;
                    }
                    
                    .connection-test-section {
                        padding: 15px;
                    }
                }
            </style>
        `;
        $('head').append(adminCss);
    }
});