<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/auth.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/_zip.php';

$adminUser = require_admin();

// ── Icon upload helpers ───────────────────────────────────────────────────────
define('ICONS_DIR', dirname(__DIR__) . '/assets/app-icons/');
define('ICONS_WEB', APP_BASE . '/assets/app-icons/');

function handle_icon_upload(string $slug): string
{
    $file = $_FILES['icon_image'] ?? [];
    if (empty($file['tmp_name']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    if ((int) $file['size'] > 2 * 1024 * 1024) {
        throw new \RuntimeException('ไฟล์ขนาดใหญ่เกิน 2 MB');
    }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $exts = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/svg+xml' => 'svg',
    ];
    if (!isset($exts[$mime])) {
        throw new \RuntimeException('ประเภทไฟล์ไม่รองรับ (JPG, PNG, GIF, WebP, SVG เท่านั้น)');
    }
    if (!is_dir(ICONS_DIR)) {
        mkdir(ICONS_DIR, 0755, true);
    }
    $filename = preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) . '-' . time() . '.' . $exts[$mime];
    if (!move_uploaded_file($file['tmp_name'], ICONS_DIR . $filename)) {
        throw new \RuntimeException('ไม่สามารถบันทึกไฟล์ได้');
    }
    return $filename;
}

function delete_icon(string $filename): void
{
    if ($filename === '') return;
    $path = ICONS_DIR . basename($filename);
    if (is_file($path)) {
        @unlink($path);
    }
}

