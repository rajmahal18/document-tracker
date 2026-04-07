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
if ($effectiveUserId <= 0) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Invalid user']);
  exit;
}

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
    AND r.sent_by_user_id = ?
    AND r.route_kind = 'ACTION'
    AND r.received_at IS NULL
    AND r.cancelled_at IS NULL
";
$params = [$docId, $effectiveUserId];
$types = 'ii';

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
  http_response_code(404);
  echo json_encode(['ok' => false, 'error' => 'No editable pending route was found.']);
  exit;
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
    'message' => 'No changes were made.'
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
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  $conn->rollback();
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Failed to update pending remarks.']);
}
