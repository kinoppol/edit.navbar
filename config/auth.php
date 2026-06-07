<?php
declare(strict_types=1);

function session_init(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Strict',
            'use_strict_mode' => true,
        ]);
    }
}

function current_user(): ?array
{
    session_init();
    return $_SESSION['user'] ?? null;
}

function require_auth(bool $json = false): array
{
    session_init();
    if (empty($_SESSION['user'])) {
        if ($json) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }
        header('Location: ' . APP_BASE . '/login.php');
        exit;
    }
    return $_SESSION['user'];
}

function require_admin(bool $json = false): array
{
    $user = require_auth($json);
    if (($user['role'] ?? '') !== 'admin') {
        if ($json) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden']);
        } else {
            http_response_code(403);
            echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px">'
               . '<h2>403 — ไม่มีสิทธิ์เข้าถึง</h2>'
               . '<p><a href="' . htmlspecialchars(APP_BASE . '/index.php') . '">กลับหน้าหลัก</a></p>'
               . '</body></html>';
        }
        exit;
    }
    return $user;
}

function csrf_token(): string
{
    session_init();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_verify(): void
{
    session_init();
    $posted = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $posted)) {
        http_response_code(403);
        exit('CSRF validation failed.');
    }
}

function e(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
