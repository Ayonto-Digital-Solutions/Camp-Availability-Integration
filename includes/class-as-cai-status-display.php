<?php
/**
 * Status Display Component — Verfügbarkeits-Anzeige (v1.3.79).
 *
 * Uses WooCommerce stock as the SINGLE source of truth.
 * Stachethemes syncs stock automatically (decrements on order,
 * increments on refund/cancel), so stock_quantity = available seats.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.59
 * @updated 1.3.79
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AS_CAI_Status_Display {

	/** @var AS_CAI_Status_Display|null */
	private static $instance = null;

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
	 * Enqueue status display CSS and JS on product pages.
	 */
	public function enqueue_assets() {
		if ( ! is_product() ) {
			return;
		}

		global $product;
		if ( ! $product || ! is_object( $product ) ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product || 'auditorium' !== $product->get_type() ) {
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

	/**
	 * Conditionally render the status box.
	 */
	public function maybe_render_status_box() {
		if ( ! is_product() ) {
			return;
		}

		global $product;
		if ( ! $product || ! is_object( $product ) ) {
			$product = wc_get_product( get_the_ID() );
		}
		if ( ! $product || 'auditorium' !== $product->get_type() ) {
			return;
		}

		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		// Only render when product is available (countdown expired).
		$availability = AS_CAI_Availability_Check::get_product_availability( $product->get_id() );
		if ( ! $availability['is_available'] ) {
			return;
		}

		$this->render_status_box( $product->get_id() );
	}

	/**
	 * Get availability data — WooCommerce Stock is the ONLY source of truth.
	 *
	 * available = get_stock_quantity()  — WC manages this, Stachethemes syncs it
	 * sold      = count from WC orders with valid statuses only
	 * total     = available + sold
	 *
	 * @param int $product_id Product ID.
	 * @return array|null Status data or null if not applicable.
	 */
	public static function get_detailed_availability_status( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'auditorium' !== $product->get_type() ) {
			return null;
		}

		// ── WooCommerce Stock as primary source ──
		$available  = 0;
		$sold_seats = self::count_sold_seats_from_orders( $product_id );

		if ( $product->managing_stock() ) {
			$stock_qty = $product->get_stock_quantity();
			$available = ( null !== $stock_qty ) ? max( 0, (int) $stock_qty ) : 0;
		}

		// If WC stock not available, try Stachethemes get_taken_seats as fallback for sold count.
		if ( 0 === $sold_seats && method_exists( $product, 'get_taken_seats' ) ) {
			$taken      = $product->get_taken_seats();
			$sold_seats = is_array( $taken ) ? count( $taken ) : 0;
		}

		$total = $available + $sold_seats;

		// If we have no data at all, don't display.
		if ( $total <= 0 ) {
			return null;
		}

		// Count reserved seats (in carts).
		$reserved = self::count_reserved_seats( $product_id );

		// Available for booking = stock minus reserved-in-carts.
		$bookable     = max( 0, $available - $reserved );
		$percent_free = ( $bookable / $total ) * 100;

		// Determine status.
		if ( $bookable <= 0 && $available <= 0 ) {
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
			'sold'         => $sold_seats,
			'percent_free' => round( $percent_free, 1 ),
			'last_updated' => time(),
		);
	}

	/**
	 * Count sold seats from WooCommerce orders (valid statuses only).
	 *
	 * Only counts: processing, completed, on-hold, pending.
	 * Does NOT count: refunded, cancelled, failed, trash.
	 *
	 * Uses seat ID deduplication to prevent double-counting
	 * when the same seat appears in multiple orders.
	 *
	 * @param int $product_id Product ID.
	 * @return int Number of sold seats.
	 */
	private static function count_sold_seats_from_orders( $product_id ) {
		$valid_statuses = array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' );

		$orders = wc_get_orders( array(
			'limit'  => -1,
			'status' => $valid_statuses,
			'return' => 'ids',
		) );

		$sold_count    = 0;
		$counted_seats = array();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				if ( (int) $item->get_product_id() !== (int) $product_id ) {
					continue;
				}

				// Try to get specific seat IDs to avoid double-counting.
				$seat_meta = $item->get_meta( '_stachethemes_seat_planner_data', true );
				if ( ! empty( $seat_meta ) ) {
					$seat_ids = self::extract_seat_ids( $seat_meta );
					foreach ( $seat_ids as $seat_id ) {
						if ( ! in_array( $seat_id, $counted_seats, true ) ) {
							$counted_seats[] = $seat_id;
							$sold_count++;
						}
					}
				} else {
					$sold_count += max( 1, $item->get_quantity() );
				}
			}
		}

		return $sold_count;
	}

	/**
	 * Count reserved seats (in carts / Stachethemes transients).
	 *
	 * @param int $product_id Product ID.
	 * @return int Number of reserved seats.
	 */
	private static function count_reserved_seats( $product_id ) {
		$reserved = 0;

		// Our reservation system.
		if ( class_exists( 'AS_CAI_Reservation_DB' ) ) {
			$db = AS_CAI_Reservation_DB::instance();
			$reserved = max( $reserved, (int) $db->get_reserved_stock_for_product( $product_id ) );
		}

		// Stachethemes transient-based reservations.
		global $wpdb;
		$stache_count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			 AND option_name NOT LIKE %s",
			$wpdb->esc_like( '_transient_stachesepl_reserved_seat_' . $product_id . '_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_stachesepl_reserved_seat_' . $product_id . '_' ) . '%'
		) );

		return max( $reserved, $stache_count );
	}

	/**
	 * Extract seat IDs from Stachethemes meta data.
	 *
	 * @param mixed $seat_meta Seat meta data.
	 * @return array Array of seat ID strings.
	 */
	private static function extract_seat_ids( $seat_meta ) {
		$ids = array();

		if ( is_string( $seat_meta ) ) {
			$decoded = json_decode( $seat_meta, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$seat_meta = $decoded;
			} else {
				return array( $seat_meta );
			}
		}

		if ( is_object( $seat_meta ) ) {
			$seat_meta = (array) $seat_meta;
		}

		// Single seat.
		if ( is_array( $seat_meta ) ) {
			foreach ( array( 'seatId', 'label', 'seat' ) as $key ) {
				if ( isset( $seat_meta[ $key ] ) ) {
					return array( (string) $seat_meta[ $key ] );
				}
			}
		}

		// Array of seats.
		if ( is_array( $seat_meta ) ) {
			foreach ( $seat_meta as $entry ) {
				if ( is_object( $entry ) ) {
					$entry = (array) $entry;
				}
				if ( is_array( $entry ) ) {
					foreach ( array( 'seatId', 'label', 'seat' ) as $key ) {
						if ( isset( $entry[ $key ] ) ) {
							$ids[] = (string) $entry[ $key ];
							break;
						}
					}
				} elseif ( is_string( $entry ) || is_numeric( $entry ) ) {
					$ids[] = (string) $entry;
				}
			}
		}

		return $ids;
	}

	/**
	 * Render the status box HTML — clean, minimal, correct.
	 *
	 * @param int $product_id Product ID.
	 */
	public function render_status_box( $product_id ) {
		$data = self::get_detailed_availability_status( $product_id );
		if ( ! $data ) {
			return;
		}

		$status = $data['status'];
		$config = self::get_status_config( $status );
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
					<strong><?php echo esc_html( $data['available'] ); ?> von <?php echo esc_html( $data['total'] ); ?> Parzellen</strong>
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

	/**
	 * Get status configuration.
	 */
	private static function get_status_config( $status ) {
		$configs = array(
			'available' => array(
				'icon'         => '✓',
				'title'        => 'Sofort buchbar',
				'subtitle'     => 'verfügbar',
				'urgency_text' => '',
			),
			'limited' => array(
				'icon'         => '⚠',
				'title'        => 'Nur noch wenige Parzellen',
				'subtitle'     => 'verfügbar',
				'urgency_text' => 'Hohe Nachfrage',
			),
			'critical' => array(
				'icon'         => '⚡',
				'title'        => 'Letzte Parzellen!',
				'subtitle'     => 'verfügbar',
				'urgency_text' => 'JETZT BUCHEN!',
			),
			'reserved_full' => array(
				'icon'         => '🔒',
				'title'        => 'Alle Parzellen reserviert',
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

	/**
	 * AJAX handler: Get current status data.
	 */
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

	/**
	 * AJAX handler: Register email notification.
	 */
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

		// Check duplicate.
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

		wp_send_json_success( array( 'message' => 'Sie werden benachrichtigt, sobald Parzellen verfügbar sind.' ) );
	}

	/**
	 * Create notifications table if it doesn't exist.
	 */
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

	/**
	 * Send notifications when seats become available.
	 */
	public function check_and_notify_on_cancellation( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$product    = wc_get_product( $product_id );

			if ( ! $product || 'auditorium' !== $product->get_type() ) {
				continue;
			}

			$data = self::get_detailed_availability_status( $product_id );
			if ( $data && $data['available'] > 0 ) {
				self::send_availability_notifications( $product_id );
			}
		}
	}

	/**
	 * Send pending notifications.
	 */
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
		$product_name = $product ? $product->get_name() : 'Camp-Parzelle';

		foreach ( $notifications as $notification ) {
			wp_mail(
				$notification->email,
				'Ayonto Camp: Parzellen wieder verfügbar!',
				sprintf(
					"Gute Nachrichten!\n\nEs sind wieder Parzellen verfügbar für \"%s\".\n\nJetzt buchen: %s\n\n---\nayonto",
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

	/**
	 * Debug endpoint — admin only. Shows all raw data for diagnostics.
	 *
	 * Usage: /wp-admin/admin-ajax.php?action=as_cai_debug_status&product_id=XXX
	 */
	public function ajax_debug_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Nicht autorisiert' );
		}

		$product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
		if ( ! $product_id ) {
			// Find first auditorium product.
			$products = wc_get_products( array(
				'type'   => 'auditorium',
				'limit'  => 1,
				'return' => 'ids',
			) );
			$product_id = ! empty( $products ) ? $products[0] : 0;
		}

		if ( ! $product_id ) {
			wp_send_json_error( 'Kein Auditorium-Produkt gefunden' );
		}

		$product = wc_get_product( $product_id );
		$debug   = array(
			'product_id'      => $product_id,
			'product_type'    => $product ? $product->get_type() : 'NOT_FOUND',
			'product_name'    => $product ? $product->get_name() : 'N/A',
			'managing_stock'  => $product ? $product->managing_stock() : false,
			'stock_quantity'  => $product ? $product->get_stock_quantity() : null,
			'stock_status'    => $product ? $product->get_stock_status() : 'N/A',
			'has_get_taken'   => $product && method_exists( $product, 'get_taken_seats' ),
			'taken_seats'     => null,
			'sold_from_orders' => self::count_sold_seats_from_orders( $product_id ),
			'reserved_seats'  => self::count_reserved_seats( $product_id ),
			'computed_status' => self::get_detailed_availability_status( $product_id ),
		);

		if ( $product && method_exists( $product, 'get_taken_seats' ) ) {
			$taken = $product->get_taken_seats();
			$debug['taken_seats'] = is_array( $taken ) ? count( $taken ) : $taken;
		}

		// Check availability window.
		$debug['availability_check'] = AS_CAI_Availability_Check::get_product_availability( $product_id );

		wp_send_json_success( $debug );
	}
}
