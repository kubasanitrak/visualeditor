# Visual Editor

An edit-what-you-see content editor for simple portfolio sites. Plain PHP, vanilla JS and CSS, MySQL/MariaDB. No Node, no build step.

Current state: **Phase 1, content editing.** Pages render from the database; a logged-in owner edits text, media, items and page settings in place.

**Running, updating or deploying a site: see [docs/howto.md](docs/howto.md).** After every update, run the installer.

## Structure

```
index.php              front controller: slug -> page -> render; loads /admin only if present
.htaccess              pretty URLs (all non-file requests -> index.php)
config/
  config.example.php   template for each environment
  config.php           local values (git-ignored, never shipped)
site/                  site runtime, works without /admin
  lib/db.php           PDO helper: db(), q()
  lib/render.php       loads pages + items, renders templates
  templates/           layout.php + one template per item type
  css/base.css         reset + design tokens (CSS variables)
  css/library.css      predefined style classes v1
  styles.manifest.json registry of style classes (the editor builds pickers from it)
  js/site.js           scroll-triggered animations
admin/                 admin core: removable, updatable
  install.php          migrations runner
  migrations/          NNN_name.sql, applied in order
uploads/               media (demo placeholders included)
docs/brief.md          project brief: scope, architecture, phases
docs/howto.md          how to install, update, migrate and deploy
CLAUDE.md              working notes for Claude Code
```

## Local setup (MAMP)

1. Start MAMP. Defaults assumed: Apache on **8888**, MySQL on **8889**, document root `/Users/kuba/Sites_MAMP`.
2. In phpMyAdmin, create the database `visualeditor00_DB` with collation `utf8mb4_unicode_ci` (skip this if it already exists).
3. Check `config/config.php`. It is set to `127.0.0.1:8889`, `root` / `root`.
4. Open http://localhost:8888/visualeditor/admin/install.php to create the tables, the demo content and the first admin account (from `admin` in `config/config.php`, stored as a password hash only).
5. Open http://localhost:8888/visualeditor/. You should see the demo portfolio page. http://localhost:8888/visualeditor/about tests slugs.

Phase 0 gate: rename `admin/` to `_admin/`, reload, and the site still renders.

## Live server

Target: PHP 8.4, MariaDB 10.5, Apache with `mod_rewrite`. First deploy, updates and moving content: [docs/howto.md](docs/howto.md#5-first-deploy-to-a-live-server).

## Data model

- `pages`: slug, title, meta, OG image, page settings (JSON)
- `items`: tree per page (`parent_id`, `sort`), `type`, `content` (JSON), `classes` (from the manifest), `settings` (JSON)
- `components`: reusable masters; `items.component_id` links instances
- `media`, `settings` (global, key -> JSON), `users`, `redirects` (old slug -> page), `schema_migrations`

Item types: `section`, `row`, `col` (containers) and `heading`, `text`, `image`, `video`.

## Adding a style

1. Add the CSS class to `site/css/library.css`.
2. Add one entry to the matching group in `site/styles.manifest.json`.

## Adding a migration

See [docs/howto.md, section 8](docs/howto.md#8-writing-a-new-migration-developers). Every new migration gets a row in the migration log there.
