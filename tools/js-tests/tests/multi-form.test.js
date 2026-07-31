/**
 * Re-arming after a success, on a page with more than one form.
 *
 * A solution is single-use, so the form that just submitted has to be re-armed.
 * Re-arming *every* widget on the page is the tempting shortcut and it is wrong:
 * each re-arm fetches a fresh challenge, so one success on an N-form page fires
 * N requests at our own per-client rate limiter. The surplus widgets error, and
 * a visitor who then submits one of those forms — which they never touched — is
 * turned away for having no solution.
 *
 * A content form plus a footer form is an ordinary page, so this is an ordinary
 * failure, not an edge case.
 */

'use strict';

const test = require( 'node:test' );
const assert = require( 'node:assert' );
const { setup, tick, click, dispatchSuccess } = require( './harness' );

/**
 * Bring a form's widget to a verified state and submit it.
 */
async function submitForm( entry ) {
	entry.widget.setState( 'verified' );
	await tick();

	click( entry );
	await tick();
}

test( 'a success on one form re-arms only that form', async () => {
	const ctx = await setup( { formIds: [ 42, 77 ] } );
	const a = ctx.byFormId[ 42 ];
	const b = ctx.byFormId[ 77 ];

	// Both forms have solved challenges waiting; only A is submitted.
	b.widget.setState( 'verified' );
	await tick();
	const bPayload = b.widget.kwfaPayload();

	await submitForm( a );
	assert.strictEqual( a.kadence.submissions.length, 1 );

	const aResets = a.widget.kwfaResetCalls;
	const bResets = b.widget.kwfaResetCalls;
	const bVerifies = b.widget.kwfaVerifyCalls;

	dispatchSuccess( ctx, '42-cpt-id' );
	await tick( 20 );

	assert.ok( a.widget.kwfaResetCalls > aResets, 'The form that submitted must be re-armed.' );
	assert.strictEqual( b.widget.kwfaResetCalls, bResets, 'The other form must be left alone.' );
	assert.strictEqual( b.widget.kwfaVerifyCalls, bVerifies, 'And must not fetch a fresh challenge.' );
	assert.strictEqual( b.widget.kwfaPayload(), bPayload, 'Its unspent solution must survive.' );
} );

test( 'the untouched form can still submit successfully afterwards', async () => {
	const ctx = await setup( { formIds: [ 42, 77 ] } );
	const a = ctx.byFormId[ 42 ];
	const b = ctx.byFormId[ 77 ];

	b.widget.setState( 'verified' );
	await tick();
	const bPayload = b.widget.kwfaPayload();

	await submitForm( a );
	dispatchSuccess( ctx, '42-cpt-id' );
	await tick( 20 );

	click( b );
	await tick( 20 );

	assert.strictEqual( b.kadence.submissions.length, 1, 'The other form must still work.' );
	assert.strictEqual( b.kadence.lastPayload, bPayload, 'With the solution it already had.' );
} );

test( 'three forms, one success, two left untouched', async () => {
	const ctx = await setup( { formIds: [ 42, 77, 99 ] } );

	for ( const entry of ctx.forms ) {
		entry.widget.setState( 'verified' );
	}
	await tick();

	const before = ctx.forms.map( ( entry ) => entry.widget.kwfaVerifyCalls );

	await submitForm( ctx.byFormId[ 77 ] );
	dispatchSuccess( ctx, '77-cpt-id' );
	await tick( 20 );

	assert.ok( ctx.byFormId[ 77 ].widget.kwfaVerifyCalls > before[ 1 ] );
	assert.strictEqual( ctx.byFormId[ 42 ].widget.kwfaVerifyCalls, before[ 0 ] );
	assert.strictEqual( ctx.byFormId[ 99 ].widget.kwfaVerifyCalls, before[ 2 ] );
} );

test( 'the re-armed form does fetch a fresh challenge', async () => {
	const ctx = await setup( { formIds: [ 42, 77 ] } );
	const a = ctx.byFormId[ 42 ];

	await submitForm( a );

	const before = a.widget.kwfaVerifyCalls;

	dispatchSuccess( ctx, '42-cpt-id' );
	await tick( 20 );

	assert.ok( a.widget.kwfaVerifyCalls > before, 'It is spent, so it needs a new one.' );
	assert.strictEqual( a.widget.kwfaPayload(), '', 'And starts from empty.' );
} );

// -----------------------------------------------------------------------------
// Fallback: the originating form cannot be identified
// -----------------------------------------------------------------------------

test( 'with no uniqueId, only forms that actually submitted are re-armed', async () => {
	const ctx = await setup( { formIds: [ 42, 77 ] } );
	const a = ctx.byFormId[ 42 ];
	const b = ctx.byFormId[ 77 ];

	b.widget.setState( 'verified' );
	await tick();
	const bPayload = b.widget.kwfaPayload();

	await submitForm( a );

	const bVerifies = b.widget.kwfaVerifyCalls;

	// Kadence emits "" when the hidden input is missing from the form.
	dispatchSuccess( ctx, null );
	await tick( 20 );

	assert.strictEqual( a.widget.kwfaPayload(), '', 'The submitted form is still re-armed.' );
	assert.strictEqual( b.widget.kwfaVerifyCalls, bVerifies, 'The untouched one is still spared.' );
	assert.strictEqual( b.widget.kwfaPayload(), bPayload );
} );

