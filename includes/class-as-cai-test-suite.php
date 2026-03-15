<?php
/**
 * Test Suite - Automatisierte Tests für das Reservierungssystem.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.14
 * @since   1.3.70 Komplett überarbeitet: Deutsche UI, saubere Ausgabe, robustere Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AS_CAI_Test_Suite {

	private static $instance = null;
	private $db              = null;
	private $test_results    = array();

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->db = AS_CAI_Reservation_DB::instance();
		add_action( 'wp_ajax_as_cai_run_tests', array( $this, 'run_tests_ajax' ) );
		add_action( 'wp_ajax_as_cai_status_diagnose', array( $this, 'status_diagnose_ajax' ) );
	}

	/**
	 * Render test page.
	 */
	public function render_page() {
		?>
		<div class="as-cai-card as-cai-fade-in">
			<div class="as-cai-card-header">
				<h2 class="as-cai-card-title">
					<i class="fas fa-vial"></i>
					<?php esc_html_e( 'Systemtests', 'as-camp-availability-integration' ); ?>
				</h2>
			</div>
			<div class="as-cai-card-body">
				<p style="margin-bottom: 16px; color: var(--as-gray-600, #666);">
					<?php esc_html_e( 'Prüft alle kritischen Funktionen des Reservierungssystems.', 'as-camp-availability-integration' ); ?>
				</p>
				<button id="run-all-tests" class="as-cai-btn as-cai-btn-primary" style="font-size: 15px; padding: 10px 20px;">
					<i class="fas fa-play"></i>
					<?php esc_html_e( 'Alle Tests ausführen', 'as-camp-availability-integration' ); ?>
				</button>

				<div id="test-results" style="display:none; margin-top: 20px;">
					<div id="test-results-content"></div>
				</div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			$('#run-all-tests').on('click', function() {
				var $btn = $(this);
				var orig = $btn.html();
				$btn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> <?php echo esc_js( __( 'Tests laufen...', 'as-camp-availability-integration' ) ); ?>');
				$('#test-results').show();
				$('#test-results-content').html('<div style="text-align:center;padding:30px;"><i class="fas fa-spinner fa-spin" style="font-size:32px;color:var(--as-primary);"></i></div>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'as_cai_run_tests',
						nonce: '<?php echo esc_js( wp_create_nonce( 'as_cai_tests' ) ); ?>'
					},
					success: function(r) {
						$btn.prop('disabled', false).html(orig);
						$('#test-results-content').html(r.success ? r.data.html : '<div style="padding:12px;background:#fee;border-left:4px solid #dc3232;border-radius:4px;"><strong><?php echo esc_js( __( 'Fehler:', 'as-camp-availability-integration' ) ); ?></strong> ' + (r.data ? r.data.message : 'Unbekannt') + '</div>');
					},
					error: function() {
						$btn.prop('disabled', false).html(orig);
						$('#test-results-content').html('<div style="padding:12px;background:#fee;border-left:4px solid #dc3232;border-radius:4px;"><strong><?php echo esc_js( __( 'Verbindungsfehler', 'as-camp-availability-integration' ) ); ?></strong></div>');
					}
				});
			});
		});
		</script>
		<?php

		// Status-Diagnose Sektion.
		$this->render_status_diagnose();
	}

	/**
	 * Render Status-Display Diagnose Panel.
	 */
	private function render_status_diagnose() {
		// Alle unterstützten Produkte laden.
		$auditorium_products = wc_get_products( array(
			'type'   => 'auditorium',
			'limit'  => 50,
			'return' => 'objects',
			'status' => 'publish',
		) );

		$simple_products = wc_get_products( array(
			'type'   => 'simple',
			'limit'  => 50,
			'return' => 'objects',
			'status' => 'publish',
		) );
		// Nur Simple-Produkte mit Stock Management.
		$simple_products = array_filter( $simple_products, function( $p ) {
			return $p->managing_stock();
		} );

		$nonce = wp_create_nonce( 'as_cai_status_diagnose' );
		?>

		<div class="as-cai-card as-cai-fade-in" style="margin-top: 20px;">
			<div class="as-cai-card-header">
				<h2 class="as-cai-card-title">
					<i class="fas fa-chart-bar"></i>
					Status-Display Diagnose
				</h2>
			</div>
			<div class="as-cai-card-body">
				<p style="margin-bottom: 16px; color: var(--as-gray-600, #666);">
					Prüft die Verfügbarkeits-Daten für echte Produkte und zeigt die Rohdaten der jeweiligen Datenquelle.
				</p>

				<!-- Produkt-Auswahl -->
				<div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px;">
					<div style="flex: 1; min-width: 250px;">
						<label style="display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px;">
							<i class="fas fa-campground"></i> Produkt auswählen
						</label>
						<select id="status-diagnose-product" style="width: 100%; padding: 8px 12px; border: 1px solid var(--as-gray-300, #ddd); border-radius: 6px; font-size: 14px;">
							<?php if ( ! empty( $auditorium_products ) ) : ?>
								<optgroup label="Auditorium (Parzellen) — Stachethemes">
									<?php foreach ( $auditorium_products as $p ) : ?>
										<option value="<?php echo esc_attr( $p->get_id() ); ?>" data-type="auditorium">
											<?php echo esc_html( $p->get_name() ); ?> (#<?php echo esc_html( $p->get_id() ); ?>)
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
							<?php if ( ! empty( $simple_products ) ) : ?>
								<optgroup label="Einfache Produkte (Zimmer/Bungalows) — WC Stock">
									<?php foreach ( $simple_products as $p ) : ?>
										<option value="<?php echo esc_attr( $p->get_id() ); ?>" data-type="simple">
											<?php echo esc_html( $p->get_name() ); ?> (#<?php echo esc_html( $p->get_id() ); ?>) — Stock: <?php echo esc_html( $p->get_stock_quantity() ); ?>
										</option>
									<?php endforeach; ?>
								</optgroup>
							<?php endif; ?>
							<?php if ( empty( $auditorium_products ) && empty( $simple_products ) ) : ?>
								<option value="">Keine unterstützten Produkte gefunden</option>
							<?php endif; ?>
						</select>
					</div>
				</div>

				<!-- Buttons -->
				<div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
					<button id="run-status-diagnose" class="as-cai-btn as-cai-btn-primary" style="font-size: 14px; padding: 8px 16px;">
						<i class="fas fa-search"></i> Echte Daten prüfen
					</button>
					<button id="run-status-simulate" class="as-cai-btn" style="font-size: 14px; padding: 8px 16px; background: var(--as-gray-100, #f5f5f5); border: 1px solid var(--as-gray-300, #ddd); color: var(--as-gray-700, #333);">
						<i class="fas fa-flask"></i> Simulation starten
					</button>
				</div>

				<!-- Simulations-Parameter (zunächst versteckt) -->
				<div id="simulation-params" style="display: none; padding: 16px; background: var(--as-gray-50, #f9f9f9); border-radius: 8px; margin-bottom: 16px; border: 1px solid var(--as-gray-200, #eee);">
					<h4 style="margin: 0 0 12px 0; font-size: 14px; color: var(--as-gray-700, #333);">
						<i class="fas fa-sliders-h"></i> Simulationsparameter
					</h4>
					<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
						<div>
							<label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Gesamt</label>
							<input type="number" id="sim-total" value="45" min="1" max="999"
								   style="width: 100%; padding: 6px 10px; border: 1px solid var(--as-gray-300, #ddd); border-radius: 6px;">
						</div>
						<div>
							<label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Verfügbar</label>
							<input type="number" id="sim-available" value="20" min="0" max="999"
								   style="width: 100%; padding: 6px 10px; border: 1px solid var(--as-gray-300, #ddd); border-radius: 6px;">
						</div>
						<div>
							<label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Verkauft</label>
							<input type="number" id="sim-sold" value="23" min="0" max="999"
								   style="width: 100%; padding: 6px 10px; border: 1px solid var(--as-gray-300, #ddd); border-radius: 6px;">
						</div>
						<div>
							<label style="display: block; font-size: 12px; font-weight: 600; margin-bottom: 4px;">Reserviert</label>
							<input type="number" id="sim-reserved" value="2" min="0" max="999"
								   style="width: 100%; padding: 6px 10px; border: 1px solid var(--as-gray-300, #ddd); border-radius: 6px;">
						</div>
					</div>
					<button id="run-simulation-now" class="as-cai-btn as-cai-btn-primary" style="margin-top: 12px; font-size: 13px; padding: 6px 14px;">
						<i class="fas fa-play"></i> Simulieren
					</button>
				</div>

				<!-- Ergebnis -->
				<div id="status-diagnose-result" style="display: none;"></div>
			</div>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var diagNonce = '<?php echo esc_js( $nonce ); ?>';

			// Toggle Simulation Panel.
			$('#run-status-simulate').on('click', function() {
				$('#simulation-params').slideToggle(200);
			});

			// Echte Daten prüfen.
			$('#run-status-diagnose').on('click', function() {
				var productId = $('#status-diagnose-product').val();
				if (!productId) return;
				runDiagnose({ product_id: productId });
			});

			// Simulation.
			$('#run-simulation-now').on('click', function() {
				runDiagnose({
					product_id: $('#status-diagnose-product').val() || 0,
					simulate: 1,
					sim_total: $('#sim-total').val(),
					sim_available: $('#sim-available').val(),
					sim_sold: $('#sim-sold').val(),
					sim_reserved: $('#sim-reserved').val()
				});
			});

			function runDiagnose(params) {
				var $result = $('#status-diagnose-result');
				$result.show().html('<div style="text-align:center;padding:20px;"><i class="fas fa-spinner fa-spin" style="font-size:24px;color:var(--as-primary);"></i></div>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: $.extend({ action: 'as_cai_status_diagnose', nonce: diagNonce }, params),
					success: function(r) {
						if (r.success) {
							$result.html(renderDiagnoseResult(r.data));
						} else {
							$result.html('<div style="padding:12px;background:#fee;border-left:4px solid #dc3232;border-radius:4px;"><strong>Fehler:</strong> ' + (r.data || 'Unbekannt') + '</div>');
						}
					},
					error: function() {
						$result.html('<div style="padding:12px;background:#fee;border-left:4px solid #dc3232;border-radius:4px;"><strong>Verbindungsfehler</strong></div>');
					}
				});
			}

			function renderDiagnoseResult(data) {
				var status = data.computed_status;
				var statusColors = {
					'available': '#22c55e', 'limited': '#f59e0b', 'critical': '#ef4444',
					'reserved_full': '#8b5cf6', 'sold_out': '#dc2626'
				};
				var statusLabels = {
					'available': 'Sofort buchbar', 'limited': 'Nur noch wenige',
					'critical': 'Letzte!', 'reserved_full': 'Alle reserviert', 'sold_out': 'Ausgebucht'
				};

				var html = '<div style="border: 1px solid var(--as-gray-200, #eee); border-radius: 8px; overflow: hidden;">';

				// Header.
				html += '<div style="padding: 14px 16px; background: var(--as-gray-50, #f9f9f9); border-bottom: 1px solid var(--as-gray-200, #eee); display: flex; justify-content: space-between; align-items: center;">';
				html += '<div>';
				html += '<strong style="font-size: 15px;">' + (data.product_name || 'Unbekannt') + '</strong>';
				html += '<span style="margin-left: 8px; font-size: 12px; padding: 2px 8px; border-radius: 10px; background: ' + (data.product_type === 'auditorium' ? '#dbeafe' : '#f3e8ff') + '; color: ' + (data.product_type === 'auditorium' ? '#1d4ed8' : '#7c3aed') + ';">';
				html += data.product_type === 'auditorium' ? 'Stachethemes' : 'WC Stock';
				html += '</span>';
				if (data.is_simulation) {
					html += '<span style="margin-left: 8px; font-size: 12px; padding: 2px 8px; border-radius: 10px; background: #fef3c7; color: #92400e;">Simulation</span>';
				}
				html += '</div>';
				html += '<span style="font-size: 12px; color: var(--as-gray-500, #999);">v' + (data.plugin_version || '?') + '</span>';
				html += '</div>';

				// Status-Ergebnis.
				if (status) {
					var sColor = statusColors[status.status] || '#666';
					var sLabel = statusLabels[status.status] || status.status;
					html += '<div style="padding: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">';

					// Status-Badge.
					html += '<div style="grid-column: 1 / -1; padding: 12px; background: ' + sColor + '15; border-radius: 6px; border-left: 4px solid ' + sColor + ';">';
					html += '<strong style="color: ' + sColor + '; font-size: 16px;">' + sLabel + '</strong>';
					html += '<span style="float: right; font-size: 14px; font-weight: 600;">' + status.percent_free + '% verfügbar</span>';
					html += '</div>';

					// Zahlen.
					var cells = [
						{ label: 'Gesamt', value: status.total, icon: '📊' },
						{ label: 'Verfügbar', value: status.available, icon: '✅' },
						{ label: 'Verkauft', value: status.sold, icon: '🔴' },
						{ label: 'Reserviert', value: status.reserved, icon: '🔒' },
					];
					cells.forEach(function(c) {
						html += '<div style="padding: 10px; background: var(--as-gray-50, #f9f9f9); border-radius: 6px; text-align: center;">';
						html += '<div style="font-size: 22px; font-weight: 700;">' + c.value + '</div>';
						html += '<div style="font-size: 12px; color: var(--as-gray-500, #999);">' + c.icon + ' ' + c.label + '</div>';
						html += '</div>';
					});

					html += '</div>';
				} else {
					html += '<div style="padding: 16px; text-align: center; color: var(--as-gray-500, #999);">';
					html += '<i class="fas fa-exclamation-triangle" style="font-size: 32px; margin-bottom: 8px; display: block; color: #f59e0b;"></i>';
					html += '<strong>Keine Status-Daten</strong><br>';
					html += '<small>get_detailed_availability_status() gibt null zurück</small>';
					html += '</div>';
				}

				// Rohdaten.
				html += '<div style="padding: 12px 16px; border-top: 1px solid var(--as-gray-200, #eee); background: #1e1e2e; color: #cdd6f4; border-radius: 0 0 8px 8px;">';
				html += '<details><summary style="cursor: pointer; font-size: 13px; font-weight: 600; color: #89b4fa;">Rohdaten anzeigen</summary>';
				html += '<pre style="margin-top: 8px; font-size: 12px; line-height: 1.5; overflow-x: auto; white-space: pre-wrap;">' + JSON.stringify(data, null, 2) + '</pre>';
				html += '</details>';
				html += '</div>';

				html += '</div>';
				return html;
			}
		});
		</script>
		<?php
	}

	/**
	 * AJAX handler: Status Diagnose.
	 */
	public function status_diagnose_ajax() {
		check_ajax_referer( 'as_cai_status_diagnose', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Keine Berechtigung' );
		}

		$product_id    = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$is_simulation = ! empty( $_POST['simulate'] );

		// Simulation.
		if ( $is_simulation ) {
			$sim_total     = isset( $_POST['sim_total'] ) ? absint( $_POST['sim_total'] ) : 45;
			$sim_available = isset( $_POST['sim_available'] ) ? absint( $_POST['sim_available'] ) : 20;
			$sim_sold      = isset( $_POST['sim_sold'] ) ? absint( $_POST['sim_sold'] ) : 23;
			$sim_reserved  = isset( $_POST['sim_reserved'] ) ? absint( $_POST['sim_reserved'] ) : 2;

			$bookable     = max( 0, $sim_available - $sim_reserved );
			$percent_free = ( $sim_total > 0 ) ? ( $bookable / $sim_total ) * 100 : 0;

			if ( $sim_available <= 0 ) {
				$status = 'sold_out';
			} elseif ( $bookable <= 0 && $sim_reserved > 0 ) {
				$status = 'reserved_full';
			} elseif ( $percent_free > 20 ) {
				$status = 'available';
			} elseif ( $percent_free > 5 ) {
				$status = 'limited';
			} else {
				$status = 'critical';
			}

			$product = $product_id ? wc_get_product( $product_id ) : null;

			wp_send_json_success( array(
				'plugin_version' => AS_CAI_VERSION,
				'product_id'     => $product_id,
				'product_type'   => $product ? $product->get_type() : 'simulation',
				'product_name'   => $product ? $product->get_name() : 'Simulation',
				'data_source'    => 'simulation',
				'is_simulation'  => true,
				'computed_status' => array(
					'status'       => $status,
					'total'        => $sim_total,
					'available'    => $sim_available,
					'sold'         => $sim_sold,
					'reserved'     => $sim_reserved,
					'percent_free' => round( $percent_free, 1 ),
				),
			) );
			return;
		}

		// Echte Daten.
		if ( ! $product_id ) {
			wp_send_json_error( 'Keine Produkt-ID angegeben' );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( 'Produkt nicht gefunden' );
		}

		$type = $product->get_type();

		$debug = array(
			'plugin_version' => AS_CAI_VERSION,
			'product_id'     => $product_id,
			'product_type'   => $type,
			'product_name'   => $product->get_name(),
			'data_source'    => 'auditorium' === $type ? 'stachethemes_seat_plan' : 'woocommerce_stock',
			'is_simulation'  => false,
		);

		// Typ-spezifische Rohdaten.
		if ( 'auditorium' === $type ) {
			$seat_plan     = method_exists( $product, 'get_seat_plan_data' ) ? $product->get_seat_plan_data( 'object' ) : null;
			$seat_count    = 0;
			$total_objects = 0;

			if ( $seat_plan && isset( $seat_plan->objects ) && is_array( $seat_plan->objects ) ) {
				$total_objects = count( $seat_plan->objects );
				foreach ( $seat_plan->objects as $obj ) {
					if ( isset( $obj->type ) && 'seat' === $obj->type ) {
						$seat_count++;
					}
				}
			}

			$taken = method_exists( $product, 'get_taken_seats' ) ? $product->get_taken_seats() : array();

			$debug['seat_plan_total_objects'] = $total_objects;
			$debug['seat_plan_seat_count']    = $seat_count;
			$debug['taken_seats']             = is_array( $taken ) ? $taken : array();
			$debug['taken_seats_count']       = is_array( $taken ) ? count( $taken ) : 0;
		} else {
			$debug['managing_stock'] = $product->managing_stock();
			$debug['stock_quantity'] = $product->get_stock_quantity();
			$debug['stock_status']   = $product->get_stock_status();
		}

		$debug['computed_status'] = AS_CAI_Status_Display::get_detailed_availability_status( $product_id );

		wp_send_json_success( $debug );
	}

	/**
	 * AJAX handler.
	 */
	public function run_tests_ajax() {
		check_ajax_referer( 'as_cai_tests', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung', 'as-camp-availability-integration' ) ) );
		}

		$this->test_results = array();

		$this->test_db_table();
		$this->test_create_reservation();
		$this->test_expire_reservation();
		$this->test_wc_cart();
		$this->test_wc_session();
		$this->test_seat_planner_transient();
		$this->test_hooks();

		wp_send_json_success( array( 'html' => $this->generate_report() ) );
	}

	// --------------------------------------------------
	// Individual Tests
	// --------------------------------------------------

	/**
	 * Test: Datenbank-Tabelle existiert.
	 */
	private function test_db_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'as_cai_cart_reservations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		if ( $exists ) {
			$this->pass( __( 'Datenbank', 'as-camp-availability-integration' ), __( 'Reservierungstabelle existiert', 'as-camp-availability-integration' ) );
		} else {
			$this->fail( __( 'Datenbank', 'as-camp-availability-integration' ), __( 'Reservierungstabelle fehlt', 'as-camp-availability-integration' ), $table );
		}
	}

	/**
	 * Test: Reservierung erstellen und wieder löschen.
	 */
	private function test_create_reservation() {
		global $wpdb;

		$table       = $wpdb->prefix . 'as_cai_cart_reservations';
		$customer_id = 'test_' . uniqid();
		$product_id  = 99999;

		// Direct insert instead of reserve_stock() — avoids needing a real WC product.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$inserted = $wpdb->insert(
			$table,
			array(
				'customer_id'    => $customer_id,
				'product_id'     => $product_id,
				'stock_quantity' => 1,
				'timestamp'      => current_time( 'mysql' ),
				'expires'        => gmdate( 'Y-m-d H:i:s', time() + 300 ),
			),
			array( '%s', '%d', '%f', '%s', '%s' )
		);

		if ( ! $inserted ) {
			$this->fail( __( 'Reservierung erstellen', 'as-camp-availability-integration' ), __( 'INSERT in Reservierungstabelle fehlgeschlagen', 'as-camp-availability-integration' ) );
			return;
		}

		// Verify it exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE customer_id = %s AND product_id = %d",
			$customer_id,
			$product_id
		) );

		// Cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'customer_id' => $customer_id, 'product_id' => $product_id ), array( '%s', '%d' ) );

		if ( $found > 0 ) {
			$this->pass( __( 'Reservierung erstellen', 'as-camp-availability-integration' ), __( 'Reservierung erfolgreich erstellt und wieder gelöscht', 'as-camp-availability-integration' ) );
		} else {
			$this->fail( __( 'Reservierung erstellen', 'as-camp-availability-integration' ), __( 'Reservierung wurde nicht in der Datenbank gefunden', 'as-camp-availability-integration' ) );
		}
	}

	/**
	 * Test: Abgelaufene Reservierungen werden korrekt gefiltert.
	 */
	private function test_expire_reservation() {
		global $wpdb;

		$table       = $wpdb->prefix . 'as_cai_cart_reservations';
		$customer_id = 'test_expire_' . uniqid();
		$product_id  = 99998;

		// Insert already-expired reservation.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$table,
			array(
				'customer_id'    => $customer_id,
				'product_id'     => $product_id,
				'stock_quantity' => 1,
				'timestamp'      => '2020-01-01 00:00:00',
				'expires'        => '2020-01-01 00:05:00',
			),
			array( '%s', '%d', '%f', '%s', '%s' )
		);

		$reserved = $this->db->get_reserved_products_by_customer( $customer_id );

		// Cleanup.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'customer_id' => $customer_id ), array( '%s' ) );

		if ( empty( $reserved ) || ! isset( $reserved[ $product_id ] ) ) {
			$this->pass( __( 'Ablauf-Filter', 'as-camp-availability-integration' ), __( 'Abgelaufene Reservierung wird korrekt ignoriert', 'as-camp-availability-integration' ) );
		} else {
			$this->fail( __( 'Ablauf-Filter', 'as-camp-availability-integration' ), __( 'Abgelaufene Reservierung wird noch als aktiv gewertet', 'as-camp-availability-integration' ) );
		}
	}

	/**
	 * Test: WooCommerce Warenkorb verfügbar.
	 */
	private function test_wc_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			$this->fail( __( 'WooCommerce Warenkorb', 'as-camp-availability-integration' ), __( 'WC()->cart ist nicht verfügbar', 'as-camp-availability-integration' ) );
			return;
		}

		$count = count( WC()->cart->get_cart() );
		$this->pass(
			__( 'WooCommerce Warenkorb', 'as-camp-availability-integration' ),
			sprintf(
				/* translators: %d: number of items */
				__( 'Warenkorb erreichbar (%d Artikel)', 'as-camp-availability-integration' ),
				$count
			)
		);
	}

	/**
	 * Test: WooCommerce Session verfügbar.
	 */
	private function test_wc_session() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			$this->fail( __( 'WooCommerce Session', 'as-camp-availability-integration' ), __( 'WC()->session ist nicht verfügbar', 'as-camp-availability-integration' ) );
			return;
		}

		$session_id = WC()->session->get_customer_id();
		$this->pass(
			__( 'WooCommerce Session', 'as-camp-availability-integration' ),
			sprintf(
				/* translators: %s: session ID */
				__( 'Session aktiv (ID: %s)', 'as-camp-availability-integration' ),
				substr( $session_id, 0, 12 ) . '...'
			)
		);
	}

	/**
	 * Test: Seat Planner Transient setzen und löschen.
	 */
	private function test_seat_planner_transient() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			$this->skip( __( 'Seat Planner Transient', 'as-camp-availability-integration' ), __( 'WC Session nicht verfügbar', 'as-camp-availability-integration' ) );
			return;
		}

		$key = 'stachethemes_seat_selection_99999';

		WC()->session->set( $key, array( 'test' => true ) );
		$before = WC()->session->get( $key );

		WC()->session->set( $key, null );
		$after = WC()->session->get( $key );

		if ( ! empty( $before ) && empty( $after ) ) {
			$this->pass( __( 'Seat Planner Transient', 'as-camp-availability-integration' ), __( 'Transient setzen und löschen funktioniert', 'as-camp-availability-integration' ) );
		} else {
			$this->fail( __( 'Seat Planner Transient', 'as-camp-availability-integration' ), __( 'Transient konnte nicht korrekt gesetzt/gelöscht werden', 'as-camp-availability-integration' ) );
		}
	}

	/**
	 * Test: Wichtige WooCommerce Hooks sind registriert.
	 */
	private function test_hooks() {
		global $wp_filter;

		$required_hooks = array(
			'woocommerce_cart_loaded_from_session'           => __( 'Warenkorb aus Session geladen', 'as-camp-availability-integration' ),
			'woocommerce_before_calculate_totals'            => __( 'Vor Berechnung der Summen', 'as-camp-availability-integration' ),
		);

		$missing = array();
		foreach ( $required_hooks as $hook => $label ) {
			if ( ! isset( $wp_filter[ $hook ] ) ) {
				$missing[] = $label . ' (' . $hook . ')';
			}
		}

		if ( empty( $missing ) ) {
			$this->pass(
				__( 'Hook-Registrierung', 'as-camp-availability-integration' ),
				sprintf(
					/* translators: %d: number of hooks */
					__( 'Alle %d WooCommerce-Hooks registriert', 'as-camp-availability-integration' ),
					count( $required_hooks )
				)
			);
		} else {
			$this->fail(
				__( 'Hook-Registrierung', 'as-camp-availability-integration' ),
				__( 'Fehlende Hooks: ', 'as-camp-availability-integration' ) . implode( ', ', $missing )
			);
		}
	}

	// --------------------------------------------------
	// Result helpers
	// --------------------------------------------------

	private function pass( $name, $message ) {
		$this->test_results[] = array( 'status' => 'pass', 'name' => $name, 'message' => $message );
	}

	private function fail( $name, $message, $detail = '' ) {
		$this->test_results[] = array( 'status' => 'fail', 'name' => $name, 'message' => $message, 'detail' => $detail );
	}

	private function skip( $name, $message ) {
		$this->test_results[] = array( 'status' => 'skip', 'name' => $name, 'message' => $message );
	}

	// --------------------------------------------------
	// Report
	// --------------------------------------------------

	private function generate_report() {
		$total  = count( $this->test_results );
		$passed = 0;
		$failed = 0;

		foreach ( $this->test_results as $r ) {
			if ( 'pass' === $r['status'] ) {
				$passed++;
			} elseif ( 'fail' === $r['status'] ) {
				$failed++;
			}
		}

		$all_ok       = ( 0 === $failed );
		$summary_bg   = $all_ok ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)';
		$summary_border = $all_ok ? '#10b981' : '#ef4444';
		$summary_color  = $all_ok ? '#065f46' : '#991b1b';
		$summary_icon   = $all_ok ? 'fa-check-circle' : 'fa-exclamation-triangle';

		$html  = '<div style="padding:12px 16px;background:' . $summary_bg . ';border:1px solid ' . $summary_border . ';border-radius:8px;margin-bottom:16px;">';
		$html .= '<strong style="font-size:15px;color:' . $summary_color . ';">';
		$html .= '<i class="fas ' . $summary_icon . '"></i> ';
		$html .= sprintf(
			/* translators: 1: passed count, 2: total count */
			esc_html__( '%1$d von %2$d Tests bestanden', 'as-camp-availability-integration' ),
			$passed,
			$total
		);
		$html .= '</strong></div>';

		$html .= '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
		$html .= '<thead><tr style="background:#f9f9f9;border-bottom:2px solid #ddd;">';
		$html .= '<th style="padding:8px 12px;text-align:left;width:30px;"></th>';
		$html .= '<th style="padding:8px 12px;text-align:left;">' . esc_html__( 'Test', 'as-camp-availability-integration' ) . '</th>';
		$html .= '<th style="padding:8px 12px;text-align:left;">' . esc_html__( 'Ergebnis', 'as-camp-availability-integration' ) . '</th>';
		$html .= '</tr></thead><tbody>';

		foreach ( $this->test_results as $r ) {
			if ( 'pass' === $r['status'] ) {
				$icon  = '<i class="fas fa-check-circle" style="color:#10b981;"></i>';
				$bg    = '#fff';
			} elseif ( 'fail' === $r['status'] ) {
				$icon  = '<i class="fas fa-times-circle" style="color:#ef4444;"></i>';
				$bg    = 'rgba(239,68,68,0.05)';
			} else {
				$icon  = '<i class="fas fa-minus-circle" style="color:#f59e0b;"></i>';
				$bg    = 'rgba(245,158,11,0.05)';
			}

			$html .= '<tr style="background:' . $bg . ';border-bottom:1px solid #eee;">';
			$html .= '<td style="padding:8px 12px;text-align:center;">' . $icon . '</td>';
			$html .= '<td style="padding:8px 12px;font-weight:600;">' . esc_html( $r['name'] ) . '</td>';
			$html .= '<td style="padding:8px 12px;color:#555;">' . esc_html( $r['message'] );

			// Show detail only for failures.
			if ( 'fail' === $r['status'] && ! empty( $r['detail'] ) ) {
				$html .= '<br><code style="font-size:11px;color:#991b1b;">' . esc_html( $r['detail'] ) . '</code>';
			}

			$html .= '</td></tr>';
		}

		$html .= '</tbody></table>';

		return $html;
	}
}
