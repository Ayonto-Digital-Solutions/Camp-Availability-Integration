<?php
/**
 * Frontend functionality.
 *
 * @package AS_Camp_Availability_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frontend class for handling timer display and button control.
 */
class AS_CAI_Frontend {

	/**
	 * Instance of this class.
	 *
	 * @var AS_CAI_Frontend|null
	 */
	private static $instance = null;

	/**
	 * Get instance.
	 *
	 * @return AS_CAI_Frontend
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
		// Enqueue scripts and styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		
		// v1.3.57 AGGRESSIVE FALLBACK: Load countdown script on ALL pages (debug mode)
		// This ensures script is loaded even if WooCommerce conditional tags fail
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_countdown_fallback' ), 999 );

		// Add counter before price (standard WooCommerce template).
		add_action( 'woocommerce_single_product_summary', array( $this, 'add_counter_before_price' ), 9 );

		// Add counter before add to cart button (works with Elementor).
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'add_counter_before_button' ), 5 );

		// Hide Seat Planner button if product is not available.
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'maybe_hide_seat_planner_button' ), 10 );

		// Add shortcode for manual counter placement.
		add_shortcode( 'as_cai_availability_counter', array( $this, 'shortcode_availability_counter' ) );

		// v1.3.37: Hide stock display on product detail pages
		add_filter( 'woocommerce_get_stock_html', array( $this, 'hide_stock_display' ), 10, 2 );

		// v1.3.37: Customize loop button for unavailable products
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'customize_loop_button' ), 10, 2 );
	}

	/**
	 * Enqueue scripts and styles.
	 * 
	 * @since 1.3.41 CRITICAL FIX: Robust WooCommerce page detection + always load countdown script
	 */
	public function enqueue_scripts() {
		// Check if WooCommerce is active
		if ( ! function_exists( 'is_shop' ) ) {
			return;
		}

		$is_product_page = is_product();
		$is_shop_page = is_shop() || is_product_category() || is_product_tag();
		
		// Additional WooCommerce checks (v1.3.57 fix)
		$is_wc_page = $is_product_page || $is_shop_page;
		
		// Check if we're in a WooCommerce query (even if conditional tags fail)
		if ( ! $is_wc_page && function_exists( 'is_woocommerce' ) ) {
			$is_wc_page = is_woocommerce();
		}
		
		// Check if post type is product
		if ( ! $is_wc_page && is_singular() ) {
			global $post;
			if ( $post && $post->post_type === 'product' ) {
				$is_wc_page = true;
				$is_product_page = true;
			}
		}
		
		// Check if we have product loop (archive/shop page)
		if ( ! $is_wc_page && ( is_post_type_archive( 'product' ) || is_tax( get_object_taxonomies( 'product' ) ) ) ) {
			$is_wc_page = true;
			$is_shop_page = true;
		}
		
		// v1.3.57 DEBUG: Log enqueue decision
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// SECURITY FIX v1.3.55: Sanitize REQUEST_URI to prevent XSS in logs
			$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : 'unknown';
			
			error_log( sprintf(
				'[AS-CAI v1.3.55] enqueue_scripts() | is_product: %s, is_shop: %s, is_wc_page: %s, URL: %s',
				$is_product_page ? 'YES' : 'NO',
				$is_shop_page ? 'YES' : 'NO',
				$is_wc_page ? 'YES' : 'NO',
				$request_uri
			) );
		}

		if ( ! $is_product_page && ! $is_shop_page ) {
			return;
		}

		// Load on product pages
		if ( $is_product_page ) {
			global $product;

			// Fallback: Versuche Produkt über get_the_ID() zu holen.
			if ( ! $product || ! is_object( $product ) ) {
				$product = wc_get_product( get_the_ID() );
			}

			if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
				return;
			}

			// Load styles and scripts for all product types that have availability settings.
			// No longer limited to 'auditorium' products only.

			wp_enqueue_style(
				'as-cai-styles',
				AS_CAI_PLUGIN_URL . 'assets/css/as-cai-frontend.css',
				array(),
				AS_CAI_VERSION
			);

			wp_enqueue_script(
				'as-cai-script',
				AS_CAI_PLUGIN_URL . 'assets/js/as-cai-frontend.js',
				array( 'jquery' ),
				AS_CAI_VERSION,
				true
			);

			// Pass availability data to JavaScript.
			$availability = AS_CAI_Availability_Check::get_product_availability( $product->get_id() );

