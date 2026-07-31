<?php
/**
 * Drift probe: notices when Kadence's submission pipeline moves underneath us.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Watches whether our integration points are still firing.
 *
 * The plugin attaches to three places in Kadence's internals, two of them
 * undocumented. If an update moves one, nothing breaks loudly: we fail open,
 * forms keep working, and protection quietly stops. Because a fleet runs the
 * same Kadence version, that could happen everywhere on the same day with
 * nothing to see.
 *
 * The signal that needs no cooperation from Kadence is our own challenge
 * endpoint. It is ours, it works regardless, and a challenge is only ever
 * issued because a real visitor touched a real form. So challenges are a
 * trustworthy heartbeat, and "challenges are flowing but a checkpoint never
 * fires" means the pipeline moved.
 *
 * Everything here is counters and timestamps. No personal data, no request
 * details, nothing about a visitor.
 */
final class Probe {

	/**
	 * Option holding the counters. Small, and read on admin requests.
	 */
	const OPTION = 'kwfa_probe';

	/**
	 * Storage schema version.
	 */
	const SCHEMA = 1;

	/**
	 * Kadence Blocks version this integration was verified against.
	 */
	const VERIFIED_FREE = '3.7.8.2';

	/**
	 * Kadence Blocks Pro version this integration was verified against.
	 */
	const VERIFIED_PRO = '2.8.17';

	/**
	 * Events we count.
	 *
	 * @var string[]
	 */
	private static $events = array( 'challenges', 'a', 'pass', 'reject', 'b', 'reached', 'accepted' );

	/**
	 * Counts accumulated during this request, flushed once at shutdown.
	 *
	 * @var array<string,int>
	 */
	private static $pending = array();

	/**
	 * Whether the shutdown flush is hooked.
	 *
	 * @var bool
	 */
	private static $flush_hooked = false;

	/**
	 * Hook the structural check.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'structural_check' ) );
	}

	/**
	 * Count one event.
	 *
	 * Accumulates in memory and writes once, at shutdown, and only for requests
	 * that actually saw something. An ordinary page view writes nothing.
	 *
	 * Never throws: a monitoring feature that breaks a submission would be
	 * worse than the problem it monitors.
	 *
	 * @param string $event One of self::$events.
	 * @return void
	 */
	public static function record( $event ) {
		try {
			if ( ! in_array( $event, self::$events, true ) ) {
				return;
			}

			if ( ! isset( self::$pending[ $event ] ) ) {
				self::$pending[ $event ] = 0;
			}

			++self::$pending[ $event ];

			if ( ! self::$flush_hooked ) {
				self::$flush_hooked = true;
				add_action( 'shutdown', array( __CLASS__, 'flush' ), 100 );
			}
		} catch ( \Throwable $e ) {
			// Counting is never worth an error.
			return;
		}
	}

	/**
	 * Write this request's counts. One option write, at most once per request.
	 *
	 * @return void
	 */
	public static function flush() {
		try {
			if ( empty( self::$pending ) ) {
				return;
			}

			$state = self::roll( self::state() );

			foreach ( self::$pending as $event => $count ) {
				$state['c'][ $event ]   += $count;
				$state['last'][ $event ] = time();
			}

			self::$pending = array();

			update_option( self::OPTION, $state, true );
		} catch ( \Throwable $e ) {
			self::$pending = array();
		}
	}

