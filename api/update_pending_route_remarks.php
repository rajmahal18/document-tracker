<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();
require_csrf();
header("Content-Type: application/json; charset=utf-8");

$docId = (int)($_POST['document_id'] ?? 0);
$routeId = (int)($_POST['route_id'] ?? 0);
$branchId = (int)($_POST['branch_id'] ?? 0);
$remarks = trim((string)($_POST['remarks'] ?? ''));
if (strcasecmp($remarks, 'none') === 0) {
  $remarks = '';
}
if (mb_strlen($remarks) > 500) {
  $remarks = mb_substr($remarks, 0, 500);
}

if ($docId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'Missing document id']);
  exit;
}

if (!can_view_document($conn, $docId)) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden']);
  exit;
}

$identity = effective_document_identity($conn);
$effectiveUserId = (int)($identity['effective_user_id'] ?? 0);
$effectiveSectionId = (int)($identity['effective_section_id'] ?? 0);
$actualUserId = (int)($identity['actual_user_id'] ?? 0);
$assistantMode = (bool)($identity['assistant_mode'] ?? false);
if ($effectiveUserId <= 0) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Invalid user']);
  exit;
}

$senderUserIds = [$effectiveUserId];
if ($assistantMode && $actualUserId > 0 && $actualUserId !== $effectiveUserId) {
  $senderUserIds[] = $actualUserId;
}

$senderPlaceholders = implode(',', array_fill(0, count($senderUserIds), '?'));

$sql = "
  SELECT
    r.id,
    r.document_id,
    r.branch_id,
    r.remarks,
    r.sent_by_user_id,
    r.received_at,
    r.cancelled_at
  FROM routes r
  WHERE r.document_id = ?
    AND r.sent_by_user_id IN ($senderPlaceholders)
    AND r.route_kind = 'ACTION'
    AND r.received_at IS NULL
    AND r.cancelled_at IS NULL
";
$params = array_merge([$docId], $senderUserIds);
$types = 'i' . str_repeat('i', count($senderUserIds));

if ($routeId > 0) {
  $sql .= " AND r.id = ?";
  $types .= 'i';
  $params[] = $routeId;
} elseif ($branchId > 0) {
  $sql .= " AND COALESCE(r.branch_id, 0) = ?";
  $types .= 'i';
  $params[] = $branchId;
}

