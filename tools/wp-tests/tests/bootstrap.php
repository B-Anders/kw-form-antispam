<?php
/**
 * PHPUnit bootstrap.
 *
 * Loads a stubbed WordPress, then the real plugin — including the real main
 * file, so its autoloader and constant definitions are exercised rather than
 * reimplemented here. The protocol core in plugin/includes/altcha/ is loaded
 * through that same autoloader, which is itself part of what the suite checks.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

// tests/ -> wp-tests/ -> tools/ -> repo root
$kwfa_repo_root   = dirname( dirname( dirname( __DIR__ ) ) );
$kwfa_plugin_root = $kwfa_repo_root . '/plugin';

if ( ! is_readable( $kwfa_plugin_root . '/kw-form-antispam.php' ) ) {
	fwrite( STDERR, "Cannot find the plugin at {$kwfa_plugin_root}\n" );
	exit( 1 );
}

require __DIR__ . '/../vendor/autoload.php';

// WordPress stand-ins. Order matters: state, then classes, then functions.
require __DIR__ . '/stubs/class-wp-stub-state.php';
require __DIR__ . '/stubs/wordpress-classes.php';
require __DIR__ . '/stubs/wordpress-functions.php';

// The plugin's own guard.
define( 'ABSPATH', $kwfa_repo_root . '/' );

// Kadence Blocks, present and recent.
define( 'KADENCE_BLOCKS_VERSION', '3.7.8.2' );

// The real plugin: header constants, autoloader, hook registration.
require $kwfa_plugin_root . '/kw-form-antispam.php';

// Dispatch through the hooks the plugin registered, rather than calling
// Plugin::init() directly, so bootstrap wiring is covered too. These fire once
// per request in WordPress and once per process here.
do_action( 'plugins_loaded' );
do_action( 'init' );

/*
 * Lets a test trade proof-of-work difficulty for speed. Registered once, here,
 * because hooks persist for the whole process; tests toggle it through
 * WP_Stub_State::$forced_cost, which reset_data() clears. Leaving it null keeps
 * the plugin's shipped default, which the end-to-end test depends on.
 */
add_filter(
	'kwfa_challenge_cost',
	function ( $cost ) {
		return null === WP_Stub_State::$forced_cost ? $cost : WP_Stub_State::$forced_cost;
	}
);
