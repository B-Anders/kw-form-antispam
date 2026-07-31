/**
 * Forms that arrive after page load.
 *
 * Kadence Pro's Query block replaces whole result regions with fetched markup —
 * `replaceHtml()` in its dist/query.js assigns `innerHTML` — so a form rendered
 * inside one is a different element from anything present at DOMContentLoaded.
 * Without a re-scan it gets no `required` observer and no submit deferral, and
 * on an invisible widget that means a send button that silently does nothing.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { setup, tick, click, dispatchSuccess } = require( './harness' );

/**
 * The observer debounces; give it room.
 */
const SETTLE = 200;

test( 'a form injected after load gets wired up', async () => {
	const ctx = await setup( { formIds: [ 42 ] } );

	const late = ctx.injectForm( 77 );
	await tick( SETTLE );

	assert.strictEqual( late.form.getAttribute( 'data-kwfa-bound' ), '1', 'The form must be bound.' );
	assert.strictEqual( late.widget.getAttribute( 'data-kwfa-bound' ), '1', 'The widget must be bound.' );
} );

test( 'an injected form gets the submit deferral', async () => {
	const ctx = await setup( { formIds: [ 42 ] } );

	const late = ctx.injectForm( 77 );
	await tick( SETTLE );

	late.widget.verify();
	await tick();

	click( late );
	await tick( 20 );

	assert.strictEqual( late.kadence.submissions.length, 0, 'The submission must be held.' );

	late.widget.kwfaResolve( 'verified' );
	await tick( 20 );

	assert.strictEqual( late.kadence.submissions.length, 1, 'Then sent automatically.' );
	assert.match( late.kadence.lastPayload, /^PAYLOAD-/ );
} );

test( 'an injected form gets the required guard', async () => {
	const ctx = await setup( { formIds: [ 42 ], display: 'standard' } );

	const late = ctx.injectForm( 77, { display: 'standard' } );
	await tick( SETTLE );

	const checkbox = late.widget.querySelector( 'input[type="checkbox"]' );

	assert.strictEqual( checkbox.required, false, 'Stripped on binding.' );

	late.widget.setState( 'verifying' );
	await tick( 20 );

	assert.strictEqual( checkbox.required, false, 'And kept stripped.' );
} );

test( 'an injected invisible form can actually submit', async () => {
	const ctx = await setup( { formIds: [ 42 ] } );

	const late = ctx.injectForm( 77 );
	await tick( SETTLE );

	// The state that would otherwise eat the submit event entirely.
	late.widget.setState( 'verifying' );
	await tick( 20 );

	click( late );
	await tick( 20 );

	assert.strictEqual( late.kadence.submissions.length, 0, 'Held, which means we saw the event.' );

	late.widget.setState( 'verified' );
	await tick( 20 );

	assert.strictEqual( late.kadence.submissions.length, 1 );
} );

test( 'the first form keeps working after another is injected', async () => {
	const ctx = await setup( { formIds: [ 42 ] } );
	const first = ctx.byFormId[ 42 ];

	ctx.injectForm( 77 );
	await tick( SETTLE );

	first.widget.setState( 'verified' );
	await tick();

	click( first );

	assert.strictEqual( first.kadence.submissions.length, 1 );
} );

test( 're-scanning does not re-bind what is already bound', async () => {
	const ctx = await setup( { formIds: [ 42 ] } );
	const first = ctx.byFormId[ 42 ];

	first.widget.setState( 'verified' );
	await tick();

	// Churn the DOM so the observer fires repeatedly.
	for ( let i = 0; i < 5; i++ ) {
		ctx.document.body.appendChild( ctx.document.createElement( 'div' ) );
	}
	await tick( SETTLE );

	click( first );
	await tick( 20 );

	assert.strictEqual(
		first.kadence.submissions.length,
		1,
		'A double-bound form would hold or duplicate the submission.'
	);
} );

test( 'an injected form participates in scoped re-arm', async () => {
	const ctx = await setup( { formIds: [ 42 ] } );
	const first = ctx.byFormId[ 42 ];

	const late = ctx.injectForm( 77 );
	await tick( SETTLE );

	first.widget.setState( 'verified' );
	late.widget.setState( 'verified' );
	await tick();

	const latePayload = late.widget.kwfaPayload();

	click( first );
	await tick();

	dispatchSuccess( ctx, '42-cpt-id' );
	await tick( 20 );

	assert.strictEqual( first.widget.kwfaPayload(), '', 'Re-armed.' );
	assert.strictEqual( late.widget.kwfaPayload(), latePayload, 'Untouched.' );
} );

test( 'text-only mutations do not trigger a re-scan', async () => {
	const ctx = await setup( { formIds: [ 42 ] } );

	const marker = ctx.document.createElement( 'div' );
	ctx.document.body.appendChild( marker );
	await tick( SETTLE );

	// A text node is not an element node, so the cheap pre-filter should skip
	// it entirely rather than scheduling work on every unrelated mutation.
	let scanned = false;
	const original = ctx.document.querySelectorAll.bind( ctx.document );
	ctx.document.querySelectorAll = function ( selector ) {
		if ( 'altcha-widget.kwfa-widget' === selector ) {
			scanned = true;
		}
		return original( selector );
	};

	marker.appendChild( ctx.document.createTextNode( 'hello' ) );
	await tick( SETTLE );

	assert.strictEqual( scanned, false, 'Text churn must not cost a scan.' );

	ctx.document.querySelectorAll = original;
} );
