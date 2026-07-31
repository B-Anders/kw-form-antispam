# Browser-behaviour tests

Dev-only. Never shipped — `tools/` is excluded from the plugin ZIP and
`node_modules/` is gitignored.

## Running it

```
cd tools/js-tests
npm install
npm test
```

About four seconds. Node's built-in test runner, no framework.

## Why this exists separately from tools/wp-tests

`tools/wp-tests/` covers PHP. It cannot reach `assets/js/kwfa-widget.js`, and
that file now carries the behaviour the plugin depends on most: with the widget
invisible by default, the deferred-submission handler is the only thing between
a slow proof-of-work and a rejected visitor. It needed real tests, and real
tests needed a real DOM.

jsdom provides one with enough fidelity for the things that actually matter
here — and they are subtle:

- capture-phase listeners fire before bubble-phase listeners on the same element
- `stopImmediatePropagation()` in the capture phase suppresses the bubble
  listener, which is how the glue holds a submission back from Kadence
- `form.requestSubmit(submitter)` re-dispatches with `event.submitter` intact
- **constraint validation runs before the `submit` event**, so a `required`
  control that cannot be focused silently eats the submission

That last one is not a detail. It is the bug `required-guard.test.js` exists for.

## What it covers

| File | Focus |
|---|---|
| `deferred-submit.test.js` | A held submission completes automatically; timeout releases it; a widget error releases it; repeated clicks send exactly once; typed data survives; the submitter button survives; a verified widget is not delayed; a form without a widget is untouched. |
| `required-guard.test.js` | Reproduces the invisible-`required` trap with the glue *not* loaded, then proves the glue removes it, keeps it removed when the widget re-applies it, and leaves the site owner's own required fields alone. |
| `wait-notice.test.js` | The message appears only after the configured delay, is a polite live region, is removed on every exit path, appears once however many clicks, is inserted as text rather than markup, and is skipped when the server sent none. |

Mutation-checked. Six deliberate breakages, all caught: removing the deferral,
removing the double-submit guard, removing the wait timeout, removing the
`required` guard, treating an error state as still-pending, and removing the
wait notice.

## What it does not cover

`tests/harness.js` contains a **stand-in** for the ALTCHA widget, written from
reading `research/altcha-widget-pinned/src/`. It reproduces the contract the
glue depends on — `getState()`, `verify()`, `reset()`, asynchronous
`statechange` / `verified` events, the hidden payload input, and the widget's
own `required` expression — but it is a model, exactly like the Kadence pipeline
model in the PHP suite. If the real widget changes, these tests keep passing.

Also untested here: real proof-of-work (there is none — the harness resolves
state on command), real network, real Kadence JavaScript, custom-element upgrade
timing, and anything visual. jsdom has no layout engine, so "invisible" is taken
on trust from the CSS rule in `widget.scss:728`; it is not observed.
