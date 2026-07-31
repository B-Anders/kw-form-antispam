=== KW Form Antispam ===
Contributors: kreiswolke
Tags: spam, antispam, captcha, kadence, altcha, proof-of-work
Requires at least: 6.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Proof-of-work spam protection for Kadence Advanced Form blocks. Self-hosted, no third-party services, no cookies, no personal data.

== Description ==

KW Form Antispam adds an invisible proof-of-work challenge to Kadence Advanced
Form blocks. Before a submission is accepted, the visitor's browser has to
complete a small computational task that your own WordPress site issued and
verifies. Sending one message costs nothing noticeable. Sending fifty thousand
becomes expensive.

There is no puzzle to solve, no traffic light to find, no cookie banner to
extend, and no account to create.

= How it is different =

* **No third parties.** Challenges are generated and verified entirely inside
  your WordPress installation. The plugin makes no outbound HTTP requests, under
  any condition. No API keys, no accounts, no CDN.
* **No personal data in the mechanism.** No cookies, no fingerprinting, no
  tracking pixels. The challenge contains a random nonce, a salt and a form ID —
  nothing about the visitor. See the Privacy section below.
* **Accessible.** The visitor sees a real checkbox with a real label, which
  works with a keyboard and with a screen reader. There is no visual or audio
  puzzle to pass. Relevant wherever the European Accessibility Act (in Germany,
  the BFSG) applies.
* **Stacks with what you already run.** It coexists with Kadence's own honeypot
  and its native CAPTCHA field.
* **Fails open, loudly.** If the plugin's own machinery ever breaks — a missing
  secret, an unwritable store — it lets the submission through and raises a
  specific admin notice telling you protection is off and why. A client's
  contact form going dark is worse than a window of spam. Missing or invalid
  *solutions* are still rejected: that is the plugin working, not failing.

= Rate limiting =

The challenge endpoint is rate limited out of the box: 30 challenges per minute
per client, tunable with the `kwfa_rate_limit_max` and `kwfa_rate_limit_window`
filters. Buckets are keyed on a salted, time-windowed hash of the client
address — the address itself is never stored.

= Which form blocks are supported =

**Kadence Advanced Form** (the block backed by the Kadence Form post type) is
supported.

The **legacy Kadence Form block cannot be supported.** It runs through a
different AJAX handler that exposes no server-side validation hook of any kind,
so no third-party plugin can add verification to it. Kadence's own block
transform converts a legacy form into an Advanced Form; do that first, then this
plugin protects it.

= Requirements =

* WordPress 6.6 or later
* PHP 7.4 or later
* Kadence Blocks (free), using the Advanced Form block
* A site served over HTTPS — the widget requires a secure browser context

== Installation ==

1. Install and activate Kadence Blocks, and build your form with the Advanced
   Form block.
2. Install and activate KW Form Antispam.
3. That is all. There is nothing to configure. A signing secret is generated on
   activation and the verification checkbox appears above the submit button of
   every Advanced Form.

== Frequently Asked Questions ==

= Does this send anything to an external service? =

No. The plugin makes no outbound HTTP requests at all. Challenges are created
and verified by your own server.

= Does it set cookies or store IP addresses? =

No cookies. No IP addresses are stored. See the Privacy section.

= What happens to visitors with JavaScript disabled? =

They could not submit a Kadence Advanced Form in the first place: the form
element carries no `action` attribute and submission is handled entirely by
Kadence's own JavaScript. This is a Kadence limitation, not one this plugin
introduces.

= Will it slow down old phones? =

The default work factor is chosen to stay well under a second on low-end
hardware, and the work starts as soon as the visitor touches the form rather
than when they press submit. If your site attracts unusually heavy automated
traffic you can raise it with the `kwfa_challenge_cost` filter.

= Does it work with page caching? =

Yes. Nothing time-sensitive is written into the page. The challenge is fetched
from a REST endpoint at interaction time, and that endpoint sends no-cache
headers.

= Can I change the rejection message? =

Yes, with the `kwfa_rejection_message` filter. The return value is escaped
before it reaches the browser.

== Privacy ==

This plugin was built so that a data protection audit finds nothing to
complain about.

* **No outbound requests.** Nothing about your visitors leaves your server,
  because nothing is sent anywhere.
* **No cookies and no local storage.** The verification result travels as a
  hidden form field within the submission the visitor is already making.
* **No personal data inside the challenge.** A challenge contains a random
  nonce, a random salt, a work factor, an expiry timestamp and the numeric ID of
  the form it belongs to. Nothing derived from the visitor.
* **No behavioural telemetry.** The bundled widget's optional interaction
  collector is switched off explicitly and unconditionally.
* **IP addresses are never stored.** The rate limiter needs to tell clients
  apart. It reads the address from the current request, folds it into an HMAC
  keyed with your site's private signing secret *and* the current time window,
  and stores only a truncated hash of that as a short-lived transient name. The
  key changes every window, so nothing links two visits together, and the raw
  address is never written to the database, to a log, or anywhere else.
* **What is stored.** One option holding the site's signing secret, one small
  option recording whether protection is currently degraded, and short-lived
  transients for rate limiting and single-use enforcement. Uninstalling removes
  all of them.

== Third-party assets and trademarks ==

Proof-of-work challenges follow the ALTCHA protocol, and this plugin bundles the
MIT-licensed ALTCHA browser widget (© 2023-2026 Daniel Regeci, BAU Software
s.r.o.), unminified, in `assets/vendor/altcha/`. Its licence and exact upstream
provenance are documented in `assets/vendor/altcha/LICENSE.txt` and
`assets/vendor/altcha/VENDOR.md`. The widget is loaded from your own server; it
contacts nothing.

ALTCHA® is a registered trademark of BAU Software s.r.o. This project is an
independent implementation and is **not affiliated with, endorsed by, or
supported by** BAU Software s.r.o. Kadence® is a trademark of its respective
owner; this project is likewise independent of it. Both names are used here only
to describe what this plugin is compatible with.

== Changelog ==

= 0.1.0 =
* First release. Proof-of-work protection for Kadence Advanced Form blocks,
  self-hosted challenge endpoint with rate limiting, single-use solutions,
  fail-open behaviour with admin reporting.

== Upgrade Notice ==

= 0.1.0 =
First release.
