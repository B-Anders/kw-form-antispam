/**
 * KW Form Antispam — glue between the ALTCHA widget and Kadence Advanced Forms.
 *
 * Four jobs:
 *
 * 1. Register WordPress-translated UI strings in the widget's global i18n
 *    registry, so the plugin can ship the base widget bundle instead of the
 *    much larger build that carries 73 locales.
 *
 * 2. Start the proof-of-work on the visitor's first interaction with the form,
 *    so it is normally finished long before they press submit.
 *
 * 3. Hold a submission that arrives before verification finished, and send it
 *    automatically once it does. The visitor already asked for the submission;
 *    making them ask twice is our problem to solve, not theirs.
 *
 * 4. Never let the form go dark. The widget's own `required` checkbox is
 *    disarmed, every wait is bounded, and every exit path releases the
 *    submission rather than swallowing it. The security boundary is entirely
 *    server-side, so nothing here needs to block anybody.
 *
 * @package Kreiswolke\FormAntispam
 */

(function () {
	'use strict';

	var data = window.kwfaWidgetData || {};

	var SELECTOR = 'altcha-widget.kwfa-widget';
	var MAX_EXPIRY_RETRIES = 3;

	/**
	 * Coerce a localized value to a sane non-negative integer.
	 */
	function positiveInt( value, fallback ) {
		var number = parseInt( value, 10 );

		return ( isNaN( number ) || number < 0 ) ? fallback : number;
	}

	var WAIT_TIMEOUT = positiveInt( data.waitTimeout, 15000 );
	var NOTICE_DELAY = positiveInt( data.noticeDelay, 750 );

	/**
	 * The widget's global registry, once the bundle has loaded.
	 */
	function altchaGlobal() {
		return window.$altcha || null;
	}

	/**
	 * Merge our translated strings over the widget's built-in English set.
	 *
	 * The widget has no `strings` attribute; translations live in a global
	 * registry. A partial map would render missing keys as `undefined`, because
	 * the widget does not fall back per key — hence the merge.
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

	// -------------------------------------------------------------------------
	// Widget helpers
	// -------------------------------------------------------------------------

	/**
	 * All of our widgets, optionally scoped to one element.
	 */
	function widgets( root ) {
		return ( root || document ).querySelectorAll( SELECTOR );
	}

	/**
	 * The widget belonging to a form, if any.
	 */
	function widgetIn( form ) {
		return form ? form.querySelector( SELECTOR ) : null;
	}

	/**
	 * Current widget state: unverified | verifying | verified | error | expired.
	 */
	function stateOf( widget ) {
		if ( ! widget || typeof widget.getState !== 'function' ) {
			return '';
		}

		try {
			return widget.getState();
		} catch ( err ) {
			return '';
		}
	}

	/**
	 * Would waiting for this widget plausibly produce a payload?
	 */
	function isPending( state ) {
		return 'unverified' === state || 'verifying' === state;
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
		if ( ! widget || typeof widget.verify !== 'function' ) {
			return;
		}

		if ( widget.kwfaArming || 'unverified' !== stateOf( widget ) ) {
			return;
		}

		widget.kwfaArming = true;

		var settle = function () {
			widget.kwfaArming = false;
		};

		try {
			var result = widget.verify();

			if ( result && typeof result.then === 'function' ) {
				result.then( settle, settle );
			} else {
				settle();
			}
		} catch ( err ) {
			settle();
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
	 * Permanently strip the widget's `required` flag from its checkbox.
	 *
	 * The widget sets `required` whenever it is verifying, and additionally for
	 * the whole lifetime of the visible mode (Widget.svelte:1387-1388):
	 *
	 *   required = (display === 'standard' && auto !== 'onsubmit')
	 *              || state === VERIFYING
	 *
	 * In the invisible mode that is a trap rather than a guard. The checkbox
	 * sits inside a `display: none` container (widget.scss:728), so a submit
	 * during the solve fails constraint validation on a control the browser can
	 * neither focus nor report on: Chrome refuses the submission, logs to the
	 * console, and — critically — never fires the `submit` event, which would
	 * take this script's deferral down with it.
	 *
	 * In the visible mode it is merely redundant, because the deferral below
	 * handles an early submit far better than a browser bubble does.
	 *
	 * Svelte re-applies the attribute whenever the expression changes value, so
	 * a one-shot removal is not enough; the observer keeps it off. Removing it
	 * costs nothing: this checkbox is a convenience for humans and was never
	 * the security boundary.
	 */
	function disarmRequired( widget ) {
		var strip = function () {
			var checkbox = widget.querySelector( 'input[type="checkbox"]' );

			if ( ! checkbox ) {
				return;
			}

			if ( checkbox.required ) {
				checkbox.required = false;
			}

			if ( typeof checkbox.setCustomValidity === 'function' ) {
				checkbox.setCustomValidity( '' );
			}
		};

		strip();

		if ( ! window.MutationObserver ) {
			return;
		}

		var observer = new window.MutationObserver( strip );

		observer.observe( widget, {
			attributes: true,
			attributeFilter: [ 'required' ],
			childList: true,
			subtree: true
		} );
	}

	// -------------------------------------------------------------------------
	// Deferred submission
	// -------------------------------------------------------------------------

	/**
	 * Show the "hold on" message, once per form.
	 *
	 * Built with textContent, so a translated string can never inject markup.
	 */
	function showNotice( form ) {
		if ( form.kwfaNotice ) {
			return;
		}

		var text = ( data.ui && data.ui.waiting ) ? data.ui.waiting : '';

		if ( ! text ) {
			return;
		}

		var notice = document.createElement( 'div' );

		notice.className = 'kwfa-wait-notice';
		notice.setAttribute( 'role', 'status' );
		notice.setAttribute( 'aria-live', 'polite' );
		notice.style.marginTop = '0.5em';
		notice.textContent = text;

		var anchor = form.querySelector( '.kb-submit-field' );

		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( notice, anchor.nextSibling );
		} else {
			form.appendChild( notice );
		}

		form.kwfaNotice = notice;
	}

	/**
	 * Remove the "hold on" message.
	 */
	function hideNotice( form ) {
		if ( form.kwfaNotice && form.kwfaNotice.parentNode ) {
			form.kwfaNotice.parentNode.removeChild( form.kwfaNotice );
		}

		form.kwfaNotice = null;
	}

	/**
	 * Re-submit the form, letting Kadence handle it this time.
	 */
	function release( form, submitter ) {
		// Read by our own submit handler on the very next, synchronous, submit
		// event so it lets this one straight through. Cleared on the next tick
		// in case constraint validation on some *other* field means the event
		// never arrives at all.
		form.kwfaReleasing = true;
		window.setTimeout( function () {
			form.kwfaReleasing = false;
		}, 0 );

		if ( typeof form.requestSubmit === 'function' ) {
			try {
				if ( submitter && submitter.form === form ) {
					form.requestSubmit( submitter );
				} else {
					form.requestSubmit();
				}

				return;
			} catch ( err ) {
				// Fall through to the click fallback.
			}
		}

		var button = ( submitter && submitter.form === form )
			? submitter
			: form.querySelector( 'button[type="submit"], input[type="submit"], .kb-adv-form-submit-button' );

		if ( button && typeof button.click === 'function' ) {
			button.click();
		}
	}

	/**
	 * Hold a submission until the widget resolves, then send it.
	 *
	 * Every exit path releases the submission. On success the payload is in the
	 * hidden field and the server accepts it; on error or timeout it is not, and
	 * the server decides. That is the fail-open trade the plugin makes
	 * everywhere: a spam window is recoverable, a trapped visitor is not.
	 */
	function hold( form, widget, submitter ) {
		form.kwfaPending = true;

		var settled = false;

		var noticeTimer = window.setTimeout( function () {
			showNotice( form );
		}, NOTICE_DELAY );

		var timeoutTimer = window.setTimeout( function () {
			finish();
		}, WAIT_TIMEOUT );

		function finish() {
			if ( settled ) {
				return;
			}

			settled = true;

			window.clearTimeout( noticeTimer );
			window.clearTimeout( timeoutTimer );

			widget.removeEventListener( 'statechange', onStateChange );
			widget.removeEventListener( 'verified', onStateChange );

			hideNotice( form );
			form.kwfaPending = false;

			release( form, submitter );
		}

		function onStateChange() {
			if ( ! isPending( stateOf( widget ) ) ) {
				finish();
			}
		}

		widget.addEventListener( 'statechange', onStateChange );
		widget.addEventListener( 'verified', onStateChange );

		arm( widget );

		// It may have resolved between the submit handler's check and now.
		onStateChange();
	}

	/**
	 * Capture-phase submit handler, ahead of Kadence's own.
	 */
	function onSubmit( event ) {
		var form = event.currentTarget;

		// Our own re-submit: let it straight through.
		if ( form.kwfaReleasing ) {
			return;
		}

		// Already holding one. Swallow this so a second click cannot send twice.
		if ( form.kwfaPending ) {
			event.preventDefault();
			event.stopImmediatePropagation();
			return;
		}

		var widget = widgetIn( form );

		if ( ! widget ) {
			return;
		}

		// Verified, errored, expired, or a widget that never came up: there is
		// nothing to wait for. Let Kadence have it.
		if ( ! isPending( stateOf( widget ) ) ) {
			return;
		}

		event.preventDefault();
		event.stopImmediatePropagation();

		hold( form, widget, event.submitter || null );
	}

	// -------------------------------------------------------------------------
	// Binding
	// -------------------------------------------------------------------------

	/**
	 * Attach to a form: start the work early, and handle an early submit.
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

		// Capture, so this runs before Kadence's own submit listener and can
		// stop the request from starting at all.
		form.addEventListener( 'submit', onSubmit, true );
	}

	/**
	 * Attach our listeners to one widget.
	 */
	function bindWidget( widget ) {
		if ( ! widget || '1' === widget.getAttribute( 'data-kwfa-bound' ) ) {
			return;
		}

		widget.setAttribute( 'data-kwfa-bound', '1' );

		disarmRequired( widget );

		var retries = 0;

		widget.addEventListener( 'expired', function () {
			if ( retries >= MAX_EXPIRY_RETRIES ) {
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