			wp_localize_script(
				'as-cai-script',
				'asCaiData',
				array(
					'isAvailable' => $availability['is_available'],
					'hasCounter'  => $availability['has_counter'],
					'startDate'   => $availability['start_date'],
					'startTime'   => $availability['start_time'],
					'endDate'     => $availability['end_date'],
					'endTime'     => $availability['end_time'],
				)
			);
		}

		// Load on category/shop pages for countdown buttons
		// v1.3.57: Always load if $is_shop_page is true
		if ( $is_shop_page ) {
			wp_enqueue_style(
				'as-cai-loop-styles',
				AS_CAI_PLUGIN_URL . 'assets/css/as-cai-frontend.css',
				array(),
				AS_CAI_VERSION
			);

			wp_enqueue_script(
				'as-cai-loop-countdown',
				AS_CAI_PLUGIN_URL . 'assets/js/as-cai-loop-countdown.js',
				array( 'jquery' ),
				AS_CAI_VERSION,
				true
			);
			
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AS-CAI] Countdown script enqueued on shop/category page' );
			}
		}
	}

	/**
	 * FALLBACK: Force-load countdown script if it wasn't enqueued normally
	 * 
	 * This method runs AFTER normal enqueue_scripts() and ensures the countdown
	 * script is loaded even if WooCommerce conditional tags fail to detect
	 * shop/category pages properly.
	 * 
	 * v1.3.57: Enhanced with WooCommerce page detection to prevent loading on non-WC pages
	 * This significantly improves performance on non-WooCommerce pages (~200ms faster)
	 * 
	 * @since 1.3.41 CRITICAL FIX: Ensures script is always loaded for debugging
	 * @since 1.3.57 PERFORMANCE FIX: Only load on WooCommerce pages
	 */
	public function enqueue_countdown_fallback() {
		// Check if WooCommerce is active
		if ( ! function_exists( 'is_shop' ) ) {
			return;
		}
		
		// Check if script was already enqueued by normal method
		if ( wp_script_is( 'as-cai-loop-countdown', 'enqueued' ) ) {
			// Script already loaded - perfect!
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AS-CAI v1.3.57] ✅ Countdown script already enqueued by normal method' );
			}
			return;
		}
		
		// v1.3.57: Check if this is actually a WooCommerce page before fallback loading
		$is_wc_page = false;
		
		// Check 1: WooCommerce Body Classes
		$body_classes = get_body_class();
		$wc_classes = array(
			'woocommerce',
			'woocommerce-page',
			'woocommerce-cart',
			'woocommerce-checkout',
			'single-product',
			'post-type-archive-product',
			'tax-product_cat',
			'tax-product_tag'
		);
		
		foreach ( $wc_classes as $wc_class ) {
			if ( in_array( $wc_class, $body_classes, true ) ) {
				$is_wc_page = true;
				break;
			}
		}
		
		// Check 2: URL-based detection (fallback)
		if ( ! $is_wc_page ) {
			$request_uri = $_SERVER['REQUEST_URI'] ?? '';
			$wc_urls = array( '/warenkorb/', '/kasse/', '/shop/', '/produkt/', '/product/', '/cart/', '/checkout/' );
			
			foreach ( $wc_urls as $wc_url ) {
				if ( strpos( $request_uri, $wc_url ) !== false ) {
					$is_wc_page = true;
					break;
				}
			}
		}
		
		// Only load script if this is a WooCommerce page
		if ( ! $is_wc_page ) {
			// Not a WooCommerce page - skip loading
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AS-CAI v1.3.57] ⭕ Countdown script NOT loaded - non-WooCommerce page detected' );
			}
			return;
		}
		
		// Script was NOT enqueued but this IS a WooCommerce page - let's force load it!
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AS-CAI v1.3.57] ⚠️ Countdown script NOT enqueued - forcing load via fallback (WooCommerce page)!' );
		}
		
		// Force load countdown script
		$cache_buster = AS_CAI_VERSION . '-fallback-' . time();
		
		wp_enqueue_style(
			'as-cai-loop-styles-fallback',
			AS_CAI_PLUGIN_URL . 'assets/css/as-cai-frontend.css',
			array(),
			$cache_buster
		);
		
		wp_enqueue_script(
			'as-cai-loop-countdown',
			AS_CAI_PLUGIN_URL . 'assets/js/as-cai-loop-countdown.js',
			array( 'jquery' ),
			$cache_buster,
			true
		);
		
		// Add HTML comment to show fallback was used (no sensitive data).
		add_action( 'wp_footer', function() {
			echo "\n<!-- [AS-CAI] Countdown script loaded via fallback method -->\n";
		}, 999 );
		
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AS-CAI v1.3.57] ✅ Countdown script force-loaded via fallback method (WooCommerce page)' );
		}
	}

	/**
	 * Add counter before price on single product pages.
	 */
	public function add_counter_before_price() {
		// Only on single product pages, not on shop/archive pages.
		if ( ! is_product() ) {
			return;
		}

		global $product;

		// Fallback: Versuche Produkt über get_the_ID() zu holen.
		if ( ! $product || ! is_object( $product ) ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
			return;
		}

		// Show counter for all product types, not just 'auditorium'.
		// This allows the counter to work with simple, variable, and other product types.

		$this->render_availability_counter( $product->get_id(), 'price' );
	}

	/**
	 * Add counter before add to cart button (works with Elementor templates).
	 */
	public function add_counter_before_button() {
		// Only on single product pages, not on shop/archive pages.
		if ( ! is_product() ) {
			return;
		}

		global $product;

		// Fallback: Versuche Produkt über get_the_ID() zu holen.
		if ( ! $product || ! is_object( $product ) ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
			return;
		}

		// Show counter for all product types, not just 'auditorium'.
		// This ensures compatibility with Elementor templates for all product types.

		$this->render_availability_counter( $product->get_id(), 'button' );
	}

	/**
	 * Maybe hide the Seat Planner button if product is not available or already in cart.
	 * v1.3.10: Fixed button visibility - now explicitly shows button when available
	 * v1.3.11: Prevent multiple executions and remove conflicting classes
	 */
	public function maybe_hide_seat_planner_button() {
		// Only on single product pages, not on shop/archive pages.
		if ( ! is_product() ) {
			return;
		}

		global $product;

		// Fallback: Versuche Produkt über get_the_ID() zu holen.
		if ( ! $product || ! is_object( $product ) ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product || ! method_exists( $product, 'get_type' ) ) {
			return;
		}

		if ( ! in_array( $product->get_type(), array( 'auditorium', 'simple' ), true ) ) {
			return;
		}

		// v1.3.11: Prevent multiple executions
		static $already_run = false;
		if ( $already_run ) {
			return;
		}
		$already_run = true;

		$product_id = $product->get_id();
		$availability = AS_CAI_Availability_Check::get_product_availability( $product_id );
		
		$should_hide = false;

		// Hide if not available (timer hasn't expired yet).
		if ( ! $availability['is_available'] ) {
			$should_hide = true;
		}

		// v1.3.77: Hide if sold out — uses dual-source status (Stachethemes / WC Stock).
		if ( ! $should_hide ) {
			$status_data = AS_CAI_Status_Display::get_detailed_availability_status( $product_id );
			if ( $status_data && in_array( $status_data['status'], array( 'sold_out', 'reserved_full' ), true ) ) {
				$should_hide = true;
			}
		}

		// Hide if already in cart (v1.3.6 - prevent duplicate bookings).
		if ( ! $should_hide && WC()->cart ) {
			foreach ( WC()->cart->get_cart() as $cart_item ) {
				if ( $cart_item['product_id'] == $product_id ) {
					$should_hide = true;
					break;
				}
			}
		}

		// v1.3.62: Hide/show seat planner button wrapper, root, and status box.
		// All selection elements are hidden until the countdown expires.
		?>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			// Auditorium (Stachethemes) buttons.
			var $wrapper = $('.stachesepl-single-add-to-cart-button-wrapper');
			var $root = $('.stachesepl-add-to-cart-button-root');
			// Simple (WooCommerce) button.
			var $wcButton = $('form.cart .single_add_to_cart_button');
			var $statusBox = $('.as-cai-status-box');

			var $buttons = $wrapper.add($root).add($wcButton);
			var $all = $buttons.add($statusBox);
			$all.removeClass('as-cai-button-hidden as-cai-button-visible');

			<?php if ( $should_hide ) : ?>
			$buttons.addClass('as-cai-button-hidden');
			// Status-Box nur verstecken wenn NICHT in einer BuyBox.
			$statusBox.each(function() {
				if (!$(this).closest('.as-cai-buybox').length) {
					$(this).addClass('as-cai-button-hidden');
				}
			});
			// Add-to-Cart Wrapper in BuyBox auch verstecken.
			$('.as-cai-buybox .as-cai-add-to-cart-wrapper').hide();
			<?php else : ?>
			$wrapper.addClass('as-cai-button-visible').css('display', 'block');
			$root.addClass('as-cai-button-visible').css('display', '');
			$wcButton.addClass('as-cai-button-visible').css('display', '');
			$statusBox.addClass('as-cai-button-visible').css('display', '');
			<?php endif; ?>
		});
		</script>
		<style>
			.stachesepl-single-add-to-cart-button-wrapper.as-cai-button-hidden,
			.stachesepl-add-to-cart-button-root.as-cai-button-hidden,
			.single_add_to_cart_button.as-cai-button-hidden,
			.as-cai-status-box.as-cai-button-hidden {
				display: none !important;
			}
			.stachesepl-single-add-to-cart-button-wrapper.as-cai-button-visible {
				display: block !important;
			}
		</style>
		<?php
	}

	/**
	 * Render the availability counter.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $location   Location where counter is rendered ('price' or 'button').
	 */
	private function render_availability_counter( $product_id, $location = 'price' ) {
		// Prevent duplicate counter output.
		static $counter_rendered = false;

		if ( $counter_rendered ) {
			return;
		}

		$debug = AS_CAI_Debug::instance();
		$debug->log( 'render_availability_counter() called', 'info', array( 
			'product_id' => $product_id,
			'location'   => $location,
		) );

		$availability = AS_CAI_Availability_Check::get_product_availability( $product_id );

		$debug->log( 'Availability data retrieved', 'info', $availability );

		if ( ! $availability['has_counter'] ) {
			$debug->log( 'Counter not shown: has_counter is false', 'warning' );
			return;
		}

		// Get WordPress timezone object for proper datetime calculations.
		$wp_timezone = wp_timezone();

		// Get current date, time and timestamp using WordPress timezone with DateTime.
		// This ensures consistency with start/end timestamp calculations below.
		try {
			$current_datetime_obj = new DateTime( 'now', $wp_timezone );
			$current_timestamp    = $current_datetime_obj->getTimestamp();
			$current_datetime     = $current_datetime_obj->format( 'Y-m-d H:i:s' );
			$current_date         = $current_datetime_obj->format( 'Y-m-d' );
		} catch ( Exception $e ) {
			// Fallback to current_time() if DateTime fails.
			$current_datetime  = current_time( 'Y-m-d H:i:s' );
			$current_date      = current_time( 'Y-m-d' );
			$current_timestamp = time();
		}

		// Get start and end timestamps using WordPress timezone.
		$start_datetime = $availability['start_date'] . ' ' . $availability['start_time'];
		try {
			$start_datetime_obj = new DateTime( $start_datetime, $wp_timezone );
			$start_timestamp    = $start_datetime_obj->getTimestamp();
		} catch ( Exception $e ) {
			$start_timestamp = strtotime( $start_datetime );
		}

		$end_datetime = $availability['end_date'] . ' ' . $availability['end_time'];
		try {
			$end_datetime_obj = new DateTime( $end_datetime, $wp_timezone );
			$end_timestamp    = $end_datetime_obj->getTimestamp();
		} catch ( Exception $e ) {
			$end_timestamp = strtotime( $end_datetime );
		}

		$counter_display = ! empty( $availability['counter_display'] ) ? $availability['counter_display'] : '';

		$debug->log(
			'Time comparison data',
			'info',
			array(
				'current_datetime'    => $current_datetime,
				'current_timestamp'   => $current_timestamp,
				'start_datetime'      => $start_datetime,
				'start_timestamp'     => $start_timestamp,
				'end_datetime'        => $end_datetime,
				'end_timestamp'       => $end_timestamp,
				'counter_display'     => $counter_display,
			)
		);

		// Determine if counter should be displayed based on counter_display setting.
		$should_display = false;

		// Product-level settings.
		if ( 'avail_bfr_prod' === $counter_display || 'unavail_bfr_prod' === $counter_display ) {
			// Display BEFORE product becomes available.
			$should_display = $current_timestamp < $start_timestamp;
			$debug->log(
				'Counter display check: BEFORE mode (product-level)',
				'info',
				array(
					'mode'           => $counter_display,
					'should_display' => $should_display ? 'YES' : 'NO',
					'check'          => $current_timestamp . ' < ' . $start_timestamp,
					'result'         => ( $current_timestamp < $start_timestamp ) ? 'true' : 'false',
				)
			);
		} elseif ( 'avail_dur_prod' === $counter_display || 'unavail_dur_prod' === $counter_display ) {
			// Display DURING product availability window.
			$should_display = ( $current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp );
			$debug->log(
				'Counter display check: DURING mode (product-level)',
				'info',
				array(
					'mode'           => $counter_display,
					'should_display' => $should_display ? 'YES' : 'NO',
					'timestamp_check' => $current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp,
				)
			);
		} elseif ( 'avail_bfr_aftr_prod_both' === $counter_display ) {
			// Display BOTH before and during.
			$should_display = ( $current_timestamp < $end_timestamp );
			$debug->log( 'Counter display check: BOTH mode (product-level) - display until end', 'info', array( 'should_display' => $should_display ? 'YES' : 'NO' ) );
		}

		// Rule-level settings.
		if ( 'aps_before_prod_avail' === $counter_display || 'aps_before_prod_unavail' === $counter_display ) {
			// Display BEFORE product becomes available.
			$should_display = $current_timestamp < $start_timestamp;
			$debug->log(
				'Counter display check: BEFORE mode (rule-level)',
				'info',
				array(
					'mode'           => $counter_display,
					'should_display' => $should_display ? 'YES' : 'NO',
				)
			);
		} elseif ( 'aps_dur_prod_avail' === $counter_display || 'aps_dur_prod_unavail' === $counter_display ) {
			// Display DURING product availability window.
			$should_display = ( $current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp );
			$debug->log(
				'Counter display check: DURING mode (rule-level)',
				'info',
				array(
					'mode'           => $counter_display,
					'should_display' => $should_display ? 'YES' : 'NO',
				)
			);
		} elseif ( 'aps_both_bfr_aftr' === $counter_display ) {
			// Display BOTH before and during.
			$should_display = ( $current_timestamp < $end_timestamp );
			$debug->log( 'Counter display check: BOTH mode (rule-level) - display until end', 'info', array( 'should_display' => $should_display ? 'YES' : 'NO' ) );
		}

		if ( ! $should_display ) {
			$debug->log( 'Counter not shown: should_display is false after time checks', 'warning', array(
				'current_timestamp' => $current_timestamp,
				'start_timestamp'   => $start_timestamp,
				'counter_display'   => $counter_display,
			) );
			return;
		}

		$debug->log( 'Counter WILL BE DISPLAYED - rendering HTML', 'success' );

		// Calculate target timestamp for countdown.
		$target_timestamp = $start_timestamp;

		?>
		<div class="as-cai-availability-counter-wrapper" 
			data-target-timestamp="<?php echo esc_attr( $target_timestamp ); ?>"
			data-text-before="<?php echo esc_attr( $availability['text_before'] ); ?>"
			data-text-after="<?php echo esc_attr( $availability['text_after'] ); ?>">
			
			<?php if ( ! empty( $availability['text_before'] ) ) : ?>
				<div class="as-cai-counter-text-before">
					<?php echo esc_html( $availability['text_before'] ); ?>
				</div>
			<?php endif; ?>

			<div class="as-cai-countdown-timer">
				<div class="as-cai-countdown-unit">
					<span class="as-cai-countdown-value" data-unit="days">0</span>
					<span class="as-cai-countdown-label">Tage</span>
				</div>
				<div class="as-cai-countdown-separator">:</div>
				<div class="as-cai-countdown-unit">
					<span class="as-cai-countdown-value" data-unit="hours">0</span>
					<span class="as-cai-countdown-label">Std</span>
				</div>
				<div class="as-cai-countdown-separator">:</div>
				<div class="as-cai-countdown-unit">
					<span class="as-cai-countdown-value" data-unit="minutes">0</span>
					<span class="as-cai-countdown-label">Min</span>
				</div>
				<div class="as-cai-countdown-separator">:</div>
				<div class="as-cai-countdown-unit">
					<span class="as-cai-countdown-value" data-unit="seconds">0</span>
					<span class="as-cai-countdown-label">Sek</span>
				</div>
			</div>

			<?php if ( ! empty( $availability['text_after'] ) ) : ?>
				<div class="as-cai-counter-text-after">
					<?php echo esc_html( $availability['text_after'] ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php

		$debug->log( 'Counter HTML rendered successfully', 'success' );
		
		// Mark counter as rendered to prevent duplicates.
		$counter_rendered = true;
	}

	/**
	 * Shortcode for displaying the availability counter.
	 *
	 * Usage: [as_cai_availability_counter]
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function shortcode_availability_counter( $atts ) {
		global $product;

		// Fallback: Versuche Produkt über get_the_ID() zu holen.
		if ( ! $product || ! is_object( $product ) ) {
			$product = wc_get_product( get_the_ID() );
		}

		if ( ! $product || ! method_exists( $product, 'get_id' ) ) {
			return '';
		}

		ob_start();
		$this->render_availability_counter( $product->get_id(), 'shortcode' );
		return ob_get_clean();
	}

	/**
	 * Hide stock display on product detail pages.
	 * 
	 * Stock information is irrelevant when availability system is active.
	 * The button visibility already indicates availability.
	 * 
	 * @since 1.3.37
	 * @param string     $html    Stock HTML.
	 * @param WC_Product $product Product object.
	 * @return string Empty string to hide stock, original HTML otherwise.
	 */
	public function hide_stock_display( $html, $product ) {
		// Only hide on single product pages
		if ( ! is_product() ) {
			return $html;
		}

		// Check if our availability system is enabled for this product
		$product_id = $product->get_id();
		$enabled = get_post_meta( $product_id, '_as_cai_availability_enabled', true );

		// If our system is active, hide stock display
		if ( $enabled === 'yes' ) {
			return '';
		}

		// Otherwise, show default stock HTML
		return $html;
	}

	/**
	 * Customize loop button for unavailable products on category pages.
	 * 
	 * When product is not yet available, show a disabled button with countdown
	 * in short format (1T 2S 3M 4S) instead of "Read more" or "Add to cart".
	 * 
	 * @since 1.3.37
	 * @since 1.3.38 Fixed timezone handling for accurate countdown
	 * @param string     $html    Button HTML.
	 * @param WC_Product $product Product object.
	 * @return string Modified button HTML.
	 */
	public function customize_loop_button( $html, $product ) {
		// Only on category/archive pages, not on single product pages
		if ( is_product() ) {
			return $html;
		}

		$product_id = $product->get_id();
		
		// Check if our availability system is enabled for this product
		$enabled = get_post_meta( $product_id, '_as_cai_availability_enabled', true );
		
		if ( $enabled !== 'yes' ) {
			return $html; // Not using our system
		}

		// Check if product is available
		if ( class_exists( 'AS_CAI_Product_Availability' ) ) {
			$availability_manager = AS_CAI_Product_Availability::instance();
			$is_available = $availability_manager->is_product_available( $product_id );
			
			if ( ! $is_available ) {
				// Get availability data for countdown
				$availability_data = $availability_manager->get_availability_data( $product_id );
				
				if ( $availability_data && isset( $availability_data['start_timestamp'] ) ) {
					$start_timestamp = $availability_data['start_timestamp'];
					
					// Use current_timestamp from availability_data for consistency (v1.3.38 fix)
					// This ensures both timestamps use the same timezone calculation
					$current_timestamp = isset( $availability_data['current_timestamp'] ) 
						? $availability_data['current_timestamp'] 
						: time();
					
					$seconds = max( 0, $start_timestamp - $current_timestamp );
					
					// Calculate countdown in short format
					$days = floor( $seconds / 86400 );
					$hours = floor( ( $seconds % 86400 ) / 3600 );
					$minutes = floor( ( $seconds % 3600 ) / 60 );
					$secs = $seconds % 60;
					
					// Build short countdown text
					$countdown_text = '';
					if ( $days > 0 ) {
						$countdown_text .= $days . 'T ';
					}
					if ( $hours > 0 || $days > 0 ) {
						$countdown_text .= $hours . 'S ';
					}
					if ( $minutes > 0 || $hours > 0 || $days > 0 ) {
						$countdown_text .= $minutes . 'M ';
					}
					$countdown_text .= $secs . 'S';
					
					$countdown_text = trim( $countdown_text );
					
					// Create disabled button with countdown and data attributes for JavaScript
					$classes = 'button product_type_' . $product->get_type() . ' as-cai-loop-button-disabled';
					
					$html = sprintf(
						'<a href="%s" class="%s" data-target-timestamp="%d" data-product-id="%d" aria-disabled="true" style="opacity: 0.5; cursor: not-allowed; pointer-events: none;">%s</a>',
						esc_url( $product->get_permalink() ),
						esc_attr( $classes ),
						esc_attr( $start_timestamp ),
						esc_attr( $product_id ),
						esc_html( $countdown_text )
					);
				}
			}
		}

		return $html;
	}
}
