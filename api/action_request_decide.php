<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();

header("Content-Type: application/json");

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
  http_response_code(405);
  echo json_encode(["ok" => false, "error" => "Method not allowed"]);
  exit;
}

require_csrf();

if (!workflow_action_requests_enabled($conn)) {
  http_response_code(409);
  echo json_encode(["ok" => false, "error" => "Signature/approval requests are not available until the latest migration is applied."]);
  exit;
}

$docId = (int)($_POST["document_id"] ?? 0);
$branchIdReq = (int)($_POST["branch_id"] ?? 0);
$decision = strtoupper(trim((string)($_POST["decision"] ?? "")));
$notes = trim((string)($_POST["notes"] ?? ""));

$allowedDecisions = [
  'SIGNED' => 'SIGNED',
  'APPROVED' => 'APPROVED',
  'REJECTED' => 'REJECTED',
];

if ($docId <= 0 || !isset($allowedDecisions[$decision])) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Bad request"]);
  exit;
}

$identity = effective_document_identity($conn);
$actualUserId = (int)($identity['actual_user_id'] ?? 0);
$userId = (int)($identity['effective_user_id'] ?? 0);
$mySectionId = (int)($identity['effective_section_id'] ?? 0);

if ($actualUserId <= 0 || $userId <= 0 || $mySectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing session assignment"]);
  exit;
}

try {
  $conn->begin_transaction();

  $branchMode = workflow_branch_mode_enabled($conn);
  $docHasRealBranches = ($branchMode && workflow_document_has_real_branches($conn, $docId));
  $resolvedBranchId = $docHasRealBranches ? max(0, $branchIdReq) : 0;

  if ($docHasRealBranches && $resolvedBranchId <= 0) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Please select the request lane first."]);
    exit;
  }

  $sql = "
    SELECT
      dar.id,
      dar.route_id,
      dar.sender_branch_id,
      dar.recipient_branch_id,
      dar.sender_user_id,
      dar.sender_section_id,
      dar.recipient_user_id,
      dar.recipient_section_id,
      dar.task_status,
      ru.full_name AS recipient_name
    FROM document_action_requests dar
    LEFT JOIN users ru ON ru.id = dar.recipient_user_id
    WHERE dar.document_id = ?
      AND dar.recipient_user_id = ?
      AND dar.task_status = 'IN_PROGRESS'
  ";
  $types = 'ii';
  $params = [$docId, $userId];
  if ($docHasRealBranches) {
    $sql .= " AND dar.recipient_branch_id = ?";
    $types .= 'i';
    $params[] = $resolvedBranchId;
  } else {
    $sql .= " AND COALESCE(dar.recipient_branch_id, 0) = 0";
  }
  $sql .= " ORDER BY dar.id DESC LIMIT 1 FOR UPDATE";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $request = $stmt->get_result()->fetch_assoc();

  if (!$request) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Please receive the request first before responding."]);
    exit;
  }

  $taskId = (int)($request['id'] ?? 0);
  $routeId = (int)($request['route_id'] ?? 0);
  $senderBranchId = (int)($request['sender_branch_id'] ?? 0);
  $recipientBranchId = (int)($request['recipient_branch_id'] ?? 0);

  $stmtUpdate = $conn->prepare("
    UPDATE document_action_requests
    SET task_status = ?,
        acted_at = NOW(),
        acted_by_user_id = ?,
        decision_notes = ?,
        updated_at = NOW()
    WHERE id = ?
      AND task_status = 'IN_PROGRESS'
  ");
  $stmtUpdate->bind_param("sisi", $decision, $actualUserId, $notes, $taskId);
  $stmtUpdate->execute();

  if ($stmtUpdate->affected_rows <= 0) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Nothing was updated."]);
    exit;
  }

  if ($docHasRealBranches && $recipientBranchId > 0) {
    $stmtBranch = $conn->prepare("
      UPDATE document_branches
      SET branch_status = 'COMPLETED',
          current_assignee_user_id = NULL,
          current_assignee_section_id = NULL,
          completed_by_user_id = ?,
          updated_at = NOW()
      WHERE id = ?
        AND document_id = ?
        AND branch_status = 'ACTIVE'
    ");
    $stmtBranch->bind_param("iii", $userId, $recipientBranchId, $docId);
    $stmtBranch->execute();
  }

  $payload = json_encode([
    'kind' => 'action_request_decided',
    'request_id' => $taskId,
    'decision' => $decision,
    'remarks' => $notes,
    'decision_notes' => $notes,
    'route_id' => $routeId,
    'branch_id' => $recipientBranchId > 0 ? $recipientBranchId : null,
    'source_branch_id' => $senderBranchId > 0 ? $senderBranchId : null,
    'acting_principal_user_id' => ($userId > 0 && $userId !== $actualUserId) ? $userId : null,
    'acting_principal_name' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_principal_name'] ?? '') : '',
    'acting_label' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_label'] ?? '') : '',
  ], JSON_UNESCAPED_UNICODE);

  $stmtEvent = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
    VALUES (?, 'updated', ?, ?, ?, ?, ?)
  ");
  $senderSectionId = (int)($request['sender_section_id'] ?? 0);
  $recipientSectionId = (int)($request['recipient_section_id'] ?? 0);
  $stmtEvent->bind_param("iiiiis", $docId, $actualUserId, $mySectionId, $senderSectionId, $recipientSectionId, $payload);
  $stmtEvent->execute();

  $conn->commit();

  echo json_encode([
    'ok' => true,
    'document_id' => $docId,
    'request_id' => $taskId,
    'decision' => $decision,
    'message' => match ($decision) {
      'SIGNED' => 'Request marked as signed.',
      'APPROVED' => 'Request marked as approved.',
      default => 'Request marked as rejected.',
    },
  ]);
  exit;
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
