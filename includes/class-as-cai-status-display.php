<?php
/**
 * Status Display Component — Transparente Echtzeit-Status-Anzeigen.
 *
 * Renders detailed availability status boxes on product pages with
 * five status levels: available, limited, critical, reserved_full, sold_out.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.3.59
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AS_CAI_Status_Display {

	/**
	 * Instance.
	 *
	 * @var AS_CAI_Status_Display|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return AS_CAI_Status_Display
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->init_hooks();
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Render status box on single product pages.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'maybe_render_status_box' ), 4 );

		// AJAX endpoint for live status updates.
		add_action( 'wp_ajax_as_cai_get_status', array( $this, 'ajax_get_status' ) );
		add_action( 'wp_ajax_nopriv_as_cai_get_status', array( $this, 'ajax_get_status' ) );

		// AJAX endpoint for notification registration.
		add_action( 'wp_ajax_as_cai_register_notification', array( $this, 'ajax_register_notification' ) );
		add_action( 'wp_ajax_nopriv_as_cai_register_notification', array( $this, 'ajax_register_notification' ) );

		// Enqueue status display assets.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Send notifications when seats become available.
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'check_and_notify_on_cancellation' ), 10, 1 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'check_and_notify_on_cancellation' ), 10, 1 );
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
	 * Conditionally render the status box on product pages.
	 *
	 * The status box is only rendered when the product is currently available
	 * (i.e. the countdown has expired). This is a server-side check that
	 * cannot be bypassed via browser DevTools.
	 *
	 * @since 1.3.62 Only render when product availability window is active.
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

		// Prevent duplicate rendering.
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		// Server-side availability check: only render when the product is
		// currently available (countdown expired). This cannot be manipulated
		// by the user in the browser.
		$availability = AS_CAI_Availability_Check::get_product_availability( $product->get_id() );
		if ( ! $availability['is_available'] ) {
			return;
		}

		$this->render_status_box( $product->get_id() );
	}

	/**
	 * Get detailed availability status with all reservation data.
	 *
	 * @param int $product_id Product ID.
	 * @return array|null Status data or null if not applicable.
	 */
	public static function get_detailed_availability_status( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'auditorium' !== $product->get_type() ) {
			return null;
		}

		// ── Use WooCommerce stock as source of truth ──
		// Stachethemes syncs stock automatically: it decrements on order
		// and increments on refund/cancel. stock_quantity = available seats.
		$stock_qty = $product->get_stock_quantity();

		// If stock management is enabled and we have a stock value, use it directly.
		if ( $product->managing_stock() && null !== $stock_qty ) {
			$available   = max( 0, $stock_qty );
			// Count sold via orders (valid statuses only).
			$sold_seats  = self::count_sold_seats_accurate( $product_id );
			$total_seats = $available + $sold_seats;

			// Sanity: if sold count seems off, try Stachethemes as fallback.
			if ( 0 === $sold_seats && method_exists( $product, 'get_taken_seats' ) ) {
				$taken = $product->get_taken_seats();
				$sold_seats = is_array( $taken ) ? count( $taken ) : 0;
				$sold_seats = min( $sold_seats, max( 0, $total_seats ) );
			}
		} else {
			// No stock management — try to count from orders.
			$total_seats = 0;
			$sold_seats  = self::count_sold_seats_accurate( $product_id );
			if ( 0 === $sold_seats && method_exists( $product, 'get_taken_seats' ) ) {
				$taken = $product->get_taken_seats();
				$sold_seats = is_array( $taken ) ? count( $taken ) : 0;
			}
			$available = max( 0, $total_seats - $sold_seats );
		}

		// Count reserved seats (in carts) from our reservation system.
		$reserved_count = 0;
		if ( class_exists( 'AS_CAI_Reservation_DB' ) ) {
			$db = AS_CAI_Reservation_DB::instance();
			$reserved_count = $db->get_reserved_stock_for_product( $product_id );
		}

		// Also count Stachethemes transient-based reservations.
		$stache_reserved = self::count_stachethemes_reserved_seats( $product_id );
		$reserved_count = max( $reserved_count, $stache_reserved );

		// Subtract reservations from available (but not below 0).
		$available    = max( 0, $available - $reserved_count );
		$percent_free = ( $total_seats > 0 ) ? ( $available / $total_seats ) * 100 : 0;

		// Determine status level.
		$status = 'sold_out';
		if ( $available > 0 ) {
			if ( $percent_free > 20 ) {
				$status = 'available';
			} elseif ( $percent_free > 5 ) {
				$status = 'limited';
			} else {
				$status = 'critical';
			}
		} elseif ( $reserved_count > 0 && $sold_seats < $total_seats ) {
			$status = 'reserved_full';
		}

		// Get next reservation expiry.
		$next_free_in = self::get_next_reservation_expiry( $product_id );

		return array(
			'status'       => $status,
			'total'        => $total_seats,
			'available'    => $available,
			'reserved'     => $reserved_count,
			'sold'         => $sold_seats,
			'percent_free' => round( $percent_free, 1 ),
			'next_free_in' => $next_free_in,
			'last_updated' => time(),
		);
	}

	/**
	 * Count Stachethemes transient-based seat reservations.
	 *
	 * @param int $product_id Product ID.
	 * @return int Number of reserved seats.
	 */
	/**
	 * Count seats directly from Stachethemes Seat Plan data.
	 *
	 * This is the primary source of truth — it reads the same data that
	 * the seat map uses to render available/sold seats. Refunded orders
	 * are automatically excluded because Stachethemes releases the seat
	 * back to "available" on refund.
	 *
	 * @since 1.3.75
	 * @param WC_Product $product The auditorium product.
	 * @return array|null Array with total/sold/available counts, or null if unavailable.
	 */
	private static function count_from_seat_plan( $product ) {
		if ( ! method_exists( $product, 'get_seat_plan_data' ) ) {
			return null;
		}

		$seat_data = $product->get_seat_plan_data( 'object' );
		if ( ! $seat_data || ! isset( $seat_data->objects ) || ! is_array( $seat_data->objects ) ) {
			return null;
		}

		$total     = 0;
		$sold      = 0;
		$available = 0;

		// Statuses that count as "sold" / "taken" in Stachethemes.
		$sold_statuses = array( 'sold-out', 'sold', 'taken', 'booked' );

		// Statuses that count as permanently unavailable (not for sale).
		$excluded_statuses = array( 'unavailable', 'disabled' );

		foreach ( $seat_data->objects as $obj ) {
			// Only count seat-type objects (not decorations, labels, etc.).
			if ( ! isset( $obj->type ) || 'seat' !== $obj->type ) {
				continue;
			}

			$seat_status = isset( $obj->status ) ? strtolower( (string) $obj->status ) : 'available';

			// Skip permanently unavailable seats.
			if ( in_array( $seat_status, $excluded_statuses, true ) ) {
				continue;
			}

			$total++;

			if ( in_array( $seat_status, $sold_statuses, true ) ) {
				$sold++;
			} else {
				$available++;
			}
		}

		if ( 0 === $total ) {
			return null;
		}

		return array(
			'total'     => $total,
			'sold'      => $sold,
			'available' => $available,
		);
	}

	private static function count_stachethemes_reserved_seats( $product_id ) {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			 AND option_name NOT LIKE %s",
			$wpdb->esc_like( '_transient_stachesepl_reserved_seat_' . $product_id . '_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_stachesepl_reserved_seat_' . $product_id . '_' ) . '%'
		) );

		return (int) $count;
	}

	/**
	 * Count sold seats accurately from WooCommerce orders.
	 *
	 * Unlike Stachethemes' get_taken_seats(), this method only counts orders
	 * with valid (non-refunded, non-cancelled, non-failed) statuses.
	 * This prevents the "38 sold out of 37" bug when orders are refunded.
	 *
	 * @since 1.3.74
	 * @param int $product_id Product ID.
	 * @return int Number of actually sold seats.
	 */
	private static function count_sold_seats_accurate( $product_id ) {
		// Valid statuses where seats are actually taken.
		$valid_statuses = array( 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-pending' );

		$orders = wc_get_orders( array(
			'limit'   => -1,
			'status'  => $valid_statuses,
			'return'  => 'ids',
		) );

		$sold_count = 0;
		$counted_seats = array();

		foreach ( $orders as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				$item_product_id = $item->get_product_id();
				if ( (int) $item_product_id !== (int) $product_id ) {
					continue;
				}

				// Try to get specific seat IDs to avoid double-counting.
				$seat_meta = $item->get_meta( '_stachethemes_seat_planner_data', true );
				if ( ! empty( $seat_meta ) ) {
					$seat_ids = self::extract_seat_ids_from_meta( $seat_meta );
					foreach ( $seat_ids as $seat_id ) {
						if ( ! in_array( $seat_id, $counted_seats, true ) ) {
							$counted_seats[] = $seat_id;
							$sold_count++;
						}
					}
				} else {
					// No seat data — count by quantity.
					$sold_count += max( 1, $item->get_quantity() );
				}
			}
		}

		return $sold_count;
	}

	/**
	 * Extract seat IDs from Stachethemes meta data.
	 *
	 * @since 1.3.74
	 * @param mixed $seat_meta Seat meta data (object, array, or JSON string).
	 * @return array Array of seat ID strings.
	 */
	private static function extract_seat_ids_from_meta( $seat_meta ) {
		$ids = array();

		// Handle JSON string.
		if ( is_string( $seat_meta ) ) {
			$decoded = json_decode( $seat_meta, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				$seat_meta = $decoded;
			} else {
				return array( $seat_meta ); // Treat the string itself as an ID.
			}
		}

		// Single object.
		if ( is_object( $seat_meta ) ) {
			$seat_meta = (array) $seat_meta;
		}

		// Single seat array.
		if ( is_array( $seat_meta ) && isset( $seat_meta['seatId'] ) ) {
			return array( (string) $seat_meta['seatId'] );
		}
		if ( is_array( $seat_meta ) && isset( $seat_meta['label'] ) ) {
			return array( (string) $seat_meta['label'] );
		}
		if ( is_array( $seat_meta ) && isset( $seat_meta['seat'] ) ) {
			return array( (string) $seat_meta['seat'] );
		}

		// Array of seats.
		if ( is_array( $seat_meta ) ) {
			foreach ( $seat_meta as $entry ) {
				if ( is_object( $entry ) ) {
					$entry = (array) $entry;
				}
				if ( is_array( $entry ) ) {
					if ( isset( $entry['seatId'] ) ) {
						$ids[] = (string) $entry['seatId'];
					} elseif ( isset( $entry['label'] ) ) {
						$ids[] = (string) $entry['label'];
					} elseif ( isset( $entry['seat'] ) ) {
						$ids[] = (string) $entry['seat'];
					}
				} elseif ( is_string( $entry ) || is_numeric( $entry ) ) {
					$ids[] = (string) $entry;
				}
			}
		}

		return $ids;
	}

	/**
	 * Get seconds until next reservation expires.
	 *
	 * @param int $product_id Product ID.
	 * @return int|null Seconds until next free, or null.
	 */
	private static function get_next_reservation_expiry( $product_id ) {
		global $wpdb;

		// Check our reservation system first.
		if ( class_exists( 'AS_CAI_Reservation_DB' ) ) {
			$db    = AS_CAI_Reservation_DB::instance();
			$table = $wpdb->prefix . 'as_cai_cart_reservations';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$next_expiry = $wpdb->get_var( $wpdb->prepare(
					"SELECT MIN(expires) FROM {$table} WHERE product_id = %d AND expires > %s",
					$product_id,
					current_time( 'mysql', true )
				) );

				if ( $next_expiry ) {
					$expiry_ts = strtotime( $next_expiry );
					$remaining = max( 0, $expiry_ts - time() );
					if ( $remaining > 0 ) {
						return $remaining;
					}
				}
			}
		}

		// Check Stachethemes transients.
		$pattern = '_transient_timeout_stachesepl_reserved_seat_' . $product_id . '_%';
		$next_timeout = $wpdb->get_var( $wpdb->prepare(
			"SELECT MIN(CAST(option_value AS UNSIGNED)) FROM {$wpdb->options}
			 WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) > %d",
			$wpdb->esc_like( '_transient_timeout_stachesepl_reserved_seat_' . $product_id . '_' ) . '%',
			time()
		) );

		if ( $next_timeout ) {
			return max( 0, (int) $next_timeout - time() );
		}

		return null;
	}

	/**
	 * Render the detailed status box HTML.
	 *
	 * @param int $product_id Product ID.
	 */
	public function render_status_box( $product_id ) {
		$status_data = self::get_detailed_availability_status( $product_id );

		if ( ! $status_data ) {
			return;
		}

		$status = $status_data['status'];
		$config = self::get_status_config( $status );

		// Calculate reserved percentage safely.
		$reserved_percent = ( $status_data['total'] > 0 )
			? ( $status_data['reserved'] / $status_data['total'] ) * 100
			: 0;

		?>
		<div class="as-cai-status-box status-<?php echo esc_attr( $status ); ?>"
			 data-product-id="<?php echo esc_attr( $product_id ); ?>"
			 data-refresh-interval="15000">

			<!-- Status Icon & Title -->
			<div class="status-header">
				<span class="status-icon"><?php echo esc_html( $config['icon'] ); ?></span>
				<h3 class="status-title"><?php echo esc_html( $config['title'] ); ?></h3>
			</div>

			<!-- Availability Details -->
			<div class="status-details">
				<div class="availability-main">
					<strong><?php echo esc_html( $status_data['available'] ); ?> von <?php echo esc_html( $status_data['total'] ); ?> Parzellen</strong>
					<?php echo esc_html( $config['subtitle'] ); ?>
				</div>

				<div class="availability-breakdown">
					<?php if ( $status_data['reserved'] > 0 ) : ?>
						<span class="reserved-badge">
							&#128336; <?php echo esc_html( $status_data['reserved'] ); ?> reserviert
						</span>
					<?php endif; ?>

					<?php if ( $status_data['sold'] > 0 ) : ?>
						<span class="sold-badge">
							&#10003; <?php echo esc_html( $status_data['sold'] ); ?> verkauft
						</span>
					<?php endif; ?>
				</div>

				<!-- Progress Bar -->
				<div class="availability-progress">
					<div class="progress-bar">
						<div class="progress-available"
							 style="width: <?php echo esc_attr( $status_data['percent_free'] ); ?>%"></div>
						<div class="progress-reserved"
							 style="width: <?php echo esc_attr( $reserved_percent ); ?>%"></div>
					</div>
					<div class="progress-labels">
						<span class="label-available"><?php echo esc_html( round( $status_data['percent_free'] ) ); ?>% frei</span>
					</div>
				</div>

				<!-- Reservation Timer (reserved_full only) -->
				<?php if ( 'reserved_full' === $status && $status_data['next_free_in'] ) : ?>
					<div class="reservation-timer">
						<span class="timer-label">Parzellen werden frei in:</span>
						<strong class="timer-countdown"
								data-target="<?php echo esc_attr( time() + $status_data['next_free_in'] ); ?>">
							<?php echo esc_html( self::format_time_remaining( $status_data['next_free_in'] ) ); ?>
						</strong>
					</div>
				<?php endif; ?>

				<!-- Urgency Badge -->
				<?php if ( in_array( $status, array( 'limited', 'critical' ), true ) ) : ?>
					<div class="urgency-badge">
						<span class="pulse-dot"></span>
						<strong><?php echo esc_html( $config['urgency_text'] ); ?></strong>
					</div>
				<?php endif; ?>
			</div>

			<!-- Action Buttons -->
			<div class="status-action">
				<?php if ( 'sold_out' === $status ) : ?>
					<button class="as-cai-waitlist-button" type="button"
							data-product-id="<?php echo esc_attr( $product_id ); ?>">
						Auf Warteliste setzen
					</button>

				<?php elseif ( 'reserved_full' === $status ) : ?>
					<button class="as-cai-notify-button" type="button"
							data-product-id="<?php echo esc_attr( $product_id ); ?>">
						Benachrichtigen wenn frei
					</button>
					<button class="as-cai-refresh-button" type="button">
						Status aktualisieren
					</button>

				<?php else : ?>
					<?php do_action( 'as_cai_status_box_button_area', $product_id, $status ); ?>
				<?php endif; ?>
			</div>

			<!-- Last Updated -->
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
	 *
	 * @param string $status Status code.
	 * @return array Configuration array.
	 */
	private static function get_status_config( $status ) {
		$configs = array(
			'available'     => array(
				'icon'         => "\u{2713}",
				'title'        => 'Sofort buchbar',
				'subtitle'     => 'verfügbar',
				'urgency_text' => '',
			),
			'limited'       => array(
				'icon'         => "\u{26A0}",
				'title'        => 'Nur noch wenige Parzellen',
				'subtitle'     => 'verfügbar',
				'urgency_text' => 'Hohe Nachfrage',
			),
			'critical'      => array(
				'icon'         => "\u{26A1}",
				'title'        => 'Letzte Parzellen!',
				'subtitle'     => 'verfügbar',
				'urgency_text' => 'JETZT BUCHEN!',
			),
			'reserved_full' => array(
				'icon'         => "\u{1F550}",
				'title'        => 'Aktuell alle Parzellen reserviert',
				'subtitle'     => 'in Warenkörben',
				'urgency_text' => '',
			),
			'sold_out'      => array(
				'icon'         => "\u{2715}",
				'title'        => 'Ausgebucht',
				'subtitle'     => 'verkauft',
				'urgency_text' => '',
			),
		);

		return isset( $configs[ $status ] ) ? $configs[ $status ] : $configs['available'];
	}

	/**
	 * Format time remaining.
	 *
	 * @param int $seconds Seconds remaining.
	 * @return string Formatted time string.
	 */
	private static function format_time_remaining( $seconds ) {
		if ( $seconds < 60 ) {
			return $seconds . ' Sek';
		} elseif ( $seconds < 3600 ) {
			return floor( $seconds / 60 ) . ':' . str_pad( $seconds % 60, 2, '0', STR_PAD_LEFT ) . ' Min';
		} else {
			$hours   = floor( $seconds / 3600 );
			$minutes = floor( ( $seconds % 3600 ) / 60 );
			return $hours . ':' . str_pad( $minutes, 2, '0', STR_PAD_LEFT ) . ' Std';
		}
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
	 * AJAX handler: Register email notification for availability.
	 */
	public function ajax_register_notification() {
		check_ajax_referer( 'as_cai_status_nonce', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';

		if ( ! $product_id || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Ungültige Daten', 'as-camp-availability-integration' ) ) );
		}

		// Rate limiting: max 3 notification registrations per IP per hour.
		$rate_key = 'as_cai_notify_' . md5( $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 3 ) {
			wp_send_json_error( array( 'message' => __( 'Zu viele Anfragen. Bitte versuchen Sie es später erneut.', 'as-camp-availability-integration' ) ) );
		}
		set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );

		global $wpdb;
		$table_name = $wpdb->prefix . 'as_cai_notifications';

		// Create table if not exists.
		self::maybe_create_notifications_table();

		// Check for duplicate.
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE product_id = %d AND email = %s AND status = 'pending'",
			$product_id,
			$email
		) );

		if ( $exists ) {
			wp_send_json_success( array(
				'message' => 'Sie sind bereits auf der Benachrichtigungsliste.',
			) );
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

		wp_send_json_success( array(
			'message' => 'Sie werden benachrichtigt, sobald Parzellen verfügbar sind.',
		) );
	}

	/**
	 * Create notifications table if it doesn't exist.
	 */
	public static function maybe_create_notifications_table() {
		global $wpdb;
		$table_name      = $wpdb->prefix . 'as_cai_notifications';
		$charset_collate = $wpdb->get_charset_collate();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
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
	 * Send notifications when seats become available (e.g. after order cancellation).
	 *
	 * @param int $order_id Order ID.
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

			$status_data = self::get_detailed_availability_status( $product_id );
			if ( $status_data && $status_data['available'] > 0 ) {
				self::send_availability_notifications( $product_id );
			}
		}
	}

	/**
	 * Send notifications when seats become available.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function send_availability_notifications( $product_id ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'as_cai_notifications';

		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table_name}'" ) !== $table_name ) {
			return;
		}

		$notifications = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE product_id = %d AND status = 'pending'",
			$product_id
		) );

		if ( empty( $notifications ) ) {
			return;
		}

		$product     = wc_get_product( $product_id );
		$product_name = $product ? $product->get_name() : 'Camp-Parzelle';

		foreach ( $notifications as $notification ) {
			$subject = 'Ayonto Camp: Parzellen wieder verfügbar!';
			$message = sprintf(
				"Gute Nachrichten!\n\nEs sind wieder Parzellen verfügbar für \"%s\".\n\nJetzt buchen: %s\n\n---\nayonto",
				$product_name,
				get_permalink( $product_id )
			);

			wp_mail( $notification->email, $subject, $message );

			$wpdb->update(
				$table_name,
				array(
					'status'  => 'sent',
					'sent_at' => current_time( 'mysql' ),
				),
				array( 'id' => $notification->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}
	}
}
