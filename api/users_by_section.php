<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json");

$sectionId = (int)($_GET["section_id"] ?? 0);
if ($sectionId <= 0) {
  http_response_code(400);
  echo json_encode([]);
  exit;
}

try {
  $stmt = $conn->prepare("
    SELECT
      u.id,
      u.full_name AS name
    FROM users u
    WHERE u.section_id = ?
      AND u.is_active = 1
    ORDER BY u.full_name ASC, u.id ASC
  ");
  $stmt->bind_param("i", $sectionId);
  $stmt->execute();

  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  echo json_encode($rows, JSON_UNESCAPED_UNICODE);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([]);
  exit;
}