<?php
/**
 * Multilingual sites: WPML and Polylang.
 *
 * A translation plugin gives one logical form a post per language. Kadence
 * renders and submits whichever post the page's `kadence/advanced-form` block
 * points at, so on a translated page that is the translated post — while the
 * inner submit block, copied verbatim into the translation, still carries the
 * source form's `formID`.
 *
 * Deriving the two sides of the per-form binding differently rejected every
 * submission on every translated page.
 *
 * The site modelled here is the pilot's real target shape: three forms in two
 * languages, six posts in total. Single-form and single-language cases are the
 * easy ones; the combination is where this class of defect lives.
 *
 * @covers \Kreiswolke\FormAntispam\Translation
 * @covers \Kreiswolke\FormAntispam\Frontend
 * @covers \Kreiswolke\FormAntispam\Gate
 */

namespace Kreiswolke\FormAntispam\Tests;

use Kreiswolke\FormAntispam\Status;
use Kreiswolke\FormAntispam\Translation;
use WP_Stub_State;

/**
 * Three forms, two languages.
 */
final class MultilingualTest extends TestCase {

	/**
	 * The pilot site: three forms, German source, English translation.
	 */
	const FAMILIES = array(
		'contact'    => array(
			'de' => 2066,
			'en' => 2095,
		),
		'quote'      => array(
			'de' => 2070,
			'en' => 2099,
		),
		'newsletter' => array(
			'de' => 2074,
			'en' => 2103,
		),
	);

	/**
	 * A form outside every family.
	 */
	const FOREIGN_FORM = 3100;

	/**
	 * Set up a working site with a cheap proof-of-work.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		WP_Stub_State::$forced_cost = 1;
		$this->install_secret();
		$this->reset_translation_memo();
	}

	/**
	 * Remove the stubs an individual test installed.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		FakeWpml::remove();
		$this->reset_translation_memo();

		parent::tearDown();
	}

	/**
	 * Clear the per-request provider memo.
	 *
	 * @return void
	 */
	private function reset_translation_memo() {
		$this->set_static( 'Kreiswolke\\FormAntispam\\Translation', 'provider', null );
	}

	/**
	 * Bring up the pilot site in one language.
	 *
	 * @param string $language 'de' or 'en'.
	 * @return void
	 */
	private function site( $language ) {
		FakeWpml::install( self::FAMILIES, $language );
		$this->reset_translation_memo();
	}

	/**
	 * A form's post ID in a language.
	 *
	 * @param string $family   Family name.
	 * @param string $language Language code.
	 * @return int
	 */
	private function form( $family, $language ) {
		return self::FAMILIES[ $family ][ $language ];
	}

	/**
	 * The whole page in one language: every form block points at that
	 * language's post, every submit block still carries the German source ID.
	 *
	 * @param string $language Language code.
	 * @return array
	 */
	private function page_in( $language ) {
		$forms = array();

		foreach ( self::FAMILIES as $family ) {
			$forms[] = array( $family[ $language ], $family['de'] );
		}

		return $forms;
	}

	// -------------------------------------------------------------------------
	// Render side — the defect, at the point it is created
	// -------------------------------------------------------------------------

	/**
	 * On the English page the form block points at the translation, but the
	 * submit block inside it still carries the source form's `formID` because
	 * the translation plugin copied the post content verbatim. Every challenge
	 * must be bound to what Kadence will actually submit.
	 *
	 * @return void
	 */
	public function test_every_form_on_a_translated_page_binds_to_its_own_translation() {
		$this->site( 'en' );

		$rendered = $this->render_page( $this->page_in( 'en' ) );

		$this->assertSame(
			array( 2095, 2099, 2103 ),
			array_map( array( $this, 'bound_form_id' ), $rendered ),
			'Each form must bind to its own English post, not to the German source.'
		);
	}

	/**
	 * The source-language page is unaffected.
	 *
	 * @return void
	 */
	public function test_every_form_on_the_source_page_binds_to_the_source() {
		$this->site( 'de' );

		$rendered = $this->render_page( $this->page_in( 'de' ) );

		$this->assertSame( array( 2066, 2070, 2074 ), array_map( array( $this, 'bound_form_id' ), $rendered ) );
	}

	/**
	 * Three forms on a page must not bleed into one another.
	 *
	 * @return void
	 */
	public function test_form_tracking_unwinds_between_forms() {
		$this->site( 'en' );

		$rendered = $this->render_page( $this->page_in( 'en' ) );

		$this->assertCount( 3, array_unique( array_map( array( $this, 'bound_form_id' ), $rendered ) ) );
	}

