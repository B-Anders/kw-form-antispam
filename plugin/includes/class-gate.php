<?php
/**
 * The verification gate: one verifier, two checkpoints.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guards Kadence Advanced Form submissions.
 *
 * Checkpoint A runs on Kadence's own admin-ajax action at priority 1, ahead of
 * Kadence's handler. Rejecting there means Kadence never writes uploaded files
 * to disk and never makes an outbound reCAPTCHA/Turnstile request.
 *
 * Checkpoint B runs on the documented `kadence_blocks_advanced_form_submission_reject`
 * filter. It is the guarantee: if Kadence ever changes its transport, the
 * documented filter still fires and the submission is still checked.
 *
 * Both call decide(), which memoises its result for the request. That matters:
 * a solution is single-use, so verifying twice in one request would let
 * checkpoint A spend the marker and checkpoint B then reject the very same
 * request as a replay.
 *
 * decide() is read-only with respect to the replay store. The solution is only
 * marked spent in on_submission_accepted(), at the moment Kadence actually
 * accepts the submission.
 */
final class Gate {

	/**
	 * Name of the hidden input the widget writes its payload into.
	 */
	const FIELD = 'kwfa_altcha';

	/**
	 * Longest accepted payload, in bytes. A legitimate payload is ~500 bytes.
	 */
	const MAX_PAYLOAD = 8192;

	/**
	 * Memoised decision for this request.
	 *
	 * @var array|null
	 */
	private static $decision = null;

	/**
	 * Whether this plugin is the reason the submission was rejected.
	 *
	 * @var bool
	 */
	private static $rejected_by_us = false;

	/**
	 * Whether the solution has already been marked as spent this request.
	 *
	 * @var bool
	 */
	private static $spent = false;

	/**
	 * Register both checkpoints.
	 *
	 * @return void
	 */
	public static function init() {
		// Checkpoint A — before Kadence's own handler (which is registered at
		// the default priority 10).
		add_action( 'wp_ajax_nopriv_kb_process_advanced_form_submit', array( __CLASS__, 'checkpoint_a' ), 1 );
		add_action( 'wp_ajax_kb_process_advanced_form_submit', array( __CLASS__, 'checkpoint_a' ), 1 );

		// Checkpoint B — the documented filter.
		add_filter( 'kadence_blocks_advanced_form_submission_reject', array( __CLASS__, 'checkpoint_b' ), 10, 4 );
		add_filter( 'kadence_blocks_advanced_form_submission_reject_message', array( __CLASS__, 'reject_message' ), 10, 4 );

		// Spend the solution at the exact moment Kadence accepts a submission.
		add_action( 'kadence_blocks_forms_buffer_flushed', array( __CLASS__, 'on_submission_accepted' ), 10, 2 );
	}

	/**
	 * Mark the solution single-use, once Kadence has accepted the submission.
	 *
	 * Kadence fires this immediately before it sends its JSON response, with
	 * `$is_error === false` only on the success path. Spending here rather than
	 * at either checkpoint means a submission Kadence rejects for its own
	 * reasons — a missing required field, its own CAPTCHA — leaves the
	 * visitor's solution intact, so their retry still works.
	 *
	 * @param string|null $buffer   Output buffer contents. Unused.
	 * @param bool        $is_error Whether Kadence considers this an error response.
	 * @return void
	 */
	public static function on_submission_accepted( $buffer = null, $is_error = false ) {
		if ( null === self::$decision ) {
			return;
		}

		// Kadence's own handler finished a submission we saw — whether it
		// accepted or rejected it. This is what tells the drift probe that the
		// pipeline downstream of us is alive at all.
		Probe::record( 'reached' );

		if ( $is_error || self::$spent ) {
			return;
		}

		self::$spent = true;

		Probe::record( 'accepted' );

		if ( ! self::$decision['pass'] || '' === self::$decision['replay_key'] ) {
			return;
		}

		if ( ! Replay::spend( self::$decision['replay_key'], self::$decision['replay_ttl'] ) ) {
			// The solution was valid; only single-use enforcement is degraded.
			Status::report( 'replay_store_unavailable' );
		}
	}

	/**
	 * Checkpoint A: reject early, in Kadence's own JSON shape.
	 *
	 * @return void
	 */
	public static function checkpoint_a() {
		// Kadence itself bails on a request without these, so neither do we act.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['_kb_adv_form_id'] ) || empty( $_POST['_kb_adv_form_post_id'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		Probe::record( 'a' );

		$decision = self::decide();

		if ( $decision['pass'] ) {
			return;
		}

		self::$rejected_by_us = true;

		self::bail();
	}

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- both callbacks below must accept Kadence's fixed four-argument filter signature.

