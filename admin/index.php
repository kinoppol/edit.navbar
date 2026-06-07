<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/config/auth.php';
require_admin();
header('Location: ' . APP_BASE . '/admin/apps.php');
exit;
