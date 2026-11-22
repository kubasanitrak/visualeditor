# Visual Editor: notes for Claude

An edit-what-you-see content editor add-on for simple portfolio websites. **Read `docs/brief.md` first**: it holds the scope, settings levels, architecture and the phase plan, each phase with a gate.

## Status

- **Phase 0 (Foundation): done.** Pages render from the DB; style library v1 + manifest; migrations runner; demo seed; first admin account created by the installer.
- **Phase 1 (Content editing MVP): next.** Login (email + password), edit-mode switch, inline text editing, media upload + tray, replace image/video, add/repeat items from a small component library, delete with confirmation, page slug + OG image.
- Styling choices (item/page/global settings from predefined classes) belong to **Phase 2**, not the MVP.

## Hard constraints

- Plain PHP + vanilla JS (ES modules) + CSS. **No Node, React, bundlers or build step.** No Composer dependencies unless agreed.
- MySQL/MariaDB through PDO only (`site/lib/db.php`: `db()`, `q()`); always prepared statements.
- Targets: local MAMP (PHP 8.x, MySQL on 127.0.0.1:8889); live PHP 8.4 + MariaDB 10.5 on Apache with mod_rewrite. Deploy = upload a folder via FTP + `config/config.php`.
- **The admin core must stay detachable.** `site/` and `index.php` must never depend on `admin/`. `index.php` loads `admin/bootstrap.php` only if it exists. Removing `admin/` must leave a working site.
- Styling is applied only through classes registered in `site/styles.manifest.json` (CSS in `site/css/library.css`). New style = CSS + one manifest entry.
- Editor UI: minimal, namespaced (`ve-admin-` prefix for classes/ids so it never clashes with site CSS), simple icons (possibly pixelarticons mini). Destructive actions need a confirmation dialog; everything else applies in one step.

## Layout

```
index.php            front controller (slug -> page), optional admin hook
site/lib/            db.php, render.php (site runtime, no admin knowledge)
site/templates/      layout.php + items/<type>.php (section, row, col, heading, text, image, video)
site/css/            base.css (tokens), library.css (style classes)
site/js/site.js      scroll-triggered animations
admin/               admin core: install.php, migrations/NNN_*.sql (Phase 1 adds bootstrap.php, api/, editor assets)
config/config.php    local settings + admin email/password hash. NOT in git; never commit it.
uploads/             media; uploads/demo/ holds seed placeholders
docs/brief.md        project brief
```

Rendered items carry `data-ve-id` and `data-ve-type`; the editor should find items through those.

## Data

Tables: `pages`, `items` (tree via `parent_id` + `sort`; `content`/`settings` are JSON in LONGTEXT; `classes` = space-separated manifest classes), `components`, `media`, `settings` (key -> JSON), `users`, `schema_migrations`.

- Schema changes = a new `admin/migrations/NNN_name.sql`; never edit an applied migration. Run the installer to apply.
- In SQL seeds, avoid double quotes inside JSON string values (an escaped `\"` in a SQL literal breaks the JSON). Use unquoted HTML attributes or JSON-encode in PHP instead.
- Passwords: only `password_hash()` / `password_verify()`. Never store or log a plaintext password.

## Commands

- Install / migrate: http://localhost:8888/visualeditor/admin/install.php (localhost only), or `php admin/install.php` with MAMP's PHP.
- Site: http://localhost:8888/visualeditor/ (also `/about`, and any unknown slug shows the 404 page).
- Lint: `php -l <file>`.
- Phase 0 check: rename `admin/` to `_admin/`, the site must still render, then rename it back.

## Working style

- Keep the brief's phase order and gates; check items off in `docs/brief.md` as they land.
- Small, focused commits.
