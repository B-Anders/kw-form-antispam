# KW Form Antispam

Proof-of-work spam protection for [Kadence](https://www.kadencewp.com/) Advanced Form
blocks. Self-contained, privacy-preserving, no third-party services.

> **Status: in development — not yet released.**
> This plugin is not ready for production use. Please do not install it on a live
> site yet. A WordPress.org release will follow once it has been through testing.

## What it does

Adds a proof-of-work challenge to Kadence Advanced Form blocks. Before a
submission is accepted, the visitor's browser must complete a small computational
task issued and verified by your own server. Bulk spam becomes expensive; a single
genuine visitor never notices.

Unlike conventional CAPTCHAs, there is nothing for the visitor to solve, read, or
click through — and nothing to consent to.

## Why

- **No third parties.** Challenges are issued and verified entirely by your own
  WordPress installation. No accounts, no API keys, no CDN, no outbound requests.
- **GDPR/DSGVO-clean by construction.** No cookies, no fingerprinting, no tracking,
  no personal data involved in the mechanism.
- **Accessible.** No visual or audio puzzle. Relevant where the European
  Accessibility Act (in Germany, BFSG) applies.
- **Works alongside what you already run.** Coexists with Kadence's built-in
  honeypot and its native CAPTCHA field; they can be stacked.

## Requirements

- WordPress 6.6 or later
- PHP 7.4 or later
- Kadence Blocks (free), using the **Advanced Form** block

The legacy Kadence *Form* block is not supported — it exposes no server-side
validation hook, so no plugin can protect it. Kadence's block transform tool
converts legacy forms to Advanced Forms.

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Credits and trademarks

Proof-of-work challenges follow the [ALTCHA](https://altcha.org/) protocol, and this
plugin bundles the MIT-licensed ALTCHA widget
(© Daniel Regeci, BAU Software s.r.o.).

ALTCHA® is a registered trademark of BAU Software s.r.o. This project is an
independent implementation and is **not affiliated with, endorsed by, or supported
by** BAU Software s.r.o. Kadence® is a trademark of its respective owner; this
project is likewise independent of it.

---

A [Kreiswolke](https://kreiswolke.com) project.
