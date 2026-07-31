<?php
/**
 * Result of verifying an ALTCHA payload.
 *
 * Pure PHP, PHP 7.4 compatible, zero WordPress dependencies.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam\Altcha;

/**
 * Immutable verification outcome.
 *
 * A Verification is always returned — the verifier never throws on
 * attacker-controlled input.
 */
final class Verification {

	/** No error: the payload verified. */
	const ERROR_NONE = '';

	/** Payload was not decodable, or did not have the expected shape. */
	const ERROR_MALFORMED = 'malformed';

	/** The challenge HMAC did not match: forged or tampered parameters. */
	const ERROR_BAD_SIGNATURE = 'bad_signature';

	/** The challenge's signed expiry has passed (or was missing). */
	const ERROR_EXPIRED = 'expired';

	/** Signature and expiry were fine, but the proof of work was not. */
	const ERROR_BAD_SOLUTION = 'bad_solution';

	/** The widget's `{"test":true}` payload. Never acceptable in production. */
	const ERROR_TEST_MODE = 'test_mode';

	/**
	 * Whether the payload verified.
	 *
	 * @var bool
	 */
	private $valid;

	/**
	 * Machine-readable error code, '' when valid.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * Stable unique id of the challenge, for single-use tracking.
	 *
	 * @var string
	 */
	private $replay_key;

	/**
	 * The opaque string bound into the challenge at creation time.
	 *
	 * @var string
	 */
	private $data;

	/**
	 * Signed expiry as a unix timestamp, 0 if absent.
	 *
	 * @var int
	 */
	private $expires_at;

	/**
	 * Constructor.
	 *
	 * @param bool   $valid      Whether the payload verified.
	 * @param string $error_code Error code; ignored when $valid is true.
	 * @param string $replay_key Challenge id, when it could be recovered.
	 * @param string $data       Opaque bound payload, when it could be recovered.
	 * @param int    $expires_at Signed expiry, when it could be recovered.
	 */
	public function __construct( $valid, $error_code = self::ERROR_NONE, $replay_key = '', $data = '', $expires_at = 0 ) {
		$this->valid      = (bool) $valid;
		$this->error_code = $this->valid ? self::ERROR_NONE : (string) $error_code;
		$this->replay_key = (string) $replay_key;
		$this->data       = (string) $data;
		$this->expires_at = (int) $expires_at;
	}

	/**
	 * Build a successful result.
	 *
	 * @param string $replay_key Challenge id.
	 * @param string $data       Opaque bound payload.
	 * @param int    $expires_at Signed expiry.
	 * @return self
	 */
	public static function success( $replay_key, $data = '', $expires_at = 0 ) {
		return new self( true, self::ERROR_NONE, $replay_key, $data, $expires_at );
	}

	/**
	 * Build a failed result.
	 *
	 * @param string $error_code Error code.
	 * @param string $replay_key Challenge id, if recoverable.
	 * @param string $data       Opaque bound payload, if recoverable.
	 * @param int    $expires_at Signed expiry, if recoverable.
	 * @return self
	 */
	public static function error( $error_code, $replay_key = '', $data = '', $expires_at = 0 ) {
		return new self( false, $error_code, $replay_key, $data, $expires_at );
	}

	/**
	 * Did the payload verify?
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return $this->valid;
	}

	/**
	 * Machine-readable reason for rejection.
	 *
	 * One of '', 'malformed', 'bad_signature', 'expired', 'bad_solution', 'test_mode'.
	 *
	 * @return string
	 */
	public function get_error_code(): string {
		return $this->error_code;
	}

	/**
	 * Stable unique id of the challenge, for single-use tracking.
	 *
	 * This is the challenge nonce: 16 bytes of `random_bytes()` as lowercase hex,
	 * generated per challenge and covered by the HMAC signature. On a valid result
	 * it is therefore authenticated and unforgeable, which is what makes it safe to
	 * key a replay store on. Empty string when nothing could be recovered.
	 *
	 * @return string
	 */
	public function get_replay_key(): string {
		return $this->replay_key;
	}

	/**
	 * The opaque 'data' string bound into the challenge at creation.
	 *
	 * Authenticated on a valid result. Empty string if none was bound, or if the
	 * payload could not be parsed far enough to recover it.
	 *
	 * @return string
	 */
	public function get_data(): string {
		return $this->data;
	}

	/**
	 * Signed expiry as a unix timestamp, 0 if absent.
	 *
	 * @return int
	 */
	public function get_expires_at(): int {
		return $this->expires_at;
	}
}
