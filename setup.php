<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// RVC Navbar — Database Setup
// Open in a browser, fill in the connection form, and click "ติดตั้ง".
// On success the values are written to config/db.local.php (used at runtime)
// and the schema is created/migrated and seeded. Safe to re-run (idempotent).
// ---------------------------------------------------------------------------

// Derive APP_BASE (for the links at the end)
(function (): void {
    $docRoot = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appDir  = rtrim(str_replace('\\', '/', (string) realpath(__DIR__)), '/');
    define('APP_BASE', ($docRoot !== '' && str_starts_with($appDir, $docRoot))
        ? substr($appDir, strlen($docRoot))
        : '');
})();

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

const LOCAL_CFG = __DIR__ . '/config/db.local.php';

// Default connection settings (local dev). Overridden by config/db.local.php.
$defaults = ['host' => 'localhost', 'port' => 3306, 'name' => 'rvc_navbar', 'user' => 'root', 'pass' => ''];

// Current values for prefilling the form (existing override → defaults).
$form = $defaults;
if (is_file(LOCAL_CFG)) {
    $existing = require LOCAL_CFG;
    if (is_array($existing)) {
        $form = array_merge($form, $existing);
    }
}

$log          = [];
$ran          = false;   // did we attempt setup this request?
$ok           = false;   // did setup complete without error?
$savedConfig  = false;   // was config/db.local.php written?
$configContent = '';     // generated config (shown if the write failed)