// ── Handle POST actions ───────────────────────────────────────────────────────
$flash     = '';
$flashType = 'success';
$editing   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ── Export apps (+ icons) as a ZIP backup ──────────────────────────────────
    if ($action === 'export') {
        $rows = db()->query(
            "SELECT slug, name, description, color, glyph_type, glyph, url, is_active, is_beta, is_ai, sort_order
             FROM apps ORDER BY sort_order, id"
        )->fetchAll();

        // Normalise types for a clean, portable JSON payload
        $appsOut = array_map(fn($a) => [
            'slug'        => $a['slug'],
            'name'        => $a['name'],
            'description' => $a['description'],
            'color'       => $a['color'],
            'glyph_type'  => $a['glyph_type'],
            'glyph'       => $a['glyph'],
            'url'         => $a['url'],
            'is_active'   => (int) $a['is_active'],
            'is_beta'     => (int) $a['is_beta'],
            'is_ai'       => (int) $a['is_ai'],
            'sort_order'  => (int) $a['sort_order'],
        ], $rows);

        $files = [
            'manifest.json' => json_encode([
                'format'      => 'rvc-navbar-apps',
                'version'     => 1,
                'exported_at' => date('c'),
                'count'       => count($appsOut),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'apps.json' => json_encode(
                $appsOut,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ];

        // Bundle each image icon
        foreach ($rows as $a) {
            if ($a['glyph_type'] === 'image' && $a['glyph'] !== '') {
                $path = ICONS_DIR . basename($a['glyph']);
                if (is_file($path)) {
                    $files['icons/' . basename($a['glyph'])] = (string) file_get_contents($path);
                }
            }
        }

        $zip   = rvc_zip_create($files);
        $fname = 'rvc-apps-backup-' . date('Ymd-His') . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        header('Content-Length: ' . strlen($zip));
        header('Cache-Control: no-store');
        echo $zip;
        exit;
    }

    // ── Import apps (+ icons) from a ZIP backup ────────────────────────────────
    if ($action === 'import') {
        $file = $_FILES['import_zip'] ?? [];
        if (empty($file['tmp_name']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            $flash     = 'กรุณาเลือกไฟล์ ZIP ที่ต้องการนำเข้า';
            $flashType = 'error';
        } else {
            try {
                $entries = rvc_zip_read((string) file_get_contents($file['tmp_name']));
                if (!isset($entries['apps.json'])) {
                    throw new \RuntimeException('ไม่พบ apps.json ในไฟล์ ZIP');
                }
                $list = json_decode($entries['apps.json'], true);
                if (!is_array($list)) {
                    throw new \RuntimeException('ข้อมูล apps.json เสียหาย');
                }
                if (!is_dir(ICONS_DIR)) {
                    mkdir(ICONS_DIR, 0755, true);
                }

                $allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'];
                $upsert = db()->prepare(
                    "INSERT INTO apps (slug,name,description,color,glyph_type,glyph,url,is_active,is_beta,is_ai,sort_order)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?)
                     ON DUPLICATE KEY UPDATE
                        name=VALUES(name), description=VALUES(description), color=VALUES(color),
                        glyph_type=VALUES(glyph_type), glyph=VALUES(glyph), url=VALUES(url),
                        is_active=VALUES(is_active), is_beta=VALUES(is_beta), is_ai=VALUES(is_ai),
                        sort_order=VALUES(sort_order), updated_at=NOW()"
                );

                $imported = 0;
                db()->beginTransaction();
                foreach ($list as $a) {
                    if (!is_array($a)) continue;
                    $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($a['slug'] ?? '')));
                    if ($slug === '') continue;

                    $glyphType = in_array($a['glyph_type'] ?? 'mono', ['icon', 'mono', 'image'], true)
                        ? $a['glyph_type'] : 'mono';
                    $glyph = (string) ($a['glyph'] ?? '');

                    // Restore bundled icon file (path-traversal safe: basename + extension allowlist)
                    if ($glyphType === 'image' && $glyph !== '') {
                        $safe = basename($glyph);
                        $ext  = strtolower(pathinfo($safe, PATHINFO_EXTENSION));
                        if (isset($entries['icons/' . $safe]) && in_array($ext, $allowedExt, true)) {
                            file_put_contents(ICONS_DIR . $safe, $entries['icons/' . $safe]);
                            $glyph = $safe;
                        }
                    }
                    if ($glyph === '') $glyph = '?';

                    $color = (string) ($a['color'] ?? '#2F5BEA');
                    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) $color = '#2F5BEA';

                    $upsert->execute([
                        $slug,
                        trim((string) ($a['name'] ?? $slug)),
                        trim((string) ($a['description'] ?? '')),
                        $color,
                        $glyphType,
                        $glyph,
                        trim((string) ($a['url'] ?? '#')),
                        !empty($a['is_active']) ? 1 : 0,
                        !empty($a['is_beta'])   ? 1 : 0,
                        !empty($a['is_ai'])     ? 1 : 0,
                        (int) ($a['sort_order'] ?? 0),
                    ]);
                    $imported++;
                }
                db()->commit();
                $flash = "นำเข้าสำเร็จ {$imported} แอปพลิเคชัน (แอปที่ slug ซ้ำจะถูกอัปเดต)";
            } catch (\Throwable $ex) {
                if (db()->inTransaction()) db()->rollBack();
                $flash     = 'นำเข้าไม่สำเร็จ: ' . e($ex->getMessage());
                $flashType = 'error';
            }
        }
    }

    // ── Delete ────────────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = db()->prepare("SELECT glyph_type, glyph FROM apps WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if ($row && $row['glyph_type'] === 'image') {
                delete_icon($row['glyph']);
            }
            db()->prepare("DELETE FROM apps WHERE id = ?")->execute([$id]);
            $flash = 'ลบแอปพลิเคชันเรียบร้อยแล้ว';
        }

    // ── Toggle active ─────────────────────────────────────────────────────────
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            db()->prepare("UPDATE apps SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
            $flash = 'อัปเดตสถานะเรียบร้อยแล้ว';
        }

    // ── Create / Update ───────────────────────────────────────────────────────
    } elseif (in_array($action, ['create', 'update'], true)) {
        $id        = (int) ($_POST['id'] ?? 0);
        $slug      = preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($_POST['slug']   ?? '')));
        $name      = trim($_POST['name']        ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $color     = trim($_POST['color']       ?? '#2F5BEA');
        $glyphType = in_array($_POST['glyph_type'] ?? '', ['icon', 'mono', 'image']) ? $_POST['glyph_type'] : 'mono';
        $url       = trim($_POST['url']         ?? '#');
        $isActive  = isset($_POST['is_active']) ? 1 : 0;
        $isBeta    = isset($_POST['is_beta'])   ? 1 : 0;
        $isAi      = isset($_POST['is_ai'])     ? 1 : 0;
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);

        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) $color = '#2F5BEA';

        $errors = [];
        if ($slug === '') $errors[] = 'กรุณาระบุ Slug';
        if ($name === '') $errors[] = 'กรุณาระบุชื่อแอป';

        // Resolve glyph value
        $glyph = '';
        if ($glyphType === 'image') {
            try {
                $uploadedFilename = handle_icon_upload($slug);
                if ($uploadedFilename !== '') {
                    // New upload: delete old file if replacing
                    if ($action === 'update') {
                        $stmt = db()->prepare("SELECT glyph_type, glyph FROM apps WHERE id = ?");
                        $stmt->execute([$id]);
                        $old = $stmt->fetch();
                        if ($old && $old['glyph_type'] === 'image' && $old['glyph'] !== $uploadedFilename) {
                            delete_icon($old['glyph']);
                        }
                    }
                    $glyph = $uploadedFilename;
                } else {
                    // No new file uploaded → keep existing filename (edit) or error (create)
                    $glyph = trim($_POST['glyph_existing'] ?? '');
                    if ($glyph === '') {
                        $errors[] = 'กรุณาอัปโหลดรูปภาพสำหรับ icon';
                    }
                }
            } catch (\RuntimeException $ex) {
                $errors[] = $ex->getMessage();
                $glyph = trim($_POST['glyph_existing'] ?? '');
            }
        } else {
            $glyph = trim($_POST['glyph'] ?? '?');
            if ($glyph === '') $errors[] = 'กรุณาระบุ Glyph';

            // Switched away from image → delete old icon file
            if ($action === 'update') {
                $stmt = db()->prepare("SELECT glyph_type, glyph FROM apps WHERE id = ?");
                $stmt->execute([$id]);
                $old = $stmt->fetch();
                if ($old && $old['glyph_type'] === 'image') {
                    delete_icon($old['glyph']);
                }
            }
        }

        if (empty($errors)) {
            try {
                if ($action === 'create') {
                    db()->prepare("INSERT INTO apps (slug,name,description,color,glyph_type,glyph,url,is_active,is_beta,is_ai,sort_order)
                                   VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$slug,$name,$desc,$color,$glyphType,$glyph,$url,$isActive,$isBeta,$isAi,$sortOrder]);
                    $flash = 'เพิ่มแอปพลิเคชันเรียบร้อยแล้ว';
                } else {
                    db()->prepare("UPDATE apps SET slug=?,name=?,description=?,color=?,glyph_type=?,glyph=?,url=?,is_active=?,is_beta=?,is_ai=?,sort_order=?,updated_at=NOW() WHERE id=?")
                        ->execute([$slug,$name,$desc,$color,$glyphType,$glyph,$url,$isActive,$isBeta,$isAi,$sortOrder,$id]);
                    $flash = 'อัปเดตแอปพลิเคชันเรียบร้อยแล้ว';
                }
            } catch (\PDOException $e) {
                $flash     = 'Slug "' . e($slug) . '" ถูกใช้งานแล้ว กรุณาใช้ slug อื่น';
                $flashType = 'error';
                $editing   = compact('id','slug','name','desc','color','glyphType','glyph','url','isActive','isBeta','isAi','sortOrder');
            }
        } else {
            $flash     = implode(' / ', $errors);
            $flashType = 'error';
            $editing   = compact('id','slug','name','desc','color','glyphType','glyph','url','isActive','isBeta','isAi','sortOrder');
        }
    }
}

