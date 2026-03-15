<?php
/**
 * Product Availability Management
 * 
 * Handles product availability based on start date/time.
 * Replaces dependency on external Availability Scheduler plugin.
 * 
 * @package AS_Camp_Availability_Integration
 * @since 1.3.30
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AS_CAI_Product_Availability {
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

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		// Admin: Add meta box for availability settings
		add_action( 'add_meta_boxes', array( $this, 'add_availability_meta_box' ) );
		add_action( 'save_post', array( $this, 'save_availability_meta_box' ), 10, 2 );

		// Frontend: Control purchasability with HIGHEST priority
		// This runs BEFORE any other plugin (including Koalaapps Scheduler)
		add_filter( 'woocommerce_is_purchasable', array( $this, 'control_purchasability' ), 5, 2 );
		
		// Admin: Add availability column to product list
		add_filter( 'manage_product_posts_columns', array( $this, 'add_availability_column' ) );
		add_action( 'manage_product_posts_custom_column', array( $this, 'render_availability_column' ), 10, 2 );
	}

	/**
	 * Add meta box for availability settings.
	 */
	public function add_availability_meta_box() {
		add_meta_box(
			'as_cai_availability',
			__( 'Produkt-Verfügbarkeit (Ayonto Camp)', 'as-camp-availability-integration' ),
			array( $this, 'render_availability_meta_box' ),
			'product',
			'side',
			'high'
		);
	}

	/**
	 * Render availability meta box.
	 */
	public function render_availability_meta_box( $post ) {
		wp_nonce_field( 'as_cai_availability_meta_box', 'as_cai_availability_nonce' );

		$enabled = get_post_meta( $post->ID, '_as_cai_availability_enabled', true );
		$start_date = get_post_meta( $post->ID, '_as_cai_availability_start_date', true );
		$start_time = get_post_meta( $post->ID, '_as_cai_availability_start_time', true );
		
		// Default start time to 00:00 if not set
		if ( empty( $start_time ) ) {
			$start_time = '00:00';
		}
		
		?>
		<div class="as-cai-availability-settings">
			<p>
				<label>
					<input type="checkbox" 
						   name="as_cai_availability_enabled" 
						   value="yes" 
						   <?php checked( $enabled, 'yes' ); ?>>
					<strong><?php _e( 'Verfügbarkeit aktivieren', 'as-camp-availability-integration' ); ?></strong>
				</label>
			</p>

			<div class="as-cai-availability-fields" style="<?php echo $enabled === 'yes' ? '' : 'display:none;'; ?>">
				<p>
					<label>
						<strong><?php _e( 'Start-Datum', 'as-camp-availability-integration' ); ?></strong><br>
						<input type="date" 
							   name="as_cai_availability_start_date" 
							   value="<?php echo esc_attr( $start_date ); ?>"
							   style="width: 100%;">
					</label>
				</p>

				<p>
					<label>
						<strong><?php _e( 'Start-Zeit', 'as-camp-availability-integration' ); ?></strong><br>
						<input type="time" 
							   name="as_cai_availability_start_time" 
							   value="<?php echo esc_attr( $start_time ); ?>"
							   style="width: 100%;">
					</label>
				</p>

				<p class="description">
					<?php _e( 'Das Produkt wird erst ab dem angegebenen Datum/Zeit kaufbar.', 'as-camp-availability-integration' ); ?>
				</p>

				<?php
				// Show current status
				if ( ! empty( $start_date ) ) {
					$is_available = $this->is_product_available( $post->ID );
					$status_class = $is_available ? 'available' : 'not-available';
					$status_text = $is_available 
						? __( '✅ Produkt ist jetzt verfügbar', 'as-camp-availability-integration' )
						: __( '⏰ Produkt ist noch nicht verfügbar', 'as-camp-availability-integration' );
					
					echo '<p class="as-cai-status ' . esc_attr( $status_class ) . '">';
					echo '<strong>' . esc_html( $status_text ) . '</strong>';
					echo '</p>';
				}
				?>
			</div>

			<style>
				.as-cai-status {
					padding: 8px;
					border-radius: 4px;
					margin-top: 10px;
				}
				.as-cai-status.available {
					background: #d4edda;
					border: 1px solid #c3e6cb;
					color: #155724;
				}
				.as-cai-status.not-available {
					background: #fff3cd;
					border: 1px solid #ffeaa7;
					color: #856404;
				}
			</style>

			<script>
				jQuery(document).ready(function($) {
					$('input[name="as_cai_availability_enabled"]').on('change', function() {
						if ($(this).is(':checked')) {
							$('.as-cai-availability-fields').slideDown();
						} else {
							$('.as-cai-availability-fields').slideUp();
						}
					});
				});
			</script>
		</div>
		<?php
	}

	/**
	 * Save availability meta box data.
	 */
	public function save_availability_meta_box( $post_id, $post ) {
		// Security checks
		if ( ! isset( $_POST['as_cai_availability_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['as_cai_availability_nonce'], 'as_cai_availability_meta_box' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save enabled status
		$enabled = isset( $_POST['as_cai_availability_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_as_cai_availability_enabled', $enabled );

		// Save start date
		if ( isset( $_POST['as_cai_availability_start_date'] ) ) {
			$start_date = sanitize_text_field( $_POST['as_cai_availability_start_date'] );
			update_post_meta( $post_id, '_as_cai_availability_start_date', $start_date );
		}

		// Save start time
		if ( isset( $_POST['as_cai_availability_start_time'] ) ) {
			$start_time = sanitize_text_field( $_POST['as_cai_availability_start_time'] );
			update_post_meta( $post_id, '_as_cai_availability_start_time', $start_time );
		}

		// Advanced Debug: Log save action
		if ( class_exists( 'AS_CAI_Advanced_Debug' ) ) {
			AS_CAI_Advanced_Debug::instance()->info( 'admin', 'Availability settings saved', array(
				'product_id'  => $post_id,
				'enabled'     => $enabled,
				'start_date'  => isset( $start_date ) ? $start_date : '',
				'start_time'  => isset( $start_time ) ? $start_time : '',
				'is_available' => $this->is_product_available( $post_id ),
			) );
		}
	}

	/**
	 * Control product purchasability based on availability settings.
	 * 
	 * This runs with Priority 5 - BEFORE the Koalaapps Scheduler (Priority 10)
	 * and BEFORE our cart reservation logic (Priority 50).
	 * 
	 * @since 1.3.30
	 */
	public function control_purchasability( $purchasable, $product ) {
		// Advanced Debug: Track entry
		if ( class_exists( 'AS_CAI_Advanced_Debug' ) ) {
			AS_CAI_Advanced_Debug::instance()->performance_start( 'availability_control_purchasability' );
		}

		$product_id = $product->get_id();

		// Check if our availability system is enabled for this product
		$enabled = get_post_meta( $product_id, '_as_cai_availability_enabled', true );

		if ( $enabled !== 'yes' ) {
			// Not using our system - let other plugins handle it
			if ( class_exists( 'AS_CAI_Advanced_Debug' ) ) {
				AS_CAI_Advanced_Debug::instance()->debug( 'hooks', 'Ayonto Camp Availability not enabled for product', array(
					'product_id' => $product_id,
					'enabled'    => $enabled,
				) );
				AS_CAI_Advanced_Debug::instance()->performance_end( 'availability_control_purchasability' );
			}
			return $purchasable;
		}

		// Check if product is available based on start date/time
		$is_available = $this->is_product_available( $product_id );

		// Advanced Debug: Log decision
		if ( class_exists( 'AS_CAI_Advanced_Debug' ) ) {
			$start_date = get_post_meta( $product_id, '_as_cai_availability_start_date', true );
			$start_time = get_post_meta( $product_id, '_as_cai_availability_start_time', true );
			
			AS_CAI_Advanced_Debug::instance()->info( 'hooks', 'Ayonto Camp Availability check completed', array(
				'product_id'     => $product_id,
				'is_available'   => $is_available,
				'start_date'     => $start_date,
				'start_time'     => $start_time,
				'current_time'   => current_time( 'Y-m-d H:i:s' ),
				'result'         => $is_available ? 'PURCHASABLE' : 'NOT PURCHASABLE',
			) );

			AS_CAI_Advanced_Debug::instance()->performance_end( 'availability_control_purchasability', array(
				'product_id' => $product_id,
				'result'     => $is_available,
			) );
		}

		// Return availability status
		// If not available, this blocks the "Add to Cart" button
		return $is_available ? $purchasable : false;
	}

	/**
	 * Check if product is currently available based on start date/time.
	 * 
	 * IMPORTANT: Uses WordPress timezone for timestamp calculations to avoid timezone issues.
	 * 
	 * @param int $product_id Product ID
	 * @return bool True if available, false if not
	 * @since 1.3.38 Fixed timezone handling - now uses wp_timezone()
	 */
	public function is_product_available( $product_id ) {
		$enabled = get_post_meta( $product_id, '_as_cai_availability_enabled', true );

		if ( $enabled !== 'yes' ) {
			return true; // Not using our system - always available
		}

		$start_date = get_post_meta( $product_id, '_as_cai_availability_start_date', true );
		$start_time = get_post_meta( $product_id, '_as_cai_availability_start_time', true );

		// If no start date set, product is available
		if ( empty( $start_date ) ) {
			return true;
		}

		// Default to 00:00 if no time set
		if ( empty( $start_time ) ) {
			$start_time = '00:00';
		}

		// Get WordPress timezone for proper timestamp calculations (v1.3.38 fix)
		$wp_timezone = wp_timezone();

		// Combine date and time
		$start_datetime = $start_date . ' ' . $start_time . ':00';

		// Calculate timestamps using WordPress timezone
		try {
			$start_datetime_obj = new DateTime( $start_datetime, $wp_timezone );
			$start_timestamp = $start_datetime_obj->getTimestamp();
			
			$current_datetime_obj = new DateTime( 'now', $wp_timezone );
			$current_timestamp = $current_datetime_obj->getTimestamp();
		} catch ( Exception $e ) {
			// Fallback to old method if DateTime fails
			$start_timestamp = strtotime( $start_datetime );
			$current_timestamp = strtotime( current_time( 'Y-m-d H:i:s' ) );
		}

		// Product is available if current time is >= start time
		return $current_timestamp >= $start_timestamp;
	}

	/**
	 * Get availability data for a product (for frontend JavaScript).
	 * 
	 * IMPORTANT: Uses WordPress timezone for timestamp calculations to avoid timezone issues.
	 * 
	 * @param int $product_id Product ID
	 * @return array|null Availability data or null if not using our system
	 * @since 1.3.38 Fixed timezone handling - now uses wp_timezone()
	 */
	public function get_availability_data( $product_id ) {
		$enabled = get_post_meta( $product_id, '_as_cai_availability_enabled', true );

		if ( $enabled !== 'yes' ) {
			return null; // Not using our system
		}

		$start_date = get_post_meta( $product_id, '_as_cai_availability_start_date', true );
		$start_time = get_post_meta( $product_id, '_as_cai_availability_start_time', true );

		if ( empty( $start_date ) ) {
			return null; // No start date set
		}

		// Default to 00:00 if no time set
		if ( empty( $start_time ) ) {
			$start_time = '00:00';
		}

		// Get WordPress timezone for proper timestamp calculations
		$wp_timezone = wp_timezone();

		// Combine date and time
		$start_datetime = $start_date . ' ' . $start_time . ':00';
		
		// Calculate start timestamp using WordPress timezone (v1.3.38 fix)
		try {
			$start_datetime_obj = new DateTime( $start_datetime, $wp_timezone );
			$start_timestamp = $start_datetime_obj->getTimestamp();
		} catch ( Exception $e ) {
			// Fallback to strtotime if DateTime fails
			$start_timestamp = strtotime( $start_datetime );
		}

		// Get current time using WordPress timezone (v1.3.38 fix)
		try {
			$current_datetime_obj = new DateTime( 'now', $wp_timezone );
			$current_timestamp = $current_datetime_obj->getTimestamp();
		} catch ( Exception $e ) {
			// Fallback to time() if DateTime fails
			$current_timestamp = time();
		}

		return array(
			'enabled'           => true,
			'start_date'        => $start_date,
			'start_time'        => $start_time,
			'start_datetime'    => $start_datetime,
			'start_timestamp'   => $start_timestamp,
			'current_timestamp' => $current_timestamp,
			'is_available'      => $current_timestamp >= $start_timestamp,
			'seconds_until'     => max( 0, $start_timestamp - $current_timestamp ),
		);
	}

	/**
	 * Add availability column to product list.
	 */
	public function add_availability_column( $columns ) {
		$columns['as_cai_availability'] = __( 'Verfügbarkeit', 'as-camp-availability-integration' );
		return $columns;
	}

	/**
	 * Render availability column content.
	 */
	public function render_availability_column( $column, $post_id ) {
		if ( $column !== 'as_cai_availability' ) {
			return;
		}

		$enabled = get_post_meta( $post_id, '_as_cai_availability_enabled', true );

		if ( $enabled !== 'yes' ) {
			echo '<span style="color: #999;">—</span>';
			return;
		}

		$is_available = $this->is_product_available( $post_id );
		$start_date = get_post_meta( $post_id, '_as_cai_availability_start_date', true );
		$start_time = get_post_meta( $post_id, '_as_cai_availability_start_time', true );

		if ( $is_available ) {
			echo '<span style="color: #46b450;">✅ Verfügbar</span>';
		} else {
			echo '<span style="color: #dc3232;">⏰ Nicht verfügbar</span><br>';
			echo '<small style="color: #666;">' . esc_html( $start_date . ' ' . $start_time ) . '</small>';
		}
	}

	/**
	 * Check if external Koalaapps Scheduler is active.
	 * 
	 * @return bool True if Koalaapps Scheduler is active
	 */
	public function is_koalaapps_scheduler_active() {
		return function_exists( 'af_aps_init' ) || class_exists( 'Af_Aps_Product_Scheduler_Front' );
	}

	/**
	 * Migrate data from Koalaapps Scheduler to our system.
	 * 
	 * This is a one-time migration for existing products.
	 * 
	 * @param int $product_id Product ID
	 * @return bool True if migrated, false if nothing to migrate
	 */
	public function migrate_from_koalaapps( $product_id ) {
		// Check if already using our system
		$already_enabled = get_post_meta( $product_id, '_as_cai_availability_enabled', true );
		if ( $already_enabled === 'yes' ) {
			return false; // Already migrated
		}

		// Check if Koalaapps settings exist
		$koala_enabled = get_post_meta( $product_id, 'af_aps_enb_prod_lvl', true );
		if ( $koala_enabled !== 'yes' ) {
			return false; // Not using Koalaapps
		}

		// Get Koalaapps data
		$koala_availability = get_post_meta( $product_id, 'af_aps_prod_lvl_availability', true );
		$koala_start_date = get_post_meta( $product_id, 'af_aps_start_date_prod_lvl', true );
		$koala_start_time = get_post_meta( $product_id, 'af_aps_start_time_prod_lvl', true );

		// Only migrate if set to "available" mode (we don't support "unavailable" mode)
		if ( $koala_availability !== 'aps_prod_lvl_available' ) {
			return false;
		}

		// Convert Koalaapps time format (12h with AM/PM) to 24h format
		if ( ! empty( $koala_start_time ) ) {
			$time_obj = DateTime::createFromFormat( 'h:i A', $koala_start_time );
			if ( $time_obj ) {
				$koala_start_time = $time_obj->format( 'H:i' );
			}
		}

		// Migrate to our system
		update_post_meta( $product_id, '_as_cai_availability_enabled', 'yes' );
		update_post_meta( $product_id, '_as_cai_availability_start_date', $koala_start_date );
		update_post_meta( $product_id, '_as_cai_availability_start_time', $koala_start_time );

		// Log migration
		if ( class_exists( 'AS_CAI_Advanced_Debug' ) ) {
			AS_CAI_Advanced_Debug::instance()->info( 'admin', 'Migrated from Koalaapps Scheduler', array(
				'product_id'  => $product_id,
				'start_date'  => $koala_start_date,
				'start_time'  => $koala_start_time,
			) );
		}

		return true;
	}
}
