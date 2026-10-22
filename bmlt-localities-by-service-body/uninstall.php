<?php
/**
 * Removes the plugin's settings and cached BMLT data (on every site of a network).
 *
 * @package BMLT_Localities
 * @version 1.4.0
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( ! function_exists( 'bmlt_localities_uninstall_site' ) ) {
/**
 * Deletes this site's settings and cache.
 *
 * @return void
 */
function bmlt_localities_uninstall_site() {
	global $wpdb;
	delete_option( 'bmlt_localities_settings' );
	delete_option( 'bmlt_localities_cache_generation' );
	// Transients: bmlt_loc_<generation>_<hash> and bmlt_loc_stale_<hash>.
	$names = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_bmlt_loc_' ) . '%'
		)
	);
	foreach ( $names as $name ) {
		delete_transient( substr( $name, strlen( '_transient_' ) ) );
	}
}
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $bmlt_localities_site ) {
		switch_to_blog( (int) $bmlt_localities_site );
		bmlt_localities_uninstall_site();
		restore_current_blog();
	}
} else {
	bmlt_localities_uninstall_site();
}