function logLine(string $msg): void
{
    global $log;
    $log[] = $msg;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ran  = true;
    $form = [
        'host' => trim((string) ($_POST['db_host'] ?? '')),
        'port' => (int) ($_POST['db_port'] ?? 0),
        'name' => trim((string) ($_POST['db_name'] ?? '')),
        'user' => trim((string) ($_POST['db_user'] ?? '')),
        'pass' => (string) ($_POST['db_pass'] ?? ''),
    ];

    // ── Validate ───────────────────────────────────────────────────────────
    $errors = [];
    if ($form['host'] === '')                                   $errors[] = 'กรุณาระบุ Host';
    if ($form['port'] < 1 || $form['port'] > 65535)             $errors[] = 'Port ต้องอยู่ระหว่าง 1–65535';
    if (!preg_match('/^[A-Za-z0-9_]+$/', $form['name']))        $errors[] = 'ชื่อฐานข้อมูลใช้ได้เฉพาะ A–Z, 0–9 และ _';
    if ($form['user'] === '')                                   $errors[] = 'กรุณาระบุ Username';

    if ($errors) {
        foreach ($errors as $err) {
            logLine('❌ ' . h($err));
        }
    } else {
        // ── Run setup with the submitted credentials ───────────────────────
        try {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $form['host'], $form['port']);
            $pdo = new PDO($dsn, $form['user'], $form['pass'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            logLine('✅ เชื่อมต่อ MySQL ที่ <code>' . h($form['host'] . ':' . $form['port']) . '</code> สำเร็จ');

            // Create database
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$form['name']}`
                CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            logLine('✅ Database <code>' . h($form['name']) . '</code> ready');
            $pdo->exec("USE `{$form['name']}`");

            // ---------------------------------------------------------- users
            $pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                username      VARCHAR(100)    DEFAULT NULL,
                name          VARCHAR(100)    NOT NULL,
                email         VARCHAR(150)    NOT NULL,
                password_hash VARCHAR(255)    NOT NULL,
                initials      VARCHAR(10)     NOT NULL,
                avatar_color  VARCHAR(30)     NOT NULL DEFAULT '#2F5BEA',
                role          ENUM('user','admin') NOT NULL DEFAULT 'user',
                created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_email (email),
                UNIQUE KEY uq_username (username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            logLine('✅ Table <code>users</code> ready');

            // Migration for existing installs: add username (external people_id)
            if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'username'")->fetch()) {
                $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(100) DEFAULT NULL AFTER id");
                $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uq_username (username)");
            }
            logLine('✅ Schema migration: users support username');

            // ---------------------------------------------------- auth_tokens
            // "ลงชื่อค้างไว้" — selector/validator pairs; validator stored hashed
            $pdo->exec("CREATE TABLE IF NOT EXISTS auth_tokens (
                id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id        INT UNSIGNED NOT NULL,
                selector       VARCHAR(32)  NOT NULL,
                validator_hash CHAR(64)     NOT NULL,
                expires_at     DATETIME     NOT NULL,
                created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_selector (selector),
                CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            logLine('✅ Table <code>auth_tokens</code> ready');

            // ------------------------------------------------------- settings
            $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                skey       VARCHAR(100) NOT NULL,
                svalue     TEXT         NOT NULL,
                updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (skey)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $pdo->prepare("INSERT IGNORE INTO settings (skey, svalue) VALUES (?, ?)")
                ->execute(['user_import_base_url', 'http://rms.rvc.ac.th']);
            logLine('✅ Table <code>settings</code> ready');

            // ----------------------------------------------------------- apps
            $pdo->exec("CREATE TABLE IF NOT EXISTS apps (
                id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
                slug        VARCHAR(50)     NOT NULL,
                name        VARCHAR(100)    NOT NULL,
                description VARCHAR(255)    NOT NULL DEFAULT '',
                color       VARCHAR(30)     NOT NULL DEFAULT '#2F5BEA',
                glyph_type  ENUM('icon','mono','image') NOT NULL DEFAULT 'mono',
                glyph       VARCHAR(255)    NOT NULL DEFAULT '?',
                url         VARCHAR(500)    NOT NULL DEFAULT '#',
                is_active     TINYINT(1)      NOT NULL DEFAULT 1,
                is_beta       TINYINT(1)      NOT NULL DEFAULT 0,
                is_ai         TINYINT(1)      NOT NULL DEFAULT 0,
                version_stage VARCHAR(20)     NOT NULL DEFAULT '',
                sort_order    INT             NOT NULL DEFAULT 0,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            logLine('✅ Table <code>apps</code> ready');

            // Migration for existing installs: widen glyph + add 'image' type
            $pdo->exec("ALTER TABLE apps
                MODIFY COLUMN glyph_type ENUM('icon','mono','image') NOT NULL DEFAULT 'mono',
                MODIFY COLUMN glyph      VARCHAR(255) NOT NULL DEFAULT '?'");
            logLine('✅ Schema migration: glyph supports images');

            // Migration for existing installs: add is_beta flag
            $hasBeta = $pdo->query("SHOW COLUMNS FROM apps LIKE 'is_beta'")->fetch();
            if (!$hasBeta) {
                $pdo->exec("ALTER TABLE apps ADD COLUMN is_beta TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
            }
            logLine('✅ Schema migration: apps support beta flag');

            // Migration for existing installs: add is_ai flag
            $hasAi = $pdo->query("SHOW COLUMNS FROM apps LIKE 'is_ai'")->fetch();
            if (!$hasAi) {
                $pdo->exec("ALTER TABLE apps ADD COLUMN is_ai TINYINT(1) NOT NULL DEFAULT 0 AFTER is_beta");
            }
            logLine('✅ Schema migration: apps support AI flag');

            // Migration: add version_stage, migrate is_beta=1 → version_stage='beta'
            $hasStage = $pdo->query("SHOW COLUMNS FROM apps LIKE 'version_stage'")->fetch();
            if (!$hasStage) {
                $pdo->exec("ALTER TABLE apps ADD COLUMN version_stage VARCHAR(20) NOT NULL DEFAULT '' AFTER is_ai");
                $pdo->exec("UPDATE apps SET version_stage = 'beta' WHERE is_beta = 1 AND version_stage = ''");
            }
            logLine('✅ Schema migration: apps support version_stage');

            // ------------------------------------------------- user_app_prefs
            $pdo->exec("CREATE TABLE IF NOT EXISTS user_app_prefs (
                user_id    INT UNSIGNED NOT NULL,
                app_id     INT UNSIGNED NOT NULL,
                is_hidden  TINYINT(1)   NOT NULL DEFAULT 0,
                sort_order INT          NOT NULL DEFAULT 0,
                PRIMARY KEY (user_id, app_id),
                CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users  (id) ON DELETE CASCADE,
                CONSTRAINT fk_prefs_app  FOREIGN KEY (app_id)  REFERENCES apps   (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            logLine('✅ Table <code>user_app_prefs</code> ready');

            // ------------------------------------------------- notifications
            $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
                id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id    INT UNSIGNED NOT NULL,
                app_id     INT UNSIGNED DEFAULT NULL,
                title      VARCHAR(255) NOT NULL,
                body       TEXT         NOT NULL DEFAULT '',
                is_read    TINYINT(1)   NOT NULL DEFAULT 0,
                created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_notif_app  FOREIGN KEY (app_id)  REFERENCES apps  (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            logLine('✅ Table <code>notifications</code> ready');

            // -------------------------------------------------------- Seed apps
            $seedApps = [
                ['meet',  'Meet',  'ประชุมออนไลน์',    '#16a34a', 'icon', 'meet', '#', 1, 0],
                ['t',     'T',     'เครื่องมือภายใน',  '#ea580c', 'mono', 'T',    '#', 1, 1],
                ['test',  'Test',  'ระบบทดสอบ',        '#7c3aed', 'mono', 'Te',   '#', 1, 2],
                ['lgaie', 'LGAIE', 'AI Engine',        '#db2777', 'mono', 'LG',   '#', 1, 3],
                ['rms',   'RMS',   'Resource Mgmt',    '#0891b2', 'mono', 'R',    '#', 1, 4],
            ];
            $ins = $pdo->prepare("INSERT IGNORE INTO apps
                (slug, name, description, color, glyph_type, glyph, url, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($seedApps as $a) {
                $ins->execute($a);
            }
            logLine('✅ Apps seeded');

            // Build app-id map
            $appMap = [];
            foreach ($pdo->query("SELECT id, slug FROM apps")->fetchAll() as $row) {
                $appMap[$row['slug']] = (int) $row['id'];
            }

            // ------------------------------------------------------- Seed admin
            $adminEmail = 'admin@rvc.ac.th';
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$adminEmail]);
            if (!$stmt->fetch()) {
                $pdo->prepare("INSERT INTO users (name, email, password_hash, initials, avatar_color, role) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute(['RVC Admin', $adminEmail, password_hash('admin1234', PASSWORD_BCRYPT), 'A', '#0891b2', 'admin']);
                logLine('✅ Admin user created (<code>admin@rvc.ac.th</code> / <code>admin1234</code>)');
            } else {
                logLine('ℹ️  Admin user already exists');
            }

            // --------------------------------------------------- Seed demo user
            $demoEmail = 'nattawat@rvc.ac.th';
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$demoEmail]);
            $demoUser = $stmt->fetch();
            if (!$demoUser) {
                $pdo->prepare("INSERT INTO users (name, email, password_hash, initials, avatar_color, role) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute(['ณัฐวัฒน์ ศรีสุข', $demoEmail, password_hash('user1234', PASSWORD_BCRYPT), 'ณ', '#2F5BEA', 'user']);
                $stmt2 = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                $stmt2->execute([$demoEmail]);
                $demoUser = $stmt2->fetch();
                logLine('✅ Demo user created (<code>nattawat@rvc.ac.th</code> / <code>user1234</code>)');
            } else {
                logLine('ℹ️  Demo user already exists');
            }

            // ----------------------------------------------- Seed notifications
            if ($demoUser) {
                $uid = (int) $demoUser['id'];
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
                $stmt->execute([$uid]);
                if ((int) $stmt->fetchColumn() === 0) {
                    $notifs = [
                        [$uid, $appMap['meet']  ?? null, 'ประชุมทีมเริ่มใน 10 นาที',      'RVC Weekly Sync · ห้อง A-301',           0],
                        [$uid, $appMap['rms']   ?? null, 'คำขออนุมัติทรัพยากรใหม่',        'พิมพ์ชนก ขออนุมัติเซิร์ฟเวอร์ #221',    0],
                        [$uid, $appMap['lgaie'] ?? null, 'การประมวลผลโมเดลเสร็จสิ้น',      'งาน train-batch-08 สำเร็จ',              1],
                    ];
                    $ins2 = $pdo->prepare("INSERT INTO notifications (user_id, app_id, title, body, is_read) VALUES (?, ?, ?, ?, ?)");
                    foreach ($notifs as $n) {
                        $ins2->execute($n);
                    }
                    logLine('✅ Demo notifications seeded');
                } else {
                    logLine('ℹ️  Notifications already exist');
                }
            }

            $ok = true;
        } catch (\Throwable $e) {
            logLine('❌ เกิดข้อผิดพลาด: ' . h($e->getMessage()));
        }

        // ── Persist connection settings for the app to use at runtime ──────
        if ($ok) {
            $cfgToSave = [
                'host' => $form['host'],
                'port' => (int) $form['port'],
                'name' => $form['name'],
                'user' => $form['user'],
                'pass' => $form['pass'],
            ];
            $configContent = "<?php\n\n"
                . "// Generated by setup.php on " . date('c') . "\n"
                . "// RVC Navbar — database connection overrides (loaded by config/db.php).\n"
                . "// This file holds credentials; keep it out of version control.\n\n"
                . "return " . var_export($cfgToSave, true) . ";\n";

            if (@file_put_contents(LOCAL_CFG, $configContent, LOCK_EX) !== false) {
                $savedConfig = true;
                logLine('✅ บันทึกการตั้งค่าไปที่ <code>config/db.local.php</code> แล้ว');
            } else {
                logLine('⚠️ บันทึก <code>config/db.local.php</code> ไม่ได้ (สิทธิ์การเขียน) — โปรดสร้างไฟล์เองตามเนื้อหาด้านล่าง');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RVC Setup</title>
<style>
  :root { --brand:#2F5BEA; --border:#e3e7ee; --text:#1a1f29; --text-2:#5b6472; --text-3:#8a93a3; }
  * { box-sizing: border-box; }
  body { font-family: system-ui, 'Segoe UI', sans-serif; max-width: 620px; margin: 48px auto; padding: 0 20px; color: var(--text); }
  h1 { font-size: 22px; margin-bottom: 6px; }
  .sub { color: var(--text-2); margin: 0 0 28px; font-size: 14px; }
  form { background:#fff; border:1px solid var(--border); border-radius:14px; padding:24px; }
  .row { display:flex; gap:14px; }
  .row > .field { flex:1; }
  .field { margin-bottom:16px; }
  label { display:block; font-size:13px; font-weight:600; color:var(--text-2); margin-bottom:6px; }
  input { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:9px; font-size:14px; font-family:inherit; outline:none; }
  input:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(47,91,234,.18); }
  .hint { font-size:12px; color:var(--text-3); margin-top:5px; }
  button { width:100%; padding:12px; margin-top:6px; background:var(--brand); color:#fff; border:none; border-radius:9px; font-size:15px; font-weight:700; cursor:pointer; }
  button:hover { opacity:.92; }
  ul { list-style:none; padding:0; line-height:1.9; font-size:14px; }
  a { color:var(--brand); }
  .box { background:#f6f8fb; border:1px solid var(--border); border-radius:12px; padding:18px 22px; margin-top:22px; }
  .box.ok   { background:#ecfdf5; border-color:#a7f3d0; }
  .box.warn { background:#fffbeb; border-color:#fde68a; }
  code { background:#eaf0ff; padding:1px 6px; border-radius:4px; font-size:13px; }
  pre { background:#0e1116; color:#e8ecf2; padding:16px; border-radius:10px; overflow:auto; font-size:12.5px; }
</style>
</head>
<body>
<h1>🛠 RVC Navbar — ติดตั้งฐานข้อมูล</h1>
<p class="sub">กรอกค่าการเชื่อมต่อฐานข้อมูลของเซิร์ฟเวอร์ แล้วกด “ติดตั้ง” ระบบจะสร้าง/อัปเดตตาราง เพิ่มข้อมูลตัวอย่าง และบันทึกค่าให้เว็บใช้งานอัตโนมัติ</p>

<?php if ($ran): ?>
  <div class="box <?= $ok ? 'ok' : 'warn' ?>">
    <strong><?= $ok ? '✅ ติดตั้งสำเร็จ' : '⚠️ ติดตั้งไม่สำเร็จ' ?></strong>
    <ul style="margin-top:10px">
      <?php foreach ($log as $line): ?>
        <li><?= $line ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($ok && !$savedConfig && $configContent !== ''): ?>
      <p style="margin:14px 0 6px;font-weight:600">สร้างไฟล์ <code>config/db.local.php</code> ด้วยเนื้อหานี้:</p>
      <pre><?= h($configContent) ?></pre>
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="POST" autocomplete="off" style="margin-top:22px">
  <div class="row">
    <div class="field">
      <label for="db_host">Host</label>
      <input id="db_host" name="db_host" value="<?= h((string) $form['host']) ?>" placeholder="localhost หรือ 127.0.0.1" required>
    </div>
    <div class="field" style="max-width:140px">
      <label for="db_port">Port</label>
      <input id="db_port" name="db_port" type="number" min="1" max="65535" value="<?= h((string) $form['port']) ?>" required>
    </div>
  </div>

  <div class="field">
    <label for="db_name">ชื่อฐานข้อมูล (Database)</label>
    <input id="db_name" name="db_name" value="<?= h((string) $form['name']) ?>" placeholder="rvc_navbar" required>
    <p class="hint">ใช้ได้เฉพาะตัวอักษร A–Z, ตัวเลข และ _ — ถ้ายังไม่มีจะถูกสร้างให้</p>
  </div>

  <div class="row">
    <div class="field">
      <label for="db_user">Username</label>
      <input id="db_user" name="db_user" value="<?= h((string) $form['user']) ?>" placeholder="เช่น rvc_app" required>
    </div>
    <div class="field">
      <label for="db_pass">Password</label>
      <input id="db_pass" name="db_pass" type="password" value="<?= h((string) $form['pass']) ?>" placeholder="(เว้นว่างได้)">
    </div>
  </div>

  <button type="submit">ติดตั้ง / บันทึกการตั้งค่า</button>
</form>

<?php if ($ok): ?>
<div class="box">
  <strong>ผู้ใช้ตัวอย่าง</strong><br><br>
  Admin&nbsp;&nbsp;: <code>admin@rvc.ac.th</code> / <code>admin1234</code><br>
  User&nbsp;&nbsp;&nbsp;: <code>nattawat@rvc.ac.th</code> / <code>user1234</code><br><br>
  <a href="<?= h(APP_BASE . '/login.php') ?>">→ ไปที่หน้าเข้าสู่ระบบ</a>
</div>
<div class="box warn">
  ⚠️ <strong>ความปลอดภัย:</strong> บนเซิร์ฟเวอร์จริง ควร <strong>ลบหรือจำกัดสิทธิ์การเข้าถึง <code>setup.php</code></strong> หลังติดตั้งเสร็จ และเปลี่ยนรหัสผ่านผู้ใช้ตัวอย่างทันที
</div>
<?php endif; ?>
</body>
</html>
