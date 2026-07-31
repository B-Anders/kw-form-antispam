/**
 * Holding a submission that arrives before verification finished, and sending
 * it automatically once it does.
 *
 * This is the only thing standing between a slow solve and a rejected visitor
 * now that the widget is invisible by default, so every exit path is tested.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { setup, tick, click } = require( './harness' );

test( 'a verified widget submits straight through, with the payload', async () => {
	const ctx = await setup( { state: 'unverified' } );

	ctx.widget.setState( 'verified' );
	await tick();

	click( ctx );

	assert.strictEqual( ctx.kadence.submissions.length, 1, 'Kadence should receive it immediately.' );
	assert.match( ctx.kadence.lastPayload, /^PAYLOAD-/, 'The payload must travel with it.' );
} );

test( 'a submission during verification is held, then sent automatically', async () => {
	const ctx = await setup( { state: 'unverified' } );

	ctx.widget.verify();
	await tick();
	assert.strictEqual( ctx.widget.getState(), 'verifying' );

	click( ctx );
	await tick( 20 );

	assert.strictEqual( ctx.kadence.submissions.length, 0, 'The submission must be held, not sent.' );

	ctx.widget.kwfaResolve( 'verified' );
	await tick( 20 );

	assert.strictEqual( ctx.kadence.submissions.length, 1, 'It must be sent once verification lands.' );
	assert.match( ctx.kadence.lastPayload, /^PAYLOAD-/, 'And it must carry the payload.' );
} );

test( 'a submission before any verification starts arms the widget and waits', async () => {
	const ctx = await setup( { state: 'unverified' } );

	click( ctx );
	await tick( 20 );

	assert.ok( ctx.widget.kwfaVerifyCalls > 0, 'The click must start the work.' );
	assert.strictEqual( ctx.kadence.submissions.length, 0, 'And hold the submission meanwhile.' );

	ctx.widget.kwfaResolve( 'verified' );
	await tick( 20 );

	assert.strictEqual( ctx.kadence.submissions.length, 1 );
} );

test( 'the wait is bounded: on timeout the submission is released anyway', async () => {
	const ctx = await setup( { state: 'unverified', data: { waitTimeout: 120, noticeDelay: 20 } } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 40 );
	assert.strictEqual( ctx.kadence.submissions.length, 0, 'Still holding before the timeout.' );

	await tick( 200 );

	assert.strictEqual( ctx.kadence.submissions.length, 1, 'The visitor must never be trapped.' );
	assert.strictEqual( ctx.widget.getState(), 'verifying', 'The widget genuinely never resolved.' );
	assert.strictEqual( ctx.kadence.lastPayload, '', 'No payload is invented; the server decides.' );
} );

test( 'a widget error releases the submission rather than swallowing it', async () => {
	const ctx = await setup( { state: 'unverified' } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 20 );
	assert.strictEqual( ctx.kadence.submissions.length, 0 );

	ctx.widget.setState( 'error' );
	await tick( 20 );

	assert.strictEqual( ctx.kadence.submissions.length, 1, 'An error must fail open.' );
	assert.strictEqual( ctx.kadence.lastPayload, '' );
} );

test( 'a widget already in an error state is not waited for at all', async () => {
	const ctx = await setup( { state: 'unverified' } );

	ctx.widget.setState( 'error' );
	await tick();

	click( ctx );

	assert.strictEqual( ctx.kadence.submissions.length, 1, 'No point holding for a dead widget.' );
} );

test( 'clicking repeatedly while held sends exactly once', async () => {
	const ctx = await setup( { state: 'unverified' } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	click( ctx );
	click( ctx );
	await tick( 20 );

	assert.strictEqual( ctx.kadence.submissions.length, 0 );

	ctx.widget.kwfaResolve( 'verified' );
	await tick( 30 );

	assert.strictEqual( ctx.kadence.submissions.length, 1, 'Three clicks, one submission.' );
} );

test( 'clicking repeatedly while held still sends once when the wait times out', async () => {
	const ctx = await setup( { state: 'unverified', data: { waitTimeout: 100, noticeDelay: 20 } } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	click( ctx );
	await tick( 200 );

	assert.strictEqual( ctx.kadence.submissions.length, 1 );
} );

test( 'a second, genuine submission after the first completes still works', async () => {
	const ctx = await setup( { state: 'unverified' } );

	ctx.widget.setState( 'verified' );
	await tick();

	click( ctx );
	assert.strictEqual( ctx.kadence.submissions.length, 1 );

	// Kadence clears the form and we re-arm; the widget solves again.
	ctx.window.document.body.dispatchEvent(
		new ctx.window.CustomEvent( 'kb-advanced-form-success', { detail: { uniqueId: '42-cpt-id' } } )
	);
	await tick( 20 );

	assert.ok( ctx.widget.kwfaResetCalls > 0, 'The widget must be reset after success.' );
	assert.ok( ctx.widget.kwfaVerifyCalls > 0, 'And re-armed for the next submission.' );

	ctx.widget.kwfaResolve( 'verified' );
	await tick( 20 );

	click( ctx );

	assert.strictEqual( ctx.kadence.submissions.length, 2, 'The second submission must go through too.' );
	assert.match( ctx.kadence.lastPayload, /^PAYLOAD-/ );
} );

test( 'a form without a widget is never interfered with', async () => {
	const ctx = await setup( { widget: false } );

	click( ctx );

	assert.strictEqual( ctx.kadence.submissions.length, 1 );
} );

test( 'the held submission keeps the visitor typed data', async () => {
	const ctx = await setup( { state: 'unverified' } );
	const field = ctx.document.getElementById( 'field-42' );

	field.value = 'Please call me back';

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 20 );

	assert.strictEqual( field.value, 'Please call me back', 'Holding must not touch the form.' );

	ctx.widget.kwfaResolve( 'verified' );
	await tick( 20 );

	assert.strictEqual( field.value, 'Please call me back' );
	assert.strictEqual( ctx.kadence.submissions.length, 1 );
} );

test( 'the submitter button is preserved through the re-submission', async () => {
	const ctx = await setup( { state: 'unverified' } );
	const seen = [];

	ctx.form.addEventListener( 'submit', function ( event ) {
		seen.push( event.submitter ? event.submitter.className : null );
	} );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 20 );

	ctx.widget.kwfaResolve( 'verified' );
	await tick( 20 );

	assert.strictEqual( seen.length, 1 );
	assert.match( seen[ 0 ], /kb-adv-form-submit-button/ );
} );
