# CodeSort Compatibility Bridge

Migrate your existing CodeSort fan codes pages to Luster.
The bridge auto-detects which listing to serve by reading CodeSort's
`$listing` variable and looking it up in Luster's external refs table.

---

## Setup

### 1. Download the bridge

Go to **Collective → Migration & Bridges** and click **CodeSort Bridge**.

The ZIP contains:
- `luster-bridge.php` — pre-configured with your Luster install path
- `show-codes.php` — fan codes display wrapper
- `show-donate.php` — code submission form wrapper

### 2. Import your data first

Run the **CodeSort import** in Luster's Import Wizard. This creates the
ID mapping between CodeSort listing IDs and Luster listing IDs in the
`lstr_listing_external_refs` table. Without this, the bridge cannot auto-detect
which listing to serve.

### 3. Unzip into your CodeSort folder

Extract the ZIP contents into the folder where your old CodeSort
`show-codes.php` and `show-donate.php` live.

### 4. Done

Your existing pages keep working:

```php
$listing = 3;
include '/path/to/codesort/show-codes.php';
```

The bridge reads `$listing`, looks it up in the external refs table, and
renders through Luster.

---

## Supported wrappers

| Old CodeSort file | What it renders |
|---|---|
| `show-codes.php` | Fan codes display (images grouped by size) |
| `show-donate.php` | Code submission / donation form |

---

## Manual override

If auto-detection doesn't work, set `$luster_listing_id` before the include:

```php
$luster_listing_id = 5;
include 'show-codes.php';
```

---

## Troubleshooting

**"Luster compat: listing not found"**
- Make sure you ran the CodeSort import first (Import Wizard).
- Set `$luster_listing_id` manually if the import wasn't used.

**Blank output**
- Check that `luster-bridge.php` is in the same folder as the wrapper files.
