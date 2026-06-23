# Image Migration

This guide is for Luster users who want to move old image files into Luster during
an import.

Image migration happens inside the import wizard. It copies images from your old
script into Luster's own upload folders.

Think of it like this:

```text
Old script:
https://example.com/enthusiast/affiliates/button1.gif

Luster:
public/uploads/affiliates/af_8f9a1c2d3e4b5a6f.gif
```

Luster copies the image and gives it a new safe filename. Your old image file is not
deleted or changed.

## What This Guide Is For

Use this guide when you are importing from an old fanlisting script and want Luster
to copy images for you.

Image migration may copy things like:

- Owned listing images
- Affiliate buttons
- Joined listing buttons
- Wishlist images
- Code or fan graphic images

The exact image types depend on the source you are importing from.

## Before You Start

Before migrating images:

1. Back up your old site files.
2. Back up your old database.
3. Back up your Luster database.
4. Keep the old image files available until after you check the import.
5. Decide which image method you want to use: server path, live site URL, or ZIP upload.

Do not delete the old site yet. It is much easier to retry image migration when the
old files are still available.

## Where Image Migration Happens

Image migration is part of the import wizard.

The import wizard steps are:

1. **Source**
2. **Connect**
3. **Options**
4. **Preview**
5. **Done**

The image fields are on the **Options** step.

Luster shows image migration options for sources that can include images. FanUpdate
does not use image migration because it imports update/blog text, not image files.

## What Luster Can Copy

Luster can copy image files that the source import knows about.

Examples:

- Enthusiast affiliate buttons
- Enthusiast joined listing images
- Enthusiast owned listing images
- Listing Admin affiliate, joined, listing, and wishlist images
- Fanatically listing, affiliate, joined, wishlist, and code images
- CodeSort code images

Luster accepts these image types:

```text
JPEG
PNG
GIF
WebP
```

Files that are not valid images are skipped.

## What Luster Does Not Copy

Luster does not copy:

- Images pasted inside old HTML template text
- Images from unrelated folders that the import does not know about
- Broken image links
- Files that no longer exist
- Non-image files
- Images hidden behind a login
- Images only visible on your computer but not available to the server

If an image is skipped, the related listing, affiliate, code, or wishlist entry can
still import. It will simply have no image attached.

## The Three Image Methods

Luster can find old images in three ways.

| Method | Best when |
|---|---|
| **Server Path** | Luster and the old files are on the same server. |
| **Live Site URL** | The old images are still visible online. |
| **ZIP Upload** | You have the image folders on your computer or your host blocks remote fetching. |

You can provide more than one fallback. If a ZIP is uploaded, the ZIP takes priority.

## Method 1: Server Path

Use **Server Path** when Luster can read the old files directly from the same server.

A server path is not the same as a website URL.

Examples:

```text
/home/username/public_html/enthusiast
/home/username/public_html/listingadmin/images
C:\xampp\htdocs\old-site\images
```

Use this when:

- Your old site and Luster are on the same hosting account.
- You are running both on local XAMPP.
- You know the folder path on the server.

Do not enter a URL in this field.

Wrong:

```text
https://example.com/enthusiast
```

Right:

```text
/home/username/public_html/enthusiast
```

## Method 2: Live Site URL

Use **Live Site URL** when the old images are still publicly reachable online.

Examples:

```text
https://example.com/enthusiast
https://example.com/listingadmin/images
https://example.com/codesort/images
```

Use this when:

- The old site is still online.
- The old image URLs work in your browser.
- Luster is allowed to fetch files over HTTP or HTTPS.

Luster only fetches normal public `http://` or `https://` URLs. Private or local
network URLs are not used.

Remote fetching can be slower than a server path. If your host blocks outgoing web
requests, use ZIP upload instead.

## Method 3: ZIP Upload

Use **ZIP Upload** when you have the old image files on your computer.

The ZIP upload accepts `.zip` files up to 50 MB.

The folder structure inside the ZIP does not need to be perfect. Luster matches
images by filename.

Example:

```text
old-images.zip
  affiliates/button1.gif
  affiliates/button2.gif
  joined/sailor-moon.png
  codes/50x50-001.gif
```

If the old database says the image is:

```text
button1.gif
```

Luster searches the ZIP for a file named:

```text
button1.gif
```

It does not matter whether the file is inside `affiliates/`, `buttons/`, or another
folder inside the ZIP.

## Which Method Should I Choose?

If you are not sure, use this order:

1. Try **Server Path** if Luster and the old images are on the same server.
2. Try **Live Site URL** if the old images are still online.
3. Use **ZIP Upload** if the first two are confusing or blocked.

