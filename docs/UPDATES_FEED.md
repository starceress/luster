# News Feed Add-On

This guide is for Luster users who want to use the News Feed add-on.

The News Feed add-on lets you post news, updates, and small blog-style entries for
your collective or for individual fanlistings.

Think of it like this:

```text
Collective update: "New layout and three new affiliates."
Listing update: "Added 12 new members to the Spirited Away fanlisting."
```

This guide explains the admin side of News Feed. It does not explain embeds yet.
When you are ready to show updates on your website, check the embed guide.

## What This Add-On Is For

Use News Feed when you want to post announcements inside Luster.

Updates can be:

- Collective-level updates
- Per-listing updates

An update can have:

- Title
- Date and time
- One or more categories
- Body text
- Author, saved from the admin account that posted it

Examples:

- "New collective layout"
- "Fanlisting moved to a new URL"
- "Member list updated"
- "Codes added"

## Before You Install

Before installing News Feed:

1. Make a backup of your Luster database.
2. Make sure your owned listings are already in Luster if you want per-listing updates.
3. Decide whether you want to import old updates from FanUpdate or another source.
4. Think about a few category names you may want to use, such as `Site`, `Members`, or `Codes`.

You can use News Feed with no categories, but categories make filtering easier.

## How To Install

1. Open the Luster admin panel.
2. Go to **Add-ons**.
3. Find **News Feed**.
4. Click **Install**.

After installation, Luster creates the updates storage it needs and adds News Feed
management screens to the admin panel.

You can open News Feed in either of these ways:

- Click **Manage** on the News Feed card in **Add-ons**.
- Click **News Feed** in the admin sidebar.

## How To Navigate The Admin Section

The News Feed admin area opens to the Updates Index.

The Updates Index shows:

- One collective row for site-wide updates
- One row for each owned listing
- The last update date
- The total number of updates
- **View** and **Add** actions

### Collective Updates

Use collective updates for site-wide news.

Examples:

- New collective layout
- New domain
- Site maintenance
- Multiple fanlisting updates in one announcement

To open them:

1. Go to **News Feed**.
2. Find the collective row.
3. Click **View**.

### Per-Listing Updates

Use per-listing updates for one specific fanlisting.

Examples:

- A listing reopened.
- Members were added.
- Codes were added.
- Affiliates were updated.

To open them:

1. Go to **News Feed**.
2. Find the listing row.
3. Click **View**.

### News Feed Settings

Open **News Feed Settings** from the News Feed toolbar.

You can set:

- **Entries Per Page**

This controls how many updates appear per admin page. The default is 10.

## How To Add An Update

You can add an update from the Updates Index.

### Add A Collective Update

1. Go to **News Feed**.
2. Find the collective row.
3. Click **Add**.
4. Enter a **Title**.
5. Choose the **Date**.
6. Add categories if you want.
7. Write the update body.
8. Click **Preview** if you want to check it.
9. Click **Post Update**.

### Add A Listing Update

1. Go to **News Feed**.
2. Find the listing.
3. Click **Add**.
4. Enter a **Title**.
5. Choose the **Date**.
6. Add categories if you want.
7. Write the update body.
8. Click **Preview** if you want to check it.
9. Click **Post Update**.

The author is filled from the admin account you are using. It is saved for
record-keeping.

## How To Edit An Update

1. Go to **News Feed**.
2. Click **View** for the collective or listing that owns the update.
3. Find the update.
4. Click **Edit**.
5. Change the title, date, categories, or body.
6. Click **Save Changes**.

You can also delete an update from the edit screen.

## How To Delete Updates

To delete one update:

1. Open the update list.
2. Click **Delete** beside the update.
3. Confirm the warning.

To delete several updates:

1. Check the boxes beside the updates.
2. Choose **Delete** from bulk actions.
3. Click **Apply to Selected**.
4. Confirm the warning.

Deleted updates are removed from Luster.

## How Categories Work

Categories are optional tags on updates.

When writing an update, type a category and press Enter or comma to add it.

Examples:

```text
Site
Members
Codes
Affiliates
Maintenance
```

You can add more than one category to an update.

## How To Uninstall

Before uninstalling:

1. Make a fresh database backup.
2. Copy or export any update posts you want to keep.
3. Remove or update public pages that depend on News Feed.
4. Make sure you do not need imported FanUpdate posts anymore.

To uninstall:

1. Go to **Add-ons**.
2. Find **News Feed**.
3. Click **Uninstall**.
4. Confirm the warning.

Uninstalling removes update entries from Luster. This cannot be undone from inside
Luster.

## Concrete Examples

### Example: Collective Update

You redesigned your whole site.

Use a collective update:

```text
Title: New collective layout
Category: Site
Body: I updated the collective layout and refreshed the joined listings page.
```

### Example: Listing Update

You added new members to one fanlisting.

Use a listing update:

```text
Listing: Spirited Away
Title: Member update
Category: Members
Body: Added 8 new members and updated 2 member URLs.
```

### Example: Backdated Update

You forgot to post an update last week.

Use the Date field to choose last week's date before posting.

```text
Date: 2026-05-30 14:00
```

The entry is saved with that date.

## Edge Cases

### I Do Not See News Feed In The Sidebar

Go to **Add-ons** and make sure News Feed is installed.

### I Do Not See Any Listing Rows

You may not have owned listings in Luster yet.

Add or import your owned listings first, then return to News Feed.

### I Posted To The Wrong Place

If you posted a collective update that should have been a listing update, create a new
update in the correct place and delete the old one.

The current admin panel lets you edit the content of an update, but it does not offer
a simple "move this update to another listing" button.

### Future-Dated Updates Are Not A Scheduler

The Date field lets you choose the date shown for the update. Treat future dates as
record dates, not as a promise that Luster will automatically publish later.

### My Category Is Misspelled

Edit the update and correct the category. Category suggestions come from categories
already used in updates.

### I Deleted An Update By Mistake

Restore from a backup or recreate the update manually.

## FAQ

### Is News Feed the same as FanUpdate?

No. FanUpdate is an older separate script. Luster's News Feed is Luster's built-in
add-on for update posts.

### Can I import FanUpdate posts?

Yes. Install News Feed first, then use the FanUpdate import guide.

### Can updates have comments?

No. News Feed stores update entries. It does not include a public comment system.

### Can I post one update to several listings at once?

Not currently. Use a collective update for site-wide news, or create separate
per-listing updates.

### Does deleting a listing delete its updates?

Treat listing deletion carefully. Before deleting a listing, check its updates and
copy anything you want to keep.

### How do I show updates on my website?

That belongs in the embed guide. This guide only covers installing and managing the
News Feed add-on inside Luster.
