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

$docId     = (int)($_POST["document_id"] ?? 0);
$newStatus = strtoupper(trim((string)($_POST["new_status"] ?? "")));
$remarks   = trim((string)($_POST["remarks"] ?? ""));

$allowed = ["ACTIVE", "RELEASED", "ARCHIVED"];

if ($docId <= 0 || !in_array($newStatus, $allowed, true)) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

$role = $_SESSION["role"] ?? "division";
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$userId = (int)($_SESSION["user_id"] ?? 0);

if ($mySectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing section assignment"]);
  exit;
}

// Optional guard: only admin/records can archive
if ($newStatus === "ARCHIVED" && !in_array($role, ["admin", "records"], true)) {
  http_response_code(403);
  echo json_encode(["ok" => false, "error" => "Forbidden"]);
  exit;
}

try {
  $conn->begin_transaction();

  // Fetch current doc + ensure holder
  $stmt = $conn->prepare("
    SELECT
      d.current_status,
      d.current_holder_section_id,
      EXISTS (
        SELECT 1 FROM routes r
        WHERE r.document_id = d.id AND r.is_open = 1
      ) AS has_open_route
    FROM documents d
    WHERE d.id = ?
    LIMIT 1
  ");
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $doc = $stmt->get_result()->fetch_assoc();

  if (!$doc) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Document not found"]);
    exit;
  }

  $oldStatus = (string)$doc["current_status"];
  $holderSectionId = (int)$doc["current_holder_section_id"];
  $hasOpenRoute = (int)$doc["has_open_route"] === 1;

  // North Star: only holder can act (admin override allowed but still has section)
  if ($role !== "admin" && $holderSectionId !== $mySectionId) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Forbidden: your section does not hold this document"]);
    exit;
  }

  // Prevent status changes while in-transit? (optional but sensible)
  // Holder still physically holds it, but to avoid confusion, we block non-ACTIVE changes if there is an open route.
  if ($hasOpenRoute && $newStatus !== "ACTIVE") {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot change status while document has a pending route."]);
    exit;
  }

  if ($newStatus === $oldStatus) {
    $conn->rollback();
    echo json_encode([
      "ok" => true,
      "document_id" => $docId,
      "old_status" => $oldStatus,
      "new_status" => $newStatus
    ]);
    exit;
  }

  // Update documents status
  $stmt = $conn->prepare("
    UPDATE documents
    SET current_status = ?
    WHERE id = ?
  ");
  $stmt->bind_param("si", $newStatus, $docId);
  $stmt->execute();

  // Log event
  $payload = json_encode([
    "old_status" => $oldStatus,
    "new_status" => $newStatus,
    "remarks" => $remarks
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, payload_json)
    VALUES (?, 'status_changed', ?, ?, ?)
  ");
  $stmt->bind_param("iiis", $docId, $userId, $mySectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "old_status" => $oldStatus,
    "new_status" => $newStatus
  ]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error"]);
  exit;
}
