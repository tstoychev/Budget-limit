/**
 * Membership Discount Budget - Frontend Scripts
 */
(function($) {
    'use strict';
    
    // Smooth scroll to budget section if hash exists
    $(document).ready(function() {
        if (window.location.hash === '#discount-budget') {
            $('html, body').animate({
                scrollTop: $('.mdb-budget-info').offset().top - 100
            }, 500);
        }
    });
    
    // Update cart budget notice on quantity change
    $(document).on('change', '.woocommerce-cart-form .qty', function() {
        // Add small delay before updating to allow WooCommerce to update first
        setTimeout(function() {
            // Trigger cart update to refresh budget notice
            $('[name="update_cart"]').trigger('click');
        }, 500);
    });
    
    // Highlight budget section when changing quantity
    $(document).ajaxComplete(function(event, xhr, settings) {
        if (settings.url && settings.url.indexOf('wc-ajax=get_refreshed_fragments') >= 0) {
            $('.mdb-cart-notice').addClass('highlight');
            
            setTimeout(function() {
                $('.mdb-cart-notice').removeClass('highlight');
            }, 1500);
        }
    });
    
})(jQuery);