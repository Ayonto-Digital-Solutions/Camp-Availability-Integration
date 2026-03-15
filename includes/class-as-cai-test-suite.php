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
