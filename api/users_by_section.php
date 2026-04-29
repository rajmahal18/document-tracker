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
  SELECT id, full_name, is_chief, " . (email_verified_at_column_exists($conn) ? "email_verified_at" : "NULL") . " AS email_verified_at
  FROM users
  WHERE section_id = ?
    AND is_active = 1
  ORDER BY is_chief DESC, full_name ASC
");
$stmt->bind_param("i", $sectionId);
$stmt->execute();
$res = $stmt->get_result();

$out = [];
while ($row = $res->fetch_assoc()) {
  $out[] = [
    "id" => (int)$row["id"],
    "name" => (string)$row["full_name"],
    "is_chief" => ((int)($row["is_chief"] ?? 0) === 1),
    "email_verified" => !empty($row["email_verified_at"]),
  ];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
