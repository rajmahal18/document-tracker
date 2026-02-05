<?php
declare(strict_types=1);

$host = "localhost";
$user = "root";
$pass = "";
$db   = "doc_tracker";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = new mysqli($host, $user, $pass, $db);
$conn->set_charset("utf8mb4");
