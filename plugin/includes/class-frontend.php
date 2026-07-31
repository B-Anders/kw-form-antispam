<?php
/**
 * Front-end injection and asset loading.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts the widget inside every rendered Kadence Advanced Form.
 *
 * Kadence renders a form's inner blocks through `do_blocks()` inside its own
 * `build_html()`, so the standard `render_block` filter fires for the submit
 * block while it is nested inside the `<form>` element. That is the injection
 * point: precise, no string surgery on the form markup, and the block's own
 * `formID` attribute tells us which form CPT we are looking at.
 */
final class Frontend {

	/**
	 * Handle for the vendored ALTCHA widget bundle.
	 */
	const VENDOR_HANDLE = 'kwfa-altcha';

	/**
	 * Handle for our glue script.
	 */
	const GLUE_HANDLE = 'kwfa-widget';

	/**
	 * Block we attach to.
	 */
	const ANCHOR_BLOCK = 'kadence/advanced-form-submit';

	/**
	 * Whether the assets were already enqueued this request.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Hook up.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
		add_filter( 'render_block', array( __CLASS__, 'inject' ), 20, 2 );
	}

	/**
	 * Register (but do not enqueue) the scripts.
	 *
	 * @return void
	 */
	public static function register_assets() {
		wp_register_script(
			self::VENDOR_HANDLE,
			KWFA_PLUGIN_URL . 'assets/vendor/altcha/altcha.umd.js',
			array(),
			'3.2.1',
			true
		);

		wp_register_script(
			self::GLUE_HANDLE,
			KWFA_PLUGIN_URL . 'assets/js/kwfa-widget.js',
			array( self::VENDOR_HANDLE ),
			KWFA_VERSION,
			true
		);

		wp_localize_script(
			self::GLUE_HANDLE,
			'kwfaWidgetData',
			array(
				'language'    => self::language(),
				'strings'     => self::strings(),
				'waitTimeout' => Plugin::submit_wait_timeout(),
				'noticeDelay' => Plugin::submit_notice_delay(),
				'ui'          => array(
					/* translators: Shown next to the submit button when a visitor submits before the background check has finished. The submission is sent automatically once it has. */
					'waiting' => __( 'Just a moment — finishing the spam check, then sending your message.', 'kw-form-antispam' ),
				),
			)
		);
	}

	/**
	 * Inject the widget ahead of the submit button.
	 *
	 * @param string $block_content Rendered block HTML.
	 * @param array  $block         Parsed block.
	 * @return string
	 */
	public static function inject( $block_content, $block ) {
		if ( ! is_array( $block ) || empty( $block['blockName'] ) || self::ANCHOR_BLOCK !== $block['blockName'] ) {
			return $block_content;
		}

		if ( is_admin() || is_feed() ) {
			return $block_content;
		}

		// When protection is unavailable the widget must not appear at all:
		// a widget that cannot obtain a challenge would block the form, and
		// this plugin never blocks a form because of its own failure.
		if ( ! Plugin::is_operational() ) {
			return $block_content;
		}

		$form_id = isset( $block['attrs']['formID'] ) ? absint( $block['attrs']['formID'] ) : 0;

		self::enqueue();

		return self::widget_html( $form_id ) . $block_content;
	}

	/**
	 * Enqueue the scripts, once, only for pages that really contain a form.
	 *
	 * @return void
	 */
	private static function enqueue() {
		if ( self::$enqueued ) {
			return;
		}

		self::$enqueued = true;

		wp_enqueue_script( self::VENDOR_HANDLE );
		wp_enqueue_script( self::GLUE_HANDLE );
	}