$sql .= " ORDER BY r.id DESC LIMIT 1";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$route = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$route) {
  $holderEditable = false;
  $holderBranchId = 0;

  if (workflow_branch_mode_enabled($conn) && workflow_document_has_real_branches($conn, $docId)) {
    $actionableBranch = workflow_find_single_actionable_branch(
      $conn,
      $docId,
      $effectiveUserId,
      $branchId > 0 ? $branchId : null
    );
    if (is_array($actionableBranch) && (int)($actionableBranch['id'] ?? 0) > 0) {
      $holderEditable = true;
      $holderBranchId = (int)$actionableBranch['id'];
    }
  } else {
    $holderEditable = workflow_user_can_act_legacy_document(
      $conn,
      $docId,
      $effectiveUserId,
      (int)($identity['effective_section_id'] ?? 0),
      (bool)($identity['effective_is_chief'] ?? false),
      false
    );
  }

  if (!$holderEditable) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'No editable pending route was found.']);
    exit;
  }

  $stmt = $conn->prepare("
    SELECT actor_user_id, payload_json
    FROM document_events
    WHERE document_id = ?
      AND event_type = 'updated'
      AND actor_user_id IN ($senderPlaceholders)
    ORDER BY id DESC
    LIMIT 50
  ");
  $eventParams = array_merge([$docId], $senderUserIds);
  $eventTypes = 'i' . str_repeat('i', count($senderUserIds));
  $stmt->bind_param($eventTypes, ...$eventParams);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
  $stmt->close();

  $oldRemarks = '';
  foreach ($rows as $row) {
    $payload = json_decode((string)($row['payload_json'] ?? ''), true);
    if (!is_array($payload)) continue;
    $kind = (string)($payload['kind'] ?? '');
    if (!in_array($kind, ['holder_progress_note_added', 'holder_progress_note_updated', 'holder_progress_note_cleared'], true)) {
      continue;
    }
    $actorUserId = (int)($row['actor_user_id'] ?? 0);
    if ($assistantMode && $actorUserId === $actualUserId && $actualUserId !== $effectiveUserId) {
      $actingPrincipalInPayload = (int)($payload['acting_principal_user_id'] ?? 0);
      if ($actingPrincipalInPayload !== $effectiveUserId) {
        continue;
      }
    }
    if ((int)($payload['branch_id'] ?? 0) !== $holderBranchId) {
      continue;
    }
    $oldRemarks = trim((string)($payload['remarks'] ?? ''));
    break;
  }

  if ($oldRemarks === $remarks) {
    echo json_encode([
      'ok' => true,
      'route_id' => 0,
      'remarks' => $remarks,
      'has_remark' => ($remarks !== ''),
      'change_type' => $remarks !== '' ? 'holder_progress_note_updated' : 'holder_progress_note_cleared',
      'message' => 'No changes were made.',
      'branch_id' => $holderBranchId,
      'mode' => 'holder_progress',
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $conn->begin_transaction();
  try {
    $changeType = 'holder_progress_note_updated';
    if ($oldRemarks === '' && $remarks !== '') {
      $changeType = 'holder_progress_note_added';
    } elseif ($oldRemarks !== '' && $remarks === '') {
      $changeType = 'holder_progress_note_cleared';
    }

    $eventPayload = json_encode([
      'kind' => $changeType,
      'branch_id' => $holderBranchId,
      'old_remarks' => $oldRemarks,
      'remarks' => $remarks,
      'acting_principal_user_id' => ($assistantMode && $actualUserId > 0 && $actualUserId !== $effectiveUserId) ? $effectiveUserId : null,
      'title' => match ($changeType) {
        'holder_progress_note_added' => 'Added work-in-progress remarks',
        'holder_progress_note_cleared' => 'Cleared work-in-progress remarks',
        default => 'Updated work-in-progress remarks',
      },
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $conn->prepare("
      INSERT INTO document_events
        (document_id, event_type, actor_user_id, actor_section_id, payload_json)
      VALUES
        (?, 'updated', ?, ?, ?)
    ");
    $stmt->bind_param('iiis', $docId, $effectiveUserId, $effectiveSectionId, $eventPayload);
    $stmt->execute();
    $stmt->close();

    $conn->commit();

    echo json_encode([
      'ok' => true,
      'route_id' => 0,
      'remarks' => $remarks,
      'has_remark' => ($remarks !== ''),
      'change_type' => $changeType,
      'title' => match ($changeType) {
        'holder_progress_note_added' => 'Added work-in-progress remarks',
        'holder_progress_note_cleared' => 'Cleared work-in-progress remarks',
        default => 'Updated work-in-progress remarks',
      },
      'old_remarks' => $oldRemarks,
      'branch_id' => $holderBranchId,
      'mode' => 'holder_progress',
    ], JSON_UNESCAPED_UNICODE);
    exit;
  } catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to update remarks.']);
    exit;
  }
}

$oldRemarks = trim((string)($route['remarks'] ?? ''));
if (strcasecmp($oldRemarks, 'none') === 0) {
  $oldRemarks = '';
}

if ($oldRemarks === $remarks) {
  echo json_encode([
    'ok' => true,
    'route_id' => (int)($route['id'] ?? 0),
    'remarks' => $remarks,
    'has_remark' => ($remarks !== ''),
    'change_type' => $remarks !== '' ? 'pending_remarks_updated' : 'pending_remarks_cleared',
    'message' => 'No changes were made.',
    'mode' => 'pending_route',
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$conn->begin_transaction();
try {
  $stmt = $conn->prepare("UPDATE routes SET remarks = ? WHERE id = ? LIMIT 1");
  $stmt->bind_param('si', $remarks, $route['id']);
  $stmt->execute();
  $stmt->close();

  $changeType = 'pending_remarks_updated';
  if ($oldRemarks === '' && $remarks !== '') {
    $changeType = 'pending_remarks_added';
  } elseif ($oldRemarks !== '' && $remarks === '') {
    $changeType = 'pending_remarks_cleared';
  }

  $eventPayload = json_encode([
    'kind' => $changeType,
    'route_id' => (int)($route['id'] ?? 0),
    'branch_id' => (int)($route['branch_id'] ?? 0),
    'old_remarks' => $oldRemarks,
    'remarks' => $remarks,
    'title' => match ($changeType) {
      'pending_remarks_added' => 'Added pending route remarks',
      'pending_remarks_cleared' => 'Cleared pending route remarks',
      default => 'Updated pending route remarks',
    },
  ], JSON_UNESCAPED_UNICODE);

  $stmt = $conn->prepare("
    INSERT INTO document_events
      (document_id, event_type, actor_user_id, actor_section_id, payload_json)
    VALUES
      (?, 'updated', ?, ?, ?)
  ");
  $stmt->bind_param('iiis', $docId, $effectiveUserId, $effectiveSectionId, $eventPayload);
  $stmt->execute();
  $stmt->close();

  $conn->commit();

  echo json_encode([
    'ok' => true,
    'route_id' => (int)($route['id'] ?? 0),
    'remarks' => $remarks,
    'has_remark' => ($remarks !== ''),
    'change_type' => $changeType,
    'title' => match ($changeType) {
      'pending_remarks_added' => 'Added pending route remarks',
      'pending_remarks_cleared' => 'Cleared pending route remarks',
      default => 'Updated pending route remarks',
    },
    'old_remarks' => $oldRemarks,
    'branch_id' => (int)($route['branch_id'] ?? 0),
    'mode' => 'pending_route',
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to update pending remarks.']);
}
