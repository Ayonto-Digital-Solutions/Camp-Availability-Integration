<?php
/**
 * Availability Check functionality.
 *
 * @package AS_Camp_Availability_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class for checking product availability based on Availability Scheduler settings.
 */
class AS_CAI_Availability_Check {

	/**
	 * Check if a product is currently available based on Availability Scheduler settings.
	 *
	 * @param int $product_id Product ID.
	 * @return array {
	 *     Availability data.
	 *     @type bool   $is_available      Whether the product is available.
	 *     @type bool   $has_counter       Whether a counter should be displayed.
	 *     @type string $counter_display   Counter display mode.
	 *     @type string $start_date        Start date (Y-m-d format).
	 *     @type string $start_time        Start time (H:i format).
	 *     @type string $end_date          End date (Y-m-d format).
	 *     @type string $end_time          End time (H:i format).
	 *     @type string $text_before       Text before counter.
	 *     @type string $text_after        Text after counter.
	 * }
	 */
	public static function get_product_availability( $product_id ) {
		$debug = AS_CAI_Debug::instance();
		$debug->log( 'get_product_availability() called', 'info', array( 'product_id' => $product_id ) );

		$result = array(
			'is_available'    => true,
			'has_counter'     => false,
			'counter_display' => '',
			'start_date'      => '',
			'start_time'      => '',
			'end_date'        => '',
			'end_time'        => '',
			'text_before'     => '',
			'text_after'      => '',
		);

		// v1.3.30: Check OUR availability system FIRST
		if ( class_exists( 'AS_CAI_Product_Availability' ) ) {
			$our_data = AS_CAI_Product_Availability::instance()->get_availability_data( $product_id );
			
			if ( $our_data !== null ) {
				// We're using our own system for this product
				$debug->log( 'Using Ayonto Camp Availability system', 'info', $our_data );
				
				return array(
					'is_available'    => $our_data['is_available'],
					'has_counter'     => $our_data['seconds_until'] > 0, // Show counter if not yet available
					'counter_display' => 'avail_bfr_prod', // Show before availability (Product-level mode)
					'start_date'      => $our_data['start_date'],
					'start_time'      => $our_data['start_time'],
					'end_date'        => $our_data['start_date'], // Same as start for now
					'end_time'        => $our_data['start_time'],
					'text_before'     => get_option( 'as_cai_countdown_text', __( 'Verfügbar in:', 'as-camp-availability-integration' ) ),
					'text_after'      => '',
				);
			}
		}

		// Fallback to Koalaapps Scheduler if our system is not used
		$debug->log( 'Falling back to Koalaapps Scheduler', 'info' );

		// Get current time values in WordPress timezone using DateTime for consistency
		$wp_timezone = wp_timezone();
		try {
			$current_datetime_obj = new DateTime( 'now', $wp_timezone );
			$current_timestamp = $current_datetime_obj->getTimestamp(); // Real Unix timestamp
		} catch ( Exception $e ) {
			$current_timestamp = time(); // Fallback to server time
		}
		
		$current_date = current_time( 'Y-m-d' );
		$current_day  = current_time( 'l' );

		// Check product-level settings first.
		$enable_product_level = get_post_meta( $product_id, 'af_aps_enb_prod_lvl', true );

		$debug->log(
			'Product-level settings check',
			'info',
			array(
				'enabled' => $enable_product_level,
			)
		);

		if ( 'yes' === $enable_product_level ) {
			$availability_data = self::check_product_level_availability(
				$product_id,
				$current_date,
				$current_timestamp,
				$current_day
			);

			if ( $availability_data ) {
				$debug->log( 'Product-level availability data found', 'info', $availability_data );
				return $availability_data;
			}
		}

		// Check rule-based settings.
		$rules = get_posts(
			array(
				'post_type'   => 'af_product_scheduler',
				'fields'      => 'ids',
				'post_status' => 'publish',
				'numberposts' => -1,
				'orderby'     => 'menu_order',
				'order'       => 'ASC',
			)
		);

		$debug->log( 'Checking rules', 'info', array( 'rule_count' => count( $rules ) ) );

		if ( ! empty( $rules ) && class_exists( 'Af_Aps_Scheduler_General_Functions' ) ) {
			foreach ( $rules as $rule_id ) {
				if ( Af_Aps_Scheduler_General_Functions::af_aps_rule_validations( $rule_id, $product_id ) ) {
					$debug->log( 'Rule validation passed', 'info', array( 'rule_id' => $rule_id ) );

					$availability_data = self::check_rule_availability(
						$rule_id,
						$product_id,
						$current_date,
						$current_timestamp,
						$current_day
					);

					if ( $availability_data ) {
						$debug->log( 'Rule-level availability data found', 'info', $availability_data );
						return $availability_data;
					}
				}
			}
		}

		$debug->log( 'No availability settings found - using defaults', 'warning', $result );
		return $result;
	}

