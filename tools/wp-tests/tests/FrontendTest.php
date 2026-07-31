<?php
/**
 * Widget injection into the Kadence Advanced Form markup.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

namespace Kreiswolke\FormAntispam\Tests;

use Kreiswolke\FormAntispam\Frontend;
use Kreiswolke\FormAntispam\Gate;
use WP_Stub_State;

/**
 * @covers \Kreiswolke\FormAntispam\Frontend
 */
final class FrontendTest extends TestCase {

	/**
	 * The submit button markup Kadence renders.
	 */
	const SUBMIT_HTML = '<div class="kb-adv-form-field kb-submit-field"><button class="kb-adv-form-submit-button" type="submit">Send</button></div>';

	/**
	 * Give every test a working site.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->install_secret();
		$this->set_static( 'Kreiswolke\\FormAntispam\\Plugin', 'operational', null );
	}

	/**
	 * Remove filters registered by individual tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( WP_Stub_State::$hooks['kwfa_widget_configuration'] );

		parent::tearDown();
	}

	// -------------------------------------------------------------------------
	// Where it injects
	// -------------------------------------------------------------------------

	/**
	 * The widget goes in ahead of the submit button, inside the form.
	 *
	 * @return void
	 */
	public function test_widget_is_injected_before_the_submit_button() {
		$html = $this->inject();

		$this->assertStringContainsString( '<altcha-widget', $html );
		$this->assertLessThan(
			strpos( $html, '<button' ),
			strpos( $html, '<altcha-widget' ),
			'The widget must precede the button, not replace or follow it.'
		);
		$this->assertStringContainsString( self::SUBMIT_HTML, $html, 'Kadence markup must survive untouched.' );
	}

	/**
	 * Every other block passes through byte-for-byte.
	 *
	 * @return void
	 */
	public function test_other_blocks_are_untouched() {
		$blocks = array(
			array( 'blockName' => 'core/paragraph' ),
			array( 'blockName' => 'kadence/advanced-form' ),
			array( 'blockName' => 'kadence/advanced-form-text' ),
			array( 'blockName' => null ),
			array(),
		);

		foreach ( $blocks as $block ) {
			$this->assertSame( 'UNCHANGED', Frontend::inject( 'UNCHANGED', $block ) );
		}

		$this->assertSame( array(), WP_Stub_State::$enqueued_scripts );
	}

