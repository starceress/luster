# How Luster Protects Your Site

This page explains the security measures Luster uses to keep your site and
your members' data safe. It's written in plain language so you don't need a
technical background to understand what's happening behind the scenes.

---

## Passwords

**What Luster does:** When someone creates an account (admin or member),
Luster scrambles their password using a modern algorithm called Argon2id
before storing it. Even if someone somehow got access to your database, they
would see scrambled gibberish — not the actual passwords.

**What this means for you:** You never have to worry about passwords being
stored in a way that could be read by an attacker. Luster also automatically
upgrades old-style passwords (like the MD5 hashes used by Enthusiast) the
next time a member logs in — no action needed from you.

---

## Form Protection (CSRF)

**What Luster does:** Every form in Luster — joining, updating, deleting,
admin settings, everything — includes a hidden security token that proves the
form was actually loaded from your site. When the form is submitted, Luster
checks that the token matches. If it doesn't, the submission is rejected.

**What this means for you:** Nobody can trick your browser into making changes
to your site by hiding a fake form on another website. This kind of attack is
called "cross-site request forgery" (CSRF), and Luster blocks it on every
single form.

---

## Output Escaping (XSS Prevention)

**What Luster does:** Whenever Luster displays data that came from a user —
member names, listing titles, URLs, form inputs — it escapes special
characters so they show up as plain text instead of being treated as code.

**What this means for you:** If someone types something like
`<script>alert('hacked')</script>` into a form field, Luster shows it as
literal text on the page instead of running it as code. This prevents
"cross-site scripting" (XSS) attacks.

---

## Database Protection (SQL Injection)

**What Luster does:** Every database query in Luster uses "prepared
statements" — a technique where the query structure and the data are sent
to the database separately. The database knows exactly which parts are
instructions and which parts are data, so it can never be tricked into
running data as a command.

**What this means for you:** Even if someone enters something malicious into
a form field, it can never modify, delete, or expose your database. This is
the most common way websites get hacked, and Luster is designed to make it
impossible.

---

## Session Security

**What Luster does:** When you log in to the admin panel, Luster creates a
session cookie with strict security settings:
- **HttpOnly** — JavaScript on the page can't read your session cookie
- **Secure** — the cookie is only sent over HTTPS connections
- **SameSite=Strict** — the cookie is never sent with requests from other sites
- **Regeneration** — your session ID changes every time you log in, so an old
  session can never be reused

**What this means for you:** Your admin login is protected against session
hijacking, cookie theft, and replay attacks without you having to configure
anything.

---

## File Upload Safety

**What Luster does:** When you or a member uploads an image (listing banner,
affiliate button, code donation), Luster:
1. Checks the actual file content (not just the file extension) to make sure
   it's really an image
2. Generates a random filename so nobody can guess the file path
3. Stores uploaded files in a directory that blocks PHP execution — even if
   someone managed to upload a PHP file disguised as an image, it would never
   run

**What this means for you:** Uploading images is safe. Luster won't let
someone sneak a harmful file onto your server through an upload form.

---

## Outbound Connection Safety (SSRF Protection)

**What Luster does:** When Luster makes outbound connections — checking
affiliate links, fetching remote images during import, or verifying your
TFL/TAFL registration — it:
1. Resolves the hostname to an IP address and checks that it's a public
   address (not localhost or your internal network)
2. Pins the connection to that exact IP so the hostname can't switch to a
   different address between the check and the connection
3. Caps the response size so a hostile URL can't flood your server's memory

**What this means for you:** Features like the link checker and image import
can't be tricked into connecting to your own server or internal network,
even by a cleverly configured domain.

---

## Brute-Force Protection

**What Luster does:** Luster limits how many times someone can try the same
action from the same IP address. For example:
- Admin login attempts are rate-limited
- Password reset requests are rate-limited
- Public form submissions (join, contact, code donation) are rate-limited

After too many attempts, further requests are temporarily blocked.

**What this means for you:** Someone can't write a script to guess your admin
password by trying thousands of combinations. They'll be locked out after a
handful of wrong guesses.

---

## Bot Protection

**What Luster does:** Every public form includes:
- A **honeypot field** — an invisible field that real visitors never fill in,
  but automated bots do. If it's filled in, the submission is silently rejected.
- A **CAPTCHA** (on join, contact, code donation, and affiliate application
  forms) — a simple math question that humans can answer easily but automated
  scripts can't.

**What this means for you:** Spam bots have a much harder time flooding your
forms with junk submissions.

---

## Secure Password Resets

**What Luster does:** When a member requests a password reset:
1. Luster generates a 256-bit random token (extremely hard to guess)
2. Only a hash of the token is stored in the database — the actual token is
   sent only in the email
3. The token expires after 1 hour
4. The response is always the same ("if that email is registered, you'll
   receive a link") so attackers can't tell which email addresses are real

**What this means for you:** Password resets are safe from both guessing
attacks and email-enumeration attacks.

---

## Source Code & File Protection

**What Luster does:** Luster is designed so that only the `public/` folder
is meant to be visible to the internet. All of your PHP source code,
configuration, database backups, import temp files, and internal logic live
outside that folder where browsers can't reach them. On top of that, Luster
includes multiple layers of `.htaccess` protection:

- A **root `.htaccess`** blocks direct web access to everything outside
  `public/` — source code in `src/`, your `config/app.php` (which holds
  your database password), backup ZIPs in `storage/cache/`, Composer files,
  documentation, tests, and the `.git` directory are all denied
- A **`storage/.htaccess`** denies all access to logs, cache files, and
  temporary import data as a second layer of defense
- A **`config/.htaccess`** protects your configuration file specifically
- The **`public/uploads/` `.htaccess`** blocks PHP execution in the uploads
  directory so uploaded files can never run as code
- The **public stylesheet** is served through a PHP proxy (`?luster_css`)
  instead of a direct file link, so visitors never see where Luster is
  installed on your server
- Luster also strips comments from the served CSS to avoid leaking path
  hints or software identifiers

**What this means for you:** Even if someone knows your Luster URL, they
can't browse your PHP source code, download your configuration file (which
contains your database password), access backup ZIPs that include database
dumps, view your `.git` history, or see where Luster is installed on your
server. Your code and data stay private.

---

## Automated Testing

**What Luster does:** Luster has over 1,300 automated tests that check
everything from database queries to form validation to template rendering.
These tests run against every change before it's released.

**What this means for you:** Bugs and security issues are much more likely
to be caught before they ever reach your site. The test suite acts as a
safety net that makes sure new features don't accidentally break existing
security protections.

---

## Import Safety

**What Luster does:** When importing data from another script:
- Source database credentials are stored only in your session and cleared
  after the import finishes
- Luster never writes to the source database — it only reads
- SQL dump uploads are loaded into temporary tables with validated names
- Image imports validate file types and generate random filenames
- Each row is imported in isolation — one bad record doesn't abort the
  entire import

**What this means for you:** Importing from Enthusiast, Listing Admin,
Fanatically, or any other supported script is safe and won't damage your
existing data or expose your old database credentials.