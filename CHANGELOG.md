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

**After updating:** go to **Settings → System & Updates** and click **Run
migrations** once. This update makes four small changes to your database (see
below), and the migration page tells you if any of your listings were affected.

**Added:**
- **A closed or adopted-out listing's date can be "unknown".** If you don't
  know when a listing closed or was adopted out, tick **Date unknown** next to
  its Closed On / Adopted On date instead of making one up. Your Owned and Past
  templates can show it with `{if_archived_at_unknown}...{/if_archived_at_unknown}`
  (say, "closed some time ago"), and `{if_archived_at}...{/if_archived_at}`
  shows something only when there is a date. If a listing's date is unknown and
  you untick the box, Luster asks for the date instead of quietly using today.
  This needs the migration step above.
- **You can add a member yourself.** The Members page has a new **Add Member**
  button. Pick the listing, fill in the fan's details and any extra fields,
  and choose whether they're Approved or Pending. Luster makes up a password
  for them, just like the join form does when a fan leaves it blank, and they
  can pick their own later with Reset Password. Turn on **Email Fan?** to send
  them the approval email (Approved) or the join email with their password
  (Pending). It's off unless you switch it on. Closed and Adopted Out listings
  can't take new members, so they aren't offered.

**Changed:**
- **Every new member now waits for your approval.** The "Auto Approve Joins"
  switch on a listing's edit page is gone. When it was on, a fan who joined
  went straight into the member list, which meant anyone could add themselves
  without you looking first. Now all new joins land in the pending queue on
  the Members page, the same as they already did for most listings. Members you
  already approved stay approved. If any of your listings had the switch on,
  the migration page lists them so you know where to look.
- After joining, fans see "Your membership is awaiting approval" instead of
  "may need to be approved", because now it always does. If you customized
  your join success message with an `{if_joined}...{/if_joined}` part (the old
  "you're live" message), that part just won't show anymore. Nothing breaks.
- Fans who leave the password box empty no longer get a second "reset your
  password" email right after joining. Its link only ever worked for
  auto-approved members, so for everyone else it was a dead end. Their join
  email still includes the password Luster made up for them.

**Fixed:**
- **A listing that didn't save doesn't leave new categories behind.** If you
  picked a brand-new category or network on the Add or Edit listing page and
  the save was refused (say, the subject was missing), the new category was
  still created. Now it's only created once the listing actually saves.
- **Re-archiving a listing doesn't erase its notes anymore.** Picking "Archive
  as Closed" or "Archive as Adopted Out" from the bulk menu on a listing that
  was already closed or adopted out wiped its archive note and the adopted-to
  name and link. Now those stay put. Switching an Adopted Out listing to
  Closed still drops the adopted-to details, the same as the edit page does.
- **Previous owner links in capital letters save now.** A link like
  `HTTPS://example.com` is a perfectly good web address, but the listing edit
  page turned it away as "not a valid URL". It's saved just the way you typed
  it now.
- **The approval email fills in the fan's website, country and reset link.**
  If your approval email used `{fan_url}`, `{fan_country}` or `{reset_url}`,
  approving one member with the Approve button left those blank. They're filled
  in now, the same as in the join email. (Approve All and the bulk Approve
  still leave them blank for now.)
- **Extra fields named with only numbers don't break things anymore.**
  Enthusiast let you name an extra field something like `123`. On the table
  layout without `enth_` in front, a listing with a field like that crashed
  its Members page, Edit Member, the member list on your site and the join
  form. They all work now. (Making a brand-new field with a numbers-only name
  from Additional Fields still fails, so give new fields a letter for now.)
- **Joined listings brought over from Fanatically show up in your Random and
  Latest Joined boxes.** The import saved them with an old status name those
  two boxes don't look for. The import uses the right one now, and the
  migration fixes any already saved the old way.
- **Joined listings you've edited show up on their category page again.**
  Saving a joined listing on its edit page quietly lost part of its category,
  so it went missing from any category with a "/" in its name. That's every
  TAFL category ("Anime/Manga: ...") plus a few TFL ones like "Food/Drinks".
  The category dropdown still counted it, so a category could say 18 and only
  show 16. On the "All" page those listings had also slid down near the end.
  The edit page saves categories properly now, and running migrations tidies
  up the ones saved the old way. Joined listings with more than one category
  show up under each of them, not just the first.
