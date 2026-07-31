<?php
/**
 * Where the status is surfaced: Site Health, Site Health Info, admin notice.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Presents the probe's findings.
 *
 * Two failure classes are deliberately kept apart, because they have different
 * causes and different fixes:
 *
 *   drift      — Kadence moved and our integration no longer fires. Fixed by
 *                updating this plugin.
 *   protection — our own machinery is unavailable (secret, storage). Fixed on
 *                this site.
 */
final class Health {

	/**
	 * Site Health test identifier.
	 */
	const TEST_ID = 'kwfa_kadence_integration';

	/**
	 * Hook the surfaces.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'register_test' ) );
		add_filter( 'debug_information', array( __CLASS__, 'debug_information' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_drift_notice' ) );
		add_action( 'network_admin_notices', array( __CLASS__, 'render_drift_notice' ) );
	}

	/**
	 * Add our test to Site Health.
	 *
	 * @param array $tests Registered tests.
	 * @return array
	 */
	public static function register_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct'][ self::TEST_ID ] = array(
			'label' => __( 'Form spam protection', 'kw-form-antispam' ),
			'test'  => array( __CLASS__, 'run_test' ),
		);

		return $tests;
	}

	/**
	 * The Site Health test.
	 *
	 * @return array
	 */
	public static function run_test() {
		$report = Probe::report();

		$result = array(
			'label'       => __( 'Form spam protection is working', 'kw-form-antispam' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Security', 'kw-form-antispam' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'KW Form Antispam is verifying submissions on your Kadence Advanced Forms.', 'kw-form-antispam' ) . '</p>',
			'actions'     => '',
			'test'        => self::TEST_ID,
		);

		$explanations = Probe::explanations();
		$paragraphs   = array();

		if ( 'drift' === $report['status'] ) {
			$result['status'] = 'critical';
			$result['label']  = __( 'Form spam protection has stopped working', 'kw-form-antispam' );

			foreach ( $report['drift'] as $code ) {
				if ( isset( $explanations[ $code ] ) ) {
					$paragraphs[] = $explanations[ $code ]['title'] . ' ' . $explanations[ $code ]['action'];
				}
			}
		} elseif ( 'review' === $report['status'] ) {
			$result['status'] = 'recommended';
			$result['label']  = __( 'Form spam protection needs a look', 'kw-form-antispam' );

			if ( '' !== $report['protection'] ) {
				$paragraphs[] = Status::describe( $report['protection'] );
			}

			foreach ( $report['review'] as $code ) {
				if ( isset( $explanations[ $code ] ) ) {
					$paragraphs[] = $explanations[ $code ]['title'] . ' ' . $explanations[ $code ]['action'];
				}
			}
		}

		if ( $paragraphs ) {
			$description = '';

			foreach ( $paragraphs as $paragraph ) {
				$description .= '<p>' . esc_html( $paragraph ) . '</p>';
			}

			$result['description'] = $description;
		}

		return $result;
	}

	/**
	 * Add the report to Site Health → Info, where support can copy it.
	 *
	 * @param array $info Debug information.
	 * @return array
	 */
	public static function debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}

		$report = Probe::report();

		$info['kw-form-antispam'] = array(
			'label'  => __( 'KW Form Antispam', 'kw-form-antispam' ),
			'fields' => array(
				'status'        => array(
					'label' => __( 'Status', 'kw-form-antispam' ),
					'value' => $report['status'],
				),
				'drift'         => array(
					'label' => __( 'Integration warnings', 'kw-form-antispam' ),
					'value' => $report['drift'] ? implode( ', ', $report['drift'] ) : __( 'None', 'kw-form-antispam' ),
				),
				'protection'    => array(
					'label' => __( 'Protection warnings', 'kw-form-antispam' ),
					'value' => '' !== $report['protection'] ? $report['protection'] : __( 'None', 'kw-form-antispam' ),
				),
				'counters'      => array(
					'label'   => __( 'Activity in the current window', 'kw-form-antispam' ),
					'value'   => wp_json_encode( $report['counters'] ),
					'private' => false,
				),
				'kadence'       => array(
					'label' => __( 'Kadence Blocks version', 'kw-form-antispam' ),
					'value' => '' !== $report['kadence']['free'] ? $report['kadence']['free'] : __( 'Not active', 'kw-form-antispam' ),
				),
				'kadence_known' => array(
					'label' => __( 'Verified against', 'kw-form-antispam' ),
					'value' => $report['kadence']['verified_free'],
				),
			),
		);

		return $info;
	}

	/**
	 * Drift notice. Kept separate from the protection notice in Status, because
	 * the two mean different things and are fixed in different places.
	 *
	 * @return void
	 */
	public static function render_drift_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$report = Probe::report();

		if ( 'drift' !== $report['status'] ) {
			return;
		}

		$explanations = Probe::explanations();
		$lines        = array();

		foreach ( $report['drift'] as $code ) {
			if ( isset( $explanations[ $code ] ) ) {
				$lines[] = esc_html( $explanations[ $code ]['title'] . ' ' . $explanations[ $code ]['action'] );
			}
		}

		if ( ! $lines ) {
			return;
		}

		$notice = '<strong>' . esc_html__( 'KW Form Antispam: spam protection has stopped working.', 'kw-form-antispam' ) . '</strong> '
			. implode( ' ', $lines );

		wp_admin_notice(
			$notice,
			array(
				'type'               => 'error',
				'dismissible'        => false,
				'additional_classes' => array( 'kwfa-notice', 'kwfa-drift-notice' ),
			)
		);
	}
}