For many users, ZIP upload is the easiest because you can gather the old image folders
on your computer and let Luster match files by filename.

## Step-By-Step Instructions

### 1. Start The Import Wizard

In the Luster admin panel, open the import wizard.

Choose your source on the **Source** step.

Examples:

- Enthusiast
- Listing Admin
- Fanatically
- CodeSort

### 2. Connect To The Old Data

On the **Connect** step, connect to the old database or upload the SQL dump.

Image migration needs the old database information because the database tells Luster
which image filenames belong to which records.

### 3. Open The Options Step

On the **Options** step, look for **Image Migration**.

Depending on the source, Luster may show image migration as automatic or optional.

If there is a **Migrate images** checkbox, leave it checked if you want images copied.

### 4. Enter Image Details

Choose the method that fits your old site:

- Enter a **Server Path**
- Enter a **Live Site URL**
- Upload a **ZIP** file

You can leave fields blank if Luster can read the old script's image settings. But if
you know the path or URL, entering it gives Luster a better fallback.

### 5. Preview The Import

Click **Preview Import**.

The Preview step shows what Luster found before it copies everything.

Look for warnings about images, paths, or missing files.

### 6. Run The Import

If the preview looks right, run the import.

The **Done** step shows:

- How many records were imported
- How many images were copied
- How many images failed
- Warnings for anything Luster could not copy

### 7. Check The Imported Images

After the import:

1. Open a few imported listings.
2. Check affiliate images.
3. Check joined listing images.
4. Check code images if you imported codes.
5. Check wishlist images if you imported wishlist data.

If some images are missing, keep reading. Missing images are often fixable.

## Source-Specific Notes

### Enthusiast

Luster can read common Enthusiast image settings automatically.

Common Enthusiast folders include:

```text
affiliates/
joined/
owned/
```

If you enter a server path for Enthusiast, enter the old Enthusiast install folder.

Example:

```text
/home/mika/public_html/enthusiast
```

Luster can then look inside common folders such as:

```text
/home/mika/public_html/enthusiast/affiliates
/home/mika/public_html/enthusiast/joined
/home/mika/public_html/enthusiast/owned
```

### Listing Admin

Listing Admin may store different images in different folders.

Luster tries to read Listing Admin's saved image paths first. If those old settings
are correct, you may not need to enter anything.

If you do enter a path manually, use the folder that contains the image files.

Example:

```text
/home/mika/public_html/listingadmin/images
```

If only some Listing Admin images copy, ZIP upload may be easier because Luster can
match files by filename across folders.

### Fanatically

Fanatically image paths are usually read from Fanatically's settings.

If you enter a fallback server path, use the old Fanatically install folder.

Example:

```text
/home/mika/public_html/fanatically
```

Fanatically imports can include several image types, such as listings, affiliates,
joined listings, wishlist entries, and codes.

### CodeSort

CodeSort images are usually fan code graphics.

The image folder is often the old `images_folder` value from CodeSort.

Examples:

```text
/home/mika/public_html/codesort/images
https://example.com/codesort/images
```

If you are unsure where the CodeSort images live, ZIP the CodeSort image folder and
upload it on the Options step.

### FanUpdate

FanUpdate does not use image migration in Luster's import wizard.

FanUpdate imports update/blog entries into the News Feed add-on.

## What Happens To Filenames

Luster does not keep the old filename.

Instead, it creates a new filename.

Example:

```text
Old filename: button1.gif
New filename: af_8f9a1c2d3e4b5a6f.gif
```

This helps avoid duplicate filenames and keeps uploaded files organized.

Luster stores migrated images in folders such as:

```text
public/uploads/listings
public/uploads/affiliates
public/uploads/joined
public/uploads/codes
public/uploads/wishlist
```

## What Happens When An Image Is Missing

Missing images do not stop the import.

If Luster cannot find or copy an image, it imports the record without that image and
adds a warning to the import report.

Example warning:

```text
Image not copied: button1.gif
```

After that, you can:

- Re-run the import with a better path, URL, or ZIP.
- Add the image manually in the admin panel.
- Leave the record without an image.

## Re-Running Image Migration

You can re-run an import if images were missing the first time.

For the safest retry:

1. Keep the conflict strategy on **Skip** unless you intentionally want to replace data.
2. Provide a better server path, live URL, or ZIP.
3. Run the import again.
4. Check the **Done** report.

In many cases, Luster can fill in image fields that were blank from the first run. If
an image still does not appear, add it manually in the related admin screen.

## Concrete Examples

### Example: Enthusiast On The Same Server