// ── Load edit row on GET ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $id   = (int) $_GET['edit'];
    $stmt = db()->prepare("SELECT * FROM apps WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) {
        $editing = [
            'id'        => (int) $row['id'],
            'slug'      => $row['slug'],
            'name'      => $row['name'],
            'desc'      => $row['description'],
            'color'     => $row['color'],
            'glyphType' => $row['glyph_type'],
            'glyph'     => $row['glyph'],
            'url'       => $row['url'],
            'isActive'  => (int) $row['is_active'],
            'isBeta'    => (int) $row['is_beta'],
            'isAi'      => (int) $row['is_ai'],
            'sortOrder' => (int) $row['sort_order'],
        ];
    }
}

$showForm = isset($_GET['new']) || $editing !== null;
$apps     = db()->query("SELECT * FROM apps ORDER BY sort_order, id")->fetchAll();

// ── Render ────────────────────────────────────────────────────────────────────
admin_head('แอปพลิเคชัน', 'apps', $adminUser);
$csrf = csrf_token();
$base = APP_BASE;

$isEditing  = $editing && ($editing['id'] ?? 0) > 0;
$curType    = $editing['glyphType'] ?? 'mono';
$curGlyph   = $editing['glyph']     ?? '';
?>

<div class="a-page-head">
  <div>
    <h1 class="a-page-title">แอปพลิเคชัน</h1>
    <p class="a-page-sub">จัดการรายการแอปพลิเคชันที่แสดงใน Navbar</p>
  </div>
  <?php if (!$showForm): ?>
    <a href="?new=1" class="a-btn a-btn-primary">+ เพิ่มแอปใหม่</a>
  <?php endif; ?>
