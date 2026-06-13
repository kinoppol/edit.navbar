<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$authUser = current_user();
$isGuest  = $authUser === null;
$isAdmin  = !$isGuest && $authUser['role'] === 'admin';
$uid      = $isGuest ? 0 : $authUser['id'];

// ── Fetch active apps ordered by sort_order ──────────────────────────────────
$appsRaw = db()
    ->query("SELECT id, slug, name, description, color, glyph_type, glyph, url, is_beta, is_ai
             FROM apps WHERE is_active = 1 ORDER BY sort_order, id")
    ->fetchAll();

// ── Fetch this user's per-app prefs (skip for guests) ───────────────────────
$visible = [];
$hidden  = [];
if (!$isGuest) {
    $prefsRaw = [];
    $stmt = db()->prepare("SELECT app_id, is_hidden, sort_order FROM user_app_prefs WHERE user_id = ?");
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll() as $row) {
        $prefsRaw[(int) $row['app_id']] = $row;
    }
    foreach ($appsRaw as $app) {
        $pref = $prefsRaw[(int) $app['id']] ?? null;
        $app['sort_order_user'] = $pref ? (int) $pref['sort_order'] : (int) $app['id'];
        if ($pref && (int) $pref['is_hidden'] === 1) {
            $hidden[] = $app;
        } else {
            $visible[] = $app;
        }
    }
    usort($visible, fn($a, $b) => $a['sort_order_user'] <=> $b['sort_order_user']);
    usort($hidden,  fn($a, $b) => $a['sort_order_user'] <=> $b['sort_order_user']);
} else {
    $visible = $appsRaw;
}

$visibleSlugs = array_column($visible, 'slug');
$hiddenSlugs  = array_column($hidden,  'slug');

