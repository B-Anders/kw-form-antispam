<?php
/**
 * Uninstall routine.
 *
 * Removes the signing secret, the status flag and every transient the plugin
 * created. Nothing else is ever written, so nothing else needs cleaning up.
 *
 * @package Kreiswolke\FormAntispam
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete this plugin's data from the current site.
 *
 * @return void
 */
function kwfa_uninstall_site() {
	global $wpdb;

	delete_option( 'kwfa_hmac_secret' );
	delete_option( 'kwfa_status' );

	$prefixes = array( 'kwfa_rl_', 'kwfa_used_' );

	foreach ( $prefixes as $prefix ) {
		// Transients may live in the options table or in an external object
		// cache. Clean the table directly, then flush the cache. There is no
		// core API for "delete every transient matching a prefix".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			)
		);
	}

	wp_cache_flush();
}

if ( is_multisite() ) {
	$kwfa_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $kwfa_site_ids as $kwfa_site_id ) {
		switch_to_blog( (int) $kwfa_site_id );
		kwfa_uninstall_site();
		restore_current_blog();
	}

	unset( $kwfa_site_ids, $kwfa_site_id );
} else {
	kwfa_uninstall_site();
}
