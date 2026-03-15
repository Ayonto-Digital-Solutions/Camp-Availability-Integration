<?php
/**
 * Camp Availability Integration Uninstaller
 *
 * This file runs when the plugin is deleted.
 * It removes all plugin data from the database.
 *
 * @package AS_Camp_Availability_Integration
 * @since   1.2.0
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Clean up plugin data.
 */
function as_cai_uninstall_cleanup() {
	// SECURITY FIX v1.3.55: Declare $wpdb at the beginning
	global $wpdb;
	
	// Remove plugin options.
	delete_option( 'as_cai_debug_mode' );
	delete_option( 'as_cai_plugin_version' );
	
	// v1.3.0 - Remove new options
	delete_option( 'as_cai_enable_countdown' );
	delete_option( 'as_cai_countdown_position' );
	delete_option( 'as_cai_countdown_style' );
	delete_option( 'as_cai_enable_cart_reservation' );
	delete_option( 'as_cai_reservation_time' );
	delete_option( 'as_cai_show_cart_timer' );
	delete_option( 'as_cai_cart_timer_style' );
	delete_option( 'as_cai_warning_threshold' );
	delete_option( 'as_cai_enable_debug' );
	delete_option( 'as_cai_debug_log' );
	delete_option( 'as_cai_db_version' );

	// v1.3.59 - Remove new options
	delete_option( 'as_cai_translation_override_enabled' );
	delete_option( 'as_cai_github_repo' );
	delete_option( 'as_cai_github_token' );
	delete_option( 'as_cai_replace_koala_scheduler' );
	delete_option( 'as_cai_countdown_text' );
	
	// v1.3.0 - Drop custom database table
	$table_name = $wpdb->prefix . 'as_cai_cart_reservations';
	$wpdb->query( "DROP TABLE IF EXISTS {$table_name}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	// v1.3.59 - Drop notifications table
	$notifications_table = $wpdb->prefix . 'as_cai_notifications';
	$wpdb->query( "DROP TABLE IF EXISTS {$notifications_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	
	// Remove any transients that might have been set.
	// SECURITY FIX v1.3.55: Use prepared statements with esc_like()
	$transient_pattern = $wpdb->esc_like( '_transient_as_cai_' ) . '%';
	$transient_timeout_pattern = $wpdb->esc_like( '_transient_timeout_as_cai_' ) . '%';
	
	$wpdb->query( 
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE %s 
			OR option_name LIKE %s",
			$transient_pattern,
			$transient_timeout_pattern
		)
	);
	
	// Clean up user meta if any was stored.
	// SECURITY FIX v1.3.55: Use prepared statements with esc_like()
	$usermeta_pattern = $wpdb->esc_like( 'as_cai_' ) . '%';
	
	$wpdb->query( 
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} 
			WHERE meta_key LIKE %s",
			$usermeta_pattern
		)
	);
	
	// Clean up post meta related to the plugin.
	$meta_keys = array(
		'_as_cai_availability_enabled',
		'_as_cai_counter_enabled',
		'_as_cai_availability_mode',
		'_as_cai_debug_data',
	);
	
	foreach ( $meta_keys as $meta_key ) {
		$wpdb->query( 
			$wpdb->prepare( 
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				$meta_key
			)
		);
	}
	
	// Clear any scheduled cron jobs.
	$timestamp = wp_next_scheduled( 'as_cai_daily_cleanup' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'as_cai_daily_cleanup' );
	}
	
	// v1.3.0 - Clear reservation cleanup cron
	$timestamp = wp_next_scheduled( 'as_cai_cleanup_expired_reservations' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'as_cai_cleanup_expired_reservations' );
	}
	
	// Flush rewrite rules.
	flush_rewrite_rules();
	
	// Clear object cache.
	wp_cache_flush();
}

// Run cleanup for single site or network activated.
if ( ! is_multisite() ) {
	as_cai_uninstall_cleanup();
} else {
	// Get all sites in the network.
	$sites = get_sites();
	
	foreach ( $sites as $site ) {
		switch_to_blog( $site->blog_id );
		as_cai_uninstall_cleanup();
		restore_current_blog();
	}
}
