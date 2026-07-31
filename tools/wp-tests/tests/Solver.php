<?php
/**
 * Stands in for the browser widget.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

namespace Kreiswolke\FormAntispam\Tests;

use Kreiswolke\FormAntispam\Altcha\Protocol;
use Kreiswolke\FormAntispam\Rest_Challenge;
use RuntimeException;
use WP_REST_Request;

/**
 * Mints a challenge through the plugin's own REST handler, solves the
 * proof-of-work the way the ALTCHA widget does, and encodes the result in the
 * exact payload shape the widget writes into the hidden form field:
 *
 *   base64( {"challenge":{"parameters":{…},"signature":"…"},
 *            "solution":{"counter":n,"derivedKey":"…","time":ms}} )
 *
 * See RESEARCH-altcha.md §A.2 and §A.3.
 */
final class Solver {

	/**
	 * Upper bound on the brute-force loop. keyPrefix '00' needs ~256 tries.
	 */
	const MAX_COUNTER = 200000;

	/**
	 * Mint and solve a challenge for a form.
	 *
	 * @param int $form_id Kadence form CPT post ID.
	 * @return string Base64 payload.
	 * @throws RuntimeException If the endpoint refused, or no solution was found.
	 */
	public static function payload_for_form( $form_id ) {
		return self::solve( self::mint( $form_id ) );
	}

	/**
	 * Mint a challenge through the real REST handler.
	 *
	 * @param int $form_id Kadence form CPT post ID.
	 * @return array Challenge as the endpoint returned it.
	 * @throws RuntimeException If the endpoint did not return a challenge.
	 */
	public static function mint( $form_id ) {
		$response = Rest_Challenge::handle( new WP_REST_Request( array( 'form_id' => $form_id ) ) );

		if ( 200 !== $response->status ) {
			throw new RuntimeException( 'Challenge endpoint returned HTTP ' . $response->status );
		}

		return $response->data;
	}

	/**
	 * Brute-force the counter until the derived key starts with keyPrefix.
	 *
	 * @param array $challenge Challenge from the endpoint.
	 * @return string Base64 payload.
	 * @throws RuntimeException If no solution was found inside MAX_COUNTER.
	 */
	public static function solve( array $challenge ) {
		$parameters = $challenge['parameters'];

		$nonce_bin = hex2bin( $parameters['nonce'] );
		$salt_bin  = hex2bin( $parameters['salt'] );
		$prefix    = hex2bin( $parameters['keyPrefix'] );

		if ( '' === $prefix || false === $prefix ) {
			// An odd-length keyPrefix makes the prefix test a no-op on the
			// server side; the protocol core must never emit one.
			throw new RuntimeException( 'keyPrefix is not even-length hex.' );
		}

		for ( $counter = 0; $counter < self::MAX_COUNTER; $counter++ ) {
			$derived = Protocol::derive_key(
				$parameters['cost'],
				$parameters['keyLength'],
				$salt_bin,
				Protocol::password( $nonce_bin, $counter )
			);

			if ( 0 === strncmp( $derived, $prefix, strlen( $prefix ) ) ) {
				return self::encode( $challenge, $counter, bin2hex( $derived ) );
			}
		}

		throw new RuntimeException( 'No solution found within ' . self::MAX_COUNTER . ' derivations.' );
	}

	/**
	 * Encode a challenge and its solution the way the widget does.
	 *
	 * @param array  $challenge   Challenge.
	 * @param int    $counter     Winning counter.
	 * @param string $derived_key Derived key, hex.
	 * @return string Base64 payload.
	 */
	public static function encode( array $challenge, $counter, $derived_key ) {
		return base64_encode(
			json_encode(
				array(
					'challenge' => array(
						'parameters' => $challenge['parameters'],
						'signature'  => $challenge['signature'],
					),
					'solution'  => array(
						'counter'    => $counter,
						'derivedKey' => $derived_key,
						'time'       => 412.5,
					),
				)
			)
		);
	}

	/**
	 * Decode a payload back into an array.
	 *
	 * @param string $payload Base64 payload.
	 * @return array
	 */
	public static function decode( $payload ) {
		return json_decode( base64_decode( $payload ), true );
	}

	/**
	 * Re-encode a decoded payload.
	 *
	 * @param array $decoded Decoded payload.
	 * @return string
	 */
	public static function reencode( array $decoded ) {
		return base64_encode( json_encode( $decoded ) );
	}

	/**
	 * The payload the widget emits in test mode (Widget.svelte:1234-1240).
	 *
	 * @return string
	 */
	public static function test_mode_payload() {
		return base64_encode( '{"challenge":null,"solution":null,"test":true}' );
	}
}
