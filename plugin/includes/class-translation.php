<?php
/**
 * Multilingual seam: WPML and Polylang.
 *
 * @package Kreiswolke\FormAntispam
 */

namespace Kreiswolke\FormAntispam;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a Kadence form post to its translation family.
 *
 * A translation plugin gives one logical form several post IDs — one per
 * language. Kadence renders and submits whichever ID the page's
 * `kadence/advanced-form` block points at, so on a translated page that is the
 * translated post. Anything of ours that compares form IDs has to understand
 * that two different IDs can be the same form.
 *
 * With no translation plugin active every method here is a no-op and the
 * behaviour is exactly what it was before this class existed.
 */
final class Translation {

	/**
	 * Kadence's form post type (`advanced-form-cpt.php:6`).
	 */
	const POST_TYPE = 'kadence_form';

	/**
	 * Per-request memo of the detected provider: '', 'wpml' or 'polylang'.
	 *
	 * @var string|null
	 */
	private static $provider = null;

	/**
	 * Which translation plugin, if any, is running.
	 *
	 * @return string '' when none.
	 */
	public static function provider() {
		if ( null !== self::$provider ) {
			return self::$provider;
		}

		self::$provider = '';

		if ( defined( 'ICL_SITEPRESS_VERSION' ) || has_filter( 'wpml_object_id' ) ) {
			self::$provider = 'wpml';
		} elseif ( function_exists( 'pll_get_post' ) && function_exists( 'pll_default_language' ) ) {
			self::$provider = 'polylang';
		}

		/**
		 * Filters the detected translation provider.
		 *
		 * Return '' to switch the multilingual handling off entirely, or a
		 * provider name to force one. Mainly a test seam.
		 *
		 * @param string $provider '', 'wpml' or 'polylang'.
		 */
		self::$provider = (string) apply_filters( 'kwfa_translation_provider', self::$provider );

		return self::$provider;
	}

	/**
	 * Is a translation plugin active?
	 *
	 * @return bool
	 */
	public static function is_active() {
		return '' !== self::provider();
	}

	/**
	 * The form's ID in the language currently being rendered.
	 *
	 * Used as a fallback at render time. The authoritative value is the parent
	 * block's `id` attribute, because that is literally what Kadence puts in
	 * `_kb_adv_form_post_id`; this only helps when that is unavailable.
	 *
	 * @param int $form_id Any post ID from the translation family.
	 * @return int The current-language ID, or the input unchanged.
	 */
	public static function current_id( $form_id ) {
		$form_id = absint( $form_id );

		if ( $form_id < 1 || ! self::is_active() ) {
			return $form_id;
		}

		if ( 'wpml' === self::provider() ) {
			$resolved = apply_filters( 'wpml_object_id', $form_id, self::POST_TYPE, true );
		} elseif ( function_exists( 'pll_get_post' ) ) {
			$resolved = pll_get_post( $form_id );
		} else {
			return $form_id;
		}

		$resolved = absint( $resolved );

		return $resolved > 0 ? $resolved : $form_id;
	}

	/**
	 * The form's ID in the site's default language.
	 *
	 * This is the canonical member of a translation family, so two IDs belong
	 * to the same form exactly when they resolve to the same one.
	 *
	 * @param int $form_id Any post ID from the translation family.
	 * @return int 0 when the mapping cannot be resolved.
	 */
	public static function source_id( $form_id ) {
		$form_id = absint( $form_id );

		if ( $form_id < 1 ) {
			return 0;
		}

		if ( ! self::is_active() ) {
			return $form_id;
		}

		if ( 'wpml' === self::provider() ) {
			$default = apply_filters( 'wpml_default_language', null );

			if ( ! is_string( $default ) || '' === $default ) {
				return 0;
			}

			$resolved = apply_filters( 'wpml_object_id', $form_id, self::POST_TYPE, true, $default );
		} else {
			// Never call into a provider that is not really there. A fatal on a
			// public request would be a far worse failure than the one this
			// class exists to prevent.
			if ( ! function_exists( 'pll_default_language' ) || ! function_exists( 'pll_get_post' ) ) {
				return 0;
			}

			$default = pll_default_language();

			if ( ! is_string( $default ) || '' === $default ) {
				return 0;
			}

			$resolved = pll_get_post( $form_id, $default );
		}

		return absint( $resolved );
	}

	/**
	 * Are these two IDs the same form?
	 *
	 * Identical IDs are the same form everywhere. Beyond that, two IDs match
	 * only if they are translations of one another. Two genuinely different
	 * forms never match, which is the point of the binding.
	 *
	 * @param int $challenge_form_id The form the challenge was minted for.
	 * @param int $submitted_form_id The form the submission came from.
	 * @return bool
	 */
	public static function same_form( $challenge_form_id, $submitted_form_id ) {
		$challenge_form_id = absint( $challenge_form_id );
		$submitted_form_id = absint( $submitted_form_id );

		if ( $challenge_form_id === $submitted_form_id ) {
			return true;
		}

		if ( ! self::is_active() ) {
			// Single-language site: different IDs are different forms, exactly
			// as before this class existed.
			return false;
		}

		$challenge_source = self::source_id( $challenge_form_id );
		$submitted_source = self::source_id( $submitted_form_id );

		if ( $challenge_source < 1 || $submitted_source < 1 ) {
			// A translation plugin is running but will not tell us how these
			// relate. Rejecting here would turn a mapping gap into a dead form,
			// so accept and make the gap visible instead.
			Status::report( 'translation_unresolved' );

			return true;
		}

		return $challenge_source === $submitted_source;
	}
}