	/**
	 * Cheap checks that need no submission: is the handler still registered,
	 * and has Kadence changed version?
	 *
	 * Throttled, because it runs on admin page loads.
	 *
	 * @return void
	 */
	public static function structural_check() {
		try {
			$state = self::roll( self::state() );

			$interval = (int) apply_filters( 'kwfa_probe_structural_interval', HOUR_IN_SECONDS );

			if ( ( time() - (int) $state['checked'] ) < max( 60, $interval ) ) {
				return;
			}

			$state['checked'] = time();
			$state['ajax']    = has_action( 'wp_ajax_nopriv_kb_process_advanced_form_submit' ) ? 1 : 0;
			$state['free']    = defined( 'KADENCE_BLOCKS_VERSION' ) ? (string) KADENCE_BLOCKS_VERSION : '';
			$state['pro']     = defined( 'KBP_VERSION' ) ? (string) KBP_VERSION : '';

			update_option( self::OPTION, $state, true );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * The full status report.
	 *
	 * This is the plugin's monitoring contract. See kwfa_health_report().
	 *
	 * @return array
	 */
	public static function report() {
		$state = self::roll( self::state() );

		// Evaluate over the current window plus the previous one, so a quiet
		// site still accumulates enough signal to say anything at all.
		$totals = array();

		foreach ( self::$events as $event ) {
			$totals[ $event ] = (int) $state['c'][ $event ] + (int) $state['p'][ $event ];
		}

		$codes = array();

		$min_challenges = self::threshold( 'challenges', 50 );
		$min_accepted   = self::threshold( 'accepted', 10 );
		$min_reached    = self::threshold( 'reached', 10 );
		$min_passes     = self::threshold( 'passes', 25 );

		if ( Plugin::kadence_active() && 0 === (int) $state['ajax'] && (int) $state['checked'] > 0 ) {
			$codes[] = 'ajax_action_missing';
		}

		// Visitors are using forms — a challenge is only issued on a real
		// interaction — but nothing ever reaches our handler.
		if ( $totals['challenges'] >= $min_challenges && 0 === $totals['a'] ) {
			$codes[] = 'checkpoint_a_silent';
		}

		// An accepted submission cannot reach acceptance without passing
		// through the reject filter, so acceptances with no filter firings mean
		// the filter is gone. Deliberately keyed on acceptances rather than on
		// our own passes: a site whose submissions keep failing Kadence's own
		// field validation legitimately never reaches the filter either, and
		// that is the plugin working, not drift.
		if ( $totals['accepted'] >= $min_accepted && 0 === $totals['b'] ) {
			$codes[] = 'reject_filter_silent';
		}

		// The filter fires but Kadence never signals completion.
		if ( $totals['b'] >= $min_reached && 0 === $totals['accepted'] ) {
			$codes[] = 'success_action_silent';
		}

		// We let submissions through and Kadence never finishes one, either
		// way. Field errors still signal completion, so they do not land here.
		if ( $totals['pass'] >= $min_passes && 0 === $totals['reached'] ) {
			$codes[] = 'pipeline_silent';
		}

		$review = array();

		if ( defined( 'KADENCE_BLOCKS_VERSION' ) && self::VERIFIED_FREE !== (string) KADENCE_BLOCKS_VERSION ) {
			$review[] = 'kadence_version_changed';
		}

		if ( defined( 'KBP_VERSION' ) && self::VERIFIED_PRO !== (string) KBP_VERSION ) {
			$review[] = 'kadence_pro_version_changed';
		}

		$protection = Status::get_code();

		if ( $codes ) {
			$status = 'drift';
		} elseif ( '' !== $protection || $review ) {
			$status = 'review';
		} else {
			$status = 'ok';
		}

		$report = array(
			'schema'     => self::SCHEMA,
			'status'     => $status,
			'drift'      => $codes,
			'review'     => $review,
			'protection' => $protection,
			'counters'   => $totals,
			'window'     => array(
				'started' => (int) $state['w'],
				'seconds' => self::window(),
			),
			'last_seen'  => $state['last'],
			'kadence'    => array(
				'free'          => defined( 'KADENCE_BLOCKS_VERSION' ) ? (string) KADENCE_BLOCKS_VERSION : '',
				'pro'           => defined( 'KBP_VERSION' ) ? (string) KBP_VERSION : '',
				'verified_free' => self::VERIFIED_FREE,
				'verified_pro'  => self::VERIFIED_PRO,
				'handler'       => (int) $state['ajax'],
				'checked'       => (int) $state['checked'],
			),
			'plugin'     => KWFA_VERSION,
		);

		/**
		 * Filters the monitoring report.
		 *
		 * Part of the documented contract; extend rather than replace.
		 *
		 * @param array $report The report.
		 */
		return apply_filters( 'kwfa_health_report', $report );
	}

	/**
	 * Human-readable explanations, keyed by code.
	 *
	 * Kept separate from report() so the report itself stays machine-facing
	 * and free of translated text.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function explanations() {
		return array(
			'ajax_action_missing'         => array(
				'title'  => __( 'Kadence no longer registers the form submission handler this plugin attaches to.', 'kw-form-antispam' ),
				'action' => __( 'This usually means a Kadence Blocks update changed how forms are submitted. Spam protection is off until the plugin is updated to match. Check for a KW Form Antispam update.', 'kw-form-antispam' ),
			),
			'checkpoint_a_silent'         => array(
				'title'  => __( 'Verification challenges are being issued, but no form submission has reached the plugin.', 'kw-form-antispam' ),
				'action' => __( 'Visitors are using your forms, but submissions are bypassing the spam check — most likely because a Kadence Blocks update moved the submission handler. Check for a KW Form Antispam update.', 'kw-form-antispam' ),
			),
			'pipeline_silent'             => array(
				'title'  => __( 'Submissions are reaching the plugin, but Kadence never finishes processing any of them.', 'kw-form-antispam' ),
				'action' => __( 'The early check still runs, but everything after it is silent — most likely a Kadence Blocks update changed the submission pipeline. Check for a KW Form Antispam update.', 'kw-form-antispam' ),
			),
			'reject_filter_silent'        => array(
				'title'  => __( 'Submissions are reaching the plugin, but Kadence never runs the validation step this plugin relies on.', 'kw-form-antispam' ),
				'action' => __( 'The early check is still working, but the backup check is not. Protection is reduced rather than off. Check for a KW Form Antispam update.', 'kw-form-antispam' ),
			),
			'success_action_silent'       => array(
				'title'  => __( 'Kadence is accepting submissions without signalling completion, so solved challenges are not being marked as used.', 'kw-form-antispam' ),
				'action' => __( 'A solved challenge could be submitted more than once until it expires. Everything else still works. Check for a KW Form Antispam update.', 'kw-form-antispam' ),
			),
			'kadence_version_changed'     => array(
				'title'  => __( 'Kadence Blocks has been updated since this plugin was last verified against it.', 'kw-form-antispam' ),
				'action' => __( 'This is normal and usually harmless — it is a prompt to test a form submission, not a fault.', 'kw-form-antispam' ),
			),
			'kadence_pro_version_changed' => array(
				'title'  => __( 'Kadence Blocks Pro has been updated since this plugin was last verified against it.', 'kw-form-antispam' ),
				'action' => __( 'This is normal and usually harmless — it is a prompt to test a form submission, not a fault.', 'kw-form-antispam' ),
			),
		);
	}

	/**
	 * Forget everything. Used on uninstall and by tests.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$pending = array();
		delete_option( self::OPTION );
	}

	/**
	 * Window length in seconds.
	 *
	 * @return int
	 */
	private static function window() {
		$window = (int) apply_filters( 'kwfa_probe_window', WEEK_IN_SECONDS );

		return max( HOUR_IN_SECONDS, $window );
	}

	/**
	 * A threshold, filterable.
	 *
	 * @param string $name     Threshold name.
	 * @param int    $fallback Default value.
	 * @return int
	 */
	private static function threshold( $name, $fallback ) {
		$value = (int) apply_filters( 'kwfa_probe_threshold', $fallback, $name );

		return max( 1, $value );
	}

	/**
	 * Read the stored state, normalised.
	 *
	 * @return array
	 */
	private static function state() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) || ! isset( $stored['schema'] ) || self::SCHEMA !== (int) $stored['schema'] ) {
			$stored = array();
		}

