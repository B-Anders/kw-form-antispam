<?php
/**
 * The Kadence drift probe.
 *
 * The plugin attaches to three points in Kadence's internals, two undocumented.
 * If an update moves one, nothing breaks loudly — we fail open, forms keep
 * working, protection quietly stops. Across a fleet on one Kadence version that
 * could happen everywhere on the same day with nothing to see.
 *
 * The probe's job is to notice. These tests care as much about it staying quiet
 * when nothing is wrong as about it speaking up when something is: a monitor
 * that cries wolf gets ignored, and an ignored monitor is worse than none.
 *
 * @covers \Kreiswolke\FormAntispam\Probe
 * @covers \Kreiswolke\FormAntispam\Health
 */

namespace Kreiswolke\FormAntispam\Tests;

use Kreiswolke\FormAntispam\Health;
use Kreiswolke\FormAntispam\Probe;
use Kreiswolke\FormAntispam\Status;
use WP_Stub_State;

/**
 * Drift detection, and its false-positive resistance.
 */
final class ProbeTest extends TestCase {

	/**
	 * Set up a working site with a cheap proof-of-work.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		WP_Stub_State::$forced_cost = 1;
		$this->install_secret();

		// A healthy structural baseline: Kadence's handler is registered.
		$this->set_handler_registered( true );
	}

	/**
	 * Remove filters installed by individual tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			WP_Stub_State::$hooks['kwfa_probe_threshold'],
			WP_Stub_State::$hooks['kwfa_probe_window'],
			WP_Stub_State::$hooks['kwfa_probe_structural_interval'],
			WP_Stub_State::$hooks['wp_ajax_nopriv_kb_process_advanced_form_submit_probe']
		);

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Record the structural check's verdict without waiting for admin_init.
	 *
	 * @param bool $registered Whether Kadence's handler is registered.
	 * @return void
	 */
	private function set_handler_registered( $registered ) {
		$state = get_option( Probe::OPTION, array() );

		if ( ! is_array( $state ) ) {
			$state = array();
		}

		// Written straight into storage: recording a real event here would
		// pollute the very counters under test.
		$state['schema']  = Probe::SCHEMA;
		$state['ajax']    = $registered ? 1 : 0;
		$state['checked'] = time();

		update_option( Probe::OPTION, $state, true );
	}

	/**
	 * Simulate N challenges being issued, without submissions.
	 *
	 * @param int $count How many.
	 * @return void
	 */
	private function issue_challenges( $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			Probe::record( 'challenges' );
		}