	/**
	 * Checkpoint B: the documented reject filter.
	 *
	 * @param bool   $rejected         Current rejection state.
	 * @param array  $form_args        Form attributes and fields.
	 * @param array  $processed_fields Sanitised submitted fields.
	 * @param string $post_id          Form CPT post ID, as a string.
	 * @return bool
	 */
	public static function checkpoint_b( $rejected, $form_args = array(), $processed_fields = array(), $post_id = '' ) {
		Probe::record( 'b' );

		if ( $rejected ) {
			// Somebody else already rejected. Leave their message alone.
			return $rejected;
		}

		$decision = self::decide();

		if ( $decision['pass'] ) {
			return $rejected;
		}

		self::$rejected_by_us = true;

		return true;
	}

	/**
	 * Supply the rejection message, but only when we are the ones rejecting.
	 *
	 * Kadence injects this string into markup without escaping it
	 * (`html_from_notices()`), and the browser renders it through `innerHTML`
	 * after a permissive tag allowlist. So it is escaped here, at the point of
	 * use, and the filtered value is escaped too.
	 *
	 * @param string $message          Default message.
	 * @param array  $form_args        Form attributes and fields.
	 * @param array  $processed_fields Sanitised submitted fields.
	 * @param string $post_id          Form CPT post ID, as a string.
	 * @return string
	 */
	public static function reject_message( $message, $form_args = array(), $processed_fields = array(), $post_id = '' ) {
		if ( ! self::$rejected_by_us ) {
			return $message;
		}

		return self::message();
	}

	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter

	/**
	 * Verify the current request. Idempotent for the lifetime of the request.
	 *
	 * @return array
	 */
	public static function decide() {
		if ( null !== self::$decision ) {
			return self::$decision;
		}

		// Set a fail-open placeholder first so that any re-entrant call (an
		// exception handler, a plugin that re-fires the filter) cannot cause a
		// second verification and therefore cannot spend the solution twice.
		self::$decision = self::allow( 'in_progress' );
		self::$decision = self::evaluate();

		Probe::record( self::$decision['pass'] ? 'pass' : 'reject' );

		return self::$decision;
	}

	/**
	 * The single verification path.
	 *
	 * Read-only with respect to the replay store: it checks whether a solution
	 * has already been spent, but never spends it. Spending happens in
	 * on_submission_accepted().
	 *
	 * @return array
	 */
	private static function evaluate() {
		if ( ! Plugin::protocol_available() ) {
			Status::report( 'core_missing' );
			return self::allow( 'core_missing' );
		}

		$secret = Secret::get();

		if ( '' === $secret ) {
			Status::report( 'secret_missing' );
			return self::allow( 'secret_missing' );
		}

		$payload = self::read_payload();

		if ( '' === $payload ) {
			// A missing or malformed solution is not a failure of ours. It is
			// the plugin working, so it is rejected normally.
			return self::deny( 'missing' );
		}

		try {
			$verification = Altcha\Verifier::verify( $secret, $payload );
		} catch ( \Throwable $e ) {
			Status::report( 'verifier_error' );
			return self::allow( 'verifier_exception' );
		}

		if ( ! is_object( $verification ) || ! method_exists( $verification, 'is_valid' ) ) {
			Status::report( 'verifier_error' );
			return self::allow( 'verifier_contract' );
		}

		if ( ! $verification->is_valid() ) {
			$code = (string) $verification->get_error_code();

			return self::deny( '' === $code ? 'invalid' : $code );
		}

		if ( ! self::binding_matches( $verification ) ) {
			return self::deny( 'form_mismatch' );
		}

		$replay_key = (string) $verification->get_replay_key();

		if ( '' !== $replay_key && Replay::is_spent( $replay_key ) ) {
			return self::deny( 'replay' );
		}

		Status::clear();

		return self::allow( 'verified', $replay_key, self::replay_ttl( $verification ) );
	}

