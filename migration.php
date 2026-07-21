<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// RVC Navbar — Schema migration runner
//
// Applies every schema change to an EXISTING install using the connection in
// config/db.php (+ config/db.local.php). Unlike setup.php it does not ask for
// credentials, does not create the database and does not seed demo data — so it
// is the safe thing to run on a live server after pulling new code.
//
// Every step is idempotent: re-running it is a no-op.
//
//   Browser : /edit.navbar/migration.php   (admin login required)
//   CLI     : php migration.php
// ---------------------------------------------------------------------------

require_once __DIR__ . '/config/db.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    require_once __DIR__ . '/config/auth.php';
    require_once __DIR__ . '/admin/_layout.php';
    $adminUser = require_admin();
}

/** @var string[] $log */
$log    = [];
$failed = null;

function mig_log(string $msg): void
{
    global $log;
    $log[] = $msg;
}

/** Run $fn only when $name has not been applied yet (checked by $fn itself). */
function mig_step(string $name, callable $fn, string $noop = 'มีอยู่แล้ว'): void
{
    $changed = $fn(db());
    mig_log(($changed ? '✅ ' : '↔︎ ') . $name . ($changed ? '' : ' (' . $noop . ')'));
}

function mig_has_table(PDO $pdo, string $table): bool
{
    return (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetch();
}

function mig_has_column(PDO $pdo, string $table, string $column): bool
{
    if (!mig_has_table($pdo, $table)) return false;
    return (bool) $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column))->fetch();
}

function mig_has_index(PDO $pdo, string $table, string $index): bool
{
    if (!mig_has_table($pdo, $table)) return false;
    return (bool) $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = " . $pdo->quote($index))->fetch();
}

try {
    if (!mig_has_table(db(), 'users') || !mig_has_table(db(), 'apps')) {
        throw new RuntimeException(
            'ยังไม่พบตารางหลัก (users / apps) — โปรดรัน setup.php เพื่อติดตั้งครั้งแรกก่อน'
        );
    }

    // ── apps: glyph supports uploaded images ─────────────────────────────────
    mig_step('apps.glyph_type รองรับชนิด image', function (PDO $pdo): bool {
        $col = $pdo->query("SHOW COLUMNS FROM apps LIKE 'glyph_type'")->fetch();
        if ($col && str_contains((string) $col['Type'], "'image'")) return false;
        $pdo->exec("ALTER TABLE apps
            MODIFY COLUMN glyph_type ENUM('icon','mono','image') NOT NULL DEFAULT 'mono',
            MODIFY COLUMN glyph      VARCHAR(255) NOT NULL DEFAULT '?'");
        return true;
    });

    // ── apps: badge flags ────────────────────────────────────────────────────
    mig_step('apps.is_beta', function (PDO $pdo): bool {
        if (mig_has_column($pdo, 'apps', 'is_beta')) return false;
        $pdo->exec("ALTER TABLE apps ADD COLUMN is_beta TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        return true;
    });

    mig_step('apps.is_ai', function (PDO $pdo): bool {
        if (mig_has_column($pdo, 'apps', 'is_ai')) return false;
        $pdo->exec("ALTER TABLE apps ADD COLUMN is_ai TINYINT(1) NOT NULL DEFAULT 0 AFTER is_beta");
        return true;
    });

    mig_step('apps.version_stage', function (PDO $pdo): bool {
        if (mig_has_column($pdo, 'apps', 'version_stage')) return false;
        $pdo->exec("ALTER TABLE apps ADD COLUMN version_stage VARCHAR(20) NOT NULL DEFAULT '' AFTER is_ai");
        $pdo->exec("UPDATE apps SET version_stage = 'beta' WHERE is_beta = 1 AND version_stage = ''");
        return true;
    });

    // ── users: username (external people_id, used by the RMS import) ─────────
    mig_step('users.username', function (PDO $pdo): bool {
        if (mig_has_column($pdo, 'users', 'username')) return false;
        $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(100) DEFAULT NULL AFTER id");
        return true;
    });

    mig_step('users ดัชนี uq_username', function (PDO $pdo): bool {
        if (mig_has_index($pdo, 'users', 'uq_username')) return false;
        $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uq_username (username)");
        return true;
    });

    // ── auth_tokens: "ลงชื่อค้างไว้" remember-me cookies ─────────────────────
    mig_step('ตาราง auth_tokens', function (PDO $pdo): bool {
        if (mig_has_table($pdo, 'auth_tokens')) return false;
        $pdo->exec("CREATE TABLE auth_tokens (
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
        return true;
    });

    // Housekeeping: drop expired remember-me tokens
    mig_step('ล้าง auth_tokens ที่หมดอายุ', function (PDO $pdo): bool {
        if (!mig_has_table($pdo, 'auth_tokens')) return false;
        return $pdo->exec("DELETE FROM auth_tokens WHERE expires_at < NOW()") > 0;
    }, 'ไม่มีรายการที่หมดอายุ');

    // ── settings: runtime config editable from /admin/settings.php ───────────
    mig_step('ตาราง settings', function (PDO $pdo): bool {
        if (mig_has_table($pdo, 'settings')) return false;
        $pdo->exec("CREATE TABLE settings (
            skey       VARCHAR(100) NOT NULL,
            svalue     TEXT         NOT NULL,
            updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (skey)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return true;
    });

    mig_step('ค่าเริ่มต้น user_import_base_url', function (PDO $pdo): bool {
        $stmt = $pdo->prepare("SELECT 1 FROM settings WHERE skey = ?");
        $stmt->execute(['user_import_base_url']);
        if ($stmt->fetch()) return false;
        $pdo->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?)")
            ->execute(['user_import_base_url', 'http://rms.rvc.ac.th']);
        return true;
    });

} catch (\Throwable $e) {
    $failed = $e->getMessage();
}

// ── Output ──────────────────────────────────────────────────────────────────
if ($isCli) {
    foreach ($log as $line) echo $line, PHP_EOL;
    if ($failed !== null) {
        fwrite(STDERR, 'ERROR: ' . $failed . PHP_EOL);
        exit(1);
    }
    echo 'เสร็จสิ้น' . PHP_EOL;
    exit(0);
}

admin_head('Migration', '', $adminUser);
$base = APP_BASE;
?>
<div class="a-page-head">
  <div>
    <h1 class="a-page-title">Database migration</h1>
    <p class="a-page-sub">ปรับโครงสร้างฐานข้อมูลให้ตรงกับโค้ดปัจจุบัน · รันซ้ำได้ ไม่มีผลข้างเคียง</p>
  </div>
  <a href="<?= e($base) ?>/admin/settings.php" class="a-btn a-btn-ghost">ไปหน้าตั้งค่า</a>
</div>

<?php if ($failed !== null): ?>
  <div class="a-alert a-alert-error">ไม่สำเร็จ: <?= e($failed) ?></div>
<?php else: ?>
  <div class="a-alert a-alert-success">อัปเดตโครงสร้างฐานข้อมูลเรียบร้อยแล้ว</div>
<?php endif; ?>

<div class="a-card">
  <?php if (empty($log)): ?>
    <div class="a-empty">ไม่มีขั้นตอนที่รัน</div>
  <?php else: ?>
    <div style="padding:20px 24px;font-size:14px;line-height:2">
      <?php foreach ($log as $line): ?>
        <div><?= e($line) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
