<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

$docId = (int)($_POST["document_id"] ?? 0);
$newStatus = trim($_POST["new_status"] ?? "");
$remarks = trim($_POST["remarks"] ?? "");

// ✅ Use shared constant from includes/constants.php
$allowed = defined("DOC_STATUSES") ? DOC_STATUSES : ["incoming", "under_action", "released", "archived"];

if ($docId <= 0 || !in_array($newStatus, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

// ✅ Role check (adjust roles later to match your DB values)
$role = $_SESSION["role"] ?? "viewer";

$can = match($newStatus) {
  "under_action" => in_array($role, ["admin","tracker","action"], true),
  "released"     => in_array($role, ["admin","releaser"], true),
  "archived"     => in_array($role, ["admin"], true),
  "incoming"     => in_array($role, ["admin","receiver","encoder"], true),
  default        => false
};

if (!$can) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Forbidden"]);
  exit;
}

// Fetch current doc
$stmt = $conn->prepare("SELECT id, current_status FROM documents WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $docId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Document not found"]);
  exit;
}

$oldStatus = $doc["current_status"];

// Optional cleanup: prevent duplicate history spam if same status
if ($newStatus === $oldStatus) {
  echo json_encode(["ok" => true, "document_id" => $docId, "old_status" => $oldStatus, "new_status" => $newStatus]);
  exit;
}

// Decide action label for history
$action = match($newStatus) {
  "under_action" => "forwarded",
  "released"     => "released",
  "archived"     => "archived",
  default        => "updated"
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
$userId = (int)($_SESSION["user_id"] ?? 0);
$stmt->bind_param("issi", $docId, $action, $remarks, $userId);
$stmt->execute();

echo json_encode([
  "ok" => true,
  "document_id" => $docId,
  "old_status" => $oldStatus,
  "new_status" => $newStatus
]);
