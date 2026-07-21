<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

session_init();

// Already logged in → go to app
if (!empty($_SESSION['user'])) {
    header('Location: ' . APP_BASE . '/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
        $error = 'กรุณากรอกอีเมลและรหัสผ่าน';
    } else {
        try {
            // Imported users sign in with their people_id (username) or their email
            try {
                $stmt = db()->prepare("SELECT id, name, email, password_hash, initials, avatar_color, role
                                       FROM users WHERE email = ? OR username = ? LIMIT 1");
                $stmt->execute([$email, $email]);
            } catch (\PDOException $e) {
                // Install predates the username column (run setup.php to migrate)
                $stmt = db()->prepare("SELECT id, name, email, password_hash, initials, avatar_color, role
                                       FROM users WHERE email = ? LIMIT 1");
                $stmt->execute([$email]);
            }
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['user'] = [
                    'id'           => (int) $user['id'],
                    'name'         => $user['name'],
                    'email'        => $user['email'],
                    'initials'     => $user['initials'],
                    'avatar_color' => $user['avatar_color'],
                    'role'         => $user['role'],
                ];
                if (!empty($_POST['remember'])) {
                    remember_issue((int) $user['id']);
                }
                header('Location: ' . APP_BASE . '/index.php');
                exit;
            } else {
                // Deliberate timing parity
                password_verify('dummy', '$2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ01234');
                $error = 'อีเมลหรือรหัสผ่านไม่ถูกต้อง';
            }
        } catch (\Throwable $e) {
            $error = 'เกิดข้อผิดพลาด กรุณาลองใหม่';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th" id="html-root">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>เข้าสู่ระบบ — RVC</title>
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
    -webkit-font-smoothing: antialiased;
  }
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: var(--shadow-card);
    padding: 40px 40px 36px;
    width: 100%; max-width: 400px;
  }
  .logo { text-align: center; margin-bottom: 28px; }
  .logo-mark { font-size: 30px; font-weight: 800; color: var(--brand); letter-spacing: -1px; }
  .logo-sub  { font-size: 13px; font-weight: 500; color: var(--text-3); margin-top: 2px; }
  h1 { font-size: 20px; font-weight: 700; margin: 0 0 24px; text-align: center; color: var(--text); }
  label { display: block; font-size: 13.5px; font-weight: 600; color: var(--text-2); margin-bottom: 6px; }
  input[type="email"], input[type="password"] {
    width: 100%; padding: 11px 14px;
    background: var(--input-bg); color: var(--text);
    border: 1px solid var(--border); border-radius: 10px;
    font-family: inherit; font-size: 14.5px;
    outline: none; transition: border-color .15s, box-shadow .15s;
  }
  input[type="email"]:focus, input[type="password"]:focus {
    border-color: var(--brand); box-shadow: 0 0 0 3px var(--ring);
  }
  .field { margin-bottom: 16px; }
  .error {
    background: #fff1f2; border: 1px solid #fda4af; color: #be123c;
    border-radius: 10px; padding: 10px 14px; font-size: 13.5px; font-weight: 500;
    margin-bottom: 18px;
  }
  [data-theme="dark"] .error {
    background: #2d1218; border-color: #9f1239; color: #fca5a5;
  }
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
  input[type="text"] {
    width: 100%; padding: 11px 14px;
    background: var(--input-bg); color: var(--text);
    border: 1px solid var(--border); border-radius: 10px;
    font-family: inherit; font-size: 14.5px;
    outline: none; transition: border-color .15s, box-shadow .15s;
  }
  input[type="text"]:focus { border-color: var(--brand); box-shadow: 0 0 0 3px var(--ring); }
  .remember {
    display: flex; align-items: center; gap: 9px;
    font-size: 13.5px; font-weight: 500; color: var(--text-2);
    margin: 2px 0 4px; cursor: pointer;
  }
  .remember input { width: 16px; height: 16px; accent-color: var(--brand); cursor: pointer; margin: 0; }
  .back-link {
    display: block; text-align: center; margin-top: 22px;
    font-size: 13.5px; font-weight: 600; color: var(--text-2);
    text-decoration: none; transition: color .15s;
  }
  .back-link:hover { color: var(--brand); }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-mark">RVC</div>
    <div class="logo-sub">Workspace</div>
  </div>
  <h1>เข้าสู่ระบบ</h1>

  <?php if ($error !== ''): ?>
    <div class="error"><?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST" autocomplete="on" novalidate>
    <div class="field">
      <label for="email">อีเมลหรือรหัสประจำตัว</label>
      <input type="text" id="email" name="email"
             value="<?= e($_POST['email'] ?? '') ?>"
             placeholder="you@rvc.ac.th หรือ 1440100096241"
             autocomplete="username" required>
    </div>
    <div class="field">
      <label for="password">รหัสผ่าน</label>
      <input type="password" id="password" name="password"
             placeholder="••••••••" autocomplete="current-password" required>
    </div>
    <label class="remember">
      <input type="checkbox" name="remember" value="1" <?= !empty($_POST['remember']) ? 'checked' : '' ?>>
      <span>ลงชื่อเข้าใช้ค้างไว้ (30 วัน)</span>
    </label>
    <button type="submit">เข้าสู่ระบบ</button>
  </form>

  <a class="back-link" href="<?= e(APP_BASE) ?>/index.php">← กลับหน้าแอปพลิเคชัน</a>
</div>
</body>
</html>