	/**
	 * Build the widget markup.
	 *
	 * @param int $form_id Kadence form CPT post ID.
	 * @return string
	 */
	private static function widget_html( $form_id ) {
		/**
		 * Filters whether the verification widget is rendered visibly.
		 *
		 * The default is invisible. With the challenge solved in the background
		 * from the visitor's first interaction, the checkbox is never actually
		 * used, and an unstyled third-party panel on every form is a cost with
		 * no matching benefit. Return true for the classic checkbox.
		 *
		 * @param bool $visible Whether to render the widget. Default false.
		 * @param int  $form_id Kadence form CPT post ID.
		 */
		$visible = (bool) apply_filters( 'kwfa_widget_visible', false, $form_id );

		// Only two modes are ever emitted. 'floating' and 'overlay' render their
		// close control as a non-focusable <div role="button"> — a WCAG 2.1.1
		// keyboard failure — so they are not reachable from here at all.
		// 'invisible' is display:none on the widget root and renders no close
		// control of any kind (Widget.svelte:1369, widget.scss:728).
		$display = $visible ? 'standard' : 'invisible';

		$config = array(
			'hideFooter'                => true,
			'hideLogo'                  => true,
			'humanInteractionSignature' => false,
			'minDuration'               => $visible ? 300 : 0,
			'test'                      => false,
			'type'                      => 'checkbox',
		);

		/**
		 * Filters the widget configuration object.
		 *
		 * Keys that would weaken privacy, accessibility or verification are
		 * re-applied afterwards and cannot be overridden.
		 *
		 * @param array $config  Configuration passed to the widget.
		 * @param int   $form_id Kadence form CPT post ID.
		 */
		$config = apply_filters( 'kwfa_widget_configuration', $config, $form_id );

		if ( ! is_array( $config ) ) {
			$config = array();
		}

		// Non-negotiable: no telemetry collector, no test payloads, no external
		// verification round-trip, no cookie, no cloud code challenge, and no
		// display mode other than the one we lock in on the attribute below.
		unset( $config['display'], $config['challenge'], $config['verifyUrl'], $config['verifyFunction'], $config['setCookie'], $config['codeChallenge'], $config['name'] );
		$config['humanInteractionSignature'] = false;
		$config['test']                      = false;

		$json = wp_json_encode( $config );

		if ( false === $json ) {
			$json = '{}';
		}

		$widget = sprintf(
			'<altcha-widget class="kwfa-widget" name="%1$s" display="%2$s" auto="onfocus" language="%3$s" challenge="%4$s" configuration="%5$s"></altcha-widget>',
			esc_attr( Gate::FIELD ),
			esc_attr( $display ),
			esc_attr( self::language() ),
			esc_url( Rest_Challenge::url( $form_id ) ),
			esc_attr( $json )
		);

		if ( ! $visible ) {
			// No field wrapper: `kb-adv-form-field` carries Kadence's field
			// spacing, which would leave a gap where nothing is drawn.
			return $widget;
		}

		return '<div class="kb-adv-form-field kwfa-field">' . $widget . '</div>';
	}

	/**
	 * Language subtag the widget should resolve its strings under.
	 *
	 * @return string
	 */
	private static function language() {
		$locale = determine_locale();
		$parts  = preg_split( '/[_-]/', $locale );
		$lang   = is_array( $parts ) && isset( $parts[0] ) ? strtolower( $parts[0] ) : 'en';

		return preg_match( '/\A[a-z]{2,3}\z/', $lang ) ? $lang : 'en';
	}

	/**
	 * Widget UI strings, translated through WordPress.
	 *
	 * The widget has no `strings` attribute; translations live in a global
	 * registry (`$altcha.i18n`). Supplying them from here is what lets the
	 * plugin ship the 268 KB base bundle instead of the 318 KB-larger build
	 * that carries 73 locales it will never use.
	 *
	 * @return array<string,string>
	 */
	private static function strings() {
		return array(
			'ariaLinkLabel'        => __( 'Spam protection', 'kw-form-antispam' ),
			'cancel'               => __( 'Cancel', 'kw-form-antispam' ),
			/* translators: Prompt on an audio or image code challenge. This plugin never issues one, but the widget expects the key to exist. */
			'enterCode'            => __( 'Enter code', 'kw-form-antispam' ),
			'enterCodeAria'        => __( 'Enter the code you hear. Press Space to play the audio.', 'kw-form-antispam' ),
			'enterCodeFromImage'   => __( 'To continue, please enter the code shown in the image below.', 'kw-form-antispam' ),
			'error'                => __( 'Verification failed. Please try again.', 'kw-form-antispam' ),
			'expired'              => __( 'Verification expired. Please try again.', 'kw-form-antispam' ),
			'footer'               => '',
			'getAudioChallenge'    => __( 'Request an audio challenge', 'kw-form-antispam' ),
			/* translators: Label next to the verification checkbox shown above the form's submit button. */
			'label'                => __( 'I am not a robot', 'kw-form-antispam' ),
			'loading'              => __( 'Loading…', 'kw-form-antispam' ),
			'reload'               => __( 'Reload', 'kw-form-antispam' ),
			'verify'               => __( 'Verify', 'kw-form-antispam' ),
			'verificationRequired' => __( 'Verification required', 'kw-form-antispam' ),
			'verified'             => __( 'Verified', 'kw-form-antispam' ),
			'verifying'            => __( 'Verifying…', 'kw-form-antispam' ),
			/* translators: Shown by the browser if the form is submitted while verification is still running. */
			'waitAlert'            => __( 'Verification is still running — please wait a moment.', 'kw-form-antispam' ),
		);
	}
}
