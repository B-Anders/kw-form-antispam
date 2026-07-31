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
	 * Every Kadence Advanced Form on the page.
	 */
	function forms() {
		return document.querySelectorAll( 'form.kb-advanced-form' );
	}

	/**
	 * The payload the widget is currently carrying, if any.
	 */
	function payloadOf( widget ) {
		if ( ! widget ) {
			return '';
		}

		var name = widget.getAttribute( 'name' ) || 'kwfa_altcha';
		var field = widget.querySelector( 'input[name="' + name + '"]' ) || widget.querySelector( 'input[type="hidden"]' );

		return field ? field.value : '';
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

		// Bubble, so it only runs for submissions that were actually let out.
		form.addEventListener( 'submit', onSubmitted );
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
	 * Forms whose `_kb_adv_form_id` matches the one the success event carried.
	 *
	 * Kadence builds the event detail from that hidden input:
	 *
	 *   uniqueId: b.querySelector('input[name="_kb_adv_form_id"]')
	 *               ? b.querySelector('input[name="_kb_adv_form_id"]').value : ""
	 *
	 * and the input's value is `{form CPT post id}-cpt-id`. So it identifies the
	 * form *post*, not the rendered instance: the same form embedded twice on a
	 * page produces two identical values. Hence the caller's fallback.
	 */
	function formsWithId( uniqueId ) {
		var all = forms();
		var matched = [];
		var index;

		for ( index = 0; index < all.length; index++ ) {
			var field = all[ index ].querySelector( 'input[name="_kb_adv_form_id"]' );

			if ( field && field.value === uniqueId && widgetIn( all[ index ] ) ) {
				matched.push( all[ index ] );
			}
		}

		return matched;
	}

	/**
	 * Forms still holding a payload we watched them submit.
	 *
	 * A solution is single-use, so once it has been submitted the widget holding
	 * it is spent and must be re-armed. A form nobody touched is not.
	 */
	function spentForms() {
		var all = forms();
		var spent = [];
		var index;

		for ( index = 0; index < all.length; index++ ) {
			var form = all[ index ];
			var widget = widgetIn( form );

			if ( ! widget || ! form.kwfaSubmittedPayload ) {
				continue;
			}

			if ( payloadOf( widget ) === form.kwfaSubmittedPayload ) {
				spent.push( form );
			}
		}

		return spent;
	}

	/**
	 * Which forms a success event should re-arm.
	 */
	function targetsForSuccess( detail ) {
		var uniqueId = ( detail && detail.uniqueId ) ? String( detail.uniqueId ) : '';
		var byId = uniqueId ? formsWithId( uniqueId ) : [];

		if ( 1 === byId.length ) {
			return byId;
		}

		var spent = spentForms();

		if ( byId.length > 1 ) {
			// The same form post rendered more than once. Narrow by what we
			// actually watched go out; if that tells us nothing, re-arm the
			// ambiguous set rather than every form on the page.
			var both = [];
			var index;

			for ( index = 0; index < byId.length; index++ ) {
				if ( -1 !== spent.indexOf( byId[ index ] ) ) {
					both.push( byId[ index ] );
				}
			}

			return both.length ? both : byId;
		}

		return spent;
	}

	/**
	 * Record what a form sent, so we know later whether it is spent.
	 *
	 * Bubble phase on purpose: if the capture handler held the submission back
	 * with stopImmediatePropagation() this never runs, which is exactly right —
	 * nothing was sent.
	 */
	function onSubmitted( event ) {
		var form = event.currentTarget;

		form.kwfaSubmittedPayload = payloadOf( widgetIn( form ) );
	}

	/**
	 * Kadence dispatches this on document.body after a successful submission,
	 * synchronously *before* it calls clearForm() — which is only form.reset().
	 *
	 * A solution is single-use, so without a re-arm the second submission from
	 * the same page would post a spent payload and be rejected. But only the
	 * form that actually submitted may be re-armed: re-arming every widget on
	 * the page would fire one challenge request per form, and on a page with
	 * several forms the surplus requests run into our own rate limiter. The
	 * widgets that lose then sit in an error state, and a visitor submitting
	 * one of those forms — which they never touched during the first
	 * submission — is turned away for having no solution.
	 *
	 * The work is deferred by a tick so it happens after Kadence's form.reset(),
	 * which the widget also listens to and which would otherwise abort a fresh
	 * run.
	 */
	function onKadenceSuccess( event ) {
		var targets = targetsForSuccess( event ? event.detail : null );

		window.setTimeout( function () {
			var index;

			for ( index = 0; index < targets.length; index++ ) {
				var form = targets[ index ];
				var widget = widgetIn( form );

				form.kwfaSubmittedPayload = null;

				if ( widget ) {
					rearm( widget );
					arm( widget );
				}
			}
		}, 0 );
	}

	/**
	 * Re-scan when forms arrive after page load.
	 *
	 * Kadence Pro's Query block replaces whole result regions with fetched
	 * markup (`replaceHtml()` in its query.js assigns `innerHTML`), so a form
	 * inside one is a different element from the one we bound at load. Without
	 * this it would get no `required` observer and no submit deferral: on an
	 * invisible widget that means a submit button that silently does nothing.
	 *
	 * scan() is idempotent — every element it touches is marked — so the only
	 * thing to be careful about is cost. These pages are ordinary pages, and
	 * this observer sees every DOM mutation on them, so it filters to added
	 * element nodes first and then debounces.
	 */
	var watching = false;

	function watchForNewForms() {
		// boot() runs on both DOMContentLoaded and load; observe once.
		if ( watching || ! window.MutationObserver ) {
			return;
		}

		var root = document.documentElement || document.body;

		if ( ! root ) {
			return;
		}

		watching = true;

		var scheduled = false;

		var observer = new window.MutationObserver( function ( records ) {
			if ( scheduled ) {
				return;
			}

			var index;
			var child;

			for ( index = 0; index < records.length; index++ ) {
				var added = records[ index ].addedNodes;

				for ( child = 0; child < added.length; child++ ) {
					if ( 1 === added[ child ].nodeType ) {
						scheduled = true;
						window.setTimeout( function () {
							scheduled = false;
							scan();
						}, 100 );

						return;
					}
				}
			}
		} );

		observer.observe( root, { childList: true, subtree: true } );
	}

	function boot() {
		registerStrings();
		scan();
		watchForNewForms();
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