	/**
	 * Check product-level availability settings.
	 *
	 * @param int    $product_id         Product ID.
	 * @param string $current_date       Current date.
	 * @param int    $current_timestamp  Current timestamp (WordPress timezone).
	 * @param string $current_day        Current day name.
	 * @return array|null Availability data or null if not applicable.
	 */
	private static function check_product_level_availability( $product_id, $current_date, $current_timestamp, $current_day ) {
		if ( ! class_exists( 'Af_Aps_Scheduler_General_Functions' ) ) {
			return null;
		}

		if ( ! Af_Aps_Scheduler_General_Functions::af_aps_prod_lvl_validations( $product_id ) ) {
			return null;
		}

		$availability        = get_post_meta( $product_id, 'af_aps_prod_lvl_availability', true );
		$enable_counter      = get_post_meta( $product_id, 'af_aps_enb_counter_prod_lvl', true );
		$counter_display     = get_post_meta( $product_id, 'af_aps_avail_aftr_bfr_prod_lvl', true );
		$text_before_counter = get_post_meta( $product_id, 'af_aps_text_before_counter_prod_lvl', true );
		$text_after_counter  = get_post_meta( $product_id, 'af_aps_text_after_counter_prod_lvl', true );
		$specific_days       = (array) get_post_meta( $product_id, 'af_aps_specific_prod_days', true );

		// Get WordPress timezone for proper datetime calculations
		$wp_timezone = wp_timezone();

		// Get start date and time
		$start_date_meta = get_post_meta( $product_id, 'af_aps_start_date_prod_lvl', true );
		$start_date = ! empty( $start_date_meta ) ? $start_date_meta : $current_date;
		
		$start_time_meta = get_post_meta( $product_id, 'af_aps_start_time_prod_lvl', true );
		$start_time_str = ! empty( $start_time_meta ) ? $start_time_meta : '00:00';
		
		// Build start datetime timestamp
		try {
			$start_datetime_obj = new DateTime( $start_date . ' ' . $start_time_str, $wp_timezone );
			$start_timestamp = $start_datetime_obj->getTimestamp();
		} catch ( Exception $e ) {
			$start_timestamp = $current_timestamp; // Fallback to current
		}

		// Get end date and time
		$end_date_meta = get_post_meta( $product_id, 'af_aps_end_date_prod_lvl', true );
		$end_date = ! empty( $end_date_meta ) ? $end_date_meta : $current_date;
		
		$end_time_meta = get_post_meta( $product_id, 'af_aps_end_time_prod_lvl', true );
		$end_time_str = ! empty( $end_time_meta ) ? $end_time_meta : '23:59';
		
		// Build end datetime timestamp
		try {
			$end_datetime_obj = new DateTime( $end_date . ' ' . $end_time_str, $wp_timezone );
			$end_timestamp = $end_datetime_obj->getTimestamp();
		} catch ( Exception $e ) {
			// Fallback: Use end of day
			try {
				$end_datetime_obj = new DateTime( $end_date . ' 23:59:59', $wp_timezone );
				$end_timestamp = $end_datetime_obj->getTimestamp();
			} catch ( Exception $e2 ) {
				$end_timestamp = $current_timestamp;
			}
		}

		// Check if current time is within date/time range
		$is_within_date_time = ( $current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp );

		$is_applicable = $is_within_date_time && ( empty( $specific_days ) || in_array( $current_day, $specific_days, true ) );

		$is_available = true;
		if ( 'aps_prod_lvl_available' === $availability && ! $is_applicable ) {
			$is_available = false;
		} elseif ( 'aps_prod_lvl_unavailable' === $availability && $is_applicable ) {
			$is_available = false;
		}

		// Determine if counter should be shown.
		$has_counter = false;
		if ( 'yes' === $enable_counter ) {
			if ( 'avail_bfr_prod' === $counter_display || 'unavail_bfr_prod' === $counter_display ) {
				// Show counter BEFORE product becomes available (comparing full datetime)
				if ( $current_timestamp < $start_timestamp ) {
					$has_counter = true;
				}
			} elseif ( 'avail_bfr_aftr_prod_both' === $counter_display ) {
				// Show counter until the END date/time (both before and after start)
				if ( $current_timestamp < $end_timestamp ) {
					$has_counter = true;
				}
			} elseif ( 'avail_dur_prod' === $counter_display || 'unavail_dur_prod' === $counter_display ) {
				// Show counter DURING availability (between start and end datetime)
				if ( $current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp ) {
					$has_counter = true;
				}
			}
		}

		return array(
			'is_available'    => $is_available,
			'has_counter'     => $has_counter,
			'counter_display' => $counter_display,
			'start_date'      => $start_date,
			'start_time'      => $start_time_str,
			'end_date'        => $end_date,
			'end_time'        => $end_time_str,
			'text_before'     => $text_before_counter,
			'text_after'      => $text_after_counter,
		);
	}

