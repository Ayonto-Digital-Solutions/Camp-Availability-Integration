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

	/** @var bool Ob der BuyBox-Shortcode bereits gerendert wurde */
	private static $buybox_rendered = false;

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
		add_shortcode( 'as_cai_buybox', array( $this, 'shortcode_buybox' ) );
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
	 * Detect the accommodation type from the product name.
	 *
	 * Checks for keywords like "Parzelle", "Bungalow", "Zimmer" in the
	 * product title and returns the matching type. Falls back to "Platz".
	 *
	 * @param WC_Product $product The product object.
	 * @return array{ singular: string, plural: string, cta: string }
	 */
	private static function get_unit_type( $product ) {
		$name = strtolower( $product->get_name() );

		$types = array(
			'parzelle'  => array(
				'singular' => 'Parzelle',
				'plural'   => 'Parzellen',
				'cta'      => 'Jetzt Parzelle auswählen',
			),
			'bungalow'  => array(
				'singular' => 'Bungalow',
				'plural'   => 'Bungalows',
				'cta'      => 'Jetzt Bungalow auswählen',
			),
			'zimmer'    => array(
				'singular' => 'Zimmer',
				'plural'   => 'Zimmer',
				'cta'      => 'Jetzt Zimmer auswählen',
			),
		);

		foreach ( $types as $keyword => $labels ) {
			if ( false !== strpos( $name, $keyword ) ) {
				return $labels;
			}
		}

		// Fallback for unknown product types.
		return array(
			'singular' => 'Platz',
			'plural'   => 'Plätze',
			'cta'      => 'Jetzt buchen',
		);
	}

	/**
	 * Get the unit label (plural) for a product.
	 *
	 * @param WC_Product $product The product object.
	 * @return string e.g. "Parzellen", "Bungalows", "Zimmer".
	 */
	private static function get_unit_label( $product ) {
		$type = self::get_unit_type( $product );
		return $type['plural'];
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
		// BuyBox-Shortcode hat bereits gerendert → kein Doppel.
		if ( self::$buybox_rendered ) {
			return;
		}

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

	public function render_status_box( $product_id, $show_price = false ) {
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

			<?php if ( $show_price ) : ?>
				<?php $price_product = wc_get_product( $product_id ); ?>
				<?php if ( $price_product ) : ?>
					<div class="as-cai-status-price">
						<?php echo wp_kses_post( $price_product->get_price_html() ); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>

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

				</div>

			<div class="status-action">
				<?php if ( 'sold_out' === $status || 'reserved_full' === $status ) : ?>
					<button class="as-cai-waitlist-button" type="button"
							data-product-id="<?php echo esc_attr( $product_id ); ?>">
						Auf Warteliste setzen
					</button>
				<?php else : ?>
					<?php
					// Nativen WooCommerce Add-to-Cart rendern.
					// Stachethemes Seat Planner hookt sich in woocommerce_auditorium_add_to_cart ein.
					global $product;
					$original_product = $product;
					$product          = wc_get_product( $product_id );
					if ( $product ) {
						echo '<div class="as-cai-add-to-cart-wrapper">';
						do_action( 'woocommerce_' . $product->get_type() . '_add_to_cart' );
						echo '</div>';
					}
					$product = $original_product;
					?>
				<?php endif; ?>
			</div>

			<div class="status-meta">
				<small>
					Aktualisiert: <span class="update-time"><?php echo esc_html( wp_date( 'H:i:s' ) ); ?></span>
					<span class="auto-refresh-indicator">&#9679; Auto-Refresh aktiv</span>
				</small>
			</div>
		</div>
		<?php
	}

	// ─────────────────────────────────────────────
	// BuyBox Shortcode
	// ─────────────────────────────────────────────

	/**
	 * Shortcode [as_cai_buybox] — Komplette BuyBox mit Zwei-Spalten-Layout.
	 *
	 * Links: Titel, Breadcrumbs, Beschreibung, Kategorie
	 * Rechts: Preis, Status-Box oder Countdown
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode_buybox( $atts ) {
		$atts = shortcode_atts( array(
			'product_id' => 0,
		), $atts, 'as_cai_buybox' );

		$product_id = absint( $atts['product_id'] );

		if ( ! $product_id ) {
			global $product;
			if ( $product && is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$product_id = $product->get_id();
			} elseif ( get_the_ID() ) {
				$product_id = get_the_ID();
			}
		}

		if ( ! $product_id ) {
			return '';
		}

		$product_obj = wc_get_product( $product_id );
		if ( ! $product_obj ) {
			return '';
		}

		// Assets laden (ohne is_product() Check).
		$this->force_enqueue_assets();

		// Flag setzen — Hook-Injektion überspringen.
		self::$buybox_rendered = true;

		ob_start();
		$this->render_buybox( $product_obj );
		return ob_get_clean();
	}

	/**
	 * Render the complete BuyBox.
	 *
	 * @param WC_Product $product Product object.
	 */
	private function render_buybox( $product ) {
		$product_id = $product->get_id();

		// Breadcrumbs vorbereiten.
		$breadcrumbs = $this->get_buybox_breadcrumbs( $product );

		// Kategorie.
		$categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'all' ) );
		$category_html = '';
		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			$cat = $categories[0];
			$category_html = '<a href="' . esc_url( get_term_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>';
		}

		// Availability prüfen.
		$availability = null;
		if ( class_exists( 'AS_CAI_Product_Availability' ) ) {
			$availability = AS_CAI_Product_Availability::instance()->get_availability_data( $product_id );
		}
		$show_countdown = $availability && ! $availability['is_available'];

		// BuyBox CSS inline enqueuen.
		$this->enqueue_buybox_css();
		?>
		<div class="as-cai-buybox">
			<!-- Linke Spalte -->
			<div class="as-cai-buybox-left">
				<h1 class="as-cai-buybox-title"><?php echo esc_html( $product->get_name() ); ?></h1>

				<?php if ( $breadcrumbs ) : ?>
					<div class="as-cai-buybox-breadcrumbs">
						<?php echo wp_kses_post( $breadcrumbs ); ?>
					</div>
				<?php endif; ?>

				<div class="as-cai-buybox-description">
					<?php echo wp_kses_post( wpautop( $product->get_description() ) ); ?>
				</div>

				<?php if ( $category_html ) : ?>
					<div class="as-cai-buybox-category">
						<strong>Event</strong> <?php echo wp_kses_post( $category_html ); ?>
					</div>
				<?php endif; ?>
			</div>

			<!-- Rechte Spalte -->
			<div class="as-cai-buybox-right">
				<div class="as-cai-buybox-status">
					<?php if ( $show_countdown ) : ?>
						<?php $this->render_buybox_countdown( $availability, $product_id ); ?>
					<?php else : ?>
						<?php $this->render_status_box( $product_id, true ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render countdown timer for BuyBox (prominent, product page style).
	 *
	 * @param array $availability Availability data.
	 * @param int   $product_id   Product ID.
	 */
	private function render_buybox_countdown( $availability, $product_id ) {
		$start_timestamp = $availability['start_timestamp'];
		$start_date      = $availability['start_date'];
		$start_time      = $availability['start_time'];

		// Formatierte Startzeit.
		try {
			$wp_tz    = wp_timezone();
			$dt       = new DateTime( $start_date . ' ' . $start_time . ':00', $wp_tz );
			$date_str = wp_date( 'd.m.Y', $dt->getTimestamp() );
			$time_str = wp_date( 'H:i', $dt->getTimestamp() );
		} catch ( Exception $e ) {
			$date_str = $start_date;
			$time_str = $start_time;
		}

		// Template-Builder Settings laden.
		$text_before  = get_option( 'as_cai_sc_cd_text_before', 'Verkaufsstart in' );
		$text_after   = get_option( 'as_cai_sc_cd_text_after', '{date} um {time} Uhr' );
		$show_days    = get_option( 'as_cai_sc_cd_show_days', 'yes' ) === 'yes';
		$show_hours   = get_option( 'as_cai_sc_cd_show_hours', 'yes' ) === 'yes';
		$show_minutes = get_option( 'as_cai_sc_cd_show_minutes', 'yes' ) === 'yes';
		$show_seconds = get_option( 'as_cai_sc_cd_show_seconds', 'no' ) === 'yes';

		$after_text = str_replace(
			array( '{date}', '{time}' ),
			array( $date_str, $time_str ),
			$text_after
		);

		$unique_id = 'as-cai-buybox-cd-' . $product_id;
		?>
		<div class="as-cai-buybox-countdown" id="<?php echo esc_attr( $unique_id ); ?>"
			 data-target-timestamp="<?php echo esc_attr( $start_timestamp ); ?>"
			 data-product-id="<?php echo esc_attr( $product_id ); ?>">

			<?php if ( '' !== $text_before ) : ?>
				<div class="as-cai-buybox-cd-label">
					<?php echo esc_html( strtoupper( $text_before ) ); ?>
				</div>
			<?php endif; ?>

			<div class="as-cai-buybox-cd-timer">
				<?php if ( $show_days ) : ?>
					<div class="as-cai-buybox-cd-unit">
						<span class="as-cai-buybox-cd-value cd-d" data-unit="d">0</span>
						<span class="as-cai-buybox-cd-unit-label">TAGE</span>
					</div>
					<span class="as-cai-buybox-cd-sep">:</span>
				<?php endif; ?>
				<?php if ( $show_hours ) : ?>
					<div class="as-cai-buybox-cd-unit">
						<span class="as-cai-buybox-cd-value cd-h" data-unit="h">0</span>
						<span class="as-cai-buybox-cd-unit-label">STD</span>
					</div>
					<span class="as-cai-buybox-cd-sep">:</span>
				<?php endif; ?>
				<?php if ( $show_minutes ) : ?>
					<div class="as-cai-buybox-cd-unit">
						<span class="as-cai-buybox-cd-value cd-m" data-unit="m">0</span>
						<span class="as-cai-buybox-cd-unit-label">MIN</span>
					</div>
					<?php if ( $show_seconds ) : ?>
						<span class="as-cai-buybox-cd-sep">:</span>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( $show_seconds ) : ?>
					<div class="as-cai-buybox-cd-unit">
						<span class="as-cai-buybox-cd-value cd-s" data-unit="s">0</span>
						<span class="as-cai-buybox-cd-unit-label">SEK</span>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( '' !== $after_text ) : ?>
				<div class="as-cai-buybox-cd-after">
					<?php echo esc_html( $after_text ); ?>
				</div>
			<?php endif; ?>
		</div>

		<script>
		(function(){
			var el = document.getElementById('<?php echo esc_js( $unique_id ); ?>');
			if (!el) return;
			function pad(n) { return n < 10 ? '0' + n : '' + n; }
			function tick() {
				var now = Math.floor(Date.now() / 1000);
				var target = parseInt(el.getAttribute('data-target-timestamp'), 10);
				var diff = target - now;
				if (diff <= 0) {
					setTimeout(function() { location.reload(); }, 1500);
					return;
				}
				var d = Math.floor(diff / 86400);
				var h = Math.floor((diff % 86400) / 3600);
				var m = Math.floor((diff % 3600) / 60);
				var s = diff % 60;
				<?php if ( ! $show_days ) : ?>h += d * 24; d = 0;<?php endif; ?>
				<?php if ( ! $show_hours ) : ?>m += h * 60; h = 0;<?php endif; ?>
				<?php if ( ! $show_minutes ) : ?>s += m * 60; m = 0;<?php endif; ?>
				var dEl = el.querySelector('.cd-d');
				var hEl = el.querySelector('.cd-h');
				var mEl = el.querySelector('.cd-m');
				var sEl = el.querySelector('.cd-s');
				if (dEl) dEl.textContent = d;
				if (hEl) hEl.textContent = pad(h);
				if (mEl) mEl.textContent = pad(m);
				if (sEl) sEl.textContent = pad(s);
				setTimeout(tick, <?php echo $show_seconds ? '1000' : '10000'; ?>);
			}
			tick();
		})();
		</script>
		<?php
	}

	/**
	 * Generate breadcrumb HTML for BuyBox.
	 *
	 * @param WC_Product $product Product object.
	 * @return string HTML breadcrumbs.
	 */
	private function get_buybox_breadcrumbs( $product ) {
		$parts = array();

		$parts[] = '<a href="' . esc_url( home_url( '/' ) ) . '">Start</a>';

		// Produktkategorie als Zwischenschritt.
		$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'all' ) );
		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			$cat = $categories[0];
			$parts[] = '<a href="' . esc_url( get_term_link( $cat ) ) . '">' . esc_html( $cat->name ) . '</a>';
		}

		$parts[] = esc_html( $product->get_name() );

		return implode( ' <span class="as-cai-buybox-bc-sep">/</span> ', $parts );
	}

	/**
	 * Force-enqueue assets without is_product() check (for shortcode usage).
	 */
	private function force_enqueue_assets() {
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
	 * Enqueue BuyBox-specific CSS (inline).
	 */
	private function enqueue_buybox_css() {
		static $enqueued = false;
		if ( $enqueued ) {
			return;
		}
		$enqueued = true;

		$css = '
		/* ── BuyBox — Zwei-Spalten-Layout ── */
		.as-cai-buybox {
			display: grid;
			grid-template-columns: 1fr 1fr;
			gap: 0;
			background: #25282B;
			border-radius: 14px;
			padding: 28px;
			border: 1px solid rgba(177, 158, 99, 0.15);
			box-shadow: 0 2px 12px rgba(0, 0, 0, 0.25);
			color: #F8F8F8;
			font-family: inherit;
			-webkit-font-smoothing: antialiased;
		}

		.as-cai-buybox-left {
			padding-right: 32px;
			border-right: 1px solid rgba(255, 255, 255, 0.06);
		}

		/* Linke Spalte */
		.as-cai-buybox-title {
			font-size: 24px;
			font-weight: 700;
			color: #F8F8F8;
			margin: 0 0 8px 0;
			letter-spacing: -0.02em;
		}

		.as-cai-buybox-breadcrumbs {
			font-size: 13px;
			color: rgba(248, 248, 248, 0.5);
			margin-bottom: 20px;
		}
		.as-cai-buybox-breadcrumbs a {
			color: #B19E63;
			text-decoration: none;
			transition: color 0.15s;
		}
		.as-cai-buybox-breadcrumbs a:hover {
			color: #d4c07a;
		}
		.as-cai-buybox-bc-sep {
			margin: 0 6px;
			color: rgba(248, 248, 248, 0.3);
		}

		.as-cai-buybox-description {
			font-size: 14px;
			color: rgba(248, 248, 248, 0.75);
			line-height: 1.7;
		}
		.as-cai-buybox-description p {
			margin: 0 0 12px 0;
		}
		.as-cai-buybox-description strong {
			color: #B19E63;
			font-weight: 700;
		}
		.as-cai-buybox-description ul {
			padding-left: 18px;
			margin: 8px 0;
		}
		.as-cai-buybox-description li {
			margin-bottom: 4px;
		}

		.as-cai-buybox-category {
			margin-top: 16px;
			padding-top: 16px;
			border-top: 1px solid rgba(255, 255, 255, 0.08);
			font-size: 14px;
			color: rgba(248, 248, 248, 0.6);
		}
		.as-cai-buybox-category strong {
			color: rgba(248, 248, 248, 0.5);
			font-weight: 400;
			margin-right: 4px;
		}
		.as-cai-buybox-category a {
			color: #B19E63;
			text-decoration: none;
		}

		/* Rechte Spalte */
		.as-cai-buybox-right {
			display: flex;
			flex-direction: column;
			padding-left: 32px;
		}
		.as-cai-buybox-right .as-cai-buybox-status {
			display: flex;
			flex-direction: column;
			flex: 1;
		}
		.as-cai-buybox .as-cai-status-box .status-meta {
			margin-top: auto;
			padding-top: 16px;
			border-top: 1px solid rgba(255, 255, 255, 0.06);
		}

		/* Preis innerhalb Status-Box */
		.as-cai-status-price {
			font-size: 24px;
			font-weight: 700;
			color: #B19E63;
			margin-bottom: 16px;
			padding-bottom: 16px;
			border-bottom: 1px solid rgba(177, 158, 99, 0.12);
		}
		.as-cai-status-price del {
			color: rgba(248, 248, 248, 0.4);
			font-weight: 400;
		}
		.as-cai-status-price ins {
			text-decoration: none;
		}

		/* Status-Box innerhalb BuyBox: kein eigener Container */
		.as-cai-buybox .as-cai-status-box {
			background: transparent;
			border: none;
			border-radius: 0;
			box-shadow: none;
			padding: 16px 0 16px 16px;
			margin: 0;
			border-left: 3px solid;
			display: flex;
			flex-direction: column;
			flex: 1;
		}

		/* Nativer WooCommerce / Stachethemes Add-to-Cart Button */
		.as-cai-buybox .as-cai-add-to-cart-wrapper {
			margin-top: 4px;
		}
		.as-cai-buybox .stachesepl-add-to-cart-button-wrapper {
			margin: 0;
			padding: 0;
		}
		.as-cai-buybox .stachesepl-select-seats-button {
			width: 100%;
		}

		/* ── BuyBox Countdown — Prominent ── */
		.as-cai-buybox-countdown {
			background: rgba(177, 158, 99, 0.08);
			border: 1px solid rgba(177, 158, 99, 0.2);
			border-radius: 12px;
			padding: 24px;
			text-align: center;
		}

		.as-cai-buybox-cd-label {
			font-size: 14px;
			font-weight: 600;
			color: rgba(248, 248, 248, 0.6);
			letter-spacing: 1.5px;
			margin-bottom: 16px;
		}

		.as-cai-buybox-cd-timer {
			display: flex;
			justify-content: center;
			align-items: center;
			gap: 8px;
		}

		.as-cai-buybox-cd-unit {
			display: flex;
			flex-direction: column;
			align-items: center;
			min-width: 60px;
		}

		.as-cai-buybox-cd-value {
			font-size: 36px;
			font-weight: 700;
			color: #B19E63;
			line-height: 1;
			font-variant-numeric: tabular-nums;
			background: rgba(0, 0, 0, 0.3);
			border-radius: 8px;
			padding: 10px 14px;
			min-width: 56px;
			display: inline-block;
			text-align: center;
		}

		.as-cai-buybox-cd-unit-label {
			font-size: 11px;
			font-weight: 600;
			color: rgba(248, 248, 248, 0.4);
			letter-spacing: 0.5px;
			margin-top: 6px;
		}

		.as-cai-buybox-cd-sep {
			font-size: 28px;
			font-weight: 700;
			color: rgba(177, 158, 99, 0.4);
			align-self: flex-start;
			padding-top: 8px;
		}

		.as-cai-buybox-cd-after {
			font-size: 13px;
			color: rgba(248, 248, 248, 0.5);
			margin-top: 14px;
		}

		/* ── Mobile Responsive ── */
		@media (max-width: 768px) {
			.as-cai-buybox {
				grid-template-columns: 1fr;
				padding: 20px;
			}
			.as-cai-buybox-left {
				padding-right: 0;
				border-right: none;
				padding-bottom: 20px;
				border-bottom: 1px solid rgba(177, 158, 99, 0.12);
				margin-bottom: 20px;
			}
			.as-cai-buybox-right {
				padding-left: 0;
			}
			.as-cai-buybox-title {
				font-size: 20px;
			}
			.as-cai-status-price {
				font-size: 20px;
			}
			.as-cai-buybox-cd-value {
				font-size: 28px;
				padding: 8px 10px;
				min-width: 44px;
			}
			.as-cai-buybox-cd-unit {
				min-width: 44px;
			}
		}
		';

		wp_register_style( 'as-cai-buybox-inline', false );
		wp_enqueue_style( 'as-cai-buybox-inline' );
		wp_add_inline_style( 'as-cai-buybox-inline', $css );
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

		$label = self::get_unit_label( $product );
		return self::build_status_result( $total, $available, $sold, $reserved, $label );
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

		$label = self::get_unit_label( $product );

		if ( $total <= 0 && $available <= 0 ) {
			// Stock Management aktiv, aber Bestand=0 und keine Orders
			// → Produkt ist ausverkauft (oder noch nicht bestückt).
			return self::build_status_result( 1, 0, 0, 0, $label );
		}

		if ( $total <= 0 ) {
			$total = $available;
		}

		// Reservierte Einheiten aus unserem Reservierungssystem.
		$reserved = self::count_own_reserved_seats( $product_id );

		return self::build_status_result( $total, $available, $sold, $reserved, $label );
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
