<?php
/**
 * Plugin Name:       KW Form Antispam
 * Plugin URI:        https://kreiswolke.com/kw-form-antispam/
 * Description:       Proof-of-work spam protection for Kadence Advanced Form blocks. Challenges are issued and verified by your own server: no third-party service, no outbound requests, no cookies, no personal data.
 * Version:           0.2.0
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Kreiswolke
 * Author URI:        https://kreiswolke.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       kw-form-antispam
 * Domain Path:       /languages
 *
 * @package Kreiswolke\FormAntispam
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'KWFA_VERSION' ) ) {
	// Another copy of this plugin is already loaded.
	return;
}

define( 'KWFA_VERSION', '0.2.0' );
define( 'KWFA_PLUGIN_FILE', __FILE__ );
define( 'KWFA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KWFA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Minimal PSR-4-ish autoloader following WordPress file-naming conventions.
 *
 * `Kreiswolke\FormAntispam\Rate_Limiter`   -> includes/class-rate-limiter.php
 * `Kreiswolke\FormAntispam\Altcha\Verifier` -> includes/altcha/class-verifier.php
 *
 * No Composer is shipped with this plugin.
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function kwfa_autoload( $class_name ) {
	$prefix = 'Kreiswolke\\FormAntispam\\';

	if ( 0 !== strpos( $class_name, $prefix ) ) {
		return;
	}

	$relative = substr( $class_name, strlen( $prefix ) );
	$parts    = explode( '\\', $relative );
	$base     = array_pop( $parts );
	$dir      = KWFA_PLUGIN_DIR . 'includes/';

	foreach ( $parts as $part ) {
		$dir .= strtolower( str_replace( '_', '-', $part ) ) . '/';
	}

	$slug = strtolower( str_replace( '_', '-', $base ) );

	$candidates = array(
		$dir . 'class-' . $slug . '.php',
		$dir . 'interface-' . $slug . '.php',
		$dir . 'trait-' . $slug . '.php',
		$dir . $slug . '.php',
	);

	foreach ( $candidates as $candidate ) {
		if ( is_readable( $candidate ) ) {
			require_once $candidate;
			return;
		}
	}
}
spl_autoload_register( 'kwfa_autoload' );

/**
 * Activation: make sure a strong HMAC secret exists.
 *
 * @param bool $network_wide Whether the plugin was network-activated.
 * @return void
 */
function kwfa_activate( $network_wide = false ) {
	if ( $network_wide && is_multisite() ) {
		$site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( $site_id );
			\Kreiswolke\FormAntispam\Secret::ensure();
			restore_current_blog();
		}
		return;
	}

	\Kreiswolke\FormAntispam\Secret::ensure();
}
register_activation_hook( __FILE__, 'kwfa_activate' );

/**
 * Make sure a freshly created site in a network gets its own secret.
 *
 * @param \WP_Site $new_site The new site object.
 * @return void
 */
function kwfa_on_new_site( $new_site ) {
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active_for_network( plugin_basename( KWFA_PLUGIN_FILE ) ) ) {
		return;
	}
	switch_to_blog( (int) $new_site->blog_id );
	\Kreiswolke\FormAntispam\Secret::ensure();
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'kwfa_on_new_site', 100 );

/**
 * Boot the plugin.
 *
 * @return void
 */
function kwfa_bootstrap() {
	\Kreiswolke\FormAntispam\Plugin::init();
}
add_action( 'plugins_loaded', 'kwfa_bootstrap' );
