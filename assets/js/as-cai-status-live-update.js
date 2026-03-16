/**
 * Real-time status updates for availability status box.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.59
 * @updated 1.3.79
 */
(function ($) {
	'use strict';

	var StatusLiveUpdate = function () {
		this.updateInterval = 15000;
		this.refreshIntervals = [];
		this.init();
	};

	StatusLiveUpdate.prototype.init = function () {
		var self = this;

		$('.as-cai-status-box').each(function () {
			self.startAutoRefresh($(this));
		});

		$(document).on('click', '.as-cai-refresh-button', function (e) {
			e.preventDefault();
			var $box = $(e.target).closest('.as-cai-status-box');
			self.refreshStatus($box);
		});

		$(document).on('click', '.as-cai-notify-button, .as-cai-waitlist-button', function (e) {
			e.preventDefault();
			var productId = $(this).data('product-id') || $(this).closest('.as-cai-status-box').data('product-id');
			self.showNotifyPrompt(productId);
		});

		// CTA-Button: Open Seat Planner modal (auditorium) or add-to-cart (simple).
		$(document).on('click', '.as-cai-cta-button', function (e) {
			e.preventDefault();
			var type = $(this).data('product-type');
			if (type === 'auditorium') {
				// Find the Stachethemes Seat Planner button root (React component).
				var $seatRoot = $('.stachesepl-add-to-cart-button-root');
				if ($seatRoot.length) {
					// Use native click (not jQuery trigger) — React uses synthetic events
					// and won't respond to jQuery .trigger('click').
					var clickTarget = $seatRoot.find('.stachesepl-select-seat-placeholder, button, [role="button"], .stachesepl-date-time-input-placeholder')[0];
					if (!clickTarget) {
						clickTarget = $seatRoot[0];
					}
					if (clickTarget) {
						clickTarget.click();
					}
					// Scroll into view so user sees the modal context.
					$('html, body').animate({ scrollTop: $seatRoot.first().offset().top - 80 }, 600);
				}
			} else {
				var $form = $('form.cart');
				if ($form.length) {
					$form.find('.single_add_to_cart_button').trigger('click');
				}
			}
		});
	};

	StatusLiveUpdate.prototype.startAutoRefresh = function ($box) {
		var self     = this;
		var interval = $box.data('refresh-interval') || this.updateInterval;
		var id = setInterval(function () { self.refreshStatus($box); }, interval);
		this.refreshIntervals.push(id);
	};

	StatusLiveUpdate.prototype.refreshStatus = function ($box) {
		var self      = this;
		var productId = $box.data('product-id');

		if (!productId || typeof as_cai_vars === 'undefined') return;

		$box.addClass('updating');

		$.ajax({
			url:  as_cai_vars.ajax_url,
			type: 'POST',
			data: {
				action:     'as_cai_get_status',
				product_id: productId,
				nonce:      as_cai_vars.nonce
			},
			success: function (response) {
				if (response.success) {
					self.updateStatusBox($box, response.data);
					self.showUpdateNotification($box);
				}
			},
			complete: function () {
				$box.removeClass('updating');
				self.updateTimestamp($box);
			}
		});
	};

	StatusLiveUpdate.prototype.updateStatusBox = function ($box, data) {
		var self = this;

		// Update numbers (label comes from server).
		var label = data.label || 'Parzellen';
		$box.find('.availability-main strong').text(
			data.available + ' von ' + data.total + ' ' + label
		);

		// Update progress bar.
		$box.find('.progress-available').css('width', data.percent_free + '%');
		$box.find('.label-available').text(Math.round(data.percent_free) + '% verfügbar');
		$box.find('.label-sold').text(data.sold + ' verkauft');

		// Update reserved info.
		if (data.reserved > 0) {
			$box.find('.reserved-badge').text('🔒 ' + data.reserved + ' reserviert').show();
			var reservedPercent = data.total > 0 ? (data.reserved / data.total) * 100 : 0;
			$box.find('.progress-reserved').css('width', reservedPercent + '%');
		} else {
			$box.find('.reserved-badge').hide();
			$box.find('.progress-reserved').css('width', '0%');
		}

		// Update status class.
		var statusClasses = ['status-available', 'status-limited', 'status-critical', 'status-reserved-full', 'status-sold-out'];
		var currentClass  = '';
		for (var i = 0; i < statusClasses.length; i++) {
			if ($box.hasClass(statusClasses[i])) { currentClass = statusClasses[i]; break; }
		}

		var newClass = 'status-' + data.status.replace('_', '-');
		if (currentClass !== newClass) {
			$box.removeClass(statusClasses.join(' ')).addClass(newClass);
			self.showStatusChangeAlert(data.status);

			var titles = {
				'available':     'Sofort buchbar',
				'limited':       'Nur noch wenige Parzellen',
				'critical':      'Letzte Parzellen!',
				'reserved_full': 'Alle Parzellen reserviert',
				'sold_out':      'Ausgebucht'
			};
			var icons = {
				'available':     '✓',
				'limited':       '⚠',
				'critical':      '⚡',
				'reserved_full': '🔒',
				'sold_out':      '✕'
			};
			if (titles[data.status]) {
				$box.find('.status-title').text(titles[data.status]);
				$box.find('.status-icon').text(icons[data.status] || '');
			}

			// Hide/show seat planner button.
			var $seatBtn = $('.stachesepl-single-add-to-cart-button-wrapper, .stachesepl-add-to-cart-button-root');
			if (data.status === 'sold_out' || data.status === 'reserved_full') {
				$seatBtn.hide();
			} else {
				$seatBtn.show();
			}
		}
	};

	StatusLiveUpdate.prototype.updateTimestamp = function ($box) {
		var now = new Date();
		$box.find('.update-time').text(now.toLocaleTimeString('de-DE', {
			hour: '2-digit', minute: '2-digit', second: '2-digit'
		}));
	};

	StatusLiveUpdate.prototype.showUpdateNotification = function ($box) {
		var $indicator = $box.find('.auto-refresh-indicator');
		$indicator.addClass('pulse').html('&#9679; Aktualisiert');
		setTimeout(function () {
			$indicator.removeClass('pulse').html('&#9679; Auto-Refresh aktiv');
		}, 2000);
	};

	StatusLiveUpdate.prototype.showStatusChangeAlert = function (newStatus) {
		var messages = {
			'available':     'Parzellen wieder verfügbar!',
			'limited':       'Nur noch wenige Parzellen!',
			'critical':      'Letzte Parzellen!',
			'reserved_full': 'Alle Parzellen reserviert',
			'sold_out':      'Ausgebucht'
		};
		if (messages[newStatus]) this.showToast(messages[newStatus], newStatus);
	};

	StatusLiveUpdate.prototype.showToast = function (message, status) {
		var $toast = $('<div></div>')
			.addClass('as-cai-toast toast-' + status.replace('_', '-'))
			.text(message)
			.appendTo('body');

		setTimeout(function () { $toast.addClass('show'); }, 100);
		setTimeout(function () {
			$toast.removeClass('show');
			setTimeout(function () { $toast.remove(); }, 300);
		}, 3000);
	};

	StatusLiveUpdate.prototype.showNotifyPrompt = function (productId) {
		$('.as-cai-modal-overlay').remove();

		var modalHtml =
			'<div class="as-cai-modal-overlay">' +
				'<div class="as-cai-modal">' +
					'<button type="button" class="as-cai-modal-close" aria-label="Schließen">&times;</button>' +
					'<div class="as-cai-modal-icon">🔔</div>' +
					'<h3 class="as-cai-modal-title">Auf die Warteliste setzen</h3>' +
					'<p class="as-cai-modal-text">Sobald eine Parzelle wieder verfügbar wird, benachrichtigen wir Sie per E-Mail.</p>' +
					'<form class="as-cai-modal-form">' +
						'<label for="as-cai-notify-email" class="as-cai-modal-label">Ihre E-Mail-Adresse</label>' +
						'<input type="email" id="as-cai-notify-email" class="as-cai-modal-input" placeholder="name@beispiel.de" required autocomplete="email" />' +
						'<div class="as-cai-modal-error" style="display:none;"></div>' +
						'<div class="as-cai-modal-actions">' +
							'<button type="button" class="as-cai-modal-btn-cancel">Abbrechen</button>' +
							'<button type="submit" class="as-cai-modal-btn-submit">' +
								'<span class="btn-text">Benachrichtigen</span>' +
								'<span class="btn-loading" style="display:none;">Wird gesendet…</span>' +
							'</button>' +
						'</div>' +
					'</form>' +
				'</div>' +
			'</div>';

		var $modal = $(modalHtml).appendTo('body');

		requestAnimationFrame(function () {
			$modal.addClass('show');
			$modal.find('#as-cai-notify-email').focus();
		});

		var closeModal = function () {
			$modal.removeClass('show');
			setTimeout(function () { $modal.remove(); }, 300);
		};

		$modal.find('.as-cai-modal-close, .as-cai-modal-btn-cancel').on('click', closeModal);
		$modal.on('click', function (e) {
			if ($(e.target).hasClass('as-cai-modal-overlay')) closeModal();
		});
		$(document).on('keydown.ascaimodal', function (e) {
			if (e.key === 'Escape') { closeModal(); $(document).off('keydown.ascaimodal'); }
		});

		$modal.find('.as-cai-modal-form').on('submit', function (e) {
			e.preventDefault();
			var email = $modal.find('#as-cai-notify-email').val().trim();
			var $error = $modal.find('.as-cai-modal-error');
			var $btnText = $modal.find('.btn-text');
			var $btnLoading = $modal.find('.btn-loading');
			var $submit = $modal.find('.as-cai-modal-btn-submit');

			if (!email || !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
				$error.text('Bitte geben Sie eine gültige E-Mail-Adresse ein.').show();
				$modal.find('#as-cai-notify-email').focus();
				return;
			}

			$error.hide();
			$btnText.hide();
			$btnLoading.show();
			$submit.prop('disabled', true);

			$.ajax({
				url:  as_cai_vars.ajax_url,
				type: 'POST',
				data: {
					action:     'as_cai_register_notification',
					product_id: productId,
					email:      email,
					nonce:      as_cai_vars.nonce
				},
				success: function (response) {
					if (response.success) {
						$modal.find('.as-cai-modal').html(
							'<div class="as-cai-modal-icon as-cai-modal-success-icon">✓</div>' +
							'<h3 class="as-cai-modal-title">Sie stehen auf der Warteliste!</h3>' +
							'<p class="as-cai-modal-text">' + (response.data.message || 'Wir benachrichtigen Sie, sobald eine Parzelle frei wird.') + '</p>' +
							'<div class="as-cai-modal-actions">' +
								'<button type="button" class="as-cai-modal-btn-submit as-cai-modal-btn-done">Verstanden</button>' +
							'</div>'
						);
						$modal.find('.as-cai-modal-btn-done').on('click', closeModal);
					} else {
						$error.text(response.data ? response.data.message : 'Ein Fehler ist aufgetreten.').show();
						$btnText.show(); $btnLoading.hide(); $submit.prop('disabled', false);
					}
				},
				error: function () {
					$error.text('Verbindungsfehler. Bitte versuchen Sie es erneut.').show();
					$btnText.show(); $btnLoading.hide(); $submit.prop('disabled', false);
				}
			});
		});
	};

	$(document).ready(function () {
		if ($('.as-cai-status-box').length) {
			new StatusLiveUpdate();
		}
	});

})(jQuery);