		$empty = array_fill_keys( self::$events, 0 );

		return array(
			'schema'  => self::SCHEMA,
			'w'       => isset( $stored['w'] ) ? (int) $stored['w'] : time(),
			'c'       => isset( $stored['c'] ) && is_array( $stored['c'] ) ? array_merge( $empty, $stored['c'] ) : $empty,
			'p'       => isset( $stored['p'] ) && is_array( $stored['p'] ) ? array_merge( $empty, $stored['p'] ) : $empty,
			'last'    => isset( $stored['last'] ) && is_array( $stored['last'] ) ? array_merge( $empty, $stored['last'] ) : $empty,
			'ajax'    => isset( $stored['ajax'] ) ? (int) $stored['ajax'] : 0,
			'checked' => isset( $stored['checked'] ) ? (int) $stored['checked'] : 0,
			'free'    => isset( $stored['free'] ) ? (string) $stored['free'] : '',
			'pro'     => isset( $stored['pro'] ) ? (string) $stored['pro'] : '',
		);
	}

	/**
	 * Advance the window if it has elapsed.
	 *
	 * The current window becomes the previous one and a fresh window starts.
	 * After two elapsed windows there is nothing worth carrying, so both are
	 * cleared — stale counts would be worse than no counts.
	 *
	 * @param array $state State to roll.
	 * @return array
	 */
	private static function roll( array $state ) {
		$window = self::window();
		$age    = time() - (int) $state['w'];

		if ( $age < $window ) {
			return $state;
		}

		$empty = array_fill_keys( self::$events, 0 );

		if ( $age >= ( 2 * $window ) ) {
			$state['p'] = $empty;
		} else {
			$state['p'] = $state['c'];
		}

		$state['c'] = $empty;
		$state['w'] = time();

		return $state;
	}
}
