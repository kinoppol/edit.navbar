<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/auth.php';

$user = require_auth(true);
$uid  = $user['id'];

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: return active apps + this user's prefs ───────────────────────────
if ($method === 'GET') {
    $apps = db()
        ->query("SELECT id, slug, name, description, color, glyph_type, glyph, url
                 FROM apps WHERE is_active = 1 ORDER BY sort_order, id")
        ->fetchAll();

    $stmt = db()->prepare("SELECT app_id, is_hidden, sort_order FROM user_app_prefs WHERE user_id = ?");
    $stmt->execute([$uid]);
    $prefsMap = [];
    foreach ($stmt->fetchAll() as $row) {
        $prefsMap[(int) $row['app_id']] = $row;
    }

    $visible = [];
    $hidden  = [];
    foreach ($apps as $app) {
        $pref = $prefsMap[(int) $app['id']] ?? null;
        $app['sort_order_user'] = $pref ? (int) $pref['sort_order'] : (int) $app['id'];
        if ($pref && (int) $pref['is_hidden'] === 1) {
            $hidden[] = $app;
        } else {
            $visible[] = $app;
        }
    }
    usort($visible, fn($a, $b) => $a['sort_order_user'] <=> $b['sort_order_user']);
    usort($hidden,  fn($a, $b) => $a['sort_order_user'] <=> $b['sort_order_user']);

    echo json_encode([
        'apps'    => $apps,
        'visible' => array_column($visible, 'slug'),
        'hidden'  => array_column($hidden,  'slug'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST: save_prefs ─────────────────────────────────────────────────────
if ($method === 'POST') {
    csrf_verify();

    $body = (array) json_decode(file_get_contents('php://input'), true);
    if (($body['action'] ?? '') !== 'save_prefs') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    $visible = array_values(array_filter((array) ($body['visible'] ?? []), 'is_string'));
    $hidden  = array_values(array_filter((array) ($body['hidden']  ?? []), 'is_string'));

    // Resolve slugs → ids
    $slugs = array_unique(array_merge($visible, $hidden));
    if (empty($slugs)) {
        echo json_encode(['ok' => true]);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    $stmt = db()->prepare("SELECT id, slug FROM apps WHERE slug IN ($placeholders)");
    $stmt->execute($slugs);
    $slugToId = array_column($stmt->fetchAll(), 'id', 'slug');

    $upsert = db()->prepare("
        INSERT INTO user_app_prefs (user_id, app_id, is_hidden, sort_order)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE is_hidden = VALUES(is_hidden), sort_order = VALUES(sort_order)
    ");

    db()->beginTransaction();
    try {
        foreach ($visible as $i => $slug) {
            if (!isset($slugToId[$slug])) continue;
            $upsert->execute([$uid, $slugToId[$slug], 0, $i]);
        }
        foreach ($hidden as $i => $slug) {
            if (!isset($slugToId[$slug])) continue;
            $upsert->execute([$uid, $slugToId[$slug], 1, $i]);
        }
        db()->commit();
    } catch (\Throwable $e) {
        db()->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'DB error']);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
