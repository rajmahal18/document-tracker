<?php
declare(strict_types=1);

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../includes/app_config.php';
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$conn->set_charset('utf8mb4');
