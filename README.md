# ✨ Luster ✦
*Where fandoms can shine.*

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



## 🌟 Overview & Unique Features
Nearly everything in Luster is edited and configured directly from your admin
panel. No need to create or upload additional files - the script takes care of
it for you.

- **One admin panel, multiple listings & types.** Run a single listing or a
  whole collective, including cliques, webrings, shrines, and more, all side by
  side. Manage owned and joined listings, affiliates, codes, and member activity 
  from one place.
- **Dashboard to-do list.** See pending members, overdue listing
  updates, affiliate applications, code donations, adoption requests, and Keep In
  Mind signups right away when you sign in, with shortcuts to review or update 
  each item directly from the dashboard.
- **Multiple admin users.** Give co-owners or trusted helpers their own separate
  accounts and logins instead of sharing one password.
- **Admin panel customization.** Switch between light and dark mode, change your
  admin panel's appearance with different skins, create your own admin panel
  look, or download skins made by others in the community.
- **Custom networks & categories.** Use the built-in TFL/TAFL-style categories,
  add your own networks, or create custom category groups for collectives that do
  not fit neatly into one directory structure.
- **Customizable public embeds with editor previews.** Add member
  lists, stats, affiliates, codes, joined listings, latest listings, random
  listings, news feeds, wishlists, and more with a small include. Then customize
  each output with clickable tokens, targetable CSS classes, and rendered previews
  before changes reach your live site.
- **Embed Wizard for quick snippets.** Build ready-to-copy embed snippets with
  live previews and guided settings.
- **Three-tier template system.** Set global defaults that apply to every
  listing, override per listing when needed, and reset everything back with one
  click. Every template has a rendered preview so you can see exactly what your
  visitors will see before you save.
- **Per-listing customization.** Granular control over join settings, extra form
  fields, per-listing rules, stats blocks, affiliate output, code donations,
  contact forms, and redirect behavior per listing so each individual listing
  can function exactly the way you want it.
- **Drag-and-drop reordering.** Rearrange codes and affiliates in the admin
  panel with drag-and-drop, and have your live site reflect the exact order you
  chose.
- **Communication tools with live previews.** Send emails (bulk or single),
  create and save reusable email templates, preview rendered email content before
  it goes out, and enable or disable outgoing emails on a global or per-listing
  scale.
- **Extra features, when you want them.** Optional add-ons include news feeds,
  a wishlist, "keep in mind" lists, adoption applications, and per-listing join
  rules, so you only turn on the pieces your site needs.
- **Link checker, network directory, and more.** Check dead links and redirects,
  verify your TFL/TAFL registration status, review admin activity, browse error
  logs, and keep your collective tidy without living in the database.
- **Guided migration from other scripts.** The Import Data Wizard can bring over
  listings, members, affiliates, categories, codes, images, email templates,
  saved settings, and related history from other tools including Enthusiast,
  Listing Admin, Fanatically, BellaBuffs, CodeSort, and FanUpdate.
- **Security-first foundations.** Luster uses modern password handling, CSRF
  protection, careful validation, output escaping, safer upload/import handling,
  CSS served without exposing install paths, and an automated test suite of
  1,300+ tests to help protect your site by default.



## ⚙️ Requirements
- **PHP 8.1 or newer**
- **MySQL or MariaDB**
- **PDO and PDO MySQL extension**
- **PHP cURL extension**



## 🚀 Installation
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



## 🔮 Admin Panel
To access your admin panel, go to `http://yoursite.com/luster/public/admin`
and log in with the username and password you set for Luster during
installation.



## 🔄 Migrating from Another Script
Already running a fanlisting, collective, or clique with another script?
Luster includes an Import Data Wizard to help you seamlessly migrate your data
without rebuilding everything by hand.

Once you're logged into Luster, click **Import Data** in the navigation
sidebar. Choose your original source, enter your old database credentials or
upload the supported files, pick what you want to bring over, and Luster will
copy your listings, members, affiliates, categories, codes, images, and
related history into place for you.



## ❓ Brief FAQ
For a more detailed FAQ and full guides, visit [luster.nu](https://luster.nu/).

- **Is Luster free?**
  Yes, all the features of this script are completely free of charge.

- **Why does Luster require PHP 8.1+?**
  Security is a priority for Luster. PHP 8.1 is the oldest version that still
  receives security updates from the PHP project. Requiring it means Luster
  can use modern security features to keep your site and your members' data
  protected.

- **Do I need to edit any configuration files?**
  Nope! The installation wizard asks for your database details and site info, and
  writes the configuration file for you. You never touch a code file directly.

- **Can I run more than one listing with it?**
  Absolutely! That's what Luster is built around. Run a single fanlisting, a
  clique, or manage a whole collective of different listing types, all from the
  same login.

- **Will it work alongside my existing site?**
  Yup - Luster's public embeds are designed to be dropped into pages you
  already have. You can further customize the CSS classes down to every detail,
  so your site's look and feel can stay exactly as it is.

- **What if something doesn't go as expected during setup?**
  Double-check the steps above first — most hiccups come down to database
  details or the web folder pointing somewhere other than `public/`. If you're
  still stuck, [contact me](#-need-help-found-a-bug-etc) and let me know what
  you tried so I can help you figure it out!

- **Can you implement [insert fix]/[insert new feature]?**
  Of course! Your feedback means a lot to me and helps Luster grow! Please let me
  know by [contacting me](#-need-help-found-a-bug-etc) and let's see what we can
  build together!



## 💬 Need Help, Found a Bug, etc?
- Click *Send a Report* in your Luster admin panel's navigation sidebar
- Use the *Contact Form* at [https://luster.nu/contact.php](https://luster.nu/contact.php)
- *Email me* directly at `support@luster.nu`
- Post at *The Fanlistings Message Board* at [board.thefanlistings.org](https://board.thefanlistings.org/)
- Via *GitHub* at [Luster's GitHub issues](https://github.com/starceress/luster/issues) (requires GitHub account)



## 💖 Credits
[Luster](https://luster.nu/) © [Star](https://dreamesque.net/) 2026.
- Full credits are available on the [Luster credits page](https://luster.nu/credits.php).



## 📜 License
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