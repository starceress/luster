# Installing Luster

## Server Requirements

- PHP 8.2 or later (with extensions: `pdo`, `pdo_mysql`, `mbstring`, `finfo`, `zip`)
- MySQL 5.7+ or MariaDB 10.3+
- A web server with URL rewriting support (Apache with `mod_rewrite`, or nginx)
- A writable directory for uploads and logs

## Steps

### 1. Upload files

Upload all project files to your server. The recommended layout:

```
/home/youruser/public_html/luster/
  public/        ← point your document root here, or use a subdirectory alias
  src/
  templates/
  config/
  storage/
  ...
```

The web server should only be able to serve files from `public/`. Everything else
(`src/`, `config/`, `storage/`) should be outside the document root or protected
by your server configuration.

### 2. Copy the config file

```bash
cp config/app.sample.php config/app.php
```

Edit `config/app.php` and fill in:
- `db_host`, `db_name`, `db_user`, `db_pass` — your database credentials
- `app_url` — the full public URL to your `public/` directory, no trailing slash
  (e.g. `https://example.com/luster/public`)
- `site_name` — your collective or site name
- `owner_email`, `owner_name` — contact details

### 3. Install via browser

Visit `public/install/` in your browser (e.g. `https://example.com/luster/public/install/`).

The installer will:
1. Check your PHP version and required extensions
2. Test the database connection
3. Create all required database tables
4. Prompt you to create an admin account (username, email, password — minimum 12 characters)
5. Show an "Installation Complete" screen

### 4. Restrict the installer

After installation completes, delete or password-protect the `public/install/` directory
to prevent unauthorized re-use.

```bash
rm -rf public/install/
# or restrict via .htaccess / nginx config
```

### 5. First login

Click "Go to Admin Panel" on the success screen, or navigate to your admin URL
(e.g. `https://example.com/luster/public/admin`).

Log in with the credentials you created in the installer.

### 6. Configure email (optional but recommended)

Luster sends email via PHP's built-in `mail()` function — no SMTP configuration
required. Most shared hosts have this enabled by default.

Set your **Owner Name** and **Owner Email** in Settings → General. These are used
as the From address on all outgoing mail (join confirmations, password resets, bulk
email). If Owner Email is blank, the From header is omitted and delivery depends
entirely on your host's mail configuration.

---

## Troubleshooting

**"Forbidden" or blank page**
- Make sure `public/` is the document root, not the project root.
- Check that `mod_rewrite` (Apache) or the equivalent rewrite rules (nginx) are active.
- Verify the `app_url` in `config/app.php` matches your actual URL.

**Database connection error**
- Double-check `db_host`, `db_name`, `db_user`, `db_pass` in `config/app.php`.
- Make sure the database already exists (Luster creates tables, not the database itself).

**After install, pages show a 404**
- Confirm `.htaccess` is being read (Apache: `AllowOverride All` in your vhost config).
- Check that `RewriteBase` in `public/.htaccess` matches your install path.
