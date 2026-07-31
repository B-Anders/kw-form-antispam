<?php
/**
 * ALTCHA v3 payload verification.
 *
 * Pure PHP, PHP 7.4 compatible, zero WordPress dependencies, no I/O.
 *
 * Everything this class touches is attacker controlled. It therefore never
 * throws: any exception or error is caught and turned into a Verification with
 * the 'malformed' code.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam\Altcha;

/**
 * Verifies the base64 payload the widget writes into the form field.
 */
final class Verifier {

	/** Largest counter representable by pack('N'). */
	const MAX_COUNTER = 4294967295;

	/** Maximum JSON nesting depth accepted in a payload. */
	const MAX_JSON_DEPTH = 32;

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Verify an ALTCHA payload.
	 *
	 * Expected input is the value of the widget's hidden input (default name
	 * `altcha`): base64 of
	 *
	 *     {"challenge":{"parameters":{...},"signature":"<hex>"},
	 *      "solution":{"counter":<int>,"derivedKey":"<hex>","time":<float>}}
	 *
	 * Order of checks, and why:
	 *
	 *   1. structural decode          - cheap, bounded
	 *   2. `test: true` rejection     - the widget's own bypass payload
	 *   3. HMAC signature             - one HMAC; authenticates every parameter,
	 *                                   including `cost`. Must happen BEFORE any
	 *                                   key derivation, otherwise an attacker sets
	 *                                   `cost` to a huge number and the verifier
	 *                                   becomes the DoS lever.
	 *   4. expiry                     - read from the now-authenticated parameters
	 *   5. parameter sanity           - defence in depth against our own misconfig
	 *   6. PBKDF2 re-derivation       - the expensive step, runs last
	 *   7. keyPrefix check            - the actual proof-of-work assertion
	 *
	 * @param string $secret         The same HMAC secret used to create the challenge.
	 * @param string $payload_base64 Raw value posted by the widget.
	 * @return Verification Never null, never throws.
	 */
	public static function verify( string $secret, string $payload_base64 ): Verification {
		try {
			return self::run( $secret, $payload_base64 );
		} catch ( \Throwable $e ) {
			// Belt and braces: nothing below is expected to throw, but a malformed
			// payload must never surface as a 500.
			return Verification::error( Verification::ERROR_MALFORMED );
		}
	}

	/**
	 * Verification body.
	 *
	 * @param string $secret         HMAC secret.
	 * @param string $payload_base64 Raw payload.
	 * @return Verification
	 */
	private static function run( $secret, $payload_base64 ) {
		if ( '' === $secret ) {
			// Without a secret we cannot authenticate anything. Fail closed rather
			// than degrading to "accept everything", which is what the upstream
			// library does when its hmacSignatureSecret is null.
			return Verification::error( Verification::ERROR_BAD_SIGNATURE );
		}

		$raw = trim( $payload_base64 );
		if ( '' === $raw ) {
			return Verification::error( Verification::ERROR_MALFORMED );
		}
		if ( strlen( $raw ) > Protocol::MAX_PAYLOAD_BYTES ) {
			// Bound the work before base64/JSON decoding. A real payload is under 1 KiB.
			return Verification::error( Verification::ERROR_MALFORMED );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Not obfuscation: this decodes the base64 payload the ALTCHA widget places in the form field, which is the wire format defined by the protocol. Strict mode is on, the length is bounded above, and the decoded value is treated as untrusted input throughout.
		$json = base64_decode( $raw, true );
		if ( false === $json || '' === $json ) {
			return Verification::error( Verification::ERROR_MALFORMED );
		}

		$decoded = json_decode( $json, true, self::MAX_JSON_DEPTH );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
			return Verification::error( Verification::ERROR_MALFORMED );
		}

		// The widget emits {"challenge":null,"solution":null,"test":true} whenever it
		// is configured with test: true. That must never be accepted in production.
		if ( array_key_exists( 'test', $decoded ) && ! empty( $decoded['test'] ) ) {
			return Verification::error( Verification::ERROR_TEST_MODE );
		}

		if ( ! isset( $decoded['challenge'] ) || ! is_array( $decoded['challenge'] ) ) {
			return Verification::error( Verification::ERROR_MALFORMED );
		}
		if ( ! isset( $decoded['solution'] ) || ! is_array( $decoded['solution'] ) ) {
			return Verification::error( Verification::ERROR_MALFORMED );
		}

		$challenge = $decoded['challenge'];
		$solution  = $decoded['solution'];

		if ( ! isset( $challenge['parameters'] ) || ! is_array( $challenge['parameters'] ) ) {
			return Verification::error( Verification::ERROR_MALFORMED );
		}

		// Re-project onto the fixed protocol schema. Injected extra keys are dropped
		// and key order becomes irrelevant, exactly as the official library does.
		$params = Protocol::normalize_parameters( $challenge['parameters'] );

		// Recovered-but-not-yet-trusted context, echoed back on failures so the caller
		// can log. Only meaningful once the signature has been checked.
		$replay_key = Protocol::is_hex( $params['nonce'] ) ? $params['nonce'] : '';
		$expires_at = isset( $params['expiresAt'] ) ? (int) $params['expiresAt'] : 0;
		$data       = self::extract_data( $params );

		// --- 3. signature -----------------------------------------------------
		if ( ! isset( $challenge['signature'] ) || ! is_string( $challenge['signature'] ) || '' === $challenge['signature'] ) {
			return Verification::error( Verification::ERROR_BAD_SIGNATURE, $replay_key, $data, $expires_at );
		}

		$expected = Protocol::hmac_hex( Protocol::canonical_json( $params ), $secret );
		if ( ! hash_equals( $expected, $challenge['signature'] ) ) {
			return Verification::error( Verification::ERROR_BAD_SIGNATURE, $replay_key, $data, $expires_at );
		}

		// Everything in $params is now authenticated.

		// --- 4. expiry --------------------------------------------------------
		// Challenge::create() always emits expiresAt, so a signature-valid challenge
		// without one cannot have come from us. Treat its absence as expired rather
		// than as "no expiry" — fail closed.
		if ( $expires_at <= 0 || time() > $expires_at ) {
			return Verification::error( Verification::ERROR_EXPIRED, $replay_key, $data, $expires_at );
		}

		// --- 5. parameter sanity ---------------------------------------------
		if ( Protocol::ALGORITHM !== $params['algorithm'] ) {
			return Verification::error( Verification::ERROR_MALFORMED, $replay_key, $data, $expires_at );
		}
		if ( isset( $params['keySignature'] ) ) {
			// Deterministic-mode challenges enable an HMAC-only fast path that skips
			// re-derivation entirely. We never issue them, so never honour them.
			return Verification::error( Verification::ERROR_MALFORMED, $replay_key, $data, $expires_at );
		}
		if ( $params['cost'] < Protocol::MIN_COST || $params['cost'] > Protocol::MAX_COST ) {
			return Verification::error( Verification::ERROR_MALFORMED, $replay_key, $data, $expires_at );
		}
		if ( $params['keyLength'] < 1 || $params['keyLength'] > 64 ) {
			return Verification::error( Verification::ERROR_MALFORMED, $replay_key, $data, $expires_at );
		}
		if ( ! Protocol::is_hex( $params['nonce'] ) || ! Protocol::is_hex( $params['salt'] ) ) {
			return Verification::error( Verification::ERROR_MALFORMED, $replay_key, $data, $expires_at );
		}
		if ( ! Protocol::is_hex( $params['keyPrefix'] ) ) {
			// An odd-length or empty keyPrefix makes hex2bin() yield '' and the prefix
			// comparison a no-op that always passes — i.e. no proof of work at all.
			return Verification::error( Verification::ERROR_MALFORMED, $replay_key, $data, $expires_at );
		}

		// --- 6. solution ------------------------------------------------------
		if ( ! isset( $solution['counter'] ) || ! is_int( $solution['counter'] ) ) {
			return Verification::error( Verification::ERROR_MALFORMED, $replay_key, $data, $expires_at );
		}
		if ( ! isset( $solution['derivedKey'] ) || ! is_string( $solution['derivedKey'] ) ) {
			return Verification::error( Verification::ERROR_MALFORMED, $replay_key, $data, $expires_at );
		}

		$counter     = $solution['counter'];
		$derived_hex = $solution['derivedKey'];

		if ( $counter < 0 || $counter > self::MAX_COUNTER ) {
			// pack('N') silently wraps outside this range.
			return Verification::error( Verification::ERROR_BAD_SOLUTION, $replay_key, $data, $expires_at );
		}
		if ( ! Protocol::is_hex( $derived_hex ) || strlen( $derived_hex ) !== $params['keyLength'] * 2 ) {
			return Verification::error( Verification::ERROR_BAD_SOLUTION, $replay_key, $data, $expires_at );
		}

		$nonce_bin  = hex2bin( $params['nonce'] );
		$salt_bin   = hex2bin( $params['salt'] );
		$prefix_bin = hex2bin( $params['keyPrefix'] );
		if ( false === $nonce_bin || false === $salt_bin || false === $prefix_bin || '' === $prefix_bin ) {
			return Verification::error( Verification::ERROR_MALFORMED, $replay_key, $data, $expires_at );
		}

		$derived = Protocol::derive_key(
			$params['cost'],
			$params['keyLength'],
			$salt_bin,
			Protocol::password( $nonce_bin, $counter )
		);

		if ( ! is_string( $derived ) || '' === $derived ) {
			return Verification::error( Verification::ERROR_BAD_SOLUTION, $replay_key, $data, $expires_at );
		}
		if ( ! hash_equals( bin2hex( $derived ), $derived_hex ) ) {
			return Verification::error( Verification::ERROR_BAD_SOLUTION, $replay_key, $data, $expires_at );
		}

		// --- 7. proof of work -------------------------------------------------
		if ( ! hash_equals( $prefix_bin, substr( $derived, 0, strlen( $prefix_bin ) ) ) ) {
			return Verification::error( Verification::ERROR_BAD_SOLUTION, $replay_key, $data, $expires_at );
		}

		return Verification::success( $replay_key, $data, $expires_at );
	}

	/**
	 * Pull the opaque bound string back out of `parameters.data`.
	 *
	 * @param array $params Normalised parameters.
	 * @return string Empty string when nothing usable was bound.
	 */
	private static function extract_data( array $params ) {
		if ( ! isset( $params['data'] ) || ! is_array( $params['data'] ) ) {
			return '';
		}
		if ( ! isset( $params['data'][ Protocol::DATA_KEY ] ) || ! is_string( $params['data'][ Protocol::DATA_KEY ] ) ) {
			return '';
		}

		return $params['data'][ Protocol::DATA_KEY ];
	}
}
