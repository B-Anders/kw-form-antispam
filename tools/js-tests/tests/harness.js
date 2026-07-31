/**
 * Test harness: a jsdom page carrying a Kadence Advanced Form and a stand-in
 * for the ALTCHA widget, with the real glue script loaded into it.
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
 * Like the Kadence pipeline model in the PHP suite, this is a model. It is
 * faithful to the source as read, not to a running browser.
 */

'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const { JSDOM } = require( 'jsdom' );

const GLUE = path.join( __dirname, '..', '..', '..', 'plugin', 'assets', 'js', 'kwfa-widget.js' );

const FORM_HTML = `
<form id="kb-adv-form-42-cpt-id" class="kb-advanced-form" method="post">
	<div class="kb-adv-form-field">
		<label for="field1">Message</label>
		<input id="field1" name="field1" type="text">
	</div>
	<div class="kb-adv-form-field kb-submit-field">
		<button class="kb-adv-form-submit-button" type="submit">Send</button>
	</div>
	<input type="hidden" name="_kb_adv_form_post_id" value="42">
	<input type="hidden" name="action" value="kb_process_advanced_form_submit">
	<input type="hidden" name="_kb_adv_form_id" value="42-cpt-id">
</form>`;

/**
 * Build a page.
 *
 * @param {object} options
 * @param {string} options.display    'invisible' (default) or 'standard'.
 * @param {string} options.state      Initial widget state.
 * @param {object} options.data       Overrides for window.kwfaWidgetData.
 * @param {boolean} options.loadGlue  Load the glue script. Default true.
 * @param {boolean} options.widget    Insert a widget at all. Default true.
 */
async function setup( options ) {
	const opts = Object.assign(
		{
			display: 'invisible',
			state: 'unverified',
			data: {},
			loadGlue: true,
			widget: true
		},
		options || {}
	);

	const dom = new JSDOM( `<!doctype html><html><body>${ FORM_HTML }</body></html>`, {
		runScripts: 'dangerously',
		pretendToBeVisual: true,
		url: 'https://example.test/contact/'
	} );

	const window = dom.window;
	const document = window.document;
	const form = document.querySelector( 'form' );
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

	if ( opts.loadGlue ) {
		window.eval( fs.readFileSync( GLUE, 'utf8' ) );

		// The glue is a footer script, so at this point the document is still
		// parsing and it has deferred its own boot() to DOMContentLoaded —
		// exactly as it does on a real page. Wait for that before handing the
		// page to a test, or the test races the script it is testing.
		await ready( window );
	}

	return { dom, window, document, form, button, widget, kadence };
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

	syncRequired();

	return el;
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
 * Click the submit button the way a visitor would.
 */
function click( ctx ) {
	ctx.button.click();
}

module.exports = { setup, tick, click };
