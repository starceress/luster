# ✨ Luster
*Where fandoms can shine! ✦*

Luster is a free, open-source management script for fanlistings, cliques,
collectives, and other hobby sites. It is meant to feel familiar if you've
used other fanlisting scripts, but built for modern standards: more control,
fortified security, and fine-tuned customization.

Run one listing or a whole collective from the same dashboard: owned & joined
listings, members, affiliates, codes, custom networks and categories, custom
fields, email templates, link checker, error and activity logs, and optional
add-ons for adoptions, join rules, keep-in-mind lists, wishlists, and news
feeds. Migrate your data seamlessly from other fanlisting scripts using an
Import Data Wizard, or use compatibility wrappers to bridge your transition
from your previous script to Luster.



## ✦ Overview & Unique Features
Nearly everything in Luster is edited and configured directly from your admin
panel. No need to create or upload additional files - the script takes care
of it for you.

- **One admin panel, multiple listings:** Run a single listing or a whole
  collective all side by side.
- **Guided migration & wrappers:** The Import Data Wizard brings over your
  data from other scripts. Compatibility wrappers keep your previous script
  working while you transition to Luster.
- **Dashboard to-do list:** See pending members, overdue listings, affiliate
  applications, code donations, adoption requests, and KIM signups right away,
  with shortcuts to review or update each item directly from the dashboard.
- **Multiple admin users:** Give co-owners their own separate accounts and
  logins instead of sharing one password.
- **Admin panel customization:** Switch between light & dark mode and
  customize your admin panel's look with different skins.
- **Custom networks & categories:** Use built-in TFL/TAFL categories or add
  custom networks and groups to accomodate other directories, plus a one-click
  lookup for a listing's TFL/TAFL approval page URL.
- **Customizable public embeds & live previews:** Drop the script into your
  site with code snippets, then shape each output with clickable tokens,
  the Embed Wizard, targetable CSS classes, and live previews as you edit.
- **Three-tier template system:** Set global defaults that apply to every
  listing, override per-listing when needed, or reset them with one click.
- **Template portability & history:** Export or import any set of templates,
  copy templates between listings in one step, and roll back any template to
  a previous version with built-in revision history.
- **Live drag-and-drop reordering:** Rearrange codes and affiliates with
  drag-and-drop and have your live site reflect the exact order you chose.
- **Communication tools with live previews:** Send emails to a single
  recipient, a selected few, or an entire group all at once with a dedicated
  bulk composer. Create and save reusable email templates, preview rendered
  content before sending, and enable or disable specific email types on a
  global or per-listing scale.
- **Extra features when you want them:** Optional add-ons include news
  feeds, a wishlist, "keep in mind" lists, adoption applications, and global and
  per-listing level join rules, so you only turn on the pieces your site needs.
- **Link Checker & maintenance tools:** Check dead links and redirects
  across affiliates, joined listings, and member URLs; verify that
  affiliates are linking back to you; update a moved URL to its new address
  in one click; review admin activity with IP tracking; browse error logs.
- **Security-first foundations:** Modern password protection, defenses against
  common web attacks, safe file uploads, hidden install files, and bot-blocking
  CAPTCHA plus rate limits on every join form — backed by 1,845+ automated
  tests. Autoupdater with security checks, and backups are always available and 
  one click away.



## ✦ Requirements
- **PHP 8.1 or newer**
- **MySQL or MariaDB**
- **PHP PDO & PDO MySQL extensions**



## ✦ Installation
1. Head to the [Releases page][releases] and download the latest
   `luster-x.y.z.zip`.
2. Unzip it, then upload everything inside to a folder on your web host. Name
   the folder whatever you like, such as `luster`.
3. In your browser, go to `http://example.com/luster/`, swapping in the
   folder name you chose/your specific Luster path.
4. Luster will take you to the setup wizard automatically. It checks your
   server, sets up your database tables, and creates your first admin
   account. No config file editing needed — it writes everything for you.
5. Once the wizard finishes, click **Proceed to Admin Panel**, then delete
   the `install/` folder for your own security.



## ✦ Admin Panel
To access your admin panel, go to `http://example.com/luster/public/admin`
and log in with the username and password you set for Luster during
installation.