	/**
	 * A form that has finished rendering must stop being "the current form".
	 *
	 * Consecutive forms each push their own ID, so they look right whether or
	 * not the stack ever unwinds. What exposes a stack that only grows is a
	 * submit block rendered *after* a form and outside any form: it must fall
	 * back to its own attribute, not inherit the last form's ID.
	 *
	 * @return void
	 */
	public function test_a_finished_form_stops_being_the_current_form() {
		$this->render_form( 500 );

		$orphan = \Kreiswolke\FormAntispam\Frontend::inject(
			'<button type="submit">Send</button>',
			array(
				'blockName' => 'kadence/advanced-form-submit',
				'attrs'     => array( 'formID' => '600' ),
			)
		);

		$this->assertSame( 600, $this->bound_form_id( $orphan ) );
	}

	/**
	 * Isolates the primary mechanism: the parent block's `id` wins even when
	 * the translation layer cannot vouch for the submit block's `formID`.
	 *
	 * On a translated page both routes happen to agree — translating the stale
	 * `formID` lands on the same post the parent names — so agreement alone
	 * proves nothing about which route ran. Here they disagree: no translation
	 * plugin is active (WPML deactivated, or the form duplicated by hand), so
	 * the stale `formID` cannot be corrected, and only the parent's `id` is
	 * right. Kadence submits the parent's `id`, so anything else rejects every
	 * visitor.
	 *
	 * @return void
	 */
	public function test_parent_id_wins_when_the_stale_form_id_cannot_be_corrected() {
		$this->assertFalse( Translation::is_active() );

		$html = $this->render_form( 2095, 2066 );

		$this->assertSame( 2095, $this->bound_form_id( $html ) );
	}

	/**
	 * Same, with a translation plugin running but the form outside every
	 * family, so the translation layer hands the stale ID straight back.
	 *
	 * @return void
	 */
	public function test_parent_id_wins_for_a_form_outside_every_family() {
		$this->site( 'en' );

		$html = $this->render_form( 4242, self::FOREIGN_FORM );

		$this->assertSame( 4242, $this->bound_form_id( $html ) );
	}

	/**
	 * And the submission that follows is accepted, which is the point.
	 *
	 * @return void
	 */
	public function test_a_stale_form_id_does_not_reject_the_visitor() {
		$html = $this->render_form( 2095, 2066 );

		$payload = Solver::payload_for_form( $this->bound_form_id( $html ) );

		$this->assertSame(
			'accepted',
			$this->submit( $this->submission_post( $payload, 2095 ) )['result']
		);
	}

	/**
	 * With no form block around it, the submit block's own attribute is used —
	 * and put through the translation layer, since it is the stale one.
	 *
	 * @return void
	 */
	public function test_orphan_submit_block_translates_its_own_attribute() {
		$this->site( 'en' );

		$html = \Kreiswolke\FormAntispam\Frontend::inject(
			'<button type="submit">Send</button>',
			array(
				'blockName' => 'kadence/advanced-form-submit',
				'attrs'     => array( 'formID' => (string) $this->form( 'contact', 'de' ) ),
			)
		);

		$this->assertSame( 2095, $this->bound_form_id( $html ) );
	}

	// -------------------------------------------------------------------------
	// Verification side — translations are the same form
	// -------------------------------------------------------------------------

	/**
	 * A challenge minted for the source form is accepted on its translation.
	 *
	 * The safety net for pages cached before the render fix, and for a visitor
	 * who switches language mid-session.
	 *
	 * @return void
	 */
	public function test_source_solution_is_accepted_on_the_translated_form() {
		$this->site( 'en' );

		$payload = Solver::payload_for_form( $this->form( 'contact', 'de' ) );

		$outcome = $this->submit( $this->submission_post( $payload, $this->form( 'contact', 'en' ) ) );

		$this->assertSame( 'accepted', $outcome['result'] );
	}

	/**
	 * And the other way round.
	 *
	 * @return void
	 */
	public function test_translated_solution_is_accepted_on_the_source_form() {
		$this->site( 'de' );

		$payload = Solver::payload_for_form( $this->form( 'contact', 'en' ) );

		$outcome = $this->submit( $this->submission_post( $payload, $this->form( 'contact', 'de' ) ) );

		$this->assertSame( 'accepted', $outcome['result'] );
	}

	/**
	 * Every family, both directions.
	 *
	 * @return void
	 */
	public function test_all_three_forms_accept_across_their_own_translation() {
		$this->site( 'en' );

		foreach ( array_keys( self::FAMILIES ) as $family ) {
			$payload = Solver::payload_for_form( $this->form( $family, 'de' ) );

			$this->assertSame(
				'accepted',
				$this->submit( $this->submission_post( $payload, $this->form( $family, 'en' ) ) )['result'],
				"Family {$family} must accept its own translation."
			);
		}
	}

