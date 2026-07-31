<?php
/**
 * Fail-open reporting: when protection is off, say so and say why.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records why protection is unavailable and surfaces it in wp-admin.
 *
 * The plugin never blocks a submission because its own machinery failed. It
 * lets the submission through and makes the failure loud instead: a contact
 * form going dark is a worse outcome for a site owner than a window of spam.
 */
final class Status {

	/**
	 * Option name. Small and read on every request, so it stays autoloaded.
	 */
	const OPTION = 'kwfa_status';

	/**
	 * Per-request memo of the stored code.
	 *
	 * @var string|null
	 */
	private static $cache = null;

	/**
	 * Hook the admin notice.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'render_notice' ) );
	}

	/**
	 * Record a failure reason.
	 *
	 * Writes only when the reason actually changed, so a broken site does not
	 * generate one option write per request.
	 *
	 * @param string $code One of the keys understood by messages().
	 * @return void
	 */
	public static function report( $code ) {
		$code = sanitize_key( $code );

		if ( '' === $code || ! array_key_exists( $code, self::messages() ) ) {
			return;
		}

		if ( self::get_code() === $code ) {
			return;
		}

		self::$cache = $code;
		update_option( self::OPTION, $code, true );
	}

	/**
	 * Clear the recorded failure. Called when a verification completes cleanly.
	 *
	 * @return void
	 */
	public static function clear() {
		if ( '' === self::get_code() ) {
			return;
		}

		self::$cache = '';
		delete_option( self::OPTION );
	}

	/**
	 * Current failure code, or an empty string when everything is fine.
	 *
	 * @return string
	 */
	public static function get_code() {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, '' );
			self::$cache = is_string( $stored ) ? $stored : '';
		}

		return self::$cache;
	}

	/**
	 * Print the admin notice.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$code     = self::get_code();
		$messages = self::messages();

		if ( '' === $code || ! isset( $messages[ $code ] ) ) {
			return;
		}

		$notice = sprintf(
			/* translators: %s: the specific reason protection is unavailable. */
			esc_html__( 'KW Form Antispam: spam protection is currently OFF. %s Form submissions are still being accepted — the plugin never blocks a form because of its own failure.', 'kw-form-antispam' ),
			$messages[ $code ]
		);

		wp_admin_notice(
			$notice,
			array(
				'type'               => 'warning',
				'dismissible'        => false,
				'additional_classes' => array( 'kwfa-notice' ),
			)
		);
	}

	/**
	 * Human-readable reason per failure code.
	 *
	 * @return array<string,string>
	 */
	private static function messages() {
		return array(
			'kadence_missing'          => esc_html__( 'Kadence Blocks is not active, so there are no Advanced Forms to protect.', 'kw-form-antispam' ),
			'secret_missing'           => esc_html__( 'The signing secret is missing or unreadable; visit any admin page as an administrator to have it regenerated.', 'kw-form-antispam' ),
			'core_missing'             => esc_html__( 'The challenge/verification component of the plugin could not be loaded; reinstall the plugin.', 'kw-form-antispam' ),
			'verifier_error'           => esc_html__( 'Verification raised an unexpected error on the last submission.', 'kw-form-antispam' ),
			'challenge_error'          => esc_html__( 'A challenge could not be generated on the last request.', 'kw-form-antispam' ),
			'replay_store_unavailable' => esc_html__( 'The single-use store (transients) is not writable, so a solved challenge could be reused until it expires.', 'kw-form-antispam' ),
		);
	}
}
