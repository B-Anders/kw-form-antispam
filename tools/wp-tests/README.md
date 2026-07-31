# WordPress-layer regression suite

Dev-only. Never shipped — `tools/` is excluded from the plugin ZIP, and
`tools/**/vendor/` is gitignored.

## Running it

```
cd tools/wp-tests
composer install
php vendor/bin/phpunit
```

Takes about 15 seconds; most of that is really solving proof-of-work puzzles.

Useful variations:

```
php vendor/bin/phpunit --filter GateTest              # one file
php vendor/bin/phpunit --exclude-group slow           # skip the end-to-end run
php vendor/bin/phpunit --testdox                      # readable output
```

## What it is

WordPress is replaced by stubs in `tests/stubs/`, so the suite needs no
database, no web server and no WordPress checkout. Everything else is real: the
plugin is loaded through its own main file and its own autoloader, and the
protocol core in `plugin/includes/altcha/` does the actual signing and
verifying.

The hook system in the stubs is real too — priorities, `accepted_args`, ordering.
Tests dispatch through `do_action()` / `apply_filters()` rather than calling
plugin methods directly, so a callback that is never registered fails the test
just as it would fail on a live site.

`tests/TestCase.php::submit()` models Kadence's submission pipeline in the order
`KB_Ajax_Advanced_Form::process_ajax()` actually runs it:

```
wp_ajax[_nopriv]_kb_process_advanced_form_submit   -> checkpoint A (priority 1)
get_form / captcha / process_fields
  on a field error: process_bail()   -> kadence_blocks_forms_buffer_flushed(true)
kadence_blocks_advanced_form_submission_reject     -> checkpoint B
  on a truthy return: ..._reject_message, then process_bail()
after_submit_actions                -> kadence_blocks_forms_buffer_flushed(false)
```

`tests/Solver.php` stands in for the browser: it mints a challenge through the
real REST handler, brute-forces the counter exactly as the ALTCHA widget's
worker does, and encodes the payload in the widget's exact wire format.

## What it covers

| File | Focus |
|---|---|
| `GateTest.php` | Hook registration and priority, memoisation, peek-vs-commit, replay, tampering, test-mode payloads, expiry, cross-form payloads, the Kadence bail shape, message escaping. |
| `FailOpenTest.php` | Missing and corrupt secrets, unwritable transient store, status reporting and the admin notice, secret generation and autoload flag. |
| `RateLimiterTest.php` | Per-client bucket, absence of a global cap, no raw IP stored anywhere, keyed rather than bare hashing, window rotation and expiry, filters, 429 response. |
| `ChallengeEndpointTest.php` | Challenge shape, no cloud features, form binding, uniqueness, no-cache headers, expiry clamping. |
| `FrontendTest.php` | Injection point, blocks left alone, conditional asset loading, locked display mode, configuration keys that a filter must not be able to re-enable, attribute whitelist, escaping. |
| `EndToEndTest.php` | The whole journey at the plugin's shipped work factor, including the field-error → retry → replay sequence. Tagged `slow`. |

The suite is mutation-checked. Six deliberate breakages were introduced and all
six were caught: spending the replay marker in checkpoint A instead of on
acceptance, removing the memoisation, removing the form-binding check, removing
the rejection-message escaping, failing closed instead of open on a missing
secret, and removing the replay check.

## What it does not cover

It stubs WordPress. It is not a substitute for testing on a real site, and it
proves nothing about:

- **The browser half.** The ALTCHA widget, the glue script, custom-element
  upgrade timing, and anything visual. `Solver.php` imitates the widget's
  *output*, not its behaviour. The glue script has its own suite in
  `tools/js-tests/`, which runs against a real DOM; the visibility filter is
  covered here, in `FrontendTest.php`, because it is a rendering decision made
  in PHP.
- **Real Kadence.** The pipeline in `submit()` is a model built from reading
  Kadence Blocks 3.7.8.2. If Kadence changes, this suite keeps passing while the
  live site breaks. That is the single biggest gap, and the reason the drift
  probe is on the hardening list.
- **Real WordPress semantics.** Object caching, transient eviction, multisite,
  autoload behaviour under load, actual REST dispatch and permission handling.
- **Page caching, multi-form pages, no-JS visitors, old devices** — the whole
  Phase 6 matrix in `PLAN.md`.
- **The protocol core's correctness against the widget.** That is the job of the
  differential oracle harness, not this suite. Here the core is used as-is; if it
  were wrong in a way that is self-consistent, these tests would not notice.
