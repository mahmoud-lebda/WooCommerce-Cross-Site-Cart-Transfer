jQuery(document).ready(function($) {
    'use strict';

    // Cross-Site Cart Transfer Frontend Handler
    var CrossSiteCart = {
        
        init: function() {
            this.bindEvents();
            this.initializeCart();
        },

        bindEvents: function() {
            // Handle all add to cart buttons
            $(document).on('click', '.single_add_to_cart_button, .add_to_cart_button', this.handleAddToCart);
            
            // Handle variation form submissions
            $(document).on('submit', 'form.variations_form', this.handleVariationForm);
            
            // Handle simple product forms
            $(document).on('submit', 'form.cart', this.handleCartForm);
            
            // Prevent default WooCommerce AJAX add to cart
            $(document).off('click', '.add_to_cart_button');
        },

        initializeCart: function() {
            // Disable normal cart functionality
            $('body').addClass('cross-site-cart-active');
            
            // Update cart button texts
            this.updateButtonTexts();
        },

        updateButtonTexts: function() {
            $('.add_to_cart_button').each(function() {
                var $button = $(this);
                if (!$button.hasClass('cross-site-updated')) {
                    var originalText = $button.text();
                    $button.attr('data-original-text', originalText);
                    $button.text(crossSiteCart.messages.transferring || 'Transfer to Checkout');
                    $button.addClass('cross-site-updated');
                }
            });
        },

        handleAddToCart: function(e) {
            e.preventDefault();
            e.stopPropagation();

            var $button = $(this);
            var $form = $button.closest('form');
            
            // Get product data
            var productData = CrossSiteCart.extractProductData($button, $form);
            
            if (!productData.product_id) {
                CrossSiteCart.showError('Product not found');
                return false;
            }

            // Validate variable products
            if ($form.hasClass('variations_form') && !productData.variation_id) {
                CrossSiteCart.showError(crossSiteCart.messages.selectOptions || 'Please select product options');
                return false;
            }

            // Process transfer
            CrossSiteCart.processTransfer(productData, $button);
            
            return false;
        },

        handleVariationForm: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $button = $form.find('.single_add_to_cart_button');
            
            $button.trigger('click');
            
            return false;
        },

        handleCartForm: function(e) {
            e.preventDefault();
            
            var $form = $(this);
            var $button = $form.find('.single_add_to_cart_button');
            
            $button.trigger('click');
            
            return false;
        },

        extractProductData: function($button, $form) {
            var data = {
                product_id: this.getProductId($button, $form),
                quantity: this.getQuantity($form),
                variation_id: this.getVariationId($form),
                variation_data: this.getVariationData($form),
                nonce: crossSiteCart.nonce
            };

            return data;
        },

        getProductId: function($button, $form) {
            // Try multiple sources for product ID
            var productId = $form.find('input[name="add-to-cart"]').val() ||
                           $form.find('input[name="product_id"]').val() ||
                           $button.data('product_id') ||
                           $button.attr('data-product_id') ||
                           $form.data('product_id');

            return parseInt(productId, 10) || 0;
        },

        getQuantity: function($form) {
            var quantity = $form.find('input[name="quantity"]').val() ||
                          $form.find('.qty').val() ||
                          1;

            return parseInt(quantity, 10) || 1;
        },

        getVariationId: function($form) {
            var variationId = $form.find('input[name="variation_id"]').val() ||
                             $form.find('.variation_id').val() ||
                             0;

            return parseInt(variationId, 10) || 0;
        },

        getVariationData: function($form) {
            var variationData = {};
            
            $form.find('.variations select, .variations input[type="radio"]:checked').each(function() {
                var $input = $(this);
                var name = $input.attr('name');
                var value = $input.val();
                
                if (name && value) {
                    variationData[name] = value;
                }
            });

            return variationData;
        },

        processTransfer: function(productData, $button) {
            // Show loading state
            this.setButtonLoading($button, true);

            // Send AJAX request
            $.ajax({
                url: crossSiteCart.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'cross_site_transfer_product',
                    ...productData
                },
                timeout: 30000, // 30 seconds timeout
                success: function(response) {
                    CrossSiteCart.handleTransferSuccess(response, $button);
                },
                error: function(xhr, status, error) {
                    CrossSiteCart.handleTransferError(xhr, status, error, $button);
                }
            });
        },

        handleTransferSuccess: function(response, $button) {
            this.setButtonLoading($button, false);

            if (response.success && response.data && response.data.redirect_url) {
                // Show success message briefly
                this.showSuccess('Product transferred successfully! Redirecting...');
                
                // Redirect to target site
                setTimeout(function() {
                    window.location.href = response.data.redirect_url;
                }, 1000);
            } else {
                var message = response.data && response.data.message ? 
                             response.data.message : 
                             'Transfer failed. Please try again.';
                this.showError(message);
            }
        },

        handleTransferError: function(xhr, status, error, $button) {
            this.setButtonLoading($button, false);

            var message = crossSiteCart.messages.error || 'Transfer failed. Please try again.';
            
            // Try to extract error message from response
            if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                message = xhr.responseJSON.data.message;
            } else if (xhr.responseText) {
                try {
                    var errorData = JSON.parse(xhr.responseText);
                    if (errorData.data && errorData.data.message) {
                        message = errorData.data.message;
                    }
                } catch (e) {
                    // Ignore JSON parse errors
                }
            }

            // Add status information for debugging
            if (status === 'timeout') {
                message = 'Transfer timed out. Please try again.';
            } else if (status === 'error' && xhr.status === 0) {
                message = 'Connection failed. Please check your internet connection.';
            }

            this.showError(message);
            
            // Log error for debugging
            console.error('Cross-Site Transfer Error:', {
                status: status,
                error: error,
                response: xhr.responseText,
                statusCode: xhr.status
            });
        },

        setButtonLoading: function($button, loading) {
            if (loading) {
                var loadingText = crossSiteCart.messages.processing || 'Processing...';
                
                $button.data('original-text', $button.text());
                $button.text(loadingText);
                $button.addClass('loading disabled');
                $button.prop('disabled', true);
                
                // Disable form submission
                $button.closest('form').addClass('processing');
            } else {
                var originalText = $button.data('original-text') || 'Add to Cart';
                
                $button.text(originalText);
                $button.removeClass('loading disabled');
                $button.prop('disabled', false);
                
                // Re-enable form
                $button.closest('form').removeClass('processing');
            }
        },

        showSuccess: function(message) {
            this.showNotice(message, 'success');
        },

        showError: function(message) {
            this.showNotice(message, 'error');
        },

        showNotice: function(message, type) {
            // Remove existing notices
            $('.cross-site-notice').remove();

            // Create notice element
            var $notice = $('<div class="cross-site-notice cross-site-notice-' + type + '">' +
                           '<span class="notice-icon">' + (type === 'success' ? '✓' : '✗') + '</span>' +
                           '<span class="notice-message">' + message + '</span>' +
                           '<button class="notice-dismiss" type="button">&times;</button>' +
                           '</div>');

            // Add to page
            if ($('.woocommerce-notices-wrapper').length) {
                $('.woocommerce-notices-wrapper').prepend($notice);
            } else if ($('.single-product .summary').length) {
                $('.single-product .summary').prepend($notice);
            } else {
                $('body').prepend($notice);
            }

            // Auto-dismiss success notices
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut();
                }, 3000);
            }

            // Handle dismiss button
            $notice.find('.notice-dismiss').on('click', function() {
                $notice.fadeOut();
            });

            // Scroll to notice
            $('html, body').animate({
                scrollTop: $notice.offset().top - 20
            }, 300);
        },

        // Utility function to check if we're on a product page
        isProductPage: function() {
            return $('body').hasClass('single-product') || $('.single-product').length > 0;
        },

        // Utility function to check if we're on a shop page
        isShopPage: function() {
            return $('body').hasClass('woocommerce-shop') || 
                   $('body').hasClass('post-type-archive-product') ||
                   $('.woocommerce-products-header').length > 0;
        }
    };

    // Initialize when DOM is ready
    CrossSiteCart.init();

    // Re-initialize on AJAX content updates
    $(document).ajaxComplete(function() {
        setTimeout(function() {
            CrossSiteCart.updateButtonTexts();
        }, 100);
    });

    // Handle dynamic content loading
    if (typeof window.MutationObserver !== 'undefined') {
        var observer = new MutationObserver(function(mutations) {
            var shouldUpdate = false;
            
            mutations.forEach(function(mutation) {
                if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                    for (var i = 0; i < mutation.addedNodes.length; i++) {
                        var node = mutation.addedNodes[i];
                        if (node.nodeType === 1) { // Element node
                            if ($(node).find('.add_to_cart_button').length > 0 ||
                                $(node).hasClass('add_to_cart_button')) {
                                shouldUpdate = true;
                                break;
                            }
                        }
                    }
                }
            });
            
            if (shouldUpdate) {
                setTimeout(function() {
                    CrossSiteCart.updateButtonTexts();
                }, 100);
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }

    // Add CSS for notices and loading states
    if (!$('#cross-site-cart-frontend-css').length) {
        var css = `
            <style id="cross-site-cart-frontend-css">
                .cross-site-notice {
                    display: flex;
                    align-items: center;
                    padding: 15px;
                    margin: 15px 0;
                    border-radius: 6px;
                    font-size: 14px;
                    position: relative;
                }
                
                .cross-site-notice-success {
                    background-color: #d4edda;
                    border: 1px solid #c3e6cb;
                    color: #155724;
                }
                
                .cross-site-notice-error {
                    background-color: #f8d7da;
                    border: 1px solid #f5c6cb;
                    color: #721c24;
                }
                
                .cross-site-notice .notice-icon {
                    margin-right: 10px;
                    font-weight: bold;
                    font-size: 16px;
                }
                
                .cross-site-notice .notice-message {
                    flex: 1;
                }
                
                .cross-site-notice .notice-dismiss {
                    background: none;
                    border: none;
                    font-size: 18px;
                    cursor: pointer;
                    padding: 0;
                    margin-left: 10px;
                    opacity: 0.7;
                }
                
                .cross-site-notice .notice-dismiss:hover {
                    opacity: 1;
                }
                
                .loading {
                    opacity: 0.6 !important;
                    cursor: wait !important;
                    pointer-events: none !important;
                }
                
                .disabled {
                    pointer-events: none !important;
                }
                
                .cross-site-transfer-notice {
                    background: linear-gradient(135deg, #e8f4f8 0%, #f0f8f0 100%);
                    border-left: 4px solid #00a0d2;
                    padding: 15px;
                    margin: 15px 0;
                    border-radius: 6px;
                    font-size: 14px;
                    color: #333;
                }
                
                .cross-site-transfer-notice .transfer-icon {
                    margin-right: 8px;
                    font-size: 16px;
                }
                
                .cross-site-stats-display {
                    display: flex;
                    gap: 20px;
                    flex-wrap: wrap;
                    margin: 15px 0;
                }
                
                .cross-site-stats-display .stat-item {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 6px;
                    text-align: center;
                    flex: 1;
                    min-width: 120px;
                }
                
                .cross-site-stats-display .stat-number {
                    display: block;
                    font-size: 24px;
                    font-weight: bold;
                    color: #0073aa;
                    margin-bottom: 5px;
                }
                
                .cross-site-stats-display .stat-label {
                    display: block;
                    font-size: 12px;
                    color: #666;
                    text-transform: uppercase;
                    letter-spacing: 0.5px;
                }
                
                @media (max-width: 768px) {
                    .cross-site-stats-display {
                        flex-direction: column;
                    }
                    
                    .cross-site-notice {
                        flex-direction: column;
                        text-align: center;
                    }
                    
                    .cross-site-notice .notice-icon {
                        margin-right: 0;
                        margin-bottom: 5px;
                    }
                }
            </style>
        `;
        $('head').append(css);
    }
});