	// -------------------------------------------------------------------------
	// Verification side — different forms stay different
	// -------------------------------------------------------------------------

	/**
	 * The guarantee most at risk from this fix: making translations equivalent
	 * must not make *different* forms equivalent.
	 *
	 * @return void
	 */
	public function test_solution_for_another_translated_form_is_rejected() {
		$this->site( 'en' );

		$payload = Solver::payload_for_form( $this->form( 'contact', 'en' ) );

		$outcome = $this->submit( $this->submission_post( $payload, $this->form( 'quote', 'en' ) ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
	}

	/**
	 * Source form A against translated form B is still a mismatch — the case
	 * that would slip through a naive "any translation matches" rule.
	 *
	 * @return void
	 */
	public function test_source_of_one_form_is_rejected_on_the_translation_of_another() {
		$this->site( 'en' );

		$payload = Solver::payload_for_form( $this->form( 'contact', 'de' ) );

		$outcome = $this->submit( $this->submission_post( $payload, $this->form( 'quote', 'en' ) ) );

		$this->assertSame( 'rejected_at_checkpoint_a', $outcome['result'] );
	}

	/**
	 * Every cross-family pair, both languages, is rejected. Six posts means
	 * thirty ordered pairs; the fifteen within-family ones are legal and the
	 * rest are not.
	 *
	 * @return void
	 */
	public function test_no_cross_family_pair_is_ever_accepted() {
		$this->site( 'en' );

		foreach ( self::FAMILIES as $name => $family ) {
			foreach ( self::FAMILIES as $other_name => $other ) {
				if ( $name === $other_name ) {
					continue;
				}

				foreach ( array( 'de', 'en' ) as $from ) {
					foreach ( array( 'de', 'en' ) as $to ) {
						$this->assertFalse(
							Translation::same_form( $family[ $from ], $other[ $to ] ),
							"{$name}/{$from} must not match {$other_name}/{$to}."
						);
					}
				}
			}
		}
	}

	/**
	 * And every within-family pair is accepted.
	 *
	 * @return void
	 */
	public function test_every_within_family_pair_is_accepted() {
		$this->site( 'en' );

		foreach ( self::FAMILIES as $name => $family ) {
			foreach ( array( 'de', 'en' ) as $from ) {
				foreach ( array( 'de', 'en' ) as $to ) {
					$this->assertTrue(
						Translation::same_form( $family[ $from ], $family[ $to ] ),
						"{$name}/{$from} must match {$name}/{$to}."
					);
				}
			}
		}
	}

	/**
	 * A form outside every family matches only itself.
	 *
	 * @return void
	 */
	public function test_a_form_outside_every_family_matches_only_itself() {
		$this->site( 'en' );

		$this->assertTrue( Translation::same_form( self::FOREIGN_FORM, self::FOREIGN_FORM ) );

		foreach ( self::FAMILIES as $family ) {
			$this->assertFalse( Translation::same_form( self::FOREIGN_FORM, $family['en'] ) );
			$this->assertFalse( Translation::same_form( $family['de'], self::FOREIGN_FORM ) );
		}
	}

	// -------------------------------------------------------------------------
	// Language switch mid-session
	// -------------------------------------------------------------------------

	/**
	 * A visitor loads the German page, starts the contact form, switches to
	 * English and submits there. The challenge was minted for the German post
	 * and the submission carries the English one — which must be accepted,
	 * while the other two forms on that page stay distinct.
	 *
	 * @return void
	 */
	public function test_language_switch_mid_session_on_a_multi_form_page() {
		$this->site( 'de' );
		$german = $this->render_page( $this->page_in( 'de' ) );
		$this->assertSame( 2066, $this->bound_form_id( $german[0] ) );

		$payload = Solver::payload_for_form( 2066 );

		// The visitor switches language; the English page is what submits.
		$this->site( 'en' );
		$english = $this->render_page( $this->page_in( 'en' ) );
		$this->assertSame( 2095, $this->bound_form_id( $english[0] ) );

		$this->assertSame(
			'accepted',
			$this->submit( $this->submission_post( $payload, 2095 ) )['result'],
			'The challenge from the German page must still be good on the English one.'
		);

		// But it is still only good for that form.
		$payload = Solver::payload_for_form( 2066 );

		$this->assertSame(
			'rejected_at_checkpoint_a',
			$this->submit( $this->submission_post( $payload, 2099 ) )['result'],
			'It must not become good for the quote form.'
		);
	}

	// -------------------------------------------------------------------------
	// Degradation
	// -------------------------------------------------------------------------

	/**
	 * With no translation plugin, behaviour is exactly what it was.
	 *
	 * @return void
	 */
	public function test_without_a_translation_plugin_behaviour_is_unchanged() {
		$this->assertFalse( Translation::is_active() );

		$payload = Solver::payload_for_form( 2066 );

		$this->assertSame(
			'accepted',
			$this->submit( $this->submission_post( $payload, 2066 ) )['result'],
			'Same form still accepted.'
		);

		$payload = Solver::payload_for_form( 2066 );

		$this->assertSame(
			'rejected_at_checkpoint_a',
			$this->submit( $this->submission_post( $payload, 2095 ) )['result'],
			'Different form still rejected — translations mean nothing here.'
		);
		$this->assertSame( '', Status::get_code(), 'And nothing is reported.' );
	}

	/**
	 * Rendering is untouched without a translation plugin.
	 *
	 * @return void
	 */
	public function test_rendering_without_a_translation_plugin_is_unchanged() {
		$rendered = $this->render_page( array( 2066, 2070, 2074 ) );

		$this->assertSame( array( 2066, 2070, 2074 ), array_map( array( $this, 'bound_form_id' ), $rendered ) );
	}

	/**
	 * If the plugin is active but will not resolve the mapping, accept rather
	 * than reject — and say so, so it is visible instead of silent.
	 *
	 * @return void
	 */
	public function test_unresolvable_mapping_accepts_and_reports() {
		add_filter(
			'kwfa_translation_provider',
			function () {
				return 'wpml';
			}
		);
		add_filter(
			'wpml_default_language',
			function () {
				return null;
			}
		);
		$this->reset_translation_memo();

		$payload = Solver::payload_for_form( 2066 );

		$outcome = $this->submit( $this->submission_post( $payload, 2095 ) );

		$this->assertSame( 'accepted', $outcome['result'], 'A dead form is worse than a spam window.' );
		$this->assertSame( 'translation_unresolved', Status::get_code() );
	}

	/**
	 * A degradation reported during a request that then succeeds must survive
	 * that request — otherwise the notice is wiped by the very submission that
	 * raised it, and the site owner never sees it.
	 *
	 * @return void
	 */
	public function test_a_degraded_but_successful_request_keeps_its_report() {
		add_filter(
			'kwfa_translation_provider',
			function () {
				return 'wpml';
			}
		);
		add_filter(
			'wpml_default_language',
			function () {
				return null;
			}
		);
		$this->reset_translation_memo();

		$payload = Solver::payload_for_form( 2066 );
		$this->submit( $this->submission_post( $payload, 2095 ) );

		$this->assertSame( 'translation_unresolved', Status::get_code() );
		$this->assertSame( 'translation_unresolved', get_option( 'kwfa_status' ), 'And it is persisted.' );
	}

	/**
	 * The admin notice for that state names the cause.
	 *
	 * @return void
	 */
	public function test_unresolved_mapping_notice_is_actionable() {
		Status::report( 'translation_unresolved' );
		WP_Stub_State::$user_can = true;

		Status::render_notice();

		$this->assertStringContainsString( 'translation plugin', WP_Stub_State::$admin_notices[0] );
	}

	// -------------------------------------------------------------------------
	// Polylang
	// -------------------------------------------------------------------------

	/**
	 * Polylang goes through the same seam.
	 *
	 * Its functions cannot be defined and redefined per test, so the provider is
	 * forced and the behaviour is exercised through Translation directly.
	 *
	 * @return void
	 */
	public function test_polylang_is_detected_through_the_same_seam() {
		add_filter(
			'kwfa_translation_provider',
			function () {
				return 'polylang';
			}
		);
		$this->reset_translation_memo();

		$this->assertTrue( Translation::is_active() );
		$this->assertSame( 'polylang', Translation::provider() );
	}

	/**
	 * Without Polylang's functions defined the mapping is unresolvable, so it
	 * must accept rather than reject — and must not fatal reaching for them.
	 *
	 * @return void
	 */
	public function test_polylang_without_its_functions_fails_open() {
		add_filter(
			'kwfa_translation_provider',
			function () {
				return 'polylang';
			}
		);
		$this->reset_translation_memo();

		$this->assertSame( 0, Translation::source_id( 2066 ) );
		$this->assertSame( 2066, Translation::current_id( 2066 ), 'And rendering still gets a usable ID.' );
		$this->assertTrue( Translation::same_form( 2066, 2095 ) );
		$this->assertSame( 'translation_unresolved', Status::get_code() );
	}
}
