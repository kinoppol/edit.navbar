# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

RVC Navbar is a PHP 8 + MariaDB 10 web application providing a Google-style navigation bar for the RVC Workspace. The frontend is a React 18 SPA rendered client-side via Babel standalone (no build step); all data is injected server-side by PHP into `window.__RVC__` before React mounts.

Stack: **PHP 8+**, **MariaDB 10+**, **React 18 (CDN, Babel standalone)**, served by **WampServer / Apache**. No Composer, no npm, no build toolchain.

## First-time setup

Navigate to `http://localhost/edit.navbar/setup.php` in a browser. This creates the `rvc_navbar` database, all tables, and seeds two users and five apps. It is idempotent — safe to re-run.

Default credentials after setup:
- Admin: `admin@rvc.ac.th` / `admin1234`
- User: `nattawat@rvc.ac.th` / `user1234`

## Database configuration

`config/db.php` defines the `DB_*` constants from a settings array: hardcoded defaults (localhost / root / no password, for dev) **overridden by `config/db.local.php`** if that file exists (`require`d as an array and `array_merge`d). For a real server, don't edit `db.php` — open `setup.php` in a browser and fill in the connection form; on success it writes `config/db.local.php` (via `var_export`) and runs the schema migrations + seed. `config/db.local.php` holds credentials and is **git-ignored**; if the web user can't write it, `setup.php` prints the file contents to paste manually. `setup.php` only acts on POST (the form) and should be removed/locked down on production.

`APP_BASE` is computed automatically from `$_SERVER['DOCUMENT_ROOT']` and `__DIR__` — it becomes `/edit.navbar` on a standard WampServer install. All PHP redirects and HTML links use `APP_BASE` as a prefix.

## Architecture

### Request lifecycle

1. Every page starts with `require_once 'config/db.php'` then `config/auth.php`.
2. Protected pages call `require_auth()` (redirects to `login.php` if no session) or `require_admin()` (additionally enforces `role = 'admin'`). `index.php` is public — it calls `current_user()` which returns `null` for unauthenticated visitors. `account.php` is the self-service password-change page for any logged-in user (calls `require_auth()`); it verifies the current password with `password_verify`, enforces an 8-char minimum, and calls `session_regenerate_id(true)` after a successful change. Admins can also reset *any* user's password from `admin/users.php` (the `reset_password` action sets a random temporary password via `rvc_temp_password()` and shows it once in the flash) or by entering a new one in the user edit form.
3. `index.php` fetches all data server-side and embeds it as `window.__RVC__` JSON before the React `<script>` tags. React reads this object at startup — there is **no separate initial API call**.
4. After mount, the React app calls the JSON API endpoints only on user interaction (drag-to-reorder, mark-read).

### Key patterns

- **`db()`** — static singleton PDO factory in `config/db.php`. Always use this; never open a second connection.
- **`require_auth(bool $json)`** / **`require_admin(bool $json)`** — pass `true` from API endpoints so they return JSON 401/403 instead of an HTML redirect.
- **`csrf_token()`** / **`csrf_verify()`** — CSRF token is stored in `$_SESSION['csrf']`. HTML forms embed it as `<input name="_csrf">`. JSON API endpoints accept it as the `X-CSRF-Token` request header. `csrf_verify()` reads both sources via `$_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN']`.
- **`e(string $v)`** — shorthand for `htmlspecialchars(..., ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')`. Use for every PHP value echoed into HTML.

### Database schema (four tables)

| Table | Purpose |
|---|---|
| `users` | Auth credentials, name, initials, avatar colour, role (`user`/`admin`), `username` (unique, nullable — the external `people_id`) |
| `settings` | Key/value runtime config editable by admins (`skey`/`svalue`) |
| `auth_tokens` | "ลงชื่อค้างไว้" remember-me cookies (selector + hashed validator, 30 days) |
| `apps` | App catalogue: slug, name, description, colour, glyph_type, glyph, URL, is_active, `is_beta`, sort_order |
| `user_app_prefs` | Per-user overrides: `(user_id, app_id)` PK, `is_hidden`, `sort_order` |
| `notifications` | Per-user notification feed linked to an app |

