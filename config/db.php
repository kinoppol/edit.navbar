<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'rvc_navbar');
define('DB_USER', 'root');
define('DB_PASS', '');

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
