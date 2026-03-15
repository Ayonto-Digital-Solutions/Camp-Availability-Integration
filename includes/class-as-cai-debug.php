<?php
/**
 * Debug functionality for Camp Availability Integration.
 *
 * @package AS_Camp_Availability_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Debug class for troubleshooting and diagnostics.
 */
class AS_CAI_Debug {

	/**
	 * Instance of this class.
	 *
	 * @var AS_CAI_Debug|null
	 */
	private static $instance = null;

	/**
	 * Debug log entries.
	 *
	 * @var array
	 */
	private $debug_log = array();

	/**
	 * Get instance.
	 *
	 * @return AS_CAI_Debug
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
		// Add debug info to product page if enabled.
		add_action( 'woocommerce_before_single_product', array( $this, 'output_debug_info' ), 1 );

		// Add AJAX endpoint for debug info - SECURED for authenticated users only.
		add_action( 'wp_ajax_as_cai_get_debug_info', array( $this, 'ajax_get_debug_info' ) );
		// Removed nopriv handler for security - debug info should not be available to non-authenticated users

		// NOTE: Admin debug menu removed in v1.3.23 - now integrated in Settings page
		// add_action( 'admin_menu', array( $this, 'add_debug_menu' ) );

		// Enqueue debug scripts on product pages.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_debug_scripts' ) );
	}

	/**
	 * Check if debug mode is enabled.
	 *
	 * @return bool
	 */
	public static function is_debug_enabled() {
		// Debug enabled via URL parameter - SECURED with authentication and nonce.
		if ( isset( $_GET['as_cai_debug'] ) && '1' === $_GET['as_cai_debug'] ) {
			// Only allow for authenticated admins with valid nonce
			if ( current_user_can( 'manage_options' ) ) {
				// v1.3.58 SECURITY FIX: Always require nonce (SEC-001)
				// Previously: First activation without nonce (CSRF vulnerability)
				if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'as_cai_debug_mode' ) ) {
					return false;
				}
				
				// Track debug session for user
				$transient_key = 'as_cai_debug_' . get_current_user_id();
				set_transient( $transient_key, true, HOUR_IN_SECONDS );
				
				return true;
			}
			return false;
		}

		// Debug enabled via constant.
		if ( defined( 'AS_CAI_DEBUG' ) && AS_CAI_DEBUG ) {
			return true;
		}

		// Debug enabled via option (consistent with Settings tab).
		return 'yes' === get_option( 'as_cai_enable_debug', 'no' );
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $message Debug message.
	 * @param string $level   Log level (info, warning, error).
	 * @param array  $context Additional context data.
	 */
	public function log( $message, $level = 'info', $context = array() ) {
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		$entry = array(
			'timestamp' => current_time( 'Y-m-d H:i:s' ),
			'level'     => $level,
			'message'   => $message,
			'context'   => $context,
		);

		$this->debug_log[] = $entry;

		// Write to WordPress debug log ONLY if Debug Logging is enabled
		if ( 'yes' === get_option( 'as_cai_debug_log', 'no' ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				$log_message = sprintf(
					'[AS CAI %s] %s',
					strtoupper( $level ),
					$message
				);

				if ( ! empty( $context ) ) {
					$log_message .= ' | Context: ' . wp_json_encode( $context );
				}

				error_log( $log_message );
			}
		}
	}

	/**
	 * Output debug information on product pages.
	 */
	public function output_debug_info() {
		if ( ! self::is_debug_enabled() ) {
			return;
		}

		global $product;

		if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product || 'auditorium' !== $product->get_type() ) {
			return;
		}

		$product_id = $product->get_id();

		// Get all availability data.
		$availability = AS_CAI_Availability_Check::get_product_availability( $product_id );

		// Get current time data.
		$current_date = current_time( 'Y-m-d' );
		$current_time = current_time( 'H:i' );
		$current_day  = current_time( 'l' );

		// Get Availability Scheduler settings.
		$template = get_option( 'af_aps_scheduler_templates', 'af_aps_frst_temp' );
		$labels   = get_option( 'af_aps_sched_timer_labels', 'yes' );

		// Get product-level settings.
		$enable_product_level = get_post_meta( $product_id, 'af_aps_enb_prod_lvl', true );
		$prod_availability    = get_post_meta( $product_id, 'af_aps_prod_lvl_availability', true );
		$enable_counter       = get_post_meta( $product_id, 'af_aps_enb_counter_prod_lvl', true );
		$counter_display      = get_post_meta( $product_id, 'af_aps_avail_aftr_bfr_prod_lvl', true );

		// Check for rules.
		$rules       = get_posts(
			array(
				'post_type'   => 'af_product_scheduler',
				'fields'      => 'ids',
				'post_status' => 'publish',
				'numberposts' => -1,
			)
		);
		$active_rule = null;

		if ( ! empty( $rules ) && class_exists( 'Af_Aps_Scheduler_General_Functions' ) ) {
			foreach ( $rules as $rule_id ) {
				if ( Af_Aps_Scheduler_General_Functions::af_aps_rule_validations( $rule_id, $product_id ) ) {
					$active_rule = $rule_id;
					break;
				}
			}
		}

		?>
		<div id="as-cai-debug-panel" style="background: #f0f0f0; border: 3px solid #d63638; padding: 20px; margin: 20px 0; font-family: monospace; font-size: 12px;">
			<h2 style="margin: 0 0 15px 0; color: #d63638; font-size: 16px;">🐛 BG CAI Debug Panel</h2>
			
			<div style="background: white; padding: 15px; margin-bottom: 15px; border-left: 4px solid #2271b1;">
				<h3 style="margin: 0 0 10px 0; color: #2271b1; font-size: 14px;">⏰ Current Time Data</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr>
						<td style="padding: 5px; font-weight: bold; width: 200px;">Current Date:</td>
						<td style="padding: 5px; background: #e8f5e9;"><?php echo esc_html( $current_date ); ?></td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">Current Time:</td>
						<td style="padding: 5px; background: #e8f5e9;"><?php echo esc_html( $current_time ); ?></td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">Current Day:</td>
						<td style="padding: 5px; background: #e8f5e9;"><?php echo esc_html( $current_day ); ?></td>
					</tr>
				</table>
			</div>

			<div style="background: white; padding: 15px; margin-bottom: 15px; border-left: 4px solid #00a32a;">
				<h3 style="margin: 0 0 10px 0; color: #00a32a; font-size: 14px;">✅ Availability Check Result</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr>
						<td style="padding: 5px; font-weight: bold; width: 200px;">Is Available:</td>
						<td style="padding: 5px; background: <?php echo $availability['is_available'] ? '#e8f5e9' : '#ffebee'; ?>; color: <?php echo $availability['is_available'] ? '#00a32a' : '#d63638'; ?>; font-weight: bold;">
							<?php echo $availability['is_available'] ? '✅ YES' : '❌ NO'; ?>
						</td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">Has Counter:</td>
						<td style="padding: 5px; background: <?php echo $availability['has_counter'] ? '#e8f5e9' : '#ffebee'; ?>;">
							<?php echo $availability['has_counter'] ? '✅ YES' : '❌ NO'; ?>
						</td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">Counter Display:</td>
						<td style="padding: 5px; background: #fff3e0;">
							<?php echo esc_html( ! empty( $availability['counter_display'] ) ? $availability['counter_display'] : 'NOT SET' ); ?>
						</td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">Start Date:</td>
						<td style="padding: 5px; background: #e3f2fd;"><?php echo esc_html( $availability['start_date'] ?: 'NOT SET' ); ?></td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">Start Time:</td>
						<td style="padding: 5px; background: #e3f2fd;"><?php echo esc_html( $availability['start_time'] ?: 'NOT SET' ); ?></td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">End Date:</td>
						<td style="padding: 5px; background: #e3f2fd;"><?php echo esc_html( $availability['end_date'] ?: 'NOT SET' ); ?></td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">End Time:</td>
						<td style="padding: 5px; background: #e3f2fd;"><?php echo esc_html( $availability['end_time'] ?: 'NOT SET' ); ?></td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">Text Before:</td>
						<td style="padding: 5px; background: #f3e5f5;"><?php echo esc_html( $availability['text_before'] ?: 'NOT SET' ); ?></td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">Text After:</td>
						<td style="padding: 5px; background: #f3e5f5;"><?php echo esc_html( $availability['text_after'] ?: 'NOT SET' ); ?></td>
					</tr>
				</table>
			</div>

			<div style="background: white; padding: 15px; margin-bottom: 15px; border-left: 4px solid #9b59b6;">
				<h3 style="margin: 0 0 10px 0; color: #9b59b6; font-size: 14px;">⚙️ Product-Level Settings</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr>
						<td style="padding: 5px; font-weight: bold; width: 200px;">Enabled:</td>
						<td style="padding: 5px; background: <?php echo 'yes' === $enable_product_level ? '#e8f5e9' : '#ffebee'; ?>;">
							<?php echo 'yes' === $enable_product_level ? '✅ YES' : '❌ NO'; ?>
						</td>
					</tr>
					<?php if ( 'yes' === $enable_product_level ) : ?>
						<tr>
							<td style="padding: 5px; font-weight: bold;">Availability Mode:</td>
							<td style="padding: 5px; background: #fff3e0;"><?php echo esc_html( $prod_availability ?: 'NOT SET' ); ?></td>
						</tr>
						<tr>
							<td style="padding: 5px; font-weight: bold;">Counter Enabled:</td>
							<td style="padding: 5px; background: <?php echo 'yes' === $enable_counter ? '#e8f5e9' : '#ffebee'; ?>;">
								<?php echo 'yes' === $enable_counter ? '✅ YES' : '❌ NO'; ?>
							</td>
						</tr>
						<tr>
							<td style="padding: 5px; font-weight: bold;">Counter Display:</td>
							<td style="padding: 5px; background: #e3f2fd;"><?php echo esc_html( $counter_display ?: 'NOT SET' ); ?></td>
						</tr>
					<?php endif; ?>
				</table>
			</div>

			<?php if ( $active_rule ) : ?>
				<div style="background: white; padding: 15px; margin-bottom: 15px; border-left: 4px solid #ff9800;">
					<h3 style="margin: 0 0 10px 0; color: #ff9800; font-size: 14px;">📋 Active Rule</h3>
					<table style="width: 100%; border-collapse: collapse;">
						<tr>
							<td style="padding: 5px; font-weight: bold; width: 200px;">Rule ID:</td>
							<td style="padding: 5px; background: #fff3e0;"><?php echo esc_html( $active_rule ); ?></td>
						</tr>
						<tr>
							<td style="padding: 5px; font-weight: bold;">Rule Title:</td>
							<td style="padding: 5px; background: #fff3e0;"><?php echo esc_html( get_the_title( $active_rule ) ); ?></td>
						</tr>
					</table>
				</div>
			<?php else : ?>
				<div style="background: white; padding: 15px; margin-bottom: 15px; border-left: 4px solid #999;">
					<h3 style="margin: 0 0 10px 0; color: #999; font-size: 14px;">📋 Rules</h3>
					<p style="margin: 0; color: #666;">No active rule found for this product</p>
				</div>
			<?php endif; ?>

			<div style="background: white; padding: 15px; margin-bottom: 15px; border-left: 4px solid #00bcd4;">
				<h3 style="margin: 0 0 10px 0; color: #00bcd4; font-size: 14px;">🎨 Display Settings</h3>
				<table style="width: 100%; border-collapse: collapse;">
					<tr>
						<td style="padding: 5px; font-weight: bold; width: 200px;">Template:</td>
						<td style="padding: 5px; background: #e0f7fa;"><?php echo esc_html( $template ); ?></td>
					</tr>
					<tr>
						<td style="padding: 5px; font-weight: bold;">Show Labels:</td>
						<td style="padding: 5px; background: #e0f7fa;"><?php echo 'yes' === $labels ? '✅ YES' : '❌ NO'; ?></td>
					</tr>
				</table>
			</div>

			<div style="background: white; padding: 15px; border-left: 4px solid #607d8b;">
				<h3 style="margin: 0 0 10px 0; color: #607d8b; font-size: 14px;">🔍 DOM Elements</h3>
				<div id="as-cai-debug-dom-check" style="padding: 10px; background: #f5f5f5; font-size: 11px;">
					<p style="margin: 0 0 5px 0;">Checking for elements in DOM...</p>
				</div>
			</div>

			<div style="background: #d63638; color: white; padding: 10px; margin-top: 15px; text-align: center;">
				<strong>Debug Mode Active</strong> | To disable: Go to WooCommerce → BG CAI Debug and uncheck "Enable Debug Mode", or remove ?as_cai_debug=1 from URL
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue debug scripts.
	 */
	public function enqueue_debug_scripts() {
		if ( ! self::is_debug_enabled() || ! is_product() ) {
			return;
		}

		// Enqueue the debug script file (now created)
		wp_enqueue_script(
			'as-cai-debug-script',
			plugin_dir_url( dirname( __FILE__ ) ) . 'assets/js/as-cai-debug.js',
			array( 'jquery', 'as-cai-script' ),
			'1.2.0',
			true
		);

		// Pass debug data securely via wp_localize_script
		wp_localize_script(
			'as-cai-debug-script',
			'asCaiDebugData',
			array(
				'debug_enabled' => true,
				'nonce'        => wp_create_nonce( 'as_cai_debug' ),
				'ajax_url'     => admin_url( 'admin-ajax.php' ),
				'product_id'   => get_the_ID(),
				'messages'     => array(
					'debug_active'    => __( '🐛 BG CAI Debug Mode Active', 'as-camp-availability-integration' ),
					'wrapper_found'   => __( 'Counter Wrapper Found:', 'as-camp-availability-integration' ),
					'elements_found'  => __( 'Counter Elements Found:', 'as-camp-availability-integration' ),
					'button_found'    => __( 'Seat Planner Button Found:', 'as-camp-availability-integration' ),
					'button_visible'  => __( 'Seat Planner Button Visible:', 'as-camp-availability-integration' ),
					'cart_found'      => __( 'Add to Cart Button Found:', 'as-camp-availability-integration' ),
					'cart_visible'    => __( 'Add to Cart Button Visible:', 'as-camp-availability-integration' ),
					'data_not_found'  => __( 'asCaiData not found! Scripts may not be loaded correctly.', 'as-camp-availability-integration' ),
					'dom_status'      => __( 'DOM Element Status:', 'as-camp-availability-integration' ),
					'found'          => __( 'Found', 'as-camp-availability-integration' ),
					'not_found'      => __( 'Not Found', 'as-camp-availability-integration' ),
					'visible_yes'    => __( 'Yes', 'as-camp-availability-integration' ),
					'visible_no'     => __( 'No', 'as-camp-availability-integration' ),
				),
			)
		);
	}

	/**
	 * Add debug menu to admin.
	 */
	public function add_debug_menu() {
		add_submenu_page(
			'woocommerce',
			'BG CAI Debug',
			'BG CAI Debug',
			'manage_woocommerce',
			'as-cai-debug',
			array( $this, 'render_debug_page' )
		);
	}

	/**
	 * Render debug page in admin.
	 */
	public function render_debug_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Handle debug mode toggle.
		if ( isset( $_POST['as_cai_save_debug_settings'] ) && check_admin_referer( 'as_cai_debug_settings' ) ) {
			// Check if checkbox is set (it won't be present in POST if unchecked).
			$debug_mode = isset( $_POST['as_cai_debug_mode'] ) && 'on' === $_POST['as_cai_debug_mode'] ? 'yes' : 'no';
			update_option( 'as_cai_debug_mode', $debug_mode );
			echo '<div class="notice notice-success"><p>Debug mode ' . ( 'yes' === $debug_mode ? 'enabled' : 'disabled' ) . '.</p></div>';
		}

		$debug_enabled = self::is_debug_enabled();

		?>
		<div class="wrap">
			<h1>Camp Availability Integration - Debug Tools</h1>

			<div class="card">
				<h2>Debug Mode</h2>
				<form method="post">
					<?php wp_nonce_field( 'as_cai_debug_settings' ); ?>
					<input type="hidden" name="as_cai_save_debug_settings" value="1">
					<table class="form-table">
						<tr>
							<th scope="row">Enable Debug Mode</th>
							<td>
								<label>
									<input type="checkbox" name="as_cai_debug_mode" value="on" <?php checked( $debug_enabled ); ?>>
									Show debug information on product pages
								</label>
								<p class="description">
									When enabled, detailed debug information will be displayed on all auditorium product pages.<br>
									You can also enable debug mode temporarily by adding <code>?as_cai_debug=1</code> to any product URL.
								</p>
							</td>
						</tr>
					</table>
					<?php submit_button( 'Save Settings' ); ?>
				</form>
			</div>

			<div class="card">
				<h2>System Information</h2>
				<table class="widefat">
					<tr>
						<th style="width: 300px;">WordPress Version</th>
						<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
					</tr>
					<tr>
						<th>PHP Version</th>
						<td><?php echo esc_html( phpversion() ); ?></td>
					</tr>
					<tr>
						<th>WooCommerce Version</th>
						<td><?php echo class_exists( 'WooCommerce' ) ? esc_html( WC()->version ) : 'Not installed'; ?></td>
					</tr>
					<tr>
						<th>Availability Scheduler</th>
						<td><?php echo class_exists( 'Koala_Availability_Scheduler_For_Woocommerce' ) ? '✅ Active' : '❌ Not active'; ?></td>
					</tr>
					<tr>
						<th>Seat Planner</th>
						<td><?php echo class_exists( 'Stachethemes\SeatPlanner\Stachethemes_Seat_Planner' ) ? '✅ Active' : '❌ Not active'; ?></td>
					</tr>
					<tr>
						<th>AS CAI Version</th>
						<td><?php echo esc_html( AS_CAI_VERSION ); ?></td>
					</tr>
					<tr>
						<th>Debug Mode</th>
						<td><?php echo $debug_enabled ? '✅ Enabled' : '❌ Disabled'; ?></td>
					</tr>
					<tr>
						<th>WP_DEBUG</th>
						<td><?php echo defined( 'WP_DEBUG' ) && WP_DEBUG ? '✅ Enabled' : '❌ Disabled'; ?></td>
					</tr>
					<tr>
						<th>WP_DEBUG_LOG</th>
						<td><?php echo defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ? '✅ Enabled' : '❌ Disabled'; ?></td>
					</tr>
				</table>
			</div>

			<div class="card">
				<h2>Quick Debug Links</h2>
				<p>Open these links to test the plugin on product pages:</p>
				<?php
				$products = get_posts(
					array(
						'post_type'   => 'product',
						'numberposts' => 5,
						'meta_query'  => array(
							array(
								'key'   => '_product_type',
								'value' => 'auditorium',
							),
						),
					)
				);

				if ( ! empty( $products ) ) {
					echo '<ul>';
					foreach ( $products as $product ) {
						$debug_url = add_query_arg( 'as_cai_debug', '1', get_permalink( $product->ID ) );
						echo '<li><a href="' . esc_url( $debug_url ) . '" target="_blank">' . esc_html( $product->post_title ) . ' (Debug Mode)</a></li>';
					}
					echo '</ul>';
				} else {
					echo '<p>No auditorium products found.</p>';
				}
				?>
			</div>

			<div class="card">
				<h2>How to Use Debug Mode</h2>
				<ol>
					<li>Enable debug mode above or add <code>?as_cai_debug=1</code> to any product URL</li>
					<li>Visit an auditorium product page</li>
					<li>Look for the red "Debug Panel" at the top of the product page</li>
					<li>Check the browser console (F12) for detailed JavaScript logs</li>
					<li>Review the information to identify any configuration issues</li>
				</ol>
				
				<h3>What to Check:</h3>
				<ul>
					<li><strong>Is Available:</strong> Should be YES when product should be purchasable</li>
					<li><strong>Has Counter:</strong> Should be YES when counter should display</li>
					<li><strong>Counter Display:</strong> Must match your Availability Scheduler settings</li>
					<li><strong>Start/End Dates:</strong> Verify these match your configuration</li>
					<li><strong>DOM Elements:</strong> Counter and buttons should be found in the page</li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX handler for getting debug info.
	 */
	public function ajax_get_debug_info() {
		// Security check: Verify user capabilities
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'Unauthorized access', 'as-camp-availability-integration' ), 403 );
		}
		
		check_ajax_referer( 'as_cai_debug', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;

		if ( ! $product_id ) {
			wp_send_json_error( 'No product ID provided' );
		}
		
		// SEC-004 Fix: Validate that product exists
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_send_json_error( 'Invalid product ID provided' );
		}

		$availability = AS_CAI_Availability_Check::get_product_availability( $product_id );

		wp_send_json_success(
			array(
				'product_id'   => $product_id,
				'availability' => $availability,
				'current_time' => current_time( 'Y-m-d H:i:s' ),
			)
		);
	}
}
