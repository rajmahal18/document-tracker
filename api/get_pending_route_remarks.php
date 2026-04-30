<?php
declare(strict_types=1);

require __DIR__ . "/../includes/bootstrap.php";
require_login();
header("Content-Type: application/json; charset=utf-8");

$docId = (int)($_GET['document_id'] ?? 0);
$branchId = (int)($_GET['branch_id'] ?? 0);

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
    r.branch_id,
    r.remarks,
    r.sent_at,
    r.sent_by_user_id,
    r.route_kind
  FROM routes r
  WHERE r.document_id = ?
    AND r.received_at IS NULL
    AND r.cancelled_at IS NULL
    AND r.sent_by_user_id IN ($senderPlaceholders)
    AND r.route_kind = 'ACTION'
";
$params = array_merge([$docId], $senderUserIds);
$types = 'i' . str_repeat('i', count($senderUserIds));

if ($branchId > 0) {
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

  if ($holderEditable) {
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

    $remarks = '';
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
      $payloadBranchId = (int)($payload['branch_id'] ?? 0);
      if ($payloadBranchId !== $holderBranchId) {
        continue;
      }
      $remarks = trim((string)($payload['remarks'] ?? ''));
      break;
    }

    $hasRemark = ($remarks !== '');
    echo json_encode([
      'ok' => true,
      'editable' => true,
      'route_id' => 0,
      'remarks' => $remarks,
      'has_remark' => $hasRemark,
      'button_label' => $hasRemark ? 'Edit remarks' : 'Add remarks',
      'helper_text' => 'Share a work-in-progress remark while this document is still with you. Every change is logged in the timeline.',
      'branch_id' => $holderBranchId,
      'mode' => 'holder_progress',
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }

  echo json_encode([
    'ok' => true,
    'editable' => false,
    'route_id' => 0,
    'remarks' => '',
    'has_remark' => false,
    'button_label' => 'Add remarks',
    'helper_text' => 'You can add remarks while your pending route is waiting for receive, or while the document is currently with you.',
    'branch_id' => $branchId,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

$remarks = trim((string)($route['remarks'] ?? ''));
if (strcasecmp($remarks, 'none') === 0) {
  $remarks = '';
}

$hasRemark = ($remarks !== '');

echo json_encode([
  'ok' => true,
  'editable' => true,
  'route_id' => (int)($route['id'] ?? 0),
  'remarks' => $remarks,
  'has_remark' => $hasRemark,
  'button_label' => $hasRemark ? 'Edit pending remarks' : 'Add pending remarks',
  'helper_text' => 'This stays editable until the recipient receives the route. Every change is logged in the timeline.',
  'branch_id' => (int)($route['branch_id'] ?? 0),
  'mode' => 'pending_route',
], JSON_UNESCAPED_UNICODE);
