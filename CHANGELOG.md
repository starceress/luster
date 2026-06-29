# Changelog

All notable changes to Luster are documented in this file. For the full feature overview, see the [README](README.md).


## A Note on Updating

Future releases will include a packaged ZIP file and a matching checksum
file. Luster's admin panel can check for these and apply updates
automatically — it will never overwrite your `config/app.php`, `storage/`,
`public/uploads/`, or `vendor/` folders. If a release is missing one of
those files, the admin panel will let you know that a manual update is
needed and link you to the release page.


### [1.0.0]

The first full release of Luster! A free, open-source management script
for fanlistings, cliques, collectives, and other hobby listings. If
you've used scripts like Enthusiast before, Luster should feel familiar
but with a modern foundation: one admin panel for everything, security
built in from the start, and a setup wizard that does the heavy lifting
for you.

**Highlights:**

- Full admin panel for listings, members, affiliates, codes, categories,
  emails, and joined listings — all from one place.
- 28 drop-in PHP embeds for every fan-facing page.
- Editable templates at global and per-listing levels with live previews.
- Five optional add-ons: News Feed, Wishlist, Keep In Mind, Adoptions,
  and Join Rules.
- Import wizard for Enthusiast, Listing Admin, Fanatically, BellaBuffs,
  CodeSort, and FanUpdate — plus compatibility bridges so old includes
  keep working.
- Dark/light mode, six bundled skins, and community skin support.
- Security-first: Argon2id passwords, CSRF, prepared statements, rate
  limiting, and 1,300+ automated tests.