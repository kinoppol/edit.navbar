<?php
declare(strict_types=1);

/**
 * Import users from the external RMS people API.
 *
 * Only the host part of the endpoint is configurable (stored in `settings` under
 * RVC_SETTING_IMPORT_BASE_URL so an admin can repoint it from /admin/settings.php);
 * the path + query below is fixed in code.
 *
 * Mapping:
 *   people_id                      → users.username  (upsert key)
 *   people_name + ' ' + people_surname → users.name
 *   ath_pass                       → users.password_hash (bcrypt)
 *   people_email                   → users.email
 * Rows with people_exit != 0 are skipped. created_at is never touched on re-import.
 */

require_once __DIR__ . '/settings.php';

const RVC_IMPORT_PATH = '/api_connection.php?app_name=nutty&data=people';

/** Full endpoint URL = configured base + fixed path. */
function rvc_import_endpoint(): string
{
    return rtrim(rvc_setting_get(RVC_SETTING_IMPORT_BASE_URL), '/') . RVC_IMPORT_PATH;
}

/** Fetch the raw response body. Throws RuntimeException on transport failure. */
function rvc_import_fetch(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false)  throw new RuntimeException('เชื่อมต่อแหล่งข้อมูลไม่สำเร็จ: ' . $err);
        if ($code >= 400)     throw new RuntimeException('แหล่งข้อมูลตอบกลับ HTTP ' . $code);
        return (string) $body;
    }

    $ctx  = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) throw new RuntimeException('เชื่อมต่อแหล่งข้อมูลไม่สำเร็จ');
    return $body;
}

/**
 * Locate the list of people inside the decoded JSON — accepts either a bare
 * array of records or an object wrapping one (e.g. {"data":[...]}).
 */
function rvc_import_extract_rows($decoded): array
{
    if (is_array($decoded) && array_is_list($decoded)) return $decoded;
    if (is_array($decoded)) {
        foreach ($decoded as $value) {
            if (is_array($value) && array_is_list($value) && isset($value[0]) && is_array($value[0])) {
                return $value;
            }
        }
    }
    return [];
}

function rvc_import_initials(string $name): string
{
    $initials = '';
    foreach (preg_split('/\s+/', trim($name)) as $part) {
        $ch = mb_substr($part, 0, 1);
        if ($ch !== '') {
            $initials .= $ch;
            if (mb_strlen($initials) >= 2) break;
        }
    }
    return $initials !== '' ? $initials : 'U';
}

/** Deterministic avatar colour so a re-import doesn't reshuffle colours. */
function rvc_import_color(string $seed): string
{
    $palette = ['#2F5BEA', '#16a34a', '#ea580c', '#7c3aed', '#db2777', '#0891b2', '#b45309', '#0f766e'];
    return $palette[abs(crc32($seed)) % count($palette)];
}

/**
 * Run the import.
 *
 * @param bool $updatePasswords also refresh the password of users that already exist
 * @return array{created:int,updated:int,skipped_exit:int,total:int,errors:string[],endpoint:string}
 */
function rvc_user_import_run(bool $updatePasswords = true): array
{
    $endpoint = rvc_import_endpoint();
    $body     = rvc_import_fetch($endpoint);

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('ข้อมูลที่ได้รับไม่ใช่ JSON ที่ถูกต้อง');
    }
    $rows = rvc_import_extract_rows($decoded);
    if ($rows === []) {
        throw new RuntimeException('ไม่พบรายการผู้ใช้ในข้อมูลที่ได้รับ');
    }

    $pdo = db();
    rvc_import_ensure_username_column($pdo);

    $findByUsername = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $insert = $pdo->prepare(
        "INSERT INTO users (username, name, email, password_hash, initials, avatar_color, role)
         VALUES (?, ?, ?, ?, ?, ?, 'user')"
    );
    $updateWithPass = $pdo->prepare(
        "UPDATE users SET name = ?, email = ?, password_hash = ?, initials = ?, avatar_color = ? WHERE id = ?"
    );
    $updateNoPass = $pdo->prepare(
        "UPDATE users SET name = ?, email = ?, initials = ?, avatar_color = ? WHERE id = ?"
    );

    $stats = ['created' => 0, 'updated' => 0, 'skipped_exit' => 0, 'total' => count($rows),
              'errors' => [], 'endpoint' => $endpoint];

    foreach ($rows as $r) {
        if (!is_array($r)) continue;

        if ((string) ($r['people_exit'] ?? '0') !== '0') {
            $stats['skipped_exit']++;
            continue;
        }

        $username = trim((string) ($r['people_id'] ?? ''));
        if ($username === '') {
            $stats['errors'][] = 'ข้ามรายการที่ไม่มี people_id';
            continue;
        }

        $name = trim(trim((string) ($r['people_name'] ?? '')) . ' ' . trim((string) ($r['people_surname'] ?? '')));
        if ($name === '') $name = $username;

        $email = trim((string) ($r['people_email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = $username . '@rvc.ac.th';   // placeholder — users.email is UNIQUE NOT NULL
        }

        $pass     = (string) ($r['ath_pass'] ?? '');
        $initials = rvc_import_initials($name);
        $color    = rvc_import_color($username);

        try {
            $findByUsername->execute([$username]);
            $existing = $findByUsername->fetch();

            if ($existing === false) {
                // New user — a blank source password must never become a usable login.
                $hash = $pass !== ''
                    ? password_hash($pass, PASSWORD_BCRYPT)
                    : password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
                $insert->execute([$username, $name, $email, $hash, $initials, $color]);
                $stats['created']++;
            } else {
                $id = (int) $existing['id'];
                if ($updatePasswords && $pass !== '') {
                    $updateWithPass->execute([$name, $email, password_hash($pass, PASSWORD_BCRYPT), $initials, $color, $id]);
                } else {
                    $updateNoPass->execute([$name, $email, $initials, $color, $id]);
                }
                $stats['updated']++;   // created_at is deliberately left untouched
            }
        } catch (\PDOException $e) {
            $stats['errors'][] = $username . ': อีเมล "' . $email . '" ซ้ำกับผู้ใช้อื่น — ข้ามรายการนี้';
        }
    }

    return $stats;
}

/** Existing installs may predate users.username — add it on demand. */
function rvc_import_ensure_username_column(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    if (!$pdo->query("SHOW COLUMNS FROM users LIKE 'username'")->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(100) DEFAULT NULL AFTER id");
        $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uq_username (username)");
    }
    $done = true;
}
