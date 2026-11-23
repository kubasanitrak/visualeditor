# How to: run, update and deploy

Practical steps for keeping a Visual Editor site working, locally and live.
The short version: **after every update, run the installer.** It applies
whatever database changes are new and skips the rest.

Contents

1. [After every update: run the installer](#1-after-every-update-run-the-installer)
2. [Migration log](#2-migration-log)
3. [First local install (MAMP)](#3-first-local-install-mamp)
4. [Admin accounts and passwords](#4-admin-accounts-and-passwords)
5. [First deploy to a live server](#5-first-deploy-to-a-live-server)
6. [Updating a live site](#6-updating-a-live-site)
7. [Moving content between local and live](#7-moving-content-between-local-and-live)
8. [Writing a new migration (developers)](#8-writing-a-new-migration-developers)
9. [Removing the editor from a site](#9-removing-the-editor-from-a-site)
10. [Troubleshooting](#10-troubleshooting)

---

## 1. After every update: run the installer

Do this whenever the code changes: after `git pull`, after merging a branch,
or after uploading a new `admin/` folder to a live site.

**Locally**, open in the browser:

```
http://localhost:8888/visualeditor/admin/install.php
```

or run it from the project folder with MAMP's PHP (the system `php` on this
Mac is 7.4 and too old):

```bash
/Applications/MAMP/bin/php/php8.2.0/bin/php admin/install.php
```

**Live**: see [Updating a live site](#6-updating-a-live-site). The browser
installer only answers on localhost, so live needs SSH or phpMyAdmin.

What it prints:

- `skip 001_init (already applied)`: nothing to do for that one.
- `apply 003_redirects (1 statements)`: a new change was applied.
- `Database is up to date.`: everything was already in place.
- `FAILED …`: nothing after that migration ran. Fix the cause and run it again;
  migrations that already succeeded stay applied.

Running it twice is harmless. It never deletes content and never overwrites
an existing admin account.

## 2. Migration log

Every database change, in order. When a migration is added, add a row here.

| Migration        | Since          | What it does                                                                 | Notes |
|------------------|----------------|------------------------------------------------------------------------------|-------|
| `001_init`       | Phase 0        | Creates `media`, `pages`, `components`, `items`, `settings`, `users`.        |       |
| `002_seed_demo`  | Phase 0        | Inserts the demo pages (home, about, 404), demo images and theme settings.   | Runs on **any** empty database, live included. See [first deploy](#5-first-deploy-to-a-live-server). |
| `003_redirects`  | Phase 1 (gaps) | Creates `redirects`: old page addresses send visitors to the new one (301).  | Without it the site still works, but renamed pages lose their old address. |
| `004_palette_tokens` | Phase 2    | Switches the demo dark band (item 12) from raw colours to palette colours (`dark` / `light`). | Only touches that item, and only if it still has the original demo colours. Without it the band looks the same but ignores palette changes. |

To see what a database already has, look at the `schema_migrations` table in
phpMyAdmin: one row per applied migration.

## 3. First local install (MAMP)

1. Start MAMP (Apache on **8888**, MySQL on **8889**, document root
   `/Users/kuba/Sites_MAMP`, PHP 8.2).
2. In phpMyAdmin, create the database `visualeditor00_DB` with collation
   `utf8mb4_unicode_ci`.
3. Copy `config/config.example.php` to `config/config.php` and fill in the
   database (`127.0.0.1`, port `8889`, `root` / `root`), `debug => true`,
   and the admin email + password hash (see [section 4](#4-admin-accounts-and-passwords)).
4. Run the installer ([section 1](#1-after-every-update-run-the-installer)).
   It creates the tables, the demo content and the admin account.
5. Open http://localhost:8888/visualeditor/ for the site and
   http://localhost:8888/visualeditor/admin/ to log in.

## 4. Admin accounts and passwords

There is no password screen yet, so accounts are handled with a hash.
Passwords are never stored in plain text, only as a `password_hash()` value.

**Make a hash** (replace the password; run it in Terminal, not on a shared screen):

```bash
/Applications/MAMP/bin/php/php8.2.0/bin/php -r "echo password_hash('the-new-password', PASSWORD_DEFAULT), PHP_EOL;"
```

**First account**: put the email and the hash into `config/config.php`
under `admin`, then run the installer. It creates the account only if that
email does not exist yet.

**Change a password**: changing the hash in `config.php` does nothing for an
account that already exists. Instead, in phpMyAdmin run:

```sql
UPDATE users SET password_hash = 'PASTE-THE-HASH-HERE' WHERE email = 'owner@example.com';
```

**Add another account**: same idea, with an insert:

```sql
INSERT INTO users (email, password_hash) VALUES ('second@example.com', 'PASTE-THE-HASH-HERE');
```

**Remove an account**: `DELETE FROM users WHERE email = '…';`

The login page is `/admin/` (it forwards to `admin/login.php`). After
logging in, the editor bar appears at the bottom of every page.

## 5. First deploy to a live server

Target: PHP 8.4, MariaDB 10.5, Apache with `mod_rewrite`.

1. **Upload the files by FTP**: everything in the project folder except
   `config/config.php`, `.git/` and `docs/`. Include `uploads/` (it is not
   in git, so copy it from your local folder, not from a fresh clone).
2. **Create the live database** in the hosting panel (collation
   `utf8mb4_unicode_ci`).
3. **Create `config/config.php` on the server** from `config.example.php`:
   live database name, user, password, host (usually `localhost`), port
   (usually `3306`), `debug => false`, and the admin email + hash.
   Leave `base_url` as `null` unless the site lives somewhere unusual.
4. **Fill the database.** Pick one:
   - **Start from your local content (recommended).** Export the local
     database in phpMyAdmin and import it on live
     ([section 7](#7-moving-content-between-local-and-live)). It already
     contains `schema_migrations`, so live is up to date in one step.
   - **Start empty** with the installer (needs SSH, see section 6). Note
     that `002_seed_demo` puts the demo pages in; edit or delete them in
     the editor afterwards.
5. Make sure `uploads/` is **writable** by PHP (permissions 755 or 775,
   depending on the host), or uploads will fail.
6. Open the site, then `/admin/`, log in, and edit something small to check
   saving works.

## 6. Updating a live site

1. Upload the changed files by FTP. For an editor update that is usually
   the whole `admin/` folder (replace it), plus any changed files in `site/`,
   `index.php` or `.htaccess`. Never overwrite `config/config.php` or
   `uploads/`.
2. Check the [migration log](#2-migration-log) for migrations the live
   database does not have yet (compare with its `schema_migrations` table).
3. Apply them, one of two ways:
   - **With SSH**: `cd` to the site folder and run `php admin/install.php`.
   - **Without SSH (phpMyAdmin only)**: for each missing migration, in order,
     open `admin/migrations/NNN_name.sql`, paste its SQL into phpMyAdmin's
     SQL tab and run it. Then record it, so the installer knows later:
     ```sql
     INSERT INTO schema_migrations (version) VALUES ('003_redirects');
     ```
4. Reload the site, log in, and check that editing still works.

A live-friendly installer (run from the browser once logged in) is planned
for Phase 4 (*admin core versioning + update mechanism*). Until then, use
the steps above.

## 7. Moving content between local and live

Content lives in two places: the **database** (pages, text, settings) and
the **`uploads/` folder** (images and videos). Always move both together.

**Local → live** (or live → local, the other way round):

1. phpMyAdmin on the source: select the database, *Export*, format SQL,
   default options. Save the `.sql` file.
2. phpMyAdmin on the target: select the (empty or to-be-replaced) database,
   *Import*, choose the file. To replace existing content, drop the old
   tables first.
3. Copy the `uploads/` folder over by FTP (except `.htaccess`, which is
   already there).
4. Run the installer on the target if the code there is newer than the
   source database ([section 1](#1-after-every-update-run-the-installer)).

Admin accounts travel with the database. If you import a live database
locally, log in with the live password.

## 8. Writing a new migration (developers)

1. Create the next file, e.g. `admin/migrations/004_page_drafts.sql`.
   Numbers are three digits, applied in sort order.
2. Write plain SQL. One statement per `;` at the end of a line. Use
   `CREATE TABLE IF NOT EXISTS` and similar where possible so a re-run
   cannot fail.
3. Avoid double quotes inside JSON string values in SQL seeds (an escaped
   `\"` breaks the JSON). Use unquoted HTML attributes, or seed from PHP.
4. **Never edit a migration that has been applied anywhere.** Fix mistakes
   with a new migration.
5. Run the installer locally and check the output.
6. **Add a row to the [migration log](#2-migration-log)** with what it does
   and what happens on a site that does not have it yet.
7. If the site code (`site/`, `index.php`) depends on the new table, make it
   cope with the table missing, as `ve_redirect_target()` does. The site
   must never break because a live database is one migration behind.

## 9. Removing the editor from a site

When a project ends, delete (or rename) the `admin/` folder on the server.
The site keeps rendering from the database; the login, editor and API are
gone. Leave the database and `uploads/` as they are.

To check it locally: rename `admin/` to `_admin/`, reload the site, then
rename it back.

## 10. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `Database error … Run admin/install.php` on the site | Tables missing (new database) | Run the installer. |
| `Cannot connect to the database` from the installer | Wrong values in `config/config.php`, or MySQL not running | Check host, port, name, user, password; start MAMP. |
| `Installer is available on localhost only.` | Opened `install.php` on the live server | Use SSH or phpMyAdmin ([section 6](#6-updating-a-live-site)). |
| PHP syntax errors when running the installer from Terminal | System `php` (7.4) instead of MAMP's | Use `/Applications/MAMP/bin/php/php8.2.0/bin/php`. |
| Login says *Wrong email or password* for a known account | Hash in `config.php` changed after the account existed | Update the hash in the `users` table ([section 4](#4-admin-accounts-and-passwords)). |
| Uploads fail with *not writable* | `uploads/` permissions on the server | Make it writable by PHP (755 or 775). |
| Uploads fail with *too large* | PHP limits `upload_max_filesize` / `post_max_size`, or `upload_max_mb` in config | Raise the limit in the hosting panel or `config.php`. |
| Old page address shows 404 instead of redirecting | `003_redirects` not applied on that database | Apply it ([section 6](#6-updating-a-live-site)). |
| Editor bar missing after login | Old cached page, or `admin/` folder missing | Reload; check `admin/bootstrap.php` exists. |
