/**
 * The invisible widget's `required` checkbox, and why it has to be disarmed.
 *
 * The widget sets `required` on its checkbox while verifying, in every display
 * mode (Widget.svelte:1387-1388). In the invisible mode that checkbox lives
 * inside a `display: none` container, so the browser cannot focus or report on
 * it: the submission is refused and the `submit` event never fires at all —
 * taking the deferral with it.
 *
 * The first test here proves the trap is real by reproducing it without the
 * glue loaded. The rest prove the glue removes it and keeps it removed.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { setup, tick, click } = require( './harness' );

test( 'the trap is real: an invisible required checkbox eats the submit event', async () => {
	const ctx = await setup( { display: 'invisible', state: 'unverified', loadGlue: false } );

	ctx.widget.setState( 'verifying' );
	await tick();

	const checkbox = ctx.widget.querySelector( 'input[type="checkbox"]' );
	assert.strictEqual( checkbox.required, true, 'The widget really does set it.' );

	click( ctx );

	assert.strictEqual(
		ctx.kadence.submissions.length,
		0,
		'Without the guard the click does nothing at all — this is the bug.'
	);
} );

test( 'with the glue loaded, the same submission gets through', async () => {
	const ctx = await setup( { display: 'invisible', state: 'unverified' } );

	ctx.widget.setState( 'verifying' );
	await tick();

	click( ctx );
	await tick( 20 );

	// Held rather than sent, which is the point: the submit event reached us
	// instead of being eaten by constraint validation.
	assert.strictEqual( ctx.kadence.submissions.length, 0 );

	ctx.widget.setState( 'verified' );
	await tick( 20 );

	assert.strictEqual( ctx.kadence.submissions.length, 1 );
	assert.match( ctx.kadence.lastPayload, /^PAYLOAD-/ );
} );

test( 'required is stripped immediately on binding', async () => {
	const ctx = await setup( { display: 'standard', state: 'unverified' } );

	const checkbox = ctx.widget.querySelector( 'input[type="checkbox"]' );

	assert.strictEqual( checkbox.required, false, 'Visible mode sets it at mount; it must be gone.' );
} );

test( 'required stays off when the widget re-applies it', async () => {
	const ctx = await setup( { display: 'invisible', state: 'unverified' } );

	const checkbox = ctx.widget.querySelector( 'input[type="checkbox"]' );

	ctx.widget.setState( 'verifying' );
	await tick( 20 );

	assert.strictEqual( checkbox.required, false, 'The observer must put it back off.' );

	ctx.widget.setState( 'unverified' );
	ctx.widget.setState( 'verifying' );
	await tick( 20 );

	assert.strictEqual( checkbox.required, false, 'Repeatedly.' );
} );

test( 'other required fields in the form are left alone', async () => {
	const ctx = await setup( { display: 'invisible', state: 'unverified' } );

	const field = ctx.document.getElementById( 'field-42' );
	field.required = true;

	await tick( 20 );

	assert.strictEqual( field.required, true, 'The site owner\'s own validation must survive.' );

	// And it still does its job: an empty required field blocks the submission
	// before our handler ever sees it, exactly as Kadence intends.
	click( ctx );
	await tick( 20 );

	assert.strictEqual( ctx.kadence.submissions.length, 0 );
} );
