# KW Form Antispam — working notes

Proof-of-work spam protection for Kadence Advanced Form blocks.
A [Kreiswolke](https://kreiswolke.com) project. GPL-2.0-or-later.

## Layout

```
plugin/              the shippable plugin — everything here ships
  includes/altcha/   ALTCHA protocol core; no WordPress dependencies, unit-testable standalone
  includes/          WordPress layer: endpoint, injection, verification gate, drift probe
  assets/vendor/     vendored ALTCHA widget, pinned — see its VENDOR.md before touching
tools/               development only, never shipped
  oracle/            protocol tested against the official ALTCHA library
  wp-tests/          WordPress layer, stubbed WP
  js-tests/          browser behaviour, jsdom
  lint/              PHPCS + WordPress Coding Standards + PHP compatibility
  build-zip.ps1      produces deploy/kw-form-antispam-X.Y.Z.zip
```

## Commands

```
cd tools/oracle   && composer install && ./vendor/bin/phpunit
cd tools/wp-tests && composer install && ./vendor/bin/phpunit
cd tools/js-tests && node --test
./tools/lint/vendor/bin/phpcs --standard=phpcs.xml plugin/
pwsh -File tools/build-zip.ps1
```

All three suites and the linter must be clean before a release.

## Constraints that are not negotiable

- **PHP 7.4 / WP 6.6 floor**, matching Kadence Blocks exactly. Development happens
  on PHP 8.2, so compatibility is enforced *statically* by PHPCompatibility —
  run the linter, never assume.
- **No external HTTP requests, ever.** The plugin's core claim is that challenges
  are issued and verified entirely by the site itself. Nothing may open a socket.
- **No cookies, no personal data.** Rate limiting keys on a salted hash, never a
  stored IP.
- **Fail open.** A failure in our own machinery must never block a submission —
  a dark contact form is worse than a window of spam. Invalid *solutions* are
  still rejected; that is the plugin working, not failing.
- `plugin/includes/altcha/` must stay free of WordPress calls so the protocol
  can be tested standalone against the official library.

## Testing expectations

Mutation-check suites before trusting them: deliberately break the code and
confirm the tests go red. A green bar that has never been seen to fail proves
nothing — during this project three separate mutations passed undetected because
redundant code paths were covering for each other.

If maintainers keep internal notes in `docs/`, read `docs/HANDOFF.md` first.
