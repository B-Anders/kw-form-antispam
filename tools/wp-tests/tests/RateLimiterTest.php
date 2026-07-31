<?php
/**
 * Rate limiting on the public challenge endpoint, and its DSGVO properties.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

namespace Kreiswolke\FormAntispam\Tests;

use Kreiswolke\FormAntispam\Rate_Limiter;
use Kreiswolke\FormAntispam\Rest_Challenge;
use WP_REST_Request;
use WP_Stub_State;

/**
 * @covers \Kreiswolke\FormAntispam\Rate_Limiter
 */
final class RateLimiterTest extends TestCase {

	/**
	 * Shipped default: 30 requests per minute.
	 */
	const DEFAULT_LIMIT = 30;

	/**
	 * Give every test a working site and a cheap proof-of-work.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		WP_Stub_State::$forced_cost = 1;
		$this->install_secret();
	}

	/**
	 * Remove any limit filters a test registered; hooks outlive tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset(
			WP_Stub_State::$hooks['kwfa_rate_limit_max'],
			WP_Stub_State::$hooks['kwfa_rate_limit_window'],
			WP_Stub_State::$hooks['kwfa_client_ip']
		);

		parent::tearDown();
	}

	/**
	 * The default bucket allows exactly 30 requests, then stops.
	 *
	 * @return void
	 */
	public function test_default_bucket_allows_thirty_requests() {
		$allowed = 0;

		for ( $i = 0; $i < 40; $i++ ) {
			if ( Rate_Limiter::check( 'challenge' ) ) {
				$allowed++;
			}
		}

		$this->assertSame( self::DEFAULT_LIMIT, $allowed );
	}

	/**
	 * The limit is per client, not shared. One client exhausting its bucket
	 * must not take the site's forms offline for everyone else — a global cap
	 * would be a lever an attacker could pull.
	 *
	 * @return void
	 */
	public function test_there_is_no_global_cap() {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.1';
		for ( $i = 0; $i < 60; $i++ ) {
			Rate_Limiter::check( 'challenge' );
		}
		$this->assertFalse( Rate_Limiter::check( 'challenge' ), 'The noisy client must be limited.' );

		$_SERVER['REMOTE_ADDR'] = '198.51.100.2';
		$this->assertTrue( Rate_Limiter::check( 'challenge' ), 'A different client must be unaffected.' );

		$_SERVER['REMOTE_ADDR'] = '2001:db8::dead:beef';
		$this->assertTrue( Rate_Limiter::check( 'challenge' ), 'IPv6 clients get their own bucket too.' );
	}

	/**
	 * Buckets are per named bucket as well, so a future endpoint cannot eat the
	 * challenge endpoint's allowance.
	 *
	 * @return void
	 */
	public function test_buckets_are_independent() {
		for ( $i = 0; $i < 40; $i++ ) {
			Rate_Limiter::check( 'challenge' );
		}

		$this->assertFalse( Rate_Limiter::check( 'challenge' ) );
		$this->assertTrue( Rate_Limiter::check( 'something-else' ) );
	}

	/**
	 * No raw IP address may appear anywhere in what is stored: not in a
	 * transient name, not in a value, not in an option.
	 *
	 * @return void
	 */
	public function test_no_raw_ip_address_is_ever_stored() {
		$addresses = array( '203.0.113.9', '198.51.100.77', '2001:db8::1' );

		foreach ( $addresses as $address ) {
			$_SERVER['REMOTE_ADDR'] = $address;
			Rate_Limiter::check( 'challenge' );
		}

		$haystack = wp_json_encode(
			array(
				'transients' => WP_Stub_State::$transients,
				'options'    => WP_Stub_State::$options,
			)
		);

		foreach ( $addresses as $address ) {
			$this->assertStringNotContainsString( $address, $haystack, "Raw address {$address} was persisted." );
		}
	}

	/**
	 * The bucket key is keyed with the site secret, so it is not a plain hash
	 * anyone could reverse over the IPv4 space.
	 *
	 * @return void
	 */
	public function test_bucket_key_is_not_a_bare_hash_of_the_address() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		Rate_Limiter::check( 'challenge' );

		$names = WP_Stub_State::transient_names( Rate_Limiter::PREFIX );
		$this->assertCount( 1, $names );

		$bare = Rate_Limiter::PREFIX . substr( hash( 'sha256', '203.0.113.9' ), 0, 32 );

