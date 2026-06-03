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
$toSectionId = (int)($_POST["to_section_id"] ?? 0);
$toUserId = (int)($_POST["to_user_id"] ?? 0);
$notes = trim((string)($_POST["notes"] ?? ""));

if ($docId <= 0 || $toSectionId <= 0 || $toUserId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Please select one recipient for the request."]);
  exit;
}

$identity = effective_document_identity($conn);
$actualUserId = (int)($identity['actual_user_id'] ?? 0);
$userId = (int)($identity['effective_user_id'] ?? 0);
$mySectionId = (int)($identity['effective_section_id'] ?? 0);
$isChief = (bool)($identity['effective_is_chief'] ?? false);

if ($actualUserId <= 0 || $userId <= 0 || $mySectionId <= 0) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Missing session assignment"]);
  exit;
}

try {
  $conn->begin_transaction();

  $branchMode = workflow_branch_mode_enabled($conn);
  $docHasRealBranches = ($branchMode && workflow_document_has_real_branches($conn, $docId));

  $sourceBranch = null;
  $sourceBranchId = null;
  if ($docHasRealBranches) {
    $sourceBranch = workflow_find_single_actionable_branch($conn, $docId, $userId, $branchIdReq > 0 ? $branchIdReq : null);
    if (!$sourceBranch) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode(["ok" => false, "error" => "You can only request from your active actionable lane."]);
      exit;
    }
    $sourceBranchId = (int)($sourceBranch['id'] ?? 0);
    if ($sourceBranchId <= 0) {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(["ok" => false, "error" => "Request source lane is invalid."]);
      exit;
    }
  } else {
    if (!workflow_user_can_act_legacy_document($conn, $docId, $userId, $mySectionId, $isChief)) {
      $conn->rollback();
      http_response_code(403);
      echo json_encode(["ok" => false, "error" => "You are not the current actionable holder for this document."]);
      exit;
    }
  }

  if ($docHasRealBranches && $sourceBranchId > 0 && workflow_branch_has_open_attachment_forward_tasks($conn, $docId, $sourceBranchId)) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Normal actions are temporarily locked while there are pending attachment-forward tasks."]);
    exit;
  }
  if ($docHasRealBranches && $sourceBranchId > 0 && workflow_branch_has_open_action_requests($conn, $docId, $sourceBranchId)) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "You already have a pending signature/approval request from this lane."]);
    exit;
  }
  if (!$docHasRealBranches && workflow_user_has_open_attachment_forward_tasks_as_sender($conn, $docId, $actualUserId)) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Normal actions are temporarily locked while there are pending attachment-forward tasks."]);
    exit;
  }
  if (!$docHasRealBranches && workflow_user_has_open_action_requests_as_sender($conn, $docId, $actualUserId)) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "You already have a pending signature/approval request on this document."]);
    exit;
  }

  $stmtDoc = $conn->prepare("
    SELECT d.current_status, d.current_holder_section_id
    FROM documents d
    WHERE d.id = ?
    LIMIT 1
    FOR UPDATE
  ");
  $stmtDoc->bind_param("i", $docId);
  $stmtDoc->execute();
  $doc = $stmtDoc->get_result()->fetch_assoc();
  if (!$doc) {
    $conn->rollback();
    http_response_code(404);
    echo json_encode(["ok" => false, "error" => "Document not found."]);
    exit;
  }
  if (strtoupper((string)($doc['current_status'] ?? 'ACTIVE')) !== 'ACTIVE') {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Only ACTIVE documents can request signature or approval."]);
    exit;
  }

  $stmtSec = $conn->prepare("SELECT id, name FROM sections WHERE id = ? LIMIT 1");
  $stmtSec->bind_param("i", $toSectionId);
  $stmtSec->execute();
  $toSection = $stmtSec->get_result()->fetch_assoc();
  if (!$toSection) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Invalid destination section."]);
    exit;
  }

  $stmtUser = $conn->prepare("
    SELECT id, full_name
    FROM users
    WHERE id = ?
      AND section_id = ?
      AND is_active = 1
    LIMIT 1
  ");
  $stmtUser->bind_param("ii", $toUserId, $toSectionId);
  $stmtUser->execute();
  $recipient = $stmtUser->get_result()->fetch_assoc();
  if (!$recipient) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "Recipient is invalid for the selected section."]);
    exit;
  }
  if ($toUserId === $userId) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "You cannot request signature/approval from yourself."]);
    exit;
  }

  $stmtDup = $conn->prepare("
    SELECT 1
    FROM routes
    WHERE document_id = ?
      AND received_at IS NULL
      AND cancelled_at IS NULL
      AND to_user_id = ?
    LIMIT 1
  ");
  $stmtDup->bind_param("ii", $docId, $toUserId);
  $stmtDup->execute();
  if ($stmtDup->get_result()->fetch_row()) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Request blocked. This recipient already has an active incoming copy of the document."]);
    exit;
  }

  $sendBatchId = bin2hex(random_bytes(16));
  $recipientBranchId = null;
  if ($docHasRealBranches) {
    $branchLabel = trim((string)($recipient['full_name'] ?? ("User #" . $toUserId)));
    $recipientBranchId = workflow_create_branch($conn, [
      'document_id' => $docId,
      'parent_branch_id' => (int)$sourceBranchId,
      'branch_label' => $branchLabel,
      'current_assignee_user_id' => $toUserId,
      'current_assignee_section_id' => $toSectionId,
      'branch_status' => 'ACTIVE',
      'is_reference' => 1,
      'created_by_user_id' => $actualUserId,
    ]);

    workflow_grant_visibility($conn, $docId, $toUserId, 'REFERENCE', $recipientBranchId, $actualUserId);
    workflow_grant_visibility($conn, $docId, $userId, 'PARTICIPANT', $recipientBranchId, $actualUserId);
    if ($actualUserId !== $userId) {
      workflow_grant_visibility($conn, $docId, $actualUserId, 'PARTICIPANT', $recipientBranchId, $actualUserId);
    }
  }

  $fromSectionId = $docHasRealBranches ? $mySectionId : (int)($doc['current_holder_section_id'] ?? 0);
  if ($docHasRealBranches) {
    $stmtRoute = $conn->prepare("
      INSERT INTO routes
        (document_id, branch_id, from_section_id, to_section_id, from_user_id, to_user_id, route_kind, send_batch_id, received_at, sent_by_user_id, remarks)
      VALUES
        (?, ?, ?, ?, ?, ?, 'REFERENCE', ?, NULL, ?, ?)
    ");
    $stmtRoute->bind_param("iiiiiisis", $docId, $recipientBranchId, $fromSectionId, $toSectionId, $actualUserId, $toUserId, $sendBatchId, $actualUserId, $notes);
  } else {
    $stmtRoute = $conn->prepare("
      INSERT INTO routes
        (document_id, from_section_id, to_section_id, to_user_id, route_kind, send_batch_id, received_at, sent_by_user_id, remarks)
      VALUES
        (?, ?, ?, ?, 'REFERENCE', ?, NULL, ?, ?)
    ");
    $stmtRoute->bind_param("iiiisis", $docId, $fromSectionId, $toSectionId, $toUserId, $sendBatchId, $actualUserId, $notes);
  }
  $stmtRoute->execute();
  $routeId = (int)$conn->insert_id;

  if (!$docHasRealBranches) {
    $stmtParticipant = $conn->prepare("
      INSERT IGNORE INTO document_participants
        (document_id, section_id, added_via, added_by_user_id)
      VALUES (?, ?, 'movement', ?)
    ");
    $stmtParticipant->bind_param("iii", $docId, $toSectionId, $actualUserId);
    $stmtParticipant->execute();
  }

  $stmtTask = $conn->prepare("
    INSERT INTO document_action_requests
      (document_id, sender_branch_id, recipient_branch_id, route_id, sender_user_id, sender_section_id, recipient_user_id, recipient_section_id, request_notes, task_status)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_RECEIVE')
  ");
  $stmtTask->bind_param(
    "iiiiiiiis",
    $docId,
    $sourceBranchId,
    $recipientBranchId,
    $routeId,
    $actualUserId,
    $mySectionId,
    $toUserId,
    $toSectionId,
    $notes
  );
  $stmtTask->execute();
  $taskId = (int)$conn->insert_id;

  $payload = json_encode([
    'kind' => 'action_request_created',
    'request_id' => $taskId,
    'route_kind' => 'REFERENCE',
    'send_batch_id' => $sendBatchId,
    'from_section_id' => $mySectionId,
    'to_section_id' => $toSectionId,
    'from_section_name' => '',
    'to_section_name' => (string)($toSection['name'] ?? ''),
    'to_user_id' => $toUserId,
    'to_user_name' => (string)($recipient['full_name'] ?? ''),
    'recipient_names' => [(string)($recipient['full_name'] ?? '')],
    'source_branch_id' => $sourceBranchId,
    'new_branch_ids' => $recipientBranchId ? [$recipientBranchId] : [],
    'request_notes' => $notes,
    'acting_principal_user_id' => ($userId > 0 && $userId !== $actualUserId) ? $userId : null,
    'acting_principal_name' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_principal_name'] ?? '') : '',
    'acting_label' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_label'] ?? '') : '',
  ], JSON_UNESCAPED_UNICODE);

  $stmtEvent = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
    VALUES (?, 'forwarded', ?, ?, ?, ?, ?)
  ");
  $stmtEvent->bind_param("iiiiis", $docId, $actualUserId, $mySectionId, $fromSectionId, $toSectionId, $payload);
  $stmtEvent->execute();

  $conn->commit();

  echo json_encode([
    'ok' => true,
    'document_id' => $docId,
    'route_id' => $routeId,
    'request_id' => $taskId,
    'branch_id' => $recipientBranchId,
    'message' => 'Signature/approval request sent.',
  ]);
  exit;
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
