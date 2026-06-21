# Enthusiast Compatibility Bridge

Migrate your existing Enthusiast fanlisting pages to Luster with zero code changes.
The bridge auto-detects which listing to serve by reading Enthusiast's `$listing`
variable (the dbtable name set by each fanlisting page's `config.php`).

---

## Setup

### 1. Download the bridge

Go to **Collective → Migration & Bridges** and click **Enthusiast Bridge**.

The ZIP contains:
- `luster-bridge.php` — pre-configured with your Luster install path
- All wrapper files (`show_join.php`, `show_members.php`, `show_update.php`,
  `show_lostpass.php`, `show_stats.php`, `show_affiliates.php`,
  `show_collective_affiliates.php`, `show_collective_stats.php`,
  `show_joined.php`, `show_owned.php`)

### 2. Unzip into your Enthusiast folder

Extract the ZIP contents into the same folder where your old Enthusiast
`show_join.php`, `show_members.php`, etc. live. The new files replace the
old Enthusiast ones.

### 3. Done

Your existing fanlisting pages continue to work with no code changes.
The old `include ENTH_PATH . 'show_join.php'` calls keep working as-is —
they now serve through Luster instead of Enthusiast.

---

## How it works

1. Your fanlisting page includes `config.php` (which sets `$listing = 'bes'`)
2. Then includes `show_join.php` (now a Luster compat wrapper)
3. The wrapper includes `luster-bridge.php`
4. The bridge reads the `$listing` variable, bootstraps Luster, and queries
   `SELECT listingid FROM owned WHERE dbtable = 'bes'`
5. Sets `$luster_fanlisting_id` automatically
6. The wrapper requires Luster's embed — the form renders through Luster

---

## Supported wrappers

### Per-listing (auto-detected from `$listing`)

| Old Enthusiast file | What it renders |
|---|---|
| `show_join.php` | Join form |
| `show_members.php` | Members list |
| `show_update.php` | Update membership form |
| `show_lostpass.php` | Password reset form |
| `show_stats.php` | Listing stats |
| `show_affiliates.php` | Listing affiliates |

### Collective (no listing ID needed)

| Old Enthusiast file | What it renders |
|---|---|
| `show_collective_affiliates.php` | Collective affiliates |
| `show_collective_stats.php` | Collective stats |
| `show_owned.php` | Owned listings |
| `show_joined.php` | Joined listings |

---

## Manual override

If auto-detection doesn't work for a specific page (e.g. the fanlisting page
doesn't set `$listing`), you can set the listing ID manually before the include:

```php
$luster_listing_id = 5;
include ENTH_PATH . 'show_join.php';
```

The bridge checks for `$luster_listing_id` first and skips auto-detection
when it's already set.

---

## Styling

The compat wrappers output Luster's HTML. To style the forms, add Luster's
public CSS to your page:

```html
<link rel="stylesheet" href="https://example.com/luster/public/assets/css/default/style.css">
```

Replace the URL with your actual Luster install URL.

---

## Troubleshooting

**"Luster compat: listing not found"**
- The `$listing` variable is not set or doesn't match any `dbtable` value
  in Luster's `owned` table. Check that your listing was imported correctly.

**Blank output**
- Check that `luster-bridge.php` is in the same folder as the wrapper files.
- Verify the `LUSTER_PATH` in `luster-bridge.php` points to the correct
  Luster install directory.
