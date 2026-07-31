# Vendored third-party asset: ALTCHA widget

| | |
|---|---|
| Upstream | https://github.com/altcha-org/altcha |
| Version | v3.2.1 |
| Commit | `156bb306827fcbf5e3d8677ba4653e7ed9659ec3` (2026-07-12) |
| Licence | MIT — see `LICENSE.txt` (© 2023-2026 Daniel Regeci, BAU Software s.r.o.) |
| Upstream file | `dist/main/altcha.umd.cjs` |
| Vendored as | `altcha.umd.js` |
| SHA-256 | `eea671d35fdd58259a466b976cb2a17f0124ae413a36e87c3eee5702f3c154de` |

## Why this exact file

- **UMD, not ESM** — loads as a classic `<script>` via `wp_enqueue_script()`, and
  self-registers the `<altcha-widget>` custom element.
- **Unminified** — WordPress.org forbids shipping compiled/minified code without
  accessible human-readable source. This build is readable as shipped, so the
  requirement is satisfied by the file itself with no separate source drop and no
  build step on our side. Ships ~268 KB raw, well-compressible, and is enqueued
  only on pages that actually contain a Kadence Advanced Form.
- **Base bundle, not `altcha.i18n.*`** — the i18n build carries 73 locales
  (~318 KB extra). We supply German/English strings through the widget's
  `strings` attribute using WordPress translations instead.

## Only change made

The file extension was changed from `.cjs` to `.js`. Contents are byte-identical
to upstream (verify with the SHA-256 above). Reason: some servers do not map
`.cjs` to `application/javascript`, and a wrong MIME type combined with
`X-Content-Type-Options: nosniff` blocks the script in the browser.

## Updating

1. `git clone https://github.com/altcha-org/altcha.git && git checkout <new tag>`
2. Copy `dist/main/altcha.umd.cjs` here as `altcha.umd.js`, refresh `LICENSE.txt`
3. Update this file (version, commit, date, SHA-256)
4. Re-run the protocol oracle tests — a widget update can change the wire format
   (v2 → v3 already did exactly that)
