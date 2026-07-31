<?php
/**
 * The full path a real visitor takes, at the plugin's shipped settings.
 *
 * Nothing is stubbed here except WordPress itself: the challenge comes from the
 * real REST handler, the proof-of-work is really solved against the real
 * protocol core, and the payload is byte-for-byte what the ALTCHA widget writes
 * into the hidden field.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

namespace Kreiswolke\FormAntispam\Tests;

use Kreiswolke\FormAntispam\Gate;
use Kreiswolke\FormAntispam\Status;
use WP_Stub_State;

/**
 * @covers \Kreiswolke\FormAntispam\Gate
 * @covers \Kreiswolke\FormAntispam\Rest_Challenge
 * @group slow
 */
final class EndToEndTest extends TestCase {

	/**
	 * Give every test a working site. Note the shipped cost is used — this file
	 * deliberately does not set WP_Stub_State::$forced_cost.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->install_secret();
	}

	/**
	 * Mint, solve, submit, accept — the whole thing at production difficulty.
	 *
	 * @return void
	 */
	public function test_a_visitor_can_submit_a_form() {
		$payload = Solver::payload_for_form( 42 );

		$outcome = $this->submit( $this->submission_post( $payload ) );

		$this->assertSame( 'accepted', $outcome['result'] );
		$this->assertSame( '', Status::get_code(), 'A clean run must leave no failure recorded.' );
		$this->assertCount( 1, $this->replay_markers() );
	}

	/**
	 * The payload stays comfortably inside the gate's size cap, so the cap can
	 * never reject a legitimate submission.
	 *
	 * @return void
	 */
	public function test_a_real_payload_is_far_below_the_size_cap() {
		$payload = Solver::payload_for_form( 42 );

		$this->assertLessThan( Gate::MAX_PAYLOAD / 4, strlen( $payload ) );
	}

	/**
	 * The proof-of-work is real work, but not much of it: a single-threaded PHP
	 * solve at the shipped cost must stay well inside the widget's 90 s timeout,
	 * given the browser uses native WebCrypto across several workers.
	 *
	 * @return void
	 */
	public function test_the_shipped_cost_is_solvable_quickly() {
		$started = microtime( true );

		Solver::payload_for_form( 42 );

		$elapsed = microtime( true ) - $started;

		$this->assertLessThan(
			10.0,
			$elapsed,
			sprintf( 'Single-threaded PHP solve took %.1fs; the shipped cost is too high.', $elapsed )
		);
	}

	/**
	 * Two visitors on the same page, on two different forms, do not collide.
	 *
	 * @return void
	 */
	public function test_two_forms_on_one_page_are_independent() {
		$first  = Solver::payload_for_form( 42 );
		$second = Solver::payload_for_form( 7 );

		$this->assertSame( 'accepted', $this->submit( $this->submission_post( $first, 42 ) )['result'] );
		$this->assertSame( 'accepted', $this->submit( $this->submission_post( $second, 7 ) )['result'] );
		$this->assertCount( 2, $this->replay_markers() );
	}

	/**
	 * The whole visitor journey, including the awkward middle: a first attempt
	 * that Kadence rejects for a missing field, a successful retry, and then a
	 * replay of the spent payload.
	 *
	 * @return void
	 */
	public function test_full_visitor_journey() {
		$payload = Solver::payload_for_form( 42 );
		$post    = $this->submission_post( $payload );

		$this->assertSame( 'kadence_field_error', $this->submit( $post, 'field_error' )['result'] );
		$this->assertSame( array(), $this->replay_markers() );

		$this->assertSame( 'accepted', $this->submit( $post, 'accept' )['result'] );
		$this->assertCount( 1, $this->replay_markers() );

		$this->assertSame( 'rejected_at_checkpoint_a', $this->submit( $post, 'accept' )['result'] );
		$this->assertCount( 1, $this->replay_markers(), 'A rejected replay must not add a marker.' );
	}

}
