# Visual Editor – Project Brief

Nov 22, 2026 · @kubaS

## Overview & aim

A lightweight, edit-what-you-see visual editor that attaches to simple
websites, mostly portfolios, and lets the owner manage content and its
styling directly on the page.

- **Content:** text, images and video, placed and replaced in place on
  the live page layout.

- **Styling:** chosen from a predefined library of CSS classes (layout,
  typography, transitions, animations), never free-form CSS. Styling
  management arrives in Phase 2, after content editing.

- **Component variants:** layout components (header, menu button,
  navigation, later more) come from a growing, cross-project bank of
  variants written in SCSS + vanilla JS (+ GSAP). The developer picks
  variants per project at setup; the owner tweaks their options in the
  editor.

- **Architecture:** a reusable, updatable **admin core** that sits on
  top of project-specific **content**. If the admin layer is removed,
  the site keeps working as a plain website.

- **Target sites:** visual pages that rely on image positioning,
  responsive layouts, container queries and CSS-driven motion.

## Core features

Everything is edited on the rendered page itself. An **edit mode
switch** shows or hides the editor layer.

### Editing in place

- Edit what you see: text, captions and headings directly on the page.

- Replace an image or video in its container.

- Drag items to change their position.

- Layers panel for sorting and reordering items in a container.

### Components

- Add modules from a predefined component library.

- Repeat or duplicate a component.

- Save any component as a **reusable component**, so editing the master
  updates every instance.

- **Unlink** an instance so it can be edited on its own.

### Media

- Upload media to the project: multiple images and videos at once.

- Uploaded media is stored as thumbnails in a **media tray**.

- Two ways to place media:

  1.  **Container first:** add a row or column placeholder, pick media
      from a dropdown of uploaded items, and it fills the container.
      Then set width, aspect ratio and a predefined animation.

  2.  **Tray first:** pick a thumbnail from the tray and drop it onto
      the page.

- Image fit and position within its container (cover, contain, focal
  point).

### Page meta

- Custom slug per page.

- Social share image (OG thumbnail) per page.

## Settings levels

Settings cascade from global to page to item. The more specific level
overrides the broader one.

| Level  | Settings                                                                                                                            |
|--------|-------------------------------------------------------------------------------------------------------------------------------------|
| Global | Colour palette, background colour, light/dark mode, animation timing, border radius for media containers, buttons and form inputs   |
| Page   | Background colour, font colour, full width, predefined grid, parallax scrolling items, fullscreen slider                            |
| Item   | Background colour, font colour (caption if present), animation (fade-in, slide-in…), position (sticky, fixed…), width, aspect ratio |

Global settings map naturally to CSS custom properties (--color-bg,
--radius-media, --anim-duration), so a change updates the whole site
without touching markup.

## Styling & animation library

All styling is applied by toggling predefined CSS classes. The library
is defined upfront and grows as maintenance and development continue,
for example a scroll-reveal effect or fake parallax added later.

- **Layout:** grids, rows/columns, full width, container queries for
  responsive modules.

- **Media fit:** cover, contain, focal-point positioning, aspect ratios.

- **Typography:** predefined type scale and styles, responsive.

- **Transitions & animations:** fade-in, slide-in, scroll reveal,
  parallax, all as CSS classes, with JS only where needed (e.g. an
  IntersectionObserver to trigger reveals).

- **Position:** sticky, fixed.

Each class is registered in a manifest (name, label, group, icon), so
the editor UI builds its pickers from the manifest instead of
hard-coding them. Adding a style = add CSS + one manifest entry.

## Component variant library

Layout components with their own markup, styling and behaviour (header,
menu button, navigation; later sliders, galleries, footers) are built
as **variants** in a shared bank that grows with every project.

- **The bank** is a separate git repo used by all projects. One folder
  per variant: `<family>/<variant>/` with `variant.json`, a SCSS
  partial, an optional PHP template and an optional ES module (vanilla
  JS, GSAP where it helps).

- **Setup (developer, before editing starts)** picks the variants a
  project uses and copies them into the project, pinned to that
  version, so later changes in the bank never break a finished site.
  Only the chosen SCSS and JS end up in the site.

