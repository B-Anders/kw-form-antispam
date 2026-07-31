<?php
/**
 * The verification gate: two checkpoints, one memoised verifier, single-use
 * solutions spent only when Kadence accepts.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

namespace Kreiswolke\FormAntispam\Tests;

use Kreiswolke\FormAntispam\Gate;
use Kreiswolke\FormAntispam\Status;
use WP_Stub_State;

/**
 * @covers \Kreiswolke\FormAntispam\Gate
 * @covers \Kreiswolke\FormAntispam\Replay
 */
final class GateTest extends TestCase {

	/**
	 * Fully qualified Gate class name.
	 */
	const GATE = 'Kreiswolke\\FormAntispam\\Gate';

	/**
	 * Set up a working site with a cheap proof-of-work.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		WP_Stub_State::$forced_cost = 1;
		$this->install_secret();
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	/**
	 * Checkpoint A must sit ahead of Kadence's handler, which uses the default
	 * priority 10 (advanced-form-ajax.php:46-47).
	 *
	 * @return void
	 */
	public function test_checkpoint_a_is_registered_ahead_of_kadence() {
		foreach ( array( 'wp_ajax_nopriv_kb_process_advanced_form_submit', 'wp_ajax_kb_process_advanced_form_submit' ) as $hook ) {
			$priorities = wp_stub_hook_priorities( $hook );

			$this->assertNotEmpty( $priorities, "Nothing registered on {$hook}" );
			$this->assertLessThan( 10, min( $priorities ), "{$hook} must run before Kadence's priority 10" );
		}
	}

	/**
	 * Checkpoint B and the message filter must both be registered.
	 *
	 * @return void
	 */
	public function test_checkpoint_b_and_message_filter_are_registered() {
		$this->assertNotEmpty( wp_stub_hook_priorities( 'kadence_blocks_advanced_form_submission_reject' ) );
		$this->assertNotEmpty( wp_stub_hook_priorities( 'kadence_blocks_advanced_form_submission_reject_message' ) );
		$this->assertNotEmpty( wp_stub_hook_priorities( 'kadence_blocks_forms_buffer_flushed' ) );
	}

	// -------------------------------------------------------------------------
	// The happy path
	// -------------------------------------------------------------------------

	/**
	 * A real, freshly solved payload gets through both checkpoints.
	 *
	 * @return void
	 */
	public function test_valid_solution_is_accepted() {
		$payload = Solver::payload_for_form( 42 );

		$outcome = $this->submit( $this->submission_post( $payload ) );

		$this->assertSame( 'accepted', $outcome['result'] );
	}

	/**
	 * Accepting a submission spends the solution, exactly once.
	 *
	 * @return void
	 */
	public function test_accepted_submission_spends_the_marker() {
		$payload = Solver::payload_for_form( 42 );

		$this->assertSame( array(), $this->replay_markers(), 'Nothing should be spent before a submission.' );

		$this->submit( $this->submission_post( $payload ) );

		$this->assertCount( 1, $this->replay_markers(), 'Exactly one single-use marker should exist.' );
	}

	/**
	 * Spending is idempotent inside one request, so a plugin that fires the
	 * Kadence hook twice cannot double-spend.
	 *
	 * @return void
	 */
	public function test_spending_is_idempotent_within_a_request() {
		$payload = Solver::payload_for_form( 42 );

		$this->submit( $this->submission_post( $payload ) );
		do_action( 'kadence_blocks_forms_buffer_flushed', null, false );

		$this->assertCount( 1, $this->replay_markers() );
	}

	// -------------------------------------------------------------------------
	// Memoisation
	// -------------------------------------------------------------------------

