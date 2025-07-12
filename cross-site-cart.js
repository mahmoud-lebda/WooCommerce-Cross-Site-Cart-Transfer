/**
 * Enhanced Cross-Site Cart JavaScript
 * This file completely prevents normal cart functionality and implements transfer
 */

(function($) {
    'use strict';
    
    // Wait for document ready and WooCommerce to load
    $(document).ready(function() {
        console.log('Cross-Site Cart: Enhanced JavaScript loaded');
        
        // Disable WooCommerce AJAX completely
        if (typeof wc_add_to_cart_params !== 'undefined') {
            wc_add_to_cart_params.ajax_url = '';
            wc_add_to_cart_params.wc_ajax_url = '';
        }
        
        // Remove all existing event handlers immediately
        removeExistingHandlers();
        
        // Set up our custom handlers with higher priority
        setupCustomHandlers();
        
        // Monitor for dynamically added elements
        observeDOMChanges();
        
        // Override WooCommerce cart functions
        overrideWooCommerceFunctions();
    });
    
    function removeExistingHandlers() {
        console.log('Cross-Site Cart: Removing existing handlers');
        
        // Remove all click handlers from cart buttons
        $('.single_add_to_cart_button, .add_to_cart_button, .ajax_add_to_cart').off('click');
        
        // Remove form submit handlers
        $('form.cart, form.variations_form').off('submit');
        
        // Remove WooCommerce specific handlers
        $('.single_add_to_cart_button').off('click.wc-add-to-cart');
        $('.ajax_add_to_cart').off('click.wc-ajax-add-to-cart');
        
        // Remove any other cart-related handlers
        $(document).off('click', '.single_add_to_cart_button');
        $(document).off('click', '.add_to_cart_button');
        $(document).off('click', '.ajax_add_to_cart');
        $(document).off('submit', 'form.cart');
        $(document).off('submit', 'form.variations_form');
    }
    
    function setupCustomHandlers() {
        console.log('Cross-Site Cart: Setting up custom handlers');
        
        // Use event delegation with highest priority
        $(document).on('click.cross-site-cart', '.single_add_to_cart_button, .add_to_cart_button, .ajax_add_to_cart', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            e.stopPropagation();
            
            console.log('Cross-Site Cart: Button clicked, processing transfer');
            
            handleProductTransfer($(this));
            return false;
        });
        
        // Prevent form submissions
        $(document).on('submit.cross-site-cart', 'form.cart, form.variations_form', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            
            console.log('Cross-Site Cart: Form submit prevented');
            
            // Trigger button click instead
            $(this).find('.single_add_to_cart_button').trigger('click.cross-site-cart');
            return false;
        });
        
        // Override any programmatic add to cart attempts
        $(document).on('wc_add_to_cart_button_click', function(e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            return false;
        });
    }
    
    function handleProductTransfer(button) {
        console.log('Cross-Site Cart: Starting product transfer');
        
        var form = button.closest('form.cart, form.variations_form, .product, .woocommerce-product-details__short-description').length ? 
                   button.closest('form.cart, form.variations_form, .product, .woocommerce-product-details__short-description') : 
                   button.closest('.product');
        
        // Extract product data with multiple fallback methods
        var productData = extractProductData(button, form);
        
        if (!productData.product_id) {
            alert('Unable to find product information. Please refresh the page and try again.');
            return;
        }
        
        console.log('Cross-Site Cart: Product data extracted', productData);
        
        // Validate required selections for variable products
        if (isVariableProduct(form) && !productData.variation_id) {
            alert('Please select product options before adding to cart.');
            return;
        }
        
        // Show loading state
        showLoadingState(button);
        
        // Execute transfer
        executeTransfer(productData, button);
    }
    
    function extractProductData(button, form) {
        var data = {
            product_id: null,
            quantity: 1,
            variation_id: 0,
            variation_data: {}
        };
        
        // Try multiple methods to get product ID
        data.product_id = form.find('input[name="add-to-cart"]').val() ||
                         form.find('[name="add-to-cart"]').val() ||
                         button.data('product_id') ||
                         button.attr('data-product_id') ||
                         button.data('product-id') ||
                         form.data('product_id') ||
                         form.attr('data-product_id');
        
        // Get quantity
        data.quantity = parseInt(form.find('input[name="quantity"]').val() || 
                               form.find('.qty').val() || 
                               button.data('quantity') || 
                               1);
        
        // Get variation ID for variable products
        data.variation_id = parseInt(form.find('input[name="variation_id"]').val() || 0);
        
        // Extract variation data
        form.find('.variations select, .variations input[type="radio"]:checked').each(function() {
            var name = $(this).attr('name');
            var value = $(this).val();
            if (name && value) {
                data.variation_data[name] = value;
            }
        });
        
        console.log('Cross-Site Cart: Extracted product data', data);
        return data;
    }
    
    function isVariableProduct(form) {
        return form.hasClass('variations_form') || 
               form.find('.variations').length > 0 ||
               form.find('input[name="variation_id"]').length > 0;
    }
    
    function showLoadingState(button) {
        button.data('original-text', button.text());
        button.addClass('loading disabled')
              .text('Transferring...')
              .prop('disabled', true);
        
        // Add visual loading indicator
        if (!button.find('.loading-spinner').length) {
            button.prepend('<span class="loading-spinner">⟳ </span>');
        }
    }
    
    function hideLoadingState(button) {
        button.removeClass('loading disabled')
              .text(button.data('original-text') || 'Add to cart')
              .prop('disabled', false);
        
        button.find('.loading-spinner').remove();
    }
    
    function executeTransfer(productData, button) {
        console.log('Cross-Site Cart: Executing transfer to target site');
        
        $.ajax({
            url: cross_site_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'transfer_cart',
                product_id: productData.product_id,
                quantity: productData.quantity,
                variation_id: productData.variation_id,
                variation_data: productData.variation_data,
                nonce: cross_site_ajax.nonce
            },
            timeout: 30000,
            success: function(response) {
                console.log('Cross-Site Cart: Transfer response received', response);
                
                if (response && response.success && response.data && response.data.redirect_url) {
                    console.log('Cross-Site Cart: Transfer successful, redirecting to', response.data.redirect_url);
                    
                    // Show success message briefly
                    button.removeClass('loading disabled')
                          .addClass('success')
                          .text('✓ Redirecting...');
                    
                    // Redirect after short delay
                    setTimeout(function() {
                        window.location.href = response.data.redirect_url;
                    }, 500);
                    
                } else {
                    console.error('Cross-Site Cart: Invalid response format', response);
                    hideLoadingState(button);
                    
                    var errorMessage = 'Transfer failed';
                    if (response && response.data && response.data.message) {
                        errorMessage += ': ' + response.data.message;
                    }
                    alert(errorMessage + '. Please try again.');
                }
            },
            error: function(xhr, status, error) {
                console.error('Cross-Site Cart: Transfer failed', {
                    status: status,
                    error: error,
                    responseText: xhr.responseText
                });
                
                hideLoadingState(button);
                
                var errorMessage = 'Transfer failed';
                if (xhr.responseText) {
                    try {
                        var errorData = JSON.parse(xhr.responseText);
                        if (errorData.data && errorData.data.message) {
                            errorMessage += ': ' + errorData.data.message;
                        }
                    } catch (e) {
                        errorMessage += ': ' + xhr.responseText.substring(0, 100);
                    }
                } else if (error) {
                    errorMessage += ': ' + error;
                }
                
                alert(errorMessage + '. Please check your connection and try again.');
            }
        });
    }
    
    function observeDOMChanges() {
        // Watch for dynamically added cart buttons
        if (window.MutationObserver) {
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.addedNodes.length) {
                        $(mutation.addedNodes).find('.single_add_to_cart_button, .add_to_cart_button, .ajax_add_to_cart').each(function() {
                            // Remove any existing handlers and apply our custom ones
                            $(this).off('click');
                        });
                    }
                });
            });
            
            observer.observe(document.body, {
                childList: true,
                subtree: true
            });
        }
    }
    
    function overrideWooCommerceFunctions() {
        // Override common WooCommerce functions that might interfere
        if (window.wc_add_to_cart_handler) {
            window.wc_add_to_cart_handler = function() {
                console.log('Cross-Site Cart: WooCommerce add to cart handler blocked');
                return false;
            };
        }
        
        // Block WooCommerce AJAX add to cart
        if (window.wc_add_to_cart_variation_form_handler) {
            window.wc_add_to_cart_variation_form_handler = function() {
                console.log('Cross-Site Cart: WooCommerce variation form handler blocked');
                return false;
            };
        }
        
        // Override jQuery AJAX for WooCommerce cart operations
        var originalAjax = $.ajax;
        $.ajax = function(options) {
            if (options.url && (
                options.url.indexOf('wc-ajax=add_to_cart') !== -1 ||
                options.url.indexOf('add-to-cart') !== -1
            )) {
                console.log('Cross-Site Cart: Blocking WooCommerce AJAX cart operation');
                return {
                    done: function() { return this; },
                    fail: function() { return this; },
                    always: function() { return this; }
                };
            }
            return originalAjax.call(this, options);
        };
    }
    
    // Additional CSS for loading states
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .loading-spinner {
                animation: spin 1s linear infinite;
                display: inline-block;
            }
            
            @keyframes spin {
                from { transform: rotate(0deg); }
                to { transform: rotate(360deg); }
            }
            
            .button.success {
                background-color: #4CAF50 !important;
                color: white !important;
            }
            
            .button.loading {
                opacity: 0.7;
                cursor: wait !important;
                pointer-events: none !important;
            }
        `)
        .appendTo('head');
    
})(jQuery);