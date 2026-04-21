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
$rowsRaw = $_POST["routing_rows"] ?? [];
$remarks = trim((string)($_POST["remarks"] ?? ""));

if ($docId <= 0 || !is_array($rowsRaw) || $rowsRaw === []) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Please select at least one attachment routing row."]);
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

$normalizedRows = [];
foreach ($rowsRaw as $row) {
  if (!is_array($row)) continue;
  $attachmentId = (int)($row['attachment_id'] ?? 0);
  $toSectionId = (int)($row['to_section_id'] ?? 0);
  $toUserId = (int)($row['to_user_id'] ?? 0);
  if ($attachmentId <= 0 || $toSectionId <= 0 || $toUserId <= 0) continue;
  $normalizedRows[] = [
    'attachment_id' => $attachmentId,
    'to_section_id' => $toSectionId,
    'to_user_id' => $toUserId,
  ];
}

if ($normalizedRows === []) {
  http_response_code(400);
  echo json_encode(["ok" => false, "error" => "Please select valid attachments and recipients."]);
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
      echo json_encode(["ok" => false, "error" => "You can only forward attachments from your active actionable lane."]);
      exit;
    }
    $sourceBranchId = (int)($sourceBranch['id'] ?? 0);
    if ($sourceBranchId <= 0) {
      $conn->rollback();
      http_response_code(409);
      echo json_encode(["ok" => false, "error" => "Attachment forwarding source lane is invalid."]);
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

  $stmtDoc = $conn->prepare("
    SELECT current_status
    FROM documents
    WHERE id = ?
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
    echo json_encode(["ok" => false, "error" => "Only ACTIVE documents can forward attachments."]);
    exit;
  }

  $attachmentIds = array_values(array_unique(array_map(static fn($r) => (int)$r['attachment_id'], $normalizedRows)));
  $attachmentPlaceholders = implode(",", array_fill(0, count($attachmentIds), "?"));
  $attachmentTypes = "i" . str_repeat("i", count($attachmentIds));
  $attachmentParams = array_merge([$docId], $attachmentIds);

  $stmtAtt = $conn->prepare("
    SELECT
      id,
      branch_id,
      original_name,
      stored_name,
      stored_path,
      mime,
      size_bytes,
      note,
      is_append,
      uploaded_by_user_id,
      uploaded_by_section_id
    FROM document_attachments
    WHERE document_id = ?
      AND is_deleted = 0
      AND id IN ($attachmentPlaceholders)
    FOR UPDATE
  ");
  $stmtAtt->bind_param($attachmentTypes, ...$attachmentParams);
  $stmtAtt->execute();
  $attachmentRows = $stmtAtt->get_result()->fetch_all(MYSQLI_ASSOC);
  $attachmentsById = [];
  foreach ($attachmentRows as $attRow) {
    $attachmentsById[(int)$attRow['id']] = $attRow;
  }

  if (count($attachmentsById) !== count($attachmentIds)) {
    $conn->rollback();
    http_response_code(400);
    echo json_encode(["ok" => false, "error" => "One or more selected attachments are invalid."]);
    exit;
  }

  foreach ($normalizedRows as $row) {
    $att = $attachmentsById[(int)$row['attachment_id']] ?? null;
    if (!$att) {
      $conn->rollback();
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "Selected attachment was not found."]);
      exit;
    }
    $attBranchId = (int)($att['branch_id'] ?? 0);
    if ($docHasRealBranches) {
      if ($attBranchId > 0 && $attBranchId !== (int)$sourceBranchId) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(["ok" => false, "error" => "You can only forward global attachments or attachments that belong to your current lane."]);
        exit;
      }
    } else {
      if ($attBranchId > 0) {
        $conn->rollback();
        http_response_code(409);
        echo json_encode(["ok" => false, "error" => "Please use the active lane view when forwarding lane-scoped attachments."]);
        exit;
      }
    }
  }

  $recipientKeys = [];
  foreach ($normalizedRows as $row) {
    $recipientKeys[$row['to_section_id'] . ':' . $row['to_user_id']] = [
      'to_section_id' => (int)$row['to_section_id'],
      'to_user_id' => (int)$row['to_user_id'],
    ];
  }
  $recipientItems = array_values($recipientKeys);
  $recipientIds = array_values(array_unique(array_map(static fn($r) => (int)$r['to_user_id'], $recipientItems)));
  $recipientPlaceholders = implode(",", array_fill(0, count($recipientIds), "?"));
  $recipientTypes = "i" . str_repeat("i", count($recipientIds));
  $recipientParams = array_merge([$docId], $recipientIds);

  $stmtDup = $conn->prepare("
    SELECT DISTINCT to_user_id
    FROM routes
    WHERE document_id = ?
      AND received_at IS NULL
      AND cancelled_at IS NULL
      AND to_user_id IN ($recipientPlaceholders)
  ");
  $stmtDup->bind_param($recipientTypes, ...$recipientParams);
  $stmtDup->execute();
  $dupRecipients = array_map(static fn($r) => (int)$r['to_user_id'], $stmtDup->get_result()->fetch_all(MYSQLI_ASSOC));
  if ($dupRecipients !== []) {
    $conn->rollback();
    http_response_code(409);
    echo json_encode(["ok" => false, "error" => "Attachment forwarding blocked because one or more selected recipients already have an active incoming route for this document."]);
    exit;
  }

  $stmtUsers = $conn->prepare("
    SELECT u.id, u.full_name, u.section_id
    FROM users u
    WHERE u.is_active = 1
      AND u.id IN ($recipientPlaceholders)
  ");
  $stmtUsers->bind_param(str_repeat("i", count($recipientIds)), ...$recipientIds);
  $stmtUsers->execute();
  $recipientInfo = [];
  foreach ($stmtUsers->get_result()->fetch_all(MYSQLI_ASSOC) as $userRow) {
    $recipientInfo[(int)$userRow['id']] = $userRow;
  }

  foreach ($recipientItems as $item) {
    $rid = (int)$item['to_user_id'];
    $sid = (int)$item['to_section_id'];
    $userRow = $recipientInfo[$rid] ?? null;
    if (!$userRow || (int)($userRow['section_id'] ?? 0) !== $sid) {
      $conn->rollback();
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "One or more selected recipients are invalid for the chosen section."]);
      exit;
    }
    if ($rid === $userId) {
      $conn->rollback();
      http_response_code(400);
      echo json_encode(["ok" => false, "error" => "You cannot attachment-forward to yourself."]);
      exit;
    }
  }

  $routeRemarks = '';
  if ($remarks !== '' && strcasecmp($remarks, 'none') !== 0) {
    $routeRemarks = $remarks;
  }

  $batchId = bin2hex(random_bytes(16));
  $newBranchIds = [];
  $routeIds = [];
  $attachmentTaskCount = 0;
  $recipientSummary = [];

  $stmtInsertRoute = $conn->prepare("
    INSERT INTO routes
      (document_id, branch_id, from_section_id, to_section_id, from_user_id, to_user_id, route_kind, send_batch_id, received_at, sent_by_user_id, remarks)
    VALUES
      (?, ?, ?, ?, ?, ?, 'ACTION', ?, NULL, ?, ?)
  ");

  $stmtDuplicateAttachment = $conn->prepare("
    INSERT INTO document_attachments
      (document_id, branch_id, original_name, stored_name, stored_path, mime, size_bytes, note, is_append, uploaded_by_user_id, uploaded_by_section_id)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
  ");

  $stmtInsertTask = $conn->prepare("
    INSERT INTO attachment_forward_tasks
      (document_id, sender_branch_id, recipient_branch_id, route_id, source_attachment_id, forwarded_attachment_id, sender_user_id, sender_section_id, recipient_user_id, recipient_section_id, batch_id, task_status)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING_RECEIVE')
  ");

  $grouped = [];
  foreach ($normalizedRows as $row) {
    $key = $row['to_section_id'] . ':' . $row['to_user_id'];
    $grouped[$key][] = $row;
  }

  foreach ($grouped as $rowsForRecipient) {
    $toSectionId = (int)$rowsForRecipient[0]['to_section_id'];
    $toUserId = (int)$rowsForRecipient[0]['to_user_id'];
    $recipientBranchId = null;

    if ($docHasRealBranches) {
      $branchLabel = (string)($recipientInfo[$toUserId]['full_name'] ?? ("User #".$toUserId));
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
      $newBranchIds[] = $recipientBranchId;

      workflow_grant_visibility($conn, $docId, $toUserId, 'PARTICIPANT', $recipientBranchId, $actualUserId);
      workflow_grant_visibility($conn, $docId, $userId, 'PARTICIPANT', $recipientBranchId, $actualUserId);
      if ($actualUserId !== $userId) {
        workflow_grant_visibility($conn, $docId, $actualUserId, 'PARTICIPANT', $recipientBranchId, $actualUserId);
      }
    }

    $stmtInsertRoute->bind_param("iiiiiisis", $docId, $recipientBranchId, $mySectionId, $toSectionId, $actualUserId, $toUserId, $batchId, $actualUserId, $routeRemarks);
    $stmtInsertRoute->execute();
    $routeId = (int)$conn->insert_id;
    $routeIds[] = $routeId;

    $recipientFiles = [];
    foreach ($rowsForRecipient as $row) {
      $sourceAttachmentId = (int)$row['attachment_id'];
      $att = $attachmentsById[$sourceAttachmentId];
      $dupBranchId = $docHasRealBranches ? $recipientBranchId : null;
      $stmtDuplicateAttachment->bind_param(
        "iissssisiii",
        $docId,
        $dupBranchId,
        $att['original_name'],
        $att['stored_name'],
        $att['stored_path'],
        $att['mime'],
        $att['size_bytes'],
        $att['note'],
        $att['is_append'],
        $actualUserId,
        $mySectionId
      );
      $stmtDuplicateAttachment->execute();
      $forwardedAttachmentId = (int)$conn->insert_id;

      $stmtInsertTask->bind_param(
        "iiiiiiiiiis",
        $docId,
        $sourceBranchId,
        $recipientBranchId,
        $routeId,
        $sourceAttachmentId,
        $forwardedAttachmentId,
        $actualUserId,
        $mySectionId,
        $toUserId,
        $toSectionId,
        $batchId
      );
      $stmtInsertTask->execute();
      $attachmentTaskCount++;
      $recipientFiles[] = (string)$att['original_name'];
    }

    $recipientSummary[] = [
      'to_user_id' => $toUserId,
      'to_user_name' => (string)($recipientInfo[$toUserId]['full_name'] ?? ''),
      'to_section_id' => $toSectionId,
      'attachments' => $recipientFiles,
      'branch_id' => $recipientBranchId,
      'route_id' => $routeId,
    ];
  }

  $payload = json_encode([
    'kind' => 'attachment_forwarded',
    'remarks' => $routeRemarks,
    'batch_id' => $batchId,
    'source_branch_id' => $sourceBranchId,
    'recipient_routes' => $recipientSummary,
    'new_branch_ids' => array_values(array_unique(array_filter($newBranchIds))),
    'attachment_task_count' => $attachmentTaskCount,
    'acting_principal_user_id' => ($userId > 0 && $userId !== $actualUserId) ? $userId : null,
    'acting_principal_name' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_principal_name'] ?? '') : '',
    'acting_label' => ($userId > 0 && $userId !== $actualUserId) ? (string)($identity['acting_label'] ?? '') : '',
  ], JSON_UNESCAPED_UNICODE);

  $stmtEvent = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, from_section_id, to_section_id, payload_json)
    VALUES (?, 'forwarded', ?, ?, ?, NULL, ?)
  ");
  $stmtEvent->bind_param("iiiis", $docId, $actualUserId, $mySectionId, $mySectionId, $payload);
  $stmtEvent->execute();

  $conn->commit();

  echo json_encode([
    "ok" => true,
    "document_id" => $docId,
    "batch_id" => $batchId,
    "route_ids" => $routeIds,
    "branch_ids" => array_values(array_unique(array_filter($newBranchIds))),
    "attachment_task_count" => $attachmentTaskCount,
  ]);
  exit;
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(["ok" => false, "error" => "Server error", "debug" => $e->getMessage()]);
  exit;
}
