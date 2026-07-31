<?php
/**
 * WordPress function stubs.
 *
 * Only the functions the plugin actually calls are defined, and each behaves
 * close enough to the real thing for the behaviour under test to be meaningful.
 * The hook system is real (priorities, accepted_args), so tests dispatch through
 * add_action/add_filter exactly as WordPress would and therefore verify that the
 * plugin registered its callbacks correctly, not just that the methods work.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

// -----------------------------------------------------------------------------
// Time constants
// -----------------------------------------------------------------------------

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 60 * MINUTE_IN_SECONDS );
define( 'DAY_IN_SECONDS', 24 * HOUR_IN_SECONDS );

// -----------------------------------------------------------------------------
// Hooks
// -----------------------------------------------------------------------------

/**
 * Register a callback on a hook.
 *
 * @param string   $hook          Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Number of arguments the callback accepts.
 * @return true
 */
function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	WP_Stub_State::$hooks[ $hook ][ $priority ][] = array(
		'callback'      => $callback,
		'accepted_args' => $accepted_args,
	);

	return true;
}

/**
 * Alias of add_filter(), as in WordPress.
 *
 * @param string   $hook          Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Number of arguments the callback accepts.
 * @return true
 */
function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

/**
 * Run every callback on a hook, threading the first argument through.
 *
 * @param string $hook  Hook name.
 * @param mixed  $value Value to filter.
 * @param mixed  ...$args Additional arguments.
 * @return mixed
 */
function apply_filters( $hook, $value = null, ...$args ) {
	if ( empty( WP_Stub_State::$hooks[ $hook ] ) ) {
		return $value;
	}

	$by_priority = WP_Stub_State::$hooks[ $hook ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$all   = array_merge( array( $value ), $args );
			$slice = array_slice( $all, 0, max( 1, (int) $entry['accepted_args'] ) );
			$value = call_user_func_array( $entry['callback'], $slice );
		}
	}

	return $value;
}

/**
 * Run every callback on a hook, discarding return values.
 *
 * @param string $hook Hook name.
 * @param mixed  ...$args Arguments.
 * @return void
 */
function do_action( $hook, ...$args ) {
	if ( empty( WP_Stub_State::$hooks[ $hook ] ) ) {
		return;
	}

	$by_priority = WP_Stub_State::$hooks[ $hook ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$slice = array_slice( $args, 0, max( 1, (int) $entry['accepted_args'] ) );
			call_user_func_array( $entry['callback'], $slice );
		}
	}
}

/**
 * Does a hook have any callbacks?
 *
 * @param string $hook Hook name.
 * @return bool
 */
function has_filter( $hook ) {
	return ! empty( WP_Stub_State::$hooks[ $hook ] );
}

/**
 * Alias of has_filter(), as in WordPress.
 *
 * @param string $hook Hook name.
 * @return bool
 */
function has_action( $hook ) {
	return has_filter( $hook );
}

/**
 * Priorities a hook has callbacks registered on.
 *
 * Test-only helper; no WordPress equivalent.
 *
 * @param string $hook Hook name.
 * @return int[]
 */
function wp_stub_hook_priorities( $hook ) {
	if ( empty( WP_Stub_State::$hooks[ $hook ] ) ) {
		return array();
	}

	$priorities = array_keys( WP_Stub_State::$hooks[ $hook ] );
	sort( $priorities );

	return $priorities;
}

// -----------------------------------------------------------------------------
// Options
// -----------------------------------------------------------------------------

/**
 * Read an option.
 *
 * @param string $name    Option name.
 * @param mixed  $default Default value.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, WP_Stub_State::$options )
		? WP_Stub_State::$options[ $name ]
		: $default;
}

/**
 * Create an option.
 *
 * @param string $name       Option name.
 * @param mixed  $value      Value.
 * @param string $deprecated Unused.
 * @param string $autoload   'yes' or 'no'.
 * @return bool
 */
function add_option( $name, $value, $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, WP_Stub_State::$options ) ) {
		return false;
	}

	WP_Stub_State::$options[ $name ]  = $value;
	WP_Stub_State::$autoload[ $name ] = is_bool( $autoload ) ? ( $autoload ? 'yes' : 'no' ) : (string) $autoload;

	return true;
}

/**
 * Create or update an option.
 *
 * @param string    $name     Option name.
 * @param mixed     $value    Value.
 * @param bool|null $autoload Autoload flag.
 * @return bool
 */
