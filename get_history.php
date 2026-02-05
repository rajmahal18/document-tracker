<?php
declare(strict_types=1);

require __DIR__ . "/includes/bootstrap.php";
require_login();

$docId = (int)($_GET["document_id"] ?? 0);
if ($docId <= 0) {
  http_response_code(400);
  header("Content-Type: application/json");
  echo json_encode(["ok" => false, "error" => "Bad document_id"]);
  exit;
}

$stmt = $conn->prepare("
  SELECT
    h.action,
    h.remarks,
    h.acted_at,
    u.full_name AS actor
  FROM doc_history h
  LEFT JOIN users u ON u.id = h.acted_by
  WHERE h.document_id = ?
  ORDER BY h.acted_at DESC
  LIMIT 50
");
$stmt->bind_param("i", $docId);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

header("Content-Type: application/json");
echo json_encode(["ok" => true, "history" => $rows]);
