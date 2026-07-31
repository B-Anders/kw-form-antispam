<?php
/**
 * Stand-ins for the WordPress classes the plugin touches.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

/**
 * Thrown in place of the exit() inside wp_send_json_error().
 *
 * WordPress terminates the request there; a test needs to inspect what would
 * have been sent, so the stub throws this instead.
 */
class WP_Json_Exit extends \RuntimeException {

	/**
	 * The payload handed to wp_send_json_error().
	 *
	 * @var array
	 */
	public $payload;

	/**
	 * Constructor.
	 *
	 * @param array $payload Response payload.
	 */
	public function __construct( array $payload ) {
		parent::__construct( 'wp_send_json_error()' );
		$this->payload = $payload;
	}
}

/**
 * Minimal WP_REST_Response.
 */
class WP_REST_Response {

	/**
	 * Response body.
	 *
	 * @var mixed
	 */
	public $data;

	/**
	 * HTTP status.
	 *
	 * @var int
	 */
	public $status;

	/**
	 * Response headers.
	 *
	 * @var array<string,string>
	 */
	public $headers = array();

	/**
	 * Constructor.
	 *
	 * @param mixed $data   Body.
	 * @param int   $status Status code.
	 */
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	/**
	 * Set a header.
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 * @return void
	 */
	public function header( $name, $value ) {
		$this->headers[ $name ] = $value;
	}
}

/**
 * Minimal WP_REST_Request.
 */
class WP_REST_Request {

	/**
	 * Request parameters.
	 *
	 * @var array<string,mixed>
	 */
	private $params;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 */
	public function __construct( array $params = array() ) {
		$this->params = $params;
	}

	/**
	 * Read a parameter.
	 *
	 * @param string $key Parameter name.
	 * @return mixed|null
	 */
	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}
}
