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

if ($docId <= 0 || $newStatus === "") {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

$role        = (string)($_SESSION["role"] ?? "user");
$mySectionId = (int)($_SESSION["section_id"] ?? 0);
$userId      = (int)($_SESSION["user_id"] ?? 0);
$isPrivileged = ($role === 'admin');

try {
  $conn->begin_transaction();

  $branchMode = workflow_branch_mode_enabled($conn);
  if ($branchMode) {
    $stmt = $conn->prepare("\n      SELECT\n        d.current_status,\n        d.current_holder_section_id,\n        EXISTS (\n          SELECT 1 FROM routes r\n          WHERE r.document_id = d.id\n            AND r.received_at IS NULL\n            AND r.cancelled_at IS NULL\n            AND r.route_kind = 'ACTION'\n        ) AS has_open_route,\n        EXISTS (\n          SELECT 1 FROM document_branches b\n          WHERE b.document_id = d.id\n            AND b.branch_status = 'ACTIVE'\n            AND b.is_reference = 0\n        ) AS has_active_branch\n      FROM documents d\n      WHERE d.id = ?\n      LIMIT 1\n    ");
  } else {
    $stmt = $conn->prepare("\n      SELECT\n        d.current_status,\n        d.current_holder_section_id,\n        EXISTS (\n          SELECT 1 FROM routes r\n          WHERE r.document_id = d.id\n            AND r.received_at IS NULL\n            AND r.cancelled_at IS NULL\n        ) AS has_open_route,\n        0 AS has_active_branch\n      FROM documents d\n      WHERE d.id = ?\n      LIMIT 1\n    ");
  }
  $stmt->bind_param("i", $docId);
  $stmt->execute();
  $doc = $stmt->get_result()->fetch_assoc();

  if (!$doc) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Document not found"]);
    exit;
  }

  $oldStatus = strtoupper((string)$doc["current_status"]);
  $holderSectionId = (int)$doc["current_holder_section_id"];
  $hasOpenRoute = ((int)($doc["has_open_route"] ?? 0) === 1);
  $hasActiveBranch = ((int)($doc["has_active_branch"] ?? 0) === 1);

  if (!$isPrivileged && $holderSectionId !== $mySectionId) {
    $conn->rollback();
    http_response_code(403);
    echo json_encode(["ok" => false, "error" => "Only the current holder may change document lifecycle status."]);
    exit;
  }

  if ($hasOpenRoute || ($branchMode && $hasActiveBranch)) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Cannot change root status while document still has active workflow branches or pending routes."]);
    exit;
  }

  $allowedTransitions = [
    "ACTIVE"   => ["RELEASED", "ARCHIVED"],
    "RELEASED" => ["ACTIVE", "ARCHIVED"],
    "ARCHIVED" => ["RELEASED"],
  ];

  if (!isset($allowedTransitions[$oldStatus]) || !in_array($newStatus, $allowedTransitions[$oldStatus], true)) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Invalid status transition."]);
    exit;
  }

  if ($newStatus === $oldStatus) {
    $conn->rollback();
    echo json_encode(["ok" => true, "document_id" => $docId, "old_status" => $oldStatus, "new_status" => $newStatus]);
    exit;
  }

  $stmt = $conn->prepare("UPDATE documents SET current_status = ?, updated_at = NOW() WHERE id = ?");
  $stmt->bind_param("si", $newStatus, $docId);
  $stmt->execute();

  $eventType = "updated";
  if ($oldStatus === "ACTIVE" && $newStatus === "RELEASED") $eventType = "released";
  if ($oldStatus === "RELEASED" && $newStatus === "ACTIVE") $eventType = "released";
  if (($oldStatus === "ACTIVE" || $oldStatus === "RELEASED") && $newStatus === "ARCHIVED") $eventType = "archived";
  if ($oldStatus === "ARCHIVED" && $newStatus === "RELEASED") $eventType = "archived";

  $eventRemarks = '';
  if ($remarks !== '' && strcasecmp($remarks, 'none') !== 0) {
    $eventRemarks = $remarks;
  }

  $payload = json_encode([
    "old_status" => $oldStatus,
    "new_status" => $newStatus,
    "remarks" => $eventRemarks,
    "branch_mode" => $branchMode,
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("\n    INSERT INTO document_events\n      (document_id, event_type, actor_user_id, actor_section_id, payload_json)\n    VALUES (?, ?, ?, ?, ?)\n  ");
  $stmt->bind_param("isiis", $docId, $eventType, $userId, $mySectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "event_type" => $eventType,
    "old_status" => $oldStatus,
    "new_status" => $newStatus,
    "branch_mode" => $branchMode,
  ]);
  exit;

} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
