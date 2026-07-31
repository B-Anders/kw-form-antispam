<?php
/**
 * Public challenge endpoint.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST route that mints a signed, expiring proof-of-work challenge.
 *
 * The route is public by necessity: the visitor who has to solve the challenge
 * is by definition not authenticated. Everything that makes it safe lives in
 * the response instead — the challenge is signed, short-lived, single-use once
 * spent, and the route is rate limited.
 *
 * The challenge is deliberately *not* rendered into the page. A page-cache
 * plugin would happily serve the same expiring challenge to every visitor for
 * hours; fetching it at interaction time keeps cached HTML static.
 */
final class Rest_Challenge {

	/**
	 * REST namespace.
	 */
	const REST_NAMESPACE = 'kw-form-antispam/v1';

	/**
	 * Route.
	 */
	const ROUTE = '/challenge';

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * Endpoint URL for a given form.
	 *
	 * @param int $form_id Kadence form CPT post ID.
	 * @return string
	 */
	public static function url( $form_id ) {
		$url     = rest_url( self::REST_NAMESPACE . self::ROUTE );
		$form_id = absint( $form_id );

		if ( $form_id > 0 ) {
			$url = add_query_arg( 'form_id', $form_id, $url );
		}

		return $url;
	}

	/**
	 * Register the route with the REST server.
	 *
	 * @return void
	 */
	public static function register_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'handle' ),
					// Public on purpose: anonymous visitors must be able to
					// obtain a challenge before they can submit a form.
					'permission_callback' => '__return_true',
					'args'                => array(
						'form_id' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Build a challenge.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return \WP_REST_Response
	 */
	public static function handle( $request ) {
		if ( ! Rate_Limiter::check( 'challenge' ) ) {
			return self::error_response(
				429,
				'kwfa_rate_limited',
				__( 'Too many verification requests. Please wait a moment and try again.', 'kw-form-antispam' ),
				array( 'Retry-After' => (string) Rate_Limiter::retry_after( 'challenge' ) )
			);
		}

		if ( ! Plugin::protocol_available() ) {
			Status::report( 'core_missing' );

			return self::error_response(
				503,
				'kwfa_unavailable',
				__( 'Spam protection is temporarily unavailable.', 'kw-form-antispam' ),
				array()
			);
		}

		$secret = Secret::get();

		if ( '' === $secret ) {
			Status::report( 'secret_missing' );

			return self::error_response(
				503,
				'kwfa_unavailable',
				__( 'Spam protection is temporarily unavailable.', 'kw-form-antispam' ),
				array()
			);
		}

		$form_id = absint( $request->get_param( 'form_id' ) );

		$args = array(
			'expires_in' => Plugin::expires_in(),
			'cost'       => Plugin::cost(),
			'data'       => Plugin::binding( $form_id ),
		);

		try {
			$challenge = Altcha\Challenge::create( $secret, $args );
		} catch ( \Throwable $e ) {
			Status::report( 'challenge_error' );

			return self::error_response(
				503,
				'kwfa_unavailable',
				__( 'Spam protection is temporarily unavailable.', 'kw-form-antispam' ),
				array()
			);
		}

		if ( ! is_array( $challenge ) || empty( $challenge ) ) {
			Status::report( 'challenge_error' );

			return self::error_response(
				503,
				'kwfa_unavailable',
				__( 'Spam protection is temporarily unavailable.', 'kw-form-antispam' ),
				array()
			);
		}

		if ( 'challenge_error' === Status::get_code() ) {
			Status::clear();
		}

		// The heartbeat the drift probe depends on: a challenge is only ever
		// issued because a real visitor touched a real form, and this endpoint
		// is ours, so it keeps working whatever Kadence does.
		Probe::record( 'challenges' );

		$response = new \WP_REST_Response( $challenge, 200 );

		return self::no_cache( $response );
	}

	/**
	 * Build an error response the widget can understand.
	 *
	 * @param int                  $status  HTTP status code.
	 * @param string               $code    Machine-readable code.
	 * @param string               $message Human-readable message.
	 * @param array<string,string> $headers Extra headers.
	 * @return \WP_REST_Response
	 */
	private static function error_response( $status, $code, $message, $headers ) {
		$response = new \WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status
		);

		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}

		return self::no_cache( $response );
	}

	/**
	 * Stamp no-cache headers onto a response.
	 *
	 * Without these a reverse proxy or CDN would happily hand the same
	 * single-use challenge to thousands of visitors.
	 *
	 * @param \WP_REST_Response $response Response to stamp.
	 * @return \WP_REST_Response
	 */
	private static function no_cache( $response ) {
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', 'Wed, 11 Jan 1984 05:00:00 GMT' );
		$response->header( 'X-Robots-Tag', 'noindex' );

		return $response;
	}
}
