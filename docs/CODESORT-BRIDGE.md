# CodeSort Compatibility Bridge

Migrate your existing CodeSort fan codes pages to Luster.

---

## Setup

### 1. Drop in the wrapper files

Copy everything from `public/compat/codesort/` into the folder where
your old CodeSort `show-codes.php` and `show-donate.php` live. The new
files replace the old CodeSort ones.

### 2. That's it

Unlike Enthusiast and Listing Admin wrappers, CodeSort wrappers are fully
self-contained — they bootstrap Luster directly from their location inside
the Luster install directory. No bridge file or path configuration needed.

Your existing pages keep working:

```php
<?php
$listing = 3;
include '/path/to/codesort/show-codes.php';
?>
```

---

## How listing detection works

The CodeSort wrappers use a three-tier resolution strategy:

### 1. Explicit Luster ID (highest priority)

If you set `$luster_fanlisting_id` before the include, that ID is used
directly. This overrides all auto-detection.

### 2. CodeSort `$listing` variable

The wrapper reads CodeSort's `$listing` variable (an integer listing ID)
and looks it up in Luster's `lstr_listing_external_refs` table. This table
is populated automatically when you import from CodeSort via the Import
Wizard.

If no external ref mapping exists, the wrapper treats `$listing` as a
direct Luster listing ID (works when CodeSort and Luster IDs happen to
match).

### 3. URL-based fallback

If neither `$luster_fanlisting_id` nor `$listing` is set, the wrapper
detects the current page's URL and matches it against:
- Form URLs configured in Luster's per-listing settings
- The listing URL in the `owned` table

This works when codes pages live inside the fanlisting directory and the
listing URL is configured correctly in Luster.

---

## Supported wrappers

| Old CodeSort file | What it renders |
|---|---|
| `show-codes.php` | Fan codes display (images grouped by size) |
| `show-donate.php` | Code submission / donation form |

---

## Styling

Add Luster's public CSS to your page:

```html
<link rel="stylesheet" href="https://example.com/luster/public/assets/css/default/style.css">
```

---

## Troubleshooting

**"Luster compat: could not resolve a listing"**
- Set `$luster_fanlisting_id` explicitly before the include.
- Or ensure you ran the CodeSort import in Luster's Import Wizard, which
  populates the external ref mapping table.

**Codes show up but from the wrong listing**
- CodeSort and Luster listing IDs may not match. Run the CodeSort import
  to create the correct ID mapping, or set `$luster_fanlisting_id`
  explicitly.

**"Luster compat error: Luster is not installed correctly"**
- The CodeSort wrappers expect to live inside Luster's directory structure
  (`public/compat/codesort/`). Make sure the Luster install is intact and
  `vendor/autoload.php` exists (run `composer install` if needed).
