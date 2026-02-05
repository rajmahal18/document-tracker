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

require_csrf();

$docId   = (int)($_POST["document_id"] ?? 0);
$remarks = trim($_POST["remarks"] ?? "");

if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

$role = $_SESSION["role"] ?? "viewer";
$canReceive = in_array($role, ["admin","receiver","encoder"], true);

if (!$canReceive) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Forbidden"]);
  exit;
}

$mySectionId = (int)($_SESSION["section_id"] ?? 0);
if ($role !== "admin" && $mySectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing section assignment"]);
  exit;
}

$userId = (int)($_SESSION["user_id"] ?? 0);

// Fetch doc
$stmt = $conn->prepare("
  SELECT id, current_status, current_section_id
  FROM documents
  WHERE id = ?
  LIMIT 1
");
$stmt->bind_param("i", $docId);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();

if (!$doc) {
  http_response_code(404);
  echo json_encode(["ok" => false, "error" => "Document not found"]);
  exit;
}

$oldSectionId = (int)($doc["current_section_id"] ?? 0);
$newSectionId = $mySectionId;

// If already in your section, don't spam history
if ($role !== "admin" && $oldSectionId === $newSectionId) {
  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "message" => "Already received by your section."
  ]);
  exit;
}

// ✅ On receive: set location + reset status to incoming queue
$stmt = $conn->prepare("
  UPDATE documents
  SET current_section_id = ?, current_status = 'incoming', status_updated_at = NOW()
  WHERE id = ?
");
$stmt->bind_param("ii", $newSectionId, $docId);
$stmt->execute();

// ✅ Insert history as RECEIVED
$action = "received";
$stmt = $conn->prepare("
  INSERT INTO doc_history (document_id, action, remarks, acted_by)
  VALUES (?, ?, ?, ?)
");
$stmt->bind_param("issi", $docId, $action, $remarks, $userId);
$stmt->execute();

echo json_encode([
  "ok" => true,
  "document_id" => $docId,
  "old_section_id" => $oldSectionId,
  "new_section_id" => $newSectionId
]);