function update_option( $name, $value, $autoload = null ) {
	$existing = array_key_exists( $name, WP_Stub_State::$options ) ? WP_Stub_State::$options[ $name ] : null;

	WP_Stub_State::$options[ $name ] = $value;

	if ( null !== $autoload ) {
		WP_Stub_State::$autoload[ $name ] = is_bool( $autoload ) ? ( $autoload ? 'yes' : 'no' ) : (string) $autoload;
	}

	return $existing !== $value;
}

/**
 * Delete an option.
 *
 * @param string $name Option name.
 * @return bool
 */
function delete_option( $name ) {
	if ( ! array_key_exists( $name, WP_Stub_State::$options ) ) {
		return false;
	}

	unset( WP_Stub_State::$options[ $name ], WP_Stub_State::$autoload[ $name ] );

	return true;
}

// -----------------------------------------------------------------------------
// Transients
// -----------------------------------------------------------------------------

/**
 * Read a transient, honouring its TTL.
 *
 * @param string $name Transient name.
 * @return mixed False when absent or expired.
 */
function get_transient( $name ) {
	if ( ! isset( WP_Stub_State::$transients[ $name ] ) ) {
		return false;
	}

	$entry = WP_Stub_State::$transients[ $name ];

	if ( $entry['ttl'] > 0 && ( $entry['set_at'] + $entry['ttl'] ) <= time() ) {
		unset( WP_Stub_State::$transients[ $name ] );
		return false;
	}

	return $entry['value'];
}

/**
 * Write a transient.
 *
 * @param string $name  Transient name.
 * @param mixed  $value Value.
 * @param int    $ttl   Lifetime in seconds.
 * @return bool
 */
function set_transient( $name, $value, $ttl = 0 ) {
	if ( WP_Stub_State::$transients_readonly ) {
		return false;
	}

	WP_Stub_State::$transients[ $name ] = array(
		'value'  => $value,
		'ttl'    => (int) $ttl,
		'set_at' => time(),
	);

	return true;
}

/**
 * Delete a transient.
 *
 * @param string $name Transient name.
 * @return bool
 */
function delete_transient( $name ) {
	if ( ! isset( WP_Stub_State::$transients[ $name ] ) ) {
		return false;
	}

	unset( WP_Stub_State::$transients[ $name ] );

	return true;
}

// -----------------------------------------------------------------------------
// Sanitising and escaping
// -----------------------------------------------------------------------------

/**
 * Sanitise a key.
 *
 * @param string $key Raw key.
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * Sanitise a single-line text field.
 *
 * @param string $value Raw value.
 * @return string
 */
function sanitize_text_field( $value ) {
	$value = (string) $value;
	$value = wp_strip_all_tags( $value );
	$value = preg_replace( '/[\r\n\t ]+/', ' ', $value );

	return trim( $value );
}

/**
 * Strip tags.
 *
 * @param string $value Raw value.
 * @return string
 */
function wp_strip_all_tags( $value ) {
	return strip_tags( (string) $value );
}

/**
 * Reverse the slashes WordPress adds to superglobals.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * Cast to a non-negative integer.
 *
 * @param mixed $value Value.
 * @return int
 */
function absint( $value ) {
	return abs( (int) $value );
}

/**
 * Escape for HTML output.
 *
 * @param string $value Value.
 * @return string
 */
