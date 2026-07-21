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

// ── "ลงชื่อค้างไว้" (remember me) ────────────────────────────────────────────
// A selector/validator cookie backed by the auth_tokens table. The validator is
// only ever stored hashed, so a database leak cannot be replayed as a login.

const REMEMBER_COOKIE = 'rvc_remember';
const REMEMBER_DAYS   = 30;

function remember_cookie_params(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => (APP_BASE === '' ? '/' : APP_BASE . '/'),
        'httponly' => true,
        'samesite' => 'Strict',
        'secure'   => !empty($_SERVER['HTTPS']),
    ];
}

/** Issue a fresh remember-me cookie for a user. */
function remember_issue(int $userId): void
{
    $selector  = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $expires   = time() + REMEMBER_DAYS * 86400;

    try {
        db()->prepare("INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at)
                       VALUES (?, ?, ?, ?)")
            ->execute([$userId, $selector, hash('sha256', $validator), date('Y-m-d H:i:s', $expires)]);
    } catch (\Throwable $e) {
        return;   // table missing (install not migrated) — just skip remember-me
    }
    setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, remember_cookie_params($expires));
}

/** Drop the current remember-me cookie and its database row. */
function remember_clear(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        [$selector] = array_pad(explode(':', (string) $_COOKIE[REMEMBER_COOKIE], 2), 2, '');
        try {
            db()->prepare("DELETE FROM auth_tokens WHERE selector = ?")->execute([$selector]);
        } catch (\Throwable $e) { /* ignore */ }
    }
    setcookie(REMEMBER_COOKIE, '', remember_cookie_params(time() - 3600));
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/** Restore a session from the remember-me cookie. Returns the user or null. */
function remember_restore(): ?array
{
    if (empty($_COOKIE[REMEMBER_COOKIE]) || headers_sent()) return null;

    $parts = explode(':', (string) $_COOKIE[REMEMBER_COOKIE], 2);
    if (count($parts) !== 2) { remember_clear(); return null; }
    [$selector, $validator] = $parts;

    try {
        $stmt = db()->prepare(
            "SELECT t.id, t.validator_hash, u.id AS uid, u.name, u.email, u.initials, u.avatar_color, u.role
             FROM auth_tokens t JOIN users u ON u.id = t.user_id
             WHERE t.selector = ? AND t.expires_at > NOW() LIMIT 1"
        );
        $stmt->execute([$selector]);
        $row = $stmt->fetch();
    } catch (\Throwable $e) {
        return null;
    }

    if (!$row || !hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
        remember_clear();
        return null;
    }

    // Valid — rotate the token so a stolen cookie is single-use
    db()->prepare("DELETE FROM auth_tokens WHERE id = ?")->execute([(int) $row['id']]);

    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'           => (int) $row['uid'],
        'name'         => $row['name'],
        'email'        => $row['email'],
        'initials'     => $row['initials'],
        'avatar_color' => $row['avatar_color'],
        'role'         => $row['role'],
    ];
    remember_issue((int) $row['uid']);
    return $_SESSION['user'];
}

function current_user(): ?array
{
    session_init();
    if (empty($_SESSION['user'])) {
        return remember_restore();
    }
    return $_SESSION['user'];
}

function require_auth(bool $json = false): array
{
    session_init();
    if (empty($_SESSION['user'])) {
        remember_restore();
    }
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