</div>

<?php if ($flash !== ''): ?>
  <div class="a-alert a-alert-<?= $flashType ?>"><?= $flash ?></div>
<?php endif; ?>

<?php if (!$showForm): ?>
<!-- ── Backup: export / import ────────────────────────────────────────────── -->
<div class="a-card a-backup">
  <div class="a-backup-info">
    <div class="a-backup-title">สำรอง &amp; กู้คืนรายการแอป</div>
    <div class="a-backup-sub">ส่งออกรายการแอปพลิเคชันทั้งหมดพร้อมไอคอนเป็นไฟล์ ZIP หรือนำเข้าจากไฟล์สำรอง</div>
  </div>
  <div class="a-backup-actions">
    <form method="POST" style="display:inline">
      <input type="hidden" name="_csrf"  value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="export">
      <button type="submit" class="a-btn a-btn-ghost">⤓ ส่งออก ZIP</button>
    </form>
    <form method="POST" enctype="multipart/form-data" id="import-form" style="display:inline">
      <input type="hidden" name="_csrf"  value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="import">
      <input type="file" name="import_zip" id="import-file" accept=".zip,application/zip"
             style="display:none" onchange="confirmImport(this)">
      <button type="button" class="a-btn a-btn-primary"
              onclick="document.getElementById('import-file').click()">⤒ นำเข้า ZIP</button>
    </form>
  </div>
</div>

<style>
.a-backup {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  flex-wrap: wrap; margin-bottom: 16px; padding: 18px 20px;
}
.a-backup-title { font-size: 15px; font-weight: 700; color: var(--text); }
.a-backup-sub   { font-size: 13px; color: var(--text-3); margin-top: 3px; max-width: 540px; }
.a-backup-actions { display: flex; gap: 8px; flex-shrink: 0; }
</style>

<script>
function confirmImport(input) {
  if (!input.files || !input.files[0]) return;
  if (confirm('นำเข้าจากไฟล์ "' + input.files[0].name + '"?\n\nแอปที่มี slug ตรงกันจะถูกอัปเดตด้วยข้อมูลจากไฟล์สำรอง')) {
    document.getElementById('import-form').submit();
  } else {
    input.value = '';
  }
}
</script>
<?php endif; ?>

