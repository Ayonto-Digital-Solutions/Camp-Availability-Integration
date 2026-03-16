<?php
/**
 * Admin Reservations — Parzellen direkt im Admin reservieren.
 *
 * Erstellt reine Plugin-Reservierungen (ohne WC-Order).
 * Nutzt Stachethemes' add_meta_taken_seat() / delete_meta_taken_seat().
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.78
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AS_CAI_Admin_Reservations {

	/** @var AS_CAI_Admin_Reservations|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_as_cai_get_available_seats', array( $this, 'ajax_get_available_seats' ) );
		add_action( 'wp_ajax_as_cai_admin_create_reservation', array( $this, 'ajax_create_reservation' ) );
		add_action( 'wp_ajax_as_cai_admin_release_reservation', array( $this, 'ajax_release_reservation' ) );
	}

	/**
	 * Create the admin reservations table if needed.
	 */
	public static function maybe_create_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'as_cai_admin_reservations';
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			seat_id varchar(100) NOT NULL,
			reserved_by bigint(20) unsigned NOT NULL,
			customer_name varchar(255) DEFAULT '',
			customer_email varchar(255) DEFAULT '',
			reason text DEFAULT '',
			created_at datetime NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			released_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY product_id (product_id),
			KEY status (status),
			KEY seat_id (seat_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * AJAX: Get available seats for a product.
	 */
	public function ajax_get_available_seats() {
		check_ajax_referer( 'as_cai_admin_reservations', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Keine Berechtigung' );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error( 'Keine Produkt-ID' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'auditorium' !== $product->get_type() ) {
			wp_send_json_error( 'Nur Auditorium-Produkte werden unterstützt' );
		}

		$seat_plan = $product->get_seat_plan_data( 'object' );
		if ( ! $seat_plan || ! isset( $seat_plan->objects ) ) {
			wp_send_json_error( 'Kein Seat Plan vorhanden' );
		}

		$taken_seats = $product->get_taken_seats();
		if ( ! is_array( $taken_seats ) ) {
			$taken_seats = array();
		}

		// Auch eigene Admin-Reservierungen prüfen (unabhängig von Stachethemes).
		global $wpdb;
		$table_name = $wpdb->prefix . 'as_cai_admin_reservations';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			$own_reserved = $wpdb->get_col( $wpdb->prepare(
				"SELECT seat_id FROM {$table_name} WHERE product_id = %d AND status = 'active'",
				$product_id
			) );
			if ( ! empty( $own_reserved ) ) {
				$taken_seats = array_unique( array_merge( $taken_seats, $own_reserved ) );
			}
		}

		$seats = array();
		foreach ( $seat_plan->objects as $obj ) {
			if ( ! isset( $obj->type ) || 'seat' !== $obj->type || empty( $obj->seatId ) ) {
				continue;
			}

			$status   = isset( $obj->status ) ? $obj->status : 'available';
			$is_taken = in_array( $obj->seatId, $taken_seats, true );

			$seats[] = array(
				'seat_id'   => $obj->seatId,
				'label'     => isset( $obj->label ) ? $obj->label : $obj->seatId,
				'status'    => $is_taken || 'sold-out' === $status ? 'taken' : $status,
				'available' => ! $is_taken && 'available' === $status,
			);
		}

		// Sort by seat ID (natural sort).
		usort( $seats, function( $a, $b ) {
			return strnatcmp( $a['seat_id'], $b['seat_id'] );
		} );

		wp_send_json_success( array( 'seats' => $seats ) );
	}

	/**
	 * AJAX: Create admin reservation.
	 */
	public function ajax_create_reservation() {
		check_ajax_referer( 'as_cai_admin_reservations', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Keine Berechtigung' );
		}

		$product_id    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$seat_ids      = isset( $_POST['seat_ids'] ) ? array_map( 'sanitize_text_field', (array) $_POST['seat_ids'] ) : array();
		$customer_name = isset( $_POST['customer_name'] ) ? sanitize_text_field( $_POST['customer_name'] ) : '';
		$customer_email = isset( $_POST['customer_email'] ) ? sanitize_email( $_POST['customer_email'] ) : '';
		$reason        = isset( $_POST['reason'] ) ? sanitize_textarea_field( $_POST['reason'] ) : '';

		if ( ! $product_id || empty( $seat_ids ) ) {
			wp_send_json_error( 'Produkt und mindestens ein Seat erforderlich' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product || 'auditorium' !== $product->get_type() ) {
			wp_send_json_error( 'Ungültiges Produkt' );
		}

		self::maybe_create_table();

		global $wpdb;
		$table_name = $wpdb->prefix . 'as_cai_admin_reservations';
		$reserved   = array();
		$errors     = array();

		foreach ( $seat_ids as $seat_id ) {
			// Check if already taken.
			$taken_seats = $product->get_taken_seats();
			if ( is_array( $taken_seats ) && in_array( $seat_id, $taken_seats, true ) ) {
				$errors[] = "Seat {$seat_id} ist bereits vergeben";
				continue;
			}

			// Mark as taken in Stachethemes.
			if ( method_exists( $product, 'add_meta_taken_seat' ) ) {
				$product->add_meta_taken_seat( $seat_id );
				$product->save_meta_data();
			}

			// Save to our DB.
			$wpdb->insert(
				$table_name,
				array(
					'product_id'     => $product_id,
					'seat_id'        => $seat_id,
					'reserved_by'    => get_current_user_id(),
					'customer_name'  => $customer_name,
					'customer_email' => $customer_email,
					'reason'         => $reason,
					'created_at'     => current_time( 'mysql' ),
					'status'         => 'active',
				),
				array( '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
			);

			$reserved[] = $seat_id;
		}

		if ( empty( $reserved ) ) {
			wp_send_json_error( 'Keine Seats konnten reserviert werden: ' . implode( ', ', $errors ) );
		}

		$message = count( $reserved ) . ' Seat(s) reserviert: ' . implode( ', ', $reserved );
		if ( ! empty( $errors ) ) {
			$message .= '. Fehler: ' . implode( ', ', $errors );
		}

		wp_send_json_success( array( 'message' => $message, 'reserved' => $reserved, 'errors' => $errors ) );
	}

	/**
	 * AJAX: Release admin reservation.
	 */
	public function ajax_release_reservation() {
		check_ajax_referer( 'as_cai_admin_reservations', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Keine Berechtigung' );
		}

		$reservation_id = isset( $_POST['reservation_id'] ) ? absint( $_POST['reservation_id'] ) : 0;
		if ( ! $reservation_id ) {
			wp_send_json_error( 'Keine Reservierungs-ID' );
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'as_cai_admin_reservations';

		$reservation = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE id = %d AND status = 'active'",
			$reservation_id
		) );

		if ( ! $reservation ) {
			wp_send_json_error( 'Reservierung nicht gefunden oder bereits freigegeben' );
		}

		// Release in Stachethemes.
		$product = wc_get_product( $reservation->product_id );
		if ( $product && method_exists( $product, 'delete_meta_taken_seat' ) ) {
			$product->delete_meta_taken_seat( $reservation->seat_id );
			$product->save_meta_data();
		}

		// Update our DB.
		$wpdb->update(
			$table_name,
			array( 'status' => 'released', 'released_at' => current_time( 'mysql' ) ),
			array( 'id' => $reservation_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		wp_send_json_success( array( 'message' => 'Reservierung für Seat ' . $reservation->seat_id . ' freigegeben' ) );
	}

	/**
	 * Get all active admin reservations.
	 */
	public static function get_active_reservations() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'as_cai_admin_reservations';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return array();
		}

		return $wpdb->get_results(
			"SELECT r.*, u.display_name as admin_name, p.post_title as product_name
			 FROM {$table_name} r
			 LEFT JOIN {$wpdb->users} u ON r.reserved_by = u.ID
			 LEFT JOIN {$wpdb->posts} p ON r.product_id = p.ID
			 WHERE r.status = 'active'
			 ORDER BY r.created_at DESC"
		);
	}

	/**
	 * Render the admin reservations page.
	 */
	public static function render_page() {
		$nonce = wp_create_nonce( 'as_cai_admin_reservations' );

		// Get Auditorium products.
		$products = wc_get_products( array(
			'type'   => 'auditorium',
			'limit'  => 50,
			'status' => 'publish',
			'orderby' => 'name',
			'order'   => 'ASC',
		) );

		// Group products by category for the dropdown.
		$products_by_category = array();
		foreach ( $products as $p ) {
			$categories = wp_get_post_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
			$cat_name   = ! empty( $categories ) && ! is_wp_error( $categories ) ? implode( ', ', $categories ) : 'Ohne Kategorie';
			if ( ! isset( $products_by_category[ $cat_name ] ) ) {
				$products_by_category[ $cat_name ] = array();
			}
			$products_by_category[ $cat_name ][] = $p;
		}
		ksort( $products_by_category );

		$active_reservations = self::get_active_reservations();
		?>

		<!-- Reservierung anlegen -->
		<div class="as-cai-card as-cai-fade-in">
			<div class="as-cai-card-header">
				<h2 class="as-cai-card-title">
					<i class="fas fa-plus-circle"></i>
					Neue Reservierung
				</h2>
			</div>
			<div class="as-cai-card-body">
				<div id="reservation-form">
					<!-- Schritt 1: Produkt -->
					<div class="as-cai-form-group" style="margin-bottom: 16px;">
						<label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">
							<i class="fas fa-campground"></i> Produkt auswählen
						</label>
						<select id="res-product" style="width: 100%; padding: 8px 12px; border: 1px solid var(--as-gray-300, #ddd); border-radius: 6px; font-size: 14px;">
							<option value="">Bitte wählen...</option>
							<?php foreach ( $products_by_category as $cat_name => $cat_products ) : ?>
								<optgroup label="<?php echo esc_attr( $cat_name ); ?>">
									<?php foreach ( $cat_products as $p ) : ?>
										<option value="<?php echo esc_attr( $p->get_id() ); ?>">
											<?php echo esc_html( $p->get_name() ); ?> (#<?php echo esc_html( $p->get_id() ); ?>)
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endforeach; ?>
						</select>
					</div>

					<!-- Schritt 2: Seats -->
					<div id="seat-selection" style="display: none; margin-bottom: 16px;">
						<label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">
							<i class="fas fa-th"></i> Parzelle(n) auswählen
						</label>
						<div id="seat-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(70px, 1fr)); gap: 6px; max-height: 300px; overflow-y: auto; padding: 12px; background: var(--as-gray-50, #f9f9f9); border-radius: 8px; border: 1px solid var(--as-gray-200, #eee);"></div>
						<div id="seat-loading" style="text-align: center; padding: 20px; display: none;">
							<i class="fas fa-spinner fa-spin" style="font-size: 20px; color: var(--as-primary);"></i>
						</div>
						<div style="margin-top: 8px; font-size: 12px; color: var(--as-gray-500, #999);">
							<span style="display: inline-block; width: 12px; height: 12px; background: #22c55e; border-radius: 3px; vertical-align: middle;"></span> Verfügbar
							<span style="display: inline-block; width: 12px; height: 12px; background: #ef4444; border-radius: 3px; vertical-align: middle; margin-left: 12px;"></span> Vergeben
							<span style="display: inline-block; width: 12px; height: 12px; background: #3b82f6; border-radius: 3px; vertical-align: middle; margin-left: 12px;"></span> Ausgewählt
						</div>
					</div>

					<!-- Schritt 3: Details -->
					<div id="reservation-details" style="display: none;">
						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
							<div>
								<label style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">Kundenname (optional)</label>
								<input type="text" id="res-customer-name" placeholder="Max Mustermann"
									   style="width: 100%; padding: 8px 12px; border: 1px solid var(--as-gray-300, #ddd); border-radius: 6px; font-size: 14px;">
							</div>
							<div>
								<label style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">E-Mail (optional)</label>
								<input type="email" id="res-customer-email" placeholder="max@example.com"
									   style="width: 100%; padding: 8px 12px; border: 1px solid var(--as-gray-300, #ddd); border-radius: 6px; font-size: 14px;">
							</div>
						</div>
						<div style="margin-bottom: 16px;">
							<label style="display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px;">Grund / Notiz</label>
							<textarea id="res-reason" rows="2" placeholder="z.B. Telefonische Reservierung"
									  style="width: 100%; padding: 8px 12px; border: 1px solid var(--as-gray-300, #ddd); border-radius: 6px; font-size: 14px; resize: vertical;"></textarea>
						</div>

						<div id="res-summary" style="display: none; padding: 12px; background: var(--as-gray-50, #f9f9f9); border-radius: 8px; margin-bottom: 16px; border-left: 4px solid var(--as-primary, #0583F2);">
						</div>

						<button id="res-submit" class="as-cai-btn as-cai-btn-primary" style="font-size: 15px; padding: 10px 24px;">
							<i class="fas fa-check"></i> Reservierung anlegen
						</button>
					</div>

					<div id="res-result" style="display: none; margin-top: 16px;"></div>
				</div>
			</div>
		</div>

		<!-- Aktive Reservierungen -->
		<div class="as-cai-card as-cai-fade-in" style="margin-top: 20px;">
			<div class="as-cai-card-header">
				<h2 class="as-cai-card-title">
					<i class="fas fa-list"></i>
					Aktive Admin-Reservierungen
					<?php if ( ! empty( $active_reservations ) ) : ?>
						<span style="background: var(--as-primary, #0583F2); color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 12px; margin-left: 8px;">
							<?php echo count( $active_reservations ); ?>
						</span>
					<?php endif; ?>
				</h2>
			</div>
			<div class="as-cai-card-body" id="active-reservations-list">
				<?php if ( empty( $active_reservations ) ) : ?>
					<p style="color: var(--as-gray-500, #999); text-align: center; padding: 20px;">
						<i class="fas fa-inbox" style="font-size: 24px; display: block; margin-bottom: 8px;"></i>
						Keine aktiven Admin-Reservierungen
					</p>
				<?php else : ?>
					<div style="overflow-x: auto;">
						<table class="as-cai-table" style="width: 100%;">
							<thead>
								<tr>
									<th>Seat</th>
									<th>Produkt</th>
									<th>Kunde</th>
									<th>Grund</th>
									<th>Reserviert von</th>
									<th>Datum</th>
									<th>Aktion</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $active_reservations as $res ) : ?>
									<tr id="reservation-row-<?php echo esc_attr( $res->id ); ?>">
										<td><strong><?php echo esc_html( $res->seat_id ); ?></strong></td>
										<td><?php echo esc_html( $res->product_name ?: '#' . $res->product_id ); ?></td>
										<td>
											<?php echo esc_html( $res->customer_name ?: '—' ); ?>
											<?php if ( $res->customer_email ) : ?>
												<br><small style="color: var(--as-gray-500, #999);"><?php echo esc_html( $res->customer_email ); ?></small>
											<?php endif; ?>
										</td>
										<td><small><?php echo esc_html( $res->reason ?: '—' ); ?></small></td>
										<td><?php echo esc_html( $res->admin_name ?: '#' . $res->reserved_by ); ?></td>
										<td><small><?php echo esc_html( wp_date( 'd.m.Y H:i', strtotime( $res->created_at ) ) ); ?></small></td>
										<td>
											<button class="as-cai-btn release-reservation-btn" style="font-size: 12px; padding: 4px 10px; background: #ef4444; color: #fff; border: none; border-radius: 4px; cursor: pointer;"
													data-id="<?php echo esc_attr( $res->id ); ?>">
												<i class="fas fa-unlock"></i> Freigeben
											</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var resNonce = '<?php echo esc_js( $nonce ); ?>';
			var selectedSeats = [];

			// Produkt auswählen → Seats laden.
			$('#res-product').on('change', function() {
				var productId = $(this).val();
				selectedSeats = [];
				if (!productId) {
					$('#seat-selection, #reservation-details').hide();
					return;
				}

				$('#seat-selection').show();
				$('#seat-grid').empty();
				$('#seat-loading').show();
				$('#reservation-details').hide();

				$.post(ajaxurl, {
					action: 'as_cai_get_available_seats',
					product_id: productId,
					nonce: resNonce
				}, function(r) {
					$('#seat-loading').hide();
					if (!r.success) {
						$('#seat-grid').html('<p style="color:#ef4444;grid-column:1/-1;">' + (r.data || 'Fehler') + '</p>');
						return;
					}

					r.data.seats.forEach(function(seat) {
						var isAvail = seat.available;
						var $btn = $('<button type="button"></button>')
							.text(seat.label || seat.seat_id)
							.attr('data-seat-id', seat.seat_id)
							.css({
								padding: '8px 4px',
								border: '2px solid ' + (isAvail ? '#22c55e' : '#ef4444'),
								borderRadius: '6px',
								background: isAvail ? '#f0fdf4' : '#fef2f2',
								color: isAvail ? '#166534' : '#991b1b',
								fontSize: '13px',
								fontWeight: '600',
								cursor: isAvail ? 'pointer' : 'not-allowed',
								opacity: isAvail ? 1 : 0.5,
								transition: 'all 0.15s ease'
							});

						if (isAvail) {
							$btn.on('click', function() {
								var sId = $(this).data('seat-id');
								var idx = selectedSeats.indexOf(sId);
								if (idx > -1) {
									selectedSeats.splice(idx, 1);
									$(this).css({ background: '#f0fdf4', borderColor: '#22c55e', color: '#166534' });
								} else {
									selectedSeats.push(sId);
									$(this).css({ background: '#3b82f6', borderColor: '#3b82f6', color: '#fff' });
								}
								updateSummary();
							});
						}

						$('#seat-grid').append($btn);
					});

					$('#reservation-details').show();
				});
			});

			function updateSummary() {
				var $summary = $('#res-summary');
				if (selectedSeats.length === 0) {
					$summary.hide();
					return;
				}
				$summary.show().html(
					'<strong>' + selectedSeats.length + ' Parzelle(n) ausgewählt:</strong> ' + selectedSeats.join(', ')
				);
			}

			// Reservierung anlegen.
			$('#res-submit').on('click', function() {
				if (selectedSeats.length === 0) {
					$('#res-result').show().html('<div style="padding:10px;background:#fef2f2;border-left:4px solid #ef4444;border-radius:4px;color:#991b1b;">Bitte mindestens eine Parzelle auswählen</div>');
					return;
				}

				var $btn = $(this);
				$btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Wird angelegt...');

				$.post(ajaxurl, {
					action: 'as_cai_admin_create_reservation',
					product_id: $('#res-product').val(),
					seat_ids: selectedSeats,
					customer_name: $('#res-customer-name').val(),
					customer_email: $('#res-customer-email').val(),
					reason: $('#res-reason').val(),
					nonce: resNonce
				}, function(r) {
					$btn.prop('disabled', false).html('<i class="fas fa-check"></i> Reservierung anlegen');
					if (r.success) {
						$('#res-result').show().html('<div style="padding:10px;background:#f0fdf4;border-left:4px solid #22c55e;border-radius:4px;color:#166534;"><i class="fas fa-check-circle"></i> ' + r.data.message + '</div>');
						// Seats neu laden.
						$('#res-product').trigger('change');
						selectedSeats = [];
						// Seite nach 2s neu laden für aktualisierte Reservierungsliste.
						setTimeout(function() { location.reload(); }, 2000);
					} else {
						$('#res-result').show().html('<div style="padding:10px;background:#fef2f2;border-left:4px solid #ef4444;border-radius:4px;color:#991b1b;">' + (r.data || 'Fehler') + '</div>');
					}
				});
			});

			// Reservierung freigeben.
			$(document).on('click', '.release-reservation-btn', function() {
				var $btn = $(this);
				var resId = $btn.data('id');

				if (!confirm('Reservierung wirklich freigeben?')) return;

				$btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i>');

				$.post(ajaxurl, {
					action: 'as_cai_admin_release_reservation',
					reservation_id: resId,
					nonce: resNonce
				}, function(r) {
					if (r.success) {
						$('#reservation-row-' + resId).fadeOut(300, function() { $(this).remove(); });
						if (typeof asCaiToast !== 'undefined') asCaiToast.show(r.data.message, 'success');
					} else {
						$btn.prop('disabled', false).html('<i class="fas fa-unlock"></i> Freigeben');
						if (typeof asCaiToast !== 'undefined') asCaiToast.show(r.data || 'Fehler', 'error');
					}
				});
			});
		});
		</script>
		<?php
	}
}