	/**
	 * Checkpoint B must read the memoised decision rather than verifying again.
	 *
	 * Proven by poisoning the memo after checkpoint A has run: if B re-verified,
	 * the (still valid) payload would pass; it can only reject by reading the
	 * sentinel.
	 *
	 * @return void
	 */
	public function test_checkpoint_b_reads_the_memoised_decision() {
		$payload = Solver::payload_for_form( 42 );

		$this->new_request();
		$_POST = $this->submission_post( $payload );

		do_action( 'wp_ajax_nopriv_kb_process_advanced_form_submit' );

		$this->set_static(
			self::GATE,
			'decision',
			array(
				'pass'       => false,
				'code'       => 'sentinel',
				'replay_key' => '',
				'replay_ttl' => 0,
			)
		);

		$rejected = apply_filters(
			'kadence_blocks_advanced_form_submission_reject',
			false,
			array(),
			array(),
			'42'
		);

		$this->assertTrue( $rejected, 'Checkpoint B re-verified instead of reusing the memo.' );
	}

	/**
	 * The verifier runs at most once per request.
	 *
	 * Proven by removing the signing secret between the two checkpoints: a
	 * second verification would take the fail-open branch and record
	 * 'secret_missing'. Silence means evaluate() never ran again.
	 *
	 * @return void
	 */
	public function test_verifier_does_not_run_twice_in_one_request() {
		$payload = Solver::payload_for_form( 42 );

		$this->new_request();
		$_POST = $this->submission_post( $payload );

		do_action( 'wp_ajax_nopriv_kb_process_advanced_form_submit' );

		$this->assertSame( '', Status::get_code(), 'Checkpoint A should have verified cleanly.' );

		// Break the environment in a way only a fresh verification would notice.
		delete_option( 'kwfa_hmac_secret' );
		$this->set_static( 'Kreiswolke\\FormAntispam\\Secret', 'cache', null );
		$this->set_static( 'Kreiswolke\\FormAntispam\\Status', 'cache', null );

		$rejected = apply_filters(
			'kadence_blocks_advanced_form_submission_reject',
			false,
			array(),
			array(),
			'42'
		);

		$this->assertFalse( $rejected, 'Checkpoint B should have passed on the memo.' );
		$this->assertSame( '', Status::get_code(), 'Checkpoint B re-entered the verifier.' );
	}

	// -------------------------------------------------------------------------
	// Peek vs commit — the regression the design exists for
	// -------------------------------------------------------------------------

	/**
	 * A submission Kadence rejects for its own reasons must not spend the
	 * solution, and the visitor's corrected resubmission must be accepted.
	 *
	 * Kadence's process_fields() bails on a missing required field
	 * (advanced-form-ajax.php:432-435) before the reject filter ever runs, so
	 * checkpoint B never fires. If the marker were spent in checkpoint A, the
	 * visitor's second attempt — which posts the same still-valid payload,
	 * because the widget is still in its verified state — would be rejected as
	 * a replay and the form would look broken.
	 *
	 * @return void
	 */
	public function test_resubmission_after_a_kadence_field_error_is_accepted() {
		$payload = Solver::payload_for_form( 42 );
		$post    = $this->submission_post( $payload );

		$first = $this->submit( $post, 'field_error' );

		$this->assertSame( 'kadence_field_error', $first['result'] );
		$this->assertSame( array(), $this->replay_markers(), 'Nothing may be spent when Kadence rejects.' );

		$second = $this->submit( $post, 'accept' );

		$this->assertSame( 'accepted', $second['result'], 'The corrected resubmission must go through.' );
		$this->assertCount( 1, $this->replay_markers() );
	}

	/**
	 * Once a submission has been accepted, the same payload is a replay.
	 *
	 * @return void
	 */
	public function test_replay_after_acceptance_is_rejected() {
		$payload = Solver::payload_for_form( 42 );
		$post    = $this->submission_post( $payload );

		$this->assertSame( 'accepted', $this->submit( $post )['result'] );

		$replay = $this->submit( $post );

		$this->assertSame( 'rejected_at_checkpoint_a', $replay['result'], 'A replay must be caught early.' );
	}