<?php if ($showForm): ?>
<!-- ── Add / Edit form ────────────────────────────────────────────────────── -->
<div class="a-form-card">
  <div class="a-form-title"><?= $isEditing ? 'แก้ไขแอปพลิเคชัน' : 'เพิ่มแอปพลิเคชันใหม่' ?></div>
  <form method="POST" enctype="multipart/form-data" autocomplete="off" novalidate>
    <input type="hidden" name="_csrf"   value="<?= e($csrf) ?>">
    <input type="hidden" name="action"  value="<?= $isEditing ? 'update' : 'create' ?>">
    <?php if ($isEditing): ?>
      <input type="hidden" name="id" value="<?= (int) $editing['id'] ?>">
    <?php endif; ?>

    <div class="a-field-row">
      <div class="a-field">
        <label class="a-label" for="f-name">ชื่อแอป *</label>
        <input class="a-input" id="f-name" name="name" required
               value="<?= e($editing['name'] ?? '') ?>" placeholder="เช่น Meet">
      </div>
      <div class="a-field">
        <label class="a-label" for="f-slug">Slug (ตัวอักษรเล็ก, ไม่มีช่องว่าง) *</label>
        <input class="a-input" id="f-slug" name="slug" required
               value="<?= e($editing['slug'] ?? '') ?>" placeholder="เช่น meet">
      </div>
    </div>

    <div class="a-field">
      <label class="a-label" for="f-desc">คำอธิบาย</label>
      <input class="a-input" id="f-desc" name="description"
             value="<?= e($editing['desc'] ?? '') ?>" placeholder="เช่น ประชุมออนไลน์">
    </div>

    <div class="a-field-row">
      <div class="a-field">
        <label class="a-label" for="f-url">URL</label>
        <input class="a-input" id="f-url" name="url" type="url"
               value="<?= e($editing['url'] ?? '#') ?>" placeholder="https://meet.rvc.ac.th">
      </div>
      <div class="a-field">
        <label class="a-label" for="f-order">ลำดับการแสดง</label>
        <input class="a-input" id="f-order" name="sort_order" type="number" min="0"
               value="<?= (int) ($editing['sortOrder'] ?? 0) ?>">
      </div>
    </div>

    <!-- ── Glyph section ────────────────────────────────────────────────── -->
    <div class="a-field-row">
      <div class="a-field">
        <label class="a-label">สี (Hex)</label>
        <div class="a-color-row">
          <input class="a-color-input" type="color" id="f-color-picker"
                 value="<?= e($editing['color'] ?? '#2F5BEA') ?>"
                 oninput="document.getElementById('f-color-text').value=this.value">
          <input class="a-input" id="f-color-text" name="color" style="flex:1"
                 value="<?= e($editing['color'] ?? '#2F5BEA') ?>"
                 oninput="document.getElementById('f-color-picker').value=this.value"
                 placeholder="#2F5BEA">
        </div>
      </div>
      <div class="a-field">
        <label class="a-label" for="f-glyph-type">ประเภท Icon *</label>
        <select class="a-select a-input" id="f-glyph-type" name="glyph_type"
                onchange="onGlyphTypeChange(this.value)">
          <option value="mono"  <?= $curType === 'mono'  ? 'selected' : '' ?>>Mono (ตัวอักษร)</option>
          <option value="icon"  <?= $curType === 'icon'  ? 'selected' : '' ?>>Icon (Meet)</option>
          <option value="image" <?= $curType === 'image' ? 'selected' : '' ?>>รูปภาพ (อัปโหลด)</option>
        </select>
      </div>
    </div>

    <!-- Mono / icon glyph text -->
    <div class="a-field" id="field-glyph-text"
         style="<?= $curType === 'image' ? 'display:none' : '' ?>">
      <label class="a-label" for="f-glyph">Glyph (ตัวอักษรย่อ หรือชื่อ icon)</label>
      <input class="a-input" id="f-glyph" name="glyph"
             value="<?= $curType !== 'image' ? e($curGlyph) : '' ?>"
             placeholder="เช่น T, LG, meet">
      <p class="a-hint">mono: ตัวอักษร 1–2 ตัว &nbsp;|&nbsp; icon: พิมพ์ <code>meet</code></p>
    </div>

    <!-- Image upload -->
    <div class="a-field" id="field-glyph-image"
         style="<?= $curType !== 'image' ? 'display:none' : '' ?>">
      <input type="hidden" name="glyph_existing" value="<?= e($curType === 'image' ? $curGlyph : '') ?>">
      <label class="a-label">อัปโหลดรูปภาพ Icon</label>

      <?php if ($curType === 'image' && $curGlyph !== ''): ?>
      <div id="img-preview" style="margin-bottom:12px;display:flex;align-items:center;gap:12px">
        <img src="<?= e(ICONS_WEB . $curGlyph) ?>" alt="current icon"
             style="width:52px;height:52px;border-radius:14px;object-fit:cover;
                    border:1px solid var(--border);box-shadow:0 2px 8px rgba(20,30,55,.12)">
        <span style="font-size:13px;color:var(--text-3)"><?= e($curGlyph) ?></span>
      </div>
      <?php else: ?>
      <div id="img-preview" style="display:none;margin-bottom:12px;align-items:center;gap:12px">
        <img id="preview-img" src="" alt="preview"
             style="width:52px;height:52px;border-radius:14px;object-fit:cover;
                    border:1px solid var(--border);box-shadow:0 2px 8px rgba(20,30,55,.12)">
        <span id="preview-name" style="font-size:13px;color:var(--text-3)"></span>
      </div>
      <?php endif; ?>

      <div class="upload-drop-area" id="drop-area"
           onclick="document.getElementById('f-icon-file').click()"
           ondragover="event.preventDefault();this.classList.add('drag-over')"
           ondragleave="this.classList.remove('drag-over')"
           ondrop="handleDrop(event)">
        <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.6" style="color:var(--text-3);margin-bottom:8px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <p style="margin:0;font-size:14px;font-weight:600;color:var(--text-2)">คลิกหรือลากไฟล์มาวาง</p>
        <p style="margin:4px 0 0;font-size:12.5px;color:var(--text-3)">JPG, PNG, GIF, WebP, SVG · สูงสุด 2 MB</p>
      </div>
      <input type="file" id="f-icon-file" name="icon_image" accept="image/*"
             style="display:none" onchange="previewIcon(this)">
    </div>

    <div class="a-field">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:600;color:var(--text-2)">
        <input type="checkbox" name="is_active" value="1"
               <?= ($editing['isActive'] ?? 1) ? 'checked' : '' ?>>
        เปิดใช้งาน (แสดงใน Navbar)
      </label>
    </div>

    <div class="a-field">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:600;color:var(--text-2)">
        <input type="checkbox" name="is_beta" value="1"
               <?= ($editing['isBeta'] ?? 0) ? 'checked' : '' ?>>
        เวอร์ชันทดลอง (แสดงป้าย Beta บนไอคอน)
      </label>
      <p class="a-hint">เปิดเพื่อแจ้งผู้ใช้ว่าแอปนี้อยู่ในช่วงทดลองใช้งาน</p>
    </div>

    <div class="a-field">
      <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:14px;font-weight:600;color:var(--text-2)">
        <input type="checkbox" name="is_ai" value="1"
               <?= ($editing['isAi'] ?? 0) ? 'checked' : '' ?>>
        สร้างด้วย AI (แสดงป้าย ★ AI บนไอคอน)
      </label>
      <p class="a-hint">เปิดเพื่อระบุว่าแอปนี้ใช้ AI ในการสร้างหรือขับเคลื่อน</p>
    </div>

    <div class="a-form-actions">
      <button type="submit" class="a-btn a-btn-primary">
        <?= $isEditing ? 'บันทึกการเปลี่ยนแปลง' : 'เพิ่มแอปพลิเคชัน' ?>
      </button>
      <a href="<?= e($base) ?>/admin/apps.php" class="a-btn a-btn-ghost">ยกเลิก</a>
    </div>
  </form>
