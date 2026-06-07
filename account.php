<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

$user = require_auth();          // redirects to login.php if not signed in
$uid  = (int) $user['id'];

$flash     = '';
$flashType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $current = (string) ($_POST['current_password'] ?? '');
    $next    = (string) ($_POST['new_password']     ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $errors = [];
    if ($current === '' || $next === '' || $confirm === '') {
        $errors[] = 'กรุณากรอกข้อมูลให้ครบทุกช่อง';
    }
    if ($next !== '' && mb_strlen($next) < 8) {
        $errors[] = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';
    }
    if ($next !== $confirm) {
        $errors[] = 'รหัสผ่านใหม่และการยืนยันไม่ตรงกัน';
    }
    if ($current !== '' && $next !== '' && $current === $next) {
        $errors[] = 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม';
    }

    if (empty($errors)) {
        try {
            $stmt = db()->prepare("SELECT password_hash FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$uid]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($current, $row['password_hash'])) {
                $errors[] = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
            } else {
                $newHash = password_hash($next, PASSWORD_BCRYPT);
                db()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
                    ->execute([$newHash, $uid]);
                // Refresh the session id after a credential change
                session_regenerate_id(true);
                $flash     = 'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';
                $flashType = 'success';
            }
        } catch (\Throwable $ex) {
            $errors[] = 'เกิดข้อผิดพลาด กรุณาลองใหม่';
        }
    }

    if (!empty($errors)) {
        $flash     = implode(' / ', $errors);
        $flashType = 'error';
    }
}

$csrf = csrf_token();
$base = APP_BASE;
?>
<!DOCTYPE html>
<html lang="th" id="html-root">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>เปลี่ยนรหัสผ่าน — RVC</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Noto+Sans+Thai:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>
(function(){
  var t = localStorage.getItem('rvc-theme') || 'system';
  var dark = t === 'dark' || (t === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
  if (dark) document.documentElement.setAttribute('data-theme', 'dark');
})();
</script>
<style>
  :root {
    --font-sans: 'Plus Jakarta Sans', 'Noto Sans Thai', system-ui, sans-serif;
    --brand: #2F5BEA; --brand-tint: #eaf0ff;
  }
  :root, [data-theme="light"] {
    --bg: #f1f3f6; --surface: #ffffff; --border: #e3e7ee; --border-strong: #d4dae5;
    --text: #1a1f29; --text-2: #5b6472; --text-3: #8a93a3;
    --hover: #f0f3f8; --input-bg: #f6f8fb;
    --shadow-card: 0 4px 32px rgba(20,30,55,.10), 0 1px 4px rgba(20,30,55,.06);
    --ring: rgba(47,91,234,.35);
  }
  [data-theme="dark"] {
    --bg: #0e1116; --surface: #171b22; --border: #262c37; --border-strong: #333b48;
    --text: #e8ecf2; --text-2: #a3adbd; --text-3: #6f7a8b;
    --hover: #20262f; --input-bg: #20262f;
    --shadow-card: 0 6px 40px rgba(0,0,0,.5), 0 1px 4px rgba(0,0,0,.3);
    --ring: rgba(108,150,255,.45);
    --brand: #6c96ff; --brand-tint: #1b2742;
  }
  *, *::before, *::after { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; min-height: 100vh; }
  body {
    font-family: var(--font-sans);
    background: var(--bg);
    color: var(--text);
    display: flex; align-items: center; justify-content: center;
    padding: 24px;
    -webkit-font-smoothing: antialiased;
  }
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: var(--shadow-card);
    padding: 36px 36px 32px;
    width: 100%; max-width: 420px;
  }
  .logo { text-align: center; margin-bottom: 22px; }
  .logo-mark { font-size: 28px; font-weight: 800; color: var(--brand); letter-spacing: -1px; }
  .logo-sub  { font-size: 13px; font-weight: 500; color: var(--text-3); margin-top: 2px; }
  h1 { font-size: 20px; font-weight: 700; margin: 0 0 4px; text-align: center; color: var(--text); }
  .who { text-align: center; font-size: 13.5px; color: var(--text-3); margin: 0 0 24px; }
  label { display: block; font-size: 13.5px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; }
  input[type="password"] {
    width: 100%; padding: 11px 14px;
    background: var(--input-bg); color: var(--text);
    border: 1px solid var(--border); border-radius: 10px;
    font-family: inherit; font-size: 14.5px;
    outline: none; transition: border-color .15s, box-shadow .15s;
  }
  input[type="password"]:focus {
    border-color: var(--brand); box-shadow: 0 0 0 3px var(--ring);
  }
  .field { margin-bottom: 16px; }
  .hint { font-size: 12px; color: var(--text-3); margin: 6px 0 0; }
  .alert {
    border-radius: 10px; padding: 10px 14px; font-size: 13.5px; font-weight: 500;
    margin-bottom: 18px;
  }
  .alert-error   { background: #fff1f2; border: 1px solid #fda4af; color: #be123c; }
  .alert-success { background: #ecfdf5; border: 1px solid #6ee7b7; color: #047857; }
  [data-theme="dark"] .alert-error   { background: #2d1218; border-color: #9f1239; color: #fca5a5; }
  [data-theme="dark"] .alert-success { background: #052e22; border-color: #065f46; color: #6ee7b7; }
  button[type="submit"] {
    width: 100%; padding: 12px;
    background: var(--brand); color: #fff;
    border: none; border-radius: 10px;
    font-family: inherit; font-size: 15px; font-weight: 700;
    cursor: pointer; margin-top: 8px;
    transition: opacity .15s, transform .1s;
  }
  button[type="submit"]:hover  { opacity: .9; }
  button[type="submit"]:active { transform: scale(.98); }
  .back {
    display: block; text-align: center; margin-top: 18px;
    font-size: 13.5px; font-weight: 600; color: var(--brand); text-decoration: none;
  }
  .back:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-mark">RVC</div>
    <div class="logo-sub">Workspace</div>
  </div>
  <h1>เปลี่ยนรหัสผ่าน</h1>
  <p class="who"><?= e($user['email']) ?></p>

  <?php if ($flash !== ''): ?>
    <div class="alert alert-<?= $flashType ?>"><?= e($flash) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="off" novalidate>
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <div class="field">
      <label for="current_password">รหัสผ่านปัจจุบัน</label>
      <input type="password" id="current_password" name="current_password"
             placeholder="••••••••" autocomplete="current-password" required>
    </div>
    <div class="field">
      <label for="new_password">รหัสผ่านใหม่</label>
      <input type="password" id="new_password" name="new_password"
             placeholder="••••••••" autocomplete="new-password" required>
      <p class="hint">อย่างน้อย 8 ตัวอักษร</p>
    </div>
    <div class="field">
      <label for="confirm_password">ยืนยันรหัสผ่านใหม่</label>
      <input type="password" id="confirm_password" name="confirm_password"
             placeholder="••••••••" autocomplete="new-password" required>
    </div>
    <button type="submit">บันทึกรหัสผ่านใหม่</button>
  </form>

  <a class="back" href="<?= e($base) ?>/index.php">← กลับสู่ Workspace</a>
</div>
</body>
</html>
