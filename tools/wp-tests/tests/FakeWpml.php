<?php
/**
 * A WPML stand-in, parameterised by translation family.
 *
 * Mirrors the two seams the plugin uses:
 *
 *   apply_filters( 'wpml_object_id', $id, $post_type, $original_if_missing, $lang )
 *       maps a post into a language, handing the input back unchanged when
 *       there is no translation and $original_if_missing is true.
 *   apply_filters( 'wpml_default_language', null )
 *       names the source language.
 *
 * Polylang exposes the same two ideas as pll_get_post() / pll_default_language().
 * Its functions cannot be defined and redefined per test, so Polylang is covered
 * through Translation's own seam rather than here.
 *
 * @package Kreiswolke\FormAntispam\Tests
 */

namespace Kreiswolke\FormAntispam\Tests;

use Kreiswolke\FormAntispam\Translation;
use WP_Stub_State;

/**
 * Builds a multilingual site out of translation families.
 */
final class FakeWpml {

	/**
	 * Install the stubs.
	 *
	 * @param array  $families         name => array( language => post ID ).
	 * @param string $current_language Language the page is served in.
	 * @param string $default_language Source language.
	 * @return void
	 */
	public static function install( array $families, $current_language, $default_language = 'de' ) {
		$index = self::index( $families );

		add_filter(
			'kwfa_translation_provider',
			function () {
				return 'wpml';
			}
		);

		add_filter(
			'wpml_default_language',
			function () use ( $default_language ) {
				return $default_language;
			}
		);

		add_filter(
			'wpml_object_id',
			function ( $id, $post_type, $original_if_missing = true, $language = null ) use ( $index, $current_language ) {
				if ( Translation::POST_TYPE !== $post_type ) {
					return $id;
				}

				$id = (int) $id;

				if ( ! isset( $index[ $id ] ) ) {
					// Not in any translation family: WPML hands it straight back.
					return $original_if_missing ? $id : null;
				}

				$family = $index[ $id ];
				$target = $language ? $language : $current_language;

				if ( isset( $family[ $target ] ) ) {
					return $family[ $target ];
				}

				return $original_if_missing ? $id : null;
			},
			10,
			4
		);
	}

	/**
	 * Remove the stubs.
	 *
	 * @return void
	 */
	public static function remove() {
		unset(
			WP_Stub_State::$hooks['kwfa_translation_provider'],
			WP_Stub_State::$hooks['wpml_object_id'],
			WP_Stub_State::$hooks['wpml_default_language']
		);
	}

	/**
	 * post ID => its whole family, for O(1) lookup.
	 *
	 * @param array $families name => array( language => post ID ).
	 * @return array
	 */
	private static function index( array $families ) {
		$index = array();

		foreach ( $families as $family ) {
			foreach ( $family as $post_id ) {
				$index[ (int) $post_id ] = $family;
			}
		}

		return $index;
	}
}
