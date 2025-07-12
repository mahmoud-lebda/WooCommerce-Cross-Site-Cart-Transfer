jQuery(document).ready(function($) {
    // التحكم في أزرار Add to Cart
    $('.single_add_to_cart_button, .add_to_cart_button').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var form = button.closest('form.cart');
        var product_id = form.find('input[name="add-to-cart"]').val() || button.data('product_id');
        var quantity = form.find('input[name="quantity"]').val() || 1;
        var variation_id = form.find('input[name="variation_id"]').val() || 0;
        
        if (!product_id) {
            console.error('Product ID not found');
            return;
        }
        
        // إظهار loading
        button.addClass('loading');
        button.text('Transferring...');
        
        // إرسال البيانات للتحويل
        $.ajax({
            url: cross_site_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'transfer_cart',
                product_id: product_id,
                quantity: quantity,
                variation_id: variation_id,
                nonce: cross_site_ajax.nonce
            },
            success: function(response) {
                // تحويل المستخدم للموقع الثاني
                window.location.href = cross_site_ajax.target_url + '/cart/';
            },
            error: function(xhr, status, error) {
                console.error('Transfer failed:', error);
                button.removeClass('loading');
                button.text('Transfer Failed - Try Again');
                
                // إظهار رسالة خطأ
                if (xhr.responseText) {
                    alert('Transfer failed: ' + xhr.responseText);
                } else {
                    alert('Transfer failed. Please try again.');
                }
            }
        });
    });
    
    // التحكم في أزرار Add to Cart في صفحات المنتجات المتعددة
    $('.ajax_add_to_cart').on('click', function(e) {
        e.preventDefault();
        
        var button = $(this);
        var product_id = button.data('product_id');
        var quantity = button.data('quantity') || 1;
        
        if (!product_id) {
            console.error('Product ID not found');
            return;
        }
        
        // إظهار loading
        button.addClass('loading');
        button.text('Transferring...');
        
        // إرسال البيانات للتحويل
        $.ajax({
            url: cross_site_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'transfer_cart',
                product_id: product_id,
                quantity: quantity,
                variation_id: 0,
                nonce: cross_site_ajax.nonce
            },
            success: function(response) {
                // تحويل المستخدم للموقع الثاني
                window.location.href = cross_site_ajax.target_url + '/cart/';
            },
            error: function(xhr, status, error) {
                console.error('Transfer failed:', error);
                button.removeClass('loading');
                button.text('Transfer Failed');
                
                // إظهار رسالة خطأ
                if (xhr.responseText) {
                    alert('Transfer failed: ' + xhr.responseText);
                } else {
                    alert('Transfer failed. Please try again.');
                }
            }
        });
    });
    
    // التحكم في Variable Products
    $('form.variations_form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var button = form.find('.single_add_to_cart_button');
        var product_id = form.find('input[name="add-to-cart"]').val();
        var quantity = form.find('input[name="quantity"]').val() || 1;
        var variation_id = form.find('input[name="variation_id"]').val();
        
        if (!variation_id) {
            alert('Please select product options before adding to cart.');
            return;
        }
        
        // إظهار loading
        button.addClass('loading');
        button.text('Transferring...');
        
        // جمع بيانات المتغيرات
        var variation_data = {};
        form.find('.variations select').each(function() {
            var name = $(this).attr('name');
            var value = $(this).val();
            if (value) {
                variation_data[name] = value;
            }
        });
        
        // إرسال البيانات للتحويل
        $.ajax({
            url: cross_site_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'transfer_cart',
                product_id: product_id,
                quantity: quantity,
                variation_id: variation_id,
                variation_data: variation_data,
                nonce: cross_site_ajax.nonce
            },
            success: function(response) {
                // تحويل المستخدم للموقع الثاني
                window.location.href = cross_site_ajax.target_url + '/cart/';
            },
            error: function(xhr, status, error) {
                console.error('Transfer failed:', error);
                button.removeClass('loading');
                button.text('Transfer Failed');
                
                // إظهار رسالة خطأ
                if (xhr.responseText) {
                    alert('Transfer failed: ' + xhr.responseText);
                } else {
                    alert('Transfer failed. Please try again.');
                }
            }
        });
    });
});

// إضافة CSS للتحسينات البصرية
jQuery(document).ready(function($) {
    $('<style>')
        .prop('type', 'text/css')
        .html(`
            .loading {
                opacity: 0.6;
                cursor: wait !important;
            }
            
            .back-to-source-site {
                background-color: #f8f9fa;
                border: 1px solid #dee2e6;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }
            
            .back-to-source-site .button {
                display: inline-block;
                padding: 12px 24px;
                font-size: 16px;
                font-weight: 600;
                text-decoration: none;
                border-radius: 6px;
                transition: all 0.3s ease;
            }
            
            .back-to-source-site .button:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            }
        `)
        .appendTo('head');
});