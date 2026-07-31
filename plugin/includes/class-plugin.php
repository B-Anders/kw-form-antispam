<?php
/**
 * Plugin bootstrap and shared configuration.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin together and holds the tunable defaults.
 */
final class Plugin {

	/**
	 * Text domain, repeated as a constant for readability.
	 */
	const TEXT_DOMAIN = 'kw-form-antispam';

	/**
	 * Per-request memo for the operational check.
	 *
	 * @var bool|null
	 */
	private static $operational = null;

	/**
	 * Hook everything up. Runs on `plugins_loaded`.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ), 0 );

		Status::init();

		if ( ! self::kadence_active() ) {
			Status::report( 'kadence_missing' );
			return;
		}

		if ( 'kadence_missing' === Status::get_code() ) {
			Status::clear();
		}

		Secret::init();
		Rest_Challenge::init();
		Frontend::init();
		Gate::init();
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public static function load_textdomain() {
		load_plugin_textdomain(
			'kw-form-antispam',
			false,
			dirname( plugin_basename( KWFA_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Is Kadence Blocks present?
	 *
	 * @return bool
	 */
	public static function kadence_active() {
		return defined( 'KADENCE_BLOCKS_VERSION' );
	}

	/**
	 * Can the plugin actually protect anything right now?
	 *
	 * Every negative answer also records a reason so the site owner is told
	 * that protection is off and why. See the fail-open policy in readme.txt.
	 *
	 * @return bool
	 */
	public static function is_operational() {
		if ( null !== self::$operational ) {
			return self::$operational;
		}

		self::$operational = false;

		if ( ! self::kadence_active() ) {
			Status::report( 'kadence_missing' );
			return false;
		}

		if ( ! self::protocol_available() ) {
			Status::report( 'core_missing' );
			return false;
		}

		if ( '' === Secret::get() ) {
			Status::report( 'secret_missing' );
			return false;
		}

		self::$operational = true;

		return true;
	}

	/**
	 * Are the protocol classes from includes/altcha/ loadable?
	 *
	 * @return bool
	 */
	public static function protocol_available() {
		return class_exists( __NAMESPACE__ . '\\Altcha\\Challenge' )
			&& class_exists( __NAMESPACE__ . '\\Altcha\\Verifier' );
	}

	/**
	 * Proof-of-work cost (KDF iterations) for new challenges.
	 *
	 * Kept low enough to stay tolerable on an old phone. Raise it on sites that
	 * attract heavy automated traffic.
	 *
	 * @return int
	 */
	public static function cost() {
		$cost = (int) apply_filters( 'kwfa_challenge_cost', 5000 );

		return max( 1000, min( $cost, 250000 ) );
	}

	/**
	 * Challenge lifetime in seconds.
	 *
	 * Long enough that a visitor can finish a longer form after the widget
	 * armed itself, short enough to keep the single-use replay window small.
	 *
	 * @return int
	 */
	public static function expires_in() {
		$seconds = (int) apply_filters( 'kwfa_challenge_expires_in', 600 );

		return max( 60, min( $seconds, HOUR_IN_SECONDS ) );
	}

	/**
	 * Binding string carried inside the signed challenge parameters.
	 *
	 * Ties a challenge to one form so a solution minted for form A cannot be
	 * spent on form B. Contains no personal data — only the form post ID.
	 *
	 * @param int $form_id Kadence form CPT post ID.
	 * @return string Empty string when the form is unknown.
	 */
	public static function binding( $form_id ) {
		$form_id = absint( $form_id );

		return $form_id > 0 ? 'f' . $form_id : '';
	}
}
