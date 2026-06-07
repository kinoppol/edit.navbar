<?php
declare(strict_types=1);
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/auth.php';

$user = require_auth(true);
$uid  = $user['id'];

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list notifications ──────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = db()->prepare("
        SELECT n.id, n.title, n.body, n.is_read, n.created_at,
               a.slug AS app_slug, a.name AS app_name, a.color AS app_color
        FROM notifications n
        LEFT JOIN apps a ON a.id = n.app_id
        WHERE n.user_id = ?
        ORDER BY n.created_at DESC
        LIMIT 30
    ");
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();

    $result = array_map(fn($n) => [
        'id'      => (int) $n['id'],
        'app'     => $n['app_name']  ?? '',
        'color'   => $n['app_color'] ?? '#8a93a3',
        'title'   => $n['title'],
        'body'    => $n['body'],
        'time'    => (new DateTime($n['created_at']))->format('d/m/Y H:i'),
        'unread'  => !(bool) $n['is_read'],
    ], $rows);

    echo json_encode(['notifications' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST: mark_read ──────────────────────────────────────────────────────
if ($method === 'POST') {
    csrf_verify();

    $body = (array) json_decode(file_get_contents('php://input'), true);
    if (($body['action'] ?? '') !== 'mark_read') {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
        exit;
    }

    $stmt = db()->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0");
    $stmt->execute([$uid]);

    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
