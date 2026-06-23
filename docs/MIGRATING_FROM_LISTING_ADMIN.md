# Migrating from Listing Admin to Luster

This guide is for fanlisting owners moving from Listing Admin to Luster.

The most important idea is this: Listing Admin keeps all members in one central
members table, while Luster keeps members in separate tables for each listing. The
import wizard handles that split for you.

## What This Guide Is For

Use this guide if your old site was managed by Listing Admin.

Listing Admin commonly stores data in tables such as:

```text
main
members
categories
affiliates
joined
updates
```

Some installs use a table prefix. For example, if your prefix is `la_`, your tables
might look like:

```text
la_members
la_categories
la_affiliates
```

You do not need to edit these tables by hand. The wizard reads them for you.

## Before You Start

Before importing, gather these things:

- A backup of your Listing Admin database
- A backup of your Luster database
- Access to your Luster admin panel
- Your Listing Admin database details, or an exported `.sql` file
- Your Listing Admin table prefix, if you used one
- Your old image folder path or live site URL, if you want images copied

If you want to import updates, wishlist entries, or KIM entries, install the matching
Luster add-ons before importing:

- News Feed add-on for updates
- Wishlist add-on for wishlist entries
- KIM add-on for KIM entries

## What Luster Can Import

The Listing Admin import can bring over:

- Owned listings
- Members
- Categories
- Affiliates
- Joined listings
- Updates, if the News Feed add-on is installed
- Wishlist entries, if the Wishlist add-on is installed
- KIM entries, if the KIM add-on is installed
- Listing, affiliate, joined, and wishlist images where available

Examples:

- A Listing Admin fanlisting becomes an owned listing in Luster.
- Members from Listing Admin's central members table are copied into the correct
  per-listing member table in Luster.
- Listing Admin updates can become Luster News Feed entries.

## What Luster Does Not Import

Luster does not import:

- Listing Admin admin accounts
- Listing Admin error logs
- Lyrics, quotes, or other LA-specific features that have no Luster equivalent
- Crossposting fields from Listing Admin updates
- Images embedded inside old HTML template text

If a feature requires an add-on and that add-on is not installed, Luster skips that
part and shows a note.

## Important: Import Listings Before Members

Listing Admin stores members in one big table. Luster needs to create one member
table per listing first.

That means listings must be imported before members.

The wizard warns you about this. In most cases, keep both **Owned listings** and
**Members** selected on the same import run.

If you import members without listings, Luster may skip the member import because it
has nowhere safe to put those members yet.

## Step-By-Step Import Instructions

### 1. Open the Import Wizard

In the Luster admin panel, open the import wizard.

The wizard has these steps:

1. Source
2. Connect
3. Options
4. Preview
5. Done

Choose **Listing Admin** on the **Source** step.

### 2. Connect to Listing Admin

On the **Connect** step, choose:

- **Live database connection**
- **Upload SQL dump**

Use **Live database connection** if your old Listing Admin database is still
available.

Example:

```text
Host: localhost
Database Name: mysite_listingadmin
User: mysite_dbuser
Password: your database password
```

Use **Upload SQL dump** if you exported the database from phpMyAdmin or with
`mysqldump`.

Example:

```text
listing-admin-backup.sql
```

### 3. Enter Your Table Prefix

On the **Options** step, enter your Listing Admin table prefix if you used one.

Examples:

| Your old tables look like | Enter this prefix |
|---|---|
| `main`, `members`, `categories` | Leave blank |
| `la_main`, `la_members`, `la_categories` | `la_` |
| `trex_main`, `trex_members`, `trex_categories` | `trex_` |

If you are not sure, look in phpMyAdmin at the table names.

### 4. Choose What To Import

For a normal full move, keep these selected:

- Owned listings
- Joined listings
- Categories
- Affiliates
- Members

Select these only if you use them and have installed the matching add-on:

- Updates
- Wishlist
- KIM List

### 5. Choose Image Sources

Luster can copy images into its own uploads folder.

You can provide:

- **Server Path**
- **Live Site URL**
- **ZIP Upload**