	/**
	 * Check rule-based availability settings.
	 *
	 * @param int    $rule_id            Rule ID.
	 * @param int    $product_id         Product ID.
	 * @param string $current_date       Current date.
	 * @param int    $current_timestamp  Current timestamp (WordPress timezone).
	 * @param string $current_day        Current day name.
	 * @return array|null Availability data or null if not applicable.
	 */
	private static function check_rule_availability( $rule_id, $product_id, $current_date, $current_timestamp, $current_day ) {
		$availability        = get_post_meta( $rule_id, 'af_aps_prod_availability', true );
		$enable_counter      = get_post_meta( $rule_id, 'af_aps_enable_disble_countr', true );
		$counter_display     = get_post_meta( $rule_id, 'af_aps_countr_disp_aftr_bfr', true );
		$text_before_counter = get_post_meta( $rule_id, 'af_aps_text_before_counter', true );
		$text_after_counter  = get_post_meta( $rule_id, 'af_aps_text_after_counter', true );
		$specific_days       = (array) get_post_meta( $rule_id, 'af_aps_specific_days', true );

		// Get WordPress timezone for proper datetime calculations
		$wp_timezone = wp_timezone();

		// Get start date and time
		$start_date_meta = get_post_meta( $rule_id, 'af_aps_start_date', true );
		$start_date = ! empty( $start_date_meta ) ? $start_date_meta : $current_date;
		
		$start_time_meta = get_post_meta( $rule_id, 'af_aps_start_time', true );
		$start_time_str = ! empty( $start_time_meta ) ? $start_time_meta : '00:00';
		
		// Build start datetime timestamp
		try {
			$start_datetime_obj = new DateTime( $start_date . ' ' . $start_time_str, $wp_timezone );
			$start_timestamp = $start_datetime_obj->getTimestamp();
		} catch ( Exception $e ) {
			$start_timestamp = $current_timestamp; // Fallback to current
		}

		// Get end date and time
		$end_date_meta = get_post_meta( $rule_id, 'af_aps_end_date', true );
		$end_date = ! empty( $end_date_meta ) ? $end_date_meta : $current_date;
		
		$end_time_meta = get_post_meta( $rule_id, 'af_aps_end_time', true );
		$end_time_str = ! empty( $end_time_meta ) ? $end_time_meta : '23:59';
		
		// Build end datetime timestamp
		try {
			$end_datetime_obj = new DateTime( $end_date . ' ' . $end_time_str, $wp_timezone );
			$end_timestamp = $end_datetime_obj->getTimestamp();
		} catch ( Exception $e ) {
			// Fallback: Use end of day
			try {
				$end_datetime_obj = new DateTime( $end_date . ' 23:59:59', $wp_timezone );
				$end_timestamp = $end_datetime_obj->getTimestamp();
			} catch ( Exception $e2 ) {
				$end_timestamp = $current_timestamp;
			}
		}

		// Check if current time is within date/time range
		$is_within_date_time = ( $current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp );

		$is_applicable = $is_within_date_time && ( empty( $specific_days ) || in_array( $current_day, $specific_days, true ) );

		$is_available = true;
		if ( 'aps_prod_available' === $availability && ! $is_applicable ) {
			$is_available = false;
		} elseif ( 'aps_prod_unavailable' === $availability && $is_applicable ) {
			$is_available = false;
		}

		// Determine if counter should be shown.
		$has_counter = false;
		if ( 'yes' === $enable_counter ) {
			if ( 'aps_before_prod_avail' === $counter_display || 'aps_before_prod_unavail' === $counter_display ) {
				// Show counter BEFORE product becomes available (comparing full datetime)
				if ( $current_timestamp < $start_timestamp ) {
					$has_counter = true;
				}
			} elseif ( 'aps_both_bfr_aftr' === $counter_display ) {
				// Show counter until the END date/time (both before and after start)
				if ( $current_timestamp < $end_timestamp ) {
					$has_counter = true;
				}
			} elseif ( 'aps_dur_prod_avail' === $counter_display || 'aps_dur_prod_unavail' === $counter_display ) {
				// Show counter DURING availability (between start and end datetime)
				if ( $current_timestamp >= $start_timestamp && $current_timestamp <= $end_timestamp ) {
					$has_counter = true;
				}
			}
		}

		return array(
			'is_available'    => $is_available,
			'has_counter'     => $has_counter,
			'counter_display' => $counter_display,
			'start_date'      => $start_date,
			'start_time'      => $start_time_str,
			'end_date'        => $end_date,
			'end_time'        => $end_time_str,
			'text_before'     => $text_before_counter,
			'text_after'      => $text_after_counter,
		);
	}
}
