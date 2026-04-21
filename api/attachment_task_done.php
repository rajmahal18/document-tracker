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

if (!workflow_attachment_forwarding_enabled($conn)) {
  http_response_code(409);
  echo json_encode(["ok" => false, "error" => "Attachment forwarding is not available until the latest migration is applied."]);
  exit;
}

$docId = (int)($_POST["document_id"] ?? 0);
$branchIdReq = (int)($_POST["branch_id"] ?? 0);
$remarks = trim((string)($_POST["remarks"] ?? ""));

if ($docId <= 0) {
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
  $doneCount = 0;
  $eventScopeBranchId = $branchIdReq > 0 ? $branchIdReq : null;

  if ($docHasRealBranches) {
    if ($branchIdReq <= 0) {
      $conn->rollback();
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Bad request"]);
      exit;
    }

    $stmt = $conn->prepare("
      SELECT
        b.id,
        b.branch_status,
        b.current_assignee_user_id,
        b.current_assignee_section_id,
        COALESCE(SUM(CASE WHEN aft.recipient_user_id = ? AND aft.task_status = 'IN_PROGRESS' THEN 1 ELSE 0 END), 0) AS in_progress_count
      FROM document_branches b
      LEFT JOIN attachment_forward_tasks aft
        ON aft.recipient_branch_id = b.id
       AND aft.document_id = b.document_id
      WHERE b.id = ?
        AND b.document_id = ?
        AND b.is_reference = 1
      GROUP BY b.id
      LIMIT 1
      FOR UPDATE
    ");
    $stmt->bind_param("iii", $userId, $branchIdReq, $docId);
    $stmt->execute();
    $branch = $stmt->get_result()->fetch_assoc();

    if (!$branch) {
      $conn->rollback();
      http_response_code(404);
      echo json_encode(["ok" => false, "error" => "Attachment-forward lane not found."]);
      exit;
    }

    if ((int)($branch['current_assignee_user_id'] ?? 0) !== $userId || strtoupper((string)($branch['branch_status'] ?? '')) !== 'ACTIVE') {
      $conn->rollback();
      http_response_code(403);
      echo json_encode(["ok" => false, "error" => "You can only complete your own active attachment-forward lane."]);
      exit;
    }

    if ((int)($branch['in_progress_count'] ?? 0) <= 0) {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(["ok" => false, "error" => "Please receive the attachment-forwarded lane first before marking task done."]);
      exit;
    }

    $stmt = $conn->prepare("
      UPDATE attachment_forward_tasks
      SET task_status = 'DONE',
          done_at = NOW(),
          done_by_user_id = ?,
          done_remarks = ?,
          updated_at = NOW()
      WHERE document_id = ?
        AND recipient_branch_id = ?
        AND recipient_user_id = ?
        AND task_status = 'IN_PROGRESS'
    ");
    $stmt->bind_param("isiii", $actualUserId, $remarks, $docId, $branchIdReq, $userId);
    $stmt->execute();
    $doneCount = (int)$stmt->affected_rows;

    if ($doneCount <= 0) {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(["ok" => false, "error" => "Nothing was marked done."]);
      exit;
    }

    $stmt = $conn->prepare("
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
    $stmt->bind_param("iii", $userId, $branchIdReq, $docId);
    $stmt->execute();
  } else {
    $stmt = $conn->prepare("
      SELECT COUNT(*) AS in_progress_count
      FROM attachment_forward_tasks aft
      WHERE aft.document_id = ?
        AND COALESCE(aft.recipient_branch_id, 0) = 0
        AND aft.recipient_user_id = ?
        AND aft.task_status = 'IN_PROGRESS'
      FOR UPDATE
    ");
    $stmt->bind_param("ii", $docId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];

    if ((int)($row['in_progress_count'] ?? 0) <= 0) {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(["ok" => false, "error" => "Please receive the attachment-forwarded route first before marking task done."]);
      exit;
    }

    $stmt = $conn->prepare("
      UPDATE attachment_forward_tasks
      SET task_status = 'DONE',
          done_at = NOW(),
          done_by_user_id = ?,
          done_remarks = ?,
          updated_at = NOW()
      WHERE document_id = ?
        AND COALESCE(recipient_branch_id, 0) = 0
        AND recipient_user_id = ?
        AND task_status = 'IN_PROGRESS'
    ");
    $stmt->bind_param("isii", $actualUserId, $remarks, $docId, $userId);
    $stmt->execute();
    $doneCount = (int)$stmt->affected_rows;

    if ($doneCount <= 0) {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(["ok" => false, "error" => "Nothing was marked done."]);
      exit;
    }
  }

  $eventRemarks = '';
  if ($remarks !== '' && strcasecmp($remarks, 'none') !== 0) {
    $eventRemarks = $remarks;
  }

  $payload = json_encode([
    'kind' => 'attachment_forward_task_done',
    'branch_id' => $eventScopeBranchId,
    'done_count' => $doneCount,
    'remarks' => $eventRemarks,
    'acting_principal_user_id' => ($userId > 0 && $userId !== $actualUserId) ? $userId : null,
    'acting_principal_name' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_principal_name'] ?? '') : '',
    'acting_label' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_label'] ?? '') : '',
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
    VALUES (?, 'updated', ?, ?, ?, ?, ?)
  ");
  $stmt->bind_param("iiiiis", $docId, $actualUserId, $mySectionId, $mySectionId, $mySectionId, $payload);
  $stmt->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "branch_id" => $eventScopeBranchId,
    "done_count" => $doneCount,
  ]);
  exit;
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
