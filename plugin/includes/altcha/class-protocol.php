<?php
/**
 * Low-level ALTCHA v3 protocol primitives.
 *
 * Pure PHP, PHP 7.4 compatible, zero WordPress dependencies, no I/O of any kind.
 * This file deliberately contains no networking calls — the plugin never talks to
 * any ALTCHA cloud service.
 *
 * Everything here mirrors, byte for byte, the behaviour of the official
 * `altcha-org/altcha` PHP library (v2.1.0) and of the `altcha` widget (v3.2.1):
 *
 *   - `Protocol::canonical_json()`      <-> `AltchaOrg\Altcha\ChallengeParameters::canonicalJson()`
 *   - `Protocol::normalize_parameters()`<-> `ChallengeParameters::fromArray()->toArray()`
 *   - `Protocol::derive_key()`          <-> `AltchaOrg\Altcha\Algorithm\Pbkdf2::deriveKey()`
 *   - `Protocol::hmac_hex()`            <-> `AltchaOrg\Altcha\Altcha::hmacHex()`
 *
 * Agreement is enforced by the differential test suite in `tools/oracle/`.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam\Altcha;

/**
 * Protocol constants and stateless primitives.
 */
final class Protocol {

	/**
	 * Key-derivation function advertised to the widget.
	 *
	 * PBKDF2 only. The `SHA-*` KDF path is never used: upstream's PHP truncates the
	 * derived key once after the iteration loop while their JS truncates inside every
	 * iteration, so the two disagree whenever `keyLength` < digest size and `cost` > 1
	 * (see docs/RESEARCH-altcha.md B.3). PBKDF2 has no such divergence.
	 */
	const ALGORITHM = 'PBKDF2/SHA-256';

	/** Hash used inside PBKDF2. */
	const HASH_ALGO = 'sha256';

	/** Hash used for the challenge HMAC signature. */
	const HMAC_ALGO = 'sha256';

	/** Derived key length in bytes. */
	const KEY_LENGTH = 32;

	/**
	 * Difficulty knob #1: the hex prefix the derived key must start with.
	 *
	 * MUST be even-length hex. An odd-length prefix makes `hex2bin()` return false,
	 * which degrades the server-side prefix check to a zero-length comparison that
	 * always passes, while the JS solver still treats it as a hex-string prefix
	 * (docs/RESEARCH-altcha.md B.4 step 6). One byte => 1/256 hit rate => ~256
	 * expected derivations on the client.
	 */
	const KEY_PREFIX = '00';

	/**
	 * Difficulty knob #2: PBKDF2 iterations per derivation.
	 *
	 * Expected client work = 256 (from KEY_PREFIX) x COST iterations = ~1.28M
	 * PBKDF2-SHA256 iterations, spread over up to 16 Web Workers. Server work is
	 * exactly ONE derivation of COST iterations.
	 *
	 * Measured on the dev box (PHP 8.2, ~900k PBKDF2-SHA256 iterations/s/core):
	 *   cost  1000 -> server 1.1 ms/verify, client ~0.3 s single-core
	 *   cost  5000 -> server 5.3 ms/verify, client ~1.4 s single-core   <-- default
	 *   cost 10000 -> server 11.2 ms/verify, client ~2.9 s single-core
	 *
	 * 5000 is the compromise the plan asks for: on a modern desktop the widget
	 * finishes in well under a second, on a slow phone with a single usable worker
	 * the mean is a few seconds (the widget's own abort bound is 90 s), and the
	 * server pays ~5 ms per submission — low enough that the verify path is not
	 * itself a DoS lever. Raising it past ~20000 buys little: a determined spammer
	 * running native code solves any of these in a fraction of a second, so the PoW
	 * is a cost multiplier that works together with rate limiting, not a wall.
	 */
	const DEFAULT_COST = 5000;

	/** Default challenge lifetime, seconds. */
	const DEFAULT_EXPIRES_IN = 600;

	/** Lower clamp for the challenge lifetime, seconds. */
	const MIN_EXPIRES_IN = 1;

	/** Upper clamp for the challenge lifetime, seconds. */
	const MAX_EXPIRES_IN = 86400;

	/** Lower clamp for `cost`. */
	const MIN_COST = 1;

	/**
	 * Upper clamp for `cost`.
	 *
	 * Also enforced on verify. `cost` is covered by the signature so an attacker
	 * cannot inflate it, but this bounds the damage of a misconfigured site.
	 */
	const MAX_COST = 250000;

	/** Hard ceiling on the base64 payload we are willing to look at, in bytes. */
	const MAX_PAYLOAD_BYTES = 32768;

	/**
	 * Key used inside `parameters.data` to carry the plugin's opaque string.
	 *
	 * `parameters.data` must be a JSON object: the widget types it as
	 * `Record<string, string|number|boolean|null>` and the official PHP library
	 * drops it unless it decodes to an array. A bare string there would silently
	 * vanish on the way back in and break the signature.
	 */
	const DATA_KEY = 'd';

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Canonical JSON encoding of a challenge-parameters array.
	 *
	 * Sorts the top level and every nested associative array by key, then encodes
	 * with JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE. Both flags are load
	 * bearing: without them PHP emits `\/` and `\uXXXX` escapes, which JS
	 * `JSON.stringify` does not, and every signature would mismatch.
	 *
	 * @param array $params Parameters array.
	 * @return string Canonical JSON, or '' if encoding failed.
	 */
	public static function canonical_json( array $params ) {
		ksort( $params );
		self::sort_recursive( $params );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() must NOT be used here. This file is deliberately WordPress-free so the protocol can be tested standalone against the official ALTCHA library, and wp_json_encode() applies its own depth/flag handling that would alter the canonical byte sequence the HMAC signature is computed over.
		$json = json_encode( $params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '';
	}

	/**
	 * Recursively ksort nested associative arrays, leaving lists untouched.
	 *
	 * Mirrors ChallengeParameters::sortRecursive() exactly, including the fact that
	 * it does not descend into list-shaped values.
	 *
	 * @param array $data Array, by reference.
	 * @return void
	 */
	private static function sort_recursive( array &$data ) {
		foreach ( $data as &$value ) {
			if ( is_array( $value ) && ! self::is_list( $value ) ) {
				ksort( $value );
				self::sort_recursive( $value );
			}
		}
		unset( $value );
	}

	/**
	 * Polyfill for array_is_list(), which is PHP 8.1+.
	 *
	 * @param array $arr Array to inspect.
	 * @return bool
	 */
	private static function is_list( array $arr ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $arr );
		}