	/**
	 * Replays are caught before Kadence writes uploads or calls a CAPTCHA
	 * service, i.e. by checkpoint A rather than only by the filter.
	 *
	 * @return void
	 */
	public function test_replay_is_rejected_at_checkpoint_a_with_kadence_json_shape() {
		$payload = Solver::payload_for_form( 42 );
		$post    = $this->submission_post( $payload );

		$this->submit( $post );
		$replay = $this->submit( $post );

		$this->assertSame( 'rejected_at_checkpoint_a', $replay['result'] );
		$this->assertKadenceBailShape( $replay['payload'] );
	}

	// -------------------------------------------------------------------------
	// Rejections
	// -------------------------------------------------------------------------

	/**
	 * No payload at all is a rejection, not a failure of ours.
	 *
	 * @return void
	 */
	public function test_missing_payload_is_rejected() {
		$post = $this->submission_post( '' );
		unset( $post['kwfa_altcha'] );

		$outcome = $this->submit( $post );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
		$this->assertSame( '', Status::get_code(), 'A missing solution is not a plugin failure.' );
	}

	/**
	 * Anything that is not base64 is discarded before it reaches the verifier.
	 *
	 * @return void
	 */
	public function test_non_base64_payload_is_rejected() {
		$outcome = $this->submit( $this->submission_post( '<script>alert(1)</script>' ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
	}

	/**
	 * An oversized payload is discarded without being decoded.
	 *
	 * @return void
	 */
	public function test_oversized_payload_is_rejected() {
		$outcome = $this->submit( $this->submission_post( str_repeat( 'A', 9000 ) ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
	}

	/**
	 * Editing the signed parameters invalidates the signature.
	 *
	 * @return void
	 */
	public function test_tampered_parameters_are_rejected() {
		$decoded = Solver::decode( Solver::payload_for_form( 42 ) );

		$decoded['challenge']['parameters']['cost'] = 1;

		$outcome = $this->submit( $this->submission_post( Solver::reencode( $decoded ) ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
	}

	/**
	 * Claiming a different counter invalidates the solution.
	 *
	 * @return void
	 */
	public function test_wrong_counter_is_rejected() {
		$decoded = Solver::decode( Solver::payload_for_form( 42 ) );

		$decoded['solution']['counter'] = (int) $decoded['solution']['counter'] + 1;

		$outcome = $this->submit( $this->submission_post( Solver::reencode( $decoded ) ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
	}

	/**
	 * The widget's test-mode payload must never be honoured.
	 *
	 * @return void
	 */
	public function test_widget_test_mode_payload_is_rejected() {
		$outcome = $this->submit( $this->submission_post( Solver::test_mode_payload() ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
	}

	/**
	 * An expired challenge is rejected even though its signature is valid.
	 *
	 * Minted straight from the protocol core so the lifetime can go below the
	 * plugin's own 60-second floor; the alternative is a minute-long test.
	 *
	 * @return void
	 */
	public function test_expired_challenge_is_rejected() {
		$challenge = \Kreiswolke\FormAntispam\Altcha\Challenge::create(
			\Kreiswolke\FormAntispam\Secret::get(),
			array(
				'expires_in' => 1,
				'cost'       => 1,
				'data'       => \Kreiswolke\FormAntispam\Plugin::binding( 42 ),
			)
		);

		$payload = Solver::solve( $challenge );

		// Solve first, then let it lapse, so the wait is the only cost.
		sleep( 2 );

		$outcome = $this->submit( $this->submission_post( $payload ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
		$this->assertSame( array(), $this->replay_markers(), 'An expired solution must not be spent.' );
	}

	/**
	 * A solution minted for one form cannot be spent on another.
	 *
	 * @return void
	 */
	public function test_solution_for_another_form_is_rejected() {
		$payload = Solver::payload_for_form( 7 );

		$outcome = $this->submit( $this->submission_post( $payload, 42 ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
	}

	/**
	 * And the same solution is fine on the form it was minted for.
	 *
	 * @return void
	 */
	public function test_solution_is_accepted_on_its_own_form() {
		$payload = Solver::payload_for_form( 7 );

		$outcome = $this->submit( $this->submission_post( $payload, 7 ) );

		$this->assertSame( 'accepted', $outcome['result'] );
	}

	// -------------------------------------------------------------------------
	// The rejection message
	// -------------------------------------------------------------------------

	/**
	 * Checkpoint A's response has to look exactly like Kadence's own bail so
	 * their front-end script renders it (advanced-form-ajax.php:447-457).
	 *
	 * @return void
	 */
	public function test_checkpoint_a_bail_matches_kadence_shape() {
		$outcome = $this->submit( $this->submission_post( 'not!!base64' ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
		$this->assertKadenceBailShape( $outcome['payload'] );
	}

	/**
	 * We only supply a message when we are the ones rejecting.
	 *
	 * @return void
	 */
	public function test_message_filter_leaves_other_rejections_alone() {
		$payload = Solver::payload_for_form( 42 );

		$this->new_request();
		$_POST = $this->submission_post( $payload );

		do_action( 'wp_ajax_nopriv_kb_process_advanced_form_submit' );

		$message = apply_filters(
			'kadence_blocks_advanced_form_submission_reject_message',
			'Submission rejected.',
			array(),
			array(),
			'42'
		);

		$this->assertSame( 'Submission rejected.', $message );
	}

	/**
	 * Kadence injects the message into markup unescaped and the browser renders
	 * it through innerHTML, so it must be escaped on our side.
	 *
	 * @return void
	 */
	public function test_rejection_message_is_escaped() {
		add_filter(
			'kwfa_rejection_message',
			function () {
				return '<img src=x onerror=alert(1)>"quoted"';
			}
		);

		$outcome = $this->submit( $this->submission_post( 'not!!base64' ) );

		$message = $outcome['message'];

		$this->assertStringNotContainsString( '<img', $message );
		$this->assertStringNotContainsString( '"', $message );
		$this->assertStringContainsString( '&lt;img', $message );

		$this->reset_rejection_message_filter();
	}

	/**
	 * An empty or non-string filter return falls back to the default message.
	 *
	 * @return void
	 */
	public function test_rejection_message_falls_back_when_the_filter_returns_junk() {
		add_filter(
			'kwfa_rejection_message',
			function () {
				return array( 'not a string' );
			}
		);

		$outcome = $this->submit( $this->submission_post( 'not!!base64' ) );

		$this->assertNotSame( '', $outcome['message'] );
		$this->assertStringContainsString( 'could not verify', $outcome['message'] );

		$this->reset_rejection_message_filter();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Assert a payload matches KB_Ajax_Advanced_Form::process_bail().
	 *
	 * @param array $payload Payload handed to wp_send_json_error().
	 * @return void
	 */
	private function assertKadenceBailShape( array $payload ) {
		foreach ( array( 'html', 'console', 'fieldErrors', 'message', 'headers_sent' ) as $key ) {
			$this->assertArrayHasKey( $key, $payload, "Kadence's bail payload has a '{$key}' key." );
		}

		$this->assertNull( $payload['fieldErrors'] );
		$this->assertStringContainsString( 'kb-adv-form-message kb-adv-form-warning', $payload['html'] );
		$this->assertSame( $payload['message'], $payload['console'] );
		$this->assertStringContainsString( $payload['message'], $payload['html'] );
	}

	/**
	 * Drop any kwfa_rejection_message callbacks a test registered.
	 *
	 * Hooks live for the whole process, so a test that adds one has to take it
	 * away again.
	 *
	 * @return void
	 */
	private function reset_rejection_message_filter() {
		unset( WP_Stub_State::$hooks['kwfa_rejection_message'] );
	}
}