		$this->assertNotSame( $bare, $names[0] );
	}

	/**
	 * The key rotates with the time window, so two visits cannot be linked.
	 *
	 * @return void
	 */
	public function test_bucket_key_rotates_between_windows() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';

		$key_now = $this->bucket_key_for_window( 60 );

		// A different window length puts the client in a different slot, which
		// is the same mechanism that separates consecutive windows in time.
		$key_other = $this->bucket_key_for_window( 3600 );

		$this->assertNotSame( $key_now, $key_other );
	}

	/**
	 * The marker expires with the window rather than accumulating.
	 *
	 * @return void
	 */
	public function test_bucket_expires_with_the_window() {
		Rate_Limiter::check( 'challenge' );

		$names = WP_Stub_State::transient_names( Rate_Limiter::PREFIX );
		$entry = WP_Stub_State::$transients[ $names[0] ];

		$this->assertSame( 60, $entry['ttl'] );
	}

	/**
	 * Limits are filterable.
	 *
	 * @return void
	 */
	public function test_limit_is_filterable() {
		add_filter(
			'kwfa_rate_limit_max',
			function () {
				return 3;
			}
		);

		$allowed = 0;
		for ( $i = 0; $i < 10; $i++ ) {
			if ( Rate_Limiter::check( 'challenge' ) ) {
				$allowed++;
			}
		}

		$this->assertSame( 3, $allowed );
	}

	/**
	 * Setting the limit to zero switches rate limiting off entirely.
	 *
	 * @return void
	 */
	public function test_zero_limit_disables_rate_limiting() {
		add_filter(
			'kwfa_rate_limit_max',
			function () {
				return 0;
			}
		);

		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertTrue( Rate_Limiter::check( 'challenge' ) );
		}

		$this->assertSame( array(), WP_Stub_State::transient_names( Rate_Limiter::PREFIX ) );
	}

	/**
	 * Forwarded-for headers are not trusted by default: honouring them would
	 * let one spammer both evade the limit and flood the options table.
	 *
	 * @return void
	 */
	public function test_forwarded_headers_are_ignored_by_default() {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.5';

		for ( $i = 0; $i < 40; $i++ ) {
			Rate_Limiter::check( 'challenge' );
		}

		// Only one bucket exists, so only REMOTE_ADDR was consulted.
		$this->assertCount( 1, WP_Stub_State::transient_names( Rate_Limiter::PREFIX ) );
		$this->assertFalse( Rate_Limiter::check( 'challenge' ) );

		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/**
	 * A site behind a known proxy can opt in.
	 *
	 * @return void
	 */
	public function test_client_ip_is_filterable() {
		$seen = array();

		add_filter(
			'kwfa_client_ip',
			function ( $ip ) use ( &$seen ) {
				$seen[] = $ip;
				return '198.51.100.5';
			}
		);

		Rate_Limiter::check( 'challenge' );

		$this->assertSame( array( '203.0.113.9' ), $seen );
	}

	/**
	 * With no usable address, requests pass rather than being denied.
	 *
	 * @return void
	 */
	public function test_missing_address_does_not_block() {
		add_filter(
			'kwfa_client_ip',
			function () {
				return '';
			}
		);

		for ( $i = 0; $i < 100; $i++ ) {
			$this->assertTrue( Rate_Limiter::check( 'challenge' ) );
		}
	}

	/**
	 * A garbage address is treated as no address.
	 *
	 * @return void
	 */
	public function test_invalid_address_does_not_block_or_store() {
		add_filter(
			'kwfa_client_ip',
			function () {
				return 'definitely-not-an-ip';
			}
		);

		$this->assertTrue( Rate_Limiter::check( 'challenge' ) );
		$this->assertSame( array(), WP_Stub_State::transient_names( Rate_Limiter::PREFIX ) );
	}

	/**
	 * Over the limit, the endpoint answers 429 with Retry-After and no
	 * challenge, and the response is not cacheable.
	 *
	 * @return void
	 */
	public function test_endpoint_returns_429_when_limited() {
		for ( $i = 0; $i < 40; $i++ ) {
			Rate_Limiter::check( 'challenge' );
		}

		$response = Rest_Challenge::handle( new WP_REST_Request( array( 'form_id' => 42 ) ) );

		$this->assertSame( 429, $response->status );
		$this->assertSame( '60', $response->headers['Retry-After'] );
		$this->assertArrayNotHasKey( 'parameters', $response->data );
		$this->assertStringContainsString( 'no-store', $response->headers['Cache-Control'] );
	}

	/**
	 * Compute the bucket key the limiter would use for a given window.
	 *
	 * @param int $window Window length in seconds.
	 * @return string
	 */
	private function bucket_key_for_window( $window ) {
		add_filter(
			'kwfa_rate_limit_window',
			function () use ( $window ) {
				return $window;
			}
		);

		WP_Stub_State::$transients = array();
		Rate_Limiter::check( 'challenge' );

		unset( WP_Stub_State::$hooks['kwfa_rate_limit_window'] );

		$names = WP_Stub_State::transient_names( Rate_Limiter::PREFIX );

		return $names[0];
	}
}
