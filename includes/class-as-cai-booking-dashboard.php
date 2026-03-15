<?php
/**
 * Booking Dashboard Class
 *
 * Displays bookings organized by product categories.
 * Shows customer name, email, products, variations for event management.
 *
 * @package AS_Camp_Availability_Integration
 * @since 1.3.42
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Booking Dashboard functionality.
 *
 * @since 1.3.42
 */
class AS_CAI_Booking_Dashboard {

	/**
	 * Instance of this class.
	 *
	 * @var AS_CAI_Booking_Dashboard|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return AS_CAI_Booking_Dashboard
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
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_init', array( $this, 'handle_csv_export' ) );
	}

	/**
	 * Add menu page to WordPress admin.
	 */
	public function add_menu_page() {
		add_menu_page(
			__( 'Buchungs-Dashboard', 'as-camp-availability-integration' ),
			__( 'Buchungen', 'as-camp-availability-integration' ),
			'manage_woocommerce',
			'as-cai-booking-dashboard',
			array( $this, 'render_dashboard' ),
			'dashicons-calendar-alt',
			56
		);
	}

	/**
	 * Enqueue admin styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_styles( $hook ) {
		if ( 'toplevel_page_as-cai-booking-dashboard' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'as-cai-booking-dashboard',
			AS_CAI_PLUGIN_URL . 'assets/css/booking-dashboard.css',
			array(),
			AS_CAI_VERSION
		);
	}

	/**
	 * Render dashboard page.
	 */
	public function render_dashboard() {
		// Check permission
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Sie haben keine Berechtigung, diese Seite zu sehen.', 'as-camp-availability-integration' ) );
		}

		// Get filter parameters
		$selected_category = isset( $_GET['category'] ) ? sanitize_text_field( $_GET['category'] ) : '';
		$order_status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'any';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
		$date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';

