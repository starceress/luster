# Changelog

All notable changes to Luster are documented in this file. Entries are
written in plain language so you don't need a technical background to
understand what changed.

For the full feature overview, see the [README](README.md).


## A Note on Updating

Future releases will include a packaged ZIP file and a matching checksum
file. Luster's admin panel can check for these and apply updates
automatically — it will never overwrite your `config/app.php`, `storage/`,
`public/uploads/`, or `vendor/` folders. If a release is missing one of
those files, the admin panel will let you know that a manual update is
needed and link you to the release page.


## [1.0.0]

The first full release of Luster! A free, open-source management script
for fanlistings, cliques, collectives, and other hobby listings. If
you've used scripts like Enthusiast before, Luster should feel familiar
but with a modern foundation: one admin panel for everything, security
built in from the start, and a setup wizard that does the heavy lifting
for you.

**Highlights:**

- One admin panel for managing owned listings, joined listings, members,
  affiliates, codes, categories, and email — across a single listing or
  an entire collective.
- Embed-only public frontend: 28 drop-in embeds for join forms, member
  lists, stats, codes, affiliates, wishlists, news feeds, and more.
- Three-tier template system with rendered previews: set global defaults,
  override per listing, and reset with one click.
- Embed Wizard for building ready-to-copy snippets with live previews.
- Drag-and-drop reordering for codes and affiliates.
- Five optional add-ons: News Feed, Wishlist, Keep In Mind, Adoptions,
  and Join Rules. Turn on only what your site needs.
- Dashboard with pending-item alerts and one-click shortcuts.
- Multiple admin accounts, dark/light mode, six bundled admin panel
  skins, and community skin support.
- Bulk and single email with reusable templates and live token previews.
- Link Checker for dead/moved affiliate and joined-listing URLs.
- Import Data Wizard for migrating from Enthusiast, Listing Admin,
  Fanatically, BellaBuffs, CodeSort, and FanUpdate.
- Compatibility bridges for Enthusiast, Listing Admin, and CodeSort so
  old page includes keep working while you transition.
- Security-first: Argon2id passwords, CSRF on every form, prepared
  statements everywhere, rate limiting, SSRF protection, and 1,300+
  automated tests.