Your old Enthusiast install is here:

```text
/home/lee/public_html/enth
```

On the Options step, enter:

```text
Server Path: /home/lee/public_html/enth
```

Leave Live Site URL blank unless you want it as a fallback.

### Example: Old Site Still Online

Your old Listing Admin images are still visible here:

```text
https://example.com/listingadmin/images/button1.gif
```

On the Options step, enter:

```text
Live Site URL: https://example.com/listingadmin/images
```

### Example: Using A ZIP

You have a folder on your computer named:

```text
old-fanlisting-images
```

Inside are folders like:

```text
affiliates
joined
owned
codes
```

Compress that folder into:

```text
old-fanlisting-images.zip
```

Upload the ZIP on the Options step. Luster will match images by filename.

### Example: Local XAMPP

You are testing locally on Windows and your old files are here:

```text
C:\xampp\htdocs\old-enthusiast
```

On the Options step, enter:

```text
Server Path: C:\xampp\htdocs\old-enthusiast
```

This works because Luster is also running on the same computer.

## After The Import

After image migration:

1. Check the **Done** report.
2. Visit the admin pages for the imported data.
3. Open several records with images.
4. Confirm images display correctly.
5. Keep the old image backup until you are sure everything looks right.

Do not judge the whole import by one missing image. A few missing files usually means
those files were missing, renamed, or stored in a different folder.

## Edge Cases

### I Entered A URL In Server Path

Use **Live Site URL** for URLs.

Server Path should look like:

```text
/home/username/public_html/old-site
```

or:

```text
C:\xampp\htdocs\old-site
```

### I Entered A Server Path In Live Site URL

Use **Server Path** for server folders.

Live Site URL should look like:

```text
https://example.com/old-site
```

### My ZIP Uploaded But Images Were Not Found

Luster matches files by filename.

Check whether the database expects:

```text
button1.gif
```

but your ZIP contains:

```text
button-1.gif
```

Those are different filenames.

### My ZIP Has Duplicate Filenames

If the ZIP has two files with the same filename in different folders, Luster may match
the first one it finds.

Example:

```text
affiliates/button.gif
joined/button.gif
```

If possible, use unique filenames before making the ZIP.

### The Old Website Blocks Hotlinking

If the live URL works in your browser but Luster still cannot fetch images, the old
server may block automated requests.

Use Server Path or ZIP Upload instead.

### The Image Is Too Large Or Not A Real Image

Luster checks that the file is a real JPEG, PNG, GIF, or WebP image. Invalid files are
skipped.

For ZIP and remote image copying, individual images over 10 MB may be skipped.

### Images Inside Old HTML Templates Are Missing

Luster only migrates image fields it knows from the old database or import source.

If an old layout template contains:

```html
<img src="layout/header.png" alt="">
```

that image is part of your old layout, not a listing/affiliate/code image. Move it
manually if you still need it.

### CodeSort Images Imported Without Width Or Height

CodeSort can sometimes fill width and height from the code size even when the actual
image file was not copied.

Check the image migration report to see whether the image itself copied.

### I Deleted The Old Images Too Soon

Restore them from backup if possible, then re-run the import or add images manually.

## FAQ

### Does image migration delete my old images?

No. Luster copies images. It does not delete the old files.

### Does Luster keep the old filenames?

No. Luster creates new randomized filenames to avoid collisions.

### Can I skip image migration?

For some sources, Luster tries image migration automatically because the old script
stores image settings in its database. For others, there may be a **Migrate images**
checkbox.

If you truly do not want images copied, turn off the checkbox if one appears. If the
source uses automatic image migration, let the import finish and remove unwanted
images afterward from the related admin screens.

### Which image method is safest?

Server Path is usually fastest when available. ZIP Upload is often easiest when you
are not sure what path your host uses.

### Can I upload more than one ZIP?

The wizard uses one uploaded ZIP at a time. If you upload another ZIP before running
the import, it replaces the previous ZIP for that import session.

### What is the ZIP size limit?

The ZIP upload limit is 50 MB.

### What if my image folders are bigger than 50 MB?

Use Server Path if the old files are on the same server. Otherwise, split the import
work by source where possible, or migrate the remaining images manually after import.

### Can Luster fetch images from my private computer?

No. A Live Site URL must be publicly reachable by the server running Luster.

If the files are only on your computer, use ZIP Upload.

### Can I fix missing images later?

Yes. You can re-run the import with better image settings or upload images manually in
the relevant admin screen.

### Why did the record import even though the image failed?

Because missing images should not block your data migration. Luster imports the data
it can and reports image problems separately.
