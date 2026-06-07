<?php
declare(strict_types=1);

// Connection settings. The defaults below suit a local WampServer/XAMPP dev box.
// On a real server, run setup.php and fill in the form — it writes the values to
// config/db.local.php (git-ignored), which overrides these defaults here.
(function (): void {
    $cfg = [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'rvc_navbar',
        'user' => 'root',
        'pass' => '',
    ];
    $localCfg = __DIR__ . '/db.local.php';
    if (is_file($localCfg)) {
        $override = require $localCfg;
        if (is_array($override)) {
            $cfg = array_merge($cfg, $override);
        }
    }
    define('DB_HOST', (string) $cfg['host']);
    define('DB_PORT', (int)    $cfg['port']);
    define('DB_NAME', (string) $cfg['name']);
    define('DB_USER', (string) $cfg['user']);
    define('DB_PASS', (string) $cfg['pass']);
})();

// Derive web base path from document root (works on WampServer / Apache)
(function (): void {
    $docRoot = rtrim(str_replace('\\', '/', (string) realpath($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    $appDir  = rtrim(str_replace('\\', '/', (string) realpath(dirname(__DIR__))), '/');
    define('APP_BASE', ($docRoot !== '' && str_starts_with($appDir, $docRoot))
        ? substr($appDir, strlen($docRoot))
        : '');
})();

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST, DB_PORT, DB_NAME
    );
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}