</div>

<style>
.upload-drop-area {
  border: 2px dashed var(--border-strong); border-radius: 12px;
  padding: 28px 20px; text-align: center; cursor: pointer;
  transition: border-color .15s, background .15s;
}
.upload-drop-area:hover, .upload-drop-area.drag-over {
  border-color: var(--brand); background: var(--brand-tint);
}
</style>

<script>
function onGlyphTypeChange(type) {
  document.getElementById('field-glyph-text').style.display  = type === 'image' ? 'none' : '';
  document.getElementById('field-glyph-image').style.display = type === 'image' ? '' : 'none';
}

function previewIcon(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  const wrap = document.getElementById('img-preview');
  const img  = document.getElementById('preview-img') || wrap.querySelector('img');
  const name = document.getElementById('preview-name');
  img.src = URL.createObjectURL(file);
  if (name) name.textContent = file.name;
  wrap.style.display = 'flex';
}

function handleDrop(e) {
  e.preventDefault();
  document.getElementById('drop-area').classList.remove('drag-over');
  const file = e.dataTransfer.files[0];
  if (!file) return;
  const input = document.getElementById('f-icon-file');
  const dt = new DataTransfer();
  dt.items.add(file);
  input.files = dt.files;
  previewIcon(input);
}
</script>
<?php endif; ?>