Listing Admin may store different image types in different places. Luster first tries
to read the old image locations from Listing Admin's options table.

If you need to enter a path manually, use the folder that contains the images.

Example:

```text
/home/username/public_html/listingadmin/images
```

If your old images are still online, you can use a live URL.

Example:

```text
https://example.com/listingadmin/images
```

If paths or URLs are difficult, upload a ZIP of the old image folders. Luster matches
images by filename.

### 6. Preview the Import

The **Preview** step shows what Luster found.

Example:

```text
Owned listings: 18 found, 18 to import
Members: 950 found, 950 to import
Affiliates: 42 found, 42 to import
```

Read any warnings carefully. They help you understand what will be skipped or changed.

### 7. Run the Import

When the Preview looks correct, run the import.

The **Done** step shows what was imported, skipped, or warned about.

## Concrete Examples

### Example: Default Listing Admin Tables

Your old database has:

```text
main
members
categories
affiliates
joined
```

On the Options step, leave **Table Prefix** blank.

### Example: Prefixed Listing Admin Tables

Your old database has:

```text
trex_main
trex_members
trex_categories
trex_affiliates
```

On the Options step, enter:

```text
trex_
```

### Example: Members And Custom Fields

Your Listing Admin members table has a custom field called favorite song. Listing
Admin stores those custom values inside `mExtra`.

Luster tries to read `mExtra` and place each value in the right Luster additional
field. If one member's custom field data is messy, Luster still imports the member
and shows a warning that the custom field values could not be parsed.

## After The Import

After importing:

1. Check **Owned Listings** in Luster.
2. Open a listing and check its members.
3. Check a few custom fields, if you used them.
4. Check affiliates and joined listings.
5. Check updates, wishlist, or KIM if you imported them.
6. Check images.
7. Test public join, update, remove, and reset pages.

If your old public pages used includes like `show-join.php`, see
[Embeds and Compatibility](EMBEDS_AND_COMPATIBILITY.md) for compatibility wrapper
options.

## Edge Cases

### Members Were Skipped

This usually means Luster did not have matching listing/member tables ready.

Run the import again with **Owned listings** selected, or import owned listings first
and then import members.

### The Table Prefix Is Wrong

If the Preview finds zero rows, check the table prefix.

For example, if your tables are:

```text
la_main
la_members
```

then the prefix should be:

```text
la_
```

### Custom Field Values Are Missing

Listing Admin stores custom member fields in `mExtra`. Luster parses this as best it
can.

If a row cannot be parsed, the member still imports, but custom field values for that
member may be blank.

### Updates Did Not Import

Updates require the Luster News Feed add-on. Install that add-on, then re-run the
import with **Updates** selected.

### Wishlist Or KIM Did Not Import

Wishlist requires the Wishlist add-on. KIM requires the KIM add-on.

Install the needed add-on and re-run the import with **Skip** selected.

### Images Did Not Copy

Try one of these:

- Enter a more direct image folder path.
- Enter a live URL to the old image folder.
- Upload a ZIP of the image folders.
- Check whether the files still exist.

The data can import even if some images do not.

## FAQ

### Will Luster change my old Listing Admin database?

No. Luster reads the old database and writes to Luster.

### Should I choose Skip or Overwrite?

Choose **Skip** for the first import. It is safer and lets you re-run the import
without replacing records already in Luster.

Choose **Overwrite** only when you want old Listing Admin values to replace values
already in Luster.

### Can I import only members?

Usually not as the first step. Members need owned listings to exist first.

If listings are already imported, you can run the wizard again and import members.

### Does Luster keep Listing Admin passwords?

Luster imports the stored password values where possible. When older password formats
are used, Luster upgrades passwords when members log in or reset them.

### What if I do not know my table prefix?

Look at your table names in phpMyAdmin.

If you see `members`, leave the prefix blank. If you see `la_members`, enter `la_`.

### Can I run the import more than once?

Yes. Use **Skip** when re-running so Luster keeps existing records and only adds
missing ones.

### When can I remove Listing Admin?

Wait until you have checked listings, members, images, forms, and any add-on data in
Luster. Keep a backup even after removing the old files.
