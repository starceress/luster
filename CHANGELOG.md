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

A note for anyone who's been running a pre-release copy: the members-list
`{sort_link_all}` template token was renamed to `{sort_link_date}`, and the
News Feed embed's tokens/CSS classes were renamed from the old `update_*`
wording to `newsfeed_*` (`{each_update}` → `{each_newsfeed_entry}`,
`{update_title}` → `{newsfeed_title}`, `{if_updates}` → `{if_newsfeed}`,
`.fl-update-item` → `.fl-newsfeed-item`, and so on across the whole set) —
update any custom templates using the old names.