test( 'an unrecognised uniqueId falls back the same way', async () => {
	const ctx = await setup( { formIds: [ 42, 77 ] } );
	const a = ctx.byFormId[ 42 ];
	const b = ctx.byFormId[ 77 ];

	b.widget.setState( 'verified' );
	await tick();
	const bVerifies = b.widget.kwfaVerifyCalls;

	await submitForm( a );

	dispatchSuccess( ctx, '12345-cpt-id' );
	await tick( 20 );

	assert.strictEqual( a.widget.kwfaPayload(), '' );
	assert.strictEqual( b.widget.kwfaVerifyCalls, bVerifies );
} );

test( 'a success for a form that never submitted re-arms nothing', async () => {
	const ctx = await setup( { formIds: [ 42, 77 ] } );

	for ( const entry of ctx.forms ) {
		entry.widget.setState( 'verified' );
	}
	await tick();

	const before = ctx.forms.map( ( entry ) => entry.widget.kwfaVerifyCalls );

	dispatchSuccess( ctx, null );
	await tick( 20 );

	ctx.forms.forEach( ( entry, index ) => {
		assert.strictEqual( entry.widget.kwfaVerifyCalls, before[ index ] );
	} );
} );

test( 'the same form post rendered twice: both are re-armed, nothing else is', async () => {
	// RESEARCH-kadence.md §7: two blocks referencing one form CPT emit an
	// identical _kb_adv_form_id, so the success event cannot tell them apart.
	const ctx = await setup( { formIds: [ 42, 42, 77 ] } );
	const other = ctx.forms[ 2 ];

	other.widget.setState( 'verified' );
	await tick();
	const otherVerifies = other.widget.kwfaVerifyCalls;

	await submitForm( ctx.forms[ 0 ] );

	dispatchSuccess( ctx, '42-cpt-id' );
	await tick( 20 );

	assert.strictEqual( ctx.forms[ 0 ].widget.kwfaPayload(), '', 'The one that submitted is re-armed.' );
	assert.strictEqual(
		other.widget.kwfaVerifyCalls,
		otherVerifies,
		'The unrelated form must still be spared.'
	);
} );

test( 'the uniqueId identifies the form even when its payload has moved on', async () => {
	// Isolates the primary mechanism. The spent-payload fallback is deliberately
	// blinded here — A re-verifies after submitting, so the payload it now holds
	// is not the one that went out — leaving detail.uniqueId as the only thing
	// that can still identify A. Without this, the fallback silently covers for
	// a broken uniqueId path and the two are indistinguishable.
	const ctx = await setup( { formIds: [ 42, 77 ] } );
	const a = ctx.byFormId[ 42 ];
	const b = ctx.byFormId[ 77 ];

	b.widget.setState( 'verified' );
	await tick();
	const bVerifies = b.widget.kwfaVerifyCalls;

	await submitForm( a );

	a.widget.verify();
	await tick();
	a.widget.kwfaResolve( 'verified' );
	await tick();

	const aVerifies = a.widget.kwfaVerifyCalls;

	dispatchSuccess( ctx, '42-cpt-id' );
	await tick( 20 );

	assert.ok( a.widget.kwfaVerifyCalls > aVerifies, 'uniqueId must still identify A.' );
	assert.strictEqual( b.widget.kwfaVerifyCalls, bVerifies, 'And B must still be spared.' );
} );

test( 'the targets are decided before Kadence clears the form', async () => {
	// Kadence dispatches the success event and only then calls clearForm(),
	// which is form.reset() — and the widget resets itself on that, wiping the
	// payload. Deciding which forms to re-arm has to happen while that evidence
	// still exists, so the decision is made synchronously in the handler and
	// only the re-arm itself is deferred by a tick.
	const ctx = await setup( { formIds: [ 42, 77 ] } );
	const a = ctx.byFormId[ 42 ];
	const b = ctx.byFormId[ 77 ];

	b.widget.setState( 'verified' );
	await tick();
	const bVerifies = b.widget.kwfaVerifyCalls;

	await submitForm( a );

	const aVerifies = a.widget.kwfaVerifyCalls;

	// No uniqueId, so the payload fallback is the only route.
	dispatchSuccess( ctx, null );
	a.form.reset();

	await tick( 20 );

	assert.ok( a.widget.kwfaVerifyCalls > aVerifies, 'A must still be re-armed.' );
	assert.strictEqual( b.widget.kwfaVerifyCalls, bVerifies );
} );

test( 'a held submission on one form does not block another', async () => {
	const ctx = await setup( { formIds: [ 42, 77 ] } );
	const a = ctx.byFormId[ 42 ];
	const b = ctx.byFormId[ 77 ];

	a.widget.verify();
	await tick();

	click( a );
	await tick( 20 );
	assert.strictEqual( a.kadence.submissions.length, 0, 'A is held.' );

	b.widget.setState( 'verified' );
	await tick();
	click( b );

	assert.strictEqual( b.kadence.submissions.length, 1, 'B goes straight out.' );
} );
