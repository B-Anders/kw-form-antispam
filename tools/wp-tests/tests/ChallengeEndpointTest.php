<?php
/**
 * The public challenge endpoint.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

namespace Kreiswolke\FormAntispam\Tests;

use Kreiswolke\FormAntispam\Plugin;
use Kreiswolke\FormAntispam\Rest_Challenge;
use WP_REST_Request;
use WP_Stub_State;

/**
 * @covers \Kreiswolke\FormAntispam\Rest_Challenge
 */
final class ChallengeEndpointTest extends TestCase {

	/**
	 * Give every test a working site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->install_secret();
	}

	/**
	 * Remove filters registered by individual tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( WP_Stub_State::$hooks['kwfa_challenge_expires_in'] );

		parent::tearDown();
	}

	/**
	 * The route is registered under the plugin's own namespace.
	 *
	 * @return void
	 */
	public function test_route_is_registered() {
		WP_Stub_State::$hooks = array_filter(
			WP_Stub_State::$hooks,
			function ( $key ) {
				return 0 !== strpos( $key, 'rest_route:' );
			},
			ARRAY_FILTER_USE_KEY
		);

		Rest_Challenge::register_route();

		$this->assertArrayHasKey(
			'rest_route:kw-form-antispam/v1/challenge',
			WP_Stub_State::$hooks
		);
	}

	/**
	 * A challenge comes back in the shape the widget validates against
	 * (Widget.svelte isChallengeValid: parameters + algorithm/nonce/salt/keyPrefix).
	 *
	 * @return void
	 */
	public function test_challenge_has_the_shape_the_widget_requires() {
		$challenge = $this->mint();

		$this->assertArrayHasKey( 'parameters', $challenge );
		$this->assertArrayHasKey( 'signature', $challenge );

		foreach ( array( 'algorithm', 'nonce', 'salt', 'keyPrefix', 'cost', 'keyLength', 'expiresAt' ) as $key ) {
			$this->assertArrayHasKey( $key, $challenge['parameters'] );
		}

		$this->assertSame( 'PBKDF2/SHA-256', $challenge['parameters']['algorithm'] );
	}

	/**
	 * The KDF choice matters: SHA-* with keyLength below the digest size and
	 * cost above 1 diverges between the widget and the server
	 * (RESEARCH-altcha.md §B.3). PBKDF2 has no such divergence.
	 *
	 * @return void
	 */
	public function test_key_prefix_is_even_length_hex() {
		$prefix = $this->mint()['parameters']['keyPrefix'];

		$this->assertSame( 0, strlen( $prefix ) % 2, 'An odd-length keyPrefix makes the server-side check a no-op.' );
		$this->assertTrue( (bool) ctype_xdigit( $prefix ) );
	}

	/**
	 * Nothing that would phone home or weaken privacy may be emitted.
	 *
	 * @return void
	 */
	public function test_challenge_emits_no_cloud_features() {
		$challenge = $this->mint();

		$this->assertArrayNotHasKey( 'his', $challenge, 'HIS would POST behavioural telemetry to a URL.' );
		$this->assertArrayNotHasKey( 'codeChallenge', $challenge, 'A code challenge is the cloud image/audio CAPTCHA.' );
		$this->assertArrayNotHasKey( 'verifyUrl', $challenge );
	}

	/**
	 * The form binding rides inside the signed parameters.
	 *
	 * @return void
	 */
	public function test_challenge_carries_the_form_binding() {
		$challenge = $this->mint( 42 );

		$this->assertArrayHasKey( 'data', $challenge['parameters'] );
		$this->assertContains( 'f42', $challenge['parameters']['data'] );
	}

	/**
	 * A form-less request still gets a usable challenge, just without binding.
	 *
	 * @return void
	 */
	public function test_challenge_without_a_form_id_has_no_binding() {
		$challenge = $this->mint( 0 );

		$this->assertArrayHasKey( 'parameters', $challenge );
		$this->assertArrayNotHasKey( 'data', $challenge['parameters'] );
	}

	/**
	 * Every challenge is unique, so one cannot be handed round.
	 *
	 * @return void
	 */
	public function test_every_challenge_is_unique() {
		$first  = $this->mint();
		$second = $this->mint();

		$this->assertNotSame( $first['parameters']['nonce'], $second['parameters']['nonce'] );
		$this->assertNotSame( $first['parameters']['salt'], $second['parameters']['salt'] );
		$this->assertNotSame( $first['signature'], $second['signature'] );
	}

	/**
	 * A challenge must never be cached: a proxy would hand the same single-use
	 * puzzle to every visitor.
	 *
	 * @return void
	 */
	public function test_response_is_not_cacheable() {
		$response = Rest_Challenge::handle( new WP_REST_Request( array( 'form_id' => 42 ) ) );

		$this->assertStringContainsString( 'no-store', $response->headers['Cache-Control'] );
		$this->assertStringContainsString( 'no-cache', $response->headers['Cache-Control'] );
		$this->assertSame( 'no-cache', $response->headers['Pragma'] );
		$this->assertArrayHasKey( 'Expires', $response->headers );
		$this->assertSame( 'noindex', $response->headers['X-Robots-Tag'] );
	}

	/**
	 * The challenge expires, and within the documented bounds.
	 *
	 * @return void
	 */
	public function test_challenge_expires() {
		$expires_at = $this->mint()['parameters']['expiresAt'];

		$this->assertGreaterThan( time(), $expires_at );
		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, $expires_at );
	}

	/**
	 * The lifetime is filterable but clamped, so a filter cannot mint an
	 * effectively immortal challenge.
	 *
	 * @return void
	 */
	public function test_expiry_is_clamped() {
		add_filter(
			'kwfa_challenge_expires_in',
			function () {
				return 10 * DAY_IN_SECONDS;
			}
		);

		$this->assertLessThanOrEqual( time() + HOUR_IN_SECONDS, $this->mint()['parameters']['expiresAt'] );
	}

	/**
	 * The cost the plugin ships with stays inside the bounds it advertises.
	 *
	 * @return void
	 */
	public function test_default_cost_is_within_bounds() {
		$cost = Plugin::cost();

		$this->assertGreaterThanOrEqual( 1000, $cost );
		$this->assertLessThanOrEqual( 250000, $cost );
		$this->assertSame( $cost, $this->mint()['parameters']['cost'] );
	}

	/**
	 * Mint a challenge and return the body.
	 *
	 * @param int $form_id Form CPT post ID.
	 * @return array
	 */
	private function mint( $form_id = 42 ) {
		$response = Rest_Challenge::handle( new WP_REST_Request( array( 'form_id' => $form_id ) ) );

		$this->assertSame( 200, $response->status );

		return $response->data;
	}
}
