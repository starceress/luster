# Listing Admin Compatibility Bridge

Migrate your existing Listing Admin fanlisting pages to Luster.

---

## Setup

### 1. Drop in the wrapper files

Copy everything from `public/compat/listing-admin/` into the folder where
your old Listing Admin `show-join.php`, `show-members.php`, etc. live.
The new files replace the old Listing Admin ones.

### 2. Set the listing ID

Listing Admin wrappers need a Luster listing ID. On each fanlisting page,
set `$luster_listing_id` before the include:

```php
<?php
$luster_listing_id = 5;
$luster_path = '/path/to/luster';
include 'show-join.php';
?>
```

If `LUSTER_BASE` is already defined (e.g. from another Luster embed on the
same page), you can skip `$luster_path`.

The wrapper also checks Listing Admin's own `$listid` variable as a fallback,
so if your pages already set that, it may work without changes.

### 3. Done

The old `include` calls keep working — they now render through Luster.

---

## Supported wrappers

### Per-listing

| Old Listing Admin file | What it renders |
|---|---|
| `show-join.php` | Join form |
| `show-members.php` | Members list |
| `show-update.php` | Update membership form |
| `show-reset.php` | Password reset form |
| `show-stats.php` | Listing stats |
| `show-real-stats.php` | Listing stats (alternative) |
| `show-affiliates.php` | Listing affiliates |
| `show-codes.php` | Fan codes display |
| `show-codes-form.php` | Code submission form |
| `show-updates.php` | Updates feed |

### Collective

| Old Listing Admin file | What it renders |
|---|---|
| `show-owned.php` | Owned listings |
| `show-joined.php` | Joined listings |
| `show-wishlist.php` | Wishlist |

### KIM (Keep In Mind)

| Old Listing Admin file | What it renders |
|---|---|
| `show-kim-join.php` | KIM sign-up form |
| `show-kim-members.php` | KIM list |
| `show-kim-update.php` | KIM update form |
| `show-kim-reset.php` | KIM password reset |

---

## Styling

Add Luster's public CSS to your page for styled output:

```html
<link rel="stylesheet" href="https://example.com/luster/public/assets/css/default/style.css">
```

---

## Troubleshooting

**"Luster compat: $luster_listing_id not set"**
- Set `$luster_listing_id` to your listing's ID in Luster before the include.
- Or set Listing Admin's `$listid` variable if your page already uses it.

**"Luster compat: set $luster_path or define LUSTER_BASE"**
- Add `$luster_path = '/absolute/path/to/luster';` before the include.