		// Get all product categories
		$categories = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
		) );

		// Get bookings
		$bookings = $this->get_bookings( $selected_category, $order_status, $date_from, $date_to );

		// Group by category
		$grouped_bookings = $this->group_bookings_by_category( $bookings );

		?>
		<div class="wrap as-cai-booking-dashboard">
			<h1><?php esc_html_e( 'Buchungs-Dashboard', 'as-camp-availability-integration' ); ?></h1>
			
			<div class="as-cai-dashboard-info">
				<p><?php esc_html_e( 'Übersicht aller Buchungen sortiert nach Event-Kategorien. Ideal für Camp-Events, Zimmer und Parzellen-Buchungen.', 'as-camp-availability-integration' ); ?></p>
			</div>

			<!-- Filters -->
			<div class="as-cai-filters">
				<form method="get" action="">
					<input type="hidden" name="page" value="as-cai-booking-dashboard">
					
					<div class="as-cai-filter-row">
						<div class="as-cai-filter-item">
							<label for="category"><?php esc_html_e( 'Kategorie:', 'as-camp-availability-integration' ); ?></label>
							<select name="category" id="category">
								<option value=""><?php esc_html_e( 'Alle Kategorien', 'as-camp-availability-integration' ); ?></option>
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( $category->slug ); ?>" <?php selected( $selected_category, $category->slug ); ?>>
										<?php echo esc_html( $category->name ); ?> (<?php echo esc_html( $category->count ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
						</div>

						<div class="as-cai-filter-item">
							<label for="status"><?php esc_html_e( 'Bestellstatus:', 'as-camp-availability-integration' ); ?></label>
							<select name="status" id="status">
								<option value="any" <?php selected( $order_status, 'any' ); ?>><?php esc_html_e( 'Alle Status', 'as-camp-availability-integration' ); ?></option>
								<option value="wc-pending" <?php selected( $order_status, 'wc-pending' ); ?>><?php esc_html_e( 'Ausstehend', 'as-camp-availability-integration' ); ?></option>
								<option value="wc-processing" <?php selected( $order_status, 'wc-processing' ); ?>><?php esc_html_e( 'In Bearbeitung', 'as-camp-availability-integration' ); ?></option>
								<option value="wc-completed" <?php selected( $order_status, 'wc-completed' ); ?>><?php esc_html_e( 'Abgeschlossen', 'as-camp-availability-integration' ); ?></option>
								<option value="wc-cancelled" <?php selected( $order_status, 'wc-cancelled' ); ?>><?php esc_html_e( 'Storniert', 'as-camp-availability-integration' ); ?></option>
							</select>
						</div>

						<div class="as-cai-filter-item">
							<label for="date_from"><?php esc_html_e( 'Von:', 'as-camp-availability-integration' ); ?></label>
							<input type="date" name="date_from" id="date_from" value="<?php echo esc_attr( $date_from ); ?>">
						</div>

						<div class="as-cai-filter-item">
							<label for="date_to"><?php esc_html_e( 'Bis:', 'as-camp-availability-integration' ); ?></label>
							<input type="date" name="date_to" id="date_to" value="<?php echo esc_attr( $date_to ); ?>">
						</div>

						<div class="as-cai-filter-item">
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Filtern', 'as-camp-availability-integration' ); ?></button>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=as-cai-booking-dashboard' ) ); ?>" class="button"><?php esc_html_e( 'Zurücksetzen', 'as-camp-availability-integration' ); ?></a>
						</div>
					</div>
				</form>
			</div>

			<!-- Statistics -->
			<div class="as-cai-stats">
				<?php $this->render_statistics( $bookings ); ?>
			</div>

			<!-- Bookings by Category -->
			<?php if ( empty( $grouped_bookings ) ) : ?>
				<div class="as-cai-no-bookings">
					<p><?php esc_html_e( 'Keine Buchungen gefunden.', 'as-camp-availability-integration' ); ?></p>
				</div>
			<?php else : ?>
				<?php foreach ( $grouped_bookings as $category_slug => $category_data ) : ?>
					<div class="as-cai-category-section">
						<h2 class="as-cai-category-title">
							<?php echo esc_html( $category_data['name'] ); ?>
							<span class="as-cai-booking-count">(<?php echo count( $category_data['bookings'] ); ?> <?php esc_html_e( 'Buchungen', 'as-camp-availability-integration' ); ?>)</span>
						</h2>

						<table class="wp-list-table widefat fixed striped as-cai-bookings-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Bestellung', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Kunde', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'E-Mail', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Telefon', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Produkt', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Parzelle', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Zahlstatus', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Auftragsstatus', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Datum', 'as-camp-availability-integration' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $category_data['bookings'] as $booking ) : ?>
									<tr>
										<td>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $booking['order_id'] . '&action=edit' ) ); ?>" target="_blank">
												#<?php echo esc_html( $booking['order_id'] ); ?>
											</a>
										</td>
										<td><?php echo esc_html( $booking['customer_name'] ); ?></td>
										<td><a href="mailto:<?php echo esc_attr( $booking['customer_email'] ); ?>"><?php echo esc_html( $booking['customer_email'] ); ?></a></td>
										<td><?php echo esc_html( $booking['customer_phone'] ); ?></td>
										<td><?php echo esc_html( $booking['product_name'] ); ?></td>
										<td><?php echo wp_kses_post( $booking['variation_and_seat'] ); ?></td>
										<td>
											<span class="as-cai-status as-cai-status-<?php echo esc_attr( $booking['payment_status'] === 'paid' ? 'completed' : 'pending' ); ?>">
												<?php echo esc_html( $booking['payment_status'] === 'paid' ? __( 'Abgeschlossen', 'as-camp-availability-integration' ) : __( 'Ausstehend', 'as-camp-availability-integration' ) ); ?>
											</span>
										</td>
										<td>
											<span class="as-cai-status as-cai-status-<?php echo esc_attr( sanitize_title( $booking['status'] ) ); ?>">
												<?php echo esc_html( $this->get_order_status_label( $booking['status'] ) ); ?>
											</span>
										</td>
										<td><?php echo esc_html( $booking['date'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>

			<!-- Export Buttons -->
			<div class="as-cai-export">
				<?php
				$export_params = array(
					'page'       => 'as-cai-booking-dashboard',
					'export'     => 'csv',
					'_wpnonce'   => wp_create_nonce( 'as_cai_export_csv' ),
				);
				if ( $selected_category ) {
					$export_params['category'] = $selected_category;
				}
				if ( 'any' !== $order_status ) {
					$export_params['status'] = $order_status;
				}
				if ( $date_from ) {
					$export_params['date_from'] = $date_from;
				}
				if ( $date_to ) {
					$export_params['date_to'] = $date_to;
				}
				?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?' . http_build_query( $export_params ) ) ); ?>" class="button button-secondary">
					<span class="dashicons dashicons-download" style="vertical-align: middle; margin-top: -2px;"></span>
					<?php esc_html_e( 'CSV/Excel Export', 'as-camp-availability-integration' ); ?>
				</a>
				<button type="button" class="button button-secondary" onclick="window.print();">
					<span class="dashicons dashicons-printer" style="vertical-align: middle; margin-top: -2px;"></span>
					<?php esc_html_e( 'Als PDF drucken', 'as-camp-availability-integration' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Get bookings based on filters.
	 *
	 * @param string $category_slug Category slug to filter by.
	 * @param string $order_status  Order status to filter by.
	 * @param string $date_from     Start date for filtering.
	 * @param string $date_to       End date for filtering.
	 * @return array Array of booking data.
	 */
	private function get_bookings( $category_slug = '', $order_status = 'any', $date_from = '', $date_to = '' ) {
		$args = array(
			'limit'   => -1,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'ids',
		);

		if ( 'any' !== $order_status ) {
			$args['status'] = $order_status;
		}

		if ( ! empty( $date_from ) && ! empty( $date_to ) ) {
			$args['date_created'] = strtotime( $date_from ) . '...' . strtotime( $date_to . ' 23:59:59' );
		} elseif ( ! empty( $date_from ) ) {
			$args['date_created'] = '>=' . strtotime( $date_from );
		} elseif ( ! empty( $date_to ) ) {
			$args['date_created'] = '<=' . strtotime( $date_to . ' 23:59:59' );
		}

		$order_ids = wc_get_orders( $args );
		$bookings = array();

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			
			if ( ! $order ) {
				continue;
			}

			// Skip refunds (HPOS compatibility)
			if ( $order->get_type() === 'shop_order_refund' ) {
				continue;
			}

			foreach ( $order->get_items() as $item_id => $item ) {
				$product = $item->get_product();
				
				if ( ! $product ) {
					continue;
				}

				// Check category filter
				if ( ! empty( $category_slug ) ) {
					$product_categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) );
					if ( ! in_array( $category_slug, $product_categories ) ) {
						continue;
					}
				}

				// Get variation info
				$variation_text = '';
				if ( $item->get_variation_id() ) {
					$variation = wc_get_product( $item->get_variation_id() );
					if ( $variation ) {
						$attributes = $variation->get_attributes();
						$variation_text = implode( ', ', $attributes );
					}
				}

				// Get seat planner info - check multiple meta keys and handle serialized data
				$seat_info = '';
				$seats = array();
				
				// Try different meta keys
				$meta_keys = array(
					'_stachethemes_seat_planner_data',
					'seat_data',
					'_seat_data',
				);
				
				foreach ( $meta_keys as $meta_key ) {
					$seat_meta = $item->get_meta( $meta_key, true );
					
					if ( ! empty( $seat_meta ) ) {
						// SECURITY FIX v1.3.55: WooCommerce get_meta() returns already deserialized data
						// NEVER use maybe_unserialize() on user-controllable data (RCE vulnerability)
						
						// Handle JSON string (fallback for custom implementations)
						if ( is_string( $seat_meta ) ) {
							$decoded = json_decode( $seat_meta, true );
							if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
								$seat_meta = (object) $decoded;
							}
						}
						
						// Handle stdClass object (from Stachethemes Seat Planner)
						if ( is_object( $seat_meta ) ) {
							if ( isset( $seat_meta->label ) ) {
								$seats[] = $seat_meta->label;
							} elseif ( isset( $seat_meta->seat ) ) {
								$seats[] = $seat_meta->seat;
							} elseif ( isset( $seat_meta->name ) ) {
								$seats[] = $seat_meta->name;
							} elseif ( isset( $seat_meta->seatId ) ) {
								$seats[] = $seat_meta->seatId;
							}
						}
						// Handle array of seat data
						elseif ( is_array( $seat_meta ) ) {
							foreach ( $seat_meta as $seat_data ) {
								if ( is_object( $seat_data ) ) {
									// stdClass object in array
									if ( isset( $seat_data->label ) ) {
										$seats[] = $seat_data->label;
									} elseif ( isset( $seat_data->seat ) ) {
										$seats[] = $seat_data->seat;
									} elseif ( isset( $seat_data->name ) ) {
										$seats[] = $seat_data->name;
									} elseif ( isset( $seat_data->seatId ) ) {
										$seats[] = $seat_data->seatId;
									}
								} elseif ( is_array( $seat_data ) ) {
									// Array in array
									if ( isset( $seat_data['label'] ) ) {
										$seats[] = $seat_data['label'];
									} elseif ( isset( $seat_data['seat'] ) ) {
										$seats[] = $seat_data['seat'];
									} elseif ( isset( $seat_data['name'] ) ) {
										$seats[] = $seat_data['name'];
									}
								} elseif ( is_string( $seat_data ) ) {
									// String value
									$seats[] = $seat_data;
								}
							}
						}
					}
				}
				
				$seat_info = ! empty( $seats ) ? implode( ', ', array_unique( $seats ) ) : '';

				// Get customer name (HPOS compatible)
				$customer_name = '';
				if ( method_exists( $order, 'get_formatted_billing_full_name' ) ) {
					$customer_name = $order->get_formatted_billing_full_name();
				} else {
					// Fallback for HPOS OrderRefund or other edge cases
					$first_name = $order->get_billing_first_name();
					$last_name = $order->get_billing_last_name();
					$customer_name = trim( $first_name . ' ' . $last_name );
					if ( empty( $customer_name ) ) {
						$customer_name = __( 'Gast', 'as-camp-availability-integration' );
					}
				}

				// Build booking data
				// Combine variation and seat info
				$variation_and_seat_parts = array();
				if ( $variation_text ) {
					$variation_and_seat_parts[] = $variation_text;
				}
				if ( $seat_info ) {
					$variation_and_seat_parts[] = $seat_info;
				}
				$variation_and_seat = ! empty( $variation_and_seat_parts ) 
					? implode( ' • ', $variation_and_seat_parts ) 
					: '—';
				
				$bookings[] = array(
					'order_id'           => $order->get_id(),
					'customer_name'      => $customer_name,
					'customer_email'     => $order->get_billing_email(),
					'customer_phone'     => $order->get_billing_phone(),
					'product_name'       => $item->get_name(),
					'product_id'         => $product->get_id(),
					'variation'          => $variation_text ? $variation_text : '—',
					'seat_info'          => $seat_info ? $seat_info : '—',
					'variation_and_seat' => $variation_and_seat,
					'status'             => $order->get_status(),
					'payment_status'     => $order->is_paid() ? 'paid' : 'unpaid',
					'date'               => $order->get_date_created()->date_i18n( 'd.m.Y H:i' ),
					'categories'         => wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'slugs' ) ),
				);
			}
		}

		return $bookings;
	}

	/**
	 * Group bookings by category.
	 *
	 * @param array $bookings Array of booking data.
	 * @return array Bookings grouped by category.
	 */
	private function group_bookings_by_category( $bookings ) {
		$grouped = array();

		foreach ( $bookings as $booking ) {
			$categories = $booking['categories'];
			
			if ( empty( $categories ) ) {
				$categories = array( 'uncategorized' );
			}

			foreach ( $categories as $category_slug ) {
				if ( ! isset( $grouped[ $category_slug ] ) ) {
					$term = get_term_by( 'slug', $category_slug, 'product_cat' );
					$grouped[ $category_slug ] = array(
						'name'     => $term ? $term->name : __( 'Unkategorisiert', 'as-camp-availability-integration' ),
						'bookings' => array(),
					);
				}
				
				$grouped[ $category_slug ]['bookings'][] = $booking;
			}
		}

		// Sort by category name
		uasort( $grouped, function( $a, $b ) {
			return strcmp( $a['name'], $b['name'] );
		});

		return $grouped;
	}

	/**
	 * Render statistics section.
	 *
	 * @param array $bookings Array of booking data.
	 */
	private function render_statistics( $bookings ) {
		$total_bookings = count( $bookings );
		$total_customers = count( array_unique( array_column( $bookings, 'customer_email' ) ) );
		$total_products = count( array_unique( array_column( $bookings, 'product_id' ) ) );

		// Count by status
		$status_counts = array();
		foreach ( $bookings as $booking ) {
			if ( ! isset( $status_counts[ $booking['status'] ] ) ) {
				$status_counts[ $booking['status'] ] = 0;
			}
			$status_counts[ $booking['status'] ]++;
		}

		?>
		<div class="as-cai-stats-grid">
			<div class="as-cai-stat-card">
				<div class="as-cai-stat-value"><?php echo esc_html( $total_bookings ); ?></div>
				<div class="as-cai-stat-label"><?php esc_html_e( 'Gesamt Buchungen', 'as-camp-availability-integration' ); ?></div>
			</div>
			<div class="as-cai-stat-card">
				<div class="as-cai-stat-value"><?php echo esc_html( $total_customers ); ?></div>
				<div class="as-cai-stat-label"><?php esc_html_e( 'Kunden', 'as-camp-availability-integration' ); ?></div>
			</div>
			<div class="as-cai-stat-card">
				<div class="as-cai-stat-value"><?php echo esc_html( $total_products ); ?></div>
				<div class="as-cai-stat-label"><?php esc_html_e( 'Produkte', 'as-camp-availability-integration' ); ?></div>
			</div>
			<?php foreach ( $status_counts as $status => $count ) : ?>
				<div class="as-cai-stat-card as-cai-stat-status">
					<div class="as-cai-stat-value"><?php echo esc_html( $count ); ?></div>
					<div class="as-cai-stat-label"><?php echo esc_html( wc_get_order_status_name( $status ) ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Get custom order status label.
	 *
	 * @param string $status Order status.
	 * @return string Translated status label.
	 * @since 1.3.50
	 */
	private function get_order_status_label( $status ) {
		$labels = array(
			'pending'    => __( 'Ausstehend', 'as-camp-availability-integration' ),
			'processing' => __( 'In Bearbeitung', 'as-camp-availability-integration' ),
			'completed'  => __( 'Erfolgreich', 'as-camp-availability-integration' ),
			'on-hold'    => __( 'In Wartestellung', 'as-camp-availability-integration' ),
			'cancelled'  => __( 'Storniert', 'as-camp-availability-integration' ),
			'refunded'   => __( 'Erstattet', 'as-camp-availability-integration' ),
			'failed'     => __( 'Fehlgeschlagen', 'as-camp-availability-integration' ),
		);

		return isset( $labels[ $status ] ) ? $labels[ $status ] : wc_get_order_status_name( $status );
	}

	/**
	 * Handle CSV export request.
	 *
	 * Exports visible bookings (respecting active filters) as CSV file.
	 *
	 * @since 1.3.61
	 */
	public function handle_csv_export() {
		if ( ! isset( $_GET['page'], $_GET['export'] ) || 'as-cai-booking-dashboard' !== $_GET['page'] || 'csv' !== $_GET['export'] ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'as-camp-availability-integration' ) );
		}

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'as_cai_export_csv' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'as-camp-availability-integration' ) );
		}

		$category   = isset( $_GET['category'] ) ? sanitize_text_field( $_GET['category'] ) : '';
		$status     = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : 'any';
		$date_from  = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
		$date_to    = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';

		$bookings = $this->get_bookings( $category, $status, $date_from, $date_to );

		$filename = 'buchungen-export-' . gmdate( 'Y-m-d-His' ) . '.csv';

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM for Excel compatibility
		fwrite( $output, "\xEF\xBB\xBF" );

		// Header row
		fputcsv( $output, array(
			'Bestellung',
			'Kunde',
			'E-Mail',
			'Telefon',
			'Produkt',
			'Parzelle',
			'Zahlstatus',
			'Auftragsstatus',
			'Datum',
		), ';' );

		// Data rows
		foreach ( $bookings as $booking ) {
			fputcsv( $output, array(
				'#' . $booking['order_id'],
				$booking['customer_name'],
				$booking['customer_email'],
				$booking['customer_phone'],
				$booking['product_name'],
				wp_strip_all_tags( $booking['variation_and_seat'] ),
				$booking['payment_status'] === 'paid' ? 'Abgeschlossen' : 'Ausstehend',
				$this->get_order_status_label( $booking['status'] ),
				$booking['date'],
			), ';' );
		}

		fclose( $output );
		exit;
	}
}