- **A joined category's number always matches what you see when you open it.**
  "Cafe" and "Café" (or "cafe") used to show each other's listings, a really
  long category name could pull in listings from a shorter one, and a listing
  picked under the same category twice was counted twice.
- **Saving a joined listing brought over from Fanatically keeps its
  categories.** The edit page didn't recognise how the import labelled TFL and
  TAFL, so saving one wiped its categories. The import labels them properly
  now, and the migration fixes listings you've already imported.
- **Categories the dropdowns don't know about stay put.** If a listing has a
  network or category that isn't in the pickers anymore (like "Custom" from an
  import), the edit page still shows it, and saving keeps it.
- **Blank category rows don't shuffle your categories anymore.** On the joined
  and owned listing pages, leaving a Network & Category row empty above a
  filled one could save a category under the wrong network or drop it. And if
  saving a joined listing fails (say the URL is missing), the form comes back
  with the categories you picked, or none if you'd removed them all.
- **Running a Fanatically import again with "Overwrite" doesn't fail on
  members anymore.** Every member who was already there came back with an
  error. Now they get updated, and anyone who'd hidden their email or website
  keeps it hidden.
- **Fanatically's "lost password" email isn't offered for import anymore.** It
  sends fans a new password, but Luster's reset email sends a link instead, so
  an imported copy said "Your password is:" followed by nothing. The template
  preview now says to copy any wording you like over by hand.
- A Fanatically import now tells you, on the preview and the results page, if
  Fanatically was approving new joins automatically, since Luster won't.
- **Fanatically listings with no members yet get a member list anyway.** A
  listing with nobody in it (or any listing, if you left Members unticked)
  came over with nowhere to keep members and without its custom fields, so its
  join form and member list didn't work. Now every imported listing gets both
  straight away.
