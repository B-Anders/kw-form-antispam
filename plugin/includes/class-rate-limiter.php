<?php
/**
 * Rate limiting for the public challenge endpoint.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fixed-window counter, keyed on a salted hash of the client address.
 *
 * DSGVO note: a raw IP address is never written anywhere. The address is read
 * from the current request, folded into an HMAC keyed with the site's private
 * signing secret *and* the current time window, and only the first 128 bits of
 * that HMAC become a transient name. The transient expires with the window, so
 * two requests from the same visitor in different windows produce unrelated,
 * short-lived keys and nothing links back to a person.
 */
final class Rate_Limiter {

	/**
	 * Transient name prefix.
	 */
	const PREFIX = 'kwfa_rl_';

	/**
	 * Consume one unit from a bucket.
	 *
	 * @param string $bucket Bucket name, e.g. 'challenge'.
	 * @return bool True when the request may proceed, false when the limit is hit.
	 */
	public static function check( $bucket ) {
		$bucket = sanitize_key( $bucket );

		$limit  = (int) apply_filters( 'kwfa_rate_limit_max', 30, $bucket );
		$window = (int) apply_filters( 'kwfa_rate_limit_window', MINUTE_IN_SECONDS, $bucket );

		if ( $limit <= 0 || $window <= 0 ) {
			// Explicitly disabled by a filter.
			return true;
		}

		$key = self::bucket_key( $bucket, $window );

		if ( '' === $key ) {
			// No usable client address (CLI, odd proxy setup). Do not block.
			return true;
		}

		$count = get_transient( $key );
		$count = is_numeric( $count ) ? (int) $count : 0;

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, $window );

		return true;
	}

	/**
	 * How long a blocked client should wait, in seconds.
	 *
	 * @param string $bucket Bucket name.
	 * @return int
	 */
	public static function retry_after( $bucket ) {
		$window = (int) apply_filters( 'kwfa_rate_limit_window', MINUTE_IN_SECONDS, sanitize_key( $bucket ) );

		return max( 1, $window );
	}

	/**
	 * Build the transient name for the current client and window.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $window Window length in seconds.
	 * @return string Empty string when no address is available.
	 */
	private static function bucket_key( $bucket, $window ) {
		$ip = self::client_ip();

		if ( '' === $ip ) {
			return '';
		}

		$secret = Secret::get();

		if ( '' === $secret ) {
			// Still never a raw address: fall back to a WordPress salt.
			$secret = wp_salt( 'auth' );
		}

		$slot = (int) floor( time() / max( 1, $window ) );

		$hash = hash_hmac(
			'sha256',
			$ip,
			$secret . '|kwfa-rate-limit|' . $bucket . '|' . $slot
		);

		return self::PREFIX . substr( $hash, 0, 32 );
	}

	/**
	 * Resolve the client address.
	 *
	 * Only `REMOTE_ADDR` is trusted by default. Forwarded-for headers are
	 * attacker-controlled and would let a spammer both evade the limit and
	 * exhaust the transient table; sites behind a known reverse proxy can opt
	 * in through the `kwfa_client_ip` filter.
	 *
	 * @return string Validated IP address, or an empty string.
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		/**
		 * Filters the client address used for rate limiting.
		 *
		 * The value is never stored; it is immediately hashed. Return an empty
		 * string to disable rate limiting for the current request.
		 *
		 * @param string $ip Address from REMOTE_ADDR.
		 */
		$ip = apply_filters( 'kwfa_client_ip', $ip );

		if ( ! is_string( $ip ) ) {
			return '';
		}

		$valid = filter_var( trim( $ip ), FILTER_VALIDATE_IP );

		return is_string( $valid ) ? $valid : '';
	}
}