		Probe::flush();
	}

	/**
	 * Record an event N times.
	 *
	 * @param string $event Event name.
	 * @param int    $count How many.
	 * @return void
	 */
	private function record_many( $event, $count ) {
		for ( $i = 0; $i < $count; $i++ ) {
			Probe::record( $event );
		}

		Probe::flush();
	}

	// -------------------------------------------------------------------------
	// Healthy site
	// -------------------------------------------------------------------------

	/**
	 * A site where everything works reports ok.
	 *
	 * @return void
	 */
	public function test_a_working_site_reports_ok() {
		$payload = Solver::payload_for_form( 42 );
		$this->submit( $this->submission_post( $payload ) );

		$report = Probe::report();

		$this->assertSame( 'ok', $report['status'] );
		$this->assertSame( array(), $report['drift'] );
	}

	/**
	 * A real submission moves every counter it should.
	 *
	 * @return void
	 */
	public function test_a_real_submission_is_counted_end_to_end() {
		$payload = Solver::payload_for_form( 42 );
		$this->submit( $this->submission_post( $payload ) );

		$counters = Probe::report()['counters'];

		$this->assertSame( 1, $counters['challenges'], 'Solver::payload_for_form mints one.' );
		$this->assertSame( 1, $counters['a'] );
		$this->assertSame( 1, $counters['pass'] );
		$this->assertSame( 1, $counters['b'] );
		$this->assertSame( 1, $counters['accepted'] );
	}

	// -------------------------------------------------------------------------
	// Drift
	// -------------------------------------------------------------------------

	/**
	 * The headline case: visitors are using forms, but no submission ever
	 * reaches us. Kadence moved the handler.
	 *
	 * @return void
	 */
	public function test_challenges_flowing_but_no_submission_reaches_us_is_drift() {
		$this->issue_challenges( 50 );

		$report = Probe::report();

		$this->assertSame( 'drift', $report['status'] );
		$this->assertContains( 'checkpoint_a_silent', $report['drift'] );
	}

	/**
	 * Submissions reach us and we let them through, but Kadence never runs the
	 * reject filter. The backup checkpoint is gone.
	 *
	 * @return void
	 */
	public function test_submissions_accepted_but_reject_filter_never_fires_is_drift() {
		$this->record_many( 'a', 10 );
		$this->record_many( 'pass', 10 );
		$this->record_many( 'reached', 10 );
		$this->record_many( 'accepted', 10 );

		$report = Probe::report();

		$this->assertSame( 'drift', $report['status'] );
		$this->assertContains( 'reject_filter_silent', $report['drift'] );
	}

	/**
	 * Kadence accepts submissions but never signals completion, so solved
	 * challenges are never marked used.
	 *
	 * @return void
	 */
	public function test_success_action_never_firing_is_drift() {
		$this->record_many( 'a', 10 );
		$this->record_many( 'pass', 10 );
		$this->record_many( 'b', 10 );
		$this->record_many( 'reached', 10 );

		$report = Probe::report();

		$this->assertSame( 'drift', $report['status'] );
		$this->assertContains( 'success_action_silent', $report['drift'] );
	}

	/**
	 * The structural check needs no traffic at all: if Kadence stops
	 * registering the handler, that is decisive on its own.
	 *
	 * @return void
	 */
	public function test_unregistered_handler_is_drift_without_any_traffic() {
		$this->set_handler_registered( false );

		$report = Probe::report();

		$this->assertSame( 'drift', $report['status'] );
		$this->assertContains( 'ajax_action_missing', $report['drift'] );
	}

	/**
	 * ...but not before the check has ever run, or every fresh install would
	 * alarm on day one.
	 *
	 * @return void
	 */
	public function test_no_alarm_before_the_structural_check_has_run() {
		Probe::reset();

		$report = Probe::report();

		$this->assertNotContains( 'ajax_action_missing', $report['drift'] );
	}

	/**
	 * The structural check reads the real hook registry.
	 *
	 * @return void
	 */
	public function test_structural_check_sees_the_registered_handler() {
		Probe::reset();
		WP_Stub_State::$options['kwfa_probe'] = array();

		Probe::structural_check();

		$state = get_option( Probe::OPTION );

		$this->assertSame( 1, $state['ajax'], 'Gate::init() registered it in bootstrap.' );
		$this->assertGreaterThan( 0, $state['checked'] );
	}

	// -------------------------------------------------------------------------
	// False positives — the part that matters most
	// -------------------------------------------------------------------------

	/**
	 * Three visitors a week must never trigger anything.
	 *
	 * @return void
	 */
	public function test_a_very_quiet_site_never_alarms() {
		$this->issue_challenges( 3 );

		$report = Probe::report();

		$this->assertSame( 'ok', $report['status'] );
		$this->assertSame( array(), $report['drift'] );
	}

	/**
	 * Just below every threshold, still silent.
	 *
	 * @return void
	 */
	public function test_just_below_the_thresholds_is_silent() {
		$this->issue_challenges( 49 );
		$this->record_many( 'a', 9 );
		$this->record_many( 'pass', 9 );
		$this->record_many( 'reached', 9 );
		$this->record_many( 'accepted', 9 );

		$this->assertSame( array(), Probe::report()['drift'] );
	}

	/**
	 * A site under a spam flood: every submission is rejected, so the reject
	 * filter and the success action legitimately never fire. That is the plugin
	 * working exactly as designed, and must not read as drift.
	 *
	 * @return void
	 */
	public function test_a_site_rejecting_all_spam_is_not_drift() {
		$this->issue_challenges( 200 );

		for ( $i = 0; $i < 30; $i++ ) {
			$this->submit( $this->submission_post( 'not!!base64' ) );
		}

		$report = Probe::report();

		$this->assertSame( 'ok', $report['status'], 'Rejecting spam is not drift.' );
		$this->assertSame( array(), $report['drift'] );
		$this->assertGreaterThan( 0, $report['counters']['reject'] );
		$this->assertSame( 0, $report['counters']['b'], 'Because we bail before Kadence gets there.' );
	}

	/**
	 * A site where visitors browse but never submit. Challenges are issued on
	 * interaction, so a high challenge count with no submissions is possible —
	 * which is exactly why the threshold has to be high enough to mean it.
	 *
	 * @return void
	 */
	public function test_visitors_who_start_forms_but_never_send_are_below_threshold() {
		$this->issue_challenges( 49 );

		$this->assertSame( 'ok', Probe::report()['status'] );
	}

	/**
	 * A submission Kadence rejects for its own reasons is not drift either.
	 *
	 * @return void
	 */
	public function test_kadence_field_errors_are_not_drift() {
		for ( $i = 0; $i < 12; $i++ ) {
			$payload = Solver::payload_for_form( 42 );
			$this->submit( $this->submission_post( $payload ), 'field_error' );
		}

		$report = Probe::report();

		$this->assertSame( 0, $report['counters']['b'], 'Kadence bails before the filter.' );
		$this->assertNotContains( 'reject_filter_silent', $report['drift'] );
		$this->assertNotContains( 'pipeline_silent', $report['drift'] );
		$this->assertSame( 'ok', $report['status'], 'Kadence rejecting a submission is not drift.' );
		$this->assertGreaterThan( 0, $report['counters']['reached'], 'Kadence still signalled completion.' );
	}

	/**
	 * But if Kadence never signals completion at all — neither accepting nor
	 * rejecting — everything downstream of us really has gone.
	 *
	 * @return void
	 */
	public function test_nothing_downstream_firing_at_all_is_drift() {
		$this->record_many( 'a', 25 );
		$this->record_many( 'pass', 25 );

		$report = Probe::report();

		$this->assertSame( 'drift', $report['status'] );
		$this->assertContains( 'pipeline_silent', $report['drift'] );
	}

	// -------------------------------------------------------------------------
	// Review, not alarm
	// -------------------------------------------------------------------------

	/**
	 * A Kadence version we have not verified against is a prompt, not a fault.
	 *
	 * @return void
	 */
	public function test_an_unverified_kadence_version_is_review_not_drift() {
		$report = Probe::report();

		// The bootstrap defines KADENCE_BLOCKS_VERSION as the verified version.
		$this->assertSame( Probe::VERIFIED_FREE, $report['kadence']['free'] );
		$this->assertSame( 'ok', $report['status'] );
		$this->assertSame( array(), $report['review'] );
	}

	/**
	 * Our own machinery failing is 'review', and is reported separately from
	 * drift, because the two are fixed in different places.
	 *
	 * @return void
	 */
	public function test_our_own_failure_is_reported_apart_from_drift() {
		Status::report( 'replay_store_unavailable' );

		$report = Probe::report();

		$this->assertSame( 'review', $report['status'] );
		$this->assertSame( array(), $report['drift'], 'Not a Kadence problem.' );
		$this->assertSame( 'replay_store_unavailable', $report['protection'] );
	}

	// -------------------------------------------------------------------------
	// Windows and counters
	// -------------------------------------------------------------------------

	/**
	 * When the window elapses the current counts become the previous ones and a
	 * fresh window starts, so evidence is not lost at the boundary.
	 *
	 * @return void
	 */
	public function test_the_window_rolls_over_without_losing_the_last_one() {
		$this->issue_challenges( 30 );

		$state      = get_option( Probe::OPTION );
		$state['w'] = time() - ( WEEK_IN_SECONDS + 10 );
		update_option( Probe::OPTION, $state, true );

		$this->issue_challenges( 25 );

		$report = Probe::report();

		$this->assertSame( 55, $report['counters']['challenges'], 'Both windows count.' );
		$this->assertContains( 'checkpoint_a_silent', $report['drift'] );
	}

	/**
	 * After two silent windows the old counts are dropped: stale evidence is
	 * worse than none.
	 *
	 * @return void
	 */
	public function test_counters_expire_after_two_windows() {
		$this->issue_challenges( 100 );

		$state      = get_option( Probe::OPTION );
		$state['w'] = time() - ( 2 * WEEK_IN_SECONDS + 10 );
		update_option( Probe::OPTION, $state, true );

		$report = Probe::report();

		$this->assertSame( 0, $report['counters']['challenges'] );
		$this->assertSame( 'ok', $report['status'], 'A site that went quiet is not a broken site.' );
	}

	/**
	 * Counts survive a request boundary.
	 *
	 * @return void
	 */
	public function test_counters_accumulate_across_requests() {
		$this->issue_challenges( 5 );
		$this->issue_challenges( 7 );

		$this->assertSame( 12, Probe::report()['counters']['challenges'] );
	}

	/**
	 * Thresholds are filterable, so a very busy or very quiet site can be tuned.
	 *
	 * @return void
	 */
	public function test_thresholds_are_filterable() {
		add_filter(
			'kwfa_probe_threshold',
			function ( $value, $name ) {
				return 'challenges' === $name ? 5 : $value;
			},
			10,
			2
		);

		$this->issue_challenges( 6 );

		$this->assertContains( 'checkpoint_a_silent', Probe::report()['drift'] );
	}

	// -------------------------------------------------------------------------
	// Cost and safety
	// -------------------------------------------------------------------------

	/**
	 * Counting must not write on every request. Nothing reaches storage until
	 * the request ends, and then only once.
	 *
	 * @return void
	 */
	public function test_counting_does_not_write_until_the_request_ends() {
		Probe::reset();

		Probe::record( 'challenges' );
		Probe::record( 'challenges' );
		Probe::record( 'a' );

		$this->assertFalse( get_option( Probe::OPTION, false ), 'No write yet.' );

		Probe::flush();

		$state = get_option( Probe::OPTION );

		$this->assertSame( 2, $state['c']['challenges'] );
		$this->assertSame( 1, $state['c']['a'] );
	}

	/**
	 * A request that saw nothing writes nothing at all.
	 *
	 * @return void
	 */
	public function test_a_quiet_request_writes_nothing() {
		Probe::reset();

		Probe::flush();

		$this->assertFalse( get_option( Probe::OPTION, false ) );
	}

	/**
	 * The probe must never take a submission down with it. If the options table
	 * refuses writes, the submission still completes.
	 *
	 * @return void
	 */
	public function test_a_failing_probe_cannot_break_a_submission() {
		$payload = Solver::payload_for_form( 42 );

		WP_Stub_State::$options_throw = true;

		$outcome = $this->submit( $this->submission_post( $payload ) );

		WP_Stub_State::$options_throw = false;

		$this->assertSame( 'accepted', $outcome['result'], 'A monitor must never block a lead form.' );
	}

	/**
	 * And an unknown event is ignored rather than stored or thrown on.
	 *
	 * @return void
	 */
	public function test_unknown_events_are_ignored() {
		Probe::reset();

		Probe::record( 'nonsense' );

		// Asserted on the buffer rather than on storage: flush() is
		// deliberately exception-safe, so a bad event that got that far would
		// be swallowed there and the write would look the same as no write.
		$this->assertSame(
			array(),
			$this->get_static( 'Kreiswolke\FormAntispam\Probe', 'pending' ),
			'An unknown event must never enter the buffer.'
		);

		Probe::flush();

		$this->assertFalse( get_option( Probe::OPTION, false ) );
	}

	/**
	 * Corrupt stored state is discarded rather than trusted.
	 *
	 * @return void
	 */
	public function test_corrupt_state_is_discarded() {
		update_option( Probe::OPTION, 'not an array', true );

		$report = Probe::report();

		$this->assertSame( 0, $report['counters']['challenges'] );
		$this->assertSame( 'ok', $report['status'] );
	}

	// -------------------------------------------------------------------------
	// The monitoring contract
	// -------------------------------------------------------------------------

	/**
	 * The documented report shape. This is public API for fleet monitoring, so
	 * a change here is a breaking change.
	 *
	 * @return void
	 */
	public function test_the_report_has_its_documented_shape() {
		$report = Probe::report();

		foreach ( array( 'schema', 'status', 'drift', 'review', 'protection', 'counters', 'window', 'last_seen', 'kadence', 'plugin' ) as $key ) {
			$this->assertArrayHasKey( $key, $report );
		}

		$this->assertContains( $report['status'], array( 'ok', 'review', 'drift' ) );
		$this->assertIsArray( $report['drift'] );
		$this->assertIsArray( $report['counters'] );
		$this->assertSame( KWFA_VERSION, $report['plugin'] );
	}

	/**
	 * The report carries counters and timestamps, and nothing about a visitor.
	 *
	 * @return void
	 */
	public function test_the_report_contains_no_personal_data() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$payload = Solver::payload_for_form( 42 );
		$this->submit( $this->submission_post( $payload ) );

		$serialised = wp_json_encode( Probe::report() );

		$this->assertStringNotContainsString( '203.0.113.9', $serialised );
		$this->assertStringNotContainsString( $payload, $serialised );

		foreach ( Probe::report()['counters'] as $value ) {
			$this->assertIsInt( $value );
		}
	}

	/**
	 * The public function is the contract a fleet agent calls.
	 *
	 * @return void
	 */
	public function test_the_public_function_returns_the_report() {
		$this->assertTrue( function_exists( 'kwfa_health_report' ) );

		$report = kwfa_health_report();

		$this->assertSame( Probe::report()['schema'], $report['schema'] );
		$this->assertArrayHasKey( 'status', $report );
	}

	/**
	 * And it is JSON-serialisable, since that is how it will be collected.
	 *
	 * @return void
	 */
	public function test_the_report_survives_json() {
		$json = wp_json_encode( kwfa_health_report() );

		$this->assertIsString( $json );
		$this->assertIsArray( json_decode( $json, true ) );
	}

	// -------------------------------------------------------------------------
	// Site Health
	// -------------------------------------------------------------------------

	/**
	 * The test is registered where WordPress looks for it.
	 *
	 * @return void
	 */
	public function test_site_health_test_is_registered() {
		$tests = Health::register_test( array( 'direct' => array() ) );

		$this->assertArrayHasKey( Health::TEST_ID, $tests['direct'] );
		$this->assertTrue( is_callable( $tests['direct'][ Health::TEST_ID ]['test'] ) );
	}

	/**
	 * Healthy site: green.
	 *
	 * @return void
	 */
	public function test_site_health_reports_good_when_healthy() {
		$result = Health::run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertSame( Health::TEST_ID, $result['test'] );
	}

	/**
	 * Drift: critical, and the description says what happened and what to do.
	 *
	 * @return void
	 */
	public function test_site_health_reports_critical_on_drift() {
		$this->issue_challenges( 50 );

		$result = Health::run_test();

		$this->assertSame( 'critical', $result['status'] );
		$this->assertStringContainsString( 'no form submission has reached', $result['description'] );
		$this->assertStringContainsString( 'update', $result['description'] );
	}

	/**
	 * Our own failure: recommended, and described as a site problem rather than
	 * a Kadence one.
	 *
	 * @return void
	 */
	public function test_site_health_distinguishes_our_own_failure() {
		Status::report( 'secret_missing' );

		$result = Health::run_test();

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertStringContainsString( 'signing secret', $result['description'] );
		$this->assertStringNotContainsString( 'Kadence Blocks update', $result['description'] );
	}

	/**
	 * The drift notice names the problem and is kept apart from the protection
	 * notice, which has a different cause and a different fix.
	 *
	 * @return void
	 */
	public function test_drift_notice_is_separate_from_the_protection_notice() {
		$this->issue_challenges( 50 );
		WP_Stub_State::$user_can = true;

		Health::render_drift_notice();
		Status::render_notice();

		$this->assertCount( 1, WP_Stub_State::$admin_notices, 'Only the drift notice applies here.' );
		$this->assertStringContainsString( 'stopped working', WP_Stub_State::$admin_notices[0] );
	}

	/**
	 * No drift, no notice.
	 *
	 * @return void
	 */
	public function test_no_drift_notice_on_a_healthy_site() {
		WP_Stub_State::$user_can = true;

		Health::render_drift_notice();

		$this->assertSame( array(), WP_Stub_State::$admin_notices );
	}

	/**
	 * The notice is for people who can act on it.
	 *
	 * @return void
	 */
	public function test_drift_notice_requires_capability() {
		$this->issue_challenges( 50 );
		WP_Stub_State::$user_can = false;

		Health::render_drift_notice();

		$this->assertSame( array(), WP_Stub_State::$admin_notices );
	}

	/**
	 * Site Health Info carries the report for support and tooling.
	 *
	 * @return void
	 */
	public function test_debug_information_includes_the_report() {
		$info = Health::debug_information( array() );

		$this->assertArrayHasKey( 'kw-form-antispam', $info );
		$this->assertArrayHasKey( 'status', $info['kw-form-antispam']['fields'] );
		$this->assertArrayHasKey( 'kadence', $info['kw-form-antispam']['fields'] );
	}
}
