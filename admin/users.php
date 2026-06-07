<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/auth.php';
require_once __DIR__ . '/_layout.php';

$adminUser = require_admin();
$selfId    = $adminUser['id'];

$flash     = '';
$flashType = 'success';
$editing   = null;

// ── Handle POST actions ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0 && $id !== $selfId) {
            db()->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);
            $flash = 'ลบผู้ใช้เรียบร้อยแล้ว';
        } else {
            $flash     = 'ไม่สามารถลบบัญชีของตัวเองได้';
            $flashType = 'error';
        }

    } elseif (in_array($action, ['create', 'update'], true)) {
        $id       = (int) ($_POST['id'] ?? 0);
        $name     = trim($_POST['name']     ?? '');
        $email    = trim($_POST['email']    ?? '');
        $role     = in_array($_POST['role'] ?? '', ['user', 'admin']) ? $_POST['role'] : 'user';
        $password = $_POST['password'] ?? '';
        $color    = trim($_POST['avatar_color'] ?? '#2F5BEA');

        // Derive initials from name
        $parts    = preg_split('/\s+/', $name);
        $initials = '';
        foreach ($parts as $part) {
            $ch = mb_substr($part, 0, 1);
            if ($ch !== '') {
                $initials .= $ch;
                if (mb_strlen($initials) >= 2) break;
            }
        }
        $initials = $initials ?: 'U';

        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) $color = '#2F5BEA';

        $errors = [];
        if ($name  === '') $errors[] = 'กรุณาระบุชื่อ';
        if ($email === '') $errors[] = 'กรุณาระบุอีเมล';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
        if ($action === 'create' && $password === '') $errors[] = 'กรุณาตั้งรหัสผ่าน';
        if ($password !== '' && mb_strlen($password) < 6) $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';

        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    db()->prepare("INSERT INTO users (name, email, password_hash, initials, avatar_color, role)
                                   VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$name, $email, $hash, $initials, $color, $role]);
                    $flash = 'เพิ่มผู้ใช้เรียบร้อยแล้ว';
                } else {
                    if ($password !== '') {
                        $hash = password_hash($password, PASSWORD_BCRYPT);
                        db()->prepare("UPDATE users SET name=?, email=?, password_hash=?, initials=?, avatar_color=?, role=? WHERE id=?")
                            ->execute([$name, $email, $hash, $initials, $color, $role, $id]);
                    } else {
                        db()->prepare("UPDATE users SET name=?, email=?, initials=?, avatar_color=?, role=? WHERE id=?")
                            ->execute([$name, $email, $initials, $color, $role, $id]);
                    }
                    $flash = 'อัปเดตผู้ใช้เรียบร้อยแล้ว';
                }
            } catch (\PDOException $e) {
                $flash     = 'อีเมล "' . e($email) . '" ถูกใช้งานแล้ว';
                $flashType = 'error';
                $editing   = compact('id', 'name', 'email', 'role', 'color');
            }
        } else {
            $flash     = implode(' / ', $errors);
            $flashType = 'error';
            $editing   = compact('id', 'name', 'email', 'role', 'color');
        }
    }
}

// ── Load edit row on GET ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $id = (int) $_GET['edit'];
    $stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $editing = [
            'id'    => (int) $row['id'],
            'name'  => $row['name'],
            'email' => $row['email'],
            'role'  => $row['role'],
            'color' => $row['avatar_color'],
        ];
    }
}

$showForm = isset($_GET['new']) || $editing !== null;

// ── Fetch all users ───────────────────────────────────────────────────────
$users = db()->query("SELECT id, name, email, initials, avatar_color, role, created_at FROM users ORDER BY id")->fetchAll();

// ── Render ────────────────────────────────────────────────────────────────
admin_head('ผู้ใช้', 'users', $adminUser);
$csrf = csrf_token();
$base = APP_BASE;
?>

<div class="a-page-head">
  <div>
    <h1 class="a-page-title">ผู้ใช้</h1>
    <p class="a-page-sub">จัดการบัญชีผู้ใช้และสิทธิ์การเข้าถึง</p>
  </div>
  <?php if (!$showForm): ?>
    <a href="?new=1" class="a-btn a-btn-primary">+ เพิ่มผู้ใช้ใหม่</a>
  <?php endif; ?>
</div>

<?php if ($flash !== ''): ?>
  <div class="a-alert a-alert-<?= $flashType ?>"><?= $flash ?></div>
<?php endif; ?>

