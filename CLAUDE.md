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

All DB constants (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`) are defined at the top of `config/db.php`. Change them there before running setup.

`APP_BASE` is computed automatically from `$_SERVER['DOCUMENT_ROOT']` and `__DIR__` — it becomes `/edit.navbar` on a standard WampServer install. All PHP redirects and HTML links use `APP_BASE` as a prefix.

## Architecture

### Request lifecycle

1. Every page starts with `require_once 'config/db.php'` then `config/auth.php`.
2. Protected pages call `require_auth()` (redirects to `login.php` if no session) or `require_admin()` (additionally enforces `role = 'admin'`). `index.php` is public — it calls `current_user()` which returns `null` for unauthenticated visitors.
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
| `users` | Auth credentials, name, initials, avatar colour, role (`user`/`admin`) |
| `apps` | App catalogue: slug, name, description, colour, glyph_type, glyph, URL, is_active, sort_order |
| `user_app_prefs` | Per-user overrides: `(user_id, app_id)` PK, `is_hidden`, `sort_order` |
| `notifications` | Per-user notification feed linked to an app |

App visibility/order for a user = global `apps.sort_order` overridden by `user_app_prefs.sort_order` and `is_hidden`. Both `index.php` and `api/apps.php` share the same merge logic (sort by `sort_order_user`, split into `$visible`/`$hidden`).

### Admin panel

`admin/_layout.php` exports two functions: `admin_head(title, currentPage, adminUser)` outputs the full HTML head + sticky nav; `admin_footer()` closes the document. Every admin page includes this file and calls both functions. Admin pages use plain PHP form-based CRUD (POST with `action` field), no React.

### React / frontend

- All React code lives inline in `index.php` inside `<script type="text/babel">`.
- `window.__RVC__` fields: `user` (null for guests), `apps` (array), `prefs` (`{visible:[slugs], hidden:[slugs]}`), `notifs`, `isAdmin`, `isGuest`, `base` (APP_BASE), `csrf` (empty string for guests).
- `APP_MAP` is built from `window.__RVC__.apps` keyed by `slug`.
- Guest mode (`IS_GUEST = true`): apps shown in default sort order, no hidden zone, no drag-to-reorder, no notifications bell. The header shows a "เข้าสู่ระบบ" link instead of the avatar. `AppLauncher` renders `SimpleTile` (`<a>` elements) instead of `DragTile` when `readonly={true}`.
- After the first render, a `useEffect` watching the `apps` state POSTs to `api/apps.php` with `action: "save_prefs"` whenever the user reorders or hides an app. A `useRef(true)` guard skips the save on the initial render; `IS_GUEST` also skips it.
- Theme is stored in `localStorage` under key `rvc-theme` (`"light"` / `"dark"` / `"system"`). A small inline `<script>` before the CSS applies it before first paint to avoid flash.

## Adding a new app

1. Use the admin panel at `/admin/apps.php` → "เพิ่มแอปใหม่", or insert directly:
   ```sql
   INSERT INTO apps (slug, name, description, color, glyph_type, glyph, url, is_active, sort_order)
   VALUES ('myapp', 'MyApp', 'คำอธิบาย', '#7c3aed', 'mono', 'My', 'https://...', 1, 10);
   ```
2. Three `glyph_type` values are supported:
   - `mono` — 1–2 character text abbreviation stored in `glyph`
   - `icon` — renders the Meet video-camera SVG when `glyph = 'meet'`
   - `image` — uploaded image file; `glyph` stores the filename (e.g. `meet-1234567890.png`). Files live in `assets/app-icons/`. PHP execution is blocked there via `.htaccess`. On delete or type-change, the old file is removed by `delete_icon()` in `admin/apps.php`.

## Adding a new user role or permission check

Roles live in `users.role` as a MySQL `ENUM('user','admin')`. To add a new role, alter the column and update `require_admin()` in `config/auth.php` accordingly. The session stores `role` at login time; changes take effect on the user's next login.