App visibility/order for a user = global `apps.sort_order` overridden by `user_app_prefs.sort_order` and `is_hidden`. Both `index.php` and `api/apps.php` share the same merge logic (sort by `sort_order_user`, split into `$visible`/`$hidden`).

`migration.php` (repo root) applies the schema changes to an **existing** install using the credentials already in `config/db.php` — no form, no database creation, no seeding. Run it in a browser (`/edit.navbar/migration.php`, admin login required) or from the CLI (`php migration.php`). Every step is guarded (`SHOW TABLES` / `SHOW COLUMNS` / `SHOW INDEX`) and logs `✅ applied` vs `↔︎ already present`. When you add a column, add it in three places: the `CREATE TABLE` in `setup.php`, the guarded `ALTER` in `setup.php`, and a `mig_step()` in `migration.php`.

`setup.php` is the single source of schema truth **and the migration runner**: in addition to `CREATE TABLE IF NOT EXISTS`, it runs guarded `ALTER TABLE` blocks (e.g. widening `glyph`, adding `is_beta` via a `SHOW COLUMNS … LIKE` check) so re-running it migrates existing installs. Add any new column both to its `CREATE TABLE` and as an idempotent `ALTER` here.

`apps.is_beta` (TINYINT, default 0) flags an app as a trial; when set, a "BETA" badge is overlaid on its icon everywhere it renders (admin table, app-launcher tiles, hidden-zone chips, quick-cards).

### Admin panel

`admin/_layout.php` exports two functions: `admin_head(title, currentPage, adminUser)` outputs the full HTML head + sticky nav; `admin_footer()` closes the document. Every admin page includes this file and calls both functions. Admin pages use plain PHP form-based CRUD (POST with `action` field), no React.

Because `admin_head()` emits HTML, any handler that streams a file (e.g. the ZIP export) must run **before** it is called — handle such `action`s in the POST block near the top and `exit` after streaming. `_layout.php` itself only defines functions (no output on include), so it's safe to require above the export handler.

### App backup (export / import ZIP)

`admin/apps.php` has `export`/`import` POST actions that back up the whole app catalogue (apps.json + manifest.json + the `assets/app-icons/` files) as a ZIP. Import upserts by `slug` (`ON DUPLICATE KEY UPDATE`) inside a transaction and restores icon files (path-traversal-safe via `basename()` + an extension allowlist).

ZIP I/O is **pure-PHP** in `admin/_zip.php` (`rvc_zip_create()` / `rvc_zip_read()`) — the `zip`/ZipArchive extension is **not** enabled on this install, so these build/parse the archive manually using only zlib (`gzdeflate`/`gzinflate`/`crc32`, which are available). Reading supports store (0) and deflate (8) and parses via the central directory.

### Remember me

`login.php` has a "ลงชื่อเข้าใช้ค้างไว้ (30 วัน)" checkbox. On success it calls `remember_issue()` (`config/auth.php`), which stores a random selector + **sha256-hashed** validator in `auth_tokens` and sets the `rvc_remember` cookie (`selector:validator`, HttpOnly/SameSite=Strict, Secure under HTTPS). `current_user()` and `require_auth()` fall back to `remember_restore()`, which validates with `hash_equals`, rotates the token (single-use) and regenerates the session id. `logout.php` calls `remember_clear()`. Expired rows are purged by `migration.php`.

### External user import (RMS)

`admin/settings.php` (nav: **ตั้งค่า**) lets an admin set the **base URL only** of the external people API — stored in `settings` under key `user_import_base_url` (default `http://rms.rvc.ac.th`) via `rvc_setting_get()`/`rvc_setting_set()` in `config/settings.php`. The path+query is hardcoded as `RVC_IMPORT_PATH` (`/api_connection.php?app_name=nutty&data=people`) in `config/user_import.php`.

