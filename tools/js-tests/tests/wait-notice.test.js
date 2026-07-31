/**
 * Feedback while a submission is held.
 *
 * With no visible widget, a silent multi-second pause after pressing a button
 * reads as a broken button.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { setup, tick, click } = require( './harness' );

function notice( ctx ) {
	return ctx.document.querySelector( '.kwfa-wait-notice' );
}

test( 'a perceptible wait produces a polite status message', async () => {
	const ctx = await setup( { state: 'unverified', data: { noticeDelay: 30, waitTimeout: 2000 } } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 5 );

	assert.strictEqual( notice( ctx ), null, 'Nothing yet — the wait might be imperceptible.' );

	await tick( 60 );

	const el = notice( ctx );

	assert.ok( el, 'After the delay the visitor must be told something is happening.' );
	assert.strictEqual( el.getAttribute( 'role' ), 'status' );
	assert.strictEqual( el.getAttribute( 'aria-live' ), 'polite' );
	assert.strictEqual( el.textContent, 'Einen Moment …', 'Translated, from the server.' );
} );

test( 'a fast verification never shows the message', async () => {
	const ctx = await setup( { state: 'unverified', data: { noticeDelay: 200, waitTimeout: 2000 } } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 10 );

	ctx.widget.kwfaResolve( 'verified' );
	await tick( 250 );

	assert.strictEqual( notice( ctx ), null, 'No flash of unnecessary UI.' );
	assert.strictEqual( ctx.kadence.submissions.length, 1 );
} );

test( 'the message is removed once the submission goes out', async () => {
	const ctx = await setup( { state: 'unverified', data: { noticeDelay: 20, waitTimeout: 2000 } } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 50 );
	assert.ok( notice( ctx ), 'Shown while waiting.' );

	ctx.widget.kwfaResolve( 'verified' );
	await tick( 30 );

	assert.strictEqual( notice( ctx ), null, 'Gone afterwards.' );
} );

test( 'the message is removed when the wait times out too', async () => {
	const ctx = await setup( { state: 'unverified', data: { noticeDelay: 20, waitTimeout: 100 } } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 200 );

	assert.strictEqual( notice( ctx ), null );
	assert.strictEqual( ctx.kadence.submissions.length, 1 );
} );

test( 'the message sits next to the submit button, inside the form', async () => {
	const ctx = await setup( { state: 'unverified', data: { noticeDelay: 10, waitTimeout: 2000 } } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 40 );

	const el = notice( ctx );

	assert.ok( el );
	assert.strictEqual( el.closest( 'form' ), ctx.form );
	assert.strictEqual( el.previousElementSibling.className, 'kb-adv-form-field kb-submit-field' );
} );

test( 'only one message appears however many times the visitor clicks', async () => {
	const ctx = await setup( { state: 'unverified', data: { noticeDelay: 10, waitTimeout: 2000 } } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	click( ctx );
	click( ctx );
	await tick( 50 );

	assert.strictEqual( ctx.document.querySelectorAll( '.kwfa-wait-notice' ).length, 1 );
} );

test( 'a message the server did not send is simply not shown', async () => {
	const ctx = await setup( { state: 'unverified', data: { noticeDelay: 10, waitTimeout: 2000, ui: {} } } );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 40 );

	assert.strictEqual( notice( ctx ), null, 'No empty box.' );

	ctx.widget.kwfaResolve( 'verified' );
	await tick( 20 );

	assert.strictEqual( ctx.kadence.submissions.length, 1, 'And the deferral still works.' );
} );

test( 'the message is inserted as text, never as markup', async () => {
	const ctx = await setup( {
		state: 'unverified',
		data: { noticeDelay: 10, waitTimeout: 2000, ui: { waiting: '<img src=x onerror=alert(1)>' } }
	} );

	ctx.widget.verify();
	await tick();

	click( ctx );
	await tick( 40 );

	const el = notice( ctx );

	assert.ok( el );
	assert.strictEqual( el.querySelector( 'img' ), null, 'Must not become an element.' );
	assert.strictEqual( el.textContent, '<img src=x onerror=alert(1)>' );
} );
