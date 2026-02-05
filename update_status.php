<?php
declare(strict_types=1);

require __DIR__ . "/includes/bootstrap.php";
require_login();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo "Method not allowed";
  exit;
}

$docId = (int)($_POST["document_id"] ?? 0);
$newStatus = trim($_POST["new_status"] ?? "");
$remarks = trim($_POST["remarks"] ?? "");

$allowed = ["incoming", "under_action", "released", "archived"];
if ($docId <= 0 || !in_array($newStatus, $allowed, true)) {
  http_response_code(400);
  echo "Bad request";
  exit;
}

// Fetch current doc
$stmt = $conn->prepare("SELECT id, current_status FROM documents WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $docId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
  http_response_code(404);
  echo "Document not found";
  exit;
}

$oldStatus = $doc["current_status"];

// Decide action label for history
$action = match($newStatus) {
  "under_action" => "forwarded",
  "released" => "released",
  "archived" => "archived",
  default => "updated"
};

// Update document status + timestamp
$stmt = $conn->prepare("
  UPDATE documents
  SET current_status = ?, status_updated_at = NOW()
  WHERE id = ?
");
$stmt->bind_param("si", $newStatus, $docId);
$stmt->execute();

// Insert history
$stmt = $conn->prepare("
  INSERT INTO doc_history (document_id, action, remarks, acted_by)
  VALUES (?, ?, ?, ?)
");
$userId = (int)$_SESSION["user_id"];
$stmt->bind_param("issi", $docId, $action, $remarks, $userId);
$stmt->execute();

header("Content-Type: application/json");
echo json_encode([
  "ok" => true,
  "document_id" => $docId,
  "old_status" => $oldStatus,
  "new_status" => $newStatus
]);