`rvc_user_import_run()` fetches the JSON (curl, falling back to `file_get_contents`), accepts either a bare list or a wrapper object, and imports only rows with `people_exit == 0`. Mapping: `people_id` → `users.username` (the upsert key), `people_name` + `" "` + `people_surname` → `name`, `ath_pass` → bcrypt `password_hash`, `people_email` → `email` (falls back to `<people_id>@rvc.ac.th` when missing/invalid, since `email` is UNIQUE NOT NULL). Re-imports UPDATE and never touch `created_at`; email collisions with a different user are caught per-row and reported instead of aborting. `login.php` accepts either the email or the username.

### React / frontend

- All React code lives inline in `index.php` inside `<script type="text/babel">`.
- `window.__RVC__` fields: `user` (null for guests), `apps` (array), `prefs` (`{visible:[slugs], hidden:[slugs]}`), `notifs`, `isAdmin`, `isGuest`, `base` (APP_BASE), `csrf` (empty string for guests).
- `APP_MAP` is built from `window.__RVC__.apps` keyed by `slug`.
- Guest mode (`IS_GUEST = true`): apps shown in default sort order, no hidden zone, no drag-to-reorder, no notifications bell. The header shows a "เข้าสู่ระบบ" link instead of the avatar. `AppLauncher` renders `SimpleTile` (`<a>` elements) instead of `DragTile` when `readonly={true}`.
- After the first render, a `useEffect` watching the `apps` state POSTs to `api/apps.php` with `action: "save_prefs"` whenever the user reorders or hides an app. A `useRef(true)` guard skips the save on the initial render; `IS_GUEST` also skips it.
- Theme is stored in `localStorage` under key `rvc-theme` (`"light"` / `"dark"` / `"system"`). A small inline `<script>` before the CSS applies it before first paint to avoid flash.
- The header search box (`<form role="search">`) submits to DuckDuckGo scoped to the RVC site: `https://duckduckgo.com/?q=<query> site:rvc.ac.th`, opened in a new tab. Edit `doSearch()` in the `Header` component to change the engine/scope.
- `renderGlyph(app, size)` is the single icon renderer (image → `<img class="app-icon-img">`, `icon`+`meet` → SVG, else mono text). `image`-type `<img>`s use the `.app-icon-img` class (smooth scaling + GPU layer) — keep changes there, not inline. The `BetaBadge` component is overlaid inside each icon container (which must be `position: relative`); pass `dot` for the compact hidden-chip variant.

## Adding a new app

1. Use the admin panel at `/admin/apps.php` → "เพิ่มแอปใหม่", or insert directly:
   ```sql
   INSERT INTO apps (slug, name, description, color, glyph_type, glyph, url, is_active, sort_order)
   VALUES ('myapp', 'MyApp', 'คำอธิบาย', '#7c3aed', 'mono', 'My', 'https://...', 1, 10);
   ```
2. Three `glyph_type` values are supported:
   - `mono` — 1–2 character text abbreviation stored in `glyph`
   - `icon` — renders the Meet video-camera SVG when `glyph = 'meet'`
   - `image` — uploaded image file; `glyph` stores the filename (e.g. `meet-1234567890.png`). Files live in `assets/app-icons/`. PHP execution is blocked there via `.htaccess`. On delete or type-change, the old file is removed by `delete_icon()` in `admin/apps.php`. The **GD extension is disabled** on this install, so uploads are stored as-is (no server-side resizing/resampling) — icon crispness depends on the source file; `.app-icon-img` only smooths client-side scaling.

## Adding a new user role or permission check

Roles live in `users.role` as a MySQL `ENUM('user','admin')`. To add a new role, alter the column and update `require_admin()` in `config/auth.php` accordingly. The session stores `role` at login time; changes take effect on the user's next login.
