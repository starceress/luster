# Changelog

All notable changes to Luster are documented in this file. For the full feature overview, see the [README](README.md).


## A Note on Updating

Future releases will include a packaged ZIP file and a matching checksum
file. Luster's admin panel can check for these and apply updates
automatically — it will never overwrite your `config/app.php`, `storage/`,
`public/uploads/`, or `vendor/` folders. If a release is missing one of
those files, the admin panel will let you know that a manual update is
needed and link you to the release page.


## [Unreleased]

**Archive listings as Closed or Adopted Out**
Instead of deleting a listing when it closes, you can now mark it as "Closed" or
"Adopted Out" and keep it in your panel in a read-only state with all its history
intact. Adopted-out listings can record who they went to and a link to the new home.
The public embeds surface the archived status automatically, and everything can be
reopened at any time if you need it back.

**Template packs — share, import, and copy templates between installs**
Your listing and collective templates can now be packed up and exported to a single
file. Hand that file off to a friend, move it to another install, or just keep it
as a backup. Importing shows you a before/after diff so you can see exactly what
will change before you commit. You can also copy templates from one listing to
another in a few clicks, all from within the admin panel.

**Template revision history**
Every time you save a template, the old version is quietly snapshotted in the
background. If you ever need to undo a change, open the revision history for that
template, pick the version you want, and restore it — no digging through backups
needed. You can also flush the history for a listing if you want to start clean.

**Remember me across multiple devices**
The "remember me" option at login now issues a separate token for each device
instead of one shared token for everything. Logging out on your phone no longer
ends your session on your laptop. Each device shows up individually so you can
revoke them one by one.

**TFL/TAFL approval URL lookup**
When editing a listing's TFL or TAFL approval status, a lookup button now searches
the relevant directory for you and fills in the approval page URL automatically.
You can also paste it in by hand if the lookup doesn't find it.

**Download a database backup from the admin panel**
A one-click database backup download is now available in Admin → Settings. It
exports a SQL file you can keep locally — no phpMyAdmin or server access required.

**Link Checker: what's actually at each URL**
The Link Checker now tells you what it found at each URL — whether it's an HTML
page, an image, a redirect chain, a server error, a timeout, and so on. Helps you
quickly sort which broken links need fixing from which ones are just being weird.

**Notes on joined listings**
Any notes or comments you added when you added a joined listing are now shown in
the admin joined-listings view, so you don't have to click into each one to see
what you wrote.

**Affiliates and codes section labels at the collective level**
The on/off toggles for the affiliates and codes sections now include the same label
and style options that individual listings already had. Re-enabling a section after
turning it off correctly restores whatever overrides you had set instead of wiping
them.

**Other fixes**
- Listing title is no longer marked as required on the server when editing — the
  form already showed it as optional, and it falls back to the listing subject if
  left blank.
- The template-pack modal now closes reliably with the Escape key instead of
  sometimes firing on the wrong keypress.
- Affiliate stats in collective templates are now split into sitewide-only and
  total-across-all-listings so you can show either number precisely.
- Fanatically import now brings over members who had pending profile updates, and
  handles add-on data more reliably when an add-on isn't yet installed.


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