## ✦ Migrating from Another Script
Once you're logged into Luster, click **Import Data** in the navigation
sidebar. Choose your original source, enter your old database credentials or
upload the supported files, pick what you want to bring over, and Luster
will copy your listings, members, affiliates, categories, codes, images, and
related history into place for you.

Luster also includes compatibility wrappers ("bridges") for Enthusiast,
Listing Admin, CodeSort, and Fanatically that allow your old include paths to
keep working while you transition, so your existing pages don't break before
you've had a chance to update them. These can be found within the
**Collective** section in the admin panel. Click the bridge for your script
and Luster downloads a zip pre-configured with your install path; just
extract and replace the old files.



## ✦ Brief FAQ
For a more detailed FAQ and full guides, visit [luster.nu](https://luster.nu/).

- **Is Luster free?**
  Yes, all the features of this script are completely free of charge.

- **Why doesn't Luster support PHP versions lower than 8.1?**
  Security is a priority for Luster. When Luster began development (early
  Dec 2025), 8.1 was the newest version still receiving security updates
  from the developers of PHP (as of today, it has reached end of life).
  Older PHP versions are even more outdated and can expose your server and
  members' data to known exploits. Requiring 8.1 minimum means Luster can
  keep you and your members as safe as possible.

- **Do I need to edit any configuration files?**
  Nope! The installation wizard asks for your database details and site
  info and writes the configuration file for you. You rarely touch a
  configuration file directly.

- **Can I run more than one listing with it?**
  Absolutely! That's what Luster is built around. Run a single fanlisting, a
  clique, or manage a whole collective of different listing types, all from
  the same login.

- **Will it work alongside my existing site?**
  Yup; Luster's public embeds are designed to be dropped into pages you
  already have. The Embed Wizard generates ready-to-copy and customizable
  snippets, compatibility wrappers keep your old include paths working, and
  every element has targetable CSS classes so your site's look and feel can
  stay exactly as it is.

- **What if something doesn't go as expected during setup?**
  Most hiccups come down to database details or the web folder pointing
  somewhere other than `public/`. If you're still stuck,
  [contact me](#-need-help-found-a-bug-etc) and let me know what you tried
  so I can help you troubleshoot.

- **How does Luster handle updates?**
  Luster's built-in update checker looks for new versions for you
  automatically. Before installing anything, it double-checks a security
  code (called a checksum) to make sure the update file isn't corrupted; if
  it doesn't match, the update won't proceed. Updates live on GitHub, a well
  established, independent code-hosting platform, so your update path is not
  dependent on any personal site or community server.

- **Can you implement [insert fix]/[insert new feature]?**
  Your feedback means a lot and helps Luster grow! Please
  [contact me](#-need-help-found-a-bug-etc) and let's see what we can build
  together!



## ✦ Need Help, Found a Bug, etc?
- **(Recommended)** Click *Send a Report* in your Luster admin panel's
  navigation sidebar.
- Use the *Contact Form* at [luster.nu](https://luster.nu/contact/)
- *Email me* directly at `support@luster.nu`



## ✦ Credits
[Luster](https://luster.nu/) © [Star](https://dreamesque.net/) 2026-present.
Full credits are available on [luster.nu](https://luster.nu/credits/).



## ✦ License
Luster is open-source software released under the
[GNU General Public License v3.0](LICENSE). In plain terms: you're free to
use it, change it, and share it. You cannot take Luster — with or without
modifications — and sell or distribute it as a closed-source, proprietary
product. You cannot repackage or rebrand Luster as your own script and
profit from it while denying others access to the source.

[![Tests][tests-badge]][tests-workflow]
[![License: GPL v3][license-badge]][license]
[![PHP][php-badge]][composer]

[releases]: https://github.com/starceress/luster/releases
[tests-badge]: https://github.com/starceress/luster/actions/workflows/tests.yml/badge.svg
[tests-workflow]: https://github.com/starceress/luster/actions/workflows/tests.yml
[license-badge]: https://img.shields.io/badge/License-GPLv3-blue.svg
[license]: LICENSE
[php-badge]: https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg
[composer]: composer.json