		$expected = 0;
		foreach ( $arr as $key => $unused ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}

		return true;
	}

	/**
	 * Hex-encoded HMAC, matching the official library's hmacHex().
	 *
	 * @param string $data   Message.
	 * @param string $secret Raw secret string (used as the HMAC key verbatim).
	 * @return string Lowercase hex.
	 */
	public static function hmac_hex( $data, $secret ) {
		return bin2hex( hash_hmac( self::HMAC_ALGO, (string) $data, (string) $secret, true ) );
	}

	/**
	 * PBKDF2 key derivation, matching Algorithm\Pbkdf2::deriveKey().
	 *
	 * @param int    $cost         Iteration count.
	 * @param int    $key_length   Output length in bytes.
	 * @param string $salt_bin     Raw salt bytes.
	 * @param string $password_bin Raw password bytes (nonce bytes . pack('N', counter)).
	 * @return string Raw derived key bytes.
	 */
	public static function derive_key( $cost, $key_length, $salt_bin, $password_bin ) {
		return hash_pbkdf2(
			self::HASH_ALGO,
			$password_bin,
			$salt_bin,
			max( 1, (int) $cost ),
			max( 0, (int) $key_length ),
			true
		);
	}

	/**
	 * Build the password buffer for a given nonce and counter.
	 *
	 * The counter is an unsigned 32-bit BIG-endian integer appended to the raw
	 * nonce bytes, matching the widget's DataView.setUint32(offset, n, false).
	 *
	 * @param string $nonce_bin Raw nonce bytes.
	 * @param int    $counter   Counter value, 0..4294967295.
	 * @return string
	 */
	public static function password( $nonce_bin, $counter ) {
		return $nonce_bin . pack( 'N', $counter );
	}

	/**
	 * Re-project an untrusted parameters array onto the fixed protocol schema.
	 *
	 * This is the mirror of ChallengeParameters::fromArray()->toArray(): unknown
	 * keys are dropped, wrong types fall back to the documented defaults, null-ish
	 * optionals are omitted entirely, and the result is ksorted. Running the
	 * client's echo of `parameters` through this before re-signing is what makes
	 * key order and injected junk irrelevant.
	 *
	 * @param array $raw Decoded `challenge.parameters`.
	 * @return array Normalised parameters.
	 */
	public static function normalize_parameters( array $raw ) {
		$out = array(
			'algorithm' => ( isset( $raw['algorithm'] ) && is_string( $raw['algorithm'] ) ) ? $raw['algorithm'] : '',
			'cost'      => ( isset( $raw['cost'] ) && is_int( $raw['cost'] ) ) ? $raw['cost'] : 0,
			'keyLength' => ( isset( $raw['keyLength'] ) && is_int( $raw['keyLength'] ) ) ? $raw['keyLength'] : 32,
			'keyPrefix' => ( isset( $raw['keyPrefix'] ) && is_string( $raw['keyPrefix'] ) ) ? $raw['keyPrefix'] : '',
			'nonce'     => ( isset( $raw['nonce'] ) && is_string( $raw['nonce'] ) ) ? $raw['nonce'] : '',
			'salt'      => ( isset( $raw['salt'] ) && is_string( $raw['salt'] ) ) ? $raw['salt'] : '',
		);

		if ( isset( $raw['keySignature'] ) && is_string( $raw['keySignature'] ) ) {
			$out['keySignature'] = $raw['keySignature'];
		}
		if ( isset( $raw['memoryCost'] ) && is_int( $raw['memoryCost'] ) ) {
			$out['memoryCost'] = $raw['memoryCost'];
		}
		if ( isset( $raw['parallelism'] ) && is_int( $raw['parallelism'] ) ) {
			$out['parallelism'] = $raw['parallelism'];
		}
		if ( isset( $raw['expiresAt'] ) && is_int( $raw['expiresAt'] ) ) {
			$out['expiresAt'] = $raw['expiresAt'];
		}
		if ( isset( $raw['data'] ) && is_array( $raw['data'] ) ) {
			$out['data'] = $raw['data'];
		}

		ksort( $out );

		return $out;
	}

	/**
	 * Is this a non-empty, even-length, lowercase hex string?
	 *
	 * Everything the protocol carries as hex is produced by bin2hex() on both the
	 * PHP and the JS side, so lowercase + even length is the only shape that can
	 * legitimately appear. Odd-length values are rejected outright rather than
	 * silently truncated.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_hex( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return false;
		}
		if ( 0 !== strlen( $value ) % 2 ) {
			return false;
		}

		return 1 === preg_match( '/^[0-9a-f]+$/', $value );
	}
}
