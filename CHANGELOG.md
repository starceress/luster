# Changelog

All notable changes to Luster are documented in this file. For the full
feature overview, see the [README](README.md).


## A Note on Updating

Each release includes a packaged ZIP file and a matching checksum file.
Luster's admin panel can check for these and apply updates automatically —
it will never touch your `config/app.php` or `storage/` folder, and
`public/uploads/` is always left alone too. It also cleans up old files that
aren't part of the new release anymore, and keeps your `vendor/` folder (the
bundled dependencies) up to date automatically. The one exception is
`public/.htaccess`: if you've customized it, Luster leaves it alone and saves
you a copy of the new default to look over instead of overwriting your
changes. If a release is missing one of the files an update needs, the admin
panel will let you know that a manual update is needed and link you to the
release page.


### [1.0.0-1] — 2026-10-25

A mix of new stuff, bug fixes, and a round of security hardening since 1.0.0.

**New:**
- **Previous Owners & Past Fanlistings:** Credit past owners on a listing (handy
  after an adoption), and a merged "Past Fanlistings" view that combines Closed
  and Adopted Out listings into one list.
- **Smarter auto-updater:** Updates now clean up old files that aren't part of
  the new release, keep `vendor/` current automatically, and won't silently
  overwrite a customized `public/.htaccess` — you'll get a copy of the new
  default to compare instead.
- **News Feed formatting:** Entries now support a small set of safe HTML tags
  (bold, italics, links, etc.) instead of plain text only.
- **Hidden image paths:** Uploaded images no longer reveal your install's
  folder structure in their URLs, same protection your CSS already had.

**Fixed:**
- An internal security audit turned up and patched several issues — SQL
  injection, SSRF, open redirects, and a couple of race conditions.
- The stats embed could go blank on archived (Closed/Adopted Out) listings,
  and the join rules gate could fail to actually save someone's agreement.
- Link Checker accuracy fixes: false "dead" results on dual-stack hosts,
  missed backlinks on two-level category-picker pages, silently skipped
  approved members with no URL, and results ignoring your configured date
  format.
- CodeSort image migration wasn't actually triggering during import.
- Merging duplicate members could lose the earlier join date.
- Drag-to-reorder on Codes wasn't saving.
- Affiliate approve/reject could lose its scope filter, and the
  affiliates/codes style toggle could silently override your global default.

**Polish:**
- Default theme colors reworked to meet WCAG AA contrast, plus better
  contrast on toasts and form hints.
- A wording pass over the admin panel — clearer copy, tighter forms, new
  tooltips.
- Dashboard's "At a Glance" card redone, and a lot of duplicate/dead CSS
  cleaned up under the hood.


### [1.0.0] — 2026-07-14

The first full release of Luster! A free, open-source management script
for fanlistings, cliques, collectives, and other hobby listings. If
you've used scripts like Enthusiast before, Luster should feel familiar
but with a modern foundation: one admin panel for everything, security
built in from the start, and a setup wizard that does the heavy lifting
for you.

**Highlights:**

- **One admin panel, multiple listings & types:** Run a single listing or a
  whole collective side by side.
- **Guided migration & wrappers:** Import Data Wizard plus compatibility
  wrappers for Enthusiast, Listing Admin, CodeSort, and Fanatically, so old
  includes keep working while you transition.
- **Dashboard to-do list:** Pending members, overdue listings, affiliate
  applications, and more, with shortcuts to handle each one.
- **Collective-wide member updates:** Members who've joined more than one of
  your fanlistings can update their info everywhere at once.
- **Multiple admin users:** Separate logins for co-owners instead of one
  shared password.
- **Admin panel customization:** Light/dark mode and five bundled skins,
  with community skin support in development.
- **Custom networks & categories:** Built-in TFL/TAFL categories, or add
  your own, plus a one-click TFL/TAFL approval URL lookup.
- **Customizable public embeds & live previews:** Drop-in embeds with
  clickable tokens, an Embed Wizard, and targetable CSS classes.
- **Three-tier template system:** Global defaults, per-listing overrides,
  one-click reset.
- **Template portability:** Export/import template packs, copy templates
  between listings, and roll back with built-in revision history.
- **Drag-and-drop reordering:** For codes and affiliates, reflected live
  on your site.
- **Communication tools with live previews:** Bulk or individual emails,
  reusable templates, per-listing on/off control.
- **Extra features when you want them:** Five optional add-ons — News
  Feed, Wishlist, Keep In Mind, Adoptions, and Join Rules.
- **Link Checker & maintenance tools:** Dead-link/redirect checks,
  backlink verification, one-click URL updates, activity and error logs.
- **Security-first foundations:** Argon2id passwords, CSRF protection,
  prepared statements, rate limiting, and 1,760+ automated tests.