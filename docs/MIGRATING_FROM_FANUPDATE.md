# Migrating from FanUpdate to Luster

This guide is for fanlisting owners moving public FanUpdate blog entries into
Luster's News Feed add-on.

FanUpdate imports are smaller than the other imports. FanUpdate is not used for
members or owned listings here. It only moves public update/blog entries.

## What This Guide Is For

Use this guide if you used FanUpdate to post updates for your fanlistings or
collective.

FanUpdate commonly uses a main blog table named:

```text
blog
```

It may also use related tables:

```text
blog_category
blog_catjoin
```

If your FanUpdate table has a different name, the wizard lets you enter it.

## Before You Start

Before importing, gather these things:

- A backup of your FanUpdate database
- A backup of your Luster database
- Access to your Luster admin panel
- Your FanUpdate database details, or an exported `.sql` file
- The FanUpdate blog table name, usually `blog`
- The Luster News Feed add-on installed

The News Feed add-on is required. If it is not installed, Luster has nowhere to
put the imported updates.

## What Luster Can Import

The FanUpdate import can bring over:

- Public blog entries
- Entry title
- Entry body
- Posted date
- Fanlisting assignment when Luster can match the fanlisting title

Examples:

- A public FanUpdate entry called "New layout!" becomes a Luster update.
- If FanUpdate links that entry to "Final Fantasy VII" and Luster has a matching
  listing title, the update is attached to that listing.
- If no listing match is found, the update imports as a collective-level update.

## What Luster Does Not Import

Luster does not import:

- Non-public entries
- FanUpdate comments
- FanUpdate smilies
- FanUpdate display options
- FanUpdate admin accounts
- FanUpdate settings that do not belong in Luster's News Feed
- Other FanUpdate features such as news or events

This importer is intentionally focused on blog/update entries.

## Step-By-Step Import Instructions

### 1. Install The News Feed Add-On

Before importing, make sure the Luster News Feed add-on is installed.

If it is not installed, the import will be skipped with a warning.

### 2. Open the Import Wizard

In the Luster admin panel, open the import wizard.

The wizard has these steps:

1. Source
2. Connect
3. Options
4. Preview
5. Done

Choose **FanUpdate** on the **Source** step.

### 3. Connect to FanUpdate

On the **Connect** step, choose:

- **Live database connection**
- **Upload SQL dump**

Use **Live database connection** if your FanUpdate database is still available.

Example:

```text
Host: localhost
Database Name: mysite_fanupdate
User: mysite_dbuser
Password: your database password
```

Use **Upload SQL dump** if you exported the FanUpdate database.

Example:

```text
fanupdate-backup.sql
```

### 4. Enter The Blog Table Name

On the **Options** step, enter the FanUpdate blog table name.

Most installs use:

```text
blog
```

If your main table is named `updates`, enter:

```text
updates
```

Luster will look for the main table plus related tables based on that name.

Example:

| Blog table name you enter | Luster looks for |
|---|---|
| `blog` | `blog`, `blog_category`, `blog_catjoin` |
| `updates` | `updates`, `updates_category`, `updates_catjoin` |

### 5. Choose What To Import

FanUpdate has one import option:

- Blog entries to News Feed

Keep it selected.

### 6. Preview the Import

The **Preview** step shows how many entries Luster found.

Example:

```text
Updates (blog entries): 120 found, 110 to import, 10 skipped
```

Skipped entries are usually non-public entries.

### 7. Run the Import

When the Preview looks right, run the import.

The **Done** step shows how many updates were imported and skipped.

## Concrete Examples

### Example: Default FanUpdate Table

Your FanUpdate database has:

```text
blog
blog_category
blog_catjoin
```

Enter:

```text
blog
```

### Example: Custom Blog Table

Your FanUpdate database has:

```text
updates
updates_category
updates_catjoin
```

Enter:

```text
updates
```

### Example: Matched Fanlisting

FanUpdate category subject:

```text
Sailor Moon
```

Luster listing title:

```text
Sailor Moon
```

The update imports attached to that Luster listing.

### Example: Unmatched Fanlisting

FanUpdate category subject:

```text
SM
```

Luster listing title:

```text
Sailor Moon
```

Luster may not match these automatically. The update imports as a collective-level
update instead.

## After The Import

After importing:

1. Open the News Feed section in Luster.
2. Check recent updates.
3. Check a few older updates.
4. Check whether listing-specific updates are attached to the right listing.
5. Review collective-level updates.
6. Adjust titles or assignments manually if needed.

## Edge Cases

### The News Feed Add-On Is Not Installed

Install the News Feed add-on first, then re-run the FanUpdate import.

### Non-Public Entries Were Skipped

FanUpdate entries marked non-public are not imported.

This is expected. Luster only imports public entries from FanUpdate.

### Comments Are Missing

FanUpdate comments do not have a Luster News Feed equivalent, so they are not
imported.

### Smilies Are Missing

FanUpdate smilies are not imported. The update text itself still imports.

### Entries Imported As Collective-Level Updates

If Luster cannot match a FanUpdate fanlisting title to a Luster listing title, the
entry imports without a specific listing.

You can edit the update in Luster afterward if you want to attach it manually.

### Multiple Fanlisting Assignments

If one FanUpdate entry is linked to more than one fanlisting, Luster may create an
update row for each matched listing.

## FAQ

### Does this import members?

No. FanUpdate imports only update/blog entries.

### Does this import owned listings?

No. Import owned listings from Enthusiast, Listing Admin, or Fanatically first.

### Why does Luster need the News Feed add-on?

Because FanUpdate entries become Luster News Feed entries. Without the add-on,
there is no updates table to receive them.

### Should I import FanUpdate before or after listings?

If you want updates attached to specific fanlistings, import or create your owned
listings first.

If you only need collective-level updates, the listing match is less important.

### Can I run the FanUpdate import more than once?

Yes. Use **Skip** when re-running to avoid duplicating existing updates.

### Why did some entries skip?

Most skipped entries are non-public entries or entries already imported when using
the Skip conflict strategy.

### Can I import FanUpdate comments later?

Not with the current importer. FanUpdate comments do not have a matching Luster
feature.

### When can I remove FanUpdate?

Wait until you have checked your imported updates in Luster. Keep a backup even
after removing the old files.
