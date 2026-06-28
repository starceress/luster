# ✨ Luster
*Where fandoms can shine! ✦*

Luster is a free, open-source management script for fanlistings, cliques,
collectives, and other hobby listings. It is meant to feel familiar if you've
used other fanlisting scripts, but built for today's expectations: more control,
fortified security, and fine-tuned customization.

Run one listing or a whole collective from the same dashboard: owned & joined
listings, members, affiliates, codes, custom networks and categories, custom
fields, email templates, link checker, error and activity logs, and optional
add-ons for adoptions, join rules, keep-in-mind lists, wishlists, and news feeds.
Migrate your data seamlessly from other fanlisting scripts using an Import Data
Wizard, or use compatibility wrappers to bridge your transition from your
previous script to Luster.



## ✦ Overview & Unique Features
Nearly everything in Luster is edited and configured directly from your admin
panel. No need to create or upload additional files - the script takes care of
it for you.

- **One admin panel, multiple listings & types:** Run a single listing or a whole
  collective all side by side.
- **Guided migration & wrappers:** The Import Data Wizard brings over your
  data from other scripts. Compatibility wrappers keep your previous script working
  while you transition to Luster.
- **Dashboard to-do list:** See pending members, overdue listings, affiliate
  applications, code donations, adoption requests, and KIM signups right
  away when you sign in, with shortcuts to review or update each item directly
  from the dashboard.
- **Multiple admin users:** Give co-owners their own separate accounts and logins
  instead of sharing one password.
- **Admin panel customization:** Switch between light & dark mode, change your
  admin panel's appearance with different skins, create your own admin panel
  look, or download skins made by others in the community.
- **Custom networks & categories:** Use the built-in TFL/TAFL-style categories or
  add your own custom networks and category groups to accomodate other directories.
- **Customizable public embeds & live previews:** Add the script to your existing
  website with a small snippet of code, then customize each output with clickable
  tokens or the Embed Wizard, targetable CSS classes, and rendered previews.
- **Three-tier template system:** Set global defaults that apply to every
  listing, override per listing when needed, or reset everything back with one click.
- **Drag-and-drop reordering:** Rearrange codes and affiliates with drag-and-drop
  and have your live site reflect the exact order you chose.
- **Communication tools with live previews:** Send emails (bulk or single),
  create and save email templates, preview rendered email content before sending,
  and enable or disable outgoing emails on a global or per-listing scale.
- **Extra features when you want them:** Optional add-ons include news feeds,
  a wishlist, "keep in mind" lists, adoption applications, and join rules, so you
  only turn on the pieces your site needs.
- **Link Checker & maintenance tools:** Check dead links and redirects, review admin
  activity with IP tracking, and browse error logs.
- **Security-first foundations:** Modern password handling, CSRF protection, output
  escaping, safer upload/import handling, concealed install paths, and an automated
  test suite of 1,300+ tests to help protect your site by default.



## ✦ Requirements
- **PHP 8.1 or newer**
- **MySQL or MariaDB**
- **PDO and PDO MySQL extension**
- **PHP cURL extension**



## ✦ Installation
1. Head to the [Releases page][releases] and download the latest
   `luster-x.y.z.zip`.
2. Unzip it, then upload everything inside to a folder on your web host. Name
   the folder whatever you like, such as `luster`.
3. In your browser, go to `http://yoursite.com/luster/`, swapping in the folder
   name you chose/your specific Luster path.
4. Luster will take you to the setup wizard automatically. It checks your
   server, sets up your database tables, and creates your first admin account.
   No config file editing needed — it writes everything for you.
5. Once the wizard finishes, click **Proceed to Admin Panel**, then delete the
   `install/` folder for your own security.



## ✦ Admin Panel
To access your admin panel, go to `http://yoursite.com/luster/public/admin`
and log in with the username and password you set for Luster during
installation.



## ✦ Migrating from Another Script
Already running a fanlisting, collective, or clique with another script?
Luster includes an Import Data Wizard to help you seamlessly migrate your data
without rebuilding everything by hand.

Once you're logged into Luster, click **Import Data** in the navigation
sidebar. Choose your original source, enter your old database credentials or
upload the supported files, pick what you want to bring over, and Luster will
copy your listings, members, affiliates, categories, codes, images, and
related history into place for you.

Luster also includes compatibility wrappers for Enthusiast, Listing Admin, and
CodeSort that allow your old include paths to keep working while you transition,
so your existing pages don't break before you've had a chance to update them.
These can be found within the **Collective** section in the admin panel.



## ✦ Brief FAQ
For a more detailed FAQ and full guides, visit [luster.nu](https://luster.nu/).

- **Is Luster free?**
  Yes, all the features of this script are completely free of charge.

- **Why does Luster require PHP 8.1+?**
  Security is a priority for Luster. PHP 8.1 is the oldest version that still
  receives security updates from the PHP project. Requiring it means Luster
  can use modern security features to keep your site and your members' data
  protected.

- **Do I need to edit any configuration files?**
  Nope! The installation wizard asks for your database details and site info and
  writes the configuration file for you. You never touch a code file directly.

- **Can I run more than one listing with it?**
  Absolutely! That's what Luster is built around. Run a single fanlisting, a
  clique, or manage a whole collective of different listing types, all from the
  same login.

- **Will it work alongside my existing site?**
  Yup - Luster's public embeds are designed to be dropped into pages you
  already have. The Embed Wizard generates ready-to-copy snippets, compatibility
  wrappers keep your old include paths working, and every element has targetable
  CSS classes so your site's look and feel can stay exactly as it is.

- **What if something doesn't go as expected during setup?**
  Most hiccups come down to database details or the web folder pointing somewhere
  other than `public/`. If you're still stuck, [contact me](#-need-help-found-a-bug-etc)
  and let me know what you tried so I can help you figure it out!

- **Can you implement [insert fix]/[insert new feature]?**
  Your feedback means a lot and helps Luster grow! Please [contact me](#-need-help-found-a-bug-etc)
  and let's see what we can build together!



## ✦ Need Help, Found a Bug, etc?
- Click *Send a Report* in your Luster admin panel's navigation sidebar
- Use the *Contact Form* at [luster.nu](https://luster.nu/contact/)
- *Email me* directly at `support@luster.nu`
- Post at *The Fanlistings Message Board* at [board.thefanlistings.org](https://board.thefanlistings.org/)
- Via *GitHub* at [Luster's GitHub issues](https://github.com/starceress/luster/issues) (requires GitHub account)



## ✦ Credits
[Luster](https://luster.nu/) © [Star](https://dreamesque.net/) 2026.
Full credits are available on [luster.nu](https://luster.nu/credits/).



## ✦ License
Luster is open-source software released under the [GNU General Public License v3.0](LICENSE).
In plain terms: you're free to use it, change it, and share it — including
for your own fan site or collective — but any distributed version must also
remain open source under the same license. You may not use this code in a
proprietary or commercial product.

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