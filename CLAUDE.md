# Visual Editor: notes for Claude

An edit-what-you-see content editor add-on for simple portfolio websites. **Read `docs/brief.md` first**: it holds the scope, settings levels, architecture and the phase plan, each phase with a gate.

## Status

- **Phase 0 (Foundation): done.** Pages render from the DB; style library v1 + manifest; migrations runner; demo seed; first admin account created by the installer.
- **Phase 1 (Content editing MVP): done.** Login at `/admin/` (email + password, session cookie `ve_admin`), `admin/bootstrap.php` injects the editor only for a logged-in owner, `admin/api.php` JSON API (CSRF header `X-VE-CSRF`), editor in `admin/assets/` (ES modules, no build). Component presets live in `site/components.json`.
- **Phase 2 (Styling from predefined classes): done** (gate passed Nov 24, 2026). Item Style panel, page style in the Page panel, Site panel for the global theme; all pickers come from `site/styles.manifest.json` (`groups` with `applies`, plus `theme.palette` and `theme.options`). Colours are stored as palette tokens (`"bg": "accent"` -> `var(--color-accent)`); the theme lives in `settings.theme` as `{mode, vars, dark}` and is rendered by `ve_theme_css()`. Server-side checks in `admin/lib/styles.php`.
- **Next: Phase 2b**: the component variant library (shared bank of `<family>/<variant>/` folders with `variant.json` + SCSS + optional template/JS/GSAP, picked per project by `admin/tools/setup.php`, compiled with Dart Sass) and header, footer & navigation as its first families (data in `settings`, menus link pages by id, checkbox-hack mobile menu). Then **Phase 2c** (typography, button and component tokens, fonts, Figma variables JSON import), then Phase 3 (layout & components). Details in `docs/brief.md` ("Component variant library" and the phase plan).

## Hard constraints

- Plain PHP + vanilla JS (ES modules) + CSS/SCSS. **No Node, React or bundlers, and no build step at runtime or on the server.** SCSS is compiled on the developer's machine with Dart Sass (standalone, not the old Ruby Sass at `/usr/local/bin/sass`); the compiled CSS is committed and deployed. GSAP is allowed, vendored, for component variants that need it. No Composer dependencies unless agreed.
- MySQL/MariaDB through PDO only (`site/lib/db.php`: `db()`, `q()`); always prepared statements.
- Targets: local MAMP (PHP 8.x, MySQL on 127.0.0.1:8889); live PHP 8.4 + MariaDB 10.5 on Apache with mod_rewrite. Deploy = upload a folder via FTP + `config/config.php`.
- **The admin core must stay detachable.** `site/` and `index.php` must never depend on `admin/`. `index.php` loads `admin/bootstrap.php` only if it exists. Removing `admin/` must leave a working site.
- Styling is applied only through classes registered in `site/styles.manifest.json` (SCSS in `site/scss/_library.scss`, compiled to `site/css/site.css`). New style = CSS + one manifest entry; new component variant = a folder in the bank with `variant.json`.
- Owner-editable values are CSS custom properties (runtime); SCSS variables only for structure and breakpoints. Variants degrade without JS where possible.
- Editor UI: minimal, namespaced (`ve-admin-` prefix for classes/ids so it never clashes with site CSS), simple icons (possibly pixelarticons mini). Destructive actions need a confirmation dialog; everything else applies in one step.

## Layout

```
index.php            front controller (slug -> page), optional admin hook
site/lib/            db.php, render.php (site runtime, no admin knowledge)
site/templates/      layout.php + items/<type>.php (section, row, col, heading, text, image, video)
site/scss/           main.scss + _tokens, _base, _components (generated), _library (style classes)
site/css/site.css    compiled from site/scss (Dart Sass, committed; never edit by hand)
site/components.json component presets the editor can add (item trees)
site/js/site.js      ES module: scroll-triggered animations, imports components.js
site/components/     installed component variants (copied by setup; never edit here)
site/project.json    variants installed + bank commit (written by setup)
admin/               admin core: install.php, migrations/, bootstrap.php, login/logout.php, api.php, lib/, assets/ (editor.js, editor.css, js/)
config/config.php    local settings + admin email/password hash. NOT in git; never commit it.
uploads/             media; uploads/demo/ holds seed placeholders
docs/brief.md        project brief
```

Rendered items carry `data-ve-id` and `data-ve-type`; the editor should find items through those.

## Data

Tables: `pages`, `items` (tree via `parent_id` + `sort`; `content`/`settings` are JSON in LONGTEXT; `classes` = space-separated manifest classes), `components`, `media`, `settings` (key -> JSON), `users`, `redirects` (old slug -> page, 301 after a slug change), `schema_migrations`.

- Schema changes = a new `admin/migrations/NNN_name.sql`; never edit an applied migration. Run the installer to apply. **Add a row to the migration log in `docs/howto.md`** (what it does, what happens without it), and keep site code working if the table is missing.
- In SQL seeds, avoid double quotes inside JSON string values (an escaped `\"` in a SQL literal breaks the JSON). Use unquoted HTML attributes or JSON-encode in PHP instead.
- Passwords: only `password_hash()` / `password_verify()`. Never store or log a plaintext password.

## Commands

- Operator manual (install, update, deploy, passwords, migrations): `docs/howto.md`. Keep it current when a step changes.

- Install / migrate: http://localhost:8888/visualeditor/admin/install.php (localhost only), or `php admin/install.php` with MAMP's PHP.
- Site: http://localhost:8888/visualeditor/ (also `/about`, and any unknown slug shows the 404 page).
- Lint: `php -l <file>` (system `php` is 7.4; use MAMP's `/Applications/MAMP/bin/php/php8.2.0/bin/php` to run tools).
- Component variants: `admin/tools/setup.php` (localhost page or CLI: no args = list, `install <id>...`, `update`); bank path in config `library_path`. Generates `site/scss/_components.scss`, `site/js/components.js`, `site/project.json`.
- Compile CSS: `sass --no-source-map --load-path=site/scss site/scss/main.scss site/css/site.css` (Dart Sass, `/opt/homebrew/bin/sass`; never the Ruby Sass in `/usr/local/bin`). Commit SCSS + compiled CSS together.
- Phase 0 check: rename `admin/` to `_admin/`, the site must still render, then rename it back.

## Working style

- Keep the brief's phase order and gates; check items off in `docs/brief.md` as they land.
- Small, focused commits.
