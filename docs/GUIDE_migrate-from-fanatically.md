# Migrating from Fanatically to Luster

This guide walks you through moving your fanlisting collective from
Fanatically 1.x to Luster using Luster's built-in Import Data Wizard.
The wizard handles listings, members, affiliates, codes, joined listings,
categories, images, and optional add-on data in one go — no SQL editing
or file renaming required.

---

## Before you start

**What you'll need:**

- A working Luster install on your host (run through the Luster installer
  first; the import wizard is a post-install step).
- Your Fanatically database credentials — host, database name, username,
  and password. These are in your Fanatically `config.php` or equivalent.
- Optionally, a ZIP of your Fanatically template files if you want to
  import your custom display and email templates (see
  [Importing templates](#importing-templates) below).

**PHP and host requirements** are the same as for a normal Luster install:
PHP 8.1 minimum (8.2 or 8.3 recommended), MySQL/MariaDB, PDO, and cURL.

**Back up first.** Before importing, take a snapshot of your Fanatically
database and any uploaded images. The import wizard never writes to your
source database, but having a backup means you can always start over.

---

## Running the import

1. Log in to your Luster admin panel.
2. Click **Import Data** in the navigation sidebar (or go to
   **System Info → Import Data**).
3. Choose **Fanatically** as your source.
4. Enter your Fanatically database credentials and, if your Fanatically
   tables use a custom prefix, enter that prefix too (default is
   `fanatic_`).
5. Click **Test Connection**. Luster checks the credentials before going
   further.
6. Choose your scope: you can import all listings or selected ones. You
   can also pick an image migration strategy:
   - **Same-server copy** — fastest; works when Luster and Fanatically are
     on the same hosting account and you know the absolute path to
     Fanatically's uploads folder.
   - **Download from URLs** — Luster fetches each image over HTTP. Slower
     but works across different hosts.
   - **ZIP upload** — upload a ZIP of your Fanatically `uploads/` folder.
7. Review the **preview** screen, which shows a count of what will be
   imported and any warnings (for example, `afl` network keys that will be
   remapped to `tafl`, or KIM members who will need to reset their
   passwords).
8. Click **Run Import**. A progress log appears as Luster works through
   each data type. Errors on individual rows are noted in the log but do
   not stop the rest of the import.
9. When the import finishes, check the summary and address any warnings
   before going live.

---

## What transfers

| Data | Notes |
|---|---|
| Listings | Transferred with title, subject, URL (blank URL listings are preserved), network/approved-by field, category assignments, opened date, and status |
| Members | Email, name, country, URL, join date, approval state, and custom field values all transfer; bcrypt hashes are preserved and auto-upgraded to Argon2id on each member's next successful sign-in |
| Custom field definitions | Field names, labels, and types mapped to Luster's per-listing field schema |
| Custom field values | Per-member additional field values carried over with their mapped column names |
| Affiliates | Name, URL, and image transferred; fanlisting assignment mapped from Fanatically's JSON relationship to Luster's relational row |
| Joined listings | Name, URL, image, category, join date, and active state |
| Codes | Code images, sizes, categories, and donor info |
| Categories | Category names seeded into `lstr_categories` |
| Images | Listing images and affiliate images via your chosen migration strategy (same-server / URL download / ZIP) |
| Wishlist entries | Wishlist add-on auto-installed if not already active; entries mapped to Luster's wishlist schema |
| KIM entries | KIM add-on auto-installed if not already active; member records transferred (see note on passwords below) |
| Adoption applications | Adoption add-on auto-installed if not already active; applications transferred |
| Closed / Adopted Out status | Lifecycle state preserved in `lstr_listing_lifecycle`; public embeds surface the archived status automatically |
| Pending member updates | Transferred to Luster's pending updates queue for each listing |

**Add-on auto-install:** if the import finds wishlist, KIM, or adoption
data and the matching add-on is not installed, Luster installs it
automatically before importing the data — you won't lose anything because
an add-on wasn't pre-enabled.

---

## What does not transfer

| Data | Why |
|---|---|
| Filesystem display templates | Fanatically templates live in files, not the database. The DB-only import path has no access to them. Use the template ZIP import (see below) if you have a copy of your template files. |
| Email templates | Same reason as display templates. |
| Admin credentials | Not imported for security — create your admin account in the Luster installer. |
| SMTP settings | Not imported — re-enter your SMTP details in Luster's Settings panel. |
| `fansfansfans_upper_code` | No equivalent in Luster. |
| Per-listing hold columns (`hold_for_approval`, `hold_update_for_approval`) | Fanatically's own public flows ignore these columns; Luster maps the site-wide hold settings instead. |
| KIM member passwords | Fanatically stores these in bcrypt; Luster's KIM schema uses a different column layout. KIM members imported as new rows receive a blank password — they will need to use the password-reset flow after migration. Rows that match an existing Luster KIM member are not overwritten, so existing Luster KIM hashes are preserved. |

---

## What changes automatically

| Item | What Luster does |
|---|---|
| `afl` network key | Remapped to `tafl` — Fanatically uses `afl` but TFL's canonical key is `tafl`. Flagged in the import preview so you can review it before committing. |
| Member custom field JSON | Fanatically stores custom field values as JSON in a single column; Luster maps them to individual per-column schema fields. Flagged per field in the preview if the mapping is ambiguous. |
| bcrypt member password hashes | Carried over unchanged. On each member's next successful sign-in, Luster verifies the bcrypt hash and silently re-hashes it as Argon2id. No member action required. |
| `fansfansfans_id` | Stored in Luster's `lstr_listing_external_refs` table for reference. Not surfaced in the admin UI but available in the database if you need to trace a listing back to its original Fanatically/FansFansFans ID. |

---

## Importing templates

Fanatically's display and email templates are PHP files on your server,
not rows in the database. The DB import wizard cannot reach them.

If you want to carry over your template customizations:

1. Zip up your Fanatically `templates/` directory (or the relevant
   per-listing template folders).
2. In Luster, go to **Listings → [listing name] → Templates → Import
   Template Pack** and upload the ZIP.
3. Luster shows a diff preview of what would change before applying
   anything. Review it and apply what you want.

Luster's template token syntax uses single curly braces like `{fan_name}`
and `{listing_url}`. Fanatically uses a different placeholder format, so
template text will need some manual editing after import — the import step
brings the raw text across; it does not auto-convert placeholder syntax.

---

## After the import

Run through this checklist before pointing your domain to the Luster install:

- [ ] Spot-check a few listings: member counts, affiliate links, codes
- [ ] Verify listing URLs are correct (blank URL listings imported without
  a URL will show as independent/no approval-page link)
- [ ] Open the **Link Checker** and run it across affiliates and joined
  listings to find any dead or redirected URLs that existed before the migration
- [ ] Re-enter your SMTP settings in **Settings → Email**
- [ ] Create any additional admin accounts your co-owners need
- [ ] Tell KIM members that they'll need to reset their password — the
  import wizard cannot transfer KIM passwords
- [ ] Review import warnings in the log: `afl → tafl` remaps, field
  mapping notes, and any rows that were skipped due to duplicate keys
- [ ] Delete the `install/` folder if you haven't already

If anything looks off, check the **Error Log** in the admin panel — the
import writes per-row errors there, not just to the on-screen log.

---

## Getting help

- **Send a Report** in your Luster admin panel sidebar (fastest)
- Contact form at [luster.nu/contact](https://luster.nu/contact/)
- GitHub issues at [github.com/starceress/luster/issues](https://github.com/starceress/luster/issues)