- **Custom fields called things like "Email" don't break a Fanatically import
  anymore.** A field named after something every member already has (Email,
  Name, Country...) or two fields whose names come out the same ("a-b" and
  "a b") stopped the import partway through. Now the extra one gets a new name,
  like `email_2`, and the preview and results tell you which ones moved. Luster
  remembers those names, so importing again gives every field the same one,
  even if the fields were reordered or a new one was added. A field you removed
  in Fanatically keeps its answers, and a field you added in Luster yourself is
  never written over: a new Fanatically field with the same name gets its own,
  like `color_2`. If you renamed a field in Fanatically, the answers members
  gave before the rename stay with the old field instead of jumping into the new
  one. Fields named with just numbers (like "123") import fine too. If Luster
  hasn't had the database update that remembers these names, or can't read or
  save them (or can't read its own listings and tables), the import stops before
  changing anything and tells you, instead of guessing.
- **A Fanatically listing can't end up using one of Luster's own tables.** A
  listing named after one (like "owned") could be handed that table to keep its
  members in. Now every table that already exists is off limits, and Luster
  won't change a table that isn't a member list.
- **Two Fanatically listings with the same address share one member list.**
  They become one listing in Luster, but the second one's members used to go
  into a table that listing never reads. Now both sets of members, and both
  listings' custom fields, land in the same place.
- **A listing that moved to a new address in Fanatically stays itself.** With
  Overwrite, a new listing that took its old address used to be poured into it.
  Now each one ends up where it belongs. And if Luster can't save a listing
  partway through, the import stops before the listings after it instead of
  guessing where they go, so you can fix it and import again.
- When you pick **Keep existing**, a listing that's already in Luster keeps its
  own fields and member table exactly as they are. Any values for fields it
  doesn't have are left out, and the results say how many.
- **KIM entries you hadn't approved in Fanatically stay pending.** The import
  used to make every KIM entry public, or every one pending. Now each one keeps
  the status it had in Fanatically, and you can still choose "all pending" or
  "all active" on the Options page.
- **KIM notes aren't lost.** Luster's KIM list has nowhere to keep them, so the
  results page lists every note for you to copy, and they're never shown
  publicly. They aren't saved anywhere in Luster either, so click **Download
  Report** before you leave the results page.
- **An affiliate with a broken list of fanlistings is skipped instead of showing
  on every page.** If Luster couldn't read which fanlistings an affiliate
  belonged to, it used to make it a site-wide affiliate. Now it's skipped and
  listed on the results page so you can add it by hand.
- **Fanatically's collective codes come over.** Codes saved under "Collective"
  used to be skipped. Now they import as collective codes with their size,
  approval and donor, even if you don't import listings, and importing again
  finds them instead of adding copies. Importing again also keeps the images
  Luster already copied for your codes, unless it copies a new one. Codes whose
  names only differ in capital letters (`Code.png` and `code.png`) stay two
  codes, and if Luster has more than one copy of a code and can't tell which
  one to update, it leaves them alone and tells you instead of guessing.
- **Closed and adopted-out Fanatically listings come over as Closed and
  Adopted Out.** They used to land as plain inactive listings, so they never
  showed up in your Past Fanlistings. Now they do. Fanatically doesn't keep
  the date a listing closed or who adopted it, so the date is marked unknown
  (see "Date unknown" above) and you can fill it in yourself. Importing again
  with "Overwrite" keeps the date and note you added, switches Closed to
  Adopted Out (or back) if you changed it in Fanatically, and reopens a
  listing that isn't closed in Fanatically anymore. If Fanatically gives a
  listing a status Luster doesn't recognise, it stays closed with everything you
  added, and the results page says why. "Skip" leaves them alone. If Luster
  can't save a listing's Closed or Adopted Out part, it doesn't save that
  listing at all, so a listing never ends up half-done (showing up as both
  current and past). That works on older databases that started out as
  Enthusiast too: Luster puts the listing back the way it was itself, and in
  the very rare case it can't, the results page tells you exactly what was
  saved. This needs the migration step above. Until it's run, the import tells
  you so before changing anything.
- **Previous owners come over from Fanatically.** If you listed the people who
  ran a listing before you in Fanatically, their names and links now come over
  to that listing's Previous Owners in Luster, in the same order. Importing
  again with "Overwrite" makes them match Fanatically (empty the list there and
  it's emptied here too), and "Skip" leaves the ones a listing already has in
  Luster alone. Two Fanatically listings with the same address share one list.
  A link that isn't a web address, or a name that's too long, is fixed up or
  left out, and the results page tells you. Older Fanatically versions that
  don't have previous owners leave Luster's alone. This needs the migration
  step above; until it's run, the import tells you before changing anything.
  And if something goes wrong partway and Luster can't undo it, the import
  stops right there instead of carrying on with things half-saved.
- **Fanatically listings with no category don't stop the import anymore.** A
  listing with no category failed to import, and every listing after it was
  skipped too. Listings with categories are also saved the same way the
  listing edit page saves them now, so they keep every category, not just the
  first. A listing in lots of categories keeps all of them in Luster; the older
  category field that Enthusiast-style pages read only has room for so many,
  and the results page tells you when a listing has more than fit. If a
  category can't be saved, the results page says so too, instead of the
  category quietly going missing.
- If your Luster database uses the older Enthusiast table layout (`enth_`
  tables), the Fanatically import now says it can't import into that layout
  yet, before changing anything, instead of saving listings where Luster's
  pages don't look.
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
- **Listing Admin addresses that start with `//`** (no `http:` or `https:`)
  now get the same Source ID as the full address. So switching between the two
  in your Listing Admin settings, or changing the capitals, doesn't make the
  import think it's a different install and add your codes again. Installs
  you've already imported keep the Source ID they have, so your bridge pages
  keep working. Two catches, and both only matter if the address started with
  `//` back when you imported: if it had capital letters in it and you've
  changed it since, or if you've since taken the `//` off without putting
  `http://` or `https://` in front, type your old Source ID on the Options page
  (it's listed there) before importing again. And if an earlier import already
  saved an install under two Source IDs, Luster now asks you which one to use
  instead of just picking one.


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