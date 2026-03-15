/* Camp Availability Integration - Cart Timer */

(function($) {
	'use strict';

	class CartCountdown {
		constructor(element) {
			this.$element = $(element);
			this.timeRemaining = parseInt(this.$element.data('time-remaining'), 10);
			this.warningThreshold = asCaiCart.warningThreshold || 60;
			this.totalTime = asCaiCart.timeRemaining || 300;
			this.init();
		}

		init() {
			if (this.timeRemaining <= 0) {
				this.showExpired();
				return;
			}

			this.updateDisplay();
			this.startTimer();
		}

		startTimer() {
			this.interval = setInterval(() => {
				this.timeRemaining--;

				if (this.timeRemaining <= 0) {
					clearInterval(this.interval);
					this.showExpired();
					return;
				}

				this.updateDisplay();
				this.updateState();
			}, 1000);
		}

		updateDisplay() {
			const minutes = Math.floor(this.timeRemaining / 60);
			const seconds = this.timeRemaining % 60;
			const formatted = `${minutes}:${seconds.toString().padStart(2, '0')}`;

			this.$element.find('.as-cai-countdown-time').text(formatted);

			// Update progress bar if exists
			const $progressBar = this.$element.find('.as-cai-countdown-progress-bar');
			if ($progressBar.length) {
				const percentage = (this.timeRemaining / this.totalTime) * 100;
				$progressBar.css('width', percentage + '%');
			}
		}

		updateState() {
			if (this.timeRemaining <= this.warningThreshold) {
				this.$element.addClass('warning');
			} else {
				this.$element.removeClass('warning');
			}
		}

		showExpired() {
			this.$element
				.removeClass('warning')
				.addClass('expired');
			
			this.$element.find('.as-cai-countdown-inner').html(`
				<i class="fas fa-exclamation-triangle"></i>
				<span>${asCaiCart.i18n.reservationExpired}</span>
			`);

			// Optionally reload cart after a delay
			setTimeout(() => {
				if (typeof location !== 'undefined') {
					location.reload();
				}
			}, 3000);
		}

		destroy() {
			if (this.interval) {
				clearInterval(this.interval);
			}
		}
	}

	$(document).ready(function() {
		$('.as-cai-cart-countdown').each(function() {
			new CartCountdown(this);
		});
	});

})(jQuery);
