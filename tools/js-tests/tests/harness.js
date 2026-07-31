/**
 * Test harness: a jsdom page carrying one or more Kadence Advanced Forms and a
 * stand-in for the ALTCHA widget, with the real glue script loaded into it.
 *
 * The stand-in reproduces the parts of the widget's contract the glue depends
 * on, taken from research/altcha-widget-pinned/src/:
 *
 *   - getState() / verify() / reset()          Widget.svelte WidgetMethods
 *   - 'statechange' and 'verified' events,     Widget.svelte dispatch()
 *     delivered asynchronously (tick().then)
 *   - a hidden input named after `name`,       Widget.svelte:1420-1422
 *     carrying the payload
 *   - a checkbox whose `required` follows      Widget.svelte:1387-1388
 *       (display === 'standard' && auto !== 'onsubmit') || state === 'verifying'
 *   - display="invisible" meaning the root     widget.scss:728
 *     is display:none, nothing else changed
 *
 * The form markup and the success event mirror the licensed Kadence source in
 * docs/Kadence/kadence-blocks/ — in particular `_kb_adv_form_id` carries
 * `{form CPT post id}-cpt-id`, and the success event's `detail.uniqueId` is a
 * copy of that input's value.
 *
 * Like the Kadence pipeline model in the PHP suite, this is a model. It is
 * faithful to the source as read, not to a running browser.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM } = require( 'jsdom' );

const GLUE = path.join( __dirname, '..', '..', '..', 'plugin', 'assets', 'js', 'kwfa-widget.js' );
const GLUE_SOURCE = fs.readFileSync( GLUE, 'utf8' );

/**
 * Markup for one Kadence Advanced Form.
 *
 * @param {number|string} formId Form CPT post ID.
 */
function formHtml( formId ) {
	const uniqueId = `${ formId }-cpt-id`;

	return `
<form id="kb-adv-form-${ uniqueId }" class="kb-advanced-form" method="post" data-form="${ formId }">
	<div class="kb-adv-form-field">
		<label for="field-${ formId }">Message</label>
		<input id="field-${ formId }" name="field${ formId }" type="text">
	</div>
	<div class="kb-adv-form-field kb-submit-field">
		<button class="kb-adv-form-submit-button" type="submit">Send</button>
	</div>
	<input type="hidden" name="_kb_adv_form_post_id" value="${ formId }">
	<input type="hidden" name="action" value="kb_process_advanced_form_submit">
	<input type="hidden" name="_kb_adv_form_id" value="${ uniqueId }">
</form>`;
}

/**
 * Build a page.
 *
 * @param {object}   options
 * @param {string}   options.display   'invisible' (default) or 'standard'.
 * @param {string}   options.state     Initial widget state.
 * @param {object}   options.data      Overrides for window.kwfaWidgetData.
 * @param {boolean}  options.loadGlue  Load the glue script. Default true.
 * @param {boolean}  options.widget    Insert a widget at all. Default true.
 * @param {Array}    options.formIds   Form CPT ids to render. Default [42].
 */
async function setup( options ) {
	const opts = Object.assign(
		{
			display: 'invisible',
			state: 'unverified',
			data: {},
			loadGlue: true,
			widget: true,
			formIds: [ 42 ]
		},
		options || {}
	);

	const dom = new JSDOM( '<!doctype html><html><body></body></html>', {
		runScripts: 'dangerously',
		pretendToBeVisual: true,
		url: 'https://example.test/contact/'
	} );

	const window = dom.window;
	const document = window.document;

	const ctx = { dom, window, document, forms: [], byFormId: {} };

	opts.formIds.forEach( ( formId ) => {
		const entry = insertForm( ctx, formId, opts );
		ctx.forms.push( entry );
		ctx.byFormId[ formId ] = entry;
	} );

	// Convenience aliases for the single-form tests.
	const first = ctx.forms[ 0 ];
	if ( first ) {
		ctx.form = first.form;
		ctx.button = first.button;
		ctx.widget = first.widget;
		ctx.kadence = first.kadence;
	}

	window.kwfaWidgetData = Object.assign(
		{
			language: 'de',
			strings: { label: 'Ich bin kein Roboter' },
			waitTimeout: 300,
			noticeDelay: 50,
			ui: { waiting: 'Einen Moment …' }
		},
		opts.data
	);

	/**
	 * Add a form to the page after the fact, the way Kadence Pro's Query block
	 * does when it replaces a result region's innerHTML.
	 */
	ctx.injectForm = function ( formId, injectOptions ) {
		const entry = insertForm( ctx, formId, Object.assign( {}, opts, injectOptions || {} ) );

		ctx.forms.push( entry );
		ctx.byFormId[ formId ] = entry;

		return entry;
	};

	if ( opts.loadGlue ) {
		window.eval( GLUE_SOURCE );

		// The glue is a footer script, so at this point the document is still
		// parsing and it has deferred its own boot() to DOMContentLoaded —
		// exactly as it does on a real page. Wait for that before handing the
		// page to a test, or the test races the script it is testing.
		await ready( window );
	}

	return ctx;
}

/**
 * Render one form (plus its widget) into the page.
 */
