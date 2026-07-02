# Changelog

All notable changes to Luster are documented in this file. For the full feature overview, see the [README](README.md).


## A Note on Updating

Each release includes a packaged ZIP file and a matching checksum file.
Luster's admin panel can check for these and apply updates automatically
— it will never overwrite your `config/app.php`, `storage/`,
`public/uploads/`, or `vendor/` folders. If a release is missing one of
those files, the admin panel will let you know that a manual update is
needed and link you to the release page.


## [1.0.0] — 2026-07-02

The first full release of Luster! A free, open-source management script
for fanlistings, cliques, collectives, and other hobby listings. If
you've used scripts like Enthusiast before, Luster should feel familiar
but with a modern foundation: one admin panel for everything, security
built in from the start, and a setup wizard that does the heavy lifting
for you.

**Highlights:**

- Full admin panel for listings, members, affiliates, codes, categories,
  emails, and joined listings — all from one place.
- 29 drop-in PHP embeds for every fan-facing page.
- Editable templates at global and per-listing levels with live previews.
- Template packs: export your templates to a shareable file, import a
  pack from someone else with a before/after diff, copy templates between
  listings in one step, and roll back any template to a previous saved
  version with built-in revision history.
- Listing archive states: mark a listing as Closed or Adopted Out instead
  of deleting it. The listing stays in your panel in read-only mode with
  all its history intact, and public embeds surface the status automatically.
- Five optional add-ons: News Feed, Wishlist, Keep In Mind, Adoptions,
  and Join Rules.
- Import wizard for Enthusiast, Listing Admin, Fanatically, BellaBuffs,
  CodeSort, and FanUpdate — plus compatibility bridges so old includes
  keep working.
- Dark/light mode, six bundled skins, and community skin support.
- Remember me across multiple devices — each device gets its own token
  so logging out on one doesn't end your session on another.
- TFL/TAFL approval URL lookup: a built-in button finds the approval page
  URL from the TFL or TAFL directory so you don't have to hunt for it.
- One-click database backup download from Admin → Settings — no phpMyAdmin
  or server access required.
- Link Checker now shows what it found at each URL (HTML page, image,
  redirect, server error, timeout, and so on) so you can triage results
  at a glance.
- Notes and comments on joined listings are now shown in the admin view.
- Collective affiliates and codes section toggles now include the same
  label and style options that individual listings already had.
- Security-first: Argon2id passwords, CSRF, prepared statements, rate
  limiting, and 1,500+ automated tests.

**Other fixes**

- Listing title is no longer marked as required on the server when editing
  — the form already showed it as optional, and it falls back to the
  listing subject if left blank.
- The template-pack modal now closes reliably with the Escape key instead
  of sometimes firing on the wrong keypress.
- Affiliate stats in collective templates are now split into sitewide-only
  and total-across-all-listings so you can show either number precisely.
- Fanatically import now brings over members who had pending profile
  updates, and handles add-on data more reliably when an add-on isn't yet
  installed.
- Settings page reorganized: Embed Form URL moved to General, Reciprocal
  URL moved to Link Checker settings, and Install Path shown read-only in
  System Details. The separate Server Paths panel is gone.
- The update checker no longer shows "update available" when you're already
  on the latest version.
