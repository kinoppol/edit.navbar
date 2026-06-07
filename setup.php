<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// RVC Navbar — Database Setup
// Run once: http://localhost/edit.navbar/setup.php
// ---------------------------------------------------------------------------

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'rvc_navbar');
define('DB_USER', 'root');
define('DB_PASS', '');

// Derive APP_BASE (needed for link at end)
(function (): void {
    $docRoot = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appDir  = rtrim(str_replace('\\', '/', (string) realpath(__DIR__)), '/');
    define('APP_BASE', ($docRoot !== '' && str_starts_with($appDir, $docRoot))
        ? substr($appDir, strlen($docRoot))
        : '');
})();

$log = [];

function logLine(string $msg): void
{
    global $log;
    $log[] = $msg;
}

try {
    // Connect without selecting a database first
    $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Create database
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    logLine("✅ Database <code>" . DB_NAME . "</code> ready");

    $pdo->exec("USE `" . DB_NAME . "`");

    // ------------------------------------------------------------------ users
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        name          VARCHAR(100)    NOT NULL,
        email         VARCHAR(150)    NOT NULL,
        password_hash VARCHAR(255)    NOT NULL,
        initials      VARCHAR(10)     NOT NULL,
        avatar_color  VARCHAR(30)     NOT NULL DEFAULT '#2F5BEA',
        role          ENUM('user','admin') NOT NULL DEFAULT 'user',
        created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    logLine("✅ Table <code>users</code> ready");

    // ------------------------------------------------------------------- apps
    $pdo->exec("CREATE TABLE IF NOT EXISTS apps (
        id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
        slug        VARCHAR(50)     NOT NULL,
        name        VARCHAR(100)    NOT NULL,
        description VARCHAR(255)    NOT NULL DEFAULT '',
        color       VARCHAR(30)     NOT NULL DEFAULT '#2F5BEA',
        glyph_type  ENUM('icon','mono','image') NOT NULL DEFAULT 'mono',
        glyph       VARCHAR(255)    NOT NULL DEFAULT '?',
        url         VARCHAR(500)    NOT NULL DEFAULT '#',
        is_active   TINYINT(1)      NOT NULL DEFAULT 1,
        sort_order  INT             NOT NULL DEFAULT 0,
        created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_slug (slug)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    logLine("✅ Table <code>apps</code> ready");

    // Migration for existing installs: widen glyph + add 'image' type
    $pdo->exec("ALTER TABLE apps
        MODIFY COLUMN glyph_type ENUM('icon','mono','image') NOT NULL DEFAULT 'mono',
        MODIFY COLUMN glyph      VARCHAR(255) NOT NULL DEFAULT '?'");
    logLine("✅ Schema migration: glyph supports images");

    // --------------------------------------------------------- user_app_prefs
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_app_prefs (
        user_id    INT UNSIGNED NOT NULL,
        app_id     INT UNSIGNED NOT NULL,
        is_hidden  TINYINT(1)   NOT NULL DEFAULT 0,
        sort_order INT          NOT NULL DEFAULT 0,
        PRIMARY KEY (user_id, app_id),
        CONSTRAINT fk_prefs_user FOREIGN KEY (user_id) REFERENCES users  (id) ON DELETE CASCADE,
        CONSTRAINT fk_prefs_app  FOREIGN KEY (app_id)  REFERENCES apps   (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    logLine("✅ Table <code>user_app_prefs</code> ready");

    // --------------------------------------------------------- notifications
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
    logLine("✅ Table <code>notifications</code> ready");

    // ------------------------------------------------------------------ Seed apps
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
    logLine("✅ Apps seeded");

    // Build app-id map
    $appMap = [];
    foreach ($pdo->query("SELECT id, slug FROM apps")->fetchAll() as $row) {
        $appMap[$row['slug']] = (int) $row['id'];
    }

    // ------------------------------------------------------------------ Seed admin
    $adminEmail = 'admin@rvc.ac.th';
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$adminEmail]);
    if (!$stmt->fetch()) {
        $pdo->prepare("INSERT INTO users (name, email, password_hash, initials, avatar_color, role) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute(['RVC Admin', $adminEmail, password_hash('admin1234', PASSWORD_BCRYPT), 'A', '#0891b2', 'admin']);
        logLine("✅ Admin user created (<code>admin@rvc.ac.th</code> / <code>admin1234</code>)");
    } else {
        logLine("ℹ️  Admin user already exists");
    }

    // ------------------------------------------------------------------ Seed demo user
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
        logLine("✅ Demo user created (<code>nattawat@rvc.ac.th</code> / <code>user1234</code>)");
    } else {
        logLine("ℹ️  Demo user already exists");
    }

    // ------------------------------------------------------------------ Seed notifications
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
            logLine("✅ Demo notifications seeded");
        } else {
            logLine("ℹ️  Notifications already exist");
        }
    }

} catch (\Throwable $e) {
    $log[] = "❌ Error: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RVC Setup</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 560px; margin: 60px auto; padding: 0 20px; color: #1a1f29; }
  h1   { font-size: 22px; margin-bottom: 24px; }
  ul   { list-style: none; padding: 0; line-height: 2; }
  a    { color: #2F5BEA; }
  .box { background: #f6f8fb; border: 1px solid #e3e7ee; border-radius: 12px; padding: 20px 24px; margin-top: 24px; }
  code { background: #eaf0ff; padding: 1px 6px; border-radius: 4px; font-size: 13px; }
</style>
</head>
<body>
<h1>🛠 RVC Navbar — Setup</h1>
<ul>
<?php foreach ($log as $line): ?>
  <li><?= $line ?></li>
<?php endforeach; ?>
</ul>
<div class="box">
  <strong>ผู้ใช้ตัวอย่าง</strong><br><br>
  Admin&nbsp;&nbsp;: <code>admin@rvc.ac.th</code> / <code>admin1234</code><br>
  User&nbsp;&nbsp;&nbsp;: <code>nattawat@rvc.ac.th</code> / <code>user1234</code><br><br>
  <a href="<?= htmlspecialchars(APP_BASE . '/login.php') ?>">→ ไปที่หน้าเข้าสู่ระบบ</a>
</div>
</body>
</html>