function esc_html( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escape for an HTML attribute.
 *
 * @param string $value Value.
 * @return string
 */
function esc_attr( $value ) {
	return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
}

/**
 * Escape a URL for output.
 *
 * @param string $url URL.
 * @return string
 */
function esc_url( $url ) {
	return str_replace( '&', '&#038;', (string) $url );
}

/**
 * JSON-encode the WordPress way.
 *
 * @param mixed $value Value.
 * @return string|false
 */
function wp_json_encode( $value ) {
	return json_encode( $value );
}

// -----------------------------------------------------------------------------
// Translation
// -----------------------------------------------------------------------------

/**
 * Translate.
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 * @return string
 */
function __( $text, $domain = 'default' ) {
	return $text;
}

/**
 * Translate and escape for HTML.
 *
 * @param string $text   Text.
 * @param string $domain Text domain.
 * @return string
 */
function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

/**
 * Resolve the current locale.
 *
 * @return string
 */
function determine_locale() {
	return WP_Stub_State::$locale;
}

/**
 * Load a plugin text domain.
 *
 * @return bool
 */
function load_plugin_textdomain() {
	return true;
}

// -----------------------------------------------------------------------------
// Context
// -----------------------------------------------------------------------------

/**
 * Are we in wp-admin?
 *
 * @return bool
 */
function is_admin() {
	return WP_Stub_State::$is_admin;
}

/**
 * Are we rendering a feed?
 *
 * @return bool
 */
function is_feed() {
	return WP_Stub_State::$is_feed;
}

/**
 * Is this a multisite install?
 *
 * @return bool
 */
function is_multisite() {
	return false;
}

/**
 * Capability check.
 *
 * @param string $capability Capability.
 * @return bool
 */
function current_user_can( $capability ) {
	return WP_Stub_State::$user_can;
}

/**
 * A stable salt.
 *
 * @param string $scheme Salt scheme.
 * @return string
 */
function wp_salt( $scheme = 'auth' ) {
	return 'stub-salt-' . $scheme;
}

// -----------------------------------------------------------------------------
// Plugin API
// -----------------------------------------------------------------------------

/**
 * Path to a plugin directory.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_dir_path( $file ) {
	return rtrim( str_replace( '\\', '/', dirname( $file ) ), '/' ) . '/';
}

/**
 * URL of a plugin directory.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_dir_url( $file ) {
	return 'https://example.test/wp-content/plugins/kw-form-antispam/';
}

/**
 * Plugin basename.
 *
 * @param string $file Plugin file.
 * @return string
 */
function plugin_basename( $file ) {
	return 'kw-form-antispam/kw-form-antispam.php';
}

/**
 * Register an activation hook.
 *
 * @param string   $file     Plugin file.
 * @param callable $callback Callback.
 * @return void
 */
function register_activation_hook( $file, $callback ) {
	WP_Stub_State::$hooks['activate'][10][] = array(
		'callback'      => $callback,
		'accepted_args' => 1,
	);
}

// -----------------------------------------------------------------------------
// REST
// -----------------------------------------------------------------------------

/**
 * Build a REST URL.
 *
 * @param string $path Route path.
 * @return string
 */
function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}

/**
 * Append a query argument.
 *
 * @param string $key   Argument name.
 * @param mixed  $value Argument value.
 * @param string $url   URL.
 * @return string
 */
function add_query_arg( $key, $value, $url ) {
	$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';

	return $url . $separator . rawurlencode( $key ) . '=' . rawurlencode( (string) $value );
}

/**
 * Register a REST route.
 *
 * @param string $namespace Namespace.
 * @param string $route     Route.
 * @param array  $args      Route arguments.
 * @return true
 */
function register_rest_route( $namespace, $route, $args = array() ) {
	WP_Stub_State::$hooks[ 'rest_route:' . $namespace . $route ][10][] = array(
		'callback'      => '__return_true',
		'accepted_args' => 1,
	);

	return true;
}

// -----------------------------------------------------------------------------
// Output
// -----------------------------------------------------------------------------

/**
 * Send a JSON error response. WordPress exits here; the stub throws.
 *
 * @param mixed $data Payload.
 * @throws WP_Json_Exit Always.
 * @return void
 */
function wp_send_json_error( $data = null ) {
	throw new WP_Json_Exit( is_array( $data ) ? $data : array( 'data' => $data ) );
}

/**
 * Render an admin notice.
 *
 * @param string $message Message. Not escaped by WordPress.
 * @param array  $args    Notice arguments.
 * @return void
 */
function wp_admin_notice( $message, $args = array() ) {
	WP_Stub_State::$admin_notices[] = (string) $message;
}

// -----------------------------------------------------------------------------
// Assets
// -----------------------------------------------------------------------------

/**
 * Register a script.
 *
 * @param string $handle    Handle.
 * @param string $src       URL.
 * @param array  $deps      Dependencies.
 * @param string $version   Version.
 * @param bool   $in_footer Whether to print in the footer.
 * @return true
 */
function wp_register_script( $handle, $src = '', $deps = array(), $version = false, $in_footer = false ) {
	WP_Stub_State::$registered_scripts[ $handle ] = array(
		'src'       => $src,
		'deps'      => $deps,
		'version'   => $version,
		'in_footer' => $in_footer,
	);

	return true;
}

/**
 * Enqueue a script.
 *
 * @param string $handle Handle.
 * @return void
 */
function wp_enqueue_script( $handle ) {
	WP_Stub_State::$enqueued_scripts[] = $handle;
}

/**
 * Attach data to a script.
 *
 * @param string $handle      Handle.
 * @param string $object_name JavaScript object name.
 * @param array  $data        Data.
 * @return true
 */
function wp_localize_script( $handle, $object_name, $data ) {
	WP_Stub_State::$localized[ $handle ] = array(
		'object_name' => $object_name,
		'data'        => $data,
	);

	return true;
}

/**
 * Always true. Used as a REST permission callback.
 *
 * @return true
 */
function __return_true() {
	return true;
}
