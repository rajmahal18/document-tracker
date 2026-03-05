<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json; charset=utf-8");

$sectionId = (int)($_GET["section_id"] ?? 0);
if ($sectionId <= 0) {
  echo json_encode([]);
  exit;
}

// NOTE: adjust column names if your users table differs.
// You mentioned users(full_name,...)
$stmt = $conn->prepare("
  SELECT id, full_name
  FROM users
  WHERE section_id = ?
    AND is_active = 1
  ORDER BY full_name ASC
");
$stmt->bind_param("i", $sectionId);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = [
    "id" => (int)$row["id"],
    "name" => (string)$row["full_name"],
  ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);