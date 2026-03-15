/**
 * AS Camp Availability Integration - Frontend Script
 *
 * @package AS_Camp_Availability_Integration
 */

(function($) {
	'use strict';

	// Debug helper.
	const debug = {
		isEnabled: window.location.search.includes('as_cai_debug=1'),
		log: function(message, data) {
			if (this.isEnabled) {
				console.log('%c[AS CAI] ' + message, 'color: #2271b1; font-weight: bold;', data || '');
			}
		},
		error: function(message, data) {
			if (this.isEnabled) {
				console.error('%c[AS CAI ERROR] ' + message, 'color: #d63638; font-weight: bold;', data || '');
			}
		}
	};

	// Countdown timer instance.
	let countdownInterval = null;

	/**
	 * Initialize the plugin functionality.
	 */
	function init() {
		debug.log('init() called');

		if (typeof asCaiData === 'undefined') {
			debug.error('asCaiData is undefined! Plugin data not loaded.');
			return;
		}

		debug.log('asCaiData loaded:', asCaiData);

		const $seatPlannerButton = $('.stachesepl-single-add-to-cart-button-wrapper');
		const $counterWrapper = $('.as-cai-availability-counter-wrapper');

		// Enhanced debug for counter wrapper search.
		debug.log('Counter wrapper search:', {
			selector: '.as-cai-availability-counter-wrapper',
			found: $counterWrapper.length,
			exists: $counterWrapper.length > 0,
			hasCounter: asCaiData.hasCounter,
			isAvailable: asCaiData.isAvailable
		});

		if ($counterWrapper.length > 0) {
			debug.log('Counter wrapper HTML:', $counterWrapper.prop('outerHTML').substring(0, 200) + '...');
			debug.log('Counter wrapper data:', {
				targetTimestamp: $counterWrapper.data('target-timestamp'),
				textBefore: $counterWrapper.data('text-before'),
				textAfter: $counterWrapper.data('text-after')
			});
		} else {
			debug.error('Counter wrapper NOT FOUND in DOM!');
			debug.log('Searching for partial matches...');
			debug.log('Elements with "counter" in class:', $('[class*="counter"]').length);
			debug.log('Elements with "as-cai" in class:', $('[class*="as-cai"]').length);
		}

		if ($seatPlannerButton.length === 0) {
			debug.log('Seat Planner button not found in DOM (normal for non-auditorium products)');
		} else {
			debug.log('Seat Planner button found:', $seatPlannerButton);

			// Initially hide or show button based on availability (only for auditorium products).
			if (!asCaiData.isAvailable) {
				debug.log('Product NOT available - hiding button');
				$seatPlannerButton.addClass('as-cai-button-hidden').hide();
			} else {
				debug.log('Product IS available - showing button');
				$seatPlannerButton.addClass('as-cai-button-visible').show();
			}
		}

		// If counter is active, initialize countdown.
		if (asCaiData.hasCounter && $counterWrapper.length > 0) {
			debug.log('✅ All conditions met - initializing countdown');
			initCountdown($counterWrapper, $seatPlannerButton);
		} else {
			debug.log('❌ Countdown NOT initialized. Conditions check:', {
				hasCounter: asCaiData.hasCounter,
				isAvailable: asCaiData.isAvailable,
				counterWrapperFound: $counterWrapper.length > 0,
				allConditionsMet: asCaiData.hasCounter && $counterWrapper.length > 0
			});
		}
	}

	/**
	 * Initialize countdown timer.
	 *
	 * @param {jQuery} $wrapper - Counter wrapper element.
	 * @param {jQuery} $button - Seat planner button element.
	 */
	function initCountdown($wrapper, $button) {
		const targetTimestamp = parseInt($wrapper.data('target-timestamp'), 10);
		
		if (!targetTimestamp || isNaN(targetTimestamp)) {
			debug.error('Invalid target timestamp:', $wrapper.data('target-timestamp'));
			return;
		}

		const targetDate = new Date(targetTimestamp * 1000);
		
		debug.log('Countdown initialized:', {
			targetTimestamp: targetTimestamp,
			targetDate: targetDate.toString(),
			now: new Date().toString()
		});

		// Update countdown immediately.
		updateCountdown($wrapper, targetDate, $button);

		// Update countdown every second.
		countdownInterval = setInterval(function() {
			updateCountdown($wrapper, targetDate, $button);
		}, 1000);

		debug.log('Countdown interval started:', countdownInterval);
	}

	/**
	 * Update countdown display.
	 *
	 * @param {jQuery} $wrapper - Counter wrapper element.
	 * @param {Date} targetDate - Target date/time.
	 * @param {jQuery} $button - Seat planner button element.
	 */
	function updateCountdown($wrapper, targetDate, $button) {
		const now = new Date();
		const timeDiff = targetDate - now;

		// Check if countdown has finished.
		if (timeDiff <= 0) {
			debug.log('⏰ COUNTDOWN FINISHED! Hiding counter and showing buttons.');
			
			// Clear interval.
			if (countdownInterval) {
				clearInterval(countdownInterval);
				countdownInterval = null;
			}

			// Hide counter with fade out.
			$wrapper.fadeOut(400, function() {
				$(this).remove();
			});

			// Show Seat Planner button with fade in (only if button exists - for auditorium products).
			if ($button && $button.length > 0) {
				debug.log('Showing Seat Planner button (auditorium product)');
				$button
					.removeClass('as-cai-button-hidden')
					.addClass('as-cai-button-visible')
					.fadeIn(400);
			}

			// Also show standard WooCommerce add-to-cart buttons.
			const $addToCartButton = $('.single_add_to_cart_button, .add_to_cart_button');
			if ($addToCartButton.length > 0) {
				debug.log('Showing standard WooCommerce add-to-cart button');
				$addToCartButton.fadeIn(400);
			}

			// Show WooCommerce variations form if it was hidden.
			const $variationsForm = $('.variations_form, .cart');
			if ($variationsForm.length > 0) {
				debug.log('Showing WooCommerce cart/variations form');
				$variationsForm.fadeIn(400);
			}

			// Trigger custom event.
			$(document).trigger('as-cai-product-available');
			debug.log('Custom event "as-cai-product-available" triggered');

			// Clear caches and reload to ensure fresh availability data.
			debug.log('Clearing caches and triggering page refresh...');
			setTimeout(function() {
				// Clear WooCommerce cart fragments cache
				if (typeof sessionStorage !== 'undefined') {
					sessionStorage.removeItem('wc_fragments');
					sessionStorage.removeItem('wc_cart_hash');
					sessionStorage.removeItem('wc_cart_created');
				}
				// Force hard reload bypassing browser cache
				location.replace(location.href.split('#')[0] + (location.href.indexOf('?') > -1 ? '&' : '?') + '_nocache=' + Date.now());
			}, 500);

			return;
		}

		// Calculate time units.
		const days = Math.floor(timeDiff / (1000 * 60 * 60 * 24));
		const hours = Math.floor((timeDiff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
		const minutes = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
		const seconds = Math.floor((timeDiff % (1000 * 60)) / 1000);

		// Update display.
		$wrapper.find('[data-unit="days"]').text(String(days).padStart(2, '0'));
		$wrapper.find('[data-unit="hours"]').text(String(hours).padStart(2, '0'));
		$wrapper.find('[data-unit="minutes"]').text(String(minutes).padStart(2, '0'));
		$wrapper.find('[data-unit="seconds"]').text(String(seconds).padStart(2, '0'));

		// Log every 10 seconds to avoid console spam.
		if (debug.isEnabled && seconds % 10 === 0) {
			debug.log('Countdown update:', {
				days: days,
				hours: hours,
				minutes: minutes,
				seconds: seconds,
				timeRemaining: Math.floor(timeDiff / 1000) + ' seconds'
			});
		}
	}

	/**
	 * Handle dynamic content updates (e.g., AJAX product loading).
	 */
	function handleDynamicContent() {
		debug.log('Setting up dynamic content handlers');

		// Re-initialize if content is dynamically loaded.
		$(document).on('updated_wc_div', function() {
			debug.log('WooCommerce content updated - re-initializing');
			
			// Clear existing interval if any.
			if (countdownInterval) {
				clearInterval(countdownInterval);
				countdownInterval = null;
			}
			
			init();
		});
		
		// Support for Elementor preview.
		if (typeof elementorFrontend !== 'undefined' && elementorFrontend.hooks) {
			debug.log('Elementor detected - hooking into Elementor events');
			elementorFrontend.hooks.addAction('frontend/element_ready/global', function() {
				debug.log('Elementor element ready - re-initializing');
				
				// Clear existing interval if any.
				if (countdownInterval) {
					clearInterval(countdownInterval);
					countdownInterval = null;
				}
				
				init();
			});
		}
	}

	// Initialize on document ready.
	$(document).ready(function() {
		debug.log('Document ready - initializing plugin');
		init();
		handleDynamicContent();
	});

	// Re-initialize on window load (for late-loading elements).
	$(window).on('load', function() {
		debug.log('Window loaded - re-initializing after delay');
		// Slight delay to ensure all elements are rendered.
		setTimeout(function() {
			debug.log('Delayed re-initialization triggered');
			
			// Clear existing interval if any.
			if (countdownInterval) {
				clearInterval(countdownInterval);
				countdownInterval = null;
			}
			
			init();
		}, 100);
	});

})(jQuery);
