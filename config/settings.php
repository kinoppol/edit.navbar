<?php
declare(strict_types=1);

/**
 * Key/value application settings stored in the `settings` table.
 * Used for values an admin must be able to change at runtime (e.g. the base URL
 * of the external RMS server used by the user-import feature).
 *
 * The table is also created by setup.php; the guarded CREATE here keeps existing
 * installs working without a re-run of setup.
 */

const RVC_SETTING_IMPORT_BASE_URL = 'user_import_base_url';

/** Default values used when a key has never been saved. */
function rvc_settings_defaults(): array
{
    return [
        RVC_SETTING_IMPORT_BASE_URL => 'http://rms.rvc.ac.th',
    ];
}

function rvc_settings_ensure(): void
{
    static $done = false;
    if ($done) return;
    db()->exec("CREATE TABLE IF NOT EXISTS settings (
        skey       VARCHAR(100) NOT NULL,
        svalue     TEXT         NOT NULL,
        updated_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (skey)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function rvc_setting_get(string $key, ?string $default = null): string
{
    rvc_settings_ensure();
    $stmt = db()->prepare("SELECT svalue FROM settings WHERE skey = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if ($row !== false) {
        return (string) $row['svalue'];
    }
    return $default ?? (string) (rvc_settings_defaults()[$key] ?? '');
}

function rvc_setting_set(string $key, string $value): void
{
    rvc_settings_ensure();
    db()->prepare("INSERT INTO settings (skey, svalue) VALUES (?, ?)
                   ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)")
        ->execute([$key, $value]);
}