- **Variant vs option:**

  | | Who | When | How |
  |---|---|---|---|
  | Variant (sticky header that slides out on scroll, dots menu button, foldable navigation) | Developer | Setup | SCSS, JS, template |
  | Option (colours, blend mode, transition, easing, duration, animation) | Owner | Editor | Manifest classes and CSS variables declared in `variant.json` |

- **Tokens:** values the owner or a Figma import can change are CSS
  custom properties (runtime); SCSS uses them through `var(--…)`.
  SCSS variables are only for structure, mixins and breakpoints
  (compile-time, changed by recompiling).

- **Adding a variant** = a new folder in the bank, then setup + Sass
  compile in the project. The editor picks up its options from
  `variant.json` without code changes.

## Editor UI principles

- **Minimal:** the editor stays out of the way of the content. A
  floating toolbar and contextual item panels only.

- **Icons:** simple icons, possibly pixel-art from [Pixelarticons
  Mini](https://pixelarticons.com/mini/).

- **One-step edits by default:** changes apply immediately.

- **Two-step only for destructive actions:** delete (or similar) opens a
  confirmation dialog before the action runs.

- **Isolated:** editor CSS and JS are namespaced so they never clash
  with site styles.

## Architecture & tech stack

The system is split into a shared **admin core** and per-project
**content**, built with plain PHP, vanilla JS and CSS/SCSS. There is
no Node, bundler or server-side build: SCSS is compiled on the
developer's machine with Dart Sass and the compiled CSS is committed and
uploaded, so the runtime never needs a build step.

<img src="media/image1.png" style="width:6in;height:3.33928in"
alt="architecture · admin core over site content" />

architecture · admin core over site content

### Admin core vs content

- **Admin core:** editor UI, admin API, auth. The same package is copied
  into each project and can be updated on its own, with no ties to any
  project's content.

- **Content:** page templates, CSS class library, the variants copied
  from the bank, database records and media. It belongs to the project.

- **Component bank:** a third, shared part next to the admin core: the
  source of component variants. Projects copy from it at setup and do
  not depend on it at runtime.

- **Detachable:** when a project ends (client leaves, maintenance
  stops), the admin folder is removed or disabled. The site does not
  crash. Pages render from the DB as before and the editing layer is
  simply no longer there.

- Core updates ship as a versioned folder with DB migrations, so one
  improvement can be rolled out across all projects.

### Stack

| Layer           | Choice                                                                                             |
|-----------------|----------------------------------------------------------------------------------------------------|
| Server language | PHP (runs on MAMP and on standard shared hosting)                                                  |
| Front end       | Vanilla JS (ES modules) + CSS. Avoid Node, React and JS build tools                                |
| Styles          | SCSS compiled with Dart Sass (standalone, no Node) at development time; compiled CSS is deployed   |
| Motion          | CSS first; GSAP (vendored) for component variants that need it                                    |
| Database        | MariaDB / MySQL to start. PostgreSQL kept as an option via PDO                                     |
| Local dev       | MAMP on localhost (MySQL)                                                                          |
| Production      | MariaDB on the live server                                                                         |
| Deployment      | A build folder/package uploaded via FTP, plus a simple config file with DB name, user and password |

## Local dev setup

The project runs on MAMP at /Users/kuba/Sites_MAMP/visualeditor with the
MySQL database visualeditor00_DB (user root, password root, local only).

1.  **Configure MAMP:** set the document root to /Users/kuba/Sites_MAMP
    (or the project folder itself), PHP 8.2+, and note the ports (MAMP
    default: Apache 8888, MySQL 8889).

2.  **Create the database:** in phpMyAdmin (MAMP start page), create
    visualeditor00_DB with collation utf8mb4_unicode_ci.

3.  **Create the folder structure:**

    - /admin/: admin core (editor JS/CSS, API endpoints, auth,
      migrations). Self-contained and removable.

    - /site/: page templates, partials, the CSS class library and the
      style manifest.

    - /uploads/: media files and generated thumbnails (writable).

    - /config/config.php: DB host, port, name, user, password, base URL.
      Kept out of git, with a config.example.php beside it.

    - index.php: front controller that resolves slugs and renders pages,
      and loads /admin/ only if it exists and the user is logged in.

4.  **Connect with PDO:** one small db.php helper using PDO (host
    localhost, port 8889, charset utf8mb4). PDO keeps the
    MySQL/MariaDB/PostgreSQL switch open.

5.  **Run the first migration:** SQL files in /admin/migrations/ create
    the starter tables: pages, items (page, parent, order, type, content
    JSON, classes), components, media, settings, users.

6.  **Seed a test page:** one page with a few items, rendered by
    index.php, to confirm the stack end to end.

7.  **Version control:** git init, with .gitignore covering
    config/config.php and /uploads/.

8.  **Pretty URLs:** an .htaccess rewrite sending all non-file requests
    to index.php, so custom slugs work locally and on Apache hosting.

## Development phases

The MVP is content editing on fixed templates. Styling, layout freedom
and distribution follow in that order, and each phase ends at a gate
that must pass before the next starts.

<img src="media/image2.png" style="width:6in;height:2.1875in"
alt="roadmap · 5 phases, a gate after each" />

roadmap · 5 phases, a gate after each

Phases 2b (component variant library, header, footer & navigation) and
2c (design tokens & Figma import) were added after kick-off, between
Phase 2 and Phase 3; the roadmap image predates them.

### Phase 0: Foundation

- [x] Local setup as above: MAMP, DB, folder structure, config, PDO
  helper

- [x] DB schema v1 + migrations runner

- [x] Front controller + page renderer from DB (no admin)

- [x] CSS class library v1 + style manifest

- [x] One seeded portfolio page

**Gate:** the test page renders from the DB with the admin folder
absent. *Passed.*

### Phase 1: Content editing (MVP)

- [x] Login with email + password, sessions, logout

- [x] Edit mode show/hide switch; editor assets load only when logged in

- [x] Inline text editing, saved to DB

- [x] Media upload (multiple images and videos) + thumbnail tray

- [x] Replace image/video in a container (pick from tray)

- [x] Add items from a small predefined component library; repeat
  components

- [x] Delete with confirmation dialog

- [x] Page meta: custom slug, social share image

**Gate:** the site owner updates a portfolio page end to end without
touching code. *Passed (Nov 23, 2026).*

### Phase 2: Styling from predefined classes

- [x] Item settings: background, font colour, animation, position,
  width, aspect ratio, image fit/focal point

- [x] Page settings: background, font colour, full width, predefined
  grid

- [x] Global settings as CSS variables: colours, light/dark mode,
  animation timing, border radius

- [x] Pickers generated from the style manifest

**Gate:** a page can be fully restyled without editing CSS. *Passed
(Nov 24, 2026).*

### Phase 2b: Component variant library + header, footer & navigation

Header, menu button and navigation become the first families of a
**component variant library** (see [Component variant
library](#component-variant-library)). The developer picks variants
per project at setup; the owner edits content and the options each
variant exposes. Free-form header/footer layouts built from reusable
components still come in Phase 3.

**Library mechanism**

- [x] Variant format in the shared bank (`visualeditor_lib`, see
  [Component variant library](#component-variant-library); path set in
  `config.php` as `library_path`): one folder per variant,
  `<family>/<variant>/` with `variant.json` (id, label, family, needs
  such as `gsap`, editor options), a SCSS partial, optional template
  `<family>.php` (only when the markup differs from the family
  default) and an optional ES module.
  *Done in the bank (Nov 27, 2026): `core/` + families `header`,
  `menu-button`, `nav`, `footer`, each with `family.json` and a shared
  `_base.scss`; format documented in the bank's README. `library_path`
  arrives with the setup tool.*

- [x] Setup tool `admin/tools/setup.php` (CLI and localhost page, like
  the installer): pick variants per family, copy them into
  `site/components/<family>/<variant>/` (pinned; re-run to update),
  write `site/project.json` (chosen variants), the generated
  `site/scss/_components.scss` and `site/js/components.js`.
  *Done (Nov 27, 2026): also copies the bank's SCSS tools to
  `site/scss/core/` (breakpoints once, then project-owned), records the
  bank commit in `project.json` and recompiles `site.css`.*

- [x] SCSS pipeline: `site/scss/main.scss` → `site/css/site.css`,
  compiled with Dart Sass on the developer's machine; the compiled CSS
  is committed and uploaded. `base.css` and `library.css` move into
  SCSS partials (`_tokens`, `_base`, `_library`) so the site loads one
  stylesheet. Steps documented in `docs/howto.md`.
  *Done (Nov 27, 2026): compiled output matches the old two files rule
  for rule.*

- [x] Runtime stays build-free: `site.js` imports
  `components.js` (chosen variant modules only); GSAP vendored in
  `site/js/vendor/`, loaded only when a chosen variant needs it. Every
  variant works without JS where it can (progressive enhancement).
  *Done (Nov 27, 2026): `site.js` is an ES module; GSAP is loaded by
  `components.js` only when an installed variant lists it in `needs`.*

- [x] Editor: the manifest is merged from `site/styles.manifest.json`
  and the installed variants' `variant.json` options, so the existing
  pickers offer variant options without editor code changes. When
  setup installs more than one variant of a family, the owner can
  switch between them.
  *Done (Nov 27, 2026): `site/lib/components.php` merges what setup
  installed; the owner's choices live in `settings.components` (variant,
  option classes, tokens per family), shown in the Site panel in
  `family.json` `order`. Options and tokens of a variant appear only
  while that variant is selected.*

**Header, footer & navigation**

- [ ] Data in `settings` (no new table): `nav` with three menus (`top`,
  `footer`, `social`), plus `header` and `footer` (logo, site title,
  tagline, footer text, chosen variant + options). Menu links point to
  a page **by id** (slug changes never break a menu), an external URL
  or an anchor. Social links = network + URL, icons from a small SVG
  set in `site/icons/social/`.

- [ ] Family default templates `site/templates/header.php` and
  `footer.php`, included by `layout.php`, with slots (logo, title,
  tagline, menu, menu button); fall back to the site name when
  settings are missing, so the site works without `admin/` or the
  migration.

- [ ] Mobile menu with the **checkbox hack** (opens without JS), using
  the markup of the existing starter kit
  (`visualeditor_lib`, `index.html` + `5-components/_03.*`):

  ```html
  <div class="nav">
    <input type="checkbox" id="nav-top" class="nav-switch desktop-hide">
    <label for="nav-top" class="hamburger hamburger--x desktop-hide"><span>menu</span></label>
    <div class="menu" style="--menu-items:5">
      <ul class="main-nav-list nav-list list-none">
        <li style="--i:0"><a href="…">…</a></li> …
      </ul>
    </div>
  </div>
  ```

  Changes when the starter kit's partials move into the bank:
  - The checkbox is **visually hidden, not `display: none`**, so
    keyboard users can focus it and toggle it with Space; the label
    shows a focus ring via `.nav-switch:focus-visible ~ label`.
  - Ids come from the renderer (`nav-top`, `nav-footer`), never
    hard-coded, so two menus on a page never clash.
  - The open height never depends on a hard-coded item count (the
    owner edits menus): open with a `grid-template-rows: 0fr → 1fr`
    transition, or from `--menu-items`, which the renderer outputs.
    It also outputs `--i` per item for staggered reveals.
  - Each menu-button variant is scoped under its modifier class
    (`.hamburger--x`, `.hamburger--dots`) instead of redefining
    `.hamburger`, so several can be installed and the owner can switch.
  - Colours and timings the owner or Figma can change become CSS
    custom properties (`--nav-bg`, `--nav-duration`, `--ham-color`…,
    defaulting to palette tokens); SCSS variables stay for sizes and
    breakpoints (`mq()` mixin).
  - The collapse breakpoint (`desktop-hide`, `mq-L-UP`) is a precompiled
    class per option (never, tablet, mobile).
  - Small JS layer on top: Escape closes, a link click closes, focus
    returns to the button. Without JS the menu still works.

- [ ] First variants:
  - header: static, sticky, fixed with slide out/in on scroll
  - menu button: hamburger to X (`_03.02-hamburgerX`), dots
    (`_03.02-hamburgerDOTS`), logo, text label
  - navigation: inline with collapse breakpoint, foldable (mobile
    style) on all screens

- [ ] Logo as SVG, inlined so it follows `currentColor` and blend
  modes; SVG sanitised on upload (no scripts, `on*` attributes,
  `foreignObject` or external references).

- [ ] Editor: Navigation tab in the Site panel (add, reorder, remove
  links, page picker); inline editing of site title and tagline;
  "Show in menu" option when creating a page; header/footer style
  (palette colours, blend mode, variant options such as transition,
  easing, duration, collapse breakpoint).

- [ ] Page-level option: hide header and/or footer on a page.

**Gate:** the owner changes the logo, all three menus, header colours,
blend mode, menu button and mobile behaviour, and hides the header on
one page, without editing code. A new variant added to the bank and
installed with setup shows up in the editor with no editor code
changes. Removing `admin/` leaves header, footer and the mobile menu
working (also with JS off).

### Phase 2c: Design tokens & Figma import

Tokens feed the variant library: anything the owner or an import can
change is a CSS custom property that the SCSS reads with `var(--…)`.
Values that cannot be custom properties (media/container query
breakpoints) go into `site/scss/_settings.scss` and need a recompile.

- [ ] Extend the theme model: typography for headlines (h1–h6) and
  paragraphs (font family, size, weight, line height, letter spacing,
  colour token), rendered as CSS variables by `ve_theme_css()` and
  used in the SCSS library.

- [ ] Button tokens: border, radius, background, text and focus colour,
  hover state. Other form elements (inputs, radios, checkboxes) wait
  until a form item type exists.

- [ ] Component tokens for the variant library (e.g. header height,
  menu panel background, transition duration and easing), declared in
  `variant.json` so imports and the editor know them.

- [ ] Fonts: self-hosted woff2 upload generating `@font-face`
  (preferred, GDPR-friendly), or a Google Fonts URL.

- [ ] Token import: Figma variables exported to JSON with an
  export-variables plugin; a PHP importer maps the JSON to
  `settings.theme` (runtime tokens) and `_settings.scss` (compile-time
  values). First as a command
  (`php admin/tools/import-tokens.php tokens.json`), later as an admin
  upload that previews the changes before applying.

- [ ] Starter Figma file convention: variable collections named like
  the tokens (`color/*`, `type/h1/*`, `button/*`, `header/*`), so the
  import maps 1:1. Document it in `docs/howto.md`.

**Gate:** importing a designer's Figma variables export restyles
colours, typography, buttons and the installed header/navigation
variants across the whole site.

### Phase 3: Layout & components

- [ ] Drag to reposition; layers panel for sorting

- [ ] Add row/column placeholder → pick media from dropdown → set params

- [ ] Reusable components (master/instance) + unlink instance

- [ ] Extended motion: scroll reveal, parallax items, fullscreen slider

- [ ] Responsive checks with container queries; preview at breakpoints

- [ ] Free-form header/footer layouts built from reusable components
  (beyond the fixed templates of Phase 2b)

**Gate:** a new page is built from components alone.

### Phase 4: Production-ready add-on

- [ ] Build script that produces an upload package (admin core + site +
  example config)

- [ ] FTP deployment guide; DB import/export between localhost and live

- [ ] Admin core versioning + update mechanism with migrations

- [ ] Detach procedure, tested: remove /admin/, site keeps working

- [ ] Security: password hashing, CSRF tokens, upload type/size checks,
  rate-limited login

- [ ] Backups of DB and uploads

- [ ] Google login (next wave)

**Gate:** deployed to a live client site, and removing the admin core
leaves it intact.

## Open questions

- [ ] Production DB: MariaDB confirmed, or keep PostgreSQL support from
  day one?

- [ ] Content storage: one JSON blob per item, or typed columns per
  field?

- [ ] Rendering on detach: keep PHP rendering from the DB, or export
  static HTML when a project ends?

- [ ] Admin core updates: manual FTP copy, or a version check that pulls
  updates from a central source?

- [ ] Media: generate resized versions and WebP on upload (needs
  GD/Imagick on the host)?

- [ ] Multiple editors per site, or a single owner account?

- [x] Component bank: the starter kit, now its own repo
  `visualeditor_lib` (local `~/Sites/visualeditor/visualeditor_lib`,
  GitHub `kubasanitrak/visualeditor_lib`). ITCSS layers 1–4 become the
  project core, `5-components/` the variant families, restructured
  into `<family>/<variant>/` folders in Phase 2b. *Decided Nov 24, 2026.*

- [ ] How setup shows that a project's pinned variant is older than the
  bank's.

- [x] Starter kit SCSS under Dart Sass: fixed, not silenced. `@use`
  instead of `@import` (each partial loads the settings and mixins it
  needs), `math.div()` instead of `/`, `color.adjust()` instead of
  `darken()`, declarations moved above nested rules. Ruby Sass can no
  longer compile it. *Decided Nov 24, 2026.*
