<?php
/**
 * Plugin Name:       Camp Availability Integration
 * Plugin URI:        https://ayon.to
 * Description:       Integriert den Availability Scheduler Timer mit dem Stachethemes Seat Planner für Camp-Buchungen. Steuert die Anzeige des Parzellen-Auswahl-Buttons basierend auf dem Availability Timer. Inkl. 5-Minuten-Warenkorb-Reservierung und modernes Admin-Dashboard.
 * Version:           1.3.61
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Marc Mirschel
 * Author URI:        https://marc.mirschel.biz
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       as-camp-availability-integration
 * Domain Path:       /languages
 * Requires Plugins:  woocommerce, stachethemes-seat-planner
 *
 * @package AS_Camp_Availability_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class AS_Camp_Availability_Integration {

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
    /**
     * Current plugin version.
     *
     * The patch version is incremented when releasing a hotfix or bug fix.
     * See the accompanying UPDATE files for a detailed changelog of what
     * changed in each release.
     *
     * @since 1.3.58
     * @var string
     */
    const VERSION = '1.3.61';

	/**
	 * Plugin instance.
	 *
	 * @var AS_Camp_Availability_Integration|null
	 */
	private static $instance = null;

	/**
	 * Get plugin instance.
	 *
	 * @return AS_Camp_Availability_Integration
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
		$this->define_constants();
		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Define plugin constants.
	 */
	private function define_constants() {
		define( 'AS_CAI_VERSION', self::VERSION );
		define( 'AS_CAI_PLUGIN_FILE', __FILE__ );
		define( 'AS_CAI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
		define( 'AS_CAI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
		define( 'AS_CAI_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
	}

	/**
	 * Include required files.
	 */
	private function includes() {
		// Core functionality
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-timezone.php';
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-debug.php';
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-frontend.php';
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-availability-check.php';

		// Admin interface (v1.3.0)
		if ( is_admin() ) {
			require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-admin.php';
			require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-markdown-parser.php';
			// Booking Dashboard (v1.3.42)
			require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-booking-dashboard.php';
		}
		
		// Order Confirmation Shortcode (v1.3.42)
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-order-confirmation.php';

		// Cart Reservation System (v1.3.0)
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-reservation-db.php';
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-reservation-session.php';
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-cart-reservation.php';
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-reservation-cron.php';
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-cart-countdown.php';
		
		// Product Availability System (v1.3.30)
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-product-availability.php';
		
		// Debug & Testing Tools (v1.3.14)
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-logger.php';
		
		// SECURITY v1.3.56: Rate Limiter (prevents DoS attacks)
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-rate-limiter.php';
		
		if ( is_admin() ) {
			require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-debug-panel.php';
			require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-test-suite.php';
		}

		// Advanced Debug System (v1.3.28)
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-advanced-debug.php';

		// Translation Override for Stachethemes (v1.3.59)
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-translation-override.php';

		// Status Display System (v1.3.59)
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-status-display.php';

		// GitHub Auto-Updater (v1.3.59)
		require_once AS_CAI_PLUGIN_DIR . 'includes/class-as-cai-github-updater.php';
	}

	/**
	 * Initialize hooks.
	 */
	private function init_hooks() {
		add_action( 'plugins_loaded', array( $this, 'check_dependencies' ), 5 );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		
		// Auto-complete orders when fully paid (v1.3.53)
		add_action( 'woocommerce_payment_complete', array( $this, 'auto_complete_paid_order' ), 10, 1 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'auto_complete_on_status_change' ), 10, 4 );
	}

	/**
	 * Check if required plugins are active.
	 */
	public function check_dependencies() {
		$missing_plugins = array();
		$optional_plugins = array();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing_plugins[] = 'WooCommerce';
		}

		// v1.3.30: Koalaapps Scheduler is now OPTIONAL
		// Our plugin now has its own availability system
		if ( ! class_exists( 'Koala_Availability_Scheduler_For_Woocommerce' ) ) {
			$optional_plugins[] = 'Product Availability Scheduler (Koalaapps) - Optional, wir haben jetzt ein eigenes System';
		}

		if ( ! class_exists( 'Stachethemes\SeatPlanner\Stachethemes_Seat_Planner' ) ) {
			$missing_plugins[] = 'Stachethemes Seat Planner';
		}

		// Show critical error for missing required plugins
		if ( ! empty( $missing_plugins ) ) {
			add_action( 'admin_notices', function() use ( $missing_plugins ) {
				$this->display_missing_plugins_notice( $missing_plugins );
			} );
			return;
		}

		// Show info notice for optional plugins
		if ( ! empty( $optional_plugins ) ) {
			add_action( 'admin_notices', function() use ( $optional_plugins ) {
				$this->display_optional_plugins_notice( $optional_plugins );
			} );
		}

		// Initialize debug functionality.
		AS_CAI_Debug::instance();
		
		// Initialize Logger (v1.3.14).
		AS_CAI_Logger::instance();
		
		// SECURITY v1.3.56: Initialize Rate Limiter (prevents DoS attacks)
		AS_CAI_Rate_Limiter::instance();

		// Initialize Advanced Debug (v1.3.28).
		AS_CAI_Advanced_Debug::instance();

		// Initialize Product Availability System (v1.3.30).
		AS_CAI_Product_Availability::instance();

		// Initialize frontend functionality.
		AS_CAI_Frontend::instance();

		// Initialize admin interface (v1.3.0).
		if ( is_admin() ) {
			AS_CAI_Admin::instance();
			AS_CAI_Debug_Panel::instance();
			AS_CAI_Test_Suite::instance();
			// Initialize Booking Dashboard (v1.3.42)
			AS_CAI_Booking_Dashboard::instance();
		}

		// Initialize Order Confirmation Shortcode (v1.3.42)
		AS_CAI_Order_Confirmation::instance();

		// Initialize cart reservation system (v1.3.0).
		AS_CAI_Reservation_DB::instance();
		AS_CAI_Cart_Reservation::instance();
		AS_CAI_Reservation_Cron::instance();
		AS_CAI_Cart_Countdown::instance();

		// Initialize Translation Override for Stachethemes (v1.3.59).
		AS_CAI_Translation_Override::instance();

		// Initialize Status Display System (v1.3.59).
		AS_CAI_Status_Display::instance();

		// Initialize GitHub Auto-Updater (v1.3.59).
		AS_CAI_GitHub_Updater::instance();
	}

	/**
	 * Display admin notice for missing plugins.
	 *
	 * @param array $missing_plugins Array of missing plugin names.
	 */
	private function display_missing_plugins_notice( $missing_plugins ) {
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Camp Availability Integration', 'as-camp-availability-integration' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated list of missing plugin names */
					esc_html__( 'Folgende Plugins werden benötigt: %s', 'as-camp-availability-integration' ),
					'<strong>' . esc_html( implode( ', ', $missing_plugins ) ) . '</strong>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Display admin notice for optional plugins.
	 *
	 * @param array $optional_plugins Array of optional plugin names.
	 * @since 1.3.30
	 */
	private function display_optional_plugins_notice( $optional_plugins ) {
		// Only show once per session
		if ( get_transient( 'as_cai_optional_plugins_notice_shown' ) ) {
			return;
		}
		set_transient( 'as_cai_optional_plugins_notice_shown', true, DAY_IN_SECONDS );
		
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Camp Availability Integration - Info', 'as-camp-availability-integration' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Das Plugin hat jetzt ein eigenes Availability-System!', 'as-camp-availability-integration' ); ?>
			</p>
			<p>
				<?php
				printf(
					/* translators: %s: comma-separated list of optional plugin names */
					esc_html__( 'Folgende Plugins sind nicht mehr erforderlich: %s', 'as-camp-availability-integration' ),
					'<em>' . esc_html( implode( ', ', $optional_plugins ) ) . '</em>'
				);
				?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Nächste Schritte:', 'as-camp-availability-integration' ); ?></strong><br>
				<?php esc_html_e( '1. Produkte bearbeiten → Neue "Produkt-Verfügbarkeit (Camp)" Meta-Box verwenden', 'as-camp-availability-integration' ); ?><br>
				<?php esc_html_e( '2. Start-Datum & Zeit eingeben', 'as-camp-availability-integration' ); ?><br>
				<?php esc_html_e( '3. Fertig! Der Button wird automatisch gesteuert.', 'as-camp-availability-integration' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Load plugin textdomain.
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'as-camp-availability-integration',
			false,
			dirname( AS_CAI_PLUGIN_BASENAME ) . '/languages'
		);
	}

	/**
	 * Auto-complete order when payment is completed.
	 * 
	 * This ensures that fully paid orders automatically receive the "completed" status
	 * without requiring manual intervention.
	 *
	 * @param int $order_id Order ID.
	 * @since 1.3.54
	 */
	public function auto_complete_paid_order( $order_id ) {
		if ( ! $order_id ) {
			return;
		}

		$order = wc_get_order( $order_id );
		
		if ( ! $order ) {
			return;
		}

		// Only auto-complete if order is fully paid and not already completed
		if ( $order->is_paid() && 'completed' !== $order->get_status() ) {
			// Set status to completed
			$order->update_status( 'completed', __( 'Automatisch abgeschlossen - Zahlung vollständig erhalten.', 'as-camp-availability-integration' ) );
			
			// Log the action
			if ( class_exists( 'AS_CAI_Logger' ) ) {
				AS_CAI_Logger::instance()->info( 
					'Auto-completed order #' . $order_id . ' after payment received'
				);
			}
		}
	}

	/**
	 * Auto-complete order when status changes to a paid status.
	 * 
	 * Catches status changes like processing → paid, ensuring the order
	 * is auto-completed if fully paid.
	 *
	 * @param int    $order_id   Order ID.
	 * @param string $old_status Old order status.
	 * @param string $new_status New order status.
	 * @param WC_Order $order     Order object.
	 * @since 1.3.54
	 */
	public function auto_complete_on_status_change( $order_id, $old_status, $new_status, $order ) {
		// Skip if already completed or cancelled/failed
		if ( in_array( $new_status, array( 'completed', 'cancelled', 'refunded', 'failed' ), true ) ) {
			return;
		}

		// Auto-complete if order is fully paid
		if ( $order && $order->is_paid() ) {
			$order->update_status( 'completed', __( 'Automatisch abgeschlossen - Zahlung vollständig erhalten.', 'as-camp-availability-integration' ) );
			
			// Log the action
			if ( class_exists( 'AS_CAI_Logger' ) ) {
				AS_CAI_Logger::instance()->info( 
					'Auto-completed order #' . $order_id . ' on status change from ' . $old_status . ' to ' . $new_status
				);
			}
		}
	}

	/**
	 * Declare HPOS compatibility.
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				AS_CAI_PLUGIN_FILE,
				true
			);
		}
	}
}

/**
 * Returns the main instance of AS_Camp_Availability_Integration.
 *
 * @return AS_Camp_Availability_Integration
 */
function AS_CAI() {
	return AS_Camp_Availability_Integration::instance();
}

// Initialize the plugin.
AS_CAI();