<?php if ($showForm): ?>
<!-- ── Add / Edit form ──────────────────────────────────────────────────── -->
<div class="a-form-card">
  <div class="a-form-title"><?= ($editing && ($editing['id'] ?? 0) > 0) ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้ใหม่' ?></div>
  <form method="POST" autocomplete="off" novalidate>
    <input type="hidden" name="_csrf"   value="<?= e($csrf) ?>">
    <input type="hidden" name="action"  value="<?= ($editing && ($editing['id'] ?? 0) > 0) ? 'update' : 'create' ?>">
    <?php if ($editing && ($editing['id'] ?? 0) > 0): ?>
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    <?php endif; ?>

    <div class="a-field-row">
      <div class="a-field">
        <label class="a-label" for="u-name">ชื่อ-สกุล *</label>
        <input class="a-input" id="u-name" name="name" required
               value="<?= e($editing['name'] ?? '') ?>" placeholder="เช่น ณัฐวัฒน์ ศรีสุข">
      </div>
      <div class="a-field">
        <label class="a-label" for="u-email">อีเมล *</label>
        <input class="a-input" id="u-email" name="email" type="email" required
               value="<?= e($editing['email'] ?? '') ?>" placeholder="you@rvc.ac.th">
      </div>
    </div>

    <div class="a-field-row">
      <div class="a-field">
        <label class="a-label" for="u-password">
          รหัสผ่าน<?= ($editing && ($editing['id'] ?? 0) > 0) ? ' (เว้นว่างเพื่อคงเดิม)' : ' *' ?>
        </label>
        <input class="a-input" id="u-password" name="password" type="password"
               placeholder="อย่างน้อย 6 ตัวอักษร" autocomplete="new-password">
      </div>
      <div class="a-field">
        <label class="a-label" for="u-role">สิทธิ์</label>
        <select class="a-select a-input" id="u-role" name="role">
          <option value="user"  <?= ($editing['role'] ?? 'user') === 'user'  ? 'selected' : '' ?>>ผู้ใช้ทั่วไป</option>
          <option value="admin" <?= ($editing['role'] ?? '') === 'admin' ? 'selected' : '' ?>>ผู้ดูแลระบบ (Admin)</option>
        </select>
      </div>
    </div>

    <div class="a-field">
      <label class="a-label">สีอวาตาร์</label>
      <div class="a-color-row">
        <input class="a-color-input" type="color" id="u-color-picker"
               value="<?= e($editing['color'] ?? '#2F5BEA') ?>"
               oninput="document.getElementById('u-color-text').value = this.value">
        <input class="a-input" id="u-color-text" name="avatar_color" style="flex:1"
               value="<?= e($editing['color'] ?? '#2F5BEA') ?>"
               oninput="document.getElementById('u-color-picker').value = this.value"
               placeholder="#2F5BEA">
      </div>
    </div>

    <div class="a-form-actions">
      <button type="submit" class="a-btn a-btn-primary">
        <?= ($editing && ($editing['id'] ?? 0) > 0) ? 'บันทึกการเปลี่ยนแปลง' : 'เพิ่มผู้ใช้' ?>
      </button>
      <a href="<?= e($base) ?>/admin/users.php" class="a-btn a-btn-ghost">ยกเลิก</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- ── Users table ──────────────────────────────────────────────────────── -->
<div class="a-card">
  <?php if (empty($users)): ?>
    <div class="a-empty">ยังไม่มีผู้ใช้</div>
  <?php else: ?>
  <table class="a-table">
    <thead>
      <tr>
        <th>ผู้ใช้</th>
        <th>อีเมล</th>
        <th>สิทธิ์</th>
        <th>สร้างเมื่อ</th>
        <th>จัดการ</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <span class="a-avatar" style="background:<?= e($u['avatar_color']) ?>"><?= e($u['initials']) ?></span>
            <span><?= e($u['name']) ?><?= (int) $u['id'] === $selfId ? ' <span style="font-size:11px;color:var(--text-3)">(คุณ)</span>' : '' ?></span>
          </div>
        </td>
        <td style="color:var(--text-2)"><?= e($u['email']) ?></td>
        <td>
          <?php if ($u['role'] === 'admin'): ?>
            <span class="badge-admin">Admin</span>
          <?php else: ?>
            <span class="badge-user">User</span>
          <?php endif; ?>
        </td>
        <td style="color:var(--text-3);font-size:13px"><?= e((new DateTime($u['created_at']))->format('d/m/Y')) ?></td>
        <td class="actions">
          <div style="display:flex;gap:6px">
            <a href="?edit=<?= (int) $u['id'] ?>" class="a-btn a-btn-ghost" style="padding:6px 12px;font-size:13px">แก้ไข</a>
            <?php if ((int) $u['id'] !== $selfId): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('ยืนยันการลบผู้ใช้ \"<?= e($u['name']) ?>\"?')">
              <input type="hidden" name="_csrf"  value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id"     value="<?= (int) $u['id'] ?>">
              <button type="submit" class="a-btn a-btn-danger" style="padding:6px 12px;font-size:13px">ลบ</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
