<?php
/**
 * Admin Interface - Modern Dashboard and Settings
 *
 * @package AS_Camp_Availability_Integration
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AS_CAI_Admin class - Handles all admin functionality with modern UI.
 */
class AS_CAI_Admin {

	/**
	 * Instance of this class.
	 *
	 * @var AS_CAI_Admin|null
	 */
	private static $instance = null;

	/**
	 * Current active tab.
	 *
	 * @var string
	 */
	private $active_tab = 'dashboard';

	/**
	 * Get instance.
	 *
	 * @return AS_CAI_Admin
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
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'wp_ajax_as_cai_clear_reservations', array( $this, 'ajax_clear_reservations' ) );
		add_action( 'wp_ajax_as_cai_get_stats', array( $this, 'ajax_get_stats' ) );
	}

	/**
	 * Add admin menu and submenu pages.
	 */
	public function add_admin_menu() {
		// Main menu.
		add_menu_page(
			__( 'Ayonto Camp Availability', 'as-camp-availability-integration' ),
			__( 'Ayonto Camp Avail.', 'as-camp-availability-integration' ),
			'manage_woocommerce',
			'bg-camp-availability',
			array( $this, 'render_admin_page' ),
			'dashicons-tickets-alt',
			56
		);

		// Dashboard submenu.
		add_submenu_page(
			'bg-camp-availability',
			__( 'Dashboard', 'as-camp-availability-integration' ),
			__( 'Dashboard', 'as-camp-availability-integration' ),
			'manage_woocommerce',
			'bg-camp-availability',
			array( $this, 'render_admin_page' )
		);

		// Cart Reservations submenu.
		add_submenu_page(
			'bg-camp-availability',
			__( 'Cart Reservations', 'as-camp-availability-integration' ),
			__( 'Cart Reservations', 'as-camp-availability-integration' ),
			'manage_woocommerce',
			'bg-camp-availability-reservations',
			array( $this, 'render_admin_page' )
		);

		// Settings submenu.
		add_submenu_page(
			'bg-camp-availability',
			__( 'Settings', 'as-camp-availability-integration' ),
			__( 'Settings', 'as-camp-availability-integration' ),
			'manage_woocommerce',
			'bg-camp-availability-settings',
			array( $this, 'render_admin_page' )
		);

		// Test Suite submenu (v1.3.14).
		add_submenu_page(
			'bg-camp-availability',
			__( 'Test Suite', 'as-camp-availability-integration' ),
			__( 'Test Suite', 'as-camp-availability-integration' ),
			'manage_woocommerce',
			'bg-camp-availability-tests',
			array( $this, 'render_admin_page' )
		);

		// Documentation submenu.
		add_submenu_page(
			'bg-camp-availability',
			__( 'Documentation', 'as-camp-availability-integration' ),
			__( 'Documentation', 'as-camp-availability-integration' ),
			'manage_woocommerce',
			'bg-camp-availability-docs',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_assets( $hook ) {
		// Safety check: Only in admin.
		if ( ! is_admin() ) {
			return;
		}
		
		// Only load on our plugin pages.
		if ( strpos( $hook, 'bg-camp-availability' ) === false ) {
			return;
		}

		// Tailwind CSS (CDN).
		wp_enqueue_style(
			'as-cai-tailwind',
			'https://cdn.jsdelivr.net/npm/tailwindcss@3.4.0/dist/tailwind.min.css',
			array(),
			'3.4.0'
		);

		// Font Awesome (CDN).
		wp_enqueue_style(
			'as-cai-fontawesome',
			'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css',
			array(),
			'6.5.1'
		);

		// Custom admin CSS.
		wp_enqueue_style(
			'as-cai-admin',
			AS_CAI_PLUGIN_URL . 'assets/css/as-cai-admin.css',
			array(),
			AS_CAI_VERSION
		);

		// Chart.js (CDN) - Load early.
		wp_enqueue_script(
			'as-cai-chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js',
			array(),
			'4.4.1',
			true
		);

		// Custom admin JS - Load BEFORE Alpine.js to register asCaiAdminApp function.
		wp_enqueue_script(
			'as-cai-admin-js',
			AS_CAI_PLUGIN_URL . 'assets/js/as-cai-admin.js',
			array( 'jquery', 'as-cai-chartjs' ),
			AS_CAI_VERSION,
			false // Load in header, not footer, so function is available
		);

		// Alpine.js (CDN) - Load AFTER admin-js with defer.
		wp_enqueue_script(
			'as-cai-alpine',
			'https://cdn.jsdelivr.net/npm/alpinejs@3.13.3/dist/cdn.min.js',
			array( 'as-cai-admin-js' ),
			'3.13.3',
			true
		);
		// Add defer attribute to Alpine.js
		add_filter( 'script_loader_tag', function( $tag, $handle ) {
			if ( 'as-cai-alpine' === $handle ) {
				return str_replace( ' src', ' defer src', $tag );
			}
			return $tag;
		}, 10, 2 );

		// Localize script.
		wp_localize_script(
			'as-cai-admin-js',
			'asCaiAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'as_cai_admin_nonce' ),
				'i18n'      => array(
					'confirm_clear' => __( 'Are you sure you want to clear all reservations?', 'as-camp-availability-integration' ),
					'cleared'       => __( 'Reservations cleared successfully!', 'as-camp-availability-integration' ),
					'error'         => __( 'An error occurred. Please try again.', 'as-camp-availability-integration' ),
				),
			)
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		// General Settings.
		register_setting( 'as_cai_general_settings', 'as_cai_enable_countdown' );
		register_setting( 'as_cai_general_settings', 'as_cai_countdown_position' );
		register_setting( 'as_cai_general_settings', 'as_cai_countdown_style' );

		// Cart Reservation Settings.
		register_setting( 'as_cai_cart_settings', 'as_cai_enable_cart_reservation' );
		register_setting( 'as_cai_cart_settings', 'as_cai_reservation_time' );
		register_setting( 'as_cai_cart_settings', 'as_cai_show_cart_timer' );
		register_setting( 'as_cai_cart_settings', 'as_cai_cart_timer_style' );
		register_setting( 'as_cai_cart_settings', 'as_cai_warning_threshold' );

		// Debug Settings.
		register_setting( 'as_cai_debug_settings', 'as_cai_enable_debug' );
		register_setting( 'as_cai_debug_settings', 'as_cai_debug_log' );

		// Advanced Debug Settings (v1.3.28).
		register_setting( 'as_cai_advanced_debug_settings', 'as_cai_advanced_debug' );
		register_setting( 'as_cai_advanced_debug_settings', 'as_cai_debug_area_admin' );
		register_setting( 'as_cai_advanced_debug_settings', 'as_cai_debug_area_frontend' );
		register_setting( 'as_cai_advanced_debug_settings', 'as_cai_debug_area_cart' );
		register_setting( 'as_cai_advanced_debug_settings', 'as_cai_debug_area_database' );
		register_setting( 'as_cai_advanced_debug_settings', 'as_cai_debug_area_cron' );
		register_setting( 'as_cai_advanced_debug_settings', 'as_cai_debug_area_hooks' );
		register_setting( 'as_cai_advanced_debug_settings', 'as_cai_debug_area_performance' );
	}

	/**
	 * Render admin page based on current submenu.
	 */
	public function render_admin_page() {
		// Get current page from query string.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : 'bg-camp-availability';

		// Determine which tab to show.
		$tab_map = array(
			'bg-camp-availability'              => 'dashboard',
			'bg-camp-availability-settings'     => 'settings',
			'bg-camp-availability-reservations' => 'reservations',
			'bg-camp-availability-tests'        => 'tests',
			'bg-camp-availability-docs'         => 'docs',
		);

		$this->active_tab = isset( $tab_map[ $page ] ) ? $tab_map[ $page ] : 'dashboard';

		?>
		<div class="wrap as-cai-admin-wrap" x-data="asCaiAdminApp()">
			<?php $this->render_header(); ?>
			
			<div class="as-cai-admin-content">
				<?php
				switch ( $this->active_tab ) {
					case 'dashboard':
						$this->render_dashboard();
						break;
					case 'settings':
						$this->render_settings();
						break;
					case 'reservations':
						$this->render_reservations();
						break;
					case 'tests':
						AS_CAI_Test_Suite::instance()->render_page();
						break;
					case 'docs':
						$this->render_documentation();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render admin header with branding and navigation.
	 */
	private function render_header() {
		?>
		<div class="as-cai-header as-cai-fade-in">
			<div style="display: flex; align-items: center; justify-content: space-between;">
				<div style="display: flex; align-items: center; gap: 20px;">
					<i class="fas fa-campground" style="font-size: 3rem;"></i>
					<div>
						<h1>Ayonto Camp Availability Integration</h1>
						<p>
							<?php
							printf(
								/* translators: %s: plugin version */
								esc_html__( 'Version %s - Professional Camp Booking Management', 'as-camp-availability-integration' ),
								esc_html( AS_CAI_VERSION )
							);
							?>
						</p>
					</div>
				</div>
				<div style="text-align: right;">
					<div style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 5px;">
						<?php esc_html_e( 'Powered by', 'as-camp-availability-integration' ); ?>
					</div>
					<div style="font-size: 1.125rem; font-weight: 600;">ayon.to</div>
				</div>
			</div>
		</div>

		<!-- Tab Navigation -->
		<div class="as-cai-tabs as-cai-fade-in">
			<button class="as-cai-tab <?php echo esc_attr( 'dashboard' === $this->active_tab ? 'active' : '' ); ?>" 
			        onclick="window.location.href='<?php echo esc_url( admin_url( 'admin.php?page=bg-camp-availability' ) ); ?>'">
				<i class="fas fa-chart-line"></i>
				<?php esc_html_e( 'Dashboard', 'as-camp-availability-integration' ); ?>
			</button>
			<button class="as-cai-tab <?php echo esc_attr( 'reservations' === $this->active_tab ? 'active' : '' ); ?>" 
			        onclick="window.location.href='<?php echo esc_url( admin_url( 'admin.php?page=bg-camp-availability-reservations' ) ); ?>'">
				<i class="fas fa-list"></i>
				<?php esc_html_e( 'Reservations', 'as-camp-availability-integration' ); ?>
			</button>
			<button class="as-cai-tab <?php echo esc_attr( 'settings' === $this->active_tab ? 'active' : '' ); ?>" 
			        onclick="window.location.href='<?php echo esc_url( admin_url( 'admin.php?page=bg-camp-availability-settings' ) ); ?>'">
				<i class="fas fa-cog"></i>
				<?php esc_html_e( 'Settings', 'as-camp-availability-integration' ); ?>
			</button>
			<button class="as-cai-tab <?php echo esc_attr( 'tests' === $this->active_tab ? 'active' : '' ); ?>" 
			        onclick="window.location.href='<?php echo esc_url( admin_url( 'admin.php?page=bg-camp-availability-tests' ) ); ?>'">
				<i class="fas fa-vial"></i>
				<?php esc_html_e( 'Tests', 'as-camp-availability-integration' ); ?>
			</button>
			<button class="as-cai-tab <?php echo esc_attr( 'docs' === $this->active_tab ? 'active' : '' ); ?>" 
			        onclick="window.location.href='<?php echo esc_url( admin_url( 'admin.php?page=bg-camp-availability-docs' ) ); ?>'">
				<i class="fas fa-book"></i>
				<?php esc_html_e( 'Documentation', 'as-camp-availability-integration' ); ?>
			</button>
		</div>
		<?php
	}

	/**
	 * Render Dashboard tab.
	 */
	private function render_dashboard() {
		$stats = $this->get_dashboard_stats();
		?>
		<!-- Statistics Cards -->
		<div class="as-cai-stats-grid">
			<!-- Active Reservations Card -->
			<div class="as-cai-stat-card purple as-cai-fade-in">
				<div class="as-cai-stat-icon">
					<i class="fas fa-shopping-cart"></i>
				</div>
				<div class="as-cai-stat-value as-cai-count-up" x-text="stats.active_reservations">
					<?php echo esc_html( $stats['active_reservations'] ); ?>
				</div>
				<div class="as-cai-stat-label">
					<?php esc_html_e( 'Active Reservations', 'as-camp-availability-integration' ); ?>
				</div>
			</div>

			<!-- Reserved Products Card -->
			<div class="as-cai-stat-card green as-cai-fade-in" style="animation-delay: 0.1s;">
				<div class="as-cai-stat-icon">
					<i class="fas fa-box"></i>
				</div>
				<div class="as-cai-stat-value as-cai-count-up" x-text="stats.reserved_products">
					<?php echo esc_html( $stats['reserved_products'] ); ?>
				</div>
				<div class="as-cai-stat-label">
					<?php esc_html_e( 'Reserved Products', 'as-camp-availability-integration' ); ?>
				</div>
			</div>

			<!-- Expired Today Card -->
			<div class="as-cai-stat-card orange as-cai-fade-in" style="animation-delay: 0.2s;">
				<div class="as-cai-stat-icon">
					<i class="fas fa-clock"></i>
				</div>
				<div class="as-cai-stat-value as-cai-count-up" x-text="stats.expired_today">
					<?php echo esc_html( $stats['expired_today'] ); ?>
				</div>
				<div class="as-cai-stat-label">
					<?php esc_html_e( 'Expired Today', 'as-camp-availability-integration' ); ?>
				</div>
			</div>

			<!-- System Status Card -->
			<div class="as-cai-stat-card blue as-cai-fade-in" style="animation-delay: 0.3s;">
				<div class="as-cai-stat-icon">
					<i class="fas fa-heartbeat"></i>
				</div>
				<div class="as-cai-badge healthy" style="margin-top: 10px;">
					<i class="fas fa-check-circle"></i>
					<?php echo $stats['system_healthy'] ? esc_html__( 'Healthy', 'as-camp-availability-integration' ) : esc_html__( 'Issues', 'as-camp-availability-integration' ); ?>
				</div>
				<div class="as-cai-stat-label">
					<?php esc_html_e( 'System Status', 'as-camp-availability-integration' ); ?>
				</div>
			</div>
		</div>

		<!-- Quick Actions Bar -->
		<div class="as-cai-quick-actions as-cai-fade-in" style="animation-delay: 0.4s;">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bg-camp-availability-settings' ) ); ?>" 
			   class="as-cai-btn as-cai-btn-primary">
				<i class="fas fa-cog"></i>
				<?php esc_html_e( 'Configure Settings', 'as-camp-availability-integration' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bg-camp-availability-reservations' ) ); ?>" 
			   class="as-cai-btn as-cai-btn-success">
				<i class="fas fa-list"></i>
				<?php esc_html_e( 'View Reservations', 'as-camp-availability-integration' ); ?>
			</a>
			<button @click="clearAllReservations()" class="as-cai-btn as-cai-btn-danger">
				<i class="fas fa-trash"></i>
				<?php esc_html_e( 'Clear All Reservations', 'as-camp-availability-integration' ); ?>
			</button>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=bg-camp-availability-settings' ) ); ?>" 
			   class="as-cai-btn as-cai-btn-secondary">
				<i class="fas fa-cog"></i>
				<?php esc_html_e( 'Settings & Tools', 'as-camp-availability-integration' ); ?>
			</a>
		</div>

		<!-- Main Content Grid -->
		<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-bottom: 24px;">
			<!-- Chart Card -->
			<div class="as-cai-card as-cai-fade-in" style="animation-delay: 0.5s;">
				<div class="as-cai-card-header">
					<h2 class="as-cai-card-title">
						<i class="fas fa-chart-line"></i>
						<?php esc_html_e( 'Reservation Statistics (Last 7 Days)', 'as-camp-availability-integration' ); ?>
					</h2>
				</div>
				<div class="as-cai-card-body">
					<div class="as-cai-chart-container">
						<canvas id="reservationsChart"></canvas>
					</div>
				</div>
			</div>

			<!-- Recent Activity Card -->
			<div class="as-cai-card as-cai-fade-in" style="animation-delay: 0.6s;">
				<div class="as-cai-card-header">
					<h2 class="as-cai-card-title">
						<i class="fas fa-history"></i>
						<?php esc_html_e( 'Recent Activity', 'as-camp-availability-integration' ); ?>
					</h2>
				</div>
				<div class="as-cai-card-body">
					<div x-data="{ activities: [] }" x-init="loadRecentActivity()">
						<template x-if="activities.length === 0">
							<div class="as-cai-empty-state">
								<i class="fas fa-inbox"></i>
								<p><?php esc_html_e( 'No recent activity', 'as-camp-availability-integration' ); ?></p>
							</div>
						</template>
						<template x-if="activities.length > 0">
							<div style="max-height: 280px; overflow-y: auto;">
								<template x-for="activity in activities" :key="activity.id">
									<div style="padding: 12px; border-bottom: 1px solid var(--as-gray-200); display: flex; gap: 12px;">
										<div style="flex-shrink: 0;">
											<i class="fas fa-circle" style="font-size: 8px; color: var(--as-primary);"></i>
										</div>
										<div style="flex: 1;">
											<p style="margin: 0; font-size: 14px; color: var(--as-gray-700);" x-text="activity.message"></p>
											<p style="margin: 4px 0 0 0; font-size: 12px; color: var(--as-gray-500);" x-text="activity.time"></p>
										</div>
									</div>
								</template>
							</div>
						</template>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Settings tab (v1.3.23 - Modern Card Design).
	 */
	private function render_settings() {
		?>
		<div class="as-cai-card as-cai-fade-in" x-data="{ activeTab: 'general' }">
			<div class="as-cai-card-header">
				<h2 class="as-cai-card-title">
					<i class="fas fa-cog"></i>
					<?php esc_html_e( 'Plugin Settings & Tools', 'as-camp-availability-integration' ); ?>
				</h2>
			</div>

			<!-- Tab Navigation -->
			<div class="as-cai-card-body" style="padding: 0;">
				<div style="border-bottom: 2px solid var(--as-gray-200); background: var(--as-gray-50);">
					<nav style="display: flex; gap: 0; padding: 0 24px;">
						<button type="button" @click="activeTab = 'general'" 
						        :class="activeTab === 'general' ? 'as-cai-settings-tab-active' : 'as-cai-settings-tab'"
						        style="flex: 1; padding: 16px 20px; border: none; background: transparent; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border-bottom: 3px solid transparent;">
							<i class="fas fa-sliders-h"></i>
							<?php esc_html_e( 'General', 'as-camp-availability-integration' ); ?>
						</button>
						<button type="button" @click="activeTab = 'cart'" 
						        :class="activeTab === 'cart' ? 'as-cai-settings-tab-active' : 'as-cai-settings-tab'"
						        style="flex: 1; padding: 16px 20px; border: none; background: transparent; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border-bottom: 3px solid transparent;">
							<i class="fas fa-shopping-cart"></i>
							<?php esc_html_e( 'Cart Reservation', 'as-camp-availability-integration' ); ?>
						</button>
						<button type="button" @click="activeTab = 'debug_settings'" 
						        :class="activeTab === 'debug_settings' ? 'as-cai-settings-tab-active' : 'as-cai-settings-tab'"
						        style="flex: 1; padding: 16px 20px; border: none; background: transparent; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border-bottom: 3px solid transparent;">
							<i class="fas fa-bug"></i>
							<?php esc_html_e( 'Debug Settings', 'as-camp-availability-integration' ); ?>
						</button>
						<button type="button" @click="activeTab = 'debug_tools'" 
						        :class="activeTab === 'debug_tools' ? 'as-cai-settings-tab-active' : 'as-cai-settings-tab'"
						        style="flex: 1; padding: 16px 20px; border: none; background: transparent; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border-bottom: 3px solid transparent;">
							<i class="fas fa-tools"></i>
							<?php esc_html_e( 'Debug Tools', 'as-camp-availability-integration' ); ?>
						</button>
						<button type="button" @click="activeTab = 'advanced_debug'"
						        :class="activeTab === 'advanced_debug' ? 'as-cai-settings-tab-active' : 'as-cai-settings-tab'"
						        style="flex: 1; padding: 16px 20px; border: none; background: transparent; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border-bottom: 3px solid transparent;">
							<i class="fas fa-microscope"></i>
							<?php esc_html_e( 'Advanced Debug', 'as-camp-availability-integration' ); ?>
						</button>
					</nav>
				</div>

				<!-- Settings Form (for first 3 tabs and advanced debug) -->
				<form method="post" action="options.php" x-show="activeTab !== 'debug_tools'"
					<!-- General Settings Tab -->
					<div x-show="activeTab === 'general'" style="padding: 24px;">
						<?php settings_fields( 'as_cai_general_settings' ); ?>
						<?php $this->render_general_settings(); ?>
						<div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid var(--as-gray-200); display: flex; justify-content: flex-end;">
							<?php submit_button( __( 'Save Settings', 'as-camp-availability-integration' ), 'primary as-cai-btn as-cai-btn-primary', 'submit', false ); ?>
						</div>
					</div>

					<!-- Cart Reservation Settings Tab -->
					<div x-show="activeTab === 'cart'" x-cloak style="padding: 24px;">
						<?php settings_fields( 'as_cai_cart_settings' ); ?>
						<?php $this->render_cart_settings(); ?>
						<div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid var(--as-gray-200); display: flex; justify-content: flex-end;">
							<?php submit_button( __( 'Save Settings', 'as-camp-availability-integration' ), 'primary as-cai-btn as-cai-btn-primary', 'submit', false ); ?>
						</div>
					</div>

					<!-- Debug Settings Tab -->
					<div x-show="activeTab === 'debug_settings'" x-cloak style="padding: 24px;">
						<?php settings_fields( 'as_cai_debug_settings' ); ?>
						<?php $this->render_debug_settings(); ?>
						<div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid var(--as-gray-200); display: flex; justify-content: flex-end;">
							<?php submit_button( __( 'Save Settings', 'as-camp-availability-integration' ), 'primary as-cai-btn as-cai-btn-primary', 'submit', false ); ?>
						</div>
					</div>

					<!-- Advanced Debug Tab (v1.3.28) -->
					<div x-show="activeTab === 'advanced_debug'" x-cloak style="padding: 24px;">
						<?php settings_fields( 'as_cai_advanced_debug_settings' ); ?>
						<?php $this->render_advanced_debug_settings(); ?>
						<div style="margin-top: 24px; padding-top: 24px; border-top: 2px solid var(--as-gray-200); display: flex; justify-content: flex-end;">
							<?php submit_button( __( 'Save Settings', 'as-camp-availability-integration' ), 'primary as-cai-btn as-cai-btn-primary', 'submit', false ); ?>
						</div>
					</div>
				</form>

				<!-- Debug Tools Tab (No form - direct tools) -->
				<div x-show="activeTab === 'debug_tools'" x-cloak style="padding: 24px; background: var(--as-gray-50);">
					<?php
					// Render Debug Panel directly
					if ( class_exists( 'AS_CAI_Debug_Panel' ) ) {
						AS_CAI_Debug_Panel::instance()->render_page();
					}
					?>
				</div>
			</div>
		</div>

		<style>
		.as-cai-settings-tab {
			color: var(--as-gray-600);
		}
		.as-cai-settings-tab:hover {
			color: var(--as-primary);
			background: var(--as-gray-100);
		}
		.as-cai-settings-tab-active {
			color: var(--as-primary) !important;
			border-bottom-color: var(--as-primary) !important;
			background: white !important;
		}
		
		/* Settings Sections */
		.as-cai-settings-section {
			padding: 0;
		}
		.as-cai-settings-row {
			display: flex;
			align-items: flex-start;
			gap: 16px;
			padding: 20px;
			border-bottom: 1px solid var(--as-gray-200);
		}
		.as-cai-settings-row:last-child {
			border-bottom: none;
		}
		.as-cai-settings-label {
			flex: 1;
		}
		.as-cai-settings-label strong {
			display: block;
			color: var(--as-gray-900);
			font-weight: 600;
			margin-bottom: 4px;
		}
		.as-cai-settings-label p {
			margin: 0;
			color: var(--as-gray-600);
			font-size: 0.875rem;
		}
		
		/* Toggle Switch */
		.as-cai-switch {
			position: relative;
			display: inline-block;
			width: 44px;
			height: 24px;
			flex-shrink: 0;
			margin-top: 3px;
		}
		.as-cai-switch input {
			opacity: 0;
			width: 0;
			height: 0;
		}
		.as-cai-slider {
			position: absolute;
			cursor: pointer;
			top: 0;
			left: 0;
			right: 0;
			bottom: 0;
			background-color: var(--as-gray-300);
			transition: .4s;
			border-radius: 24px;
		}
		.as-cai-slider:before {
			position: absolute;
			content: "";
			height: 18px;
			width: 18px;
			left: 3px;
			bottom: 3px;
			background-color: white;
			transition: .4s;
			border-radius: 50%;
		}
		.as-cai-switch input:checked + .as-cai-slider {
			background-color: var(--as-primary);
		}
		.as-cai-switch input:checked + .as-cai-slider:before {
			transform: translateX(20px);
		}
		
		/* Form Controls */
		.as-cai-select,
		.as-cai-input {
			padding: 8px 12px;
			border: 1px solid var(--as-gray-300);
			border-radius: 6px;
			font-size: 14px;
			color: var(--as-gray-900);
			background: white;
			transition: all 0.2s;
		}
		.as-cai-select:focus,
		.as-cai-input:focus {
			outline: none;
			border-color: var(--as-primary);
			box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
		}
		.as-cai-select {
			min-width: 200px;
		}
		
		/* Info/Warning Boxes */
		.as-cai-info-box {
			background: rgba(59, 130, 246, 0.1);
			border-left: 4px solid var(--as-info);
			padding: 16px;
			border-radius: 6px;
		}
		.as-cai-warning-box {
			background: rgba(245, 158, 11, 0.1);
			border-left: 4px solid var(--as-warning);
			padding: 16px;
			border-radius: 6px;
		}
		</style>
		<?php
	}

	/**
	 * Render general settings fields.
	 */
	private function render_general_settings() {
		$enable_countdown    = get_option( 'as_cai_enable_countdown', 'yes' );
		$countdown_position  = get_option( 'as_cai_countdown_position', 'before_add_to_cart' );
		$countdown_style     = get_option( 'as_cai_countdown_style', 'default' );
		?>
		<div class="as-cai-settings-section">
			<h3 style="font-size: 1.125rem; font-weight: 600; color: var(--as-gray-900); margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
				<i class="fas fa-clock" style="color: var(--as-primary);"></i>
				<?php esc_html_e( 'Countdown Timer Settings', 'as-camp-availability-integration' ); ?>
			</h3>

			<!-- Enable Countdown -->
			<div class="as-cai-settings-row">
				<label class="as-cai-switch">
					<input type="checkbox" name="as_cai_enable_countdown" value="yes" <?php checked( $enable_countdown, 'yes' ); ?>>
					<span class="as-cai-slider"></span>
				</label>
				<div class="as-cai-settings-label">
					<strong><?php esc_html_e( 'Enable Countdown Timer', 'as-camp-availability-integration' ); ?></strong>
					<p><?php esc_html_e( 'Show countdown timer on product pages', 'as-camp-availability-integration' ); ?></p>
				</div>
			</div>

			<!-- Position -->
			<div class="as-cai-settings-row">
				<div class="as-cai-settings-label">
					<label for="as_cai_countdown_position"><strong><?php esc_html_e( 'Countdown Position', 'as-camp-availability-integration' ); ?></strong></label>
					<p><?php esc_html_e( 'Where to display the countdown timer on product pages', 'as-camp-availability-integration' ); ?></p>
				</div>
				<select name="as_cai_countdown_position" id="as_cai_countdown_position" class="as-cai-select">
					<option value="before_add_to_cart" <?php selected( $countdown_position, 'before_add_to_cart' ); ?>>
						<?php esc_html_e( 'Before Add to Cart Button', 'as-camp-availability-integration' ); ?>
					</option>
					<option value="after_add_to_cart" <?php selected( $countdown_position, 'after_add_to_cart' ); ?>>
						<?php esc_html_e( 'After Add to Cart Button', 'as-camp-availability-integration' ); ?>
					</option>
					<option value="before_product_meta" <?php selected( $countdown_position, 'before_product_meta' ); ?>>
						<?php esc_html_e( 'Before Product Meta', 'as-camp-availability-integration' ); ?>
					</option>
				</select>
			</div>

			<!-- Style -->
			<div class="as-cai-settings-row">
				<div class="as-cai-settings-label">
					<label for="as_cai_countdown_style"><strong><?php esc_html_e( 'Countdown Style', 'as-camp-availability-integration' ); ?></strong></label>
					<p><?php esc_html_e( 'Visual style of the countdown timer', 'as-camp-availability-integration' ); ?></p>
				</div>
				<select name="as_cai_countdown_style" id="as_cai_countdown_style" class="as-cai-select">
					<option value="default" <?php selected( $countdown_style, 'default' ); ?>>
						<?php esc_html_e( 'Default', 'as-camp-availability-integration' ); ?>
					</option>
					<option value="minimal" <?php selected( $countdown_style, 'minimal' ); ?>>
						<?php esc_html_e( 'Minimal', 'as-camp-availability-integration' ); ?>
					</option>
					<option value="bold" <?php selected( $countdown_style, 'bold' ); ?>>
						<?php esc_html_e( 'Bold', 'as-camp-availability-integration' ); ?>
					</option>
				</select>
			</div>
		</div>
		<?php
	}

	/**
	 * Render cart reservation settings fields.
	 */
	private function render_cart_settings() {
		$enable_cart_reservation = get_option( 'as_cai_enable_cart_reservation', 'yes' );
		$reservation_time        = get_option( 'as_cai_reservation_time', '5' );
		$show_cart_timer         = get_option( 'as_cai_show_cart_timer', 'yes' );
		$cart_timer_style        = get_option( 'as_cai_cart_timer_style', 'full' );
		$warning_threshold       = get_option( 'as_cai_warning_threshold', '1' );
		?>
		<div class="as-cai-info-box" style="margin-bottom: 24px;">
			<div style="display: flex; align-items: flex-start; gap: 12px;">
				<i class="fas fa-info-circle" style="color: var(--as-info); font-size: 1.25rem; margin-top: 2px;"></i>
				<div>
					<strong style="color: var(--as-gray-900); display: block; margin-bottom: 4px;">
						<?php esc_html_e( 'Cart Reservations', 'as-camp-availability-integration' ); ?>
					</strong>
					<p style="margin: 0; color: var(--as-gray-700);">
						<?php esc_html_e( 'Cart reservations prevent customers from holding items indefinitely. Products are automatically released after the reservation time expires.', 'as-camp-availability-integration' ); ?>
					</p>
				</div>
			</div>
		</div>

		<div class="as-cai-settings-section">
			<h3 style="font-size: 1.125rem; font-weight: 600; color: var(--as-gray-900); margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
				<i class="fas fa-shopping-cart" style="color: var(--as-primary);"></i>
				<?php esc_html_e( 'Cart Reservation Settings', 'as-camp-availability-integration' ); ?>
			</h3>

			<!-- Enable Cart Reservation -->
			<div class="as-cai-settings-row">
				<label class="as-cai-switch">
					<input type="checkbox" name="as_cai_enable_cart_reservation" value="yes" <?php checked( $enable_cart_reservation, 'yes' ); ?>>
					<span class="as-cai-slider"></span>
				</label>
				<div class="as-cai-settings-label">
					<strong><?php esc_html_e( 'Enable Cart Reservations', 'as-camp-availability-integration' ); ?></strong>
					<p><?php esc_html_e( 'Automatically reserve products when added to cart', 'as-camp-availability-integration' ); ?></p>
				</div>
			</div>

			<!-- Reservation Time -->
			<div class="as-cai-settings-row">
				<div class="as-cai-settings-label">
					<label for="as_cai_reservation_time"><strong><?php esc_html_e( 'Reservation Time (Minutes)', 'as-camp-availability-integration' ); ?></strong></label>
					<p><?php esc_html_e( 'Default: 5 minutes', 'as-camp-availability-integration' ); ?></p>
				</div>
				<input type="number" name="as_cai_reservation_time" id="as_cai_reservation_time" value="<?php echo esc_attr( $reservation_time ); ?>" min="1" max="60" class="as-cai-input" style="width: 120px;">
			</div>

			<!-- Show Cart Timer -->
			<div class="as-cai-settings-row">
				<label class="as-cai-switch">
					<input type="checkbox" name="as_cai_show_cart_timer" value="yes" <?php checked( $show_cart_timer, 'yes' ); ?>>
					<span class="as-cai-slider"></span>
				</label>
				<div class="as-cai-settings-label">
					<strong><?php esc_html_e( 'Show Countdown Timer in Cart', 'as-camp-availability-integration' ); ?></strong>
					<p><?php esc_html_e( 'Display reservation timer in the shopping cart', 'as-camp-availability-integration' ); ?></p>
				</div>
			</div>

			<!-- Timer Style -->
			<div class="as-cai-settings-row">
				<div class="as-cai-settings-label">
					<label for="as_cai_cart_timer_style"><strong><?php esc_html_e( 'Cart Timer Style', 'as-camp-availability-integration' ); ?></strong></label>
					<p><?php esc_html_e( 'Visual appearance of the cart timer', 'as-camp-availability-integration' ); ?></p>
				</div>
				<select name="as_cai_cart_timer_style" id="as_cai_cart_timer_style" class="as-cai-select">
					<option value="full" <?php selected( $cart_timer_style, 'full' ); ?>>
						<?php esc_html_e( 'Full (With message)', 'as-camp-availability-integration' ); ?>
					</option>
					<option value="compact" <?php selected( $cart_timer_style, 'compact' ); ?>>
						<?php esc_html_e( 'Compact (Timer only)', 'as-camp-availability-integration' ); ?>
					</option>
					<option value="minimal" <?php selected( $cart_timer_style, 'minimal' ); ?>>
						<?php esc_html_e( 'Minimal', 'as-camp-availability-integration' ); ?>
					</option>
				</select>
			</div>

			<!-- Warning Threshold -->
			<div class="as-cai-settings-row">
				<div class="as-cai-settings-label">
					<label for="as_cai_warning_threshold"><strong><?php esc_html_e( 'Warning Threshold (Minutes)', 'as-camp-availability-integration' ); ?></strong></label>
					<p><?php esc_html_e( 'Show warning when time remaining is below this threshold', 'as-camp-availability-integration' ); ?></p>
				</div>
				<input type="number" name="as_cai_warning_threshold" id="as_cai_warning_threshold" value="<?php echo esc_attr( $warning_threshold ); ?>" min="1" max="10" class="as-cai-input" style="width: 120px;">
			</div>
		</div>
		<?php
	}

	/**
	 * Render debug settings fields.
	 */
	private function render_debug_settings() {
		$enable_debug = get_option( 'as_cai_enable_debug', 'no' );
		$debug_log    = get_option( 'as_cai_debug_log', 'no' );
		?>
		<div class="as-cai-warning-box" style="margin-bottom: 24px;">
			<div style="display: flex; align-items: flex-start; gap: 12px;">
				<i class="fas fa-exclamation-triangle" style="color: var(--as-warning); font-size: 1.25rem; margin-top: 2px;"></i>
				<div>
					<strong style="color: var(--as-gray-900); display: block; margin-bottom: 4px;">
						<?php esc_html_e( 'Production Warning', 'as-camp-availability-integration' ); ?>
					</strong>
					<p style="margin: 0; color: var(--as-gray-700);">
						<?php esc_html_e( 'Debug mode should only be enabled when troubleshooting issues. Disable it in production for security and performance.', 'as-camp-availability-integration' ); ?>
					</p>
				</div>
			</div>
		</div>

		<div class="as-cai-info-box" style="margin-bottom: 24px;">
			<div style="display: flex; align-items: flex-start; gap: 12px;">
				<i class="fas fa-info-circle" style="color: var(--as-info); font-size: 1.25rem; margin-top: 2px;"></i>
				<div>
					<strong style="color: var(--as-gray-900); display: block; margin-bottom: 4px;">
						<?php esc_html_e( 'Debug Configuration Overview', 'as-camp-availability-integration' ); ?>
					</strong>
					<ul style="margin: 8px 0 0 0; padding-left: 20px; color: var(--as-gray-700); font-size: 0.875rem;">
						<li><?php esc_html_e( 'Debug Mode: Shows detailed debug information in admin panels and frontend', 'as-camp-availability-integration' ); ?></li>
						<li><?php esc_html_e( 'Debug Logging: Writes detailed logs to WordPress debug.log (requires WP_DEBUG_LOG)', 'as-camp-availability-integration' ); ?></li>
						<li><?php esc_html_e( 'Debug Tools: Available in the "Debug Tools" tab above - test reservation system and view logs', 'as-camp-availability-integration' ); ?></li>
					</ul>
				</div>
			</div>
		</div>

		<div class="as-cai-settings-section">
			<h3 style="font-size: 1.125rem; font-weight: 600; color: var(--as-gray-900); margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
				<i class="fas fa-bug" style="color: var(--as-primary);"></i>
				<?php esc_html_e( 'Debug Settings', 'as-camp-availability-integration' ); ?>
			</h3>

			<!-- Enable Debug Mode -->
			<div class="as-cai-settings-row">
				<label class="as-cai-switch">
					<input type="checkbox" name="as_cai_enable_debug" value="yes" <?php checked( $enable_debug, 'yes' ); ?>>
					<span class="as-cai-slider"></span>
				</label>
				<div class="as-cai-settings-label">
					<strong><?php esc_html_e( 'Enable Debug Mode', 'as-camp-availability-integration' ); ?></strong>
					<p><?php esc_html_e( 'Shows detailed debug information in admin panels and frontend. Useful for troubleshooting reservation issues, stock calculations, and timer behavior.', 'as-camp-availability-integration' ); ?></p>
				</div>
			</div>

			<!-- Enable Debug Logging -->
			<div class="as-cai-settings-row">
				<label class="as-cai-switch">
					<input type="checkbox" name="as_cai_debug_log" value="yes" <?php checked( $debug_log, 'yes' ); ?>>
					<span class="as-cai-slider"></span>
				</label>
				<div class="as-cai-settings-label">
					<strong><?php esc_html_e( 'Enable Debug Logging', 'as-camp-availability-integration' ); ?></strong>
					<p><?php esc_html_e( 'Writes detailed log entries to WordPress debug.log file. Requires WP_DEBUG and WP_DEBUG_LOG to be enabled in wp-config.php. Check "Debug Tools" tab for log viewer.', 'as-camp-availability-integration' ); ?></p>
				</div>
			</div>
		</div>

		<div class="as-cai-info-box" style="margin-top: 24px;">
			<div style="display: flex; align-items: flex-start; gap: 12px;">
				<i class="fas fa-lightbulb" style="color: var(--as-info); font-size: 1.25rem; margin-top: 2px;"></i>
				<div>
					<strong style="color: var(--as-gray-900); display: block; margin-bottom: 4px;">
						<?php esc_html_e( 'Quick Tip', 'as-camp-availability-integration' ); ?>
					</strong>
					<p style="margin: 0; color: var(--as-gray-700); font-size: 0.875rem;">
						<?php esc_html_e( 'For comprehensive troubleshooting, enable both Debug Mode and Debug Logging, then check the "Debug Tools" tab to run tests and view logs. Don\'t forget to disable these options when done!', 'as-camp-availability-integration' ); ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Reservations tab.
	 */
	private function render_reservations() {
		// Get reservation DB instance.
		if ( class_exists( 'AS_CAI_Reservation_DB' ) ) {
			$db           = AS_CAI_Reservation_DB::instance();
			$reservations = $db->get_all_reservations();
		} else {
			$reservations = array();
		}
		?>
		<div class="as-cai-card as-cai-fade-in">
			<div class="as-cai-card-header">
				<div style="display: flex; align-items: center; justify-content: space-between;">
					<h2 class="as-cai-card-title">
						<i class="fas fa-list"></i>
						<?php esc_html_e( 'Active Cart Reservations', 'as-camp-availability-integration' ); ?>
					</h2>
					<button @click="refreshReservations()" class="as-cai-btn as-cai-btn-primary">
						<i class="fas fa-sync-alt"></i>
						<?php esc_html_e( 'Refresh', 'as-camp-availability-integration' ); ?>
					</button>
				</div>
			</div>
			<div class="as-cai-card-body">
				<?php if ( empty( $reservations ) ) : ?>
					<div class="as-cai-empty-state">
						<i class="fas fa-inbox"></i>
						<p><?php esc_html_e( 'No active reservations', 'as-camp-availability-integration' ); ?></p>
					</div>
				<?php else : ?>
					<div style="overflow-x: auto;">
						<table class="as-cai-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Customer ID', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Product', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Quantity', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Created', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Expires', 'as-camp-availability-integration' ); ?></th>
									<th><?php esc_html_e( 'Status', 'as-camp-availability-integration' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $reservations as $reservation ) : ?>
									<?php
									$product        = wc_get_product( $reservation['product_id'] );
									$product_name   = $product ? $product->get_name() : __( 'Unknown Product', 'as-camp-availability-integration' );
									$expires_ts     = strtotime( $reservation['expires'] );
									$now            = time();
									$time_remaining = $expires_ts - $now;
									$is_expiring    = $time_remaining > 0 && $time_remaining < 60;
									$is_expired     = $time_remaining <= 0;
									?>
									<tr>
										<td>
											<code style="font-size: 12px;">
												<?php echo esc_html( substr( $reservation['customer_id'], 0, 20 ) . '...' ); ?>
											</code>
										</td>
										<td>
											<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $reservation['product_id'] . '&action=edit' ) ); ?>" 
											   style="color: var(--as-primary); font-weight: 600; text-decoration: none;">
												<?php echo esc_html( $product_name ); ?>
											</a>
										</td>
										<td>
											<span style="font-weight: 600;"><?php echo esc_html( $reservation['stock_quantity'] ); ?></span>
										</td>
										<td style="font-size: 13px;">
											<?php echo esc_html( date( 'Y-m-d H:i', strtotime( $reservation['timestamp'] ) ) ); ?>
										</td>
										<td style="font-size: 13px;">
											<?php echo esc_html( date( 'Y-m-d H:i', strtotime( $reservation['expires'] ) ) ); ?>
										</td>
										<td>
											<?php if ( $is_expired ) : ?>
												<span class="as-cai-badge expired">
													<i class="fas fa-times-circle"></i>
													<?php esc_html_e( 'Expired', 'as-camp-availability-integration' ); ?>
												</span>
											<?php elseif ( $is_expiring ) : ?>
												<span class="as-cai-badge expiring">
													<i class="fas fa-exclamation-triangle"></i>
													<?php esc_html_e( 'Expiring', 'as-camp-availability-integration' ); ?>
												</span>
											<?php else : ?>
												<span class="as-cai-badge active">
													<i class="fas fa-check-circle"></i>
													<?php esc_html_e( 'Active', 'as-camp-availability-integration' ); ?>
												</span>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render Debug tab.
	 */
	/**
	 * Render Documentation tab (v1.3.23 - Modern Card Design with Latest Update).
	 */
	private function render_documentation() {
		$readme_file = AS_CAI_PLUGIN_DIR . 'README.md';
		$changelog_file = AS_CAI_PLUGIN_DIR . 'CHANGELOG.md';
		$update_file = AS_CAI_PLUGIN_DIR . 'UPDATE.md'; // PRIMARY: Single UPDATE.md file
		
		// Initialize variables
		$latest_update_file = '';
		$latest_version = '0.0.0';
		
		// Check for single UPDATE.md first (v1.3.31+)
		if ( file_exists( $update_file ) ) {
			$latest_update_file = $update_file;
			$latest_version = AS_CAI_VERSION; // Use current plugin version
		} else {
			// LEGACY: Fallback to versioned UPDATE-*.md files (pre v1.3.31)
			$update_files = glob( AS_CAI_PLUGIN_DIR . 'UPDATE-*.md' );
			
			if ( ! empty( $update_files ) ) {
				foreach ( $update_files as $file ) {
					if ( preg_match( '/UPDATE-(\d+\.\d+\.\d+)\.md$/', $file, $matches ) ) {
						if ( version_compare( $matches[1], $latest_version, '>' ) ) {
							$latest_version = $matches[1];
							$latest_update_file = $file;
						}
					}
				}
			}
		}
		
		$readme_content = file_exists( $readme_file ) ? file_get_contents( $readme_file ) : '';
		$changelog_content = file_exists( $changelog_file ) ? file_get_contents( $changelog_file ) : '';
		$update_content = $latest_update_file && file_exists( $latest_update_file ) ? file_get_contents( $latest_update_file ) : '';
		
		?>
		<div class="as-cai-card as-cai-fade-in" x-data="{ activeDoc: 'readme' }">
			<div class="as-cai-card-header">
				<h2 class="as-cai-card-title">
					<i class="fas fa-book"></i>
					<?php esc_html_e( 'Plugin Documentation', 'as-camp-availability-integration' ); ?>
				</h2>
			</div>

			<!-- Tab Navigation -->
			<div class="as-cai-card-body" style="padding: 0;">
				<div style="border-bottom: 2px solid var(--as-gray-200); background: var(--as-gray-50);">
					<nav style="display: flex; gap: 0; padding: 0 24px;">
						<button type="button" @click="activeDoc = 'readme'" 
						        :class="activeDoc === 'readme' ? 'as-cai-doc-tab-active' : 'as-cai-doc-tab'"
						        style="flex: 1; padding: 16px 20px; border: none; background: transparent; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border-bottom: 3px solid transparent;">
							<i class="fas fa-file-alt"></i>
							<?php esc_html_e( 'README', 'as-camp-availability-integration' ); ?>
						</button>
						<?php if ( $update_content ) : ?>
						<button type="button" @click="activeDoc = 'latest'" 
						        :class="activeDoc === 'latest' ? 'as-cai-doc-tab-active' : 'as-cai-doc-tab'"
						        style="flex: 1; padding: 16px 20px; border: none; background: transparent; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border-bottom: 3px solid transparent;">
							<i class="fas fa-star"></i>
							<?php 
							/* translators: %s: version number */
							printf( esc_html__( 'Latest Update (v%s)', 'as-camp-availability-integration' ), esc_html( $latest_version ) ); 
							?>
						</button>
						<?php endif; ?>
						<button type="button" @click="activeDoc = 'changelog'" 
						        :class="activeDoc === 'changelog' ? 'as-cai-doc-tab-active' : 'as-cai-doc-tab'"
						        style="flex: 1; padding: 16px 20px; border: none; background: transparent; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border-bottom: 3px solid transparent;">
							<i class="fas fa-list-alt"></i>
							<?php esc_html_e( 'Changelog', 'as-camp-availability-integration' ); ?>
						</button>
						<button type="button" @click="activeDoc = 'support'" 
						        :class="activeDoc === 'support' ? 'as-cai-doc-tab-active' : 'as-cai-doc-tab'"
						        style="flex: 1; padding: 16px 20px; border: none; background: transparent; cursor: pointer; font-weight: 600; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.3s; border-bottom: 3px solid transparent;">
							<i class="fas fa-life-ring"></i>
							<?php esc_html_e( 'Support', 'as-camp-availability-integration' ); ?>
						</button>
					</nav>
				</div>

				<!-- README Tab -->
				<div x-show="activeDoc === 'readme'" style="padding: 24px; max-height: 800px; overflow-y: auto;">
					<div class="as-cai-markdown-raw">
						<pre><code class="language-markdown"><?php echo esc_html( $readme_content ); ?></code></pre>
					</div>
				</div>

				<!-- Latest Update Tab -->
				<?php if ( $update_content ) : ?>
				<div x-show="activeDoc === 'latest'" x-cloak style="padding: 24px; max-height: 800px; overflow-y: auto;">
					<div class="as-cai-badge as-cai-badge-success" style="display: inline-flex; align-items: center; gap: 8px; margin-bottom: 20px;">
						<i class="fas fa-check-circle"></i>
						<?php 
						/* translators: %s: version number */
						printf( esc_html__( 'Version %s Documentation', 'as-camp-availability-integration' ), esc_html( $latest_version ) ); 
						?>
					</div>
					<div class="as-cai-markdown-raw">
						<pre><code class="language-markdown"><?php echo esc_html( $update_content ); ?></code></pre>
					</div>
				</div>
				<?php endif; ?>

				<!-- Changelog Tab -->
				<div x-show="activeDoc === 'changelog'" x-cloak style="padding: 24px; max-height: 800px; overflow-y: auto;">
					<div class="as-cai-markdown-raw">
						<pre><code class="language-markdown"><?php echo esc_html( $changelog_content ); ?></code></pre>
					</div>
				</div>

				<!-- Support Tab -->
				<div x-show="activeDoc === 'support'" x-cloak style="padding: 24px;">
					<div class="as-cai-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none; color: white;">
						<div class="as-cai-card-body">
							<div style="display: flex; align-items: center; gap: 20px; margin-bottom: 20px;">
								<i class="fas fa-headset" style="font-size: 3rem; opacity: 0.9;"></i>
								<div>
									<h3 style="font-size: 1.5rem; font-weight: 700; margin: 0 0 8px 0; color: white;">
										<?php esc_html_e( 'Need Help?', 'as-camp-availability-integration' ); ?>
									</h3>
									<p style="margin: 0; opacity: 0.9;">
										<?php esc_html_e( 'Our support team is here to help you!', 'as-camp-availability-integration' ); ?>
									</p>
								</div>
							</div>
							<div style="background: rgba(255, 255, 255, 0.1); border-radius: 8px; padding: 20px; backdrop-filter: blur(10px);">
								<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px;">
									<div>
										<div style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 4px;">
											<?php esc_html_e( 'Email Support', 'as-camp-availability-integration' ); ?>
										</div>
										<div style="font-weight: 600; font-size: 1.125rem;">
											kundensupport@zoobro.de
										</div>
									</div>
									<div>
										<div style="font-size: 0.875rem; opacity: 0.9; margin-bottom: 4px;">
											<?php esc_html_e( 'Website', 'as-camp-availability-integration' ); ?>
										</div>
										<div style="font-weight: 600; font-size: 1.125rem;">
											<a href="https://ayon.to" target="_blank" style="color: white; text-decoration: underline;">
												ayon.to
											</a>
										</div>
									</div>
								</div>
							</div>
						</div>
					</div>

					<div class="as-cai-card" style="margin-top: 24px;">
						<div class="as-cai-card-header">
							<h3 class="as-cai-card-title">
								<i class="fas fa-info-circle"></i>
								<?php esc_html_e( 'System Information', 'as-camp-availability-integration' ); ?>
							</h3>
						</div>
						<div class="as-cai-card-body">
							<div class="as-cai-table-wrapper">
								<table class="as-cai-table">
									<tbody>
										<tr>
											<td style="font-weight: 600;"><?php esc_html_e( 'Plugin Version', 'as-camp-availability-integration' ); ?></td>
											<td><?php echo esc_html( AS_CAI_VERSION ); ?></td>
										</tr>
										<tr>
											<td style="font-weight: 600;"><?php esc_html_e( 'WordPress Version', 'as-camp-availability-integration' ); ?></td>
											<td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
										</tr>
										<tr>
											<td style="font-weight: 600;"><?php esc_html_e( 'PHP Version', 'as-camp-availability-integration' ); ?></td>
											<td><?php echo esc_html( phpversion() ); ?></td>
										</tr>
										<tr>
											<td style="font-weight: 600;"><?php esc_html_e( 'WooCommerce Version', 'as-camp-availability-integration' ); ?></td>
											<td><?php echo esc_html( defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A' ); ?></td>
										</tr>
									</tbody>
								</table>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<style>
		.as-cai-doc-tab {
			color: var(--as-gray-600);
		}
		.as-cai-doc-tab:hover {
			color: var(--as-primary);
			background: var(--as-gray-100);
		}
		.as-cai-doc-tab-active {
			color: var(--as-primary) !important;
			border-bottom-color: var(--as-primary) !important;
			background: white !important;
		}
		.as-cai-prose {
			line-height: 1.7;
			color: var(--as-gray-800);
		}
		.as-cai-prose h1, .as-cai-prose h2, .as-cai-prose h3 {
			color: var(--as-gray-900);
			font-weight: 700;
			margin-top: 24px;
			margin-bottom: 12px;
		}
		.as-cai-prose h1 { font-size: 2rem; }
		.as-cai-prose h2 { font-size: 1.5rem; }
		.as-cai-prose h3 { font-size: 1.25rem; }
		.as-cai-prose code {
			background: var(--as-gray-100);
			color: var(--as-gray-900);
			padding: 2px 6px;
			border-radius: 4px;
			font-family: monospace;
			font-size: 0.875em;
		}
		.as-cai-prose pre {
			background: var(--as-gray-900);
			color: white;
			padding: 16px;
			border-radius: 8px;
			overflow-x: auto;
			margin: 16px 0;
		}
		.as-cai-prose ul, .as-cai-prose ol {
			margin: 12px 0;
			padding-left: 24px;
		}
		.as-cai-prose li {
			margin: 6px 0;
		}
		</style>
		<?php
	}

	/**
	 * Get dashboard statistics.
	 *
	 * @return array
	 */
	private function get_dashboard_stats() {
		$stats = array(
			'active_reservations' => 0,
			'reserved_products'   => 0,
			'expired_today'       => 0,
			'system_healthy'      => true,
		);

		if ( class_exists( 'AS_CAI_Reservation_DB' ) ) {
			$db                            = AS_CAI_Reservation_DB::instance();
			$stats['active_reservations']  = $db->count_active_reservations();
			$stats['reserved_products']    = $db->count_reserved_products();
			$stats['expired_today']        = $db->count_expired_today();
		}

		// Check system health.
		$stats['system_healthy'] = class_exists( 'WooCommerce' ) && 
								   class_exists( 'Koala_Availability_Scheduler_For_Woocommerce' );

		return $stats;
	}

	/**
	 * AJAX handler to clear all reservations.
	 */
	public function ajax_clear_reservations() {
		check_ajax_referer( 'as_cai_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'as-camp-availability-integration' ) ) );
		}

		if ( class_exists( 'AS_CAI_Reservation_DB' ) ) {
			$db = AS_CAI_Reservation_DB::instance();
			$db->flush_all_reservations();
			wp_send_json_success( array( 'message' => __( 'All reservations cleared', 'as-camp-availability-integration' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Reservation system not available', 'as-camp-availability-integration' ) ) );
	}

	/**
	 * AJAX handler to get statistics.
	 */
	public function ajax_get_stats() {
		check_ajax_referer( 'as_cai_admin_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'as-camp-availability-integration' ) ) );
		}

		$stats = $this->get_dashboard_stats();
		wp_send_json_success( $stats );
	}

	/**
	 * Render Advanced Debug settings fields.
	 *
	 * @since 1.3.28
	 */
	private function render_advanced_debug_settings() {
		$advanced_debug = get_option( 'as_cai_advanced_debug', 'no' );
		$debug_areas    = AS_CAI_Advanced_Debug::instance()->get_debug_areas();
		$log_size       = AS_CAI_Advanced_Debug::instance()->get_log_size();
		$log_file       = AS_CAI_Advanced_Debug::instance()->get_log_file();
		?>
		<div class="as-cai-settings-section">
			<!-- Header -->
			<h3 style="font-size: 1.125rem; font-weight: 600; color: var(--as-gray-900); margin-bottom: 20px; display: flex; align-items: center; gap: 8px;">
				<i class="fas fa-microscope" style="color: var(--as-primary);"></i>
				<?php esc_html_e( 'Advanced Debug System', 'as-camp-availability-integration' ); ?>
			</h3>

			<!-- Info Box -->
			<div class="as-cai-info-box" style="margin-bottom: 24px;">
				<p style="margin: 0; font-weight: 600; color: var(--as-info); margin-bottom: 8px;">
					<i class="fas fa-info-circle"></i> <?php esc_html_e( 'About Advanced Debug', 'as-camp-availability-integration' ); ?>
				</p>
				<p style="margin: 0; color: var(--as-gray-700); font-size: 0.875rem;">
					<?php esc_html_e( 'Advanced Debug provides granular control over debug logging with separate toggles for each area. Logs are written to a separate file for easy troubleshooting without cluttering WordPress debug.log.', 'as-camp-availability-integration' ); ?>
				</p>
				<p style="margin: 8px 0 0 0; color: var(--as-gray-700); font-size: 0.875rem;">
					<strong><?php esc_html_e( 'Log File:', 'as-camp-availability-integration' ); ?></strong> <?php echo esc_html( $log_file ); ?><br>
					<strong><?php esc_html_e( 'Current Size:', 'as-camp-availability-integration' ); ?></strong> <?php echo esc_html( $log_size ); ?>
				</p>
			</div>

			<!-- Master Toggle -->
			<div class="as-cai-settings-row">
				<div class="as-cai-settings-label">
					<strong>
						<i class="fas fa-power-off" style="color: var(--as-primary);"></i>
						<?php esc_html_e( 'Enable Advanced Debug', 'as-camp-availability-integration' ); ?>
					</strong>
					<p><?php esc_html_e( 'Master switch for advanced debug logging. Must be enabled to use area-specific debugging.', 'as-camp-availability-integration' ); ?></p>
				</div>
				<label class="as-cai-switch">
					<input type="checkbox" 
					       name="as_cai_advanced_debug" 
					       value="yes" 
					       <?php checked( $advanced_debug, 'yes' ); ?>>
					<span class="as-cai-slider"></span>
				</label>
			</div>

			<!-- Debug Areas -->
			<div style="background: var(--as-gray-50); padding: 20px; border-radius: 8px; margin-top: 20px;">
				<h4 style="font-size: 1rem; font-weight: 600; color: var(--as-gray-900); margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
					<i class="fas fa-layer-group" style="color: var(--as-primary);"></i>
					<?php esc_html_e( 'Debug Areas', 'as-camp-availability-integration' ); ?>
				</h4>

				<?php foreach ( $debug_areas as $area => $config ) : ?>
					<?php $area_enabled = get_option( "as_cai_debug_area_{$area}", 'no' ); ?>
					<div class="as-cai-settings-row" style="background: white; margin-bottom: 12px; border-radius: 6px; border: 1px solid var(--as-gray-200);">
						<div class="as-cai-settings-label">
							<strong><?php echo esc_html( $config['label'] ); ?></strong>
							<p><?php echo esc_html( $config['description'] ); ?></p>
						</div>
						<label class="as-cai-switch">
							<input type="checkbox" 
							       name="as_cai_debug_area_<?php echo esc_attr( $area ); ?>" 
							       value="yes" 
							       <?php checked( $area_enabled, 'yes' ); ?>>
							<span class="as-cai-slider"></span>
						</label>
					</div>
				<?php endforeach; ?>
			</div>

			<!-- Log Viewer -->
			<div style="margin-top: 32px;" x-data="{ logs: [], loading: false, filter: '', lines: 100 }">
				<h4 style="font-size: 1rem; font-weight: 600; color: var(--as-gray-900); margin-bottom: 16px; display: flex; align-items: center; gap: 8px;">
					<i class="fas fa-scroll" style="color: var(--as-primary);"></i>
					<?php esc_html_e( 'Live Log Viewer', 'as-camp-availability-integration' ); ?>
				</h4>

				<!-- Controls -->
				<div style="display: flex; gap: 12px; margin-bottom: 16px; flex-wrap: wrap;">
					<input type="text" 
					       x-model="filter" 
					       placeholder="<?php esc_attr_e( 'Filter logs...', 'as-camp-availability-integration' ); ?>" 
					       class="as-cai-input" 
					       style="flex: 1; min-width: 200px;">
					<select x-model="lines" class="as-cai-select">
						<option value="50">50 lines</option>
						<option value="100" selected>100 lines</option>
						<option value="200">200 lines</option>
						<option value="500">500 lines</option>
					</select>
					<button type="button" 
					        @click="loadLogs()" 
					        :disabled="loading"
					        class="as-cai-btn as-cai-btn-primary">
						<i class="fas fa-sync-alt" :class="{ 'fa-spin': loading }"></i>
						<?php esc_html_e( 'Refresh', 'as-camp-availability-integration' ); ?>
					</button>
					<button type="button" 
					        @click="downloadLogs()" 
					        class="as-cai-btn" 
					        style="background: var(--as-success); color: white;">
						<i class="fas fa-download"></i>
						<?php esc_html_e( 'Download', 'as-camp-availability-integration' ); ?>
					</button>
					<button type="button" 
					        @click="clearLogs()" 
					        class="as-cai-btn" 
					        style="background: var(--as-danger); color: white;">
						<i class="fas fa-trash"></i>
						<?php esc_html_e( 'Clear Logs', 'as-camp-availability-integration' ); ?>
					</button>
				</div>

				<!-- Log Display -->
				<div style="background: #1e1e1e; color: #d4d4d4; padding: 20px; border-radius: 8px; font-family: 'Courier New', monospace; font-size: 0.875rem; max-height: 500px; overflow-y: auto;">
					<template x-if="loading">
						<div style="text-align: center; color: var(--as-primary);">
							<i class="fas fa-spinner fa-spin"></i> Loading logs...
						</div>
					</template>
					<template x-if="!loading && logs.length === 0">
						<div style="text-align: center; color: var(--as-gray-500);">
							<i class="fas fa-inbox"></i> No logs found. Enable debugging and perform actions to generate logs.
						</div>
					</template>
					<template x-for="log in logs" :key="log">
						<div style="padding: 4px 0; line-height: 1.5;">
							<span x-html="formatLog(log)"></span>
						</div>
					</template>
				</div>
			</div>

			<script>
			function formatLog(log) {
				// Color coding for log levels
				let html = log;
				html = html.replace(/\[ERROR\]/g, '<span style="color: #f87171; font-weight: bold;">[ERROR]</span>');
				html = html.replace(/\[WARNING\]/g, '<span style="color: #fbbf24; font-weight: bold;">[WARNING]</span>');
				html = html.replace(/\[INFO\]/g, '<span style="color: #60a5fa; font-weight: bold;">[INFO]</span>');
				html = html.replace(/\[DEBUG\]/g, '<span style="color: #a78bfa; font-weight: bold;">[DEBUG]</span>');
				
				// Color coding for areas
				html = html.replace(/\[ADMIN\]/g, '<span style="color: #ec4899;">[ADMIN]</span>');
				html = html.replace(/\[FRONTEND\]/g, '<span style="color: #10b981;">[FRONTEND]</span>');
				html = html.replace(/\[CART\]/g, '<span style="color: #f59e0b;">[CART]</span>');
				html = html.replace(/\[DATABASE\]/g, '<span style="color: #3b82f6;">[DATABASE]</span>');
				html = html.replace(/\[CRON\]/g, '<span style="color: #8b5cf6;">[CRON]</span>');
				html = html.replace(/\[HOOKS\]/g, '<span style="color: #06b6d4;">[HOOKS]</span>');
				html = html.replace(/\[PERFORMANCE\]/g, '<span style="color: #f43f5e;">[PERFORMANCE]</span>');
				
				return html;
			}

			function loadLogs() {
				this.loading = true;
				fetch(asCaiAdmin.ajaxUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams({
						action: 'as_cai_get_debug_logs',
						nonce: asCaiAdmin.nonce,
						lines: this.lines,
						filter: this.filter
					})
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						this.logs = data.data.logs;
					} else {
						alert('Error loading logs');
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Error loading logs');
				})
				.finally(() => {
					this.loading = false;
				});
			}

			function clearLogs() {
				if (!confirm('<?php esc_html_e( 'Are you sure you want to clear all debug logs? This cannot be undone.', 'as-camp-availability-integration' ); ?>')) {
					return;
				}
				fetch(asCaiAdmin.ajaxUrl, {
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams({
						action: 'as_cai_clear_debug_logs',
						nonce: asCaiAdmin.nonce
					})
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						this.logs = [];
						alert('<?php esc_html_e( 'Logs cleared successfully', 'as-camp-availability-integration' ); ?>');
					} else {
						alert('Error clearing logs');
					}
				})
				.catch(error => {
					console.error('Error:', error);
					alert('Error clearing logs');
				});
			}

			function downloadLogs() {
				window.location.href = asCaiAdmin.ajaxUrl + '?action=as_cai_download_debug_logs&nonce=' + asCaiAdmin.nonce;
			}
			</script>
		</div>
		<?php
	}
}