	/**
	 * Does the challenge belong to the form it was submitted with?
	 *
	 * Enforced only when both sides are known, so that a challenge minted
	 * before this check existed, or for a form without an ID, never causes a
	 * mystery rejection.
	 *
	 * @param object $verification Verification result.
	 * @return bool
	 */
	private static function binding_matches( $verification ) {
		if ( ! method_exists( $verification, 'get_data' ) ) {
			return true;
		}

		$actual = (string) $verification->get_data();

		if ( '' === $actual ) {
			return true;
		}

		$submitted = self::posted_form_id();
		$expected  = Plugin::binding( $submitted );

		if ( '' === $expected ) {
			return true;
		}

		if ( hash_equals( $expected, $actual ) ) {
			return true;
		}

		// The IDs differ. On a multilingual site that is routine: one logical
		// form has a post per language, and a challenge minted on one language's
		// page can legitimately be spent on its translation — after a language
		// switch, or from a page cached before the render-time fix landed.
		$challenge_form_id = Plugin::binding_form_id( $actual );

		if ( $challenge_form_id < 1 ) {
			// Not a binding we produced.
			return false;
		}

		return Translation::same_form( $challenge_form_id, $submitted );
	}

	/**
	 * How long the single-use marker must outlive the challenge.
	 *
	 * @param object $verification Verification result.
	 * @return int Seconds.
	 */
	private static function replay_ttl( $verification ) {
		$expires_at = method_exists( $verification, 'get_expires_at' )
			? (int) $verification->get_expires_at()
			: 0;

		$remaining = $expires_at > time()
			? ( $expires_at - time() )
			: Plugin::expires_in();

		// A minute of slack covers clock skew between the signing and the
		// verifying request.
		return $remaining + MINUTE_IN_SECONDS;
	}

	/**
	 * The form post ID that came with the submission.
	 *
	 * @return int
	 */
	private static function posted_form_id() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['_kb_adv_form_post_id'] ) ) {
			return 0;
		}

		return absint( sanitize_text_field( wp_unslash( $_POST['_kb_adv_form_post_id'] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Read and validate the posted payload.
	 *
	 * The payload is base64 of ASCII JSON, so an allowlist of base64 characters
	 * is the correct sanitisation: anything else is not a payload we produced.
	 *
	 * @return string Empty string when absent or not plausibly a payload.
	 */
	private static function read_payload() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST[ self::FIELD ] ) || ! is_string( $_POST[ self::FIELD ] ) ) {
			return '';
		}

		$raw = trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $raw || strlen( $raw ) > self::MAX_PAYLOAD ) {
			return '';
		}

		if ( ! preg_match( '#\A[A-Za-z0-9+/]+={0,2}\z#', $raw ) ) {
			return '';
		}

		return $raw;
	}

	/**
	 * The user-facing rejection message, already escaped.
	 *
	 * @return string
	 */
	private static function message() {
		$default = __( 'We could not verify that this submission came from a browser. Please reload the page and try again.', 'kw-form-antispam' );

		/**
		 * Filters the message shown when a submission is rejected.
		 *
		 * The return value is escaped before it reaches the browser.
		 *
		 * @param string $default  Default message.
		 * @param string $code     Internal reason code.
		 */
		$message = apply_filters( 'kwfa_rejection_message', $default, self::reason_code() );

		if ( ! is_string( $message ) || '' === trim( $message ) ) {
			$message = $default;
		}

		return esc_html( $message );
	}

	/**
	 * Internal reason for the current decision.
	 *
	 * @return string
	 */
	private static function reason_code() {
		return null === self::$decision ? '' : self::$decision['code'];
	}

	/**
	 * Emit a rejection in Kadence's own JSON shape and stop.
	 *
	 * Mirrors `KB_Ajax_Advanced_Form::process_bail()` so the Kadence front-end
	 * script renders our message exactly the way it renders theirs.
	 *
	 * @return void
	 */
	private static function bail() {
		$message = self::message();

		wp_send_json_error(
			array(
				'html'         => '<div class="kb-adv-form-message kb-adv-form-warning">' . $message . '</div>',
				'console'      => $message,
				'fieldErrors'  => null,
				'message'      => $message,
				'headers_sent' => headers_sent(),
			)
		);
	}

	/**
	 * Build an "allow" decision.
	 *
	 * @param string $code       Reason code.
	 * @param string $replay_key Key to spend once the submission is accepted.
	 * @param int    $replay_ttl Lifetime of the single-use marker, in seconds.
	 * @return array
	 */
	private static function allow( $code, $replay_key = '', $replay_ttl = 0 ) {
		return array(
			'pass'       => true,
			'code'       => $code,
			'replay_key' => $replay_key,
			'replay_ttl' => $replay_ttl,
		);
	}

	/**
	 * Build a "deny" decision.
	 *
	 * @param string $code Reason code.
	 * @return array
	 */
	private static function deny( $code ) {
		return array(
			'pass'       => false,
			'code'       => $code,
			'replay_key' => '',
			'replay_ttl' => 0,
		);
	}
}
