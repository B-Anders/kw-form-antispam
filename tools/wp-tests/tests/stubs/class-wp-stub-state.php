<?php
/**
 * In-memory state behind the WordPress function stubs.
 *
 * Everything the plugin can read or write through WordPress lives here, so a
 * test can inspect it directly and reset it between cases.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

/**
 * Mutable state for the stubbed WordPress environment.
 */
final class WP_Stub_State {

	/**
	 * Options table. Values are keyed by option name.
	 *
	 * @var array<string,mixed>
	 */
	public static $options = array();

	/**
	 * Autoload flag per option, so tests can assert the secret is not autoloaded.
	 *
	 * @var array<string,string>
	 */
	public static $autoload = array();

	/**
	 * Transients: name => array{value: mixed, ttl: int, set_at: int}.
	 *
	 * @var array<string,array>
	 */
	public static $transients = array();

	/**
	 * Registered actions and filters: hook => priority => list of callbacks.
	 *
	 * @var array<string,array<int,array>>
	 */
	public static $hooks = array();

	/**
	 * Scripts passed to wp_register_script().
	 *
	 * @var array<string,array>
	 */
	public static $registered_scripts = array();

	/**
	 * Handles passed to wp_enqueue_script(), in order.
	 *
	 * @var string[]
	 */
	public static $enqueued_scripts = array();

	/**
	 * Data passed to wp_localize_script(): handle => array{object_name, data}.
	 *
	 * @var array<string,array>
	 */
	public static $localized = array();

	/**
	 * Messages passed to wp_admin_notice().
	 *
	 * @var string[]
	 */
	public static $admin_notices = array();

	/**
	 * Value returned by determine_locale().
	 *
	 * @var string
	 */
	public static $locale = 'de_DE';

	/**
	 * Value returned by is_admin().
	 *
	 * @var bool
	 */
	public static $is_admin = false;

	/**
	 * Value returned by is_feed().
	 *
	 * @var bool
	 */
	public static $is_feed = false;

	/**
	 * Value returned by current_user_can() for any capability.
	 *
	 * @var bool
	 */
	public static $user_can = false;

	/**
	 * When true, set_transient() refuses every write. Used to exercise the
	 * "single-use store unavailable" degradation path.
	 *
	 * @var bool
	 */
	public static $transients_readonly = false;

	/**
	 * Overrides the proof-of-work cost through the kwfa_challenge_cost filter.
	 *
	 * Most tests do not care how hard the puzzle is and would rather not spend
	 * a second solving it. Null means "use the plugin's shipped default", which
	 * the end-to-end test relies on.
	 *
	 * @var int|null
	 */
	public static $forced_cost = null;

	/**
	 * Reset everything except registered hooks.
	 *
	 * Hooks survive because the plugin registers them once per PHP process,
	 * exactly as WordPress does once per request.
	 *
	 * @return void
	 */
	public static function reset_data() {
		self::$options             = array();
		self::$autoload            = array();
		self::$transients          = array();
		self::$registered_scripts  = array();
		self::$enqueued_scripts    = array();
		self::$localized           = array();
		self::$admin_notices       = array();
		self::$locale              = 'de_DE';
		self::$is_admin            = false;
		self::$is_feed             = false;
		self::$user_can            = false;
		self::$transients_readonly = false;
		self::$forced_cost         = null;
	}

	/**
	 * Transient names currently stored, filtered by prefix.
	 *
	 * @param string $prefix Prefix to match.
	 * @return string[]
	 */
	public static function transient_names( $prefix = '' ) {
		$names = array_keys( self::$transients );

		if ( '' === $prefix ) {
			return $names;
		}

		return array_values(
			array_filter(
				$names,
				function ( $name ) use ( $prefix ) {
					return 0 === strpos( $name, $prefix );
				}
			)
		);
	}
}
