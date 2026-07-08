# Changelog

All notable changes to Luster are documented in this file. For the full 
feature overview, see the [README](README.md).


## A Note on Updating

Each release includes a packaged ZIP file and a matching checksum file.
Luster's admin panel can check for these and apply updates automatically
— it will never overwrite your `config/app.php`, `storage/`,
`public/uploads/`, or `vendor/` folders. If a release is missing one of
those files, the admin panel will let you know that a manual update is
needed and link you to the release page.


## [Unreleased]

**"Approve All Pending" actually works now**
The "Approve All Pending" and "Approve All Updates" buttons on the Members page were
quietly broken — clicking them didn't do anything, because of some invalid nested
HTML under the hood confusing the browser. That's fixed now, so bulk-approving
pending members and pending profile updates works in one click like you'd expect.

**Collective Update: a clearer success message, plus a confirmation email**
After updating your info across multiple fanlistings through the Collective Update
form, you'll now see a proper bulleted list of exactly which listings were changed,
instead of one long run-on sentence. You'll also get a confirmation email listing
the same thing, with its own editable template in Admin → Emails.

**Other fixes**
- The Members table now shows a Date Joined column, and a member's Edit page shows
  their join date too (read-only — it's set automatically and never changes).
- Collective Update's confirmation email now goes to a member's new address when
  their submission also changed their email, instead of the old one.


### [1.0.0] — 2026-07-05

The first full release of Luster! A free, open-source management script
for fanlistings, cliques, collectives, and other hobby listings. If
you've used scripts like Enthusiast before, Luster should feel familiar
but with a modern foundation: one admin panel for everything, security
built in from the start, and a setup wizard that does the heavy lifting
for you.

**Highlights:**

- **One admin panel, multiple listings & types:** run a single listing or a
  whole collective side by side.
- **Guided migration & wrappers:** Import Data Wizard plus compatibility
  wrappers so old includes keep working while you transition.
- **Dashboard to-do list:** pending members, overdue listings, affiliate
  applications, and more, with shortcuts to handle each one.
- **Multiple admin users:** separate logins for co-owners instead of one
  shared password.
- **Admin panel customization:** light/dark mode and six bundled skins,
  with community skin support.
- **Custom networks & categories:** built-in TFL/TAFL categories, or add
  your own, plus a one-click TFL/TAFL approval URL lookup.
- **Customizable public embeds & live previews:** 29 drop-in embeds with
  clickable tokens, an Embed Wizard, and targetable CSS classes.
- **Three-tier template system:** global defaults, per-listing overrides,
  one-click reset.
- **Template portability:** export/import template packs, copy templates
  between listings, and roll back with built-in revision history.
- **Drag-and-drop reordering:** for codes and affiliates, reflected live
  on your site.
- **Communication tools with live previews:** bulk or individual emails,
  reusable templates, per-listing on/off control.
- **Extra features when you want them:** five optional add-ons — News
  Feed, Wishlist, Keep In Mind, Adoptions, and Join Rules.
- **Link Checker & maintenance tools:** dead-link/redirect checks,
  backlink verification, one-click URL updates, activity and error logs.
- **Security-first foundations:** Argon2id passwords, CSRF protection,
  prepared statements, rate limiting, and 1,650+ automated tests.