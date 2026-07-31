<?php
/**
 * HMAC secret management.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, stores and validates the per-site HMAC secret.
 *
 * The secret is the only thing that makes a challenge unforgeable, so it must
 * never be exposed to the browser and never be autoloaded into every request's
 * option cache.
 */
final class Secret {

	/**
	 * Option name. Stored with autoload disabled.
	 */
	const OPTION = 'kwfa_hmac_secret';

	/**
	 * Minimum acceptable length in hex characters (32 bytes).
	 */
	const MIN_LENGTH = 64;

	/**
	 * Per-request memo.
	 *
	 * @var string|null
	 */
	private static $cache = null;

	/**
	 * Hook the self-repair pass.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_repair' ) );
	}

	/**
	 * Return the stored secret, or an empty string when it is missing or corrupt.
	 *
	 * @return string
	 */
	public static function get() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, '' );

		self::$cache = self::is_valid( $stored ) ? $stored : '';

		return self::$cache;
	}

	/**
	 * Create the secret if it does not exist yet. Never overwrites a valid one.
	 *
	 * @return string The secret, or an empty string when generation failed.
	 */
	public static function ensure() {
		$stored = get_option( self::OPTION, '' );

		if ( self::is_valid( $stored ) ) {
			self::$cache = $stored;
			return $stored;
		}

		$secret = self::generate();

		if ( '' === $secret ) {
			self::$cache = '';
			return '';
		}

		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $secret, '', 'no' );
		} else {
			update_option( self::OPTION, $secret, 'no' );
		}

		self::$cache = $secret;

		return $secret;
	}

	/**
	 * Repair a missing or corrupt secret, but only from an authenticated admin
	 * request. Writing options on anonymous front-end traffic is not something
	 * this plugin does.
	 *
	 * @return void
	 */
	public static function maybe_repair() {
		if ( '' !== self::get() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( '' !== self::ensure() ) {
			Status::clear();
		}
	}

	/**
	 * Generate a fresh secret.
	 *
	 * @return string Hex string, or an empty string if no CSPRNG is available.
	 */
	private static function generate() {
		try {
			return bin2hex( random_bytes( 32 ) );
		} catch ( \Throwable $e ) {
			// No source of cryptographic randomness. Protection stays off and
			// Status reports it; we do not fall back to a weak generator.
			return '';
		}
	}

	/**
	 * Validate a stored value.
	 *
	 * @param mixed $value Candidate secret.
	 * @return bool
	 */
	private static function is_valid( $value ) {
		return is_string( $value )
			&& strlen( $value ) >= self::MIN_LENGTH
			&& ctype_xdigit( $value );
	}
}
