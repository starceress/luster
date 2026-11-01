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


### [Unreleased]

**Fixed:**
- **Listing Admin imports found nothing if you left Table Prefix blank**, which
  is exactly what the Options page told you to do. Turns out every Listing
  Admin install has a prefix (`trex_` unless you changed it), so the import was
  looking for tables that weren't there. Now Luster finds your Listing Admin
  tables itself and fills the prefix in for you. If there's more than one
  install in the database, you pick which one. And if the prefix is wrong, it
  tells you which tables it couldn't find instead of showing a preview full of
  zeros.
- **Partial exports work too.** If your SQL file only has some of Listing
  Admin's tables (say, just the ones for codes), type the prefix and untick
  what you don't need. Luster only looks for the tables the ticked steps use.
- **Images from old sites that moved to `https://` come through now.** Lots of
  old scripts saved their image addresses as `http://`, and most sites send
  those to `https://` these days. Luster didn't follow that, so every download
  failed. Now it does, and it still checks every stop along the way. This helps
  every importer, not just Listing Admin.
- In a Listing Admin import, the **Server Path** and **Live Site URL** boxes
  get used even when Listing Admin has its own image settings. If those don't
  work anymore, Luster tries what you typed.
- No more "the Codes add-on was never turned on". If a codes, KIM or updates
  table isn't there, Luster just says so by name, and for an SQL upload
  reminds you to check the export has it. The preview also says images are
  copied automatically instead of "None".
- **A Link Checker scan that fails partway doesn't wipe your results anymore.**
  It used to throw away that listing's earlier check results and leave a
  half-finished list behind. Now a scan either finishes or changes nothing.
- **Members with the same email in different capitals don't break scans.**
  Some older member tables let `Jess@` and `jess@` both join, which made every
  scan of that listing fail. Luster now keeps one (the one with a website, if
  only one has it), skips the other, and lists who was skipped and why. That
  list stays on the page until you dismiss it, and Scan All doesn't reload the
  page on you while there's something to read.
- **Listing Admin's "0000-00-00" dates don't trip up imports anymore.** Some
  old servers saved "no date" that way. Depending on your database, those
  members, joined listings, KIM sign-ups or updates either didn't import at all
  or came over with a nonsense date. Now a member or joined listing with no
  real date just has no date, and a KIM sign-up or update gets the day you
  import it. A listing's "last updated" date also skips over bad member dates
  instead of copying one in.
- **Running the CodeSort import again doesn't pile up extra sizes, donors and
  categories anymore.** Picking "Update matching" skipped the check for ones
  you already had, so every run added another set. Now both choices look for
  what's already there first, and "Update matching" just copies over
  CodeSort's size order. Two donors with the same name but different websites
  stay two donors instead of getting merged, and if you gave a donor an email
  in Luster, the import makes its own instead of using yours.
- **Running the CodeSort import again doesn't add copies of your codes
  either.** Each code now remembers which CodeSort code it came from, so Luster
  finds it again even after its image was copied over under a new name. "Keep
  existing" leaves it alone, and "Update matching" brings its approval, size,
  donor and category up to date without copying the image again. (A code with
  no category in CodeSort keeps the ones you gave it in Luster.) New codes go
  after the ones a listing already has, instead of jumping to the top. Copies
  that older imports already made stay put, so you can delete the extras on the
  Codes page.
- **Codes from an import before this update** don't remember where they came
  from. If they came from this same CodeSort install, tick "Match codes from an
  earlier CodeSort import" on the Options page and Luster links up the ones it
  can pair exactly. Otherwise the preview tells you which ones might get added
  a second time. Old codes whose images were already copied can't be matched
  at all, so the import may add one more copy of those (just once). You can
  delete the older ones on the Codes page.
- If your CodeSort settings don't have a full web address for its images,
  Luster can't tell your install apart from another one, so it asks for a
  **Source ID** on the Options page before importing codes. Sizes, donors and
  categories still come over without one.
- **Codes that end up with no image get listed by name** on the results page,
  along with how to fix them: delete them, then run the import again with a
  working image path, web address or ZIP. Just know that deleting a code also
  removes anything you changed on it in Luster.
- **CodeSort import errors actually show up now.** The results page could say
  "Completed with Errors" without ever saying what went wrong, and the
  downloaded report didn't either. Now each problem is listed, like which code
  or image file it was and why.


### [1.0.0-3] — 2026-10-30

**After updating:** go to **Settings → System & Updates** and click **Run
migrations** once (next to "Migration state"). This release adds a little
database change that the Listing Admin codes import needs — until you run
it, the import will remind you.

**New:**
- **Listing Admin codes now import:** The Listing Admin import used to leave
  your codes (fan graphics) behind. Now they come over with their images,
  sizes, donors, categories, and approval status, into the right listing.
  Already imported everything else? Run the import again with only Codes
  ticked. You can run it as often as you like without getting copies, and
  if some images didn't make it the first time, running it again (a ZIP of
  your codes folder works well) fills in just those.
- **Source ID for Listing Admin imports:** If you've imported more than one
  Listing Admin install into the same Luster, each one now keeps its own set
  of listing links, so the compatibility bridge never mixes up which
  fanlisting to show. Most people never need to touch this.

**Fixed:**
- The Listing Admin bridge couldn't find your listings on its own after an
  import, so your old `show-join.php`-style pages needed each listing's ID set
  by hand. The import now saves the links the bridge needs. Already imported?
  Run the Listing Admin import again with only Owned listings ticked and Keep
  existing selected — it adds the links without changing anything else. If
  any of your pages pick their listing by hand with `$luster_listing_id`,
  download the bridge again from Admin → Collective first: older copies of
  the bridge let the new links override the listing you picked.
- On brand-new installs, the Link Checker's member check could fail partway
  through — or quietly lose track of members who don't list a website — until
  you ran the migration page once. Fresh installs now set things up correctly
  from the start. (If you've already run the migration page since updating,
  your site already has this fix.)


### [1.0.0-2] — 2026-10-25

A quick fix release.

**Fixed:**
- On some web hosts, pages with a form or anything else that needs to
  remember visitors between page loads — join, members, update, reset,
  contact, affiliates, wishlist, KIM, codes, adoption, and join rules — would
  just stop partway down, with nothing after the header showing up. Owned,
  joined, stats, and news pages were fine, which made it extra confusing.
  These now work everywhere. You don't need to change anything on your site:
  the one-time setup line you already pasted at the top of your header
  takes care of it after you update.
- If that setup line is missing (or has some HTML above it), those pages now
  show a short message explaining what to fix, instead of a half-blank page.


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