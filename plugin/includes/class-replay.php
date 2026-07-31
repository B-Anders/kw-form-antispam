<?php
/**
 * Single-use enforcement for solved challenges.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks a solved challenge as spent so it cannot be posted twice.
 *
 * Checking and spending are deliberately separate operations. A solution is
 * only spent at the moment Kadence accepts the submission — if Kadence rejects
 * it for an unrelated reason (a missing required field, its own CAPTCHA), the
 * visitor's solution survives and their retry works.
 *
 * Keys derive from the challenge nonce, never from anything about the visitor,
 * and live only as long as the challenge would have been valid anyway.
 */
final class Replay {

	/**
	 * Transient name prefix.
	 */
	const PREFIX = 'kwfa_used_';

	/**
	 * Has this solution already been spent?
	 *
	 * @param string $replay_key Opaque key from the verification result.
	 * @return bool
	 */
	public static function is_spent( $replay_key ) {
		$name = self::name( $replay_key );

		if ( '' === $name ) {
			return false;
		}

		return false !== get_transient( $name );
	}

	/**
	 * Spend a solution.
	 *
	 * @param string $replay_key Opaque key from the verification result.
	 * @param int    $ttl        How long the marker must live, in seconds.
	 * @return bool True on success, false when the store refused the write.
	 */
	public static function spend( $replay_key, $ttl ) {
		$name = self::name( $replay_key );

		if ( '' === $name ) {
			return false;
		}

		$ttl = max( MINUTE_IN_SECONDS, min( (int) $ttl, DAY_IN_SECONDS ) );

		return (bool) set_transient( $name, 1, $ttl );
	}

	/**
	 * Transient name for a replay key.
	 *
	 * @param string $replay_key Opaque key from the verification result.
	 * @return string Empty string when the key is unusable.
	 */
	private static function name( $replay_key ) {
		$replay_key = is_string( $replay_key ) ? trim( $replay_key ) : '';

		if ( '' === $replay_key ) {
			return '';
		}

		return self::PREFIX . substr( hash( 'sha256', $replay_key ), 0, 32 );
	}
}
