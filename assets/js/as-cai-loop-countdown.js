/**
 * Camp Availability Integration - Loop Countdown
 * 
 * Updates countdown timers on category/shop pages for unavailable products.
 * 
 * @package AS_Camp_Availability_Integration
 * @since 1.3.37
 * @since 1.3.38 Fixed timezone handling - now works with wp_timezone() timestamps
 * @since 1.3.39 CRITICAL FIX: Real countdown timer - decrements every second + AJAX-compatible
 * @since 1.3.40 CRITICAL DEBUG: Added extensive logging to identify static countdown issue
 */

(function($) {
	'use strict';

	console.log('[AS-CAI v1.3.40] ✅ JavaScript file loaded successfully!');

	var countdownInterval = null;
	var updateCounter = 0;

	/**
	 * Update countdown for a single button.
	 */
	function updateCountdown($button) {
		var targetTimestamp = parseInt($button.attr('data-target-timestamp'), 10);
		
		if (!targetTimestamp) {
			console.warn('[AS-CAI v1.3.40] ⚠️ Button found but no target timestamp!', $button);
			return;
		}

		var now = Math.floor(Date.now() / 1000);
		var secondsLeft = targetTimestamp - now;

		// Debug: Log first update for this button
		if (updateCounter === 1) {
			console.log('[AS-CAI v1.3.40] 🎯 Button Details:', {
				targetTimestamp: targetTimestamp,
				now: now,
				secondsLeft: secondsLeft,
				buttonText: $button.text(),
				buttonClasses: $button.attr('class')
			});
		}

		// If time is up, reload page to show normal button
		if (secondsLeft <= 0) {
			console.log('[AS-CAI v1.3.40] ⏰ Time is up! Reloading page...');
			location.reload();
			return;
		}

		// Calculate time units
		var days = Math.floor(secondsLeft / 86400);
		var hours = Math.floor((secondsLeft % 86400) / 3600);
		var minutes = Math.floor((secondsLeft % 3600) / 60);
		var seconds = secondsLeft % 60;

		// Build short countdown text
		var countdownText = '';
		if (days > 0) {
			countdownText += days + 'T ';
		}
		if (hours > 0 || days > 0) {
			countdownText += hours + 'S ';
		}
		if (minutes > 0 || hours > 0 || days > 0) {
			countdownText += minutes + 'M ';
		}
		countdownText += seconds + 'S';

		var newText = countdownText.trim();
		var oldText = $button.text();
		
		// Update button text
		$button.text(newText);
		
		// Debug: Log text change
		if (updateCounter <= 5 || updateCounter % 10 === 0) {
			if (oldText !== newText) {
				console.log('[AS-CAI v1.3.40] 🔄 Update #' + updateCounter + ': "' + oldText + '" → "' + newText + '"');
			}
		}
	}

	/**
	 * Update all countdown buttons.
	 * This function is called every second by the interval.
	 */
	function updateAllCountdowns() {
		updateCounter++;
		
		// Find buttons DYNAMICALLY on each call (fixes AJAX reload issue)
		var $buttons = $('.as-cai-loop-button-disabled[data-target-timestamp]');
		
		// Debug: Log button search results
		if (updateCounter === 1) {
			console.log('[AS-CAI v1.3.40] 🔍 First search - Found ' + $buttons.length + ' countdown buttons');
			if ($buttons.length > 0) {
				$buttons.each(function(index) {
					console.log('[AS-CAI v1.3.40] 🔘 Button ' + (index + 1) + ':', {
						text: $(this).text(),
						timestamp: $(this).attr('data-target-timestamp'),
						classes: $(this).attr('class')
					});
				});
			} else {
				console.warn('[AS-CAI v1.3.40] ⚠️ No buttons found! Looking for: .as-cai-loop-button-disabled[data-target-timestamp]');
				// Check if there are ANY buttons with the class
				var anyButtons = $('.as-cai-loop-button-disabled');
				console.log('[AS-CAI v1.3.40] 🔍 Buttons with class only: ' + anyButtons.length);
				var anyTimestamps = $('[data-target-timestamp]');
				console.log('[AS-CAI v1.3.40] 🔍 Elements with timestamp: ' + anyTimestamps.length);
			}
		}
		
		// Log every 5 seconds to track updates
		if (updateCounter % 5 === 0) {
			console.log('[AS-CAI v1.3.40] ⏱️ Update #' + updateCounter + ' - Processing ' + $buttons.length + ' buttons');
		}
		
		$buttons.each(function() {
			updateCountdown($(this));
		});
	}

	/**
	 * Initialize countdown interval.
	 */
	function initCountdowns() {
		console.log('[AS-CAI v1.3.40] 🚀 initCountdowns() called');
		
		// Clear any existing interval to prevent duplicates
		if (countdownInterval) {
			console.log('[AS-CAI v1.3.40] 🧹 Clearing existing interval: ' + countdownInterval);
			clearInterval(countdownInterval);
		}
		
		// Reset counter
		updateCounter = 0;

		// Update immediately
		console.log('[AS-CAI v1.3.40] ▶️ Starting first update...');
		updateAllCountdowns();

		// Then update every second
		console.log('[AS-CAI v1.3.40] ⏰ Setting up 1-second interval...');
		countdownInterval = setInterval(function() {
			updateAllCountdowns();
		}, 1000);
		
		console.log('[AS-CAI v1.3.40] ✅ Countdown interval started (ID: ' + countdownInterval + ')');
	}

	/**
	 * Stop countdown interval.
	 */
	function stopCountdowns() {
		if (countdownInterval) {
			console.log('[AS-CAI v1.3.40] 🛑 Stopping countdown interval: ' + countdownInterval);
			clearInterval(countdownInterval);
			countdownInterval = null;
		}
	}

	// Initialize when DOM is ready
	console.log('[AS-CAI v1.3.40] 📄 Waiting for document ready...');
	$(document).ready(function() {
		console.log('[AS-CAI v1.3.40] ✅ Document ready! Initializing...');
		initCountdowns();
	});

	// Reinitialize after WooCommerce AJAX events
	console.log('[AS-CAI v1.3.40] 🔌 Setting up WooCommerce event listeners...');
	
	$(document.body).on('updated_wc_div', function() {
		console.log('[AS-CAI v1.3.40] 🔄 WooCommerce updated_wc_div event fired!');
		initCountdowns();
	});

	$(document.body).on('wc_fragments_refreshed', function() {
		console.log('[AS-CAI v1.3.40] 🔄 WooCommerce wc_fragments_refreshed event fired!');
		initCountdowns();
	});

	// Stop countdown when page is hidden/unloaded to save resources
	$(window).on('beforeunload', function() {
		console.log('[AS-CAI v1.3.40] 👋 Page unloading, stopping countdowns...');
		stopCountdowns();
	});

	console.log('[AS-CAI v1.3.40] ✅ All event listeners registered!');

})(jQuery);
