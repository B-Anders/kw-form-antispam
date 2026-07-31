/**
 * KW Form Antispam — glue between the ALTCHA widget and Kadence Advanced Forms.
 *
 * Three jobs:
 *
 * 1. Register WordPress-translated UI strings in the widget's global i18n
 *    registry, so the plugin can ship the base widget bundle instead of the
 *    much larger build that carries 73 locales.
 * 2. Re-arm after a successful submission. Kadence's clearForm() is only
 *    form.reset(); without a re-arm a second submission from the same page
 *    would post an already-spent solution and be rejected.
 * 3. Never let the form go dark. If the widget cannot verify (endpoint
 *    unreachable, no HTTPS), its required checkbox is relaxed so the visitor
 *    can still submit. The server decides what happens next — and the server
 *    fails open when the failure is ours.
 *
 * @package Kreiswolke\FormAntispam
 */

(function () {
	'use strict';

	var data = window.kwfaWidgetData || {};
	var SELECTOR = 'altcha-widget.kwfa-widget';
	var MAX_AUTO_RETRIES = 3;

	/**
	 * The widget's global registry, once the bundle has loaded.
	 */
	function altchaGlobal() {
		return window.$altcha || null;
	}

	/**
	 * Merge our translated strings over the widget's built-in English set.
	 */
	function registerStrings() {
		var global = altchaGlobal();

		if ( ! global || ! global.i18n || typeof global.i18n.set !== 'function' ) {
			return;
		}

		var language = data.language || 'en';
		var strings = data.strings || {};
		var merged = {};
		var base = {};
		var key;

		try {
			base = ( typeof global.i18n.get === 'function' && global.i18n.get( 'en' ) ) || {};
		} catch ( err ) {
			base = {};
		}

		for ( key in base ) {
			if ( Object.prototype.hasOwnProperty.call( base, key ) ) {
				merged[ key ] = base[ key ];
			}
		}

		for ( key in strings ) {
			if ( Object.prototype.hasOwnProperty.call( strings, key ) ) {
				merged[ key ] = strings[ key ];
			}
		}

		global.i18n.set( language, merged );
	}

	/**
	 * All of our widgets, optionally scoped to one element.
	 */
	function widgets( root ) {
		return ( root || document ).querySelectorAll( SELECTOR );
	}

	/**
	 * Start solving if the widget is idle.
	 *
	 * verify() is asynchronous and does not move the widget out of the
	 * "unverified" state synchronously, so a plain state check is not enough to
	 * stop a pointerdown and the widget's own focusin handler from both firing
	 * a run in the same tick. The extra latch closes that window.
	 */
	function arm( widget ) {
		if ( ! widget || typeof widget.getState !== 'function' || typeof widget.verify !== 'function' ) {
			return;
		}

		if ( widget.kwfaArming || 'unverified' !== widget.getState() ) {
			return;
		}

		widget.kwfaArming = true;

		var release = function () {
			widget.kwfaArming = false;
		};

		try {
			var result = widget.verify();

			if ( result && typeof result.then === 'function' ) {
				result.then( release, release );
			} else {
				release();
			}
		} catch ( err ) {
			release();
		}
	}

	/**
	 * Drop the widget's required flag so a failed verification cannot make the
	 * form unsubmittable. This costs nothing: the checkbox is a convenience for
	 * humans, never the security boundary. The boundary is server-side.
	 */
	function relaxRequired( widget ) {
		var checkbox = widget.querySelector( 'input[type="checkbox"]' );

		if ( ! checkbox ) {
			return;
		}

		checkbox.required = false;

		if ( typeof checkbox.setCustomValidity === 'function' ) {
			checkbox.setCustomValidity( '' );
		}
	}

	/**
	 * Reset a widget so it will fetch a fresh challenge next time.
	 */
	function rearm( widget ) {
		if ( widget && typeof widget.reset === 'function' ) {
			widget.kwfaArming = false;
			widget.reset();
		}
	}

	/**
	 * Kick verification off on the first real interaction with the form.
	 *
	 * The widget's own auto="onfocus" covers most cases, but focus behaviour on
	 * buttons differs between browsers, so a pointer or key event is the more
	 * dependable trigger.
	 */
	function bindForm( form ) {
		if ( ! form || '1' === form.getAttribute( 'data-kwfa-bound' ) ) {
			return;
		}

		form.setAttribute( 'data-kwfa-bound', '1' );

		var start = function () {
			var found = widgets( form );
			var index;

			for ( index = 0; index < found.length; index++ ) {
				arm( found[ index ] );
			}
		};

		form.addEventListener( 'pointerdown', start, true );
		form.addEventListener( 'keydown', start, true );
	}

	/**
	 * Attach our listeners to one widget.
	 */
	function bindWidget( widget ) {
		if ( ! widget || '1' === widget.getAttribute( 'data-kwfa-bound' ) ) {
			return;
		}

		widget.setAttribute( 'data-kwfa-bound', '1' );

		var retries = 0;

		widget.addEventListener( 'statechange', function ( event ) {
			var state = event && event.detail ? event.detail.state : '';

			if ( 'error' === state ) {
				relaxRequired( widget );
			}
		} );

		widget.addEventListener( 'expired', function () {
			if ( retries >= MAX_AUTO_RETRIES ) {
				relaxRequired( widget );
				return;
			}

			retries++;

			if ( typeof widget.verify === 'function' ) {
				widget.verify();
			}
		} );

		if ( typeof widget.closest === 'function' ) {
			bindForm( widget.closest( 'form' ) );
		}
	}

	/**
	 * Bind every widget currently in the document.
	 */
	function scan() {
		var found = widgets();
		var index;

		for ( index = 0; index < found.length; index++ ) {
			bindWidget( found[ index ] );
		}
	}

	/**
	 * Kadence dispatches this on document.body after a successful submission,
	 * synchronously *before* it calls clearForm() — which is only form.reset().
	 *
	 * A solution is single-use, so without a re-arm the second submission from
	 * the same page would post a spent payload and be rejected. The work is
	 * deferred by a tick so it happens after Kadence's form.reset(), which the
	 * widget also listens to and which would otherwise abort a fresh run.
	 */
	function onKadenceSuccess() {
		window.setTimeout( function () {
			var found = widgets();
			var index;

			for ( index = 0; index < found.length; index++ ) {
				rearm( found[ index ] );
				arm( found[ index ] );
			}
		}, 0 );
	}

	function boot() {
		registerStrings();
		scan();
	}

	if ( document.body ) {
		document.body.addEventListener( 'kb-advanced-form-success', onKadenceSuccess );
	} else {
		document.addEventListener( 'DOMContentLoaded', function () {
			document.body.addEventListener( 'kb-advanced-form-success', onKadenceSuccess );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}

	// Custom elements upgrade asynchronously in some browsers; a second pass
	// after load catches anything that was not in the DOM yet.
	window.addEventListener( 'load', boot );
})();
