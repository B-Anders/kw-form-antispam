<?php
/**
 * ALTCHA v3 challenge creation.
 *
 * Pure PHP, PHP 7.4 compatible, zero WordPress dependencies, no I/O.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam\Altcha;

/**
 * Creates signed, expiring ALTCHA v3 challenges.
 */
final class Challenge {

	/**
	 * Not instantiable.
	 */
	private function __construct() {
	}

	/**
	 * Create a signed, expiring ALTCHA v3 challenge.
	 *
	 * The return value is ready for `json_encode()` and can be handed to the widget
	 * either as the body of the challenge endpoint or inline via the `challenge`
	 * attribute. The wire shape is:
	 *
	 *     {
	 *       "parameters": {
	 *         "algorithm": "PBKDF2/SHA-256",
	 *         "cost":      5000,
	 *         "data":      {"d": "<opaque>"},   // omitted when no data was bound
	 *         "expiresAt": 1753970000,
	 *         "keyLength": 32,
	 *         "keyPrefix": "00",
	 *         "nonce":     "<32 hex chars>",
	 *         "salt":      "<32 hex chars>"
	 *       },
	 *       "signature": "<64 hex chars>"
	 *     }
	 *
	 * `signature` = HMAC-SHA256(canonical JSON of `parameters`, $secret), hex.
	 * Because `expiresAt` and `data` live inside `parameters`, both are covered by
	 * the signature and cannot be tampered with by the client.
	 *
	 * @param string $secret HMAC secret. Must be non-empty; keep it ASCII.
	 * @param array  $args   {
	 *     Optional arguments.
	 *
	 *     @type int    $expires_in Challenge lifetime in seconds. Default 600.
	 *                              Clamped to 1..86400.
	 *     @type int    $cost       PBKDF2 iterations per derivation. Default 5000.
	 *                              Clamped to 1..250000.
	 *     @type string $data       Opaque payload bound into the SIGNED challenge,
	 *                              returned verbatim by Verification::get_data().
	 *                              Default ''.
	 * }
	 * @return array JSON-ready challenge.
	 *
	 * @throws \InvalidArgumentException If the secret is empty or $data is not stringable.
	 */
	public static function create( string $secret, array $args = array() ): array {
		if ( '' === $secret ) {
			// A null/empty secret is the upstream library's worst footgun: it silently
			// stops emitting a signature and then silently skips signature verification,
			// i.e. the server accepts any forged challenge. Fail loudly instead.
			throw new \InvalidArgumentException( 'ALTCHA secret must be a non-empty string.' );
		}

		$expires_in = isset( $args['expires_in'] ) ? (int) $args['expires_in'] : Protocol::DEFAULT_EXPIRES_IN;
		$expires_in = max( Protocol::MIN_EXPIRES_IN, min( Protocol::MAX_EXPIRES_IN, $expires_in ) );

		$cost = isset( $args['cost'] ) ? (int) $args['cost'] : Protocol::DEFAULT_COST;
		$cost = max( Protocol::MIN_COST, min( Protocol::MAX_COST, $cost ) );

		$data = '';
		if ( isset( $args['data'] ) ) {
			if ( is_string( $args['data'] ) ) {
				$data = $args['data'];
			} elseif ( is_scalar( $args['data'] ) ) {
				$data = (string) $args['data'];
			} else {
				throw new \InvalidArgumentException( 'ALTCHA challenge data must be a string.' );
			}
		}

		$parameters = array(
			'algorithm' => Protocol::ALGORITHM,
			'cost'      => $cost,
			'expiresAt' => time() + $expires_in,
			'keyLength' => Protocol::KEY_LENGTH,
			'keyPrefix' => Protocol::KEY_PREFIX,
			'nonce'     => bin2hex( random_bytes( 16 ) ),
			'salt'      => bin2hex( random_bytes( 16 ) ),
		);

		if ( '' !== $data ) {
			// `parameters.data` must be a JSON object, never a bare string: the widget
			// types it as Record<string, ...> and the official PHP library discards it
			// unless it decodes to an array.
			$parameters['data'] = array( Protocol::DATA_KEY => $data );
		}

		ksort( $parameters );

		$signature = Protocol::hmac_hex( Protocol::canonical_json( $parameters ), $secret );

		return array(
			'parameters' => $parameters,
			'signature'  => $signature,
		);
	}
}
