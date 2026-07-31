# ALTCHA oracle harness (dev only — never shipped)

Differential test suite that pins `plugin/includes/altcha/` against the **official**
`altcha-org/altcha` PHP library, used as a protocol oracle.

The plugin reimplements the ALTCHA v3 server side instead of bundling the official
library (see `docs/PLAN.md`, "Reimplement the ALTCHA server side"). A reimplementation
that is merely *self*-consistent is the worst possible outcome here: the plugin would
look like it works while accepting anything. This harness is the guard against that.

## Requirements

- **PHP 8.1+** — required by `altcha-org/altcha` (`composer.json: "php": ">=8.1"`), and
  by PHPUnit 11. Developed and run on **PHP 8.2.31**.
- Composer 2.

> The **plugin core itself needs only PHP 7.4**. The 8.1 floor applies to this harness
> only, because of the oracle library. `CoreHygieneTest` enforces the 7.4 floor
> statically by re-parsing every core file with a PHP 7.4 grammar.

## Running it

```bash
cd tools/oracle
composer install
php vendor/bin/phpunit
```

Individual groups:

```bash
php vendor/bin/phpunit --group differential   # the four-direction matrix
php vendor/bin/phpunit --group negative       # everything that must be rejected
php vendor/bin/phpunit --group hygiene        # PHP 7.4 / no-WP / no-I/O static checks
```

The whole suite runs in well under a second: `OracleTestCase::COST` is 200 PBKDF2
iterations, so solving a `keyPrefix: '00'` challenge costs ~256 × 200 = ~51k iterations.
Production defaults to `cost = 5000`.

## What it proves

`tests/DifferentialTest.php` — the four directions:

| # | Direction | Proves |
|---|---|---|
| 1 | ours creates → **official solves** → ours verifies | our verifier accepts a genuine, independently produced solution |
| 2 | ours creates → official solves → **official verifies** | our challenges are protocol-correct, not just self-consistent |
| 3 | **official creates** → official solves → ours verifies | our verifier accepts a challenge we did not mint |
| 4 | byte-for-byte canonical JSON, normalised parameter arrays, and HMAC signature | catches the `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE` landmine, key-ordering, and the fixed-schema re-projection |

Plus PBKDF2 agreement over 50 random (nonce, salt, cost, keyLength, counter) tuples.

`tests/NegativeTest.php` — rejection with the correct error code: tampered `cost` /
`salt` / `nonce` / `keyPrefix` / `keyLength` / `algorithm` / `expiresAt` / `data`,
tampered and missing signature, wrong secret, empty secret, expired challenge, missing
expiry, `test: true` payloads, wrong derived key, wrong counter, an honest derivation
that misses the `keyPrefix`, odd-length and empty `keyPrefix`, injected `keySignature`,
`cost` above the cap, malformed base64/JSON, missing fields, empty string, JSON nesting
bombs and a 4 MB payload.

`tests/CoreHygieneTest.php` — static guarantees on the shipped files: parses under a
PHP 7.4 grammar; no constructor promotion, named arguments, nullsafe, union/intersection
types, attributes, first-class callables, non-capturing catch, `new` in initializers,
PHP 8 type names, or trailing commas in parameter lists; no post-7.4 functions
(`array_is_list()` must stay behind a `function_exists()` guard); no WordPress symbols;
no socket, filesystem, process or `eval` calls; no non-cryptographic RNG; secret
comparisons via `hash_equals()`.

## Sanity of the harness itself

The suite was mutation-checked. Each of these deliberate regressions was caught:

| Mutation | Failing tests |
|---|---|
| drop both `json_encode` flags | 21 |
| drop `JSON_UNESCAPED_UNICODE` only | 4 |
| skip the `keyPrefix` proof-of-work check | 2 |
| skip the HMAC signature check | 14 |
| drop the recursive `ksort` of nested `data` | 2 |
| introduce `readonly` in a core file | 3 (errors) |

## Notes

- `vendor/` is gitignored (`tools/oracle/.gitignore`). Nothing under `tools/` is part of
  the plugin ZIP.
- Pinned oracle: `altcha-org/altcha` **v2.1.0**, commit
  `63038786ae51b572f8f1de5a29079bea5971dd84` — the same commit
  `docs/RESEARCH-altcha.md` was written against.
- The harness makes **no network calls**. `composer install` is the only step that does.