	/**
	 * Nothing is injected in wp-admin or in a feed.
	 *
	 * @return void
	 */
	public function test_no_injection_in_admin_or_feeds() {
		WP_Stub_State::$is_admin = true;
		$this->assertStringNotContainsString( '<altcha-widget', $this->inject() );

		WP_Stub_State::$is_admin = false;
		WP_Stub_State::$is_feed  = true;
		$this->assertStringNotContainsString( '<altcha-widget', $this->inject() );
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	/**
	 * The 268 KB widget bundle only loads on pages that really have a form.
	 *
	 * @return void
	 */
	public function test_assets_load_only_when_a_form_is_present() {
		Frontend::inject( 'UNCHANGED', array( 'blockName' => 'core/paragraph' ) );
		$this->assertSame( array(), WP_Stub_State::$enqueued_scripts );

		$this->inject();

		$this->assertContains( 'kwfa-altcha', WP_Stub_State::$enqueued_scripts );
		$this->assertContains( 'kwfa-widget', WP_Stub_State::$enqueued_scripts );
	}

	/**
	 * Two forms on one page must not enqueue the bundle twice.
	 *
	 * @return void
	 */
	public function test_assets_are_enqueued_once_per_request() {
		$this->inject();
		$this->inject();

		$this->assertCount( 2, WP_Stub_State::$enqueued_scripts );
	}

	/**
	 * The glue script depends on the widget bundle, so load order is fixed.
	 *
	 * @return void
	 */
	public function test_glue_script_depends_on_the_widget_bundle() {
		$this->assertArrayHasKey( 'kwfa-widget', WP_Stub_State::$registered_scripts );
		$this->assertContains( 'kwfa-altcha', WP_Stub_State::$registered_scripts['kwfa-widget']['deps'] );
	}

	/**
	 * Translated strings reach the glue script, complete enough that no key
	 * renders as undefined.
	 *
	 * @return void
	 */
	public function test_widget_strings_are_localized() {
		$this->assertArrayHasKey( 'kwfa-widget', WP_Stub_State::$localized );

		$data = WP_Stub_State::$localized['kwfa-widget']['data'];

		$this->assertSame( 'de', $data['language'], 'The de_DE locale must resolve to the "de" subtag.' );

		foreach ( array( 'label', 'verifying', 'verified', 'error', 'expired', 'waitAlert', 'verificationRequired' ) as $key ) {
			$this->assertArrayHasKey( $key, $data['strings'] );
		}
	}

	// -------------------------------------------------------------------------
	// What the markup says
	// -------------------------------------------------------------------------

	/**
	 * The hidden field the widget writes into is the one the gate reads.
	 *
	 * @return void
	 */
	public function test_field_name_matches_the_gate() {
		$this->assertStringContainsString( 'name="' . Gate::FIELD . '"', $this->inject() );
	}

	/**
	 * The challenge URL is per form and static, so cached HTML stays valid.
	 *
	 * @return void
	 */
	public function test_challenge_url_is_per_form_and_static() {
		$first  = $this->inject( 42 );
		$second = $this->inject( 42 );
		$other  = $this->inject( 7 );

		$this->assertSame( $this->challenge_url( $first ), $this->challenge_url( $second ) );
		$this->assertNotSame( $this->challenge_url( $first ), $this->challenge_url( $other ) );
		$this->assertStringContainsString( 'form_id=42', $first );
	}

	/**
	 * No challenge is ever baked into the markup — only the URL to fetch one.
	 *
	 * @return void
	 */
	public function test_no_challenge_is_baked_into_the_markup() {
		$html = $this->inject();

		$this->assertStringNotContainsString( 'signature', $html );
		$this->assertStringNotContainsString( 'nonce', $html );
		$this->assertStringNotContainsString( 'keyPrefix', $html );
	}

	/**
	 * The floating and overlay display modes have a WCAG 2.1.1 keyboard
	 * failure, so the plugin locks the widget to standard mode.
	 *
	 * @return void
	 */
	public function test_display_mode_is_locked_to_standard() {
		$this->assertStringContainsString( 'display="standard"', $this->inject() );
	}

	/**
	 * A site owner cannot switch on the behavioural collector, test mode, an
	 * external verification URL, or an inaccessible display mode.
	 *
	 * @return void
	 */
	public function test_dangerous_configuration_cannot_be_filtered_back_on() {
		add_filter(
			'kwfa_widget_configuration',
			function ( $config ) {
				$config['humanInteractionSignature'] = true;
				$config['test']                      = true;
				$config['display']                   = 'floating';
				$config['verifyUrl']                 = 'https://evil.test/verify';
				$config['setCookie']                 = array( 'name' => 'altcha' );
				$config['codeChallenge']             = array( 'image' => 'https://evil.test/x.png' );
				$config['name']                      = 'altcha';

				return $config;
			}
		);

		$config = $this->configuration( $this->inject() );

		$this->assertFalse( $config['humanInteractionSignature'] );
		$this->assertFalse( $config['test'] );
		$this->assertArrayNotHasKey( 'display', $config );
		$this->assertArrayNotHasKey( 'verifyUrl', $config );
		$this->assertArrayNotHasKey( 'setCookie', $config );
		$this->assertArrayNotHasKey( 'codeChallenge', $config );
		$this->assertArrayNotHasKey( 'name', $config );
	}

	/**
	 * Harmless keys still get through the filter.
	 *
	 * @return void
	 */
	public function test_configuration_filter_still_works_for_safe_keys() {
		add_filter(
			'kwfa_widget_configuration',
			function ( $config ) {
				$config['workers']     = 2;
				$config['minDuration'] = 1000;

				return $config;
			}
		);

		$config = $this->configuration( $this->inject() );

		$this->assertSame( 2, $config['workers'] );
		$this->assertSame( 1000, $config['minDuration'] );
	}

	/**
	 * The ALTCHA attribution and logo are hidden, and no telemetry runs.
	 *
	 * @return void
	 */
	public function test_defaults_are_privacy_and_branding_safe() {
		$config = $this->configuration( $this->inject() );

		$this->assertTrue( $config['hideFooter'] );
		$this->assertTrue( $config['hideLogo'] );
		$this->assertFalse( $config['humanInteractionSignature'] );
		$this->assertFalse( $config['test'] );
	}

	/**
	 * Only attributes on the widget's hard-coded whitelist may be emitted;
	 * anything else is silently ignored by the custom element.
	 *
	 * @return void
	 */
	public function test_only_whitelisted_attributes_are_emitted() {
		$allowed = array( 'auto', 'challenge', 'configuration', 'display', 'language', 'name', 'theme', 'type', 'workers', 'class' );

		preg_match( '#<altcha-widget([^>]*)>#', $this->inject(), $tag );

		// Match whole `name="…"` pairs only. A looser pattern also matches
		// query-string fragments such as `form_id=` inside the challenge URL.
		preg_match_all( '#\s([a-zA-Z][a-zA-Z0-9_-]*)="#', $tag[1], $attributes );

		$this->assertNotEmpty( $attributes[1] );

		foreach ( $attributes[1] as $attribute ) {
			$this->assertContains( $attribute, $allowed, "Attribute '{$attribute}' is not on the widget's whitelist." );
		}
	}

	/**
	 * A malicious form ID cannot break out of the attribute.
	 *
	 * @return void
	 */
	public function test_form_id_is_not_injectable() {
		$block = array(
			'blockName' => 'kadence/advanced-form-submit',
			'attrs'     => array( 'formID' => '42" onload="alert(1)' ),
		);

		$html = Frontend::inject( self::SUBMIT_HTML, $block );

		$this->assertStringNotContainsString( 'onload=', $html );
		$this->assertStringContainsString( 'form_id=42', $html );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Run the injection filter over a submit block.
	 *
	 * @param int $form_id Form CPT post ID.
	 * @return string
	 */
	private function inject( $form_id = 42 ) {
		return Frontend::inject(
			self::SUBMIT_HTML,
			array(
				'blockName' => 'kadence/advanced-form-submit',
				'attrs'     => array(
					'formID'   => (string) $form_id,
					'uniqueID' => $form_id . '_abc123',
				),
			)
		);
	}

	/**
	 * Extract the challenge URL from rendered markup.
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	private function challenge_url( $html ) {
		preg_match( '#challenge="([^"]*)"#', $html, $matches );

		return isset( $matches[1] ) ? $matches[1] : '';
	}

	/**
	 * Extract and decode the configuration attribute.
	 *
	 * @param string $html Markup.
	 * @return array
	 */
	private function configuration( $html ) {
		preg_match( '#configuration="([^"]*)"#', $html, $matches );

		$this->assertNotEmpty( $matches, 'No configuration attribute found.' );

		$decoded = json_decode( html_entity_decode( $matches[1], ENT_QUOTES, 'UTF-8' ), true );

		$this->assertIsArray( $decoded, 'The configuration attribute must be valid JSON once HTML-decoded.' );

		return $decoded;
	}
}
