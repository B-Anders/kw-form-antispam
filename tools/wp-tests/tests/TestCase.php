<?php
/**
 * Shared base for the WordPress-layer tests.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

namespace Kreiswolke\FormAntispam\Tests;

use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use ReflectionClass;
use WP_Json_Exit;
use WP_Stub_State;

/**
 * Resets the stubbed environment and the plugin's per-request statics, and
 * offers a faithful model of Kadence's submission pipeline.
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Fully qualified plugin class names and the statics they memoise into.
	 *
	 * These are per-request caches in production; a test process is many
	 * requests, so they have to be cleared explicitly.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private static $request_statics = array(
		'Kreiswolke\\FormAntispam\\Plugin'   => array( 'operational' => null ),
		'Kreiswolke\\FormAntispam\\Secret'   => array( 'cache' => null ),
		'Kreiswolke\\FormAntispam\\Status'   => array( 'cache' => null ),
		'Kreiswolke\\FormAntispam\\Frontend' => array( 'enqueued' => false ),
		'Kreiswolke\\FormAntispam\\Gate'     => array(
			'decision'       => null,
			'rejected_by_us' => false,
			'spent'          => false,
		),
	);

	/**
	 * Statics that are scoped to a single submission rather than the whole
	 * request lifecycle.
	 *
	 * @var array<string,mixed>
	 */
	private static $gate_statics = array(
		'decision'       => null,
		'rejected_by_us' => false,
		'spent'          => false,
	);

	/**
	 * Start every test from an empty site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		WP_Stub_State::reset_data();
		$this->reset_request_statics();

		$_POST                  = array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		// reset_data() also wipes the script registry, which WordPress rebuilds
		// on every request. `init` callbacks are idempotent and register no
		// further hooks, so replaying it here keeps each test a whole request.
		do_action( 'init' );
	}

	/**
	 * Clear every per-request memo in the plugin.
	 *
	 * @return void
	 */
	protected function reset_request_statics() {
		foreach ( self::$request_statics as $class_name => $properties ) {
			foreach ( $properties as $property => $value ) {
				$this->set_static( $class_name, $property, $value );
			}
		}
	}

	/**
	 * Simulate the boundary between two HTTP requests to admin-ajax.
	 *
	 * Options and transients survive (they are in the database); the gate's
	 * memoised decision does not.
	 *
	 * @return void
	 */
	protected function new_request() {
		foreach ( self::$gate_statics as $property => $value ) {
			$this->set_static( 'Kreiswolke\\FormAntispam\\Gate', $property, $value );
		}

		$this->set_static( 'Kreiswolke\\FormAntispam\\Plugin', 'operational', null );
	}

	/**
	 * Write a private static property.
	 *
	 * @param string $class_name Class name.
	 * @param string $property   Property name.
	 * @param mixed  $value      Value.
	 * @return void
	 */
	protected function set_static( $class_name, $property, $value ) {
		$reflection = new ReflectionClass( $class_name );
		$prop       = $reflection->getProperty( $property );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}

	/**
	 * Call a private static method.
	 *
	 * @param string $class_name Class name.
	 * @param string $method     Method name.
	 * @param array  $args       Arguments.
	 * @return mixed
	 */
	protected function call_static( $class_name, $method, array $args = array() ) {
		$reflection = new ReflectionClass( $class_name );
		$callable   = $reflection->getMethod( $method );
		$callable->setAccessible( true );

		return $callable->invokeArgs( null, $args );
	}

	/**
	 * Give the site a valid signing secret.
	 *
	 * @return string
	 */
	protected function install_secret() {
		return \Kreiswolke\FormAntispam\Secret::ensure();
	}

	/**
	 * The POST body a Kadence Advanced Form sends.
	 *
	 * @param string $payload Base64 ALTCHA payload.
	 * @param int    $form_id Form CPT post ID.
	 * @return array<string,string>
	 */
	protected function submission_post( $payload, $form_id = 42 ) {
		return array(
			// Kadence sends the post ID as a string; see RESEARCH-kadence.md.
			'_kb_adv_form_post_id' => (string) $form_id,
			'_kb_adv_form_id'      => $form_id . '-cpt-id',
			'action'               => 'kb_process_advanced_form_submit',
			'kwfa_altcha'          => $payload,
		);
	}

	/**
	 * Run one submission through Kadence's pipeline, in the real order.
	 *
	 * Mirrors KB_Ajax_Advanced_Form::process_ajax():
	 *
	 *   admin-ajax action  -> our checkpoint A at priority 1, Kadence at 10
	 *   get_form / captcha / process_fields
	 *     - on a field error: process_bail() -> send_json( ..., true )
	 *   reject filter      -> our checkpoint B
	 *     - on a truthy return: reject-message filter, then process_bail()
	 *   after_submit_actions -> send_json( ..., false )
	 *
	 * @param array  $post    POST body.
	 * @param string $outcome 'accept' or 'field_error' — what Kadence itself
	 *                        decides, assuming we let the submission through.
	 * @return array{result:string,message:string,payload:array}
	 */
	protected function submit( array $post, $outcome = 'accept' ) {
		$this->new_request();

		$_POST = $post;

		$post_id = isset( $post['_kb_adv_form_post_id'] ) ? (string) $post['_kb_adv_form_post_id'] : '';

		// Checkpoint A runs here, ahead of Kadence's own handler.
		try {
			do_action( 'wp_ajax_nopriv_kb_process_advanced_form_submit' );
		} catch ( WP_Json_Exit $exit ) {
			return array(
				'result'  => 'rejected_at_checkpoint_a',
				'message' => isset( $exit->payload['message'] ) ? $exit->payload['message'] : '',
				'payload' => $exit->payload,
			);
		}

		$form_args        = array(
			'attributes' => array(),
			'fields'     => array(),
		);
		$processed_fields = array();

		if ( 'field_error' === $outcome ) {
			// process_fields() bailed. The reject filter is never reached.
			do_action( 'kadence_blocks_forms_buffer_flushed', null, true );

			return array(
				'result'  => 'kadence_field_error',
				'message' => '',
				'payload' => array(),
			);
		}

		$rejected = apply_filters(
			'kadence_blocks_advanced_form_submission_reject',
			false,
			$form_args,
			$processed_fields,
			$post_id
		);

		if ( $rejected ) {
			$message = apply_filters(
				'kadence_blocks_advanced_form_submission_reject_message',
				'Submission rejected.',
				$form_args,
				$processed_fields,
				$post_id
			);

			do_action( 'kadence_blocks_forms_buffer_flushed', null, true );

			return array(
				'result'  => 'rejected_at_checkpoint_b',
				'message' => (string) $message,
				'payload' => array(),
			);
		}

		do_action( 'kadence_blocks_forms_buffer_flushed', null, false );

		return array(
			'result'  => 'accepted',
			'message' => '',
			'payload' => array(),
		);
	}

	/**
	 * Single-use markers currently stored.
	 *
	 * @return string[]
	 */
	protected function replay_markers() {
		return WP_Stub_State::transient_names( \Kreiswolke\FormAntispam\Replay::PREFIX );
	}
}
