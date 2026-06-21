# Listing Admin Compatibility Bridge

Migrate your existing Listing Admin fanlisting pages to Luster.
The bridge auto-detects which listing to serve by reading Listing Admin's
`$listid` variable and looking it up in Luster's external refs table.

---

## Setup

### 1. Download the bridge

Go to **Collective → Migration & Bridges** and click **Listing Admin Bridge**.

The ZIP contains:
- `luster-bridge.php` — pre-configured with your Luster install path
- All wrapper files (17 files covering per-listing, collective, and KIM)

### 2. Import your data first

Run the **Listing Admin import** in Luster's Import Wizard. This creates the
ID mapping between Listing Admin listing IDs and Luster listing IDs in the
`lstr_listing_external_refs` table. Without this, the bridge cannot auto-detect
which listing to serve.

### 3. Unzip into your Listing Admin folder

Extract the ZIP contents into the folder where your old Listing Admin
`show-join.php`, `show-members.php`, etc. live.

### 4. Done

Your existing fanlisting pages keep working — the bridge auto-detects which
listing to serve from `$listid`.

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
| `show-kim-members.php` | KIM members for a specific listing |
| `show-kim-update.php` | KIM update form |
| `show-kim-reset.php` | KIM password reset |

---

## Manual override

If auto-detection doesn't work, set `$luster_listing_id` before the include:

```php
$luster_listing_id = 5;
include 'show-join.php';
```

---

## Troubleshooting

**"Luster compat: listing not found"**
- Make sure you ran the Listing Admin import first (Import Wizard).
- Set `$luster_listing_id` manually if the import wasn't used.

**Blank output**
- Check that `luster-bridge.php` is in the same folder as the wrapper files.
