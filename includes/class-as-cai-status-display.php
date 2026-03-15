<?php
/**
 * Status Display Component — Verfügbarkeits-Anzeige (v1.3.77).
 *
 * Dual-Source Strategy:
 * - Auditorium (Stachethemes): Seat Plan JSON + _taken_seat Meta
 * - Simple (WooCommerce): get_stock_quantity() + Order-Counting
 *
 * WICHTIG: Auditorium_Product::managing_stock() gibt IMMER false zurück,
 * get_stock_quantity() gibt IMMER 0 zurück — WC Stock ist für Auditorium
 * Produkte NICHT nutzbar.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.59
 * @updated 1.3.77
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AS_CAI_Status_Display {

	/** @var AS_CAI_Status_Display|null */
	private static $instance = null;

	/** @var array Unterstützte Produkttypen */
	private static $supported_types = array( 'auditorium', 'simple' );

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->init_hooks();
	}

	private function init_hooks() {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'maybe_render_status_box' ), 4 );
		add_action( 'wp_ajax_as_cai_get_status', array( $this, 'ajax_get_status' ) );
		add_action( 'wp_ajax_nopriv_as_cai_get_status', array( $this, 'ajax_get_status' ) );
		add_action( 'wp_ajax_as_cai_register_notification', array( $this, 'ajax_register_notification' ) );
		add_action( 'wp_ajax_nopriv_as_cai_register_notification', array( $this, 'ajax_register_notification' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'check_and_notify_on_cancellation' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'check_and_notify_on_cancellation' ), 10, 1 );

		// Debug endpoint (admin only).
		add_action( 'wp_ajax_as_cai_debug_status', array( $this, 'ajax_debug_status' ) );
	}

	/**
	 * Check if a product type is supported.
	 */
	private static function is_supported_type( $product ) {
		return $product && in_array( $product->get_type(), self::$supported_types, true );
	}

	/**
	 * Get the unit label for a product type.
	 */
	private static function get_unit_label( $product ) {
		return 'auditorium' === $product->get_type() ? 'Parzellen' : 'Einheiten';
	}

	// ─────────────────────────────────────────────
	// Assets
	// ─────────────────────────────────────────────

	public function enqueue_assets() {
		if ( ! is_product() ) {
			return;
		}

		global $product;
		if ( ! $product || ! is_object( $product ) ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! self::is_supported_type( $product ) ) {
			return;
		}

		wp_enqueue_style(
			'as-cai-status-display',
			AS_CAI_PLUGIN_URL . 'assets/css/as-cai-status-display.css',
			array(),
			AS_CAI_VERSION
		);

		wp_enqueue_script(
			'as-cai-status-live-update',
			AS_CAI_PLUGIN_URL . 'assets/js/as-cai-status-live-update.js',
			array( 'jquery' ),
			AS_CAI_VERSION,
			true
		);

		wp_localize_script(
			'as-cai-status-live-update',
			'as_cai_vars',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'as_cai_status_nonce' ),
			)
		);
	}

	// ─────────────────────────────────────────────
	// Rendering
	// ─────────────────────────────────────────────

	public function maybe_render_status_box() {
		if ( ! is_product() ) {
			return;
		}

		global $product;
		if ( ! $product || ! is_object( $product ) ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! self::is_supported_type( $product ) ) {
			return;
		}

		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		// Kein Countdown-Check — Box wird IMMER gerendert.
		$this->render_status_box( $product->get_id() );
	}

	public function render_status_box( $product_id ) {
		$data = self::get_detailed_availability_status( $product_id );
		if ( ! $data ) {
			return;
		}

		$status = $data['status'];
		$config = self::get_status_config( $status, $data['label'] );
		$label  = $data['label'];
		?>
		<div class="as-cai-status-box status-<?php echo esc_attr( $status ); ?>"
			 data-product-id="<?php echo esc_attr( $product_id ); ?>"
			 data-refresh-interval="15000">

			<div class="status-header">
				<span class="status-icon"><?php echo esc_html( $config['icon'] ); ?></span>
				<h3 class="status-title"><?php echo esc_html( $config['title'] ); ?></h3>
			</div>

			<div class="status-details">
				<div class="availability-main">
					<strong><?php echo esc_html( $data['available'] ); ?> von <?php echo esc_html( $data['total'] ); ?> <?php echo esc_html( $label ); ?></strong>
					<?php echo esc_html( $config['subtitle'] ); ?>
				</div>

				<div class="availability-breakdown">
					<?php if ( $data['reserved'] > 0 ) : ?>
						<span class="reserved-badge">
							&#128274; <?php echo esc_html( $data['reserved'] ); ?> reserviert
						</span>
					<?php endif; ?>
					<span class="sold-badge">
						&#10003; <?php echo esc_html( $data['sold'] ); ?> verkauft
					</span>
				</div>

				<div class="availability-progress">
					<div class="progress-bar">
						<div class="progress-available"
							 style="width: <?php echo esc_attr( $data['percent_free'] ); ?>%"></div>
						<?php
						$reserved_pct = ( $data['total'] > 0 ) ? ( $data['reserved'] / $data['total'] ) * 100 : 0;
						?>
						<div class="progress-reserved"
							 style="width: <?php echo esc_attr( $reserved_pct ); ?>%"></div>
					</div>
					<div class="progress-labels">
						<span class="label-available"><?php echo esc_html( round( $data['percent_free'] ) ); ?>% verfügbar</span>
						<span class="label-sold"><?php echo esc_html( $data['sold'] ); ?> verkauft</span>
					</div>
				</div>

				<?php if ( in_array( $status, array( 'limited', 'critical' ), true ) ) : ?>
					<div class="urgency-badge">
						<span class="pulse-dot"></span>
						<strong><?php echo esc_html( $config['urgency_text'] ); ?></strong>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( 'sold_out' === $status || 'reserved_full' === $status ) : ?>
				<div class="status-action">
					<button class="as-cai-waitlist-button" type="button"
							data-product-id="<?php echo esc_attr( $product_id ); ?>">
						Auf Warteliste setzen
					</button>
				</div>
			<?php endif; ?>

			<div class="status-meta">
				<small>
					Aktualisiert: <span class="update-time"><?php echo esc_html( wp_date( 'H:i:s' ) ); ?></span>
					<span class="auto-refresh-indicator">&#9679; Auto-Refresh aktiv</span>
				</small>
			</div>
		</div>
		<?php
	}

	private static function get_status_config( $status, $label = 'Parzellen' ) {
		$configs = array(
			'available' => array(
				'icon'         => '✓',
				'title'        => 'Sofort buchbar',
				'subtitle'     => 'verfügbar',
				'urgency_text' => '',
			),
			'limited' => array(
				'icon'         => '⚠',
				'title'        => 'Nur noch wenige ' . $label,
				'subtitle'     => 'verfügbar',
				'urgency_text' => 'Hohe Nachfrage',
			),
			'critical' => array(
				'icon'         => '⚡',
				'title'        => 'Letzte ' . $label . '!',
				'subtitle'     => 'verfügbar',
				'urgency_text' => 'JETZT BUCHEN!',
			),
			'reserved_full' => array(
				'icon'         => '🔒',
				'title'        => 'Alle ' . $label . ' reserviert',
				'subtitle'     => 'in Warenkörben',
				'urgency_text' => '',
			),
			'sold_out' => array(
				'icon'         => '✕',
				'title'        => 'Ausgebucht',
				'subtitle'     => 'verkauft',
				'urgency_text' => '',
			),
		);

		return isset( $configs[ $status ] ) ? $configs[ $status ] : $configs['available'];
	}

	// ─────────────────────────────────────────────
	// Dual-Source Availability Status
	// ─────────────────────────────────────────────

	/**
	 * Get availability data — Dispatcher for product type.
	 *
	 * @param int $product_id Product ID.
	 * @return array|null Status data or null if not applicable.
	 */
	public static function get_detailed_availability_status( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}

		$type = $product->get_type();

		if ( 'auditorium' === $type ) {
			return self::get_auditorium_status( $product );
		}

		if ( 'simple' === $type && $product->managing_stock() ) {
			return self::get_simple_product_status( $product );
		}

		return null;
	}

	/**
	 * Auditorium-Produkte: Stachethemes Seat Plan als Datenquelle.
	 *
	 * Liest den Seat Plan JSON und zählt Seats nach Status.
	 * get_taken_seats() enthält bereits Slot-Reservation-Transients.
	 *
	 * @param WC_Product $product Auditorium product.
	 * @return array|null
	 */
	private static function get_auditorium_status( $product ) {
		// Seat Plan JSON lesen.
		if ( ! method_exists( $product, 'get_seat_plan_data' ) ) {
			return null;
		}

		$seat_plan = $product->get_seat_plan_data( 'object' );
		if ( ! $seat_plan || ! isset( $seat_plan->objects ) || ! is_array( $seat_plan->objects ) ) {
			return null;
		}

		// Taken Seats: _taken_seat Meta + Slot Reservation Transients (via Filter).
		$taken_seats = array();
		if ( method_exists( $product, 'get_taken_seats' ) ) {
			$taken_seats = $product->get_taken_seats();
			if ( ! is_array( $taken_seats ) ) {
				$taken_seats = array();
			}
		}

		$total       = 0;
		$available   = 0;
		$sold        = 0;
		$unavailable = 0;

		foreach ( $seat_plan->objects as $obj ) {
			if ( ! isset( $obj->type ) || 'seat' !== $obj->type ) {
				continue;
			}
			if ( empty( $obj->seatId ) ) {
				continue;
			}

			$total++;
			$status   = isset( $obj->status ) ? $obj->status : 'available';
			$is_taken = in_array( $obj->seatId, $taken_seats, true );

			if ( $is_taken || 'sold-out' === $status ) {
				$sold++;
			} elseif ( 'unavailable' === $status ) {
				$unavailable++;
			} else {
				// 'available' und 'on-site' zählen als verfügbar.
				$available++;
			}
		}

		if ( $total <= 0 ) {
			return null;
		}

		// Reservierte Seats: Nur unser eigenes System (Stachethemes-Reservierungen
		// sind bereits in taken_seats via Filter enthalten).
		$reserved = self::count_own_reserved_seats( $product->get_id() );

		return self::build_status_result( $total, $available, $sold, $reserved, 'Parzellen' );
	}

	/**
	 * Simple-Produkte: WooCommerce Stock als Datenquelle.
	 *
	 * @param WC_Product $product Simple product with stock management.
	 * @return array|null
	 */
	private static function get_simple_product_status( $product ) {
		$product_id = $product->get_id();
		$stock_qty  = $product->get_stock_quantity();
		$available  = ( null !== $stock_qty ) ? max( 0, (int) $stock_qty ) : 0;

		// Verkaufte Einheiten aus WC Orders zählen.
		$sold = self::count_sold_units_from_orders( $product_id );

		// Total = Verfügbar + Verkauft.
		$total = $available + $sold;

		if ( $total <= 0 ) {
			// Kein Total ermittelbar — nur verfügbare Menge anzeigen.
			if ( $available <= 0 ) {
				return null;
			}
			$total = $available;
		}

		// Reservierte Einheiten aus unserem Reservierungssystem.
		$reserved = self::count_own_reserved_seats( $product_id );

		return self::build_status_result( $total, $available, $sold, $reserved, 'Einheiten' );
	}

	/**
	 * Build the standardized status result array.
	 */
	private static function build_status_result( $total, $available, $sold, $reserved, $label ) {
		$bookable     = max( 0, $available - $reserved );
		$percent_free = ( $total > 0 ) ? ( $bookable / $total ) * 100 : 0;

		// Status bestimmen.
		if ( $available <= 0 ) {
			$status = 'sold_out';
		} elseif ( $bookable <= 0 && $reserved > 0 ) {
			$status = 'reserved_full';
		} elseif ( $percent_free > 20 ) {
			$status = 'available';
		} elseif ( $percent_free > 5 ) {
			$status = 'limited';
		} else {
			$status = 'critical';
		}

		return array(
			'status'       => $status,
			'total'        => $total,
			'available'    => $available,
			'reserved'     => $reserved,
			'sold'         => $sold,
			'percent_free' => round( $percent_free, 1 ),
			'label'        => $label,
			'last_updated' => time(),
		);
	}

	// ─────────────────────────────────────────────
	// Counting Helpers
	// ─────────────────────────────────────────────

	/**
	 * Count sold units from WC orders (for Simple products only).
	 *
	 * Uses Stachethemes' custom order query filter for auditorium products,
	 * but this method is only called for Simple products.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private static function count_sold_units_from_orders( $product_id ) {
		$valid_statuses = array( 'wc-processing', 'wc-completed', 'wc-on-hold' );

		$orders = wc_get_orders( array(
			'limit'  => -1,
			'status' => $valid_statuses,
			'return' => 'ids',
		) );

		$sold_count = 0;

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_product_id() === (int) $product_id ) {
					$sold_count += max( 1, $item->get_quantity() );
				}
			}
		}

		return $sold_count;
	}

	/**
	 * Count reserved seats/units from our own reservation system only.
	 *
	 * Stachethemes Slot Reservation transients are NOT counted here
	 * because get_taken_seats() already includes them.
	 *
	 * @param int $product_id Product ID.
	 * @return int
	 */
	private static function count_own_reserved_seats( $product_id ) {
		if ( class_exists( 'AS_CAI_Reservation_DB' ) ) {
			$db = AS_CAI_Reservation_DB::instance();
			return max( 0, (int) $db->get_reserved_stock_for_product( $product_id ) );
		}
		return 0;
	}

	// ─────────────────────────────────────────────
	// AJAX Handlers
	// ─────────────────────────────────────────────

	public function ajax_get_status() {
		check_ajax_referer( 'as_cai_status_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! $product_id ) {
			wp_send_json_error( array( 'message' => 'Ungültige Produkt-ID' ) );
		}

		$data = self::get_detailed_availability_status( $product_id );
		if ( ! $data ) {
			wp_send_json_error( array( 'message' => 'Keine Status-Daten verfügbar' ) );
		}

		wp_send_json_success( $data );
	}

	public function ajax_register_notification() {
		check_ajax_referer( 'as_cai_status_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! $product_id || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => 'Ungültige Daten' ) );
		}

		// Rate limiting: max 3 per IP per hour.
		$rate_key = 'as_cai_notify_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 3 ) {
			wp_send_json_error( array( 'message' => 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.' ) );
		}
		set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );

		global $wpdb;
		$table_name = $wpdb->prefix . 'as_cai_notifications';

		self::maybe_create_notifications_table();

		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE product_id = %d AND email = %s AND status = 'pending'",
			$product_id,
			$email
		) );

		if ( $exists ) {
			wp_send_json_success( array( 'message' => 'Sie sind bereits auf der Warteliste.' ) );
			return;
		}

		$wpdb->insert(
			$table_name,
			array(
				'product_id' => $product_id,
				'email'      => $email,
				'created_at' => current_time( 'mysql' ),
				'status'     => 'pending',
			),
			array( '%d', '%s', '%s', '%s' )
		);

		$product      = wc_get_product( $product_id );
		$unit_label   = $product ? self::get_unit_label( $product ) : 'Plätze';
		wp_send_json_success( array( 'message' => "Sie werden benachrichtigt, sobald {$unit_label} verfügbar sind." ) );
	}

	public static function maybe_create_notifications_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'as_cai_notifications';
		$charset_collate = $wpdb->get_charset_collate();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name ) {
			return;
		}

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			product_id bigint(20) unsigned NOT NULL,
			email varchar(255) NOT NULL,
			created_at datetime NOT NULL,
			sent_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			PRIMARY KEY (id),
			KEY product_id (product_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	// ─────────────────────────────────────────────
	// Notifications
	// ─────────────────────────────────────────────

	public function check_and_notify_on_cancellation( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$product    = wc_get_product( $product_id );

			if ( ! self::is_supported_type( $product ) ) {
				continue;
			}

			$data = self::get_detailed_availability_status( $product_id );
			if ( $data && $data['available'] > 0 ) {
				self::send_availability_notifications( $product_id );
			}
		}
	}

	public static function send_availability_notifications( $product_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'as_cai_notifications';

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
			return;
		}

		$notifications = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE product_id = %d AND status = 'pending'",
			$product_id
		) );

		if ( empty( $notifications ) ) {
			return;
		}

		$product      = wc_get_product( $product_id );
		$product_name = $product ? $product->get_name() : 'Unterkunft';
		$unit_label   = $product ? self::get_unit_label( $product ) : 'Plätze';

		foreach ( $notifications as $notification ) {
			wp_mail(
				$notification->email,
				"Ayonto Camp: {$unit_label} wieder verfügbar!",
				sprintf(
					"Gute Nachrichten!\n\nEs sind wieder %s verfügbar für \"%s\".\n\nJetzt buchen: %s\n\n---\nayonto",
					$unit_label,
					$product_name,
					get_permalink( $product_id )
				)
			);

			$wpdb->update(
				$table_name,
				array( 'status' => 'sent', 'sent_at' => current_time( 'mysql' ) ),
				array( 'id' => $notification->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}

	// ─────────────────────────────────────────────
	// Debug Endpoint
	// ─────────────────────────────────────────────

	public function ajax_debug_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Nicht autorisiert' );
		}

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;

		if ( ! $product_id ) {
			// Find first auditorium product, then simple.
			$products = wc_get_products( array(
				'type'   => 'auditorium',
				'limit'  => 1,
				'return' => 'ids',
			) );
			if ( empty( $products ) ) {
				$products = wc_get_products( array(
					'type'   => 'simple',
					'limit'  => 1,
					'return' => 'ids',
				) );
			}
			$product_id = ! empty( $products ) ? $products[0] : 0;
		}

		if ( ! $product_id ) {
			wp_send_json_error( 'Kein unterstütztes Produkt gefunden' );
		}

		$product = wc_get_product( $product_id );
		$type    = $product ? $product->get_type() : 'NOT_FOUND';

		$debug = array(
			'plugin_version' => AS_CAI_VERSION,
			'product_id'     => $product_id,
			'product_type'   => $type,
			'product_name'   => $product ? $product->get_name() : 'N/A',
			'data_source'    => 'auditorium' === $type ? 'stachethemes_seat_plan' : 'woocommerce_stock',
		);

		// Typ-spezifische Debug-Daten.
		if ( 'auditorium' === $type && $product ) {
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
		} elseif ( $product ) {
			$debug['managing_stock']  = $product->managing_stock();
			$debug['stock_quantity']  = $product->get_stock_quantity();
			$debug['stock_status']    = $product->get_stock_status();
		}

		$debug['own_reserved_seats'] = self::count_own_reserved_seats( $product_id );
		$debug['computed_status']    = self::get_detailed_availability_status( $product_id );

		wp_send_json_success( $debug );
	}
}