function insertForm( ctx, formId, opts ) {
	const { window, document } = ctx;
	const host = document.createElement( 'div' );

	host.innerHTML = formHtml( formId );

	const form = host.querySelector( 'form' );
	document.body.appendChild( form );

	const button = form.querySelector( 'button[type="submit"]' );

	// Kadence's own submit handler: a bubble-phase listener on the form that
	// takes over the submission (kb-advanced-form-block.min.js initForms()).
	const kadence = { submissions: [], lastPayload: null };

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
		kadence.submissions.push( Date.now() );
		const field = form.querySelector( 'input[name="kwfa_altcha"]' );
		kadence.lastPayload = field ? field.value : null;
	} );

	let widget = null;

	if ( opts.widget ) {
		widget = createWidget( window, opts.display, opts.state );
		form.insertBefore( widget, form.querySelector( '.kb-submit-field' ) );

		// The real widget registers this in onMount (Widget.svelte:355, handler
		// at :796), so Kadence's clearForm() — which is only form.reset() —
		// resets it. Tests that model the full success sequence depend on it.
		form.addEventListener( 'reset', function () {
			widget.reset();
		} );
	}

	return { formId, form, button, widget, kadence };
}

/**
 * Dispatch the event Kadence fires after a successful submission.
 *
 * Verbatim shape from docs/Kadence/kadence-blocks/includes/assets/js/
 * kb-advanced-form-block.min.js: dispatched on document.body, with
 * detail.uniqueId copied from the form's `_kb_adv_form_id` input, or the empty
 * string when that input is missing.
 *
 * @param {object} ctx
 * @param {string|null} uniqueId Pass null for the empty-string case.
 */
function dispatchSuccess( ctx, uniqueId ) {
	ctx.document.body.dispatchEvent(
		new ctx.window.CustomEvent( 'kb-advanced-form-success', {
			detail: {
				uniqueId: null === uniqueId ? '' : uniqueId,
				submissionResults: { success: true }
			}
		} )
	);
}

/**
 * Build the widget stand-in.
 */
function createWidget( window, display, state ) {
	const document = window.document;
	const el = document.createElement( 'altcha-widget' );

	el.className = 'kwfa-widget';
	el.setAttribute( 'name', 'kwfa_altcha' );
	el.setAttribute( 'display', display );
	el.setAttribute( 'auto', 'onfocus' );

	const root = document.createElement( 'div' );
	root.className = 'altcha';
	root.setAttribute( 'data-display', display );
	if ( 'invisible' === display ) {
		root.style.display = 'none';
	}

	const main = document.createElement( 'div' );
	main.className = 'altcha-main';

	const checkbox = document.createElement( 'input' );
	checkbox.type = 'checkbox';

	const hidden = document.createElement( 'input' );
	hidden.type = 'hidden';
	hidden.name = 'kwfa_altcha';
	hidden.value = '';

	main.appendChild( checkbox );
	main.appendChild( hidden );
	root.appendChild( main );
	el.appendChild( root );

	el.kwfaVerifyCalls = 0;
	el.kwfaResetCalls = 0;

	let current = state;

	/**
	 * Mirror the widget's own `required` expression.
	 */
	function syncRequired() {
		const auto = el.getAttribute( 'auto' );
		checkbox.required =
			( 'standard' === el.getAttribute( 'display' ) && 'onsubmit' !== auto ) ||
			'verifying' === current;
	}

	/**
	 * The widget dispatches on the next tick, not synchronously.
	 */
	function dispatch( name, detail ) {
		Promise.resolve().then( function () {
			el.dispatchEvent( new window.CustomEvent( name, { detail: detail } ) );
		} );
	}

	el.getState = function () {
		return current;
	};

	el.setState = function ( next ) {
		current = next;
		syncRequired();
		root.setAttribute( 'data-state', next );

		if ( 'verified' === next ) {
			hidden.value = 'PAYLOAD-' + Math.random().toString( 16 ).slice( 2 );
			dispatch( 'verified', { payload: hidden.value } );
		}

		if ( 'expired' === next ) {
			dispatch( 'expired', {} );
		}

		dispatch( 'statechange', { state: next, payload: hidden.value || null } );
	};

	el.verify = function () {
		el.kwfaVerifyCalls++;
		el.setState( 'verifying' );

		return new Promise( function ( resolve ) {
			el.kwfaResolve = function ( next ) {
				el.setState( next || 'verified' );
				resolve( { payload: hidden.value } );
			};
		} );
	};

	el.reset = function () {
		el.kwfaResetCalls++;
		hidden.value = '';
		el.setState( 'unverified' );
	};

	el.kwfaPayload = function () {
		return hidden.value;
	};

	syncRequired();

	return el;
}

/**
 * Resolve once the page has finished loading and boot() has run.
 */
function ready( window ) {
	return new Promise( function ( resolve ) {
		if ( 'loading' === window.document.readyState ) {
			window.document.addEventListener( 'DOMContentLoaded', function () {
				setTimeout( resolve, 0 );
			}, { once: true } );
			return;
		}

		setTimeout( resolve, 0 );
	} );
}

/**
 * Let queued microtasks and timers run.
 */
function tick( ms ) {
	return new Promise( function ( resolve ) {
		setTimeout( resolve, ms || 0 );
	} );
}

/**
 * Click a form's submit button the way a visitor would.
 *
 * Accepts either the whole context (first form) or one form entry.
 */
function click( target ) {
	( target.button || target.forms[ 0 ].button ).click();
}

module.exports = { setup, tick, click, dispatchSuccess };
