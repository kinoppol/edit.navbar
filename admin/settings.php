<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/auth.php';
require_once dirname(__DIR__) . '/config/settings.php';
require_once dirname(__DIR__) . '/config/user_import.php';
require_once __DIR__ . '/_layout.php';

$adminUser = require_admin();

$flash     = '';
$flashType = 'success';
$result    = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_import_url') {
        $url = trim($_POST['base_url'] ?? '');
        $url = rtrim($url, '/');
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $url)) {
            $flash     = 'URL ไม่ถูกต้อง — ต้องขึ้นต้นด้วย http:// หรือ https:// เช่น http://rms.rvc.ac.th';
            $flashType = 'error';
        } else {
            rvc_setting_set(RVC_SETTING_IMPORT_BASE_URL, $url);
            $flash = 'บันทึกแหล่งข้อมูลเรียบร้อยแล้ว';
        }

    } elseif ($action === 'run_import') {
        $updatePasswords = !empty($_POST['update_passwords']);
        try {
            $result = rvc_user_import_run($updatePasswords);
            $flash  = 'โอนข้อมูลเสร็จสิ้น · เพิ่มใหม่ <strong>' . (int) $result['created'] . '</strong> คน · '
                    . 'อัปเดต <strong>' . (int) $result['updated'] . '</strong> คน · '
                    . 'ข้าม (people_exit ≠ 0) <strong>' . (int) $result['skipped_exit'] . '</strong> คน '
                    . 'จากทั้งหมด ' . (int) $result['total'] . ' รายการ';
        } catch (\Throwable $e) {
            $flash     = 'โอนข้อมูลไม่สำเร็จ: ' . e($e->getMessage());
            $flashType = 'error';
        }
    }
}

$baseUrl  = rvc_setting_get(RVC_SETTING_IMPORT_BASE_URL);
$endpoint = rtrim($baseUrl, '/') . RVC_IMPORT_PATH;

admin_head('ตั้งค่า', 'settings', $adminUser);
$csrf = csrf_token();
?>

<div class="a-page-head">
  <div>
    <h1 class="a-page-title">ตั้งค่า</h1>
    <p class="a-page-sub">แหล่งข้อมูลภายนอกและการโอนข้อมูลผู้ใช้</p>
  </div>
</div>

<?php if ($flash !== ''): ?>
  <div class="a-alert a-alert-<?= $flashType ?>"><?= $flash ?></div>
<?php endif; ?>

<?php if ($result && !empty($result['errors'])): ?>
  <div class="a-alert a-alert-error">
    <div style="font-weight:700;margin-bottom:6px">รายการที่ข้าม (<?= count($result['errors']) ?>)</div>
    <?php foreach (array_slice($result['errors'], 0, 20) as $err): ?>
      <div style="font-size:13px"><?= e($err) ?></div>
    <?php endforeach; ?>
    <?php if (count($result['errors']) > 20): ?>
      <div style="font-size:13px">… และอีก <?= count($result['errors']) - 20 ?> รายการ</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ── แหล่งข้อมูล ───────────────────────────────────────────────────────── -->
<div class="a-form-card">
  <div class="a-form-title">แหล่งข้อมูลผู้ใช้ภายนอก</div>
  <form method="POST" autocomplete="off" novalidate>
    <input type="hidden" name="_csrf"  value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="save_import_url">

    <div class="a-field">
      <label class="a-label" for="s-base">Base URL ของเซิร์ฟเวอร์ต้นทาง *</label>
      <input class="a-input" id="s-base" name="base_url" required
             value="<?= e($baseUrl) ?>" placeholder="http://rms.rvc.ac.th">
      <div class="a-hint">
        เก็บเฉพาะชื่อโฮสต์ เพื่อให้ย้ายแหล่งข้อมูลได้ ส่วนพาธ
        <code><?= e(RVC_IMPORT_PATH) ?></code> ถูกกำหนดไว้ในโค้ด
      </div>
    </div>

    <div class="a-field">
      <label class="a-label">URL ที่ใช้เรียกจริง</label>
      <div style="font-size:13px;color:var(--text-2);word-break:break-all"><?= e($endpoint) ?></div>
    </div>

    <div class="a-form-actions">
      <button type="submit" class="a-btn a-btn-primary">บันทึกแหล่งข้อมูล</button>
    </div>
  </form>
</div>

<!-- ── โอนข้อมูล ─────────────────────────────────────────────────────────── -->
<div class="a-form-card">
  <div class="a-form-title">โอนข้อมูลผู้ใช้จากภายนอก</div>
  <p style="font-size:13.5px;color:var(--text-2);margin:0 0 18px;line-height:1.7">
    ดึงข้อมูลจากแหล่งข้างต้นแล้วนำเข้าเฉพาะผู้ใช้ที่ <code>people_exit = 0</code><br>
    <code>people_id</code> → ชื่อผู้ใช้ (username) ·
    <code>people_name people_surname</code> → ชื่อ-สกุล ·
    <code>ath_pass</code> → รหัสผ่าน (เข้ารหัสก่อนบันทึก) ·
    <code>people_email</code> → อีเมล<br>
    ผู้ใช้เดิมจะถูกอัปเดตโดย <strong>ไม่เปลี่ยนวันที่สร้างบัญชี (created_at)</strong>
  </p>
  <form method="POST"
        onsubmit="return confirm('เริ่มโอนข้อมูลผู้ใช้จากแหล่งข้อมูลภายนอก?')">
    <input type="hidden" name="_csrf"  value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="run_import">
    <div class="a-field">
      <label style="display:flex;align-items:center;gap:9px;font-size:14px;cursor:pointer">
        <input type="checkbox" name="update_passwords" value="1" checked>
        อัปเดตรหัสผ่านของผู้ใช้ที่มีอยู่แล้วด้วย
      </label>
    </div>
    <div class="a-form-actions">
      <button type="submit" class="a-btn a-btn-primary">เริ่มโอนข้อมูล</button>
    </div>
  </form>
</div>

<?php admin_footer(); ?>
