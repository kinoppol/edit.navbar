<?php
/**
 * Admin layout helper.
 * Include at the TOP of each admin page (before any output), providing:
 *   $pageTitle   (string)  — <title> text
 *   $currentPage (string)  — 'apps' | 'users'
 *   $adminUser   (array)   — from require_admin()
 *
 * Call admin_footer() at the very bottom.
 */
function admin_head(string $pageTitle, string $currentPage, array $adminUser): void
{
    $base = APP_BASE;
    ?>
<!DOCTYPE html>
<html lang="th" id="html-root">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> — RVC Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
(function(){
  var t = localStorage.getItem('rvc-theme') || 'system';
  var dark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
})();
</script>
<style>
:root {
  --font-sans: 'Plus Jakarta Sans', 'Noto Sans Thai', system-ui, sans-serif;
  --brand: #2F5BEA; --brand-tint: #eaf0ff;
}
:root, [data-theme="light"] {
  --bg: #f1f3f6; --surface: #ffffff; --surface-2: #f6f8fb;
  --header-bg: #ffffff; --border: #e3e7ee; --border-strong: #d4dae5;
  --text: #1a1f29; --text-2: #5b6472; --text-3: #8a93a3;
  --hover: #f0f3f8; --hover-strong: #e7ecf4; --input-bg: #f6f8fb;
  --shadow-header: 0 1px 0 rgba(20,30,55,.06);
  --shadow-card: 0 2px 16px rgba(20,30,55,.08);
  --shadow-pop: 0 4px 28px rgba(20,30,55,.16), 0 1px 3px rgba(20,30,55,.08);
  --ring: rgba(47,91,234,.35); --glow: rgba(47,91,234,.28);
}
[data-theme="dark"] {
  --bg: #0e1116; --surface: #171b22; --surface-2: #1d222b;
  --header-bg: #14181f; --border: #262c37; --border-strong: #333b48;
  --text: #e8ecf2; --text-2: #a3adbd; --text-3: #6f7a8b;
  --hover: #20262f; --hover-strong: #28303b; --input-bg: #20262f;
  --shadow-header: 0 1px 0 rgba(0,0,0,.5);
  --shadow-card: 0 2px 20px rgba(0,0,0,.4);
  --shadow-pop: 0 6px 32px rgba(0,0,0,.55), 0 1px 2px rgba(0,0,0,.4);
  --ring: rgba(108,150,255,.45); --glow: rgba(108,150,255,.38);
  --brand: #6c96ff; --brand-tint: #1b2742;
}
*, *::before, *::after { box-sizing: border-box; }
html, body { margin: 0; padding: 0; min-height: 100vh; }
body { font-family: var(--font-sans); background: var(--bg); color: var(--text); -webkit-font-smoothing: antialiased; }
a { color: var(--brand); }
button, input, select, textarea { font-family: inherit; }

/* ── Admin nav ── */
.a-header {
  position: sticky; top: 0; z-index: 50;
  background: var(--header-bg);
  border-bottom: 1px solid var(--border);
  box-shadow: var(--shadow-header);
}
.a-header::after {
  content: ""; display: block; height: 1px;
  background: linear-gradient(90deg, transparent 8%, var(--glow) 40%, #9cbcff 50%, var(--glow) 60%, transparent 92%);
  opacity: .6;
}
.a-header-inner {
  height: 64px; max-width: 1280px; margin: 0 auto; padding: 0 20px;
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
}
.a-header-left { display: flex; align-items: center; gap: 20px; }
.brand { display: flex; align-items: baseline; gap: 9px; text-decoration: none; }
.brand-mark { font-weight: 800; font-size: 22px; letter-spacing: -.5px; color: var(--brand); }
.brand-sub  { font-size: 13px; font-weight: 500; color: var(--text-3); }

.a-nav { display: flex; align-items: center; gap: 2px; }
.a-nav-link {
  text-decoration: none; color: var(--text-2);
  font-size: 14px; font-weight: 600;
  padding: 7px 12px; border-radius: 8px;
  transition: background .15s, color .15s;
}
.a-nav-link:hover  { background: var(--hover); color: var(--text); }
.a-nav-link.active { background: var(--brand-tint); color: var(--brand); }

.a-header-right { display: flex; align-items: center; gap: 12px; }
.a-btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 7px 14px; border-radius: 8px; border: none; cursor: pointer;
  font-size: 13.5px; font-weight: 600;
  transition: background .15s, color .15s, opacity .15s;
}
.a-btn-ghost { background: transparent; color: var(--text-2); }
.a-btn-ghost:hover { background: var(--hover); color: var(--text); }
.a-btn-primary { background: var(--brand); color: #fff; }
.a-btn-primary:hover { opacity: .88; }
.a-btn-danger { background: #fee2e2; color: #b91c1c; }
.a-btn-danger:hover { background: #fecaca; }
[data-theme="dark"] .a-btn-danger { background: #2d1212; color: #fca5a5; }
[data-theme="dark"] .a-btn-danger:hover { background: #3f1515; }

/* ── Main content ── */
.a-main { max-width: 1280px; margin: 0 auto; padding: 32px 20px 80px; }
.a-page-head {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: 24px; gap: 16px; flex-wrap: wrap;
}
.a-page-title { font-size: 22px; font-weight: 800; letter-spacing: -.5px; margin: 0; }
.a-page-sub   { font-size: 14px; color: var(--text-2); margin: 4px 0 0; }

/* ── Card / Table ── */
.a-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; box-shadow: var(--shadow-card); overflow: hidden;
}
.a-table { width: 100%; border-collapse: collapse; }
.a-table th {
  text-align: left; font-size: 11.5px; font-weight: 700; letter-spacing: .5px;
  text-transform: uppercase; color: var(--text-3);
  padding: 12px 16px; background: var(--surface-2);
  border-bottom: 1px solid var(--border);
}
.a-table td {
  padding: 14px 16px; font-size: 14px; color: var(--text);
  border-bottom: 1px solid var(--border); vertical-align: middle;
}
.a-table tr:last-child td { border-bottom: none; }
.a-table tr:hover td { background: var(--hover); }
.a-table td.actions { white-space: nowrap; }

/* ── Color swatch ── */
.color-swatch {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: 9px;
  color: #fff; font-weight: 800; font-size: 13px; letter-spacing: -.3px;
  box-shadow: 0 2px 6px rgba(20,30,55,.18);
}

/* ── Status badge ── */
.badge-active   { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 600; background: #dcfce7; color: #15803d; }
.badge-inactive { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 600; background: #f3f4f6; color: #6b7280; }
[data-theme="dark"] .badge-active   { background: #14291e; color: #86efac; }
[data-theme="dark"] .badge-inactive { background: #1f2937; color: #9ca3af; }

.badge-pre-alpha, .badge-alpha, .badge-beta, .badge-rc { display: inline-flex; padding: 2px 7px; border-radius: 6px; font-size: 10.5px; font-weight: 800; letter-spacing: .4px; }
.badge-pre-alpha { background: #fee2e2; color: #991b1b; }
[data-theme="dark"] .badge-pre-alpha { background: #450a0a; color: #fca5a5; }
.badge-alpha { background: #ffedd5; color: #9a3412; }
[data-theme="dark"] .badge-alpha { background: #431407; color: #fdba74; }
.badge-beta { background: #fef3c7; color: #b45309; }
[data-theme="dark"] .badge-beta { background: #3a2c10; color: #fbbf24; }
.badge-rc { background: #dcfce7; color: #166534; }
[data-theme="dark"] .badge-rc { background: #052e16; color: #86efac; }
.badge-ai { display: inline-flex; padding: 2px 7px; border-radius: 6px; font-size: 10.5px; font-weight: 800; letter-spacing: .4px; background: #ede9fe; color: #6d28d9; }
[data-theme="dark"] .badge-ai { background: #2e1a5e; color: #a78bfa; }

.badge-admin { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 600; background: var(--brand-tint); color: var(--brand); }
.badge-user  { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 12.5px; font-weight: 600; background: #f3f4f6; color: #6b7280; }
[data-theme="dark"] .badge-user { background: #1f2937; color: #9ca3af; }

/* ── Form ── */
.a-form-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; padding: 28px; box-shadow: var(--shadow-card); margin-bottom: 28px;
}
.a-form-title { font-size: 17px; font-weight: 700; margin: 0 0 22px; }
.a-field      { margin-bottom: 18px; }
.a-label { display: block; font-size: 13px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; }
.a-input, .a-select, .a-textarea {
  width: 100%; padding: 10px 13px;
  background: var(--input-bg); color: var(--text);
  border: 1px solid var(--border); border-radius: 10px;
  font-family: inherit; font-size: 14px;
  outline: none; transition: border-color .15s, box-shadow .15s;
}
.a-input:focus, .a-select:focus, .a-textarea:focus {
  border-color: var(--brand); box-shadow: 0 0 0 3px var(--ring);
}
.a-select { appearance: none; cursor: pointer; }
.a-textarea { resize: vertical; min-height: 80px; }
.a-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 600px) { .a-field-row { grid-template-columns: 1fr; } }
.a-hint { font-size: 12px; color: var(--text-3); margin-top: 4px; }
.a-color-row { display: flex; align-items: center; gap: 10px; }
.a-color-input { width: 42px; height: 42px; padding: 3px; border-radius: 10px; border: 1px solid var(--border); background: var(--input-bg); cursor: pointer; }
.a-form-actions { display: flex; gap: 10px; align-items: center; margin-top: 4px; }

/* ── Alerts ── */
.a-alert { border-radius: 10px; padding: 12px 16px; font-size: 14px; font-weight: 500; margin-bottom: 20px; }
.a-alert-success { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
.a-alert-error   { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
[data-theme="dark"] .a-alert-success { background: #14291e; color: #86efac; border-color: #166534; }
[data-theme="dark"] .a-alert-error   { background: #2d1212; color: #fca5a5; border-color: #9f1239; }

/* ── Modal ── */
.a-modal-backdrop {
  position: fixed; inset: 0; z-index: 500;
  background: rgba(12,17,26,.55); backdrop-filter: blur(3px);
  display: flex; align-items: center; justify-content: center; padding: 20px;
  animation: a-fade .18s ease;
}
.a-modal-backdrop[hidden] { display: none; }
.a-modal {
  width: 100%; max-width: 460px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 18px; box-shadow: var(--shadow-pop);
  padding: 26px 26px 22px;
  animation: a-pop .22s cubic-bezier(.2,.9,.3,1.2);
}
.a-modal-icon {
  width: 46px; height: 46px; border-radius: 14px;
  display: flex; align-items: center; justify-content: center;
  background: var(--brand-tint); color: var(--brand); margin-bottom: 16px;
}
.a-modal-icon svg { width: 24px; height: 24px; }
.a-modal-title { font-size: 18px; font-weight: 800; letter-spacing: -.3px; margin: 0 0 8px; }
.a-modal-body  { font-size: 14px; color: var(--text-2); line-height: 1.75; margin: 0 0 22px; }
.a-modal-actions { display: flex; gap: 10px; justify-content: flex-end; }
.a-modal-actions .a-btn { padding: 9px 18px; font-size: 14px; }
@keyframes a-fade { from { opacity: 0 } to { opacity: 1 } }
@keyframes a-pop  { from { opacity: 0; transform: translateY(10px) scale(.97) } to { opacity: 1; transform: none } }

/* ── Progress overlay (long-running action) ── */
.a-busy { text-align: center; }
.a-busy .a-modal-title { margin-bottom: 10px; }
.a-spinner {
  width: 54px; height: 54px; margin: 4px auto 18px;
  border-radius: 50%;
  border: 4px solid var(--border);
  border-top-color: var(--brand);
  animation: a-spin .8s linear infinite;
}
@keyframes a-spin { to { transform: rotate(360deg) } }
.a-progress {
  height: 6px; border-radius: 999px; background: var(--hover-strong);
  overflow: hidden; margin-top: 20px;
}
.a-progress::after {
  content: ""; display: block; height: 100%; width: 40%;
  border-radius: 999px; background: var(--brand);
  animation: a-slide 1.3s ease-in-out infinite;
}
@keyframes a-slide {
  0%   { transform: translateX(-100%) }
  100% { transform: translateX(250%) }
}
.a-busy-step { font-size: 13px; color: var(--text-3); margin-top: 14px; min-height: 19px; }
@media (prefers-reduced-motion: reduce) {
  .a-modal-backdrop, .a-modal { animation: none; }
  .a-spinner { animation-duration: 2s; }
  .a-progress::after { animation: none; width: 100%; }
}

/* ── Empty state ── */
.a-empty { text-align: center; padding: 60px 20px; color: var(--text-3); font-size: 15px; }

/* ── Avatar ── */
.a-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  color: #fff; font-weight: 700; font-size: 14px; flex-shrink: 0;
}

/* ── User menu (header avatar dropdown) ── */
.a-user { position: relative; }
.a-avatar-btn {
  border: none; background: transparent; cursor: pointer;
  padding: 3px; border-radius: 50%; display: inline-flex;
  transition: box-shadow .15s;
}
.a-avatar-btn:hover   { box-shadow: 0 0 0 2px var(--border-strong); }
.a-avatar-btn.is-open { box-shadow: 0 0 0 2px var(--brand); }
.a-avatar-btn .a-avatar { width: 34px; height: 34px; font-size: 15px; }
.a-avatar-lg { width: 48px; height: 48px; font-size: 20px; }
.a-user-pop {
  position: absolute; top: calc(100% + 10px); right: 0; width: 280px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; box-shadow: var(--shadow-pop); padding: 8px; z-index: 200;
}
.a-user-pop[hidden] { display: none; }
.a-user-head { display: flex; align-items: center; gap: 12px; padding: 12px 12px 14px; }
.a-user-name { font-size: 15px; font-weight: 700; color: var(--text); }
.a-user-email { font-size: 13px; color: var(--text-2); }
.a-menu-div { height: 1px; background: var(--border); margin: 6px 4px; }
.a-menu-row {
  display: flex; align-items: center; gap: 12px;
  padding: 10px 12px; border-radius: 10px; text-decoration: none;
  color: var(--text); font-size: 14.5px; font-weight: 500;
  transition: background .13s;
}
.a-menu-row:hover { background: var(--hover); }
.a-menu-row svg   { color: var(--text-2); width: 18px; height: 18px; flex-shrink: 0; }
.a-menu-danger    { color: #e5484d; }
.a-menu-danger svg { color: #e5484d; }
.a-menu-danger:hover { background: rgba(229,72,77,.10); }
</style>
</head>
<body>

<header class="a-header">
  <div class="a-header-inner">
    <div class="a-header-left">
      <a href="<?= e($base) ?>/index.php" class="brand">
        <span class="brand-mark">RVC</span>
        <span class="brand-sub">Admin</span>
      </a>
      <nav class="a-nav">
        <a href="<?= e($base) ?>/admin/apps.php"
           class="a-nav-link <?= $currentPage === 'apps' ? 'active' : '' ?>">แอปพลิเคชัน</a>
        <a href="<?= e($base) ?>/admin/users.php"
           class="a-nav-link <?= $currentPage === 'users' ? 'active' : '' ?>">ผู้ใช้</a>
        <a href="<?= e($base) ?>/admin/settings.php"
           class="a-nav-link <?= $currentPage === 'settings' ? 'active' : '' ?>">ตั้งค่า</a>
      </nav>
    </div>
    <div class="a-header-right">
      <a href="<?= e($base) ?>/index.php" class="a-btn a-btn-ghost">← กลับแอป</a>
      <div class="a-user">
        <button type="button" class="a-avatar-btn" id="a-avatar-btn"
                aria-haspopup="true" aria-expanded="false" aria-label="บัญชีผู้ใช้">
          <span class="a-avatar" style="background:<?= e($adminUser['avatar_color']) ?>"><?= e($adminUser['initials']) ?></span>
        </button>
        <div class="a-user-pop" id="a-user-pop" hidden>
          <div class="a-user-head">
            <span class="a-avatar a-avatar-lg" style="background:<?= e($adminUser['avatar_color']) ?>"><?= e($adminUser['initials']) ?></span>
            <div>
              <div class="a-user-name"><?= e($adminUser['name']) ?></div>
              <div class="a-user-email"><?= e($adminUser['email']) ?></div>
            </div>
          </div>
          <div class="a-menu-div"></div>
          <a class="a-menu-row" href="<?= e($base) ?>/account.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="3.5"/><path d="m10 13 8.5-8.5"/><path d="m15 6 2.5 2.5"/><path d="m18 3.5 2.5 2.5"/></svg>
            <span>เปลี่ยนรหัสผ่าน</span>
          </a>
          <a class="a-menu-row" href="<?= e($base) ?>/index.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M9.5 20v-6h5v6"/></svg>
            <span>กลับสู่ Workspace</span>
          </a>
          <div class="a-menu-div"></div>
          <a class="a-menu-row a-menu-danger" href="<?= e($base) ?>/logout.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M15 17.5 19.5 13 15 8.5"/><path d="M19.5 13H8.5"/><path d="M11 4.5H6A1.5 1.5 0 0 0 4.5 6v14A1.5 1.5 0 0 0 6 21.5h5"/></svg>
            <span>ออกจากระบบ</span>
          </a>
        </div>
      </div>
    </div>
  </div>
</header>
<script>
(function(){
  var btn = document.getElementById('a-avatar-btn');
  var pop = document.getElementById('a-user-pop');
  if (!btn || !pop) return;
  function close(){ pop.hidden = true;  btn.classList.remove('is-open'); btn.setAttribute('aria-expanded','false'); }
  function open(){  pop.hidden = false; btn.classList.add('is-open');    btn.setAttribute('aria-expanded','true');  }
  btn.addEventListener('click', function(e){ e.stopPropagation(); pop.hidden ? open() : close(); });
  document.addEventListener('click', function(e){ if (!pop.hidden && !pop.contains(e.target) && !btn.contains(e.target)) close(); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') close(); });
})();
</script>

<main class="a-main">
<?php
}

function admin_footer(): void
{
    echo '</main></body></html>';
}
