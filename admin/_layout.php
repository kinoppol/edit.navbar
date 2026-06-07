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
  --ring: rgba(47,91,234,.35); --glow: rgba(47,91,234,.28);
}
[data-theme="dark"] {
  --bg: #0e1116; --surface: #171b22; --surface-2: #1d222b;
  --header-bg: #14181f; --border: #262c37; --border-strong: #333b48;
  --text: #e8ecf2; --text-2: #a3adbd; --text-3: #6f7a8b;
  --hover: #20262f; --hover-strong: #28303b; --input-bg: #20262f;
  --shadow-header: 0 1px 0 rgba(0,0,0,.5);
  --shadow-card: 0 2px 20px rgba(0,0,0,.4);
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
  height: 56px; max-width: 1280px; margin: 0 auto; padding: 0 20px;
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
}
.a-header-left { display: flex; align-items: center; gap: 20px; }
.brand { display: flex; align-items: baseline; gap: 8px; text-decoration: none; }
.brand-mark { font-weight: 800; font-size: 20px; letter-spacing: -.5px; color: var(--brand); }
.brand-sub  { font-size: 12px; font-weight: 600; color: var(--text-3); }

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
.a-username { font-size: 13.5px; font-weight: 600; color: var(--text-2); }
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

/* ── Empty state ── */
.a-empty { text-align: center; padding: 60px 20px; color: var(--text-3); font-size: 15px; }

/* ── Avatar ── */
.a-avatar {
  width: 32px; height: 32px; border-radius: 50%;
  display: inline-flex; align-items: center; justify-content: center;
  color: #fff; font-weight: 700; font-size: 14px; flex-shrink: 0;
}
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
      </nav>
    </div>
    <div class="a-header-right">
      <span class="a-username"><?= e($adminUser['name']) ?></span>
      <a href="<?= e($base) ?>/index.php" class="a-btn a-btn-ghost">← กลับแอป</a>
      <a href="<?= e($base) ?>/logout.php" class="a-btn a-btn-ghost">ออกจากระบบ</a>
    </div>
  </div>
</header>

<main class="a-main">
<?php
}

function admin_footer(): void
{
    echo '</main></body></html>';
}