<!-- ── Apps table ──────────────────────────────────────────────────────────── -->
<div class="a-card">
  <?php if (empty($apps)): ?>
    <div class="a-empty">ยังไม่มีแอปพลิเคชัน — <a href="?new=1">เพิ่มแอปแรก</a></div>
  <?php else: ?>
  <table class="a-table">
    <thead>
      <tr>
        <th>แอป</th>
        <th>Slug</th>
        <th>คำอธิบาย</th>
        <th>URL</th>
        <th style="text-align:center">ลำดับ</th>
        <th>สถานะ</th>
        <th>จัดการ</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($apps as $app): ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <?php if ($app['glyph_type'] === 'image' && $app['glyph'] !== ''): ?>
              <span class="color-swatch" style="background:var(--surface-2);padding:0;overflow:hidden">
                <img src="<?= e(ICONS_WEB . $app['glyph']) ?>" alt=""
                     style="width:100%;height:100%;object-fit:cover;display:block;border-radius:9px">
              </span>
            <?php else: ?>
              <span class="color-swatch" style="background:<?= e($app['color']) ?>">
                <?= e(mb_strtoupper(mb_substr($app['glyph'], 0, 2))) ?>
              </span>
            <?php endif; ?>
            <strong><?= e($app['name']) ?></strong>
            <?php if (!empty($app['is_beta'])): ?>
              <span class="badge-beta">BETA</span>
            <?php endif; ?>
            <?php if (!empty($app['is_ai'])): ?>
              <span class="badge-ai">★ AI</span>
            <?php endif; ?>
          </div>
        </td>
        <td><code style="font-size:13px;background:var(--surface-2);padding:2px 7px;border-radius:5px"><?= e($app['slug']) ?></code></td>
        <td style="color:var(--text-2)"><?= e($app['description']) ?></td>
        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
          <?php if ($app['url'] !== '#'): ?>
            <a href="<?= e($app['url']) ?>" target="_blank" rel="noopener"
               style="font-size:13px;color:var(--text-3)"><?= e($app['url']) ?></a>
          <?php else: ?>
            <span style="color:var(--text-3);font-size:13px">—</span>
          <?php endif; ?>
        </td>
        <td style="text-align:center;color:var(--text-3)"><?= (int) $app['sort_order'] ?></td>
        <td>
          <?php if ($app['is_active']): ?>
            <span class="badge-active">เปิดใช้งาน</span>
          <?php else: ?>
            <span class="badge-inactive">ปิดใช้งาน</span>
          <?php endif; ?>
        </td>
        <td class="actions">
          <div style="display:flex;gap:6px">
            <a href="?edit=<?= (int) $app['id'] ?>" class="a-btn a-btn-ghost"
               style="padding:6px 12px;font-size:13px">แก้ไข</a>

            <form method="POST" style="display:inline"
                  onsubmit="return confirm('<?= $app['is_active'] ? 'ปิดการใช้งาน' : 'เปิดการใช้งาน' ?> แอปนี้?')">
              <input type="hidden" name="_csrf"  value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id"     value="<?= (int) $app['id'] ?>">
              <button type="submit" class="a-btn a-btn-ghost" style="padding:6px 12px;font-size:13px">
                <?= $app['is_active'] ? 'ปิด' : 'เปิด' ?>
              </button>
            </form>

            <form method="POST" style="display:inline"
                  onsubmit="return confirm('ยืนยันการลบแอป \"<?= e($app['name']) ?>\"?')">
              <input type="hidden" name="_csrf"  value="<?= e($csrf) ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id"     value="<?= (int) $app['id'] ?>">
              <button type="submit" class="a-btn a-btn-danger"
                      style="padding:6px 12px;font-size:13px">ลบ</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
