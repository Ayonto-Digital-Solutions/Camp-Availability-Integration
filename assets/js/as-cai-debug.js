/**
 * Camp Availability Integration - Debug Scripts
 * Version: 1.2.0
 * 
 * This file contains debug functionality separated from inline scripts
 * for security reasons (XSS prevention).
 */

(function($) {
    'use strict';

    // Only run if debug data is available
    if (typeof asCaiDebugData === 'undefined' || !asCaiDebugData.debug_enabled) {
        return;
    }

    // Console styling for debug messages
    const consoleStyle = 'background: #d63638; color: white; padding: 5px 10px; font-weight: bold;';
    
    // Log debug mode active
    console.log('%c' + asCaiDebugData.messages.debug_active, consoleStyle);
    
    $(document).ready(function() {
        // Check for counter elements
        console.group('AS CAI Debug: DOM Elements');
        
        const counterWrapper = $('.as-cai-availability-counter-wrapper');
        console.log(asCaiDebugData.messages.wrapper_found, counterWrapper.length > 0, counterWrapper);
        
        const counterElements = $('[class*="af-"][class*="aps-count"]');
        console.log(asCaiDebugData.messages.elements_found, counterElements.length, counterElements);
        
        const seatPlannerButton = $('.stachesepl-single-add-to-cart-button-wrapper');
        console.log(asCaiDebugData.messages.button_found, seatPlannerButton.length > 0, seatPlannerButton);
        console.log(asCaiDebugData.messages.button_visible, seatPlannerButton.is(':visible'));
        
        const addToCartButton = $('.single_add_to_cart_button');
        console.log(asCaiDebugData.messages.cart_found, addToCartButton.length > 0, addToCartButton);
        console.log(asCaiDebugData.messages.cart_visible, addToCartButton.is(':visible'));
        
        console.groupEnd();
        
        // Check asCaiData
        console.group('AS CAI Debug: JavaScript Data');
        if (typeof asCaiData !== 'undefined') {
            console.log('asCaiData:', asCaiData);
        } else {
            console.error(asCaiDebugData.messages.data_not_found);
        }
        console.groupEnd();
        
        // Update DOM check panel
        let domCheckHtml = '<strong>' + asCaiDebugData.messages.dom_status + '</strong><br>';
        domCheckHtml += '• Counter Wrapper: ' + (counterWrapper.length > 0 ? '✅ ' + asCaiDebugData.messages.found : '❌ ' + asCaiDebugData.messages.not_found) + '<br>';
        domCheckHtml += '• Counter Elements: ' + counterElements.length + ' ' + asCaiDebugData.messages.found.toLowerCase() + '<br>';
        domCheckHtml += '• Seat Planner Button: ' + (seatPlannerButton.length > 0 ? '✅ ' + asCaiDebugData.messages.found : '❌ ' + asCaiDebugData.messages.not_found);
        
        if (seatPlannerButton.length > 0) {
            domCheckHtml += ' | Visible: ' + (seatPlannerButton.is(':visible') ? '✅ ' + asCaiDebugData.messages.visible_yes : '❌ ' + asCaiDebugData.messages.visible_no);
        }
        domCheckHtml += '<br>';
        
        domCheckHtml += '• Add to Cart Button: ' + (addToCartButton.length > 0 ? '✅ ' + asCaiDebugData.messages.found : '❌ ' + asCaiDebugData.messages.not_found);
        if (addToCartButton.length > 0) {
            domCheckHtml += ' | Visible: ' + (addToCartButton.is(':visible') ? '✅ ' + asCaiDebugData.messages.visible_yes : '❌ ' + asCaiDebugData.messages.visible_no);
        }
        
        $('#as-cai-debug-dom-check').html(domCheckHtml);
        
        // Monitor button visibility changes
        if (seatPlannerButton.length > 0) {
            const observer = new MutationObserver(function(mutations) {
                console.log('Seat Planner Button visibility changed:', seatPlannerButton.is(':visible'));
            });
            observer.observe(seatPlannerButton[0], { 
                attributes: true, 
                attributeFilter: ['style', 'class'] 
            });
        }
        
        // AJAX Debug Info Updates (if needed)
        if (asCaiDebugData.ajax_url && asCaiDebugData.product_id) {
            // Set up interval for debug info updates (optional)
            setInterval(function() {
                $.ajax({
                    url: asCaiDebugData.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'as_cai_get_debug_info',
                        product_id: asCaiDebugData.product_id,
                        nonce: asCaiDebugData.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            console.log('Debug Info Update:', response.data);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Debug AJAX Error:', error);
                    }
                });
            }, 30000); // Update every 30 seconds
        }
    });
    
})(jQuery);