// ── Fetch notifications (skip for guests) ───────────────────────────────────
$notifsRaw = [];
if (!$isGuest) {
    $stmt = db()->prepare("
        SELECT n.id, n.title, n.body, n.is_read, n.created_at,
               a.slug AS app_slug, a.name AS app_name, a.color AS app_color
        FROM notifications n
        LEFT JOIN apps a ON a.id = n.app_id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 20
    ");
    $stmt->execute([$uid]);
    $notifsRaw = $stmt->fetchAll();
}

// ── Build JS payload ─────────────────────────────────────────────────────────
$jsUser = $isGuest ? null : [
    'id'          => $authUser['id'],
    'name'        => $authUser['name'],
    'email'       => $authUser['email'],
    'initials'    => $authUser['initials'],
    'avatarColor' => $authUser['avatar_color'],
    'role'        => $authUser['role'],
    'accounts'    => [[
        'name'    => $authUser['name'],
        'email'   => $authUser['email'],
        'initials'=> $authUser['initials'],
        'color'   => $authUser['avatar_color'],
    ]],
];

$jsApps = array_map(fn($a) => [
    'id'        => (int) $a['id'],
    'slug'      => $a['slug'],
    'name'      => $a['name'],
    'desc'      => $a['description'],
    'color'     => $a['color'],
    'glyphType' => $a['glyph_type'],
    'glyph'     => $a['glyph'],
    'url'       => $a['url'],
    'isBeta'    => (bool) (int) $a['is_beta'],
    'isAi'      => (bool) (int) $a['is_ai'],
], $appsRaw);

$jsNotifs = array_map(fn($n) => [
    'id'       => (int) $n['id'],
    'app'      => $n['app_name']  ?? '',
    'appSlug'  => $n['app_slug']  ?? '',
    'color'    => $n['app_color'] ?? '#8a93a3',
    'title'    => $n['title'],
    'body'     => $n['body'],
    'time'     => (new DateTime($n['created_at']))->format('d/m/Y H:i'),
    'unread'   => (bool) !$n['is_read'],
], $notifsRaw);

$rvcData = json_encode([
    'user'     => $jsUser,
    'apps'     => $jsApps,
    'prefs'    => ['visible' => $visibleSlugs, 'hidden' => $hiddenSlugs],
    'notifs'   => $jsNotifs,
    'isAdmin'  => $isAdmin,
    'isGuest'  => $isGuest,
    'base'     => APP_BASE,
    'csrf'     => $isGuest ? '' : csrf_token(),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RVC Workspace</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
// Apply saved theme before first paint
(function(){
  var t = localStorage.getItem('rvc-theme') || 'system';
  var dark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
})();
</script>
<style>
  :root {
    --font-sans: 'Plus Jakarta Sans', 'Noto Sans Thai', system-ui, sans-serif;
    --brand: #2F5BEA; --brand-600: #2348c9; --brand-tint: #eaf0ff;
    --c-meet: #16a34a; --c-t: #ea580c; --c-test: #7c3aed;
    --c-lgaie: #db2777; --c-rms: #0891b2;
  }
  :root, [data-theme="light"] {
    --bg: #f1f3f6; --surface: #ffffff; --surface-2: #f6f8fb;
    --header-bg: #ffffff; --border: #e3e7ee; --border-strong: #d4dae5;
    --text: #1a1f29; --text-2: #5b6472; --text-3: #8a93a3;
    --hover: #f0f3f8; --hover-strong: #e7ecf4;
    --search-bg: #f0f3f8; --search-bg-focus: #ffffff;
    --shadow-pop: 0 4px 28px rgba(20,30,55,.16), 0 1px 3px rgba(20,30,55,.08);
    --shadow-header: 0 1px 0 rgba(20,30,55,.06);
    --ring: rgba(47,91,234,.35); --glow: rgba(47,91,234,.28);
  }
  [data-theme="dark"] {
    --bg: #0e1116; --surface: #171b22; --surface-2: #1d222b;
    --header-bg: #14181f; --border: #262c37; --border-strong: #333b48;
    --text: #e8ecf2; --text-2: #a3adbd; --text-3: #6f7a8b;
    --hover: #20262f; --hover-strong: #28303b;
    --search-bg: #20262f; --search-bg-focus: #161b22;
    --shadow-pop: 0 6px 32px rgba(0,0,0,.55), 0 1px 2px rgba(0,0,0,.4);
    --shadow-header: 0 1px 0 rgba(0,0,0,.5);
    --ring: rgba(108,150,255,.45); --glow: rgba(108,150,255,.38);
    --brand: #6c96ff; --brand-tint: #1b2742;
  }
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body { font-family: var(--font-sans); color: var(--text); -webkit-font-smoothing: antialiased; }
  button { font-family: inherit; }
  ::selection { background: var(--ring); }
  #root { min-height: 100vh; background: var(--bg); }
</style>
<script>window.__RVC__ = <?= $rvcData ?>;</script>
<script src="https://unpkg.com/react@18.3.1/umd/react.development.js" integrity="sha384-hD6/rw4ppMLGNu3tX5cjIb+uRZ7UkRJ6BPkLpg4hAu/6onKUg4lLsHAs9EBPT82L" crossorigin="anonymous"></script>
<script src="https://unpkg.com/react-dom@18.3.1/umd/react-dom.development.js" integrity="sha384-u6aeetuaXnQ38mYT8rp6sbXaQe3NL9t+IBXmnYxwkUI2Hw4bsp2Wvmx4yRQF1uAm" crossorigin="anonymous"></script>
<script src="https://unpkg.com/@babel/standalone@7.29.0/babel.min.js" integrity="sha384-m08KidiNqLdpJqLq95G/LEi8Qvjl/xUYll3QILypMoQ65QorJ9Lvtp2RXYGBFj1y" crossorigin="anonymous"></script>
</head>
<body>
<div id="root"></div>
<script type="text/babel" data-presets="react">
const { useState, useEffect, useRef, useCallback } = React;

// ── Server data ───────────────────────────────────────────────────────────────
const { user: USER_DATA, apps: APPS_DATA, prefs: PREFS_DATA,
        notifs: NOTIFS_DATA, isAdmin: IS_ADMIN, isGuest: IS_GUEST,
        base: BASE, csrf: CSRF } = window.__RVC__;

const APP_MAP = Object.fromEntries(APPS_DATA.map(a => [a.slug, a]));

// ── Icons ─────────────────────────────────────────────────────────────────────
const Icon = {
  search:    (p) => (<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" {...p}><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>),
  close:     (p) => (<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" {...p}><path d="M18 6 6 18M6 6l12 12"/></svg>),
  grid:      (p) => (<svg viewBox="0 0 24 24" width="22" height="22" fill="currentColor" {...p}><circle cx="5" cy="5" r="1.9"/><circle cx="12" cy="5" r="1.9"/><circle cx="19" cy="5" r="1.9"/><circle cx="5" cy="12" r="1.9"/><circle cx="12" cy="12" r="1.9"/><circle cx="19" cy="12" r="1.9"/><circle cx="5" cy="19" r="1.9"/><circle cx="12" cy="19" r="1.9"/><circle cx="19" cy="19" r="1.9"/></svg>),
  bell:      (p) => (<svg viewBox="0 0 24 24" width="21" height="21" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M18 8.5a6 6 0 1 0-12 0c0 6-2.2 7.5-2.2 7.5h16.4S18 14.5 18 8.5"/><path d="M13.7 19.5a2 2 0 0 1-3.4 0"/></svg>),
  sun:       (p) => (<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="12" cy="12" r="4.2"/><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6"/></svg>),
  moon:      (p) => (<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M20 14.5A8 8 0 1 1 9.5 4a6.3 6.3 0 0 0 10.5 10.5"/></svg>),
  monitor:   (p) => (<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/></svg>),
  home:      (p) => (<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M9.5 20v-6h5v6"/></svg>),
  user:      (p) => (<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="12" cy="8" r="3.6"/><path d="M5 19.5a7 7 0 0 1 14 0"/></svg>),
  settings:  (p) => (<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="12" cy="12" r="3"/><path d="M19.4 13.5a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-1-1.5 1.6 1.6 0 0 0-1.8.3l-.1.1A2 2 0 1 1 3.6 17l.1-.1a1.6 1.6 0 0 0-1.1-2.7H2a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.5-1 1.6 1.6 0 0 0-.3-1.8l-.1-.1A2 2 0 1 1 6 3.6l.1.1a1.6 1.6 0 0 0 1.8.3H8a1.6 1.6 0 0 0 1-1.5V2a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 1 1.5 1.6 1.6 0 0 0 1.8-.3l.1-.1A2 2 0 1 1 20.4 6l-.1.1a1.6 1.6 0 0 0-.3 1.8V8a1.6 1.6 0 0 0 1.5 1H22a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z"/></svg>),
  key:       (p) => (<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" {...p}><circle cx="7.5" cy="15.5" r="3.5"/><path d="m10 13 8.5-8.5"/><path d="m15 6 2.5 2.5"/><path d="m18 3.5 2.5 2.5"/></svg>),
  adminPanel:(p) => (<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>),
  logout:    (p) => (<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="1.9" strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M15 17.5 19.5 13 15 8.5"/><path d="M19.5 13H8.5"/><path d="M11 4.5H6A1.5 1.5 0 0 0 4.5 6v14A1.5 1.5 0 0 0 6 21.5h5"/></svg>),
  check:     (p) => (<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" {...p}><path d="m5 12.5 4.5 4.5L19 7"/></svg>),
  chevronUp: (p) => (<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" {...p}><path d="m6 14 6-6 6 6"/></svg>),
  chevronDn: (p) => (<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round" strokeLinejoin="round" {...p}><path d="m6 10 6 6 6-6"/></svg>),
  plus:      (p) => (<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" {...p}><path d="M12 5v14M5 12h14"/></svg>),
  meet:      (p) => (<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...p}><rect x="3" y="6.5" width="12" height="11" rx="2.4"/><path d="M15 10.5 21 7v10l-6-3.5"/></svg>),
  external:  (p) => (<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" {...p}><path d="M14 4h6v6M20 4l-9 9M18 13.5V19a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5.5"/></svg>),
};

// ── Glyph renderer ────────────────────────────────────────────────────────────
function renderGlyph(app, size) {
  if (app.glyphType === 'image' && app.glyph) {
    return (
      <img
        className="app-icon-img"
        src={`${BASE}/assets/app-icons/${encodeURIComponent(app.glyph)}`}
        alt={app.name}
        loading="lazy" decoding="async" draggable={false}
      />
    );
  }
  if (app.glyphType === 'icon' && app.glyph === 'meet') {
    return <Icon.meet style={{ color: '#fff', width: size, height: size }} />;
  }
  return <span className="app-tile-mono">{app.glyph}</span>;
}

// Beta badge — overlaid on an app icon to flag a trial app.
// `dot` renders a compact marker for small icons (hidden-zone chips).
function BetaBadge({ dot }) {
  return dot
    ? <span className="beta-dot" title="เวอร์ชันทดลอง (Beta)" />
    : <span className="beta-badge">BETA</span>;
}

const AI_STAR = (
  <svg viewBox="0 0 16 16" width="9" height="9" fill="currentColor" aria-hidden="true">
    <path d="M8 0 C8 4.4 4.4 8 0 8 C4.4 8 8 11.6 8 16 C8 11.6 11.6 8 16 8 C11.6 8 8 4.4 8 0Z"/>
  </svg>
);

function AiBadge({ dot }) {
  return dot
    ? <span className="ai-dot" title="สร้างด้วย AI">{AI_STAR}</span>
    : <span className="ai-badge" title="สร้างด้วย AI">{AI_STAR}</span>;
}

// ── Drag tiles ────────────────────────────────────────────────────────────────
function DragTile({ app, dragging, onStart, onEnd, onDropBefore }) {
  return (
    <div className={"app-tile" + (dragging ? " dragging" : "")}
         draggable
         onDragStart={e => { e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", app.slug); onStart(app.slug); }}
         onDragEnd={onEnd}
         onDragOver={e => e.preventDefault()}
         onDrop={e => { e.preventDefault(); e.stopPropagation(); onDropBefore(app.slug); }}
         title={app.name}>
      <span className="app-tile-icon" style={{ background: app.color }}>
        {renderGlyph(app, 22)}
        {app.isBeta && <BetaBadge />}
        {app.isAi && <AiBadge />}
      </span>
      <span className="app-tile-name">{app.name}</span>
    </div>
  );
}

function DragChip({ app, dragging, onStart, onEnd, onDropBefore }) {
  return (
    <div className={"hidden-chip" + (dragging ? " dragging" : "")}
         draggable
         onDragStart={e => { e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", app.slug); onStart(app.slug); }}
         onDragEnd={onEnd}
         onDragOver={e => e.preventDefault()}
         onDrop={e => { e.preventDefault(); e.stopPropagation(); onDropBefore(app.slug); }}
         title={app.name}>
      <span className="hidden-chip-icon" style={{ background: app.color }}>
        {renderGlyph(app, 13)}
        {app.isBeta && <BetaBadge dot />}
        {app.isAi && <AiBadge dot />}
      </span>
      <span className="hidden-chip-name">{app.name}</span>
    </div>
  );
}

// ── App Launcher ──────────────────────────────────────────────────────────────
function SimpleTile({ app }) {
  return (
    <a className="app-tile" href={app.url && app.url !== '#' ? app.url : '#'}
       onClick={app.url === '#' ? e => e.preventDefault() : undefined}
       target={app.url && app.url !== '#' ? '_blank' : undefined}
       rel="noopener noreferrer" title={app.name}
       style={{ textDecoration: 'none' }}>
      <span className="app-tile-icon" style={{ background: app.color }}>
        {renderGlyph(app, 22)}
        {app.isBeta && <BetaBadge />}
        {app.isAi && <AiBadge />}
      </span>
      <span className="app-tile-name">{app.name}</span>
    </a>
  );
}

function AppLauncher({ visible, hidden, move, readonly }) {
  const dragId = useRef(null);
  const [draggingId, setDraggingId] = useState(null);
  const [overZone, setOverZone]     = useState(null);

  const start = id => { dragId.current = id; setDraggingId(id); };
  const end   = ()  => { dragId.current = null; setDraggingId(null); setOverZone(null); };
  const dropBefore = (list, beforeId) => { if (dragId.current) move(dragId.current, list, beforeId); end(); };
  const dropZone   = list => { if (dragId.current) move(dragId.current, list, null); end(); };

  if (readonly) {
    return (
      <React.Fragment>
        <div className="launcher-head">
          <span className="pop-title launcher-title">แอปของ RVC</span>
        </div>
        <div className="apps-grid">
          {visible.map(slug => APP_MAP[slug] && <SimpleTile key={slug} app={APP_MAP[slug]} />)}
        </div>
      </React.Fragment>
    );
  }

  return (
    <React.Fragment>
      <div className="launcher-head">
        <span className="pop-title launcher-title">แอปของ RVC</span>
        <span className="launcher-hint">ลากเพื่อจัดลำดับ</span>
      </div>
      <div className={"apps-grid drop-zone" + (overZone === "visible" ? " drag-over" : "")}
           onDragOver={e => { e.preventDefault(); setOverZone("visible"); }}
           onDragLeave={e => { if (!e.currentTarget.contains(e.relatedTarget)) setOverZone(null); }}
           onDrop={e => { e.preventDefault(); dropZone("visible"); }}>
        {visible.map(slug => (
          <DragTile key={slug} app={APP_MAP[slug]} dragging={draggingId === slug}
            onStart={start} onEnd={end} onDropBefore={b => dropBefore("visible", b)} />
        ))}
        {visible.length === 0 && <div className="zone-hint">ลากแอปมาที่นี่เพื่อแสดงในเมนู</div>}
      </div>

      <div className="hidden-zone-wrap">
        <div className="launcher-head">
          <span className="pop-title launcher-title">ซ่อนจากเมนู</span>
          {hidden.length > 0 && <span className="launcher-hint">{hidden.length} แอป</span>}
        </div>
        <div className={"hidden-zone drop-zone" + (overZone === "hidden" ? " drag-over" : "")}
             onDragOver={e => { e.preventDefault(); setOverZone("hidden"); }}
             onDragLeave={e => { if (!e.currentTarget.contains(e.relatedTarget)) setOverZone(null); }}
             onDrop={e => { e.preventDefault(); dropZone("hidden"); }}>
          {hidden.length === 0 && <div className="zone-hint">ลากแอปมาวางที่นี่เพื่อซ่อนจากเมนู</div>}
          {hidden.map(slug => (
            <DragChip key={slug} app={APP_MAP[slug]} dragging={draggingId === slug}
              onStart={start} onEnd={end} onDropBefore={b => dropBefore("hidden", b)} />
          ))}
        </div>
      </div>
    </React.Fragment>
  );
}

// ── Popover ───────────────────────────────────────────────────────────────────
function Popover({ open, onClose, anchorRef, children, align = "right", width }) {
  const ref = useRef(null);
  useEffect(() => {
    if (!open) return;
    const onDoc = e => {
      if (ref.current && !ref.current.contains(e.target) &&
          anchorRef.current && !anchorRef.current.contains(e.target)) onClose();
    };
    const onKey = e => { if (e.key === "Escape") onClose(); };
    document.addEventListener("mousedown", onDoc);
    document.addEventListener("keydown", onKey);
    return () => { document.removeEventListener("mousedown", onDoc); document.removeEventListener("keydown", onKey); };
  }, [open, onClose, anchorRef]);

  if (!open) return null;
  return <div ref={ref} className="popover" style={{ [align]: 0, width }}>{children}</div>;
}

// ── Header ────────────────────────────────────────────────────────────────────
function Header({ theme, setTheme, onHide, appsVisible, appsHidden, moveApp,
                  notifs, unreadCount, onMarkAllRead }) {
  const [openMenu, setOpenMenu] = useState(null);
  const [searchOpen, setSearchOpen] = useState(false);
  const [query, setQuery] = useState("");

  const appsRef = useRef(null), userRef = useRef(null),
        notifRef = useRef(null), themeRef = useRef(null),
        searchRef = useRef(null), searchInput = useRef(null);

  const toggle = m => setOpenMenu(prev => prev === m ? null : m);

  // Search via DuckDuckGo, restricted to the rvc.ac.th domain.
  const doSearch = () => {
    const q = query.trim();
    if (!q) { if (searchInput.current) searchInput.current.focus(); return; }
    const url = "https://duckduckgo.com/?q=" + encodeURIComponent(q + " site:rvc.ac.th");
    window.open(url, "_blank", "noopener");
  };

  useEffect(() => { if (searchOpen && searchInput.current) searchInput.current.focus(); }, [searchOpen]);

  useEffect(() => {
    if (!searchOpen) return;
    const onDoc = e => { if (searchRef.current && !searchRef.current.contains(e.target) && !query) setSearchOpen(false); };
    const onKey = e => { if (e.key === "Escape") setSearchOpen(false); };
    document.addEventListener("mousedown", onDoc);
    document.addEventListener("keydown", onKey);
    return () => { document.removeEventListener("mousedown", onDoc); document.removeEventListener("keydown", onKey); };
  }, [searchOpen, query]);

  const themeOpts = [
    { id: "light",  label: "สว่าง",   Ic: Icon.sun },
    { id: "dark",   label: "มืด",     Ic: Icon.moon },
    { id: "system", label: "ตามระบบ", Ic: Icon.monitor },
  ];
  const ThemeIc = theme === "light" ? Icon.sun : theme === "dark" ? Icon.moon : Icon.monitor;

  return (
    <header className="header">
      <div className="header-inner">

        {/* LEFT */}
        <div className="header-left">
          <a className="brand" href="#" onClick={e => e.preventDefault()}>
            <span className="brand-mark">RVC</span>
            <span className="brand-sub">Workspace</span>
          </a>
          <nav className="nav-links">
            <a className="nav-link active" href="#" onClick={e => e.preventDefault()}>
              <Icon.home /><span>Home</span>
            </a>
            {IS_ADMIN && (
              <a className="nav-link" href={BASE + "/admin/apps.php"}>
                <Icon.adminPanel /><span>จัดการระบบ</span>
              </a>
            )}
          </nav>
        </div>

        {/* RIGHT */}
        <div className="header-right">

          {/* SEARCH */}
          <form className={"search " + (searchOpen ? "open" : "")} ref={searchRef}
                role="search" onSubmit={e => { e.preventDefault(); doSearch(); }}>
            <button type="button" className="icon-btn search-trigger" aria-label="ค้นหา"
                    onClick={() => { if (searchOpen) doSearch(); else setSearchOpen(true); }}>
              <Icon.search />
            </button>
            <input ref={searchInput} className="search-input" type="text" enterKeyHint="search"
                   placeholder="ค้นหาในเว็บไซต์ RVC (rvc.ac.th)…"
                   value={query} onChange={e => setQuery(e.target.value)} />
            {searchOpen && (
              <button type="button" className="icon-btn search-clear" aria-label="ปิด"
                      onClick={() => { setQuery(""); setSearchOpen(false); }}>
                <Icon.close width="18" height="18" />
              </button>
            )}
          </form>

          {/* THEME */}
          <div className="popover-host">
            <button ref={themeRef} className={"icon-btn " + (openMenu==="theme" ? "is-active" : "")}
                    aria-label="ธีม" onClick={() => toggle("theme")}>
              <ThemeIc />
            </button>
            <Popover open={openMenu==="theme"} onClose={() => setOpenMenu(null)} anchorRef={themeRef} width={216}>
              <div className="pop-title">โทนสี</div>
              {themeOpts.map(o => (
                <button key={o.id} className="menu-row" onClick={() => { setTheme(o.id); setOpenMenu(null); }}>
                  <span className="menu-ico"><o.Ic /></span>
                  <span className="menu-label">{o.label}</span>
                  {theme === o.id && <span className="menu-check"><Icon.check /></span>}
                </button>
              ))}
            </Popover>
          </div>

          {/* NOTIFICATIONS — logged-in only */}
          {!IS_GUEST && (
            <div className="popover-host">
              <button ref={notifRef} className={"icon-btn " + (openMenu==="notif" ? "is-active" : "")}
                      aria-label="การแจ้งเตือน" onClick={() => toggle("notif")}>
                <Icon.bell />
                {unreadCount > 0 && <span className="badge">{unreadCount}</span>}
              </button>
              <Popover open={openMenu==="notif"} onClose={() => setOpenMenu(null)} anchorRef={notifRef} width={360}>
                <div className="pop-head">
                  <span className="pop-head-title">การแจ้งเตือน</span>
                  {unreadCount > 0 && (
                    <button className="pop-head-action" onClick={onMarkAllRead}>ทำเครื่องหมายอ่านแล้ว</button>
                  )}
                </div>
                <div className="notif-list">
                  {notifs.map(n => (
                    <a key={n.id} className="notif" href="#" onClick={e => e.preventDefault()}>
                      <span className="notif-dot-wrap">
                        <span className="notif-app" style={{ background: n.color }}>{(n.app||'?')[0]}</span>
                      </span>
                      <span className="notif-body">
                        <span className="notif-title">{n.title}</span>
                        <span className="notif-text">{n.body}</span>
                        <span className="notif-time">{n.time}</span>
                      </span>
                      {n.unread && unreadCount > 0 && <span className="notif-unread" />}
                    </a>
                  ))}
                  {notifs.length === 0 && <div style={{padding:'20px',textAlign:'center',color:'var(--text-3)',fontSize:'13.5px'}}>ไม่มีการแจ้งเตือน</div>}
                </div>
                <a className="pop-foot" href="#" onClick={e => e.preventDefault()}>ดูทั้งหมด</a>
              </Popover>
            </div>
          )}

          {/* APP LAUNCHER */}
          <div className="popover-host">
            <button ref={appsRef} className={"icon-btn " + (openMenu==="apps" ? "is-active" : "")}
                    aria-label="แอป RVC" onClick={() => toggle("apps")}>
              <Icon.grid />
            </button>
            <Popover open={openMenu==="apps"} onClose={() => setOpenMenu(null)} anchorRef={appsRef} width={320}>
              <AppLauncher visible={appsVisible} hidden={appsHidden} move={moveApp} readonly={IS_GUEST} />
            </Popover>
          </div>

          {/* USER or LOGIN */}
          {IS_GUEST ? (
            <a className="login-btn" href={BASE + "/login.php"}>เข้าสู่ระบบ</a>
          ) : (
            <div className="popover-host">
              <button ref={userRef} className={"avatar-btn " + (openMenu==="user" ? "is-active" : "")}
                      aria-label="บัญชีผู้ใช้" onClick={() => toggle("user")}>
                <span className="avatar" style={{ background: USER_DATA.avatarColor }}>{USER_DATA.initials}</span>
              </button>
              <Popover open={openMenu==="user"} onClose={() => setOpenMenu(null)} anchorRef={userRef} width={300}>
                <div className="user-head">
                  <span className="avatar avatar-lg" style={{ background: USER_DATA.avatarColor }}>{USER_DATA.initials}</span>
                  <div className="user-head-info">
                    <div className="user-name">{USER_DATA.name}</div>
                    <div className="user-email">{USER_DATA.email}</div>
                  </div>
                </div>
                <div className="menu-section">
                  <button className="menu-row"><span className="menu-ico"><Icon.user /></span><span className="menu-label">โปรไฟล์</span></button>
                  <a className="menu-row" href={BASE + "/account.php"} style={{textDecoration:'none'}}>
                    <span className="menu-ico"><Icon.key /></span>
                    <span className="menu-label">เปลี่ยนรหัสผ่าน</span>
                  </a>
                </div>
                <div className="menu-divider" />
                {IS_ADMIN && (
                  <React.Fragment>
                    <div className="menu-section">
                      <a className="menu-row" href={BASE + "/admin/apps.php"} style={{textDecoration:'none'}}>
                        <span className="menu-ico"><Icon.adminPanel /></span>
                        <span className="menu-label">จัดการระบบ</span>
                      </a>
                    </div>
                    <div className="menu-divider" />
                  </React.Fragment>
                )}
                <a className="menu-row menu-row-danger" href={BASE + "/logout.php"} style={{textDecoration:'none'}}>
                  <span className="menu-ico"><Icon.logout /></span>
                  <span className="menu-label">ออกจากระบบ</span>
                </a>
              </Popover>
            </div>
          )}

          <span className="header-sep" />
          <button className="icon-btn hide-btn" aria-label="ซ่อนแถบนำทาง" title="ซ่อนแถบนำทาง" onClick={onHide}>
            <Icon.chevronUp />
          </button>
        </div>
      </div>
    </header>
  );
}

// ── Demo page ─────────────────────────────────────────────────────────────────
function DemoPage({ visibleSlugs }) {
  const hour = new Date().getHours();
  const greeting = hour < 12 ? 'ตอนเช้า' : hour < 17 ? 'ตอนบ่าย' : 'ตอนเย็น';
  const title = IS_GUEST
    ? `สวัสดี${greeting}`
    : `สวัสดี${greeting}, ${USER_DATA.name.split(' ')[0]}`;
  const sub = IS_GUEST
    ? 'เลือกแอปจากไอคอนตารางมุมขวาบน หรือ เข้าสู่ระบบเพื่อปรับแต่งการแสดงผล'
    : 'เลือกแอปจากไอคอนตารางมุมขวาบน หรือเริ่มจากทางลัดด้านล่าง';
  return (
    <main className="page">
      <div className="page-inner">
        <div className="welcome">
          <div className="welcome-eyebrow">RVC WORKSPACE</div>
          <h1 className="welcome-title">{title}</h1>
          <p className="welcome-sub">{sub}</p>
        </div>
        <div className="quick-grid">
          {visibleSlugs.filter(s => APP_MAP[s]).map(slug => {
            const a = APP_MAP[slug];
            return (
              <a key={a.slug} className="quick-card"
                 href={a.url && a.url !== '#' ? a.url : '#'}
                 onClick={a.url === '#' ? e => e.preventDefault() : undefined}
                 target={a.url && a.url !== '#' ? '_blank' : undefined}
                 rel="noopener noreferrer">
                <span className="quick-icon" style={{ background: a.color }}>
                  {renderGlyph(a, 26)}
                  {a.isBeta && <BetaBadge />}
                  {a.isAi && <AiBadge />}
                </span>
                <span className="quick-meta">
                  <span className="quick-name">
                    {a.name}
                    {a.isBeta && <span className="quick-beta-tag">Beta</span>}
                    {a.isAi && <span className="quick-ai-tag">{AI_STAR} AI</span>}
                    <Icon.external className="quick-ext" />
                  </span>
                  <span className="quick-desc">{a.desc}</span>
                </span>
              </a>
            );
          })}
        </div>
      </div>
    </main>
  );
}

// ── Root ──────────────────────────────────────────────────────────────────────
function App() {
  const [theme, setTheme] = useState(() => localStorage.getItem("rvc-theme") || "system");
  const [systemDark, setSystemDark] = useState(() => window.matchMedia("(prefers-color-scheme: dark)").matches);
  const [navHidden, setNavHidden] = useState(false);
  const [apps, setApps] = useState({ visible: PREFS_DATA.visible, hidden: PREFS_DATA.hidden });
  const [notifs, setNotifs] = useState(NOTIFS_DATA);
  const skipSave = useRef(true);

  // System dark mode listener
  useEffect(() => {
    const mq = window.matchMedia("(prefers-color-scheme: dark)");
    const fn = e => setSystemDark(e.matches);
    mq.addEventListener("change", fn);
    return () => mq.removeEventListener("change", fn);
  }, []);

  // Apply theme
  const resolved = theme === "system" ? (systemDark ? "dark" : "light") : theme;
  useEffect(() => {
    document.documentElement.setAttribute("data-theme", resolved);
    localStorage.setItem("rvc-theme", theme);
  }, [resolved, theme]);

  // Save app prefs to server (skip initial render and guests)
  useEffect(() => {
    if (skipSave.current) { skipSave.current = false; return; }
    if (IS_GUEST) return;
    fetch(BASE + "/api/apps.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF },
      body: JSON.stringify({ action: "save_prefs", visible: apps.visible, hidden: apps.hidden }),
    }).catch(() => {});
  }, [apps]);

  const moveApp = useCallback((id, targetList, beforeId) => {
    if (id === beforeId) return;
    setApps(prev => {
      const visible = prev.visible.filter(x => x !== id);
      const hidden  = prev.hidden.filter(x => x !== id);
      const arr = targetList === "visible" ? visible : hidden;
      const idx = beforeId == null ? arr.length : arr.indexOf(beforeId);
      arr.splice(idx < 0 ? arr.length : idx, 0, id);
      return { visible, hidden };
    });
  }, []);

  const unreadCount = notifs.filter(n => n.unread).length;

  const markAllRead = useCallback(() => {
    setNotifs(prev => prev.map(n => ({ ...n, unread: false })));
    fetch(BASE + "/api/notifications.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", "X-CSRF-Token": CSRF },
      body: JSON.stringify({ action: "mark_read" }),
    }).catch(() => {});
  }, []);

  return (
    <React.Fragment>
      <div className={"header-wrap " + (navHidden ? "is-hidden" : "")}>
        <Header theme={theme} setTheme={setTheme}
                onHide={() => setNavHidden(true)}
                appsVisible={apps.visible} appsHidden={apps.hidden} moveApp={moveApp}
                notifs={notifs} unreadCount={unreadCount} onMarkAllRead={markAllRead} />
      </div>
      <button className={"nav-reveal " + (navHidden ? "show" : "")}
              onClick={() => setNavHidden(false)} aria-label="แสดงแถบนำทาง">
        <Icon.chevronDn /><span>แสดงแถบนำทาง</span>
      </button>
      <DemoPage visibleSlugs={apps.visible} />
    </React.Fragment>
  );
}

ReactDOM.createRoot(document.getElementById("root")).render(<App />);
</script>

<style>
/* ── Header ─────────────────────────────────────────────────────────────── */
.header-wrap {
  position: sticky; top: 0; z-index: 100; max-height: 64px; overflow: visible;
  transition: max-height .32s cubic-bezier(.4,0,.2,1), transform .32s cubic-bezier(.4,0,.2,1), opacity .24s ease;
}
.header-wrap.is-hidden { max-height: 0; transform: translateY(-100%); opacity: 0; pointer-events: none; overflow: hidden; }
.header {
  position: sticky; top: 0; z-index: 100;
  background: var(--header-bg);
  border-bottom: 1px solid var(--border);
  box-shadow: var(--shadow-header);
  transition: border-color .25s ease;
}
.header::after {
  content: ""; position: absolute; left: 0; right: 0; top: 100%; height: 1px;
  background: linear-gradient(90deg, transparent 8%, var(--glow) 40%, #9cbcff 50%, var(--glow) 60%, transparent 92%);
  box-shadow: 0 0 10px 0 var(--glow); opacity: .6; pointer-events: none;
}
.header-inner {
  height: 64px; max-width: 1480px; margin: 0 auto; padding: 0 20px;
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
}
.header-left  { display: flex; align-items: center; gap: 10px; min-width: 0; }
.header-right { display: flex; align-items: center; gap: 4px; }
.popover-host { position: relative; }

.brand { display: flex; align-items: baseline; gap: 9px; text-decoration: none; padding: 6px 8px 6px 4px; border-radius: 10px; }
.brand-mark { font-weight: 800; font-size: 22px; letter-spacing: -.5px; color: var(--brand); }
.brand-sub  { font-size: 13px; font-weight: 500; color: var(--text-3); }
@media (max-width: 560px) { .brand-sub { display: none; } }

.nav-links { display: flex; align-items: center; gap: 2px; margin-left: 6px; }
.nav-link {
  display: flex; align-items: center; gap: 7px; text-decoration: none;
  color: var(--text-2); font-size: 14.5px; font-weight: 600;
  padding: 8px 12px; border-radius: 9px;
  transition: background .15s, color .15s;
}
.nav-link:hover  { background: var(--hover); color: var(--text); }
.nav-link.active { color: var(--brand); background: var(--brand-tint); }
.nav-link svg { opacity: .9; }
@media (max-width: 680px) { .nav-link span { display: none; } }

.icon-btn {
  position: relative; display: inline-flex; align-items: center; justify-content: center;
  width: 42px; height: 42px; border: none; background: transparent; color: var(--text-2);
  border-radius: 50%; cursor: pointer; transition: background .15s, color .15s;
}
.icon-btn:hover     { background: var(--hover); color: var(--text); }
.icon-btn.is-active { background: var(--hover-strong); color: var(--text); }
.header-sep { width: 1px; height: 26px; background: var(--border); margin: 0 4px 0 6px; flex-shrink: 0; }
.hide-btn   { width: 38px; height: 38px; }

.nav-reveal {
  position: fixed; top: 0; left: 50%;
  transform: translateX(-50%) translateY(-120%);
  display: flex; align-items: center; gap: 6px;
  padding: 7px 16px 8px; border: 1px solid var(--border); border-top: none;
  background: var(--surface); color: var(--text-2);
  border-radius: 0 0 14px 14px; box-shadow: var(--shadow-pop);
  font-family: inherit; font-size: 13px; font-weight: 600;
  cursor: pointer; z-index: 99;
  transition: transform .32s cubic-bezier(.2,.8,.3,1), background .15s, color .15s;
}
.nav-reveal.show  { transform: translateX(-50%) translateY(0); }
.nav-reveal:hover { color: var(--text); background: var(--hover); }
.nav-reveal svg   { color: var(--brand); }

.badge {
  position: absolute; top: 5px; right: 4px;
  min-width: 17px; height: 17px; padding: 0 4px;
  background: #f43f5e; color: #fff; font-size: 10.5px; font-weight: 700;
  line-height: 17px; border-radius: 9px; text-align: center;
  border: 2px solid var(--header-bg);
}

/* ── Search ─────────────────────────────────────────────────────────────── */
.search { display: flex; align-items: center; position: relative; }
.search-input {
  width: 0; padding: 0; border: none; outline: none;
  background: var(--search-bg); color: var(--text);
  font-family: inherit; font-size: 14.5px; height: 42px; border-radius: 21px;
  opacity: 0; pointer-events: none;
  transition: width .28s cubic-bezier(.4,0,.2,1), padding .28s, opacity .2s, box-shadow .2s;
}
.search.open .search-input {
  width: 340px; padding: 0 46px 0 44px; opacity: 1; pointer-events: auto;
  background: var(--search-bg-focus);
  box-shadow: 0 0 0 1px var(--border-strong), 0 4px 18px rgba(20,30,55,.10);
}
.search.open .search-input:focus { box-shadow: 0 0 0 2px var(--ring), 0 4px 18px rgba(20,30,55,.10); }
.search-trigger { z-index: 2; }
.search.open .search-trigger { position: absolute; left: 1px; }
.search-clear   { position: absolute; right: 2px; width: 38px; height: 38px; z-index: 2; }
@media (max-width: 680px) { .search.open .search-input { width: 200px; } }
@media (max-width: 480px) { .search.open .search-input { width: 150px; } }

/* ── Popover ────────────────────────────────────────────────────────────── */
.popover {
  position: absolute; top: calc(100% + 10px);
  background: var(--surface); color: var(--text);
  border: 1px solid var(--border); border-radius: 16px;
  box-shadow: var(--shadow-pop); padding: 8px; z-index: 200;
  transform-origin: top right;
  animation: popIn .16s cubic-bezier(.2,.8,.3,1);
}
@keyframes popIn { from { opacity: 0; transform: translateY(-6px) scale(.97); } to { opacity: 1; transform: none; } }

.pop-title { font-size: 11.5px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--text-3); padding: 8px 12px 6px; }
.pop-head  { display: flex; align-items: center; justify-content: space-between; padding: 8px 8px 8px 12px; }
.pop-head-title  { font-size: 15px; font-weight: 700; color: var(--text); }
.pop-head-action { border: none; background: transparent; color: var(--brand); font-size: 12.5px; font-weight: 600; cursor: pointer; padding: 6px 8px; border-radius: 8px; }
.pop-head-action:hover { background: var(--hover); }

.menu-section { display: flex; flex-direction: column; }
.menu-row {
  display: flex; align-items: center; gap: 12px;
  width: 100%; border: none; background: transparent; text-align: left; cursor: pointer;
  padding: 10px 12px; border-radius: 10px; color: var(--text); font-size: 14.5px; font-weight: 500;
  transition: background .13s;
}
.menu-row:hover      { background: var(--hover); }
.menu-ico            { display: inline-flex; color: var(--text-2); width: 20px; justify-content: center; }
.menu-label          { flex: 1; }
.menu-check          { color: var(--brand); display: inline-flex; }
.menu-divider        { height: 1px; background: var(--border); margin: 6px 4px; }
.menu-row-danger     { color: #e5484d; }
.menu-row-danger .menu-ico { color: #e5484d; }
.menu-row-danger:hover     { background: rgba(229,72,77,.10); }

/* ── Notifications ──────────────────────────────────────────────────────── */
.notif-list  { display: flex; flex-direction: column; max-height: 360px; overflow: auto; }
.notif {
  display: flex; gap: 12px; align-items: flex-start;
  padding: 11px 12px; border-radius: 12px; text-decoration: none; position: relative;
  transition: background .13s;
}
.notif:hover { background: var(--hover); }
.notif-app {
  width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  color: #fff; font-weight: 700; font-size: 15px;
}
.notif-body  { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.notif-title { font-size: 14px; font-weight: 600; color: var(--text); line-height: 1.35; }
.notif-text  { font-size: 13px; color: var(--text-2); line-height: 1.35; }
.notif-time  { font-size: 11.5px; color: var(--text-3); margin-top: 2px; }
.notif-unread{ width: 8px; height: 8px; border-radius: 50%; background: var(--brand); flex-shrink: 0; margin-top: 6px; }
.pop-foot {
  display: block; text-align: center; padding: 11px; margin-top: 4px;
  color: var(--brand); font-size: 13.5px; font-weight: 600; text-decoration: none;
  border-top: 1px solid var(--border); border-radius: 0 0 10px 10px;
}
.pop-foot:hover { background: var(--hover); }

/* ── App Launcher ───────────────────────────────────────────────────────── */
.launcher-head  { display: flex; align-items: baseline; justify-content: space-between; padding: 8px 12px; }
.launcher-title { padding: 0; }
.launcher-hint  { font-size: 11.5px; font-weight: 500; color: var(--text-3); }
.apps-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 2px;
  padding: 4px; border-radius: 14px; transition: background .15s, box-shadow .15s;
}
.app-tile {
  display: flex; flex-direction: column; align-items: center; gap: 8px;
  padding: 14px 6px 12px; border-radius: 12px; cursor: grab; user-select: none;
  transition: background .13s, transform .13s, opacity .13s;
}
a.app-tile { cursor: pointer; }
.app-tile:hover    { background: var(--hover); }
.app-tile:active   { cursor: grabbing; }
.app-tile.dragging { opacity: .35; transform: scale(.94); }
.app-tile-icon {
  position: relative;
  width: 46px; height: 46px; border-radius: 14px; pointer-events: none;
  display: flex; align-items: center; justify-content: center;
  box-shadow: 0 2px 8px rgba(20,30,55,.14);
}
.app-icon-img {
  width: 100%; height: 100%; display: block;
  object-fit: cover; border-radius: inherit; pointer-events: none;
  image-rendering: auto;          /* smooth bilinear/bicubic scaling */
  -ms-interpolation-mode: bicubic;/* legacy fallback */
  -webkit-backface-visibility: hidden; backface-visibility: hidden;
  transform: translateZ(0);       /* GPU path → cleaner subpixel edges */
}
.app-tile-mono { color: #fff; font-weight: 800; font-size: 17px; letter-spacing: -.3px; }
.app-tile-name { font-size: 12.5px; font-weight: 600; color: var(--text); pointer-events: none; }
.drop-zone.drag-over { background: var(--brand-tint); box-shadow: inset 0 0 0 2px var(--brand); }
.zone-hint { width: 100%; text-align: center; color: var(--text-3); font-size: 12.5px; padding: 6px 8px; pointer-events: none; }
.hidden-zone-wrap { margin-top: 4px; padding: 0 4px 4px; }
.hidden-zone {
  min-height: 60px; background: var(--surface-2);
  border: 1.5px dashed var(--border-strong); border-radius: 14px;
  padding: 10px; display: flex; flex-wrap: wrap; gap: 8px; align-content: flex-start;
  transition: background .15s, box-shadow .15s, border-color .15s;
}
.hidden-zone.drag-over { border-color: var(--brand); }
.hidden-chip {
  display: flex; align-items: center; gap: 8px; padding: 6px 12px 6px 6px;
  background: var(--surface); border: 1px solid var(--border); border-radius: 999px;
  cursor: grab; user-select: none; transition: opacity .13s, transform .13s, box-shadow .15s;
}
.hidden-chip:hover    { box-shadow: 0 2px 8px rgba(20,30,55,.10); }
.hidden-chip:active   { cursor: grabbing; }
.hidden-chip.dragging { opacity: .35; transform: scale(.94); }
.hidden-chip-icon { position: relative; width: 24px; height: 24px; border-radius: 7px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; pointer-events: none; }
.hidden-chip-icon .app-tile-mono { font-size: 11px; }
.hidden-chip-name { font-size: 12.5px; font-weight: 600; color: var(--text-2); pointer-events: none; }

/* ── User menu ──────────────────────────────────────────────────────────── */
.login-btn {
  display: inline-flex; align-items: center; padding: 0 16px; height: 36px;
  background: var(--brand); color: #fff; border-radius: 8px;
  font-size: 13.5px; font-weight: 600; text-decoration: none; white-space: nowrap;
  transition: background .15s;
}
.login-btn:hover { background: var(--brand-600); }
.avatar-btn {
  border: none; background: transparent; cursor: pointer;
  padding: 3px; border-radius: 50%; margin-left: 2px; display: inline-flex;
  transition: box-shadow .15s;
}
.avatar-btn:hover     { box-shadow: 0 0 0 2px var(--border-strong); }
.avatar-btn.is-active { box-shadow: 0 0 0 2px var(--brand); }
.avatar {
  width: 34px; height: 34px; border-radius: 50%;
  background: var(--brand); color: #fff;
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; font-size: 15px;
}
.avatar-lg { width: 48px; height: 48px; font-size: 20px; }
.user-head       { display: flex; align-items: center; gap: 13px; padding: 12px 12px 14px; }
.user-head-info  { min-width: 0; }
.user-name       { font-size: 15.5px; font-weight: 700; color: var(--text); }
.user-email      { font-size: 13px; color: var(--text-2); }

/* ── Demo page ──────────────────────────────────────────────────────────── */
.page       { max-width: 1480px; margin: 0 auto; padding: 0 20px; }
.page-inner { padding: 56px 4px 80px; }
.welcome    { margin-bottom: 40px; }
.welcome-eyebrow { font-size: 12px; font-weight: 700; letter-spacing: 1.6px; color: var(--brand); margin-bottom: 12px; }
.welcome-title   { font-size: 38px; font-weight: 800; letter-spacing: -1px; margin: 0 0 10px; color: var(--text); }
.welcome-sub     { font-size: 16px; color: var(--text-2); margin: 0; max-width: 540px; }
.quick-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(248px, 1fr)); gap: 14px; }
.quick-card {
  display: flex; align-items: center; gap: 15px;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; padding: 18px; text-decoration: none;
  transition: transform .15s, box-shadow .15s, border-color .15s;
}
.quick-card:hover { transform: translateY(-2px); box-shadow: 0 10px 26px rgba(20,30,55,.10); border-color: var(--border-strong); }
.quick-icon { position: relative; width: 50px; height: 50px; border-radius: 15px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; box-shadow: 0 3px 10px rgba(20,30,55,.16); }
.quick-icon .app-tile-mono { font-size: 18px; }
.quick-meta { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.quick-name { display: flex; align-items: center; gap: 6px; font-size: 16px; font-weight: 700; color: var(--text); }
.quick-ext  { color: var(--text-3); }
.quick-desc { font-size: 13.5px; color: var(--text-2); }

/* ── Beta marker ────────────────────────────────────────────────────────── */
.beta-badge {
  position: absolute; top: -6px; right: -8px;
  padding: 1px 5px; border-radius: 6px;
  background: #f59e0b; color: #fff;
  font-size: 9px; font-weight: 800; letter-spacing: .4px; line-height: 1.5;
  border: 2px solid var(--surface);
  box-shadow: 0 1px 4px rgba(20,30,55,.25);
  pointer-events: none; text-transform: uppercase;
}
.beta-dot {
  position: absolute; top: -3px; right: -3px;
  width: 11px; height: 11px; border-radius: 50%;
  background: #f59e0b; border: 2px solid var(--surface);
  pointer-events: none;
}
.quick-beta-tag {
  padding: 1px 6px; border-radius: 5px;
  background: #fef3c7; color: #b45309;
  font-size: 10.5px; font-weight: 800; letter-spacing: .3px;
  text-transform: uppercase;
}
[data-theme="dark"] .quick-beta-tag { background: #3a2c10; color: #fbbf24; }

/* ── AI marker ──────────────────────────────────────────────────────────── */
.ai-badge {
  position: absolute; bottom: -5px; right: -5px;
  width: 16px; height: 16px; border-radius: 50%;
  background: #7c3aed; color: #fff;
  display: flex; align-items: center; justify-content: center;
  border: 2px solid var(--surface);
  box-shadow: 0 1px 4px rgba(20,30,55,.25);
  pointer-events: none;
}
.ai-dot {
  position: absolute; bottom: -3px; right: -3px;
  width: 12px; height: 12px; border-radius: 50%;
  background: #7c3aed; color: #fff;
  display: flex; align-items: center; justify-content: center;
  border: 1.5px solid var(--surface);
  pointer-events: none;
}
.ai-dot svg { width: 6px; height: 6px; }
.quick-ai-tag {
  padding: 1px 6px; border-radius: 5px;
  background: #ede9fe; color: #6d28d9;
  font-size: 10.5px; font-weight: 800; letter-spacing: .3px;
  display: inline-flex; align-items: center; gap: 3px;
}
[data-theme="dark"] .quick-ai-tag { background: #2e1a5e; color: #a78bfa; }
</style>
</body>